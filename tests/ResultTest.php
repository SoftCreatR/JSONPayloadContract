<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Tests;

use PHPUnit\Framework\TestCase;
use SoftCreatR\JsonPayloadContract\MatchResult;
use SoftCreatR\JsonPayloadContract\Result;
use SoftCreatR\JsonPayloadContract\Violation;
use SoftCreatR\JsonPayloadContract\ViolationCode;

/**
 * Covers result metadata and JSON serialization.
 */
final class ResultTest extends TestCase
{
    public function testResultAndDiagnosticsAreJsonSerializable(): void
    {
        $violation = new Violation(
            'id',
            ViolationCode::UnexpectedType,
            'Expected integer.',
            '$.id',
            'string',
        );
        $match = new MatchResult('id', '$.legacy.id', 1, true, false);
        $result = new Result(['name' => 'Ada'], [$violation], ['id' => $match]);

        self::assertSame([
            'field' => 'id',
            'code' => 'unexpected_type',
            'message' => 'Expected integer.',
            'selector' => '$.id',
            'actualType' => 'string',
        ], $violation->jsonSerialize());
        self::assertSame([
            'field' => 'id',
            'selector' => '$.legacy.id',
            'count' => 1,
            'usedFallback' => true,
            'usedDefault' => false,
        ], $match->jsonSerialize());
        self::assertSame([
            'valid' => false,
            'data' => ['name' => 'Ada'],
            'violations' => [$violation],
            'matches' => ['id' => $match],
        ], $result->jsonSerialize());
        self::assertSame(['id' => $match], $result->matches());
        self::assertNull($result->match('missing'));
    }

    public function testViolationPrefixHandlesNestedAndEmptyNamesAndOmitsNullMetadata(): void
    {
        $nested = new Violation('city', ViolationCode::ValidationFailed, 'Invalid.');
        $root = new Violation('', ViolationCode::ValidationFailed, 'Invalid.');

        self::assertSame('address.city', $nested->prefixed('address')->field);
        self::assertSame('address', $root->prefixed('address')->field);
        self::assertSame([
            'field' => 'city',
            'code' => 'validation_failed',
            'message' => 'Invalid.',
        ], $nested->jsonSerialize());
    }
}
