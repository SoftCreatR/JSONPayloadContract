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

$event = Contract::define([
    'eventId' => Field::required('$.id')->string()->uuid(),
    'eventType' => Field::required('$.type')->string()->startsWith('customer.'),
    'occurredAt' => Field::required('$.occurred_at')->string()->dateTime(),
    'customerId' => Field::required('$.data.customer.id')->string()->nonEmpty(),
    'email' => Field::required('$.data.customer.email')->string()->email(),
]);

$message = <<<'JSON'
    {
      "id": "0199bd51-3b93-7f74-98f9-93b0ac62049a",
      "type": "customer.updated",
      "occurred_at": "2026-10-04T08:15:00+00:00",
      "data": {
        "customer": {
          "id": "cus_42",
          "email": "not-an-email"
        }
      }
    }
    JSON;

$result = $event->extractJson($message);

if (!$result->isValid()) {
    // Attach these stable diagnostics to the dead-letter record instead of retrying forever.
    echo json_encode(
        [
            'reason' => 'payload_contract_failed',
            'violations' => $result->violations(),
        ],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    );

    exit(0);
}

// Dispatch only normalized, validated data to the application handler.
print_r($result->value());
