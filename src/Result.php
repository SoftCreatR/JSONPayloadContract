<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract;

use JsonSerializable;
use Override;
use SoftCreatR\JsonPayloadContract\Exception\ExtractionException;

/**
 * Contains normalized output, all violations, and per-field match metadata.
 */
final readonly class Result implements JsonSerializable
{
    /**
     * Creates an immutable extraction result.
     *
     * @param array<string, mixed> $data
     * @param list<Violation> $violations
     * @param array<string, MatchResult> $matches
     */
    public function __construct(
        private array $data,
        private array $violations,
        private array $matches,
    ) {
    }

    /**
     * Reports whether every field satisfied its definition.
     */
    public function isValid(): bool
    {
        return $this->violations === [];
    }

    /**
     * Returns successful normalized fields, including partial data on failure.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * Returns normalized data or throws with this result attached.
     *
     * @return array<string, mixed>
     *
     * @throws ExtractionException
     */
    public function value(): array
    {
        if (!$this->isValid()) {
            throw new ExtractionException($this);
        }

        return $this->data;
    }

    /**
     * Returns all violations in the contract's field order.
     *
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * Returns selector-resolution metadata keyed by the output field.
     *
     * @return array<string, MatchResult>
     */
    public function matches(): array
    {
        return $this->matches;
    }

    /**
     * Returns selector-resolution metadata for one output field.
     */
    public function match(string $field): ?MatchResult
    {
        return $this->matches[$field] ?? null;
    }

    /**
     * Returns the complete result in a logging-friendly shape.
     *
     * @return array{
     *     valid: bool,
     *     data: array<string, mixed>,
     *     violations: list<Violation>,
     *     matches: array<string, MatchResult>
     * }
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'valid' => $this->isValid(),
            'data' => $this->data,
            'violations' => $this->violations,
            'matches' => $this->matches,
        ];
    }
}
