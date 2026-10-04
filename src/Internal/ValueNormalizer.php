<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Internal;

use SoftCreatR\JsonPayloadContract\ContractOptions;
use SoftCreatR\JsonPayloadContract\Field;
use SoftCreatR\JsonPayloadContract\ValueType;
use SoftCreatR\JsonPayloadContract\Violation;
use SoftCreatR\JsonPayloadContract\ViolationCode;
use Stringable;
use Throwable;

/**
 * Applies one field's type, nested contract, validation, and mapping rules.
 *
 * @internal
 */
final class ValueNormalizer
{
    /**
     * Normalizes one selected value and returns its local diagnostics.
     *
     * @return array{bool, mixed, list<Violation>}
     */
    public static function normalize(
        string $name,
        ?string $selector,
        Field $field,
        mixed $value,
        ContractOptions $options,
    ): array {
        if ($value === null) {
            if ($field->type() === ValueType::Null || $field->allowsNull()) {
                return [true, null, []];
            }

            return [false, null, [new Violation(
                $name,
                ViolationCode::NullNotAllowed,
                'The value is null, but the field is not nullable.',
                $selector,
                'null',
            )]];
        }

        if ($field->allowsCoercion()) {
            [$coerced, $value] = self::coerceValue($field->type(), $value);

            if (!$coerced) {
                return self::unexpectedType($name, $selector, $field->type(), $value);
            }
        } elseif (!self::matchesType($field->type(), $value)) {
            return self::unexpectedType($name, $selector, $field->type(), $value);
        }

        if ($field->nestedContract() !== null) {
            if (!\is_array($value) && !\is_object($value)) {
                return [false, null, [new Violation(
                    $name,
                    ViolationCode::UnexpectedType,
                    'A nested contract requires an array or object value.',
                    $selector,
                    \get_debug_type($value),
                )]];
            }

            $nested = $field->nestedContract()->extract($value);

            if (!$nested->isValid()) {
                return [
                    false,
                    null,
                    \array_map(
                        static fn(Violation $violation): Violation => $violation->prefixed($name),
                        $nested->violations(),
                    ),
                ];
            }

            $value = $nested->data();
        }

        foreach ($field->validators() as $validator) {
            try {
                $valid = $validator['caseAware']
                    ? ($validator['callback'])($value, $options->caseSensitive) === true
                    : ($validator['callback'])($value) === true;
            } catch (Throwable) {
                $valid = false;
            }

            if (!$valid) {
                return [false, null, [new Violation(
                    $name,
                    $validator['code'],
                    $validator['message'],
                    $selector,
                    \get_debug_type($value),
                )]];
            }
        }

        foreach ($field->transformers() as $transformer) {
            try {
                $value = $transformer($value);
            } catch (Throwable) {
                return [false, null, [new Violation(
                    $name,
                    ViolationCode::TransformationFailed,
                    'The value could not be transformed.',
                    $selector,
                    \get_debug_type($value),
                )]];
            }
        }

        return [true, $value, []];
    }

    /**
     * Builds the standard unexpected-type normalization failure.
     *
     * @return array{false, null, list<Violation>}
     */
    private static function unexpectedType(string $name, ?string $selector, ValueType $type, mixed $value): array
    {
        return [false, null, [new Violation(
            $name,
            ViolationCode::UnexpectedType,
            \sprintf('Expected %s, got %s.', $type->value, \get_debug_type($value)),
            $selector,
            \get_debug_type($value),
        )]];
    }

    /**
     * Reports whether a value satisfies a strict field type.
     */
    private static function matchesType(ValueType $type, mixed $value): bool
    {
        return match ($type) {
            ValueType::Mixed => true,
            ValueType::Null => $value === null,
            ValueType::Scalar => \is_scalar($value),
            ValueType::String => \is_string($value),
            ValueType::Integer => \is_int($value),
            ValueType::Float => \is_float($value),
            ValueType::Number => \is_int($value) || \is_float($value),
            ValueType::Boolean => \is_bool($value),
            ValueType::Array => \is_array($value),
            ValueType::List => \is_array($value) && \array_is_list($value),
            ValueType::AssociativeArray => \is_array($value) && !\array_is_list($value),
            ValueType::Object => \is_object($value),
            ValueType::Collection => \is_array($value) || \is_object($value),
        };
    }

    /**
     * Coerces a value according to the explicitly selected target type.
     *
     * @return array{bool, mixed}
     */
    private static function coerceValue(ValueType $type, mixed $value): array
    {
        if (self::matchesType($type, $value)) {
            return [true, $value];
        }

        return match ($type) {
            ValueType::Scalar => $value instanceof Stringable
                ? [true, (string) $value]
                : [false, $value],
            ValueType::String => match (true) {
                \is_scalar($value), $value instanceof Stringable => [true, (string) $value],
                default => [false, $value],
            },
            ValueType::Integer => self::coerceInteger($value),
            ValueType::Float => self::coerceFloat($value),
            ValueType::Number => self::coerceNumber($value),
            ValueType::Boolean => self::coerceBoolean($value),
            default => [false, $value],
        };
    }

    /**
     * Coerces a decimal integer string while rejecting invalid or overflowing input.
     *
     * @return array{bool, mixed}
     */
    private static function coerceInteger(mixed $value): array
    {
        if (!\is_string($value) || \preg_match('/^[+-]?\d+$/D', $value) !== 1) {
            return [false, $value];
        }

        $integer = \filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? [false, $value] : [true, $integer];
    }

    /**
     * Coerces an integer or finite numeric string to a float.
     *
     * @return array{bool, mixed}
     */
    private static function coerceFloat(mixed $value): array
    {
        if (!\is_int($value) && !\is_string($value)) {
            return [false, $value];
        }

        $float = \filter_var($value, FILTER_VALIDATE_FLOAT);

        return $float === false || !\is_finite((float) $float)
            ? [false, $value]
            : [true, (float) $float];
    }

    /**
     * Preserves integral numbers and otherwise attempts finite float coercion.
     *
     * @return array{bool, mixed}
     */
    private static function coerceNumber(mixed $value): array
    {
        [$integerValid, $integer] = self::coerceInteger($value);

        return $integerValid ? [true, $integer] : self::coerceFloat($value);
    }

    /**
     * Coerces supported textual and integer boolean representations.
     *
     * @return array{bool, mixed}
     */
    private static function coerceBoolean(mixed $value): array
    {
        if (!\is_int($value) && !\is_string($value)) {
            return [false, $value];
        }

        $boolean = \filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $boolean === null ? [false, $value] : [true, $boolean];
    }
}
