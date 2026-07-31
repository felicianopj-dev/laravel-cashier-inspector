<?php

namespace FelicianoPJ\CashierInspector\Enums;

enum Severity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
}
