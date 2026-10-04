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

$customer = Contract::define([
    'id' => Field::required('$.id')->string()->nonEmpty(),
    'email' => Field::required('$.email')->string()->email(),
]);

$lineItem = Contract::define([
    'sku' => Field::required('$.sku')->string()->nonEmpty(),
    'quantity' => Field::required('$.quantity')->integer()->coerce()->minimum(1),
    'unitPrice' => Field::required('$.unit_price')->number()->coerce()->minimum(0),
]);

$orderResponse = Contract::define([
    'orderId' => Field::required('$.data.order.id')
        ->fallback('$.order.id')
        ->fallback('$.id')
        ->string()
        ->nonEmpty(),
    'status' => Field::required('$.data.order.status')
        ->fallback('$.order.state')
        ->string()
        ->oneOf(['pending', 'paid', 'cancelled']),
    'currency' => Field::required('$.data.order.currency')
        ->fallback('$.order.currency_code')
        ->string()
        ->matching('/\A[A-Z]{3}\z/D'),
    'customer' => Field::required('$.data.order.customer')
        ->fallback('$.order.buyer')
        ->shape($customer),
    'items' => Field::many('$.data.order.items[*]')
        ->fallback('$.order.lines[*]')
        ->shape($lineItem)
        ->matchesBetween(1, 500),
]);

// A legacy provider response is normalized to the same shape as its new API.
$providerResponse = <<<'JSON'
    {
      "order": {
        "id": "ord_1042",
        "state": "paid",
        "currency_code": "EUR",
        "buyer": {"id": "cus_71", "email": "ada@example.com"},
        "lines": [
          {"sku": "BOOK-1", "quantity": "2", "unit_price": "19.90"},
          {"sku": "MUG-4", "quantity": 1, "unit_price": 12.5}
        ]
      }
    }
    JSON;

print_r($orderResponse->applyJson($providerResponse));
