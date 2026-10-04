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

$action = Contract::define([
    'type' => Field::required('$.type')
        ->string()
        ->oneOf(['reply', 'escalate', 'refund_review']),
    'instruction' => Field::required('$.instruction')
        ->string()
        ->nonEmpty()
        ->maxLength(500),
    'priority' => Field::required('$.priority')
        ->string()
        ->oneOf(['low', 'normal', 'high']),
]);

$generatedOutput = Contract::define([
    'summary' => Field::required('$.summary')
        ->string()
        ->nonEmpty()
        ->maxLength(1_000),
    'sentiment' => Field::required('$.sentiment')
        ->string()
        ->oneOf(['negative', 'neutral', 'positive']),
    'confidence' => Field::required('$.confidence')
        ->number()
        ->between(0, 1),
    'topics' => Field::many('$.topics[*]')
        ->string()
        ->nonEmpty()
        ->maxLength(80)
        ->maxMatches(20),
    'actions' => Field::many('$.actions[*]')
        ->shape($action)
        ->maxMatches(10),
]);

$responseEnvelope = Contract::define([
    'responseId' => Field::required('$.id')->string()->startsWith('resp_'),
    'status' => Field::required('$.status')->string()->oneOf(['completed']),
    'generatedJson' => Field::required(
        '$.output[*].content[?(@.type == "output_text")].text',
    )->string()->nonEmpty(),
]);

$generatedJson = json_encode([
    'summary' => 'The customer was charged twice.',
    'sentiment' => 'negative',
    'confidence' => 0.98,
    'topics' => ['billing', 'duplicate charge'],
    'actions' => [[
        'type' => 'refund_review',
        'instruction' => 'Compare both payment identifiers before approving a refund.',
        'priority' => 'high',
    ]],
], JSON_THROW_ON_ERROR);

// This is a representative raw Responses API body returned by an HTTP client.
$openAiResponse = json_encode([
    'id' => 'resp_01JEXAMPLE',
    'status' => 'completed',
    'output' => [[
        'type' => 'message',
        'role' => 'assistant',
        'content' => [[
            'type' => 'output_text',
            'text' => $generatedJson,
        ]],
    ]],
], JSON_THROW_ON_ERROR);

$response = $responseEnvelope->applyJson($openAiResponse);
$analysis = $generatedOutput->applyJson($response['generatedJson']);

print_r([
    'responseId' => $response['responseId'],
    'analysis' => $analysis,
]);
