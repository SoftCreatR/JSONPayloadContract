<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract;

/**
 * Provides stable machine-readable categories for extraction failures.
 */
enum ViolationCode: string
{
    case AboveMaximum = 'above_maximum';
    case BelowMinimum = 'below_minimum';
    case Empty = 'empty';
    case CyclicPayload = 'cyclic_payload';
    case DuplicateObjectMember = 'duplicate_object_member';
    case EvaluationLimitExceeded = 'evaluation_limit_exceeded';
    case InvalidJson = 'invalid_json';
    case InvalidDateTime = 'invalid_date_time';
    case InvalidEmail = 'invalid_email';
    case InvalidIp = 'invalid_ip';
    case InvalidUrl = 'invalid_url';
    case InvalidUuid = 'invalid_uuid';
    case MissingRequired = 'missing_required';
    case MissingPrefix = 'missing_prefix';
    case MissingSuffix = 'missing_suffix';
    case MultipleMatches = 'multiple_matches';
    case NotAllowed = 'not_allowed';
    case NullNotAllowed = 'null_not_allowed';
    case PatternMismatch = 'pattern_mismatch';
    case PayloadTooDeep = 'payload_too_deep';
    case PayloadTooLarge = 'payload_too_large';
    case PayloadTooComplex = 'payload_too_complex';
    case SelectorEvaluationFailed = 'selector_evaluation_failed';
    case SubstringNotFound = 'substring_not_found';
    case TooFewItems = 'too_few_items';
    case TooFewMatches = 'too_few_matches';
    case TooLong = 'too_long';
    case TooManyItems = 'too_many_items';
    case TooManyMatches = 'too_many_matches';
    case TooManyViolations = 'too_many_violations';
    case TooShort = 'too_short';
    case UnexpectedType = 'unexpected_type';
    case UnsupportedPayloadType = 'unsupported_payload_type';
    case ValidationFailed = 'validation_failed';
    case TransformationFailed = 'transformation_failed';
}
