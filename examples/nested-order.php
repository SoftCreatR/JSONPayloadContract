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

$lineItem = Contract::define([
    'sku' => Field::required('$.sku')->string()->nonEmpty(),
    'quantity' => Field::required('$.quantity')->integer()->coerce(),
    'price' => Field::required('$.price')->number()->coerce(),
]);

$order = Contract::define([
    'orderId' => Field::required('$.order.id')->string(),
    'currency' => Field::optional('$.order.currency')->string()->default('EUR'),
    'items' => Field::many('$.order.items[*]')->shape($lineItem),
]);

$result = $order->extract([
    'order' => [
        'id' => 'ord_123',
        'items' => [
            ['sku' => 'BOOK-1', 'quantity' => '2', 'price' => '19.90'],
            ['sku' => 'MUG-4', 'quantity' => '1', 'price' => 12.5],
        ],
    ],
]);

if (!$result->isValid()) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    exit(1);
}

print_r($result->value());
