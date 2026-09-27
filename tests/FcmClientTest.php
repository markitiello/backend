<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\Trend\FcmClient;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

final class FcmClientTest extends TestCase
{
    /** @var list<array{request: RequestInterface}> */
    private array $calls = [];
    private string $publicKey;
    private string $credentials;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->credentials = (string) json_encode([
            'type' => 'service_account',
            'project_id' => 'benzina-app',
            'client_email' => 'push@benzina-app.iam.gserviceaccount.com',
            'private_key' => $private,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
        $this->calls = [];
    }

    /** @param list<Response> $responses */
    private function client(array $responses): FcmClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->calls));
        return FcmClient::fromCredentials($this->credentials, new Client(['handler' => $stack]));
    }

    public function testAutenticazioneEInvioAlTopic(): void
    {
        $fcm = $this->client([
            new Response(200, [], (string) json_encode(['access_token' => 'tok-1', 'expires_in' => 3600])),
            new Response(200, [], '{"name":"projects/benzina-app/messages/1"}'),
            new Response(200, [], '{"name":"projects/benzina-app/messages/2"}'),
        ]);
        $fcm->sendToTopic('trend_benzina_self', 'Benzina self in calo', 'Media nazionale…', ['type' => 'trend']);
        $fcm->sendToTopic('trend_gpl', 'GPL in aumento', '…', ['type' => 'trend']);

        // 1. Token OAuth2: JWT firmato con la chiave del service account.
        $tokenRequest = $this->calls[0]['request'];
        self::assertSame('https://oauth2.googleapis.com/token', (string) $tokenRequest->getUri());
        parse_str((string) $tokenRequest->getBody(), $form);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        $claims = JWT::decode($form['assertion'], new Key($this->publicKey, 'RS256'));
        self::assertSame('push@benzina-app.iam.gserviceaccount.com', $claims->iss);
        self::assertSame('https://www.googleapis.com/auth/firebase.messaging', $claims->scope);

        // 2. Messaggio al topic, valido per Android e iOS.
        $send = $this->calls[1]['request'];
        self::assertSame('https://fcm.googleapis.com/v1/projects/benzina-app/messages:send', (string) $send->getUri());
        self::assertSame('Bearer tok-1', $send->getHeaderLine('Authorization'));
        $message = json_decode((string) $send->getBody(), true)['message'];
        self::assertSame('trend_benzina_self', $message['topic']);
        self::assertSame(['title' => 'Benzina self in calo', 'body' => 'Media nazionale…'], $message['notification']);
        self::assertSame(['type' => 'trend'], $message['data']);
        self::assertSame('price_trends', $message['android']['notification']['channel_id']);
        self::assertSame('default', $message['apns']['payload']['aps']['sound']);
        // Notifica visibile: priorità alta, altrimenti con l'app chiusa arriva in ritardo.
        self::assertSame('high', $message['android']['priority']);
        self::assertSame('10', $message['apns']['headers']['apns-priority']);
        self::assertSame('alert', $message['apns']['headers']['apns-push-type']);

        // 3. Il token viene riusato: nessuna nuova autenticazione.
        self::assertCount(3, $this->calls);
    }

    public function testErroreFcm(): void
    {
        $fcm = $this->client([
            new Response(200, [], '{"access_token":"tok","expires_in":3600}'),
            new Response(404, [], '{"error":{"status":"NOT_FOUND"}}'),
        ]);
        $this->expectException(\RuntimeException::class);
        $fcm->sendToTopic('trend_gpl', 't', 'b', []);
    }

    public function testCredenzialiNonValide(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FcmClient::fromCredentials('{"project_id": "x"}');
    }

    public function testCredenzialiDaFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($file, $this->credentials);
        try {
            self::assertInstanceOf(FcmClient::class, FcmClient::fromCredentials($file));
        } finally {
            unlink($file);
        }
    }
}
