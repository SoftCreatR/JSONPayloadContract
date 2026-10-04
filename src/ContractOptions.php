<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract;

/**
 * Configures contract-wide comparison behavior and resource limits.
 */
final readonly class ContractOptions
{
    /**
     * Creates immutable options with strict comparisons and secure limits.
     */
    public function __construct(
        public bool $caseSensitive = true,
        public ResourceLimits $limits = new ResourceLimits(),
        public bool $allowCustomObjects = false,
        public bool $rejectDuplicateObjectMembers = true,
    ) {
    }
}
