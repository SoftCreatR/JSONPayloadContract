<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Internal;

use Closure;
use DateTimeImmutable;
use SoftCreatR\JsonPayloadContract\Exception\ContractDefinitionException;
use SoftCreatR\JsonPayloadContract\ViolationCode;

/**
 * Builds reusable validator definitions for the public Field facade.
 *
 * @internal
 *
 * @phpstan-type ValidatorDefinition array{
 *     callback: Closure,
 *     message: string,
 *     code: ViolationCode,
 *     caseAware: bool
 * }
 */
final class BuiltInValidatorFactory
{
    /**
     * Builds the non-empty value validator.
     *
     * @return ValidatorDefinition
     */
    public static function nonEmpty(): array
    {
        return self::definition(
            static fn(mixed $value): bool => match (true) {
                \is_string($value), \is_array($value) => $value !== '' && $value !== [],
                \is_object($value) => \get_object_vars($value) !== [],
                default => $value !== null,
            },
            'The value must not be empty.',
            ViolationCode::Empty,
        );
    }

    /**
     * Builds the email syntax validator.
     *
     * @return ValidatorDefinition
     */
    public static function email(): array
    {
        return self::definition(
            static fn(mixed $value): bool => \is_string($value)
                && \filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'The value must be a valid email address.',
            ViolationCode::InvalidEmail,
        );
    }

    /**
     * Builds a strict allowlist validator with optional ASCII case folding.
     *
     * @param list<mixed> $values
     *
     * @return ValidatorDefinition
     *
     * @throws ContractDefinitionException
     */
    public static function oneOf(array $values): array
    {
        if ($values === []) {
            throw new ContractDefinitionException('The allowed values list must not be empty.');
        }

        return self::definition(
            static function (mixed $value, bool $caseSensitive) use ($values): bool {
                foreach ($values as $allowed) {
                    if ($value === $allowed) {
                        return true;
                    }

                    if (
                        !$caseSensitive
                        && \is_string($value)
                        && \is_string($allowed)
                        && \strcasecmp($value, $allowed) === 0
                    ) {
                        return true;
                    }
                }

                return false;
            },
            'The value is not one of the allowed values.',
            ViolationCode::NotAllowed,
            true,
        );
    }

    /**
     * Builds a validator for a developer-supplied PCRE pattern.
     *
     * @return ValidatorDefinition
     *
     * @throws ContractDefinitionException
     */
    public static function matching(string $pattern): array
    {
        if (@\preg_match($pattern, '') === false) {
            throw new ContractDefinitionException('The validation pattern is not a valid PCRE pattern.');
        }

        return self::definition(
            static fn(mixed $value): bool => \is_string($value) && \preg_match($pattern, $value) === 1,
            'The value does not match the required pattern.',
            ViolationCode::PatternMismatch,
        );
    }

    /**
     * Builds an inclusive numeric minimum validator.
     *
     * @return ValidatorDefinition
     */
    public static function minimum(int|float $minimum): array
    {
        return self::definition(
            static fn(mixed $value): bool => (\is_int($value) || \is_float($value)) && $value >= $minimum,
            \sprintf('The value must be at least %s.', $minimum),
            ViolationCode::BelowMinimum,
        );
    }

    /**
     * Builds an inclusive numeric maximum validator.
     *
     * @return ValidatorDefinition
     */
    public static function maximum(int|float $maximum): array
    {
        return self::definition(
            static fn(mixed $value): bool => (\is_int($value) || \is_float($value)) && $value <= $maximum,
            \sprintf('The value must be at most %s.', $maximum),
            ViolationCode::AboveMaximum,
        );
    }

    /**
     * Builds a Unicode code-point minimum-length validator.
     *
     * @return ValidatorDefinition
     *
     * @throws ContractDefinitionException
     */
    public static function minLength(int $minimum): array
    {
        self::assertNonNegative($minimum, 'The minimum string length');

        return self::definition(
            static fn(mixed $value): bool => ($length = self::stringLength($value)) !== null
                && $length >= $minimum,
            \sprintf('The string must contain at least %d characters.', $minimum),
            ViolationCode::TooShort,
        );
    }

    /**
     * Builds a Unicode code-point maximum-length validator.
     *
     * @return ValidatorDefinition
     *
     * @throws ContractDefinitionException
     */
    public static function maxLength(int $maximum): array
    {
        self::assertNonNegative($maximum, 'The maximum string length');

        return self::definition(
            static fn(mixed $value): bool => ($length = self::stringLength($value)) !== null
                && $length <= $maximum,
            \sprintf('The string must contain at most %d characters.', $maximum),
            ViolationCode::TooLong,
        );
    }

    /**
     * Builds a prefix validator controlled by the contract's case policy.
     *
     * @return ValidatorDefinition
     *
     * @throws ContractDefinitionException
     */
    public static function startsWith(string $prefix): array
    {
        self::assertNonEmptyNeedle($prefix, 'prefix');

        return self::definition(
            static fn(mixed $value, bool $caseSensitive): bool => \is_string($value) && ($caseSensitive
                ? \str_starts_with($value, $prefix)
                : \strncasecmp($value, $prefix, \strlen($prefix)) === 0),
            \sprintf('The string must start with "%s".', $prefix),
            ViolationCode::MissingPrefix,
            true,
        );
    }

    /**
     * Builds a suffix validator controlled by the contract's case policy.
     *
     * @return ValidatorDefinition
     *
     * @throws ContractDefinitionException
     */
    public static function endsWith(string $suffix): array
    {
        self::assertNonEmptyNeedle($suffix, 'suffix');

        return self::definition(
            static fn(mixed $value, bool $caseSensitive): bool => \is_string($value) && ($caseSensitive
                ? \str_ends_with($value, $suffix)
                : \strlen($value) >= \strlen($suffix)
                    && \strcasecmp(\substr($value, -\strlen($suffix)), $suffix) === 0),
            \sprintf('The string must end with "%s".', $suffix),
            ViolationCode::MissingSuffix,
            true,
        );
    }

    /**
     * Builds a substring validator controlled by the contract's case policy.
     *
     * @return ValidatorDefinition
     *
     * @throws ContractDefinitionException
     */
    public static function contains(string $substring): array
    {
        self::assertNonEmptyNeedle($substring, 'substring');

        return self::definition(
            static fn(mixed $value, bool $caseSensitive): bool => \is_string($value) && ($caseSensitive
                ? \str_contains($value, $substring)
                : \stripos($value, $substring) !== false),
            \sprintf('The string must contain "%s".', $substring),
            ViolationCode::SubstringNotFound,
            true,
        );
    }

    /**
     * Builds an array minimum-size validator.
     *
     * @return ValidatorDefinition
     *
     * @throws ContractDefinitionException
     */
    public static function minItems(int $minimum): array
    {
        self::assertNonNegative($minimum, 'The minimum item count');

        return self::definition(
            static fn(mixed $value): bool => \is_array($value) && \count($value) >= $minimum,
            \sprintf('The array must contain at least %d items.', $minimum),
            ViolationCode::TooFewItems,
        );
    }

    /**
     * Builds an array maximum-size validator.
     *
     * @return ValidatorDefinition
     *
     * @throws ContractDefinitionException
     */
    public static function maxItems(int $maximum): array
    {
        self::assertNonNegative($maximum, 'The maximum item count');

        return self::definition(
            static fn(mixed $value): bool => \is_array($value) && \count($value) <= $maximum,
            \sprintf('The array must contain at most %d items.', $maximum),
            ViolationCode::TooManyItems,
        );
    }

    /**
     * Builds a canonical UUID validator.
     *
     * @return ValidatorDefinition
     */
    public static function uuid(): array
    {
        return self::definition(
            static fn(mixed $value): bool => \is_string($value)
                && \preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/Di', $value) === 1,
            'The value must be a valid UUID.',
            ViolationCode::InvalidUuid,
        );
    }

    /**
     * Builds an absolute URL validator with an explicit scheme allowlist.
     *
     * @param list<string> $schemes
     *
     * @return ValidatorDefinition
     *
     * @throws ContractDefinitionException
     */
    public static function url(array $schemes): array
    {
        if ($schemes === []) {
            throw new ContractDefinitionException('The allowed URL scheme list must not be empty.');
        }

        $normalizedSchemes = [];

        foreach ($schemes as $scheme) {
            $scheme = \strtolower($scheme);

            if (\preg_match('/\A[a-z][a-z0-9+.-]*\z/D', $scheme) !== 1) {
                throw new ContractDefinitionException(\sprintf('URL scheme "%s" is invalid.', $scheme));
            }

            $normalizedSchemes[$scheme] = true;
        }

        return self::definition(
            static fn(mixed $value): bool => \is_string($value)
                && \filter_var($value, FILTER_VALIDATE_URL) !== false
                && \is_string($scheme = \parse_url($value, PHP_URL_SCHEME))
                && isset($normalizedSchemes[\strtolower($scheme)]),
            'The value must be a valid URL.',
            ViolationCode::InvalidUrl,
        );
    }

    /**
     * Builds an IPv4 or IPv6 address validator.
     *
     * @return ValidatorDefinition
     */
    public static function ip(): array
    {
        return self::definition(
            static fn(mixed $value): bool => \is_string($value)
                && \filter_var($value, FILTER_VALIDATE_IP) !== false,
            'The value must be a valid IP address.',
            ViolationCode::InvalidIp,
        );
    }

    /**
     * Builds a warning-free formatted date and time validator.
     *
     * @return ValidatorDefinition
     *
     * @throws ContractDefinitionException
     */
    public static function dateTime(string $format): array
    {
        if ($format === '') {
            throw new ContractDefinitionException('The date and time format must not be empty.');
        }

        return self::definition(
            static function (mixed $value) use ($format): bool {
                if (!\is_string($value)) {
                    return false;
                }

                $date = DateTimeImmutable::createFromFormat($format, $value);
                $errors = DateTimeImmutable::getLastErrors();

                return $date !== false
                    && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
            },
            \sprintf('The value must be a valid date and time in format "%s".', $format),
            ViolationCode::InvalidDateTime,
        );
    }

    /**
     * Creates the compact definition consumed during extraction.
     *
     * @return ValidatorDefinition
     */
    private static function definition(
        Closure $callback,
        string $message,
        ViolationCode $code,
        bool $caseAware = false,
    ): array {
        return [
            'callback' => $callback,
            'message' => $message,
            'code' => $code,
            'caseAware' => $caseAware,
        ];
    }

    /**
     * Rejects negative count and length constraints.
     *
     * @throws ContractDefinitionException
     */
    private static function assertNonNegative(int $value, string $label): void
    {
        if ($value < 0) {
            throw new ContractDefinitionException($label . ' must not be negative.');
        }
    }

    /**
     * Rejects empty string constraints that would always succeed.
     *
     * @throws ContractDefinitionException
     */
    private static function assertNonEmptyNeedle(string $needle, string $label): void
    {
        if ($needle === '') {
            throw new ContractDefinitionException(\sprintf('The string %s must not be empty.', $label));
        }
    }

    /**
     * Counts Unicode code points without requiring mbstring.
     */
    private static function stringLength(mixed $value): ?int
    {
        if (!\is_string($value)) {
            return null;
        }

        $length = \preg_match_all('/./us', $value);

        return $length === false ? null : $length;
    }
}
