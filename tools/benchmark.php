<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

use SoftCreatR\JsonPayloadContract\Contract;
use SoftCreatR\JsonPayloadContract\Field;

require __DIR__ . '/../vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];

if (!\is_array($arguments)) {
    $arguments = [];
}

$iterations = \filter_var(
    $arguments[1] ?? 100_000,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]],
);

if (!\is_int($iterations)) {
    \fwrite(STDERR, "Usage: php tools/benchmark.php [positive-iterations]\n");
    exit(2);
}

$contract = Contract::define([
    'id' => Field::required('$.data.customer.id')->string(),
    'email' => Field::optional('$.data.customer.email')->nullable()->string(),
    'roles' => Field::many('$.data.customer.roles[*]')->string(),
    'displayName' => Field::required('$.data.customer.id')->string()->map(\strtoupper(...)),
]);
$payload = [
    'data' => [
        'customer' => [
            'id' => 'cus_123',
            'email' => 'ada@example.com',
            'roles' => ['admin', 'billing'],
        ],
    ],
];

for ($index = 0; $index < \min($iterations, 1_000); ++$index) {
    $contract->apply($payload);
}

$checksum = 0;
$startedAt = \hrtime(true);

for ($index = 0; $index < $iterations; ++$index) {
    $checksum += \count($contract->apply($payload));
}

$elapsedSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;

\printf(
    "%d extractions in %.3f s: %.0f ops/s, %.2f us/extraction (checksum: %d)\n",
    $iterations,
    $elapsedSeconds,
    $iterations / $elapsedSeconds,
    ($elapsedSeconds * 1_000_000) / $iterations,
    $checksum,
);
