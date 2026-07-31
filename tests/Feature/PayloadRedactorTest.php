<?php

use FelicianoPJ\CashierInspector\Redaction\PayloadRedactor;

it('masks a configured dot-path', function () {
    $redactor = new PayloadRedactor(paths: ['data.object.customer_email']);

    $result = $redactor->redact([
        'data' => ['object' => ['customer_email' => 'jane@example.com', 'id' => 'evt_1']],
    ]);

    expect($result['data']['object']['customer_email'])->toBe('[redacted]')
        ->and($result['data']['object']['id'])->toBe('evt_1');
});

it('masks every value under a wildcard segment', function () {
    $redactor = new PayloadRedactor(paths: ['data.object.metadata.*']);

    $result = $redactor->redact([
        'data' => ['object' => ['metadata' => ['internal_id' => 'abc', 'note' => 'xyz']]],
    ]);

    expect($result['data']['object']['metadata'])->toBe([
        'internal_id' => '[redacted]',
        'note' => '[redacted]',
    ]);
});

it('leaves the payload untouched when a path segment does not exist', function () {
    $redactor = new PayloadRedactor(paths: ['data.object.customer_email']);

    $result = $redactor->redact(['data' => ['object' => ['id' => 'evt_1']]]);

    expect($result)->toBe(['data' => ['object' => ['id' => 'evt_1']]]);
});

it('does nothing when disabled', function () {
    $redactor = new PayloadRedactor(paths: ['data.object.customer_email'], enabled: false);

    $payload = ['data' => ['object' => ['customer_email' => 'jane@example.com']]];

    expect($redactor->redact($payload))->toBe($payload);
});
