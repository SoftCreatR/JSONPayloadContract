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

/**
 * Describes one field-level contract failure.
 */
final readonly class Violation implements JsonSerializable
{
    /**
     * Creates an immutable violation with optional selector and type context.
     */
    public function __construct(
        public string $field,
        public ViolationCode $code,
        public string $message,
        public ?string $selector = null,
        public ?string $actualType = null,
    ) {
    }

    /**
     * Prefixes the field location when propagating a nested violation.
     */
    public function prefixed(string $prefix): self
    {
        $field = $this->field === '' ? $prefix : $prefix . '.' . $this->field;

        return new self($field, $this->code, $this->message, $this->selector, $this->actualType);
    }

    /**
     * Returns non-null violation details in a logging-friendly shape.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return \array_filter([
            'field' => $this->field,
            'code' => $this->code->value,
            'message' => $this->message,
            'selector' => $this->selector,
            'actualType' => $this->actualType,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
