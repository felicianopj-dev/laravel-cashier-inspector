<?php

namespace FelicianoPJ\CashierInspector\Redaction;

/**
 * Masks configured dot-paths (with "*" wildcard segments) out of a Stripe
 * webhook payload before it is persisted.
 */
class PayloadRedactor
{
    public function __construct(
        protected array $paths,
        protected bool $enabled = true,
        protected string $mask = '[redacted]',
    ) {
    }

    public function redact(array $payload): array
    {
        if (! $this->enabled) {
            return $payload;
        }

        foreach ($this->paths as $path) {
            $payload = $this->redactPath($payload, explode('.', $path));
        }

        return $payload;
    }

    protected function redactPath(array $data, array $segments): array
    {
        $segment = array_shift($segments);

        if ($segment === '*') {
            foreach ($data as $key => $value) {
                $data[$key] = $this->redactSegmentValue($value, $segments);
            }

            return $data;
        }

        if (! array_key_exists($segment, $data)) {
            return $data;
        }

        $data[$segment] = $this->redactSegmentValue($data[$segment], $segments);

        return $data;
    }

    protected function redactSegmentValue(mixed $value, array $remainingSegments): mixed
    {
        if ($remainingSegments === []) {
            return $this->mask;
        }

        if (is_array($value)) {
            return $this->redactPath($value, $remainingSegments);
        }

        return $value;
    }
}
