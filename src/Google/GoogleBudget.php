<?php

declare(strict_types=1);

namespace Benzina\Google;

use Benzina\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Limite giornaliero di richieste a Google Places (a pagamento): ogni
 * ricerca e ogni lettura di valutazione conta una richiesta. Oltre il limite
 * le valutazioni restano non disponibili fino al giorno dopo (ora italiana).
 */
final class GoogleBudget
{
    /** @param int $dailyLimit 0 = nessun limite */
    public function __construct(
        private readonly Database $db,
        private readonly int $dailyLimit,
        private readonly ?\Closure $today = null,
    ) {
    }

    /** @throws GoogleQuotaException se il limite di oggi è raggiunto */
    public function spend(): void
    {
        if ($this->dailyLimit <= 0) {
            return;
        }
        $day = $this->today();
        $used = $this->used();
        if ($used >= $this->dailyLimit) {
            throw new GoogleQuotaException("limite giornaliero di {$this->dailyLimit} richieste raggiunto");
        }
        if ($used === 0 && $this->db->one('SELECT day FROM google_usage WHERE day = ?', [$day]) === null) {
            $this->db->execute('INSERT INTO google_usage (day, calls) VALUES (?, 1)', [$day]);
        } else {
            $this->db->execute('UPDATE google_usage SET calls = calls + 1 WHERE day = ?', [$day]);
        }
    }

    public function used(): int
    {
        $row = $this->db->one('SELECT calls FROM google_usage WHERE day = ?', [$this->today()]);
        return $row === null ? 0 : (int) $row['calls'];
    }

    private function today(): string
    {
        return $this->today !== null
            ? ($this->today)()
            : (new DateTimeImmutable('now', new DateTimeZone('Europe/Rome')))->format('Y-m-d');
    }
}
