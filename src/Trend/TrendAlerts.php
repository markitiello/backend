<?php

declare(strict_types=1);

namespace Benzina\Trend;

use Benzina\Config;
use Benzina\Database;
use Benzina\Fuel;
use Benzina\Mode;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Dopo ogni import: rileva le tendenze della media nazionale, le salva in
 * trend_alerts e invia una notifica push al topic di ogni carburante.
 *
 * È idempotente: rilanciarlo per lo stesso giorno non duplica né gli avvisi
 * né le notifiche; quelle non inviate (es. FCM non raggiungibile) vengono
 * ritentate al giro successivo.
 */
final class TrendAlerts
{
    private readonly TrendDetector $detector;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly ?PushSender $push = null,
        private readonly LoggerInterface $log = new NullLogger(),
    ) {
        $this->detector = new TrendDetector($config->trendMinDays, $config->trendMinChange);
    }

    /**
     * @return array{detected: int, sent: int} avvisi nuovi del giorno e notifiche inviate
     */
    public function run(string $day): array
    {
        $detected = 0;
        foreach ($this->db->all(
            'SELECT DISTINCT fuel, mode FROM national_averages WHERE day = ? ORDER BY fuel, mode',
            [$day],
        ) as $series) {
            $alert = $this->detectFor($series['fuel'], $series['mode'], $day);
            if ($alert !== null && !$this->inCooldown($series['fuel'], $series['mode'], $day, $alert['direction'])) {
                $detected += $this->db->insertMany(
                    'trend_alerts',
                    ['fuel', 'mode', 'day', 'direction', 'days', 'change_ratio', 'price', 'sent_at'],
                    [[$series['fuel'], $series['mode'], $day, $alert['direction'], $alert['days'],
                        round($alert['change'], 5), $alert['price'], null]],
                    ignoreDuplicates: true,
                );
            }
        }
        return ['detected' => $detected, 'sent' => $this->sendPending($day)];
    }

    /** @return array{direction: 'up'|'down', days: int, change: float, price: float}|null */
    private function detectFor(string $fuel, string $mode, string $day): ?array
    {
        // Qualche giorno in più del minimo, per verificare che la serie inizi davvero oggi.
        $from = (new DateTimeImmutable($day))->modify('-' . ($this->config->trendMinDays + 7) . ' days')->format('Y-m-d');
        $rows = $this->db->all(
            'SELECT price FROM national_averages WHERE fuel = ? AND mode = ? AND day BETWEEN ? AND ? ORDER BY day',
            [$fuel, $mode, $from, $day],
        );
        $prices = array_map(static fn (array $r): float => (float) $r['price'], $rows);
        $alert = $this->detector->detect($prices);
        return $alert === null ? null : $alert + ['price' => round((float) end($prices), 4)];
    }

    private function inCooldown(string $fuel, string $mode, string $day, string $direction): bool
    {
        $since = (new DateTimeImmutable($day))->modify("-{$this->config->trendCooldownDays} days")->format('Y-m-d');
        return $this->db->one(
            'SELECT day FROM trend_alerts WHERE fuel = ? AND mode = ? AND direction = ? AND day >= ? AND day < ?',
            [$fuel, $mode, $direction, $since, $day],
        ) !== null;
    }

    private function sendPending(string $day): int
    {
        if ($this->push === null) {
            return 0;
        }
        // Solo gli avvisi recenti: una notizia di giorni fa non serve più.
        $since = (new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
        $sent = 0;
        foreach ($this->db->all('SELECT * FROM trend_alerts WHERE sent_at IS NULL AND day >= ?', [$since]) as $alert) {
            $fuel = Fuel::from($alert['fuel']);
            $mode = Mode::from($alert['mode']);
            [$title, $body] = self::message($fuel, $mode, $alert['direction'], (int) $alert['days'], (float) $alert['change_ratio'], (float) $alert['price']);
            try {
                $this->push->sendToTopic(Topics::forTrend($fuel, $mode), $title, $body, [
                    'type' => 'trend',
                    'fuel' => $fuel->value,
                    'mode' => $mode->value,
                    'direction' => $alert['direction'],
                    'day' => substr((string) $alert['day'], 0, 10),
                ]);
            } catch (\RuntimeException $e) {
                $this->log->error($e->getMessage());
                continue;
            }
            $this->db->execute(
                'UPDATE trend_alerts SET sent_at = ? WHERE fuel = ? AND mode = ? AND day = ?',
                [(new DateTimeImmutable('now', new \DateTimeZone('Europe/Rome')))->format('Y-m-d H:i:s'), $alert['fuel'], $alert['mode'], $alert['day']],
            );
            $sent++;
        }
        return $sent;
    }

    /**
     * Testo della notifica, es. "Benzina self in calo" /
     * "Media nazionale 1,819 €/l: −1,2% in 3 giorni."
     *
     * @return array{string, string}
     */
    public static function message(Fuel $fuel, Mode $mode, string $direction, int $days, float $change, float $price): array
    {
        $label = match ($fuel) {
            Fuel::Benzina => 'Benzina',
            Fuel::Diesel => 'Diesel',
            Fuel::Gpl => 'GPL',
            Fuel::Metano => 'Metano',
        } . ($fuel->hasServiceModes() ? ' ' . $mode->value : '');
        $unit = $fuel === Fuel::Metano ? '€/kg' : '€/l';
        $percent = ($change < 0 ? '−' : '+') . number_format(abs($change) * 100, 1, ',', '.') . '%';
        $average = number_format($price, 3, ',', '.');

        return $direction === 'up'
            ? ["$label in aumento", "Media nazionale $average $unit: $percent in $days giorni. Se devi fare il pieno, meglio non aspettare."]
            : ["$label in calo", "Media nazionale $average $unit: $percent in $days giorni."];
    }

    /**
     * Avvisi degli ultimi giorni, per la schermata Notifiche dell'app.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(Database $db, string $until, int $days, ?Fuel $fuel = null): array
    {
        $since = (new DateTimeImmutable($until))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $params = [$since, $until];
        $sql = 'SELECT * FROM trend_alerts WHERE day BETWEEN ? AND ?';
        if ($fuel !== null) {
            $sql .= ' AND fuel = ?';
            $params[] = $fuel->value;
        }
        $rows = $db->all($sql . ' ORDER BY day DESC, fuel, mode', $params);
        return array_map(static function (array $r): array {
            $fuel = Fuel::from($r['fuel']);
            $mode = Mode::from($r['mode']);
            [$title, $body] = self::message($fuel, $mode, $r['direction'], (int) $r['days'], (float) $r['change_ratio'], (float) $r['price']);
            return [
                'fuel' => $fuel->value,
                'mode' => $mode->value,
                'day' => substr((string) $r['day'], 0, 10),
                'direction' => $r['direction'],
                'days' => (int) $r['days'],
                'change' => (float) $r['change_ratio'],
                'price' => (float) $r['price'],
                'title' => $title,
                'body' => $body,
                'topic' => Topics::forTrend($fuel, $mode),
                // Ora italiana, come salvata dopo l'invio; null se non ancora inviata.
                'sent_at' => $r['sent_at'] === null ? null
                    : (new DateTimeImmutable((string) $r['sent_at'], new \DateTimeZone('Europe/Rome')))->format(DATE_RFC3339),
            ];
        }, $rows);
    }
}
