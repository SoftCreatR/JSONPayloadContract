<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Tests;

use ArrayObject;
use Flow\JSONPath\JSONPathException;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SoftCreatR\JsonPayloadContract\Contract;
use SoftCreatR\JsonPayloadContract\ContractOptions;
use SoftCreatR\JsonPayloadContract\Exception\ExtractionException;
use SoftCreatR\JsonPayloadContract\Field;
use SoftCreatR\JsonPayloadContract\Internal\BudgetedJSONPath;
use SoftCreatR\JsonPayloadContract\ResourceLimits;
use SoftCreatR\JsonPayloadContract\ViolationCode;
use stdClass;
use Stringable;

use const INF;

/**
 * Covers extraction, normalization, validation, and diagnostics.
 */
final class ContractTest extends TestCase
{
    public function testExtractsVersionedPayloadWithFallbacksAndMetadata(): void
    {
        $contract = Contract::define([
            'customerId' => Field::required('$.data.customer.id')
                ->fallback('$.customer.id')
                ->string(),
            'email' => Field::optional('$.data.customer.email')
                ->fallback('$.customer.email')
                ->nullable()
                ->string(),
            'roles' => Field::many('$.data.customer.roles[*]')
                ->fallback('$.customer.roles[*]')
                ->string(),
        ]);

        $result = $contract->extract([
            'customer' => [
                'id' => 'cus_123',
                'email' => null,
                'roles' => ['admin', 'billing'],
            ],
        ]);

        self::assertTrue($result->isValid());
        self::assertSame([
            'customerId' => 'cus_123',
            'email' => null,
            'roles' => ['admin', 'billing'],
        ], $result->value());
        self::assertSame('$.customer.id', $result->matches()['customerId']->selector);
        self::assertTrue($result->matches()['customerId']->usedFallback);
        self::assertSame(2, $result->matches()['roles']->count);
    }

    public function testAggregatesCardinalityAndTypeViolations(): void
    {
        $contract = Contract::define([
            'id' => Field::required('$.missing')->integer(),
            'name' => Field::required('$.users[*].name')->string(),
            'active' => Field::required('$.active')->boolean(),
            'nickname' => Field::required('$.nickname')->string(),
        ]);

        $result = $contract->extract([
            'users' => [['name' => 'Ada'], ['name' => 'Grace']],
            'active' => 'yes',
            'nickname' => null,
        ]);

        self::assertFalse($result->isValid());
        self::assertSame([], $result->data());
        self::assertSame([
            ViolationCode::MissingRequired,
            ViolationCode::MultipleMatches,
            ViolationCode::UnexpectedType,
            ViolationCode::NullNotAllowed,
        ], \array_column($result->violations(), 'code'));
        self::assertSame('string', $result->violations()[2]->actualType);
        self::assertSame(2, $result->matches()['name']->count);
    }

    public function testOptionalFieldsCanBeOmittedOrDefaultedAndManyIsAlwaysAList(): void
    {
        $contract = Contract::define([
            'omitted' => Field::optional('$.omitted')->string(),
            'locale' => Field::optional('$.locale')->string()->default('en'),
            'tags' => Field::many('$.tags[*]')->string(),
        ]);

        $result = $contract->extract([]);

        self::assertSame(['locale' => 'en', 'tags' => []], $result->value());
        self::assertFalse($result->matches()['omitted']->usedDefault);
        self::assertTrue($result->matches()['locale']->usedDefault);
        self::assertSame(0, $result->matches()['locale']->count);
    }

    public function testFallbackIsNotUsedWhenPrimarySelectorHasMultipleMatches(): void
    {
        $contract = Contract::define([
            'id' => Field::required('$.items[*].id')->fallback('$.legacyId')->integer(),
        ]);

        $result = $contract->extract([
            'items' => [['id' => 1], ['id' => 2]],
            'legacyId' => 3,
        ]);

        self::assertSame(ViolationCode::MultipleMatches, $result->violations()[0]->code);
        self::assertSame('$.items[*].id', $result->matches()['id']->selector);
        self::assertFalse($result->matches()['id']->usedFallback);
    }

    public function testNestedContractsNormalizeObjectsAndPrefixErrors(): void
    {
        $address = Contract::define([
            'city' => Field::required('$.city')->string()->nonEmpty(),
            'country' => Field::optional('$.country')->string()->default('DE'),
        ]);
        $contract = Contract::define([
            'address' => Field::required('$.address')->shape($address),
            'items' => Field::many('$.items[*]')->shape(Contract::define([
                'sku' => Field::required('$.sku')->string(),
            ])),
        ]);

        self::assertSame([
            'address' => ['city' => 'Berlin', 'country' => 'DE'],
            'items' => [['sku' => 'A-1']],
        ], $contract->apply((object) [
            'address' => (object) ['city' => 'Berlin'],
            'items' => [(object) ['sku' => 'A-1']],
        ]));

        $invalid = $contract->extract([
            'address' => ['city' => ''],
            'items' => [['name' => 'missing SKU']],
        ]);

        self::assertSame(['address.city', 'items.0.sku'], \array_column($invalid->violations(), 'field'));
    }

    public function testNestedContractRejectsScalarInput(): void
    {
        $contract = Contract::define([
            'profile' => Field::required('$.profile')->shape(Contract::define([
                'name' => Field::required('$.name'),
            ])),
        ]);

        $result = $contract->extract(['profile' => 'Ada']);

        self::assertSame(ViolationCode::UnexpectedType, $result->violations()[0]->code);
        self::assertSame('A nested contract requires an array or object value.', $result->violations()[0]->message);
    }

    #[DataProvider('strictTypes')]
    public function testSupportsStrictValueTypes(string $method, mixed $value): void
    {
        $field = self::fieldWithType($method);
        $contract = Contract::define(['value' => $field]);

        self::assertSame($value, $contract->apply(['value' => $value])['value']);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function strictTypes(): iterable
    {
        yield 'mixed' => ['mixed', new stdClass()];
        yield 'null' => ['null', null];
        yield 'scalar' => ['scalar', true];
        yield 'string' => ['string', 'value'];
        yield 'integer' => ['integer', 42];
        yield 'float' => ['float', 4.2];
        yield 'number with integer' => ['number', 42];
        yield 'number with float' => ['number', 4.2];
        yield 'boolean' => ['boolean', true];
        yield 'array' => ['array', ['value']];
        yield 'list' => ['list', ['value']];
        yield 'associative array' => ['associativeArray', ['key' => 'value']];
        yield 'object' => ['object', (object) ['value' => true]];
        yield 'collection' => ['collection', (object) ['value' => true]];
    }

    #[DataProvider('coercions')]
    public function testSupportsExplicitCoercion(string $method, mixed $input, mixed $expected): void
    {
        $contract = Contract::define([
            'value' => self::fieldWithType($method)->coerce(),
        ], new ContractOptions(allowCustomObjects: \is_object($input) && !$input instanceof stdClass));

        self::assertSame($expected, $contract->apply(['value' => $input])['value']);
    }

    /**
     * @return iterable<string, array{string, mixed, mixed}>
     */
    public static function coercions(): iterable
    {
        $object = new stdClass();

        yield 'mixed' => ['mixed', $object, $object];
        yield 'integer' => ['integer', '+42', 42];
        yield 'float from string' => ['float', '4.25', 4.25];
        yield 'float from integer' => ['float', 4, 4.0];
        yield 'number integer' => ['number', '-42', -42];
        yield 'number float' => ['number', '4.25', 4.25];
        yield 'true' => ['boolean', 'yes', true];
        yield 'false' => ['boolean', 0, false];
        yield 'stringable scalar' => ['scalar', new class implements Stringable {
            #[Override]
            public function __toString(): string
            {
                return 'object';
            }
        }, 'object'];
        yield 'scalar string' => ['string', 42, '42'];
        yield 'stringable string' => ['string', new class implements Stringable {
            #[Override]
            public function __toString(): string
            {
                return 'object';
            }
        }, 'object'];
    }

    #[DataProvider('failedCoercions')]
    public function testRejectsValuesThatCannotBeCoerced(string $method, mixed $value): void
    {
        $contract = Contract::define([
            'value' => self::fieldWithType($method)->coerce(),
        ], new ContractOptions(allowCustomObjects: \is_object($value) && !$value instanceof stdClass));

        self::assertSame(ViolationCode::UnexpectedType, $contract->extract(['value' => $value])->violations()[0]->code);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function failedCoercions(): iterable
    {
        yield 'integer syntax' => ['integer', '4.2'];
        yield 'integer range' => ['integer', '999999999999999999999999'];
        yield 'float type' => ['float', []];
        yield 'float value' => ['float', 'nope'];
        yield 'boolean type' => ['boolean', []];
        yield 'boolean value' => ['boolean', 'maybe'];
        yield 'null remains strict' => ['null', 'null'];
        yield 'scalar type' => ['scalar', new stdClass()];
        yield 'string type' => ['string', []];
        yield 'array remains strict' => ['array', new ArrayObject()];
        yield 'list remains strict' => ['list', ['key' => 'value']];
        yield 'associative array remains strict' => ['associativeArray', ['value']];
        yield 'collection remains strict' => ['collection', 42];
    }

    public function testAppliesValidationAndTransformationRules(): void
    {
        $metadata = (object) ['source' => 'api'];
        $contract = Contract::define([
            'email' => Field::required('$.email')->string()->email(),
            'status' => Field::required('$.status')->string()->oneOf(['open', 'closed']),
            'reference' => Field::required('$.reference')->string()->matching('/^[A-Z]-\d+$/'),
            'name' => Field::required('$.name')->string()->nonEmpty()->map('strtoupper'(...)),
            'metadata' => Field::required('$.metadata')->object()->nonEmpty(),
        ]);

        self::assertSame([
            'email' => 'ada@example.com',
            'status' => 'open',
            'reference' => 'A-12',
            'name' => 'ADA',
            'metadata' => $metadata,
        ], $contract->apply([
            'email' => 'ada@example.com',
            'status' => 'open',
            'reference' => 'A-12',
            'name' => 'Ada',
            'metadata' => $metadata,
        ]));

        $invalid = $contract->extract([
            'email' => 'invalid',
            'status' => 'unknown',
            'reference' => 'wrong',
            'name' => '',
            'metadata' => new stdClass(),
        ]);

        self::assertCount(5, $invalid->violations());
        self::assertSame([
            ViolationCode::InvalidEmail,
            ViolationCode::NotAllowed,
            ViolationCode::PatternMismatch,
            ViolationCode::Empty,
            ViolationCode::Empty,
        ], \array_column($invalid->violations(), 'code'));
    }

    public function testCallbackFailuresBecomeViolations(): void
    {
        $contract = Contract::define([
            'validated' => Field::required('$.validated')->validate(
                static function (): never {
                    throw new RuntimeException('No');
                },
                'Custom validation failed.',
            ),
            'mapped' => Field::required('$.mapped')->map(static function (): never {
                throw new RuntimeException('No');
            }),
        ]);

        $result = $contract->extract(['validated' => true, 'mapped' => true]);

        self::assertSame([
            ViolationCode::ValidationFailed,
            ViolationCode::TransformationFailed,
        ], \array_column($result->violations(), 'code'));
    }

    public function testContractValidationIsImmutableAndAggregatesCrossFieldFailures(): void
    {
        $base = Contract::define([
            'minimum' => Field::required('$.minimum')->integer(),
            'maximum' => Field::required('$.maximum')->integer(),
        ]);
        $contract = $base
            ->validate(
                static fn(array $data): bool => $data['minimum'] <= $data['maximum'],
                'The minimum must not exceed the maximum.',
                'range',
            )
            ->validate(static function (): never {
                throw new RuntimeException('Validation service failed.');
            }, 'The external validation could not be completed.');

        self::assertTrue($base->extract(['minimum' => 10, 'maximum' => 1])->isValid());

        $result = $contract->extract(['minimum' => 10, 'maximum' => 1]);

        self::assertSame(['range', '$'], \array_column($result->violations(), 'field'));
        self::assertSame('array', $result->violations()[0]->actualType);
    }

    public function testContractValidationRunsOnlyAfterFieldsPass(): void
    {
        $calls = 0;
        $contract = Contract::define([
            'id' => Field::required('$.id')->integer(),
        ])->validate(static function () use (&$calls): bool {
            ++$calls;

            return true;
        });

        self::assertTrue($contract->extract(['id' => 1])->isValid());
        self::assertSame(1, $calls);

        self::assertFalse($contract->extract(['id' => 'invalid'])->isValid());
        self::assertSame(1, $calls);
    }

    public function testContractsCanBeExtendedAndMergedWithoutMutation(): void
    {
        $base = Contract::define([
            'id' => Field::required('$.id')->integer(),
        ], new ContractOptions(
            false,
            new ResourceLimits(100, 10, 5, 50, 100, 500, 20),
            true,
            false,
        ))->validate(
            static fn(array $data): bool => $data['id'] > 0,
            'The identifier must be positive.',
            'id',
        );
        $extension = Contract::define([
            'name' => Field::required('$.name')->string(),
        ], new ContractOptions(
            false,
            new ResourceLimits(80, 20, 3, 60, 90, 400, 30),
        ))->validate(
            static fn(array $data): bool => $data['name'] !== '',
            'The name must not be empty.',
            'name',
        );

        $extended = $base->extend(['enabled' => Field::required('$.enabled')->boolean()]);
        $merged = $base->merge($extension);

        self::assertSame(['id'], \array_keys($base->fields()));
        self::assertSame(
            ['id' => 1, 'enabled' => true],
            $extended->apply(['id' => 1, 'enabled' => true]),
        );
        self::assertSame(['id', 'name'], \array_keys($merged->fields()));
        self::assertSame(80, $merged->limits()->maxJsonBytes);
        self::assertSame(10, $merged->limits()->maxJsonDepth);
        self::assertSame(3, $merged->limits()->maxMatchesPerField);
        self::assertSame(50, $merged->limits()->maxInputNodes);
        self::assertSame(90, $merged->limits()->maxSelectorBytes);
        self::assertSame(400, $merged->limits()->maxEvaluationSteps);
        self::assertSame(20, $merged->limits()->maxViolations);
        self::assertFalse($base->options()->caseSensitive);
        self::assertFalse($extended->options()->caseSensitive);
        self::assertFalse($merged->options()->caseSensitive);
        self::assertTrue($base->options()->allowCustomObjects);
        self::assertFalse($merged->options()->allowCustomObjects);
        self::assertFalse($base->options()->rejectDuplicateObjectMembers);
        self::assertTrue($merged->options()->rejectDuplicateObjectMembers);

        $invalid = $merged->extract(['id' => 0, 'name' => '']);

        self::assertSame(['id', 'name'], \array_column($invalid->violations(), 'field'));
    }

    public function testIdenticalSelectorsCanFeedIndependentFields(): void
    {
        $contract = Contract::define([
            'original' => Field::required('$.name')->string(),
            'normalized' => Field::required('$.name')->string()->map('strtoupper'(...)),
        ]);

        self::assertSame(
            ['original' => 'Ada', 'normalized' => 'ADA'],
            $contract->apply(['name' => 'Ada']),
        );
    }

    public function testSelectorEvaluationFailuresBecomeViolations(): void
    {
        $contract = Contract::define([
            'id' => Field::required('$.id')->string(),
        ], new ContractOptions(allowCustomObjects: true));
        $payload = new class extends ArrayObject {
            #[Override]
            public function offsetExists(mixed $key): bool
            {
                throw new JSONPathException('The collection cannot be read.');
            }
        };

        $result = $contract->extract($payload);

        self::assertSame(ViolationCode::SelectorEvaluationFailed, $result->violations()[0]->code);
        self::assertSame('id', $result->violations()[0]->field);
        self::assertStringContainsString('collection cannot be read', $result->violations()[0]->message);
        self::assertSame(0, $result->matches()['id']->count);
    }

    public function testUnexpectedObjectFailuresBecomeSafeSelectorViolations(): void
    {
        $contract = Contract::define([
            'id' => Field::required('$.id')->string(),
        ], new ContractOptions(allowCustomObjects: true));
        $payload = new class extends ArrayObject {
            #[Override]
            public function offsetExists(mixed $key): bool
            {
                throw new RuntimeException('Sensitive implementation detail.');
            }
        };

        $result = $contract->extract($payload);

        self::assertSame(ViolationCode::SelectorEvaluationFailed, $result->violations()[0]->code);
        self::assertSame('The selector could not be evaluated safely.', $result->violations()[0]->message);
    }

    public function testManyReportsFailingItemIndexWithoutReturningPartialField(): void
    {
        $contract = Contract::define([
            'ids' => Field::many('$.ids[*]')->integer(),
        ]);

        $result = $contract->extract(['ids' => [1, 'two', 3]]);

        self::assertSame('ids.1', $result->violations()[0]->field);
        self::assertSame([], $result->data());
    }

    public function testExtractsJsonAndReportsInvalidJson(): void
    {
        $contract = Contract::define(['id' => Field::required('$.id')->integer()]);

        self::assertSame(['id' => 42], $contract->applyJson('{"id":42}'));

        $result = $contract->extractJson('{');

        self::assertSame(ViolationCode::InvalidJson, $result->violations()[0]->code);
        self::assertSame('string', $result->violations()[0]->actualType);
        self::assertSame('$', $result->violations()[0]->field);
    }

    public function testRejectsDuplicateJsonMembersIncludingEscapedNames(): void
    {
        $contract = Contract::define(['value' => Field::required('$.outer.a')->integer()]);
        $duplicate = $contract->extractJson(
            "{\n \"emptyObject\": {}, \"emptyArray\": [], "
            . '"values":[true,false,null,"x\\n",-1.5e2],"outer":{"a":1,"\\u0061":2}}',
        );

        self::assertSame(ViolationCode::DuplicateObjectMember, $duplicate->violations()[0]->code);

        $permissive = Contract::define(
            ['value' => Field::required('$.a')->integer()],
            new ContractOptions(rejectDuplicateObjectMembers: false),
        );

        self::assertSame(['value' => 2], $permissive->applyJson('{"a":1,"a":2}'));
    }

    public function testDecodedInputGuardRejectsCyclesAndNonJsonTypes(): void
    {
        $contract = Contract::define(['value' => Field::optional('$.value')]);
        $array = [];
        $array['self'] = &$array;
        $object = new stdClass();
        $object->self = $object;
        $resource = \fopen('php://memory', 'rb');

        self::assertIsResource($resource);
        self::assertSame(ViolationCode::CyclicPayload, $contract->extract($array)->violations()[0]->code);
        self::assertSame(ViolationCode::CyclicPayload, $contract->extract($object)->violations()[0]->code);
        self::assertSame(ViolationCode::UnsupportedPayloadType, $contract->extract($resource)->violations()[0]->code);
        self::assertSame(ViolationCode::UnsupportedPayloadType, $contract->extract(INF)->violations()[0]->code);
        self::assertSame(
            ViolationCode::UnsupportedPayloadType,
            $contract->extract(new ArrayObject())->violations()[0]->code,
        );

        \fclose($resource);
    }

    public function testDecodedInputGuardEnforcesDepthAndNodeLimits(): void
    {
        $depthLimited = Contract::define(
            ['value' => Field::optional('$.value')],
            new ContractOptions(limits: new ResourceLimits(maxJsonDepth: 3)),
        );
        $nodeLimited = Contract::define(
            ['value' => Field::optional('$.value')],
            new ContractOptions(limits: new ResourceLimits(maxInputNodes: 2)),
        );

        self::assertSame(
            ViolationCode::PayloadTooDeep,
            $depthLimited->extract([[[]]])->violations()[0]->code,
        );
        self::assertSame(
            ViolationCode::PayloadTooComplex,
            $nodeLimited->extract(['first' => 1, 'second' => 2])->violations()[0]->code,
        );

        $nestedObject = new stdClass();
        $nestedObject->child = new stdClass();
        $objectDepthLimited = Contract::define(
            ['value' => Field::optional('$.value')],
            new ContractOptions(limits: new ResourceLimits(maxJsonDepth: 2)),
        );

        self::assertSame(
            ViolationCode::PayloadTooDeep,
            $objectDepthLimited->extract($nestedObject)->violations()[0]->code,
        );

        $shared = new stdClass();
        $shared->value = 1;
        $allowed = Contract::define([
            'first' => Field::required('$.first.value')->integer(),
            'second' => Field::required('$.second.value')->integer(),
        ]);

        self::assertSame(['first' => 1, 'second' => 1], $allowed->apply(['first' => $shared, 'second' => $shared]));
    }

    public function testEvaluationAndViolationBudgetsFailClosed(): void
    {
        $evaluationLimited = Contract::define(
            ['values' => Field::many('$.values[*]')],
            new ContractOptions(limits: new ResourceLimits(maxEvaluationSteps: 2)),
        );

        self::assertSame(
            ViolationCode::EvaluationLimitExceeded,
            $evaluationLimited->extract(['values' => [1, 2]])->violations()[0]->code,
        );

        $violationLimited = Contract::define([
            'first' => Field::required('$.first'),
            'second' => Field::required('$.second'),
            'third' => Field::required('$.third'),
            'fourth' => Field::required('$.fourth'),
        ], new ContractOptions(limits: new ResourceLimits(maxViolations: 2)));

        self::assertSame([
            ViolationCode::MissingRequired,
            ViolationCode::TooManyViolations,
        ], \array_column($violationLimited->extract([])->violations(), 'code'));
    }

    public function testBudgetedJsonPathCannotRunWithoutAnAttachedBudget(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('evaluation budget is missing');

        (new BudgetedJSONPath([]))->find('$');
    }

    public function testResourceLimitsRejectOversizedDeepAndOvermatchingPayloads(): void
    {
        $limits = new ResourceLimits(maxJsonBytes: 8, maxJsonDepth: 3, maxMatchesPerField: 2);
        $contract = Contract::define([
            'values' => Field::many('$.values[*]'),
        ], new ContractOptions(limits: $limits));

        self::assertSame($limits, $contract->limits());
        self::assertSame(
            ViolationCode::PayloadTooLarge,
            $contract->extractJson('{"value":1}')->violations()[0]->code,
        );
        self::assertSame(
            ViolationCode::PayloadTooDeep,
            $contract->extractJson('[[[]]]')->violations()[0]->code,
        );

        $overmatching = $contract->extract(['values' => [1, 2, 3]]);

        self::assertSame(ViolationCode::TooManyMatches, $overmatching->violations()[0]->code);
        self::assertSame(3, $overmatching->matches()['values']->count);
    }

    public function testApplyThrowsAnExceptionContainingTheResult(): void
    {
        $contract = Contract::define(['id' => Field::required('$.id')->integer()]);

        try {
            $contract->apply([]);
            self::fail('Expected extraction to fail.');
        } catch (ExtractionException $exception) {
            self::assertStringContainsString('id: The required field', $exception->getMessage());
            self::assertSame(ViolationCode::MissingRequired, $exception->result()->violations()[0]->code);
        }
    }

    private static function fieldWithType(string $method): Field
    {
        $field = Field::required('$.value');

        return match ($method) {
            'mixed' => $field->mixed(),
            'null' => $field->null(),
            'scalar' => $field->scalar(),
            'string' => $field->string(),
            'integer' => $field->integer(),
            'float' => $field->float(),
            'number' => $field->number(),
            'boolean' => $field->boolean(),
            'array' => $field->array(),
            'list' => $field->list(),
            'associativeArray' => $field->associativeArray(),
            'object' => $field->object(),
            'collection' => $field->collection(),
            default => throw new LogicException('Unknown test type.'),
        };
    }
}
