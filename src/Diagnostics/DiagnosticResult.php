<?php

namespace FelicianoPJ\CashierInspector\Diagnostics;

use FelicianoPJ\CashierInspector\Enums\DiagnosticStatus;

/**
 * The outcome of a single DiagnosticRule::diagnose() call. Only the
 * triggered statuses (info/warning/error) get persisted by the engine —
 * passed/skipped are run-time outcomes, not findings worth keeping.
 */
final class DiagnosticResult
{
    private function __construct(
        public readonly DiagnosticStatus $status,
        public readonly ?string $code = null,
        public readonly ?string $title = null,
        public readonly ?string $message = null,
        public readonly array $suggestedChecks = [],
        public readonly array $context = [],
    ) {
    }

    public static function passed(): self
    {
        return new self(DiagnosticStatus::Passed);
    }

    public static function skipped(): self
    {
        return new self(DiagnosticStatus::Skipped);
    }

    public static function info(string $code, string $title, string $message, array $suggestedChecks = [], array $context = []): self
    {
        return new self(DiagnosticStatus::Info, $code, $title, $message, $suggestedChecks, $context);
    }

    public static function warning(string $code, string $title, string $message, array $suggestedChecks = [], array $context = []): self
    {
        return new self(DiagnosticStatus::Warning, $code, $title, $message, $suggestedChecks, $context);
    }

    public static function error(string $code, string $title, string $message, array $suggestedChecks = [], array $context = []): self
    {
        return new self(DiagnosticStatus::Error, $code, $title, $message, $suggestedChecks, $context);
    }

    public function isTriggered(): bool
    {
        return in_array($this->status, [
            DiagnosticStatus::Info,
            DiagnosticStatus::Warning,
            DiagnosticStatus::Error,
        ], true);
    }
}
