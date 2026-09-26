<?php

declare(strict_types=1);

namespace Benzina\Trend;

/** Invio di notifiche push a un topic (tutti i dispositivi iscritti). */
interface PushSender
{
    /**
     * @param array<string, string> $data dati per l'app (es. per aprire la schermata giusta)
     * @throws \RuntimeException se l'invio fallisce
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data): void;
}
