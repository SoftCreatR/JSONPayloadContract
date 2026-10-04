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
use SoftCreatR\JsonPayloadContract\Internal\BuiltInValidatorFactory;

/**
 * Immutable definition of one named output field.
 */
final class Field
{
    /**
     * @var list<string>
     */
    private array $selectors;

    private ValueType $type = ValueType::Mixed;

    private bool $nullable = false;

    private bool $coerce = false;

    private bool $hasDefault = false;

    private mixed $default = null;

    private ?Contract $nestedContract = null;

    private ?int $minimumMatches = null;

    private ?int $maximumMatches = null;

    /**
     * @var list<array{callback: Closure, message: string, code: ViolationCode, caseAware: bool}>
     */
    private array $validators = [];

    /**
     * @var list<Closure>
     */
    private array $transformers = [];

    /**
     * Initializes a field factory with its cardinality and optional first selector.
     */
    private function __construct(private readonly Cardinality $cardinality, ?string $selector)
    {
        $this->selectors = $selector === null ? [] : [$selector];
    }

    /**
     * Creates a field that must resolve to exactly one value.
     */
    public static function required(?string $selector = null): self
    {
        return new self(Cardinality::Required, $selector);
    }

    /**
     * Creates a field that may resolve to zero or one value.
     */
    public static function optional(?string $selector = null): self
    {
        return new self(Cardinality::Optional, $selector);
    }

    /**
     * Creates a field that collects zero or more values into a list.
     */
    public static function many(?string $selector = null): self
    {
        return new self(Cardinality::Many, $selector);
    }

    /**
     * Replaces the primary selector without mutating this definition.
     */
    public function from(string $selector): self
    {
        $clone = clone $this;
        $clone->selectors = [$selector];

        return $clone;
    }

    /**
     * Appends a selector used when every preceding selector matches nothing.
     */
    public function fallback(string $selector): self
    {
        $clone = clone $this;
        $clone->selectors[] = $selector;

        return $clone;
    }

    /**
     * Configures whether matched JSON null values are accepted.
     */
    public function nullable(bool $nullable = true): self
    {
        $clone = clone $this;
        $clone->nullable = $nullable;

        return $clone;
    }

    /**
     * Enables or disables conversion between supported scalar types.
     */
    public function coerce(bool $coerce = true): self
    {
        $clone = clone $this;
        $clone->coerce = $coerce;

        return $clone;
    }

    /**
     * Supplies a value only when an optional field has no selector match.
     *
     * @throws ContractDefinitionException
     */
    public function default(mixed $value): self
    {
        if ($this->cardinality !== Cardinality::Optional) {
            throw new ContractDefinitionException('Defaults are only valid for optional fields.');
        }

        $clone = clone $this;
        $clone->hasDefault = true;
        $clone->default = $value;

        return $clone;
    }

    /**
     * Removes any strict value-type constraint.
     */
    public function mixed(): self
    {
        return $this->withType(ValueType::Mixed);
    }

    /**
     * Requires the matched value to be null.
     */
    public function null(): self
    {
        return $this->withType(ValueType::Null);
    }

    /**
     * Requires a PHP string, integer, float, or boolean value.
     */
    public function scalar(): self
    {
        return $this->withType(ValueType::Scalar);
    }

    /**
     * Requires a PHP string value.
     */
    public function string(): self
    {
        return $this->withType(ValueType::String);
    }

    /**
     * Requires a PHP integer value.
     */
    public function integer(): self
    {
        return $this->withType(ValueType::Integer);
    }

    /**
     * Requires a PHP float value.
     */
    public function float(): self
    {
        return $this->withType(ValueType::Float);
    }

    /**
     * Requires a PHP integer or float value.
     */
    public function number(): self
    {
        return $this->withType(ValueType::Number);
    }

    /**
     * Requires a PHP boolean value.
     */
    public function boolean(): self
    {
        return $this->withType(ValueType::Boolean);
    }

    /**
     * Requires a PHP array value.
     */
    public function array(): self
    {
        return $this->withType(ValueType::Array);
    }

    /**
     * Requires a PHP array with consecutive integer keys starting at zero.
     */
    public function list(): self
    {
        return $this->withType(ValueType::List);
    }

    /**
     * Requires a non-list PHP array with at least one named or sparse key.
     */
    public function associativeArray(): self
    {
        return $this->withType(ValueType::AssociativeArray);
    }

    /**
     * Requires a PHP object value.
     */
    public function object(): self
    {
        return $this->withType(ValueType::Object);
    }

    /**
     * Requires a PHP array or object value.
     */
    public function collection(): self
    {
        return $this->withType(ValueType::Collection);
    }

    /**
     * Requires a many-value field to resolve to at least the given number of matches.
     *
     * @throws ContractDefinitionException
     */
    public function minMatches(int $minimum): self
    {
        $this->assertManyCardinality('Match limits');
        self::assertNonNegative($minimum, 'The minimum match count');

        if ($this->maximumMatches !== null && $minimum > $this->maximumMatches) {
            throw new ContractDefinitionException('The minimum match count must not exceed the maximum.');
        }

        $clone = clone $this;
        $clone->minimumMatches = $minimum;

        return $clone;
    }

    /**
     * Requires a many-value field to resolve to no more than the given number of matches.
     *
     * @throws ContractDefinitionException
     */
    public function maxMatches(int $maximum): self
    {
        $this->assertManyCardinality('Match limits');
        self::assertNonNegative($maximum, 'The maximum match count');

        if ($this->minimumMatches !== null && $maximum < $this->minimumMatches) {
            throw new ContractDefinitionException('The maximum match count must not be less than the minimum.');
        }

        $clone = clone $this;
        $clone->maximumMatches = $maximum;

        return $clone;
    }

    /**
     * Requires a many-value field to resolve within an inclusive match-count range.
     *
     * @throws ContractDefinitionException
     */
    public function matchesBetween(int $minimum, int $maximum): self
    {
        if ($minimum > $maximum) {
            throw new ContractDefinitionException('The minimum match count must not exceed the maximum.');
        }

        return $this->minMatches($minimum)->maxMatches($maximum);
    }

    /**
     * Applies another contract to each matched array or object value.
     */
    public function shape(Contract $contract): self
    {
        $clone = clone $this;
        $clone->nestedContract = $contract;

        return $clone;
    }

    /**
     * Adds an application-owned predicate and its failure message.
     */
    public function validate(
        Closure $validator,
        string $message = 'The value is invalid.',
        ViolationCode $code = ViolationCode::ValidationFailed,
    ): self {
        $clone = clone $this;
        $clone->validators[] = [
            'callback' => $validator,
            'message' => $message,
            'code' => $code,
            'caseAware' => false,
        ];

        return $clone;
    }

    /**
     * Rejects empty strings, arrays, and objects.
     */
    public function nonEmpty(): self
    {
        return $this->withValidator(BuiltInValidatorFactory::nonEmpty());
    }

    /**
     * Requires a syntactically valid email address.
     */
    public function email(): self
    {
        return $this->withValidator(BuiltInValidatorFactory::email());
    }

    /**
     * Restricts a value to a non-empty strict allowlist.
     *
     * @param list<mixed> $values
     *
     * @throws ContractDefinitionException
     */
    public function oneOf(array $values): self
    {
        return $this->withValidator(BuiltInValidatorFactory::oneOf($values));
    }

    /**
     * Requires a string to match a developer-supplied PCRE pattern.
     *
     * @throws ContractDefinitionException
     */
    public function matching(string $pattern): self
    {
        return $this->withValidator(BuiltInValidatorFactory::matching($pattern));
    }

    /**
     * Requires a numeric value to be greater than or equal to the given minimum.
     */
    public function minimum(int|float $minimum): self
    {
        return $this->withValidator(BuiltInValidatorFactory::minimum($minimum));
    }

    /**
     * Requires a numeric value to be less than or equal to the given maximum.
     */
    public function maximum(int|float $maximum): self
    {
        return $this->withValidator(BuiltInValidatorFactory::maximum($maximum));
    }

    /**
     * Requires a numeric value to fall within an inclusive range.
     *
     * @throws ContractDefinitionException
     */
    public function between(int|float $minimum, int|float $maximum): self
    {
        if ($minimum > $maximum) {
            throw new ContractDefinitionException('The minimum value must not exceed the maximum.');
        }

        return $this->minimum($minimum)->maximum($maximum);
    }

    /**
     * Requires a string to contain at least the given number of Unicode code points.
     *
     * @throws ContractDefinitionException
     */
    public function minLength(int $minimum): self
    {
        return $this->withValidator(BuiltInValidatorFactory::minLength($minimum));
    }

    /**
     * Requires a string to contain no more than the given number of Unicode code points.
     *
     * @throws ContractDefinitionException
     */
    public function maxLength(int $maximum): self
    {
        return $this->withValidator(BuiltInValidatorFactory::maxLength($maximum));
    }

    /**
     * Requires a string to begin with the supplied non-empty prefix.
     *
     * Case-insensitive matching performs ASCII case folding.
     *
     * @throws ContractDefinitionException
     */
    public function startsWith(string $prefix): self
    {
        return $this->withValidator(BuiltInValidatorFactory::startsWith($prefix));
    }

    /**
     * Requires a string to end with the supplied non-empty suffix.
     *
     * Case-insensitive matching performs ASCII case folding.
     *
     * @throws ContractDefinitionException
     */
    public function endsWith(string $suffix): self
    {
        return $this->withValidator(BuiltInValidatorFactory::endsWith($suffix));
    }

    /**
     * Requires a string to contain the supplied non-empty substring.
     *
     * Case-insensitive matching performs ASCII case folding.
     *
     * @throws ContractDefinitionException
     */
    public function contains(string $substring): self
    {
        return $this->withValidator(BuiltInValidatorFactory::contains($substring));
    }

    /**
     * Requires an array to contain at least the given number of items.
     *
     * @throws ContractDefinitionException
     */
    public function minItems(int $minimum): self
    {
        return $this->withValidator(BuiltInValidatorFactory::minItems($minimum));
    }

    /**
     * Requires an array to contain no more than the given number of items.
     *
     * @throws ContractDefinitionException
     */
    public function maxItems(int $maximum): self
    {
        return $this->withValidator(BuiltInValidatorFactory::maxItems($maximum));
    }

    /**
     * Requires a canonical UUID string.
     */
    public function uuid(): self
    {
        return $this->withValidator(BuiltInValidatorFactory::uuid());
    }

    /**
     * Requires an absolute URL using one of the explicitly allowed schemes.
     *
     * @param list<string> $schemes
     *
     * @throws ContractDefinitionException
     */
    public function url(array $schemes = ['http', 'https']): self
    {
        return $this->withValidator(BuiltInValidatorFactory::url($schemes));
    }

    /**
     * Requires a valid IPv4 or IPv6 address.
     */
    public function ip(): self
    {
        return $this->withValidator(BuiltInValidatorFactory::ip());
    }

    /**
     * Requires a date and time accepted without warnings by the supplied PHP format.
     */
    public function dateTime(string $format = DATE_ATOM): self
    {
        return $this->withValidator(BuiltInValidatorFactory::dateTime($format));
    }

    /**
     * Adds an application-owned transformation after successful validation.
     */
    public function map(Closure $transformer): self
    {
        $clone = clone $this;
        $clone->transformers[] = $transformer;

        return $clone;
    }

    /**
     * Returns selectors in primary-to-last-fallback order.
     *
     * @return list<string>
     */
    public function selectors(): array
    {
        return $this->selectors;
    }

    /**
     * Returns the required, optional, or many cardinality.
     */
    public function cardinality(): Cardinality
    {
        return $this->cardinality;
    }

    /**
     * Returns the strict target type.
     */
    public function type(): ValueType
    {
        return $this->type;
    }

    /**
     * Reports whether matched null values are accepted.
     */
    public function allowsNull(): bool
    {
        return $this->nullable;
    }

    /**
     * Reports whether scalar coercion is enabled.
     */
    public function allowsCoercion(): bool
    {
        return $this->coerce;
    }

    /**
     * Reports whether a missing optional field has a default.
     */
    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    /**
     * Returns the configured default value.
     */
    public function defaultValue(): mixed
    {
        return $this->default;
    }

    /**
     * Returns the configured nested contract when present.
     */
    public function nestedContract(): ?Contract
    {
        return $this->nestedContract;
    }

    /**
     * Returns custom predicates and their failure messages.
     *
     * @return list<array{callback: Closure, message: string, code: ViolationCode, caseAware: bool}>
     */
    public function validators(): array
    {
        return $this->validators;
    }

    /**
     * Returns transformations in execution order.
     *
     * @return list<Closure>
     */
    public function transformers(): array
    {
        return $this->transformers;
    }

    /**
     * Returns the optional minimum match count for a many-value field.
     */
    public function minimumMatches(): ?int
    {
        return $this->minimumMatches;
    }

    /**
     * Returns the optional maximum match count for a many-value field.
     */
    public function maximumMatches(): ?int
    {
        return $this->maximumMatches;
    }

    /**
     * Returns a clone carrying the selected strict target type.
     */
    private function withType(ValueType $type): self
    {
        $clone = clone $this;
        $clone->type = $type;

        return $clone;
    }

    /**
     * Adds a prebuilt validator definition without exposing internal factories.
     *
     * @param array{callback: Closure, message: string, code: ViolationCode, caseAware: bool} $validator
     */
    private function withValidator(array $validator): self
    {
        $clone = clone $this;
        $clone->validators[] = $validator;

        return $clone;
    }

    /**
     * Rejects match limits on singular field definitions.
     *
     * @throws ContractDefinitionException
     */
    private function assertManyCardinality(string $rule): void
    {
        if ($this->cardinality !== Cardinality::Many) {
            throw new ContractDefinitionException($rule . ' are only valid for many-value fields.');
        }
    }

    /**
     * Rejects negative match limits.
     *
     * @throws ContractDefinitionException
     */
    private static function assertNonNegative(int $value, string $label): void
    {
        if ($value < 0) {
            throw new ContractDefinitionException($label . ' must not be negative.');
        }
    }
}
