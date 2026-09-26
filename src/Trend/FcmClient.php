<?php

declare(strict_types=1);

namespace Benzina\Trend;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Firebase Cloud Messaging (API HTTP v1): la stessa notifica arriva su Android
 * e, tramite APNs, su iOS.
 *
 * L'autenticazione usa un service account Firebase: si firma un JWT con la sua
 * chiave privata e lo si scambia con un token OAuth2 valido un'ora.
 * https://firebase.google.com/docs/cloud-messaging/send-message
 */
final class FcmClient implements PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    // Canale Android creato dall'app (lib/push/push_service.dart).
    public const ANDROID_CHANNEL = 'price_trends';

    private ?string $accessToken = null;
    private int $accessTokenExpiry = 0;

    /** @param array{project_id: string, client_email: string, private_key: string, token_uri?: string} $serviceAccount */
    public function __construct(
        private readonly array $serviceAccount,
        private readonly ClientInterface $http = new Client(['timeout' => 15]),
    ) {
    }

    /** Da percorso del file JSON o dal suo contenuto (variabile BENZINA_FCM_CREDENTIALS). */
    public static function fromCredentials(string $credentials, ?ClientInterface $http = null): self
    {
        $json = str_starts_with(ltrim($credentials), '{') ? $credentials : (string) @file_get_contents($credentials);
        $account = json_decode($json, true);
        if (!is_array($account) || !isset($account['project_id'], $account['client_email'], $account['private_key'])) {
            throw new \InvalidArgumentException('Credenziali FCM non valide: serve il JSON del service account Firebase.');
        }
        return $http === null ? new self($account) : new self($account, $http);
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiry - 60) {
            return $this->accessToken;
        }
        $tokenUri = $this->serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        $assertion = JWT::encode([
            'iss' => $this->serviceAccount['client_email'],
            'scope' => self::SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $this->serviceAccount['private_key'], 'RS256');
        try {
            $response = $this->http->request('POST', $tokenUri, ['form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]]);
            $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (GuzzleException | \JsonException $e) {
            throw new \RuntimeException('FCM: autenticazione fallita: ' . $e->getMessage(), 0, $e);
        }
        $this->accessToken = (string) $data['access_token'];
        $this->accessTokenExpiry = $now + (int) ($data['expires_in'] ?? 3600);
        return $this->accessToken;
    }

    public function sendToTopic(string $topic, string $title, string $body, array $data): void
    {
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($this->serviceAccount['project_id']) . '/messages:send';
        try {
            $this->http->request('POST', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $this->accessToken()],
                'json' => ['message' => [
                    'topic' => $topic,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $data,
                    'android' => [
                        'priority' => 'normal',
                        'notification' => ['channel_id' => self::ANDROID_CHANNEL],
                    ],
                    'apns' => [
                        // 5 = consegna non urgente: iOS può raggrupparla per risparmiare batteria.
                        'headers' => ['apns-priority' => '5'],
                        'payload' => ['aps' => ['sound' => 'default']],
                    ],
                ]],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("FCM: invio al topic $topic fallito: " . $e->getMessage(), 0, $e);
        }
    }
}
