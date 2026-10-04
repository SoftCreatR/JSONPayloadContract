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

$contract = Contract::define([
    'eventId' => Field::required('$.event.id')
        ->fallback('$.id')
        ->string()
        ->nonEmpty(),
    'customerId' => Field::required('$.event.customer.id')
        ->fallback('$.data.customer_id')
        ->string(),
    'email' => Field::optional('$.event.customer.email')
        ->fallback('$.data.email')
        ->nullable()
        ->string(),
]);

$payload = <<<'JSON'
    {
      "id": "evt_2026_10_03",
      "data": {
        "customer_id": "cus_42",
        "email": "ada@example.com"
      }
    }
    JSON;

print_r($contract->applyJson($payload));
