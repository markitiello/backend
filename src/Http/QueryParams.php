<?php

declare(strict_types=1);

namespace Benzina\Http;

/**
 * Lettura e validazione dei parametri di query, con gli stessi limiti di
 * docs/openapi.yaml. Gli errori si accumulano e diventano un'unica risposta 422.
 */
final class QueryParams
{
    /** @var list<string> */
    private array $errors = [];

    /** @param array<string, mixed> $query */
    public function __construct(private readonly array $query)
    {
    }

    private function raw(string $name): ?string
    {
        $value = $this->query[$name] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function float(string $name, float $min, float $max, ?float $default = null, bool $exclusiveMin = false): float
    {
        $raw = $this->raw($name);
        if ($raw === null) {
            if ($default === null) {
                $this->errors[] = "$name: obbligatorio";
            }
            return $default ?? 0.0;
        }
        if (!is_numeric($raw)) {
            $this->errors[] = "$name: deve essere un numero";
            return 0.0;
        }
        $value = (float) $raw;
        if ($exclusiveMin ? $value <= $min : $value < $min) {
            $this->errors[] = "$name: deve essere " . ($exclusiveMin ? 'maggiore di' : 'almeno') . " $min";
        } elseif ($value > $max) {
            $this->errors[] = "$name: deve essere al massimo $max";
        }
        return $value;
    }

    public function int(string $name, int $min, int $max, int $default): int
    {
        $raw = $this->raw($name);
        if ($raw === null) {
            return $default;
        }
        if (!preg_match('/^-?\d+$/', $raw)) {
            $this->errors[] = "$name: deve essere un numero intero";
            return $default;
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            $this->errors[] = "$name: deve essere tra $min e $max";
        }
        return $value;
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @param T $default
     * @return T
     */
    public function enum(string $name, string $enum, \BackedEnum $default): \BackedEnum
    {
        $raw = $this->raw($name);
        if ($raw === null) {
            return $default;
        }
        $value = $enum::tryFrom($raw);
        if ($value === null) {
            $allowed = implode(', ', array_map(static fn (\BackedEnum $c): string => (string) $c->value, $enum::cases()));
            $this->errors[] = "$name: valori ammessi $allowed";
            return $default;
        }
        return $value;
    }

    /** @return list<int> */
    public function idList(string $name, int $max): array
    {
        $raw = $this->raw($name);
        if ($raw === null || !preg_match('/^\d+(,\d+)*$/', $raw)) {
            $this->errors[] = "$name: elenco di id separati da virgola";
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', explode(',', $raw))));
        if (count(explode(',', $raw)) > $max) {
            $this->errors[] = "$name: al massimo $max id";
        }
        return $ids;
    }

    /** @throws HttpProblem 422 se ci sono errori */
    public function check(): void
    {
        if ($this->errors !== []) {
            throw new HttpProblem(422, implode('; ', $this->errors));
        }
    }
}
