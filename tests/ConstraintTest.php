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
use SoftCreatR\JsonPayloadContract\Contract;
use SoftCreatR\JsonPayloadContract\ContractOptions;
use SoftCreatR\JsonPayloadContract\Field;
use SoftCreatR\JsonPayloadContract\ViolationCode;

/**
 * Covers built-in constraints and stable machine-readable failures.
 */
final class ConstraintTest extends TestCase
{
    #[DataProvider('validConstraints')]
    public function testBuiltInConstraintsAcceptValidValues(Field $field, mixed $value): void
    {
        $contract = Contract::define(['value' => $field->from('$.value')]);

        self::assertSame($value, $contract->apply(['value' => $value])['value']);
    }

    /**
     * @return iterable<string, array{Field, mixed}>
     */
    public static function validConstraints(): iterable
    {
        yield 'inclusive numeric range' => [Field::required()->number()->between(1, 3), 2];
        yield 'unicode string range' => [Field::required()->string()->minLength(2)->maxLength(2), '日本'];
        yield 'strict prefix' => [Field::required()->string()->startsWith('Event.'), 'Event.Created'];
        yield 'strict suffix' => [Field::required()->string()->endsWith('.json'), 'payload.json'];
        yield 'strict substring' => [Field::required()->string()->contains('Payload'), 'JsonPayloadContract'];
        yield 'array size range' => [Field::required()->array()->minItems(1)->maxItems(2), [1, 2]];
        yield 'UUID' => [Field::required()->string()->uuid(), '123e4567-e89b-12d3-a456-426614174000'];
        yield 'URL' => [Field::required()->string()->url(), 'https://example.com/path'];
        yield 'custom URL scheme' => [Field::required()->string()->url(['git']), 'git://example.com/repository'];
        yield 'IPv4' => [Field::required()->string()->ip(), '192.0.2.1'];
        yield 'IPv6' => [Field::required()->string()->ip(), '2001:db8::1'];
        yield 'date and time' => [Field::required()->string()->dateTime(), '2026-10-03T12:30:45+00:00'];
    }

    #[DataProvider('invalidConstraints')]
    public function testBuiltInConstraintsReportSpecificCodes(
        Field $field,
        mixed $value,
        ViolationCode $code,
    ): void {
        $contract = Contract::define(['value' => $field->from('$.value')]);

        self::assertSame($code, $contract->extract(['value' => $value])->violations()[0]->code);
    }

    /**
     * @return iterable<string, array{Field, mixed, ViolationCode}>
     */
    public static function invalidConstraints(): iterable
    {
        yield 'below minimum' => [Field::required()->minimum(2), 1, ViolationCode::BelowMinimum];
        yield 'minimum receives non-number' => [Field::required()->minimum(2), '2', ViolationCode::BelowMinimum];
        yield 'above maximum' => [Field::required()->maximum(2), 3, ViolationCode::AboveMaximum];
        yield 'maximum receives non-number' => [Field::required()->maximum(2), '2', ViolationCode::AboveMaximum];
        yield 'too short' => [Field::required()->minLength(2), 'a', ViolationCode::TooShort];
        yield 'minimum length receives non-string' => [Field::required()->minLength(2), 2, ViolationCode::TooShort];
        yield 'invalid UTF-8' => [Field::required()->minLength(1), "\xFF", ViolationCode::TooShort];
        yield 'too long' => [Field::required()->maxLength(1), 'ab', ViolationCode::TooLong];
        yield 'maximum length receives non-string' => [Field::required()->maxLength(1), 1, ViolationCode::TooLong];
        yield 'prefix is case-sensitive by default' => [
            Field::required()->startsWith('Event.'),
            'event.Created',
            ViolationCode::MissingPrefix,
        ];
        yield 'prefix receives non-string' => [
            Field::required()->startsWith('Event.'),
            1,
            ViolationCode::MissingPrefix,
        ];
        yield 'suffix is case-sensitive by default' => [
            Field::required()->endsWith('.JSON'),
            'payload.json',
            ViolationCode::MissingSuffix,
        ];
        yield 'suffix receives non-string' => [
            Field::required()->endsWith('.json'),
            1,
            ViolationCode::MissingSuffix,
        ];
        yield 'suffix is longer than value' => [
            Field::required()->endsWith('payload.json'),
            'json',
            ViolationCode::MissingSuffix,
        ];
        yield 'substring is case-sensitive by default' => [
            Field::required()->contains('Payload'),
            'jsonpayloadcontract',
            ViolationCode::SubstringNotFound,
        ];
        yield 'substring receives non-string' => [
            Field::required()->contains('Payload'),
            1,
            ViolationCode::SubstringNotFound,
        ];
        yield 'too few items' => [Field::required()->minItems(2), [1], ViolationCode::TooFewItems];
        yield 'minimum items receives non-array' => [Field::required()->minItems(1), 'x', ViolationCode::TooFewItems];
        yield 'too many items' => [Field::required()->maxItems(1), [1, 2], ViolationCode::TooManyItems];
        yield 'maximum items receives non-array' => [Field::required()->maxItems(1), 'x', ViolationCode::TooManyItems];
        yield 'invalid UUID' => [Field::required()->uuid(), 'not-a-uuid', ViolationCode::InvalidUuid];
        yield 'UUID receives non-string' => [Field::required()->uuid(), 1, ViolationCode::InvalidUuid];
        yield 'invalid URL' => [Field::required()->url(), 'relative/path', ViolationCode::InvalidUrl];
        yield 'unsafe URL scheme by default' => [
            Field::required()->url(),
            'file:///etc/passwd',
            ViolationCode::InvalidUrl,
        ];
        yield 'URL receives non-string' => [Field::required()->url(), 1, ViolationCode::InvalidUrl];
        yield 'invalid IP' => [Field::required()->ip(), '999.1.1.1', ViolationCode::InvalidIp];
        yield 'IP receives non-string' => [Field::required()->ip(), 1, ViolationCode::InvalidIp];
        yield 'invalid date' => [Field::required()->dateTime('Y-m-d'), '2026-02-30', ViolationCode::InvalidDateTime];
        yield 'date receives non-string' => [Field::required()->dateTime(), 1, ViolationCode::InvalidDateTime];
    }

    public function testManyMatchRangesAreInclusiveAndReportBothBoundaries(): void
    {
        self::assertCount(2, Field::required()->between(1, 2)->validators());

        $field = Field::many('$.values[*]')->matchesBetween(2, 3);
        $contract = Contract::define(['values' => $field]);

        self::assertSame(2, $field->minimumMatches());
        self::assertSame(3, $field->maximumMatches());
        self::assertSame([1, 2], $contract->apply(['values' => [1, 2]])['values']);
        self::assertSame(
            ViolationCode::TooFewMatches,
            $contract->extract(['values' => [1]])->violations()[0]->code,
        );
        self::assertSame(
            ViolationCode::TooManyMatches,
            $contract->extract(['values' => [1, 2, 3, 4]])->violations()[0]->code,
        );
    }

    public function testCustomValidatorsCanExposeDomainSpecificCodes(): void
    {
        self::assertCount(1, Field::required()->url(['HTTPS'])->validators());

        $contract = Contract::define([
            'value' => Field::required('$.value')->validate(
                static fn(): bool => false,
                'The value is not allowed.',
                ViolationCode::NotAllowed,
            ),
        ]);

        self::assertSame(
            ViolationCode::NotAllowed,
            $contract->extract(['value' => 'x'])->violations()[0]->code,
        );
    }

    public function testContractCanUseAsciiInsensitiveTextComparisons(): void
    {
        $contract = Contract::define([
            'allowlist' => Field::required('$.value')->oneOf(['EVENT.CREATED']),
            'prefix' => Field::required('$.value')->startsWith('event.'),
            'suffix' => Field::required('$.value')->endsWith('.created'),
            'substring' => Field::required('$.value')->contains('NT.CR'),
        ], new ContractOptions(caseSensitive: false));

        self::assertSame([
            'allowlist' => 'Event.Created',
            'prefix' => 'Event.Created',
            'suffix' => 'Event.Created',
            'substring' => 'Event.Created',
        ], $contract->apply(['value' => 'Event.Created']));

        $shortSuffix = Contract::define([
            'value' => Field::required('$.value')->endsWith('payload.json'),
        ], new ContractOptions(caseSensitive: false));

        self::assertSame(
            ViolationCode::MissingSuffix,
            $shortSuffix->extract(['value' => 'json'])->violations()[0]->code,
        );
    }

    public function testStrictCaseWinsWhenContractsAreMerged(): void
    {
        $insensitive = Contract::define([
            'prefix' => Field::required('$.value')->startsWith('event.'),
        ], new ContractOptions(caseSensitive: false));
        $strict = Contract::define([
            'suffix' => Field::required('$.value')->endsWith('.CREATED'),
        ]);
        $merged = $insensitive->merge($strict);

        self::assertTrue($merged->options()->caseSensitive);
        self::assertSame([
            ViolationCode::MissingPrefix,
            ViolationCode::MissingSuffix,
        ], \array_column($merged->extract(['value' => 'Event.Created'])->violations(), 'code'));
    }
}
