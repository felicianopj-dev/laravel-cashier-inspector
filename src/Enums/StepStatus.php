<?php

namespace FelicianoPJ\CashierInspector\Enums;

enum StepStatus: string
{
    case Ok = 'ok';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
