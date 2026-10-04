<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Internal;

use Flow\JSONPath\JSONPathException;

/**
 * Signals exhaustion of a contract's shared JSONPath evaluation budget.
 *
 * @internal
 */
final class EvaluationLimitException extends JSONPathException
{
}
