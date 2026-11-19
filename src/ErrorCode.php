<?php

declare(strict_types=1);

namespace Aja;

/** Closed catalogue of the error codes the AJA API returns. */
final class ErrorCode
{
    public const VALIDATION = 'VALIDATION';
    public const SITE_KEY_INVALID = 'SITE_KEY_INVALID';
    public const SITE_KEY_REVOKED = 'SITE_KEY_REVOKED';
    public const FORBIDDEN = 'FORBIDDEN';
    public const NOT_FOUND = 'NOT_FOUND';
    public const SITE_NOT_FOUND = 'SITE_NOT_FOUND';
    public const ORDER_NOT_FOUND = 'ORDER_NOT_FOUND';
    public const COMMAND_NOT_FOUND = 'COMMAND_NOT_FOUND';
    public const COMMAND_ALREADY_ACKED = 'COMMAND_ALREADY_ACKED';
    public const PRODUCT_NOT_FOUND = 'PRODUCT_NOT_FOUND';
    public const PRODUCT_SYNC_PENDING = 'PRODUCT_SYNC_PENDING';
    public const PRODUCT_SCHEMA_OUTDATED = 'PRODUCT_SCHEMA_OUTDATED';
    /** The merchant now edits the product form in AJA: the site can no longer replace it. */
    public const PRODUCT_SCHEMA_LOCKED = 'PRODUCT_SCHEMA_LOCKED';
    /** The site enforces an IP allow-list and this server's address is not on it. */
    public const SITE_IP_NOT_ALLOWED = 'SITE_IP_NOT_ALLOWED';
    /** The ownership code is unknown or expired, or the shop's domain changed since AJA sent it. */
    public const VERIFICATION_FAILED = 'VERIFICATION_FAILED';
    /** The site has not proven its domain yet: its key only serves the link-up (profile, proof, polling). */
    public const SITE_NOT_VERIFIED = 'SITE_NOT_VERIFIED';
    /** The call came from a server address the merchant has not confirmed yet. */
    public const SITE_IP_NOT_CONFIRMED = 'SITE_IP_NOT_CONFIRMED';
    /** The merchant's decision has a type this site does not handle: acknowledge it as refused. */
    public const COMMAND_UNSUPPORTED_TYPE = 'COMMAND_UNSUPPORTED_TYPE';
    /** The order cannot move to that status from the one it has. */
    public const ORDER_TRANSITION_NOT_ALLOWED = 'ORDER_TRANSITION_NOT_ALLOWED';
    public const TEST_TRIAL_EXPIRED = 'TEST_TRIAL_EXPIRED';
    public const PROD_NOT_ACTIVE = 'PROD_NOT_ACTIVE';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const INTERNAL = 'INTERNAL';

    /** Codes that will never succeed on retry: a bad key, a rejected body, a missing order. */
    public const NON_RETRYABLE = [
        self::VALIDATION,
        self::SITE_KEY_INVALID,
        self::SITE_KEY_REVOKED,
        self::FORBIDDEN,
        self::NOT_FOUND,
        self::SITE_NOT_FOUND,
        self::ORDER_NOT_FOUND,
        self::COMMAND_NOT_FOUND,
        self::PRODUCT_NOT_FOUND,
        self::PRODUCT_SCHEMA_OUTDATED,
        self::PRODUCT_SCHEMA_LOCKED,
        self::SITE_IP_NOT_ALLOWED,
        self::VERIFICATION_FAILED,
        // Repeating the call changes nothing until the merchant or the site acts:
        // retrying them in a loop hid the reason a site stayed « pending » (18 Nov.).
        self::SITE_NOT_VERIFIED,
        self::SITE_IP_NOT_CONFIRMED,
        self::COMMAND_UNSUPPORTED_TYPE,
        self::ORDER_TRANSITION_NOT_ALLOWED,
        self::TEST_TRIAL_EXPIRED,
        self::PROD_NOT_ACTIVE,
    ];

    /** True when the SDK should back off and try the same call again. */
    public static function isRetryable(string $code): bool
    {
        return !in_array($code, self::NON_RETRYABLE, true);
    }
}
