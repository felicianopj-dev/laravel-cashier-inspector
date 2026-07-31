<?php

namespace FelicianoPJ\CashierInspector\Enums;

enum EventStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Handled = 'handled';
    case Failed = 'failed';
    case Unmatched = 'unmatched';
}
