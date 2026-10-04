<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Internal;

/**
 * Tracks work shared by every selector evaluated for one payload.
 *
 * @internal
 */
final class EvaluationBudget
{
    private int $consumed = 0;

    /**
     * Creates a budget with a positive hard ceiling.
     */
    public function __construct(private readonly int $maximum)
    {
    }

    /**
     * Accounts for evaluation work and fails before the ceiling is exceeded.
     *
     * @throws EvaluationLimitException
     */
    public function consume(int $steps = 1): void
    {
        if ($steps > $this->maximum - $this->consumed) {
            throw new EvaluationLimitException('The JSONPath evaluation budget was exhausted.');
        }

        $this->consumed += $steps;
    }
}
