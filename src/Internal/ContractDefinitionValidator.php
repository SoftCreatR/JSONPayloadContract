<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Internal;

use Flow\JSONPath\JSONPath;
use Flow\JSONPath\JSONPathException;
use SoftCreatR\JsonPayloadContract\Exception\ContractDefinitionException;
use SoftCreatR\JsonPayloadContract\Field;
use SoftCreatR\JsonPayloadContract\ResourceLimits;

/**
 * Validates field maps and primes JSONPath selectors at definition time.
 *
 * @internal
 */
final class ContractDefinitionValidator
{
    /**
     * Returns a field map with validated names, values, and selectors.
     *
     * @param array<array-key, mixed> $fields
     *
     * @return array<string, Field>
     *
     * @throws ContractDefinitionException
     */
    public static function validate(array $fields, ResourceLimits $limits): array
    {
        if ($fields === []) {
            throw new ContractDefinitionException('A contract must define at least one field.');
        }

        $parser = new JSONPath();
        $validatedFields = [];

        foreach ($fields as $name => $field) {
            if (!\is_string($name) || $name === '') {
                throw new ContractDefinitionException('Every contract field must have a non-empty string name.');
            }

            if (!$field instanceof Field) {
                throw new ContractDefinitionException(\sprintf('Field "%s" must be a Field instance.', $name));
            }

            if ($field->selectors() === []) {
                throw new ContractDefinitionException(\sprintf('Field "%s" has no JSONPath selector.', $name));
            }

            foreach ($field->selectors() as $selector) {
                self::validateSelector($parser, $name, $selector, $limits);
            }

            $validatedFields[$name] = $field;
        }

        return $validatedFields;
    }

    /**
     * Verifies that a selector is absolute, bounded, syntactically valid, and well-typed.
     *
     * @throws ContractDefinitionException
     */
    private static function validateSelector(
        JSONPath $parser,
        string $field,
        string $selector,
        ResourceLimits $limits,
    ): void {
        if ($selector === '' || !\str_starts_with($selector, '$')) {
            throw new ContractDefinitionException(\sprintf(
                'Selector "%s" for field "%s" must be an absolute JSONPath expression.',
                $selector,
                $field,
            ));
        }

        if (\strlen($selector) > $limits->maxSelectorBytes) {
            throw new ContractDefinitionException(\sprintf(
                'Selector for field "%s" exceeds the limit of %d bytes.',
                $field,
                $limits->maxSelectorBytes,
            ));
        }

        try {
            $tokens = $parser->parseTokens($selector);

            if ($selector !== '$' && $tokens === []) {
                throw new JSONPathException('The expression does not contain a valid selector.');
            }

            foreach ($tokens as $token) {
                $token->buildFilter(0);
            }
        } catch (JSONPathException $exception) {
            throw new ContractDefinitionException(\sprintf(
                'Selector "%s" for field "%s" is invalid: %s',
                $selector,
                $field,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }
}
