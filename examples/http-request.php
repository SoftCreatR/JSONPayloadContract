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

$registrationRequest = Contract::define([
    'email' => Field::required('$.email')->string()->email()->maxLength(254),
    'displayName' => Field::required('$.profile.display_name')
        ->string()
        ->minLength(2)
        ->maxLength(80),
    'locale' => Field::optional('$.profile.locale')
        ->string()
        ->oneOf(['de-DE', 'en-GB', 'en-US'])
        ->default('en-US'),
    'marketingConsent' => Field::optional('$.consent.marketing')
        ->boolean()
        ->default(false),
    'referralCodes' => Field::many('$.referral_codes[*]')
        ->string()
        ->matching('/\A[A-Z0-9]{6,16}\z/D')
        ->maxMatches(5),
]);

$requestBody = <<<'JSON'
    {
      "email": "ada@example.com",
      "profile": {
        "display_name": "Ada Lovelace",
        "locale": "en-GB",
        "ignored_client_flag": true
      },
      "consent": {"marketing": false},
      "referral_codes": ["WELCOME24"]
    }
    JSON;

// Unknown input is discarded; only explicitly contracted values cross the boundary.
print_r($registrationRequest->applyJson($requestBody));
