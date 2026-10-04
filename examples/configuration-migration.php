<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SoftCreatR\JsonPayloadContract\Contract;
use SoftCreatR\JsonPayloadContract\Field;

$configuration = Contract::define([
    'endpoint' => Field::required('$.integrations.billing.endpoint')
        ->fallback('$.billing_url')
        ->string()
        ->url(['https']),
    'timeoutSeconds' => Field::optional('$.integrations.billing.timeout_seconds')
        ->fallback('$.billing_timeout')
        ->integer()
        ->coerce()
        ->between(1, 60)
        ->default(10),
    'retryEnabled' => Field::optional('$.integrations.billing.retry_enabled')
        ->fallback('$.billing_retry')
        ->boolean()
        ->coerce()
        ->default(true),
]);

$legacyConfiguration = <<<'JSON'
    {
      "billing_url": "https://billing.example.com/v1",
      "billing_timeout": "15"
    }
    JSON;

$result = $configuration->extractJson($legacyConfiguration);
$values = $result->value();

print_r([
    'configuration' => $values,
    'migration' => [
        'endpointUsesLegacyKey' => $result->match('endpoint')?->usedFallback,
        'timeoutUsesLegacyKey' => $result->match('timeoutSeconds')?->usedFallback,
        'retryUsesDefault' => $result->match('retryEnabled')?->usedDefault,
    ],
]);
