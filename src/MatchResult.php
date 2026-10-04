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
 * Records how one field resolved its selector chain.
 */
final readonly class MatchResult implements JsonSerializable
{
    /**
     * Creates immutable selector-resolution metadata.
     */
    public function __construct(
        public string $field,
        public ?string $selector,
        public int $count,
        public bool $usedFallback = false,
        public bool $usedDefault = false,
    ) {
    }

    /**
     * Returns metadata in a logging-friendly shape.
     *
     * @return array{
     *     field: string,
     *     selector: string|null,
     *     count: int,
     *     usedFallback: bool,
     *     usedDefault: bool
     * }
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'field' => $this->field,
            'selector' => $this->selector,
            'count' => $this->count,
            'usedFallback' => $this->usedFallback,
            'usedDefault' => $this->usedDefault,
        ];
    }
}
