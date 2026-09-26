<?php

declare(strict_types=1);

/**
 * Invia una notifica di prova a un topic, per verificare la configurazione di
 * Firebase Cloud Messaging (Android e iOS).
 *
 *   php bin/push-test.php trend_benzina_self
 */

use Benzina\Config;
use Benzina\Trend\FcmClient;
use Benzina\Trend\Topics;

require __DIR__ . '/../vendor/autoload.php';

$topic = $argv[1] ?? '';
if (!in_array($topic, Topics::all(), true)) {
    fwrite(STDERR, "Uso: php bin/push-test.php TOPIC\nTopic: " . implode(', ', Topics::all()) . "\n");
    exit(1);
}
$config = Config::fromEnv(Config::loadEnv(__DIR__ . '/../.env'));
if ($config->fcmCredentials === null) {
    fwrite(STDERR, "Configurare BENZINA_FCM_CREDENTIALS (service account Firebase).\n");
    exit(1);
}
try {
    FcmClient::fromCredentials($config->fcmCredentials)->sendToTopic(
        $topic,
        'Benzina: notifica di prova',
        'Se la vedi, le notifiche push funzionano.',
        ['type' => 'test'],
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE ' . $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, "Inviata al topic $topic.\n");
