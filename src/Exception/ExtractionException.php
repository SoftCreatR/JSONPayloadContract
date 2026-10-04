<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Exception;

use RuntimeException;
use SoftCreatR\JsonPayloadContract\Result;

/**
 * Carries a failed extraction result for exception-oriented consumers.
 */
final class ExtractionException extends RuntimeException
{
    /**
     * Builds a readable summary while retaining the structured result.
     */
    public function __construct(private readonly Result $result)
    {
        $messages = \array_map(
            static fn($violation): string => $violation->field . ': ' . $violation->message,
            $result->violations(),
        );

        parent::__construct('Payload does not satisfy the contract: ' . \implode('; ', $messages));
    }

    /**
     * Returns the complete failed extraction result.
     */
    public function result(): Result
    {
        return $this->result;
    }
}
