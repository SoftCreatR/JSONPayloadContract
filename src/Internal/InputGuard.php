<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Internal;

use ReflectionReference;
use SoftCreatR\JsonPayloadContract\ResourceLimits;
use SoftCreatR\JsonPayloadContract\Violation;
use SoftCreatR\JsonPayloadContract\ViolationCode;
use stdClass;

/**
 * Validates decoded payload graphs before JSONPath is allowed to traverse them.
 *
 * @internal
 */
final class InputGuard
{
    /**
     * Validates JSON-compatible types, graph cycles, depth, and node count.
     */
    public static function inspect(
        mixed &$payload,
        ResourceLimits $limits,
        bool $allowCustomObjects,
    ): ?Violation {
        $nodes = 0;
        $activeArrays = [];
        $activeObjects = [];

        return self::inspectValue(
            $payload,
            1,
            $nodes,
            $activeArrays,
            $activeObjects,
            $limits,
            $allowCustomObjects,
        );
    }

    /**
     * @param array<string, true> $activeArrays
     * @param array<int, true> $activeObjects
     */
    private static function inspectValue(
        mixed &$value,
        int $depth,
        int &$nodes,
        array &$activeArrays,
        array &$activeObjects,
        ResourceLimits $limits,
        bool $allowCustomObjects,
    ): ?Violation {
        ++$nodes;

        if ($nodes > $limits->maxInputNodes) {
            return new Violation(
                '$',
                ViolationCode::PayloadTooComplex,
                \sprintf('The payload exceeds the limit of %d value nodes.', $limits->maxInputNodes),
            );
        }

        if (\is_float($value) && !\is_finite($value)) {
            return self::unsupported(\get_debug_type($value));
        }

        if (\is_array($value)) {
            if ($depth >= $limits->maxJsonDepth) {
                return self::tooDeep($limits);
            }

            $reference = [&$value];
            $referenceId = ReflectionReference::fromArrayElement($reference, 0)?->getId() ?? '';

            if (isset($activeArrays[$referenceId])) {
                return self::cyclic();
            }

            $activeArrays[$referenceId] = true;

            foreach ($value as &$item) {
                $violation = self::inspectValue(
                    $item,
                    $depth + 1,
                    $nodes,
                    $activeArrays,
                    $activeObjects,
                    $limits,
                    $allowCustomObjects,
                );

                if ($violation !== null) {
                    unset($item, $activeArrays[$referenceId]);

                    return $violation;
                }
            }

            unset($item, $activeArrays[$referenceId]);

            return null;
        }

        if (!\is_object($value)) {
            return \is_resource($value) ? self::unsupported(\get_debug_type($value)) : null;
        }

        if (!$value instanceof stdClass) {
            return $allowCustomObjects ? null : self::unsupported($value::class);
        }

        if ($depth >= $limits->maxJsonDepth) {
            return self::tooDeep($limits);
        }

        $objectId = \spl_object_id($value);

        if (isset($activeObjects[$objectId])) {
            return self::cyclic();
        }

        $activeObjects[$objectId] = true;
        $properties = \get_object_vars($value);

        foreach ($properties as &$property) {
            $violation = self::inspectValue(
                $property,
                $depth + 1,
                $nodes,
                $activeArrays,
                $activeObjects,
                $limits,
                $allowCustomObjects,
            );

            if ($violation !== null) {
                unset($property, $activeObjects[$objectId]);

                return $violation;
            }
        }

        unset($property, $activeObjects[$objectId]);

        return null;
    }

    /**
     * Builds a stable cycle diagnostic.
     */
    private static function cyclic(): Violation
    {
        return new Violation('$', ViolationCode::CyclicPayload, 'The payload contains a cyclic reference.');
    }

    /**
     * Builds a stable depth-limit diagnostic.
     */
    private static function tooDeep(ResourceLimits $limits): Violation
    {
        return new Violation(
            '$',
            ViolationCode::PayloadTooDeep,
            \sprintf('The payload exceeds the maximum depth of %d.', $limits->maxJsonDepth),
        );
    }

    /**
     * Builds a stable unsupported-type diagnostic.
     */
    private static function unsupported(string $type): Violation
    {
        return new Violation(
            '$',
            ViolationCode::UnsupportedPayloadType,
            \sprintf('The payload contains unsupported value type %s.', $type),
            actualType: $type,
        );
    }
}
