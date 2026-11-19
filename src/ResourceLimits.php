<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract;

use SoftCreatR\JsonPayloadContract\Exception\ContractDefinitionException;

/**
 * Bounds parsing, traversal, evaluation, and diagnostics for untrusted payloads.
 */
final readonly class ResourceLimits
{
    /**
     * @var int
     */
    public const DEFAULT_MAX_JSON_BYTES = 16_777_216;

    /**
     * @var int
     */
    public const DEFAULT_MAX_JSON_DEPTH = 64;

    /**
     * @var int
     */
    public const DEFAULT_MAX_MATCHES_PER_FIELD = 10_000;

    /**
     * @var int
     */
    public const DEFAULT_MAX_INPUT_NODES = 100_000;

    /**
     * @var int
     */
    public const DEFAULT_MAX_SELECTOR_BYTES = 4_096;

    /**
     * @var int
     */
    public const DEFAULT_MAX_EVALUATION_STEPS = 1_000_000;

    /**
     * @var int
     */
    public const DEFAULT_MAX_VIOLATIONS = 1_000;

    /**
     * @var positive-int
     */
    public int $maxJsonBytes;

    /**
     * @var positive-int
     */
    public int $maxJsonDepth;

    /**
     * @var positive-int
     */
    public int $maxMatchesPerField;

    /**
     * @var positive-int
     */
    public int $maxInputNodes;

    /**
     * @var positive-int
     */
    public int $maxSelectorBytes;

    /**
     * @var positive-int
     */
    public int $maxEvaluationSteps;

    /**
     * @var positive-int
     */
    public int $maxViolations;

    /**
     * Creates validated limits with secure, broadly usable defaults.
     *
     * @throws ContractDefinitionException
     */
    public function __construct(
        int $maxJsonBytes = self::DEFAULT_MAX_JSON_BYTES,
        int $maxJsonDepth = self::DEFAULT_MAX_JSON_DEPTH,
        int $maxMatchesPerField = self::DEFAULT_MAX_MATCHES_PER_FIELD,
        int $maxInputNodes = self::DEFAULT_MAX_INPUT_NODES,
        int $maxSelectorBytes = self::DEFAULT_MAX_SELECTOR_BYTES,
        int $maxEvaluationSteps = self::DEFAULT_MAX_EVALUATION_STEPS,
        int $maxViolations = self::DEFAULT_MAX_VIOLATIONS,
    ) {
        $this->maxJsonBytes = self::positive($maxJsonBytes, 'The maximum JSON byte length');
        $this->maxJsonDepth = self::positive($maxJsonDepth, 'The maximum JSON depth');
        $this->maxMatchesPerField = self::positive($maxMatchesPerField, 'The maximum matches per field');
        $this->maxInputNodes = self::positive($maxInputNodes, 'The maximum input node count');
        $this->maxSelectorBytes = self::positive($maxSelectorBytes, 'The maximum selector byte length');
        $this->maxEvaluationSteps = self::positive($maxEvaluationSteps, 'The maximum evaluation step count');
        $this->maxViolations = self::positive($maxViolations, 'The maximum violation count');
    }

    /**
     * Rejects zero and negative resource limits.
     *
     * @return positive-int
     *
     * @throws ContractDefinitionException
     */
    private static function positive(int $value, string $label): int
    {
        if ($value < 1) {
            throw new ContractDefinitionException($label . ' must be greater than zero.');
        }

        return $value;
    }
}
