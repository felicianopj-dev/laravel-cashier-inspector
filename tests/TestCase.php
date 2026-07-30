<?php

namespace FelicianoPJ\CashierInspector\Tests;

use FelicianoPJ\CashierInspector\CashierInspectorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            CashierInspectorServiceProvider::class,
        ];
    }
}
