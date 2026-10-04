<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SoftCreatR\JsonPayloadContract\Cardinality;
use SoftCreatR\JsonPayloadContract\Contract;
use SoftCreatR\JsonPayloadContract\ContractOptions;
use SoftCreatR\JsonPayloadContract\Exception\ContractDefinitionException;
use SoftCreatR\JsonPayloadContract\Field;
use SoftCreatR\JsonPayloadContract\ResourceLimits;
use SoftCreatR\JsonPayloadContract\ValueType;

/**
 * Covers immutable field construction and definition-time failures.
 */
final class DefinitionTest extends TestCase
{
    public function testFieldBuildersAreImmutable(): void
    {
        $base = Field::required('$.id');
        $configured = $base
            ->from('$.newId')
            ->fallback('$.legacyId')
            ->string()
            ->nullable()
            ->coerce()
            ->validate(static fn(): bool => true)
            ->map(static fn(mixed $value): mixed => $value);

        self::assertSame(['$.id'], $base->selectors());
        self::assertSame(ValueType::Mixed, $base->type());
        self::assertFalse($base->allowsNull());
        self::assertFalse($base->allowsCoercion());
        self::assertSame(['$.newId', '$.legacyId'], $configured->selectors());
        self::assertSame(ValueType::String, $configured->type());
        self::assertTrue($configured->allowsNull());
        self::assertTrue($configured->allowsCoercion());
        self::assertCount(1, $configured->validators());
        self::assertCount(1, $configured->transformers());
    }

    public function testFactoriesDefineCardinalityAndOptionalDefault(): void
    {
        $required = Field::required();
        $optional = Field::optional()->default(null);
        $many = Field::many();

        self::assertSame(Cardinality::Required, $required->cardinality());
        self::assertSame(Cardinality::Optional, $optional->cardinality());
        self::assertSame(Cardinality::Many, $many->cardinality());
        self::assertTrue($optional->hasDefault());
        self::assertNull($optional->defaultValue());
    }

    public function testMixedCanResetAFieldType(): void
    {
        self::assertSame(ValueType::Mixed, Field::required()->string()->mixed()->type());
    }

    public function testFormatValidatorBuildersReturnConfiguredFields(): void
    {
        self::assertCount(1, Field::required()->uuid()->validators());
        self::assertCount(1, Field::required()->ip()->validators());
    }

    public function testShapeExposesNestedContract(): void
    {
        $nested = Contract::define(['id' => Field::required('$.id')]);
        $field = Field::required('$.nested')->shape($nested);

        self::assertSame($nested, $field->nestedContract());
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    #[DataProvider('invalidContractDefinitions')]
    public function testRejectsInvalidContractDefinitions(array $fields, string $message): void
    {
        $this->expectException(ContractDefinitionException::class);
        $this->expectExceptionMessage($message);

        Contract::define($fields);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidContractDefinitions(): iterable
    {
        yield 'empty contract' => [[], 'at least one field'];
        yield 'numeric name' => [[Field::required('$.id')], 'non-empty string name'];
        yield 'empty name' => [['' => Field::required('$.id')], 'non-empty string name'];
        yield 'wrong value' => [['id' => 'not a field'], 'must be a Field instance'];
        yield 'no selector' => [['id' => Field::required()], 'has no JSONPath selector'];
        yield 'relative selector' => [['id' => Field::required('id')], 'absolute JSONPath'];
        yield 'empty selector' => [['id' => Field::required('')], 'absolute JSONPath'];
        yield 'invalid selector' => [['id' => Field::required('$[')], 'is invalid'];
        yield 'ill-typed selector' => [
            ['id' => Field::required('$[?length(@.*) < 3]')],
            'is invalid',
        ];
    }

    #[DataProvider('invalidFieldRules')]
    public function testRejectsInvalidFieldRules(callable $configure, string $message): void
    {
        $this->expectException(ContractDefinitionException::class);
        $this->expectExceptionMessage($message);

        $configure();
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function invalidFieldRules(): iterable
    {
        yield 'required default' => [
            static fn() => Field::required()->default('value'),
            'only valid for optional fields',
        ];
        yield 'many default' => [
            static fn() => Field::many()->default([]),
            'only valid for optional fields',
        ];
        yield 'empty one-of list' => [
            static fn() => Field::required()->oneOf([]),
            'must not be empty',
        ];
        yield 'invalid regular expression' => [
            static fn() => Field::required()->matching('/[/'),
            'not a valid PCRE pattern',
        ];
        yield 'minimum matches on singular field' => [
            static fn() => Field::required()->minMatches(1),
            'only valid for many-value fields',
        ];
        yield 'maximum matches on singular field' => [
            static fn() => Field::optional()->maxMatches(1),
            'only valid for many-value fields',
        ];
        yield 'negative minimum matches' => [
            static fn() => Field::many()->minMatches(-1),
            'must not be negative',
        ];
        yield 'negative maximum matches' => [
            static fn() => Field::many()->maxMatches(-1),
            'must not be negative',
        ];
        yield 'minimum exceeds configured maximum matches' => [
            static fn() => Field::many()->maxMatches(1)->minMatches(2),
            'minimum match count must not exceed',
        ];
        yield 'maximum below configured minimum matches' => [
            static fn() => Field::many()->minMatches(2)->maxMatches(1),
            'maximum match count must not be less',
        ];
        yield 'reversed match range' => [
            static fn() => Field::many()->matchesBetween(2, 1),
            'minimum match count must not exceed',
        ];
        yield 'reversed numeric range' => [
            static fn() => Field::required()->between(2, 1),
            'minimum value must not exceed',
        ];
        yield 'negative minimum string length' => [
            static fn() => Field::required()->minLength(-1),
            'must not be negative',
        ];
        yield 'negative maximum string length' => [
            static fn() => Field::required()->maxLength(-1),
            'must not be negative',
        ];
        yield 'empty prefix' => [
            static fn() => Field::required()->startsWith(''),
            'prefix must not be empty',
        ];
        yield 'empty suffix' => [
            static fn() => Field::required()->endsWith(''),
            'suffix must not be empty',
        ];
        yield 'empty substring' => [
            static fn() => Field::required()->contains(''),
            'substring must not be empty',
        ];
        yield 'negative minimum item count' => [
            static fn() => Field::required()->minItems(-1),
            'must not be negative',
        ];
        yield 'negative maximum item count' => [
            static fn() => Field::required()->maxItems(-1),
            'must not be negative',
        ];
        yield 'empty date format' => [
            static fn() => Field::required()->dateTime(''),
            'must not be empty',
        ];
        yield 'empty URL scheme list' => [
            static fn() => Field::required()->url([]),
            'scheme list must not be empty',
        ];
        yield 'invalid URL scheme' => [
            static fn() => Field::required()->url(['not a scheme']),
            'URL scheme "not a scheme" is invalid',
        ];
        yield 'zero maximum JSON bytes' => [
            static fn() => new ResourceLimits(maxJsonBytes: 0),
            'maximum JSON byte length must be greater than zero',
        ];
        yield 'zero maximum JSON depth' => [
            static fn() => new ResourceLimits(maxJsonDepth: 0),
            'maximum JSON depth must be greater than zero',
        ];
        yield 'zero maximum matches' => [
            static fn() => new ResourceLimits(maxMatchesPerField: 0),
            'maximum matches per field must be greater than zero',
        ];
        yield 'zero maximum input nodes' => [
            static fn() => new ResourceLimits(maxInputNodes: 0),
            'maximum input node count must be greater than zero',
        ];
        yield 'zero maximum selector bytes' => [
            static fn() => new ResourceLimits(maxSelectorBytes: 0),
            'maximum selector byte length must be greater than zero',
        ];
        yield 'zero maximum evaluation steps' => [
            static fn() => new ResourceLimits(maxEvaluationSteps: 0),
            'maximum evaluation step count must be greater than zero',
        ];
        yield 'zero maximum violations' => [
            static fn() => new ResourceLimits(maxViolations: 0),
            'maximum violation count must be greater than zero',
        ];
    }

    public function testRejectsSelectorsBeyondTheConfiguredByteLimit(): void
    {
        $this->expectException(ContractDefinitionException::class);
        $this->expectExceptionMessage('exceeds the limit of 4 bytes');

        Contract::define(
            ['id' => Field::required('$.identifier')],
            new ContractOptions(limits: new ResourceLimits(maxSelectorBytes: 4)),
        );
    }

    public function testFieldsReturnsTheDefinition(): void
    {
        $field = Field::required('$.id');
        $contract = Contract::define(['id' => $field]);

        self::assertSame(['id' => $field], $contract->fields());
    }

    public function testMergeRejectsDuplicateOutputNames(): void
    {
        $this->expectException(ContractDefinitionException::class);
        $this->expectExceptionMessage('duplicate field "id"');

        Contract::define(['id' => Field::required('$.id')])
            ->merge(Contract::define(['id' => Field::required('$.legacyId')]));
    }
}
