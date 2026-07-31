<?php

namespace FelicianoPJ\CashierInspector\Http\Controllers;

use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the dashboard's vendored Alpine.js build directly from the
 * package, so no build step, CDN, or vendor:publish is required for a
 * normal install.
 */
class AssetController extends Controller
{
    public function alpine(): BinaryFileResponse
    {
        return response()->file(
            __DIR__.'/../../../resources/js/vendor/alpine.min.js',
            [
                'Content-Type' => 'application/javascript',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]
        );
    }
}
