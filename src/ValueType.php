<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract;

/**
 * Lists the strict payload value categories supported by field definitions.
 */
enum ValueType: string
{
    case Mixed = 'mixed';
    case Null = 'null';
    case Scalar = 'scalar';
    case String = 'string';
    case Integer = 'integer';
    case Float = 'float';
    case Number = 'number';
    case Boolean = 'boolean';
    case Array = 'array';
    case List = 'list';
    case AssociativeArray = 'associative-array';
    case Object = 'object';
    case Collection = 'collection';
}
