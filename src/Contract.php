<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract;

use Closure;
use SoftCreatR\JsonPayloadContract\Exception\ContractDefinitionException;
use SoftCreatR\JsonPayloadContract\Exception\ExtractionException;
use SoftCreatR\JsonPayloadContract\Internal\ContractDefinitionValidator;
use SoftCreatR\JsonPayloadContract\Internal\ContractEvaluator;

/**
 * Defines and evaluates a named set of payload extraction rules.
 */
final readonly class Contract
{
    /**
     * @var list<array{callback: Closure, message: string, field: string}>
     */
    private array $validators;

    /**
     * Stores an already validated field map.
     *
     * @param array<string, Field> $fields
     * @param list<array{callback: Closure, message: string, field: string}> $validators
     */
    private function __construct(
        private array $fields,
        array $validators,
        private ContractOptions $options,
    ) {
        $this->validators = $validators;
    }

    /**
     * Creates a contract and validates all field names and selectors eagerly.
     *
     * @param array<array-key, mixed> $fields
     *
     * @throws ContractDefinitionException
     */
    public static function define(array $fields, ?ContractOptions $options = null): self
    {
        $options ??= new ContractOptions();

        return new self(ContractDefinitionValidator::validate($fields, $options->limits), [], $options);
    }

    /**
     * Adds new named fields while preserving this contract's validators and limits.
     *
     * @param array<array-key, mixed> $fields
     *
     * @throws ContractDefinitionException
     */
    public function extend(array $fields): self
    {
        $extension = self::define($fields, $this->options);

        return $this->merge($extension);
    }

    /**
     * Combines two contracts and retains validators from both definitions.
     *
     * The stricter resource limit wins for every limit category.
     *
     * @throws ContractDefinitionException
     */
    public function merge(self $contract): self
    {
        $duplicates = \array_intersect_key($this->fields, $contract->fields);

        if ($duplicates !== []) {
            throw new ContractDefinitionException(\sprintf(
                'Cannot merge duplicate field "%s".',
                \array_key_first($duplicates),
            ));
        }

        return new self(
            [...$this->fields, ...$contract->fields],
            [...$this->validators, ...$contract->validators],
            new ContractOptions(
                $this->options->caseSensitive || $contract->options->caseSensitive,
                new ResourceLimits(
                    \min($this->options->limits->maxJsonBytes, $contract->options->limits->maxJsonBytes),
                    \min($this->options->limits->maxJsonDepth, $contract->options->limits->maxJsonDepth),
                    \min(
                        $this->options->limits->maxMatchesPerField,
                        $contract->options->limits->maxMatchesPerField,
                    ),
                    \min($this->options->limits->maxInputNodes, $contract->options->limits->maxInputNodes),
                    \min($this->options->limits->maxSelectorBytes, $contract->options->limits->maxSelectorBytes),
                    \min(
                        $this->options->limits->maxEvaluationSteps,
                        $contract->options->limits->maxEvaluationSteps,
                    ),
                    \min($this->options->limits->maxViolations, $contract->options->limits->maxViolations),
                ),
                $this->options->allowCustomObjects && $contract->options->allowCustomObjects,
                $this->options->rejectDuplicateObjectMembers || $contract->options->rejectDuplicateObjectMembers,
            ),
        );
    }

    /**
     * Adds a predicate that receives fully normalized data after all fields pass.
     */
    public function validate(
        Closure $validator,
        string $message = 'The payload is invalid.',
        string $field = '$',
    ): self {
        return new self(
            $this->fields,
            [...$this->validators, ['callback' => $validator, 'message' => $message, 'field' => $field]],
            $this->options,
        );
    }

    /**
     * Evaluates a contract and returns normalized data plus all diagnostics.
     */
    public function extract(mixed $payload): Result
    {
        return ContractEvaluator::extract($this->fields, $this->validators, $this->options, $payload);
    }

    /**
     * Decodes and evaluates raw JSON without throwing for malformed input.
     */
    public function extractJson(string $json): Result
    {
        return ContractEvaluator::extractJson($this->fields, $this->validators, $this->options, $json);
    }

    /**
     * Evaluates a decoded payload and returns only valid normalized data.
     *
     * @return array<string, mixed>
     *
     * @throws ExtractionException
     */
    public function apply(mixed $payload): array
    {
        return $this->extract($payload)->value();
    }

    /**
     * Decodes and evaluates JSON and returns only valid normalized data.
     *
     * @return array<string, mixed>
     *
     * @throws ExtractionException
     */
    public function applyJson(string $json): array
    {
        return $this->extractJson($json)->value();
    }

    /**
     * Returns the immutable field-definition map.
     *
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Returns the resource limits enforced by this contract.
     */
    public function limits(): ResourceLimits
    {
        return $this->options->limits;
    }

    /**
     * Returns the contract-wide comparison and resource options.
     */
    public function options(): ContractOptions
    {
        return $this->options;
    }
}
