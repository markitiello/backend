<?php

declare(strict_types=1);

namespace Benzina;

/**
 * Versione del backend in esecuzione: MAJOR.MINOR.COMMIT e short commit, da
 * build.json (scritto dal deploy, vedi deploy/version.sh). Senza build.json,
 * cioè in sviluppo, solo MAJOR.MINOR dal file VERSION e commit null.
 */
final class BuildInfo
{
    /** @return array{version: string, commit: ?string} */
    public static function read(string $root = __DIR__ . '/..'): array
    {
        $build = @file_get_contents($root . '/build.json');
        $json = $build === false ? null : json_decode($build, true);
        if (is_array($json) && is_string($json['version'] ?? null)) {
            return [
                'version' => $json['version'],
                'commit' => is_string($json['commit'] ?? null) ? $json['commit'] : null,
            ];
        }
        $version = trim((string) @file_get_contents($root . '/VERSION'));
        return ['version' => $version !== '' ? $version : '0.0', 'commit' => null];
    }
}
