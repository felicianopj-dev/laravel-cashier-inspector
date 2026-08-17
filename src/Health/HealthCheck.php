<?php

namespace FelicianoPJ\CashierInspector\Health;

use FelicianoPJ\CashierInspector\Enums\Severity;

/**
 * One line of the health report: how the check went, and what to say about
 * it. Severity is the package's existing enum rather than a second status
 * type - a passing check is a success, and a failing one is a warning or an
 * error depending on whether the package can still do its job.
 */
class HealthCheck
{
    public function __construct(
        public readonly Severity $severity,
        public readonly string $message,
    ) {}

    public static function pass(string $message): self
    {
        return new self(Severity::Success, $message);
    }

    public static function warn(string $message): self
    {
        return new self(Severity::Warning, $message);
    }

    public static function error(string $message): self
    {
        return new self(Severity::Error, $message);
    }

    public function failed(): bool
    {
        return $this->severity === Severity::Error;
    }
}
