<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Exception;

use InvalidArgumentException;

/**
 * Indicates an invalid field definition or JSONPath selector.
 */
final class ContractDefinitionException extends InvalidArgumentException
{
}
