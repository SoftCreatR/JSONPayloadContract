# JSON Payload Contract

[![Tests](https://github.com/SoftCreatR/JSONPayloadContract/actions/workflows/tests.yml/badge.svg)](https://github.com/SoftCreatR/JSONPayloadContract/actions/workflows/tests.yml)
[![Latest Stable Version](https://poser.pugx.org/softcreatr/json-payload-contract/v/stable)](https://packagist.org/packages/softcreatr/json-payload-contract)
[![License](https://poser.pugx.org/softcreatr/json-payload-contract/license)](LICENSE)
[![PHP Version Require](https://poser.pugx.org/softcreatr/json-payload-contract/require/php)](https://packagist.org/packages/softcreatr/json-payload-contract)

Extract stable, typed application data from changing and untrusted JSON payloads.

APIs rename fields. Webhook versions overlap. Queue producers deploy independently. Generated JSON is almost correct until it is not. JSON Payload Contract gives these inputs one explicit boundary: select only the values your application needs, normalize them into a stable shape, and receive structured diagnostics when the contract is not met.

```php
use SoftCreatR\JsonPayloadContract\Contract;
use SoftCreatR\JsonPayloadContract\Field;

$customer = Contract::define([
    'id' => Field::required('$.data.customer.id')
        ->fallback('$.customer.id')
        ->string()
        ->nonEmpty(),
    'email' => Field::optional('$.data.customer.email')
        ->fallback('$.customer.email')
        ->nullable()
        ->string()
        ->email(),
    'roles' => Field::many('$.data.customer.roles[*]')
        ->fallback('$.customer.roles[*]')
        ->string()
        ->maxMatches(50),
]);

$data = $customer->applyJson($requestBody);

// Always the same application-facing shape:
// [
//     'id' => 'cus_123',
//     'email' => null,
//     'roles' => ['admin', 'billing'],
// ]
```

The package is deliberately not tied to a framework or DTO implementation. Its output is a predictable PHP array that can feed a constructor, command, message handler, serializer, or mapper.

## Contents

- [Why use it?](#why-use-it)
- [Installation](#installation)
- [Core model](#core-model)
- [Selectors and fallbacks](#selectors-and-fallbacks)
- [Cardinality, missing values, and null](#cardinality-missing-values-and-null)
- [Types and coercion](#types-and-coercion)
- [Built-in constraints](#built-in-constraints)
- [Nested and reusable contracts](#nested-and-reusable-contracts)
- [Custom validation and transformation](#custom-validation-and-transformation)
- [Results and error handling](#results-and-error-handling)
- [Security and resource limits](#security-and-resource-limits)
- [Recipes](#recipes)
- [API reference](#api-reference)
- [Performance](#performance)
- [Examples](#examples)
- [Development](#development)

## Why use it?

A conventional validator checks whether a known structure is valid. This library also solves the boundary problems that happen before validation:

- RFC 9535 JSONPath selects values from deeply nested payloads;
- ordered selectors support old and new producer versions simultaneously;
- required, optional, and many-value fields make cardinality explicit;
- missing values and JSON `null` remain different states;
- strict types prevent accidental scalar conversion;
- opt-in coercion handles narrowly defined transport representations;
- nested contracts normalize objects and lists without a DTO dependency;
- all independent field failures are reported in one pass;
- match metadata reveals fallback and default usage;
- immutable composition makes contract fragments reusable;
- default limits bound raw JSON size, decoded graph complexity, selector work, diagnostics, and normalized fan-out;
- duplicate JSON members, cyclic graphs, and non-JSON PHP values fail closed by default.

Use JSON Schema when the JSON document itself is the public artifact and full schema interoperability is the primary requirement. Use a DTO mapper when the main task is constructing typed objects from an already stable shape. JSON Payload Contract is for selecting and stabilizing the small application-owned shape hidden inside payloads that may move, grow, or coexist in several versions.

## Requirements

- PHP 8.3 or newer
- `softcreatr/jsonpath` 2.1 or newer

## Installation

```bash
composer require softcreatr/json-payload-contract:^1.0
```

## Core model

A contract maps output names to immutable field definitions:

```php
$contract = Contract::define([
    'outputName' => Field::required('$.source.path')->string(),
]);
```

One extraction follows this order:

1. Validate the decoded value graph and its resource budget.
2. Evaluate selectors in fallback order and stop at the first non-empty result.
3. Enforce global and field-level match limits.
4. Apply required, optional, or many-value cardinality.
5. Insert an optional default when no selector matched.
6. Handle nullability.
7. Check the strict type or perform explicitly enabled coercion.
8. Apply a nested contract, if configured.
9. Run field validators in declaration order.
10. Run transformations in declaration order.
11. If every field passed, run all contract-level validators.

Independent fields continue after a failure, so one result can describe the whole payload. If one element of a `many()` field fails, that entire output field is omitted from partial data; successful unrelated fields remain available.

Contracts and fields are immutable. Configure them once and safely reuse the same instances across requests or long-running workers.

## Selectors and fallbacks

Selectors are absolute RFC 9535 JSONPath expressions:

```php
Contract::define([
    'id' => Field::required('$.order.id'),
    'skus' => Field::many('$.order.items[*].sku'),
    'expensive' => Field::many('$.order.items[?(@.price >= 100)]'),
]);
```

Every selector is parsed and type-checked when the contract is defined. Invalid or relative expressions throw `ContractDefinitionException` before payload processing begins.

Fallbacks solve payload-version drift without branching application code:

```php
'customerId' => Field::required('$.v3.customer.id')
    ->fallback('$.v2.customer_id')
    ->fallback('$.customerId')
    ->string(),
```

A fallback is tried only when every preceding selector matched nothing. A primary selector that matches an invalid value or too many values does not fall through, because doing so would hide malformed input. `MatchResult::$selector` and `MatchResult::$usedFallback` show which path won.

Selectors that are shared by several fields are evaluated once per extraction and served from an internal cache.

## Cardinality, missing values, and null

Cardinality describes selector matches, not the value's type:

| Factory | Accepted matches | Output when no match | Output when matched |
|---|---:|---|---|
| `Field::required()` | exactly 1 | `missing_required` violation | one value |
| `Field::optional()` | 0 or 1 | omitted, or configured default | one value |
| `Field::many()` | 0 or more | an empty list | a list of values |

Singular fields report `multiple_matches` rather than silently picking one value. Many-value fields can narrow their accepted count:

```php
Field::many('$.items[*]')->minMatches(1);
Field::many('$.items[*]')->maxMatches(100);
Field::many('$.items[*]')->matchesBetween(1, 100);
```

Missing and null are intentionally different:

| Definition | Missing | JSON `null` |
|---|---|---|
| `required()->string()` | violation | violation |
| `optional()->string()` | omitted | violation |
| `optional()->string()->default('x')` | `'x'` | violation |
| `optional()->nullable()->string()` | omitted | `null` |
| `required()->null()` | violation | `null` |
| `many()` | `[]` | depends on the selector and field nullability |

An accepted null is returned unchanged. Nested contracts, validators, and transformations are not run for it.

## Types and coercion

Types are strict by default:

```php
Field::required('$.quantity')->integer();
// 2 passes; "2" does not.
```

| Method | Accepted PHP value |
|---|---|
| `mixed()` | any value |
| `null()` | only `null` |
| `scalar()` | string, integer, float, or boolean |
| `string()` | string |
| `integer()` | integer |
| `float()` | float, not integer |
| `number()` | integer or float |
| `boolean()` | boolean |
| `array()` | any PHP array |
| `list()` | array with consecutive integer keys starting at zero |
| `associativeArray()` | non-list array with a named or sparse key |
| `object()` | object |
| `collection()` | array or object |

An empty PHP array is a list. Raw JSON is decoded to objects by default, so `{}` and `[]` stay distinguishable.

Call `coerce()` explicitly when transport representations should be converted:

```php
Field::required('$.quantity')->integer()->coerce();
Field::required('$.price')->number()->coerce();
Field::required('$.enabled')->boolean()->coerce();
```

| Target | Additional accepted input |
|---|---|
| `string` | scalar or `Stringable`, converted with `(string)` |
| `scalar` | `Stringable`, converted to string |
| `integer` | signed decimal integer string within the platform integer range |
| `float` | integer or finite numeric string |
| `number` | integer string when possible, otherwise finite float input |
| `boolean` | integer or string accepted by PHP's boolean filter |

Collections are never interconverted. Coercion does not turn objects into arrays, associative arrays into lists, or arbitrary values into null.

## Built-in constraints

Constraints run after type handling and return stable machine-readable codes:

| Method | Meaning | Violation code |
|---|---|---|
| `nonEmpty()` | rejects empty strings, arrays, and objects | `empty` |
| `email()` | valid email syntax | `invalid_email` |
| `oneOf($values)` | strict non-empty allowlist | `not_allowed` |
| `matching($pattern)` | matches a developer-supplied PCRE pattern | `pattern_mismatch` |
| `minimum($number)` | inclusive numeric minimum | `below_minimum` |
| `maximum($number)` | inclusive numeric maximum | `above_maximum` |
| `between($min, $max)` | inclusive numeric range | boundary-specific code |
| `minLength($length)` | minimum Unicode code-point count | `too_short` |
| `maxLength($length)` | maximum Unicode code-point count | `too_long` |
| `startsWith($prefix)` | starts with a non-empty prefix | `missing_prefix` |
| `endsWith($suffix)` | ends with a non-empty suffix | `missing_suffix` |
| `contains($substring)` | contains a non-empty substring | `substring_not_found` |
| `minItems($count)` | minimum PHP array item count | `too_few_items` |
| `maxItems($count)` | maximum PHP array item count | `too_many_items` |
| `uuid()` | canonical hyphenated UUID syntax | `invalid_uuid` |
| `url($schemes)` | absolute URL using an allowed scheme | `invalid_url` |
| `ip()` | valid IPv4 or IPv6 address | `invalid_ip` |
| `dateTime($format)` | accepted without warnings by a `DateTimeImmutable` format | `invalid_date_time` |

```php
$contract = Contract::define([
    'email' => Field::required('$.email')->string()->email(),
    'status' => Field::required('$.status')->string()->oneOf(['open', 'closed']),
    'reference' => Field::required('$.reference')->string()->matching('/\A[A-Z]-\d+\z/D'),
    'age' => Field::required('$.age')->integer()->between(18, 130),
    'name' => Field::required('$.name')->string()->minLength(2)->maxLength(80),
    'event' => Field::required('$.event')->string()->startsWith('customer.'),
    'permissions' => Field::required('$.permissions')->list()->minItems(1)->maxItems(50),
    'requestId' => Field::required('$.request_id')->string()->uuid(),
    'callback' => Field::required('$.callback')->string()->url(['https']),
    'sourceIp' => Field::required('$.source_ip')->string()->ip(),
    'occurredAt' => Field::required('$.occurred_at')->string()->dateTime(),
]);
```

`url()` allows only HTTP and HTTPS by default. Pass an explicit list to narrow or extend the schemes. This validates syntax and scheme only; it is not an SSRF defense for outbound requests.

Text comparisons are strictly case-sensitive by default. Configure ASCII-only case-insensitive comparisons once for the complete contract:

```php
use SoftCreatR\JsonPayloadContract\ContractOptions;

$contract = Contract::define([
    'event' => Field::required('$.event')->string()->startsWith('customer.'),
    'status' => Field::required('$.status')->string()->oneOf(['ACTIVE', 'PAUSED']),
], new ContractOptions(caseSensitive: false));
```

The policy applies to `oneOf()`, `startsWith()`, `endsWith()`, and `contains()`. It does not alter JSONPath member names, explicit PCRE flags in `matching()`, or formats whose casing rules are defined by their own standards. Empty prefixes, suffixes, and substrings are rejected at definition time because they would create rules that always pass. Exact UTF-8 strings work in case-sensitive mode; Unicode-aware case folding is intentionally not implied by the dependency-free insensitive mode.

## Nested and reusable contracts

### Nested shapes

Apply a contract to one object or to every item selected by a many-value field:

```php
$lineItem = Contract::define([
    'sku' => Field::required('$.sku')->string()->nonEmpty(),
    'quantity' => Field::required('$.quantity')->integer()->coerce()->minimum(1),
]);

$order = Contract::define([
    'id' => Field::required('$.order.id')->string(),
    'items' => Field::many('$.order.items[*]')
        ->matchesBetween(1, 500)
        ->shape($lineItem),
]);
```

Nested input must be an array or object. Nested failures retain useful locations such as `items.2.quantity`; valid nested output is always the nested contract's normalized array.

### Composition

Use `extend()` for local additions and `merge()` for reusable fragments:

```php
$identity = Contract::define([
    'id' => Field::required('$.id')->string()->uuid(),
]);

$timestamps = Contract::define([
    'createdAt' => Field::required('$.created_at')->string()->dateTime(),
]);

$user = $identity
    ->extend(['email' => Field::required('$.email')->string()->email()])
    ->merge($timestamps);
```

Both operations return a new contract. Duplicate output names are rejected instead of silently overwritten. `merge()` retains validators from both contracts, uses the stricter value from each contract's resource limits, and keeps case-sensitive comparisons when either contract requires them.

## Custom validation and transformation

### Field validation

Domain rules can provide their own message and stable code:

```php
use SoftCreatR\JsonPayloadContract\ViolationCode;

$field = Field::required('$.username')
    ->string()
    ->validate(
        static fn(string $value): bool => !str_starts_with($value, 'system-'),
        'Reserved user name.',
        ViolationCode::NotAllowed,
    );
```

Validators must return exactly `true`. A false result or thrown `Throwable` fails closed. Validators run in order and stop at the first failure for that value.

### Transformations

Transform only after type and validation rules pass:

```php
$field = Field::required('$.email')
    ->string()
    ->email()
    ->map(static fn(string $value): string => strtolower($value));
```

Transformations run in declaration order. A thrown `Throwable` becomes a `transformation_failed` violation; it does not escape from `extract()`.

### Cross-field validation

Contract validators receive the complete normalized output after every field succeeds:

```php
$range = Contract::define([
    'minimum' => Field::required('$.minimum')->integer(),
    'maximum' => Field::required('$.maximum')->integer(),
])->validate(
    static fn(array $data): bool => $data['minimum'] <= $data['maximum'],
    'The minimum must not exceed the maximum.',
    'range',
);
```

All contract validators run so their failures can be returned together. They are skipped when any field failed, preventing misleading secondary errors and preserving their normalized input assumptions.

## Results and error handling

Choose the API according to whether invalid input is expected:

| Input | Structured result | Throw on invalid result |
|---|---|---|
| decoded PHP value | `extract()` | `apply()` |
| raw JSON string | `extractJson()` | `applyJson()` |

`apply()` and `applyJson()` return normalized data or throw `ExtractionException`. The exception retains the complete `Result`.

```php
use SoftCreatR\JsonPayloadContract\Exception\ExtractionException;

try {
    $data = $contract->applyJson($requestBody);
} catch (ExtractionException $exception) {
    $result = $exception->result();
}
```

Use `extract()` when validation failure is part of normal control flow:

```php
$result = $contract->extract($payload);

if (!$result->isValid()) {
    foreach ($result->violations() as $violation) {
        printf(
            "%s [%s]: %s\n",
            $violation->field,
            $violation->code->value,
            $violation->message,
        );
    }
}

$partialData = $result->data();
$data = $result->value(); // Throws unless the result is valid.
```

`data()` can contain successful independent fields even when the result is invalid. Never treat partial data as a validated payload; use `value()` at trust boundaries.

### Match metadata

Every processed field has a `MatchResult`:

```php
$match = $result->match('customerId');

if ($match?->usedFallback) {
    // Measure whether an old producer version is still active.
}

if ($match?->usedDefault) {
    // Observe reliance on a migration default.
}
```

Metadata contains the output field, winning selector, original match count, fallback usage, and default usage.

### JSON diagnostics

`Result`, `Violation`, and `MatchResult` implement `JsonSerializable`:

```php
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
```

```json
{
    "valid": false,
    "data": [],
    "violations": [
        {
            "field": "quantity",
            "code": "unexpected_type",
            "message": "Expected integer, got string.",
            "selector": "$.quantity",
            "actualType": "string"
        }
    ],
    "matches": {
        "quantity": {
            "field": "quantity",
            "selector": "$.quantity",
            "count": 1,
            "usedFallback": false,
            "usedDefault": false
        }
    }
}
```

Violation order follows contract field order. Nested field names use dot-separated output locations.

### Violation codes

The complete stable code set is grouped below:

| Category | Codes |
|---|---|
| Input | `invalid_json`, `duplicate_object_member`, `cyclic_payload`, `unsupported_payload_type`, `payload_too_deep`, `payload_too_large`, `payload_too_complex`, `evaluation_limit_exceeded`, `selector_evaluation_failed`, `too_many_violations` |
| Cardinality | `missing_required`, `multiple_matches`, `too_few_matches`, `too_many_matches` |
| Type/null | `unexpected_type`, `null_not_allowed` |
| Generic rules | `empty`, `not_allowed`, `pattern_mismatch`, `validation_failed`, `transformation_failed` |
| Numbers | `below_minimum`, `above_maximum` |
| Strings | `too_short`, `too_long`, `missing_prefix`, `missing_suffix`, `substring_not_found`, `invalid_email`, `invalid_uuid`, `invalid_url`, `invalid_ip`, `invalid_date_time` |
| Arrays | `too_few_items`, `too_many_items` |

## Security and resource limits

No payload value or JSONPath expression is evaluated as PHP. The package does not use `eval()`, dynamic code generation, shell execution, or payload-selected callbacks.

Every contract uses these defaults:

| Limit | Default | Applied to |
|---|---:|---|
| raw JSON bytes | 16 MiB | `extractJson()` / `applyJson()` before decoding |
| JSON depth | 64 | raw decoding and decoded arrays/objects |
| matches per field | 10,000 | selector output before normalization |
| decoded value nodes | 100,000 | arrays, objects, and scalar values before selection |
| selector bytes | 4,096 | each selector at contract-definition time |
| evaluation steps | 1,000,000 | shared selector and intermediate-match work per extraction |
| violations | 1,000 | diagnostics retained per extraction |

Override them for the endpoint's real budget:

```php
use SoftCreatR\JsonPayloadContract\ContractOptions;
use SoftCreatR\JsonPayloadContract\ResourceLimits;

$contract = Contract::define(
    ['events' => Field::many('$.events[*]')->maxMatches(500)],
    new ContractOptions(
        limits: new ResourceLimits(
            maxJsonBytes: 2_000_000,
            maxJsonDepth: 32,
            maxMatchesPerField: 1_000,
            maxInputNodes: 25_000,
            maxSelectorBytes: 1_024,
            maxEvaluationSteps: 100_000,
            maxViolations: 100,
        ),
    ),
);
```

The global match limit is a backstop. Use `maxMatches()` to express a smaller domain limit for a specific list. The input-node and evaluation budgets prevent broad or recursive selectors from turning bounded payloads into unbounded work. Match limits are still checked after a selector produces its result, while intermediate selector work consumes the shared evaluation budget.

`extractJson()` rejects duplicate object member names before selection. This avoids parser differentials where another service, signer, or policy layer might choose a different duplicate value. Set `rejectDuplicateObjectMembers: false` only when compatibility with last-member-wins JSON is explicitly required.

`extract()` applies the same depth and node limits to decoded values. Arrays, scalars, and `stdClass` objects are accepted; cyclic references, resources, non-finite floats, and custom objects fail closed. Application-owned objects and `ArrayAccess` can be enabled with `allowCustomObjects: true`, but their methods may then execute during selection and their internal graph cannot be fully budgeted. Treat that option as a trusted-input compatibility mode, never as an untrusted deserialization boundary.

Custom callbacks and PCRE patterns are trusted application code. Do not construct them from payload data. PHP cannot safely interrupt arbitrary callbacks, so network access and expensive work belong outside latency-sensitive contracts. Likewise, `url()` validates syntax and schemes but does not resolve hosts, follow redirects, block private addresses, or replace an outbound-request SSRF policy.

Recommended boundary pattern:

1. Apply an HTTP-server request-size limit.
2. Call `applyJson()` with endpoint-specific `ContractOptions` and `ResourceLimits`.
3. Use strict types and coercion only where the transport contract requires it.
4. Cap every attacker-controlled collection with `maxMatches()` and/or `maxItems()`.
5. Keep custom-object traversal disabled and duplicate-member rejection enabled.
6. Pass only the returned normalized array into business logic.
7. Log violation codes and match metadata, but avoid logging secret payload values.

## Recipes

### Versioned webhooks

Put the newest location first and legacy locations behind fallbacks:

```php
$webhook = Contract::define([
    'eventId' => Field::required('$.event.id')->fallback('$.id')->string(),
    'customerId' => Field::required('$.event.customer.id')
        ->fallback('$.data.customer_id')
        ->string(),
]);
```

Monitor `usedFallback` to decide when legacy selectors can be removed. A complete runnable version is in [`examples/versioned-webhook.php`](examples/versioned-webhook.php).

### Queue and event consumers

Define the contract once when constructing the consumer and reuse it for every message. Required identifiers fail closed, list outputs remain lists, and malformed messages produce complete diagnostics suitable for dead-letter metadata:

```php
$result = $eventContract->extractJson($messageBody);

if (!$result->isValid()) {
    $deadLetterQueue->publish([
        'reason' => 'payload_contract_failed',
        'violations' => $result->violations(),
    ]);

    return;
}

$handler($result->value());
```

This avoids retrying permanently malformed messages and keeps raw message data out of application handlers. See [`examples/queue-consumer.php`](examples/queue-consumer.php).

### Third-party API responses

Select only the fields your application owns conceptually. Provider-specific nesting, naming, and added response fields then stop leaking into domain code.

```php
$orderResponse = Contract::define([
    'orderId' => Field::required('$.data.order.id')
        ->fallback('$.order.id')
        ->fallback('$.id')
        ->string(),
    'status' => Field::required('$.data.order.status')
        ->fallback('$.order.state')
        ->string()
        ->oneOf(['pending', 'paid', 'cancelled']),
    'items' => Field::many('$.data.order.items[*]')
        ->fallback('$.order.lines[*]')
        ->shape($lineItem)
        ->matchesBetween(1, 500),
]);

$order = $orderResponse->applyJson($providerResponseBody);
```

The complete example normalizes a legacy commerce response, nested customer data, line items, numeric strings, and renamed keys: [`examples/third-party-api.php`](examples/third-party-api.php).

### OpenAI and other LLM structured output

An LLM can produce syntactically valid JSON that is still unsuitable for your application. Validate both the provider envelope and the generated JSON before storing it, displaying it, or using it to select an action.

For an OpenAI Responses API integration, the first contract can require a completed response and extract its `output_text`; the second contract owns the generated application shape:

```php
$responseEnvelope = Contract::define([
    'responseId' => Field::required('$.id')->string()->startsWith('resp_'),
    'status' => Field::required('$.status')->string()->oneOf(['completed']),
    'generatedJson' => Field::required(
        '$.output[*].content[?(@.type == "output_text")].text',
    )->string()->nonEmpty(),
]);

$generatedOutput = Contract::define([
    'summary' => Field::required('$.summary')->string()->nonEmpty()->maxLength(1_000),
    'sentiment' => Field::required('$.sentiment')
        ->string()
        ->oneOf(['negative', 'neutral', 'positive']),
    'confidence' => Field::required('$.confidence')->number()->between(0, 1),
    'topics' => Field::many('$.topics[*]')->string()->maxLength(80)->maxMatches(20),
    'actions' => Field::many('$.actions[*]')->shape($actionContract)->maxMatches(10),
]);

$response = $responseEnvelope->applyJson($rawOpenAiResponse);
$analysis = $generatedOutput->applyJson($response['generatedJson']);
```

OpenAI's [Structured Outputs](https://developers.openai.com/api/docs/guides/structured-outputs) can constrain model output to a JSON Schema. The local contract remains useful as the application boundary: it selects the provider response, enforces domain-specific limits, normalizes the result, and produces stable diagnostics independently of the HTTP client or SDK. A complete runnable support-ticket analysis example is in [`examples/openai-structured-output.php`](examples/openai-structured-output.php).

### Configuration migration

Read the current key first, use fallbacks for old stored formats, and apply defaults only when every selector is missing:

```php
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
]);

$result = $configuration->extractJson($storedConfiguration);

if ($result->match('endpoint')?->usedFallback) {
    $metrics->increment('configuration.legacy_key_used');
}
```

Match metadata provides migration progress without inspecting source documents again. See [`examples/configuration-migration.php`](examples/configuration-migration.php).

### HTTP request boundaries

Apply a contract immediately after the server's request-size limit and JSON content-type checks. Unknown client properties are discarded, and business logic receives only the named, validated shape:

```php
$registration = Contract::define([
    'email' => Field::required('$.email')->string()->email()->maxLength(254),
    'displayName' => Field::required('$.profile.display_name')
        ->string()
        ->minLength(2)
        ->maxLength(80),
    'locale' => Field::optional('$.profile.locale')
        ->string()
        ->oneOf(['de-DE', 'en-GB', 'en-US'])
        ->default('en-US'),
]);

$result = $registration->extractJson($requestBody);

if (!$result->isValid()) {
    return new JsonResponse(['violations' => $result->violations()], 422);
}

$commandBus->dispatch(new RegisterCustomer(...$result->value()));
```

The framework-independent runnable version also demonstrates bounded referral codes: [`examples/http-request.php`](examples/http-request.php).

### DTO construction

Keep transport normalization separate from object construction:

```php
$values = $customerContract->applyJson($responseBody);
$customer = new CustomerData(...$values);
```

### Framework controllers

The library needs no framework adapter. Read the raw request body, apply a reusable contract service, and translate violations into the framework's error response at the outermost layer. This keeps contract behavior identical in HTTP, CLI, queue, and test environments.

## API reference

### `Contract`

| Method | Purpose |
|---|---|
| `Contract::define(array $fields, ?ContractOptions $options = null)` | validates and creates a contract |
| `extend(array $fields)` | returns a contract with additional unique fields |
| `merge(Contract $contract)` | combines unique fields and validators; stricter limits win |
| `validate(Closure $validator, string $message = ..., string $field = '$')` | adds a cross-field validator |
| `extract(mixed $payload)` | returns a `Result` for decoded input |
| `extractJson(string $json)` | decodes raw JSON and returns a `Result` |
| `apply(mixed $payload)` | returns valid normalized data or throws |
| `applyJson(string $json)` | decodes and returns valid normalized data or throws |
| `fields()` | returns the field-definition map |
| `limits()` | returns effective resource limits |
| `options()` | returns comparison and resource options |

### `ContractOptions`

| Option | Default | Purpose |
|---|---|---|
| `caseSensitive` | `true` | controls built-in textual comparisons across the contract |
| `limits` | `new ResourceLimits()` | controls raw JSON and selector resource ceilings |
| `allowCustomObjects` | `false` | permits trusted application objects and `ArrayAccess` during decoded extraction |
| `rejectDuplicateObjectMembers` | `true` | rejects ambiguous repeated object names in raw JSON |

### `Field`

| Group | Methods |
|---|---|
| factories | `required()`, `optional()`, `many()` |
| selection | `from()`, `fallback()` |
| presence | `nullable()`, `default()` |
| typing | `mixed()`, `null()`, `scalar()`, `string()`, `integer()`, `float()`, `number()`, `boolean()`, `array()`, `list()`, `associativeArray()`, `object()`, `collection()`, `coerce()` |
| match count | `minMatches()`, `maxMatches()`, `matchesBetween()` |
| constraints | `nonEmpty()`, `email()`, `oneOf()`, `matching()`, `minimum()`, `maximum()`, `between()`, `minLength()`, `maxLength()`, `startsWith()`, `endsWith()`, `contains()`, `minItems()`, `maxItems()`, `uuid()`, `url()`, `ip()`, `dateTime()` |
| composition | `shape()` |
| extension | `validate()`, `map()` |

All configuration methods return a new field. Introspection methods expose selectors, cardinality, type, nullability, coercion, default, nested contract, validators, transformations, and match limits.

### `Result`

| Method | Purpose |
|---|---|
| `isValid()` | whether no violations occurred |
| `data()` | successful fields, possibly partial |
| `value()` | complete valid data or `ExtractionException` |
| `violations()` | ordered list of every violation |
| `matches()` | all match metadata keyed by field |
| `match($field)` | metadata for one field or null |
| `jsonSerialize()` | logging-friendly result shape |

## Design guarantees

- Definitions are immutable and reusable.
- Output contains only explicitly named contract fields.
- Selectors are absolute and validated at definition time.
- Fallback order and match metadata are deterministic.
- Text comparisons are case-sensitive unless the contract explicitly opts into ASCII-insensitive comparison.
- Missing input and explicit null are never conflated.
- Invalid input does not mutate the source value.
- Exceptions from validation and transformation callbacks fail closed.
- Selector failures from custom decoded objects are contained and do not expose exception details.
- Duplicate JSON members, cyclic graphs, and non-JSON decoded values fail closed by default.
- Raw decoding, decoded graph size, selector complexity, evaluation work, diagnostics, and match fan-out are bounded by default.
- Payload-controlled strings are never executed as PHP.

## Performance

Contract construction validates and primes selectors once. During extraction, one JSONPath evaluator is reused and duplicate selector results are cached for that payload. Fallback evaluation stops at the first match. Validation and transformation run only for selected values, and resource checks happen before per-item normalization.

Define contracts once and reuse them—for example as services, static definitions, or long-lived worker dependencies. Rebuilding a contract per payload repeats selector parsing and discards the main optimization.

Run the bundled representative benchmark on the production PHP build and hardware you care about:

```bash
composer benchmark -- 100000
```

The benchmark reuses a four-field contract with a list and a shared selector. It reports throughput and latency without enforcing a machine-dependent threshold. Compare before and after changes under the same PHP build, extensions, CPU policy, and operating system.

## Examples

- [`examples/versioned-webhook.php`](examples/versioned-webhook.php) — selector fallbacks across webhook versions
- [`examples/nested-order.php`](examples/nested-order.php) — nested line-item normalization and diagnostics
- [`examples/openai-structured-output.php`](examples/openai-structured-output.php) — OpenAI response-envelope and generated-JSON validation
- [`examples/third-party-api.php`](examples/third-party-api.php) — stable output across third-party API versions
- [`examples/queue-consumer.php`](examples/queue-consumer.php) — dead-letter diagnostics for malformed queue events
- [`examples/configuration-migration.php`](examples/configuration-migration.php) — legacy-key fallbacks, defaults, and migration telemetry
- [`examples/http-request.php`](examples/http-request.php) — strict HTTP request boundary with unknown-field removal

Run any example after `composer install`:

```bash
php examples/versioned-webhook.php
php examples/nested-order.php
php examples/openai-structured-output.php
php examples/third-party-api.php
php examples/queue-consumer.php
php examples/configuration-migration.php
php examples/http-request.php
```

## Development

```bash
composer install
composer test
composer phpstan
composer cs

# Xdebug must be loaded for the full release checks.
XDEBUG_MODE=coverage composer coverage
XDEBUG_MODE=coverage composer check
```

`composer check` requires exactly 100% class, method, and line coverage before running PHPStan, PHPCS, and PHP-CS-Fixer. CI tests supported PHP versions independently and runs the coverage gate with Xdebug.

## License

[ISC](LICENSE) © Sascha Greuel.

ISC provides the practical freedoms of a short permissive license while keeping the full license text compact.
