<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Internal;

use Closure;
use Flow\JSONPath\JSONPath;
use Flow\JSONPath\JSONPathException;
use JsonException;
use SoftCreatR\JsonPayloadContract\Cardinality;
use SoftCreatR\JsonPayloadContract\ContractOptions;
use SoftCreatR\JsonPayloadContract\Field;
use SoftCreatR\JsonPayloadContract\MatchResult;
use SoftCreatR\JsonPayloadContract\Result;
use SoftCreatR\JsonPayloadContract\Violation;
use SoftCreatR\JsonPayloadContract\ViolationCode;
use Throwable;

use const JSON_ERROR_DEPTH;
use const JSON_THROW_ON_ERROR;

/**
 * Runs immutable contract definitions against decoded or raw JSON payloads.
 *
 * @internal
 *
 * @phpstan-type ContractValidator array{callback: Closure, message: string, field: string}
 */
final class ContractEvaluator
{
    /**
     * Evaluates a decoded payload and returns normalized data plus diagnostics.
     *
     * @param array<string, Field> $fields
     * @param list<ContractValidator> $validators
     */
    public static function extract(
        array $fields,
        array $validators,
        ContractOptions $options,
        mixed $payload,
    ): Result {
        $inputViolation = InputGuard::inspect($payload, $options->limits, $options->allowCustomObjects);

        if ($inputViolation !== null) {
            return new Result([], [$inputViolation], []);
        }

        $data = [];
        $violations = [];
        $matches = [];
        $violationLimitReached = false;
        $jsonPath = BudgetedJSONPath::create(
            $payload,
            new EvaluationBudget($options->limits->maxEvaluationSteps),
        );
        $selectorCache = [];

        foreach ($fields as $name => $field) {
            if ($violationLimitReached) {
                break;
            }

            try {
                [$values, $selector, $fallbackIndex] = self::select($jsonPath, $field, $selectorCache);
            } catch (EvaluationLimitException) {
                self::appendViolation($violations, $violationLimitReached, $options, new Violation(
                    $name,
                    ViolationCode::EvaluationLimitExceeded,
                    \sprintf(
                        'JSONPath evaluation exceeded the limit of %d steps.',
                        $options->limits->maxEvaluationSteps,
                    ),
                ));
                $matches[$name] = new MatchResult($name, null, 0);

                continue;
            } catch (JSONPathException $exception) {
                self::appendViolation($violations, $violationLimitReached, $options, new Violation(
                    $name,
                    ViolationCode::SelectorEvaluationFailed,
                    'The selector could not be evaluated: ' . $exception->getMessage(),
                ));
                $matches[$name] = new MatchResult($name, null, 0);

                continue;
            } catch (Throwable) {
                self::appendViolation($violations, $violationLimitReached, $options, new Violation(
                    $name,
                    ViolationCode::SelectorEvaluationFailed,
                    'The selector could not be evaluated safely.',
                ));
                $matches[$name] = new MatchResult($name, null, 0);

                continue;
            }

            $cardinality = $field->cardinality();
            $matchedCount = \count($values);
            $usedDefault = false;

            if ($matchedCount > $options->limits->maxMatchesPerField) {
                self::appendViolation($violations, $violationLimitReached, $options, new Violation(
                    $name,
                    ViolationCode::TooManyMatches,
                    \sprintf(
                        'The selector exceeded the global limit of %d matches.',
                        $options->limits->maxMatchesPerField,
                    ),
                    $selector,
                ));
                $matches[$name] = new MatchResult($name, $selector, $matchedCount, $fallbackIndex > 0);

                continue;
            }

            if ($cardinality === Cardinality::Many) {
                $minimumMatches = $field->minimumMatches();
                $maximumMatches = $field->maximumMatches();

                if ($minimumMatches !== null && $matchedCount < $minimumMatches) {
                    self::appendViolation($violations, $violationLimitReached, $options, new Violation(
                        $name,
                        ViolationCode::TooFewMatches,
                        \sprintf('Expected at least %d matches, got %d.', $minimumMatches, $matchedCount),
                        $selector,
                    ));
                    $matches[$name] = new MatchResult($name, $selector, $matchedCount, $fallbackIndex > 0);

                    continue;
                }

                if ($maximumMatches !== null && $matchedCount > $maximumMatches) {
                    self::appendViolation($violations, $violationLimitReached, $options, new Violation(
                        $name,
                        ViolationCode::TooManyMatches,
                        \sprintf('Expected at most %d matches, got %d.', $maximumMatches, $matchedCount),
                        $selector,
                    ));
                    $matches[$name] = new MatchResult($name, $selector, $matchedCount, $fallbackIndex > 0);

                    continue;
                }
            }

            if ($matchedCount === 0) {
                if ($cardinality === Cardinality::Required) {
                    self::appendViolation($violations, $violationLimitReached, $options, new Violation(
                        $name,
                        ViolationCode::MissingRequired,
                        'The required field did not match any value.',
                    ));
                    $matches[$name] = new MatchResult($name, null, 0);

                    continue;
                }

                if ($cardinality === Cardinality::Many) {
                    $data[$name] = [];
                    $matches[$name] = new MatchResult($name, null, 0);

                    continue;
                }

                if (!$field->hasDefault()) {
                    $matches[$name] = new MatchResult($name, null, 0);

                    continue;
                }

                $values = [$field->defaultValue()];
                $usedDefault = true;
            }

            if ($cardinality !== Cardinality::Many && $matchedCount > 1) {
                self::appendViolation($violations, $violationLimitReached, $options, new Violation(
                    $name,
                    ViolationCode::MultipleMatches,
                    \sprintf('Expected at most one value, but the selector matched %d.', $matchedCount),
                    $selector,
                ));
                $matches[$name] = new MatchResult($name, $selector, $matchedCount, $fallbackIndex > 0);

                continue;
            }

            $normalized = [];
            $fieldIsValid = true;

            foreach ($values as $index => $value) {
                $violationField = $cardinality === Cardinality::Many
                    ? $name . '.' . $index
                    : $name;
                [$valid, $normalizedValue, $valueViolations] = ValueNormalizer::normalize(
                    $violationField,
                    $selector,
                    $field,
                    $value,
                    $options,
                );

                foreach ($valueViolations as $violation) {
                    self::appendViolation($violations, $violationLimitReached, $options, $violation);
                }

                if ($valid) {
                    $normalized[] = $normalizedValue;
                } else {
                    $fieldIsValid = false;
                }
            }

            $matches[$name] = new MatchResult(
                $name,
                $selector,
                $matchedCount,
                $fallbackIndex > 0,
                $usedDefault,
            );

            if (!$fieldIsValid) {
                continue;
            }

            $data[$name] = $cardinality === Cardinality::Many
                ? $normalized
                : $normalized[0];
        }

        if ($violations === []) {
            foreach ($validators as $validator) {
                try {
                    $valid = ($validator['callback'])($data) === true;
                } catch (Throwable) {
                    $valid = false;
                }

                if (!$valid) {
                    self::appendViolation($violations, $violationLimitReached, $options, new Violation(
                        $validator['field'],
                        ViolationCode::ValidationFailed,
                        $validator['message'],
                        actualType: 'array',
                    ));
                }
            }
        }

        return new Result($data, $violations, $matches);
    }

    /**
     * Appends one diagnostic without allowing an unbounded violation list.
     *
     * @param list<Violation> $violations
     *
     * @param-out non-empty-list<Violation> $violations
     */
    private static function appendViolation(
        array &$violations,
        bool &$limitReached,
        ContractOptions $options,
        Violation $violation,
    ): void {
        if (\count($violations) < $options->limits->maxViolations) {
            $violations[] = $violation;

            return;
        }

        $violations = [
            ...\array_slice($violations, 0, $options->limits->maxViolations - 1),
            new Violation(
                '$',
                ViolationCode::TooManyViolations,
                \sprintf(
                    'The payload produced more than %d violations.',
                    $options->limits->maxViolations,
                ),
            ),
        ];
        $limitReached = true;
    }

    /**
     * Decodes and evaluates raw JSON without throwing for malformed input.
     *
     * @param array<string, Field> $fields
     * @param list<ContractValidator> $validators
     */
    public static function extractJson(
        array $fields,
        array $validators,
        ContractOptions $options,
        string $json,
    ): Result {
        if (\strlen($json) > $options->limits->maxJsonBytes) {
            return new Result(
                [],
                [new Violation(
                    '$',
                    ViolationCode::PayloadTooLarge,
                    \sprintf('The JSON payload exceeds the limit of %d bytes.', $options->limits->maxJsonBytes),
                    actualType: 'string',
                )],
                [],
            );
        }

        try {
            $payload = \json_decode(
                $json,
                depth: $options->limits->maxJsonDepth,
                flags: JSON_THROW_ON_ERROR,
            );

            if ($options->rejectDuplicateObjectMembers && JsonDuplicateKeyDetector::containsDuplicate($json)) {
                return new Result(
                    [],
                    [new Violation(
                        '$',
                        ViolationCode::DuplicateObjectMember,
                        'The JSON payload contains a duplicate object member name.',
                        actualType: 'string',
                    )],
                    [],
                );
            }

            return self::extract($fields, $validators, $options, $payload);
        } catch (JsonException $exception) {
            $code = $exception->getCode() === JSON_ERROR_DEPTH
                ? ViolationCode::PayloadTooDeep
                : ViolationCode::InvalidJson;

            return new Result(
                [],
                [new Violation('$', $code, $exception->getMessage(), actualType: 'string')],
                [],
            );
        }
    }

    /**
     * Resolves the first selector that yields at least one value.
     *
     * @param array<string, list<mixed>> $selectorCache
     *
     * @return array{list<mixed>, ?string, int}
     *
     * @throws JSONPathException
     */
    private static function select(JSONPath $jsonPath, Field $field, array &$selectorCache): array
    {
        foreach ($field->selectors() as $index => $selector) {
            if (isset($selectorCache[$selector])) {
                $values = $selectorCache[$selector];
            } else {
                $values = \array_values((array) $jsonPath->find($selector)->getData());
                $selectorCache[$selector] = $values;
            }

            if ($values !== []) {
                return [$values, $selector, $index];
            }
        }

        return [[], null, 0];
    }
}
