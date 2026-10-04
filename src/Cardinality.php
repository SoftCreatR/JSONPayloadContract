<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract;

/**
 * Describes how many JSONPath matches a field accepts.
 */
enum Cardinality
{
    case Required;
    case Optional;
    case Many;
}
