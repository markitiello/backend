<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\BuildInfo;

final class BuildInfoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/benzina-build-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/VERSION', "1.0\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testVersioneDelDeploy(): void
    {
        file_put_contents($this->dir . '/build.json', '{"version":"1.0.57","commit":"e21dfd2"}');
        self::assertSame(['version' => '1.0.57', 'commit' => 'e21dfd2'], BuildInfo::read($this->dir));
    }

    public function testInSviluppoSoloMajorMinor(): void
    {
        self::assertSame(['version' => '1.0', 'commit' => null], BuildInfo::read($this->dir));
        file_put_contents($this->dir . '/build.json', 'non json');
        self::assertSame(['version' => '1.0', 'commit' => null], BuildInfo::read($this->dir));
    }

    public function testHealthMostraLaVersione(): void
    {
        [$status, $body] = self::get(self::app(), '/health', headers: []);
        self::assertSame(200, $status);
        self::assertMatchesRegularExpression('/^\d+\.\d+(\.\d+)?$/', $body['version']);
        self::assertArrayHasKey('commit', $body);
    }
}
