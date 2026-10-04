<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Internal;

use JsonException;

use const JSON_THROW_ON_ERROR;

/**
 * Detects ambiguous duplicate object member names in already valid JSON.
 *
 * @internal
 */
final class JsonDuplicateKeyDetector
{
    private int $position = 0;

    private bool $duplicateFound = false;

    /**
     * Creates a single-use scanner for valid JSON text.
     */
    private function __construct(private readonly string $json)
    {
    }

    /**
     * Reports whether any object repeats a decoded member name.
     *
     * @throws JsonException
     */
    public static function containsDuplicate(string $json): bool
    {
        $detector = new self($json);
        $detector->parseValue();

        return $detector->duplicateFound;
    }

    /**
     * Skips one complete JSON value.
     *
     * @throws JsonException
     */
    private function parseValue(): void
    {
        $this->skipWhitespace();

        match ($this->json[$this->position]) {
            '{' => $this->parseObject(),
            '[' => $this->parseArray(),
            '"' => $this->parseString(),
            't' => $this->position += 4,
            'f' => $this->position += 5,
            'n' => $this->position += 4,
            default => $this->parseNumber(),
        };
    }

    /**
     * Scans an object and compares fully decoded key strings.
     *
     * @throws JsonException
     */
    private function parseObject(): void
    {
        ++$this->position;
        $this->skipWhitespace();
        $keys = [];

        if ($this->consume('}')) {
            return;
        }

        while (true) {
            $key = $this->parseString();

            if (isset($keys[$key])) {
                $this->duplicateFound = true;
            }

            $keys[$key] = true;
            $this->skipWhitespace();
            ++$this->position;
            $this->parseValue();
            $this->skipWhitespace();

            if ($this->consume('}')) {
                return;
            }

            ++$this->position;
            $this->skipWhitespace();
        }
    }

    /**
     * Scans every value in an array.
     *
     * @throws JsonException
     */
    private function parseArray(): void
    {
        ++$this->position;
        $this->skipWhitespace();

        if ($this->consume(']')) {
            return;
        }

        while (true) {
            $this->parseValue();
            $this->skipWhitespace();

            if ($this->consume(']')) {
                return;
            }

            ++$this->position;
        }
    }

    /**
     * Scans and decodes a JSON string.
     *
     * @throws JsonException
     */
    private function parseString(): string
    {
        $start = $this->position++;

        while (true) {
            if ($this->json[$this->position] === '\\') {
                $this->position += 2;

                continue;
            }

            if ($this->json[$this->position++] === '"') {
                break;
            }
        }

        $decoded = \json_decode(
            \substr($this->json, $start, $this->position - $start),
            flags: JSON_THROW_ON_ERROR,
        );

        return \is_string($decoded) ? $decoded : throw new JsonException('Object member name is not a string.');
    }

    /**
     * Advances over a JSON number.
     */
    private function parseNumber(): void
    {
        $matched = \preg_match(
            '/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/',
            $this->json,
            $matches,
            0,
            $this->position,
        );
        $length = $matched === 1 ? \strlen($matches[0]) : throw new JsonException('Invalid JSON number.');
        $this->position += $length;
    }

    /**
     * Advances over insignificant JSON whitespace.
     */
    private function skipWhitespace(): void
    {
        $length = \strlen($this->json);

        while ($this->position < $length && \str_contains(" \t\r\n", $this->json[$this->position])) {
            ++$this->position;
        }
    }

    /**
     * Consumes the expected structural character when present.
     */
    private function consume(string $character): bool
    {
        if ($this->json[$this->position] !== $character) {
            return false;
        }

        ++$this->position;

        return true;
    }
}
