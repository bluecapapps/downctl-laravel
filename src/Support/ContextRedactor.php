<?php

declare(strict_types=1);

namespace Bluecapapps\DownctlLaravel\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

class ContextRedactor
{
    /** @var array<int, string> */
    private readonly array $normalizedKeys;

    /**
     * @param  array<int, string>  $keys
     */
    public function __construct(
        private readonly array $keys,
        private readonly string $replacement = '[REDACTED]',
        private readonly bool $enabled = true,
        private readonly int $maxDepth = 8,
    ) {
        $this->normalizedKeys = array_map(static fn (string $key): string => strtolower($key), $this->keys);
    }

    /**
     * @param  array<string|int, mixed>  $context
     * @return array<string|int, mixed>
     */
    public function redact(array $context): array
    {
        return $this->redactArray($context, 0);
    }

    /**
     * @param  array<string|int, mixed>  $values
     * @return array<string|int, mixed>
     */
    private function redactArray(array $values, int $depth): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            if ($this->enabled && is_string($key) && $this->shouldRedact($key)) {
                $redacted[$key] = $this->replacement;

                continue;
            }

            $redacted[$key] = $this->normalizeValue($value, $depth + 1);
        }

        return $redacted;
    }

    private function normalizeValue(mixed $value, int $depth): mixed
    {
        if ($this->isScalarValue($value)) {
            return $value;
        }

        if ($depth >= max(1, $this->maxDepth)) {
            return '[MAX_DEPTH]';
        }

        if (is_array($value)) {
            return $this->redactArray($value, $depth);
        }

        if ($value instanceof Arrayable) {
            return $this->normalizeArrayable($value, $depth);
        }

        if ($value instanceof JsonSerializable) {
            return $this->normalizeJsonSerializable($value, $depth);
        }

        if ($value instanceof Stringable) {
            return $this->normalizeStringable($value);
        }

        if (is_object($value)) {
            return sprintf('[object %s]', $this->className($value));
        }

        return $value;
    }

    private function normalizeArrayable(Arrayable $value, int $depth): mixed
    {
        try {
            return $this->redactArray($value->toArray(), $depth);
        } catch (\Throwable) {
            return sprintf('[object %s]', $this->className($value));
        }
    }

    private function normalizeJsonSerializable(JsonSerializable $value, int $depth): mixed
    {
        try {
            return $this->normalizeValue($value->jsonSerialize(), $depth + 1);
        } catch (\Throwable) {
            return sprintf('[object %s]', $this->className($value));
        }
    }

    private function normalizeStringable(Stringable $value): string
    {
        try {
            return (string) $value;
        } catch (\Throwable) {
            return sprintf('[object %s]', $this->className($value));
        }
    }

    private function isScalarValue(mixed $value): bool
    {
        return is_null($value) || is_scalar($value);
    }

    private function shouldRedact(string $key): bool
    {
        return in_array(strtolower($key), $this->normalizedKeys, true);
    }

    private function className(object $value): string
    {
        $reflection = new \ReflectionClass($value);

        return $reflection->isAnonymous() ? 'anonymous' : $value::class;
    }
}
