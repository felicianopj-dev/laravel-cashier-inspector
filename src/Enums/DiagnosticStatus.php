<?php

namespace FelicianoPJ\CashierInspector\Enums;

enum DiagnosticStatus: string
{
    case Passed = 'passed';
    case Skipped = 'skipped';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
