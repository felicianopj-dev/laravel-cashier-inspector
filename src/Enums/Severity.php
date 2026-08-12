<?php

namespace FelicianoPJ\CashierInspector\Enums;

enum Severity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';

    /**
     * How alarming this severity is, for picking the worst of several and
     * for ordering the dashboard's severity column. Only the order matters,
     * not the numbers.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 1,
            self::Success => 2,
            self::Warning => 3,
            self::Error => 4,
        };
    }
}
