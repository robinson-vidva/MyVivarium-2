<?php
/**
 * Service-layer shared helpers.
 *
 * The services in this directory are the canonical "do the thing" functions
 * for the database. The REST API wraps them, and the upcoming chatbot will
 * call them directly too. They throw `ApiException` on user-visible problems
 * (validation, permission, not-found, conflict). Anything else bubbles up as
 * a generic 500 to the caller.
 */

class ApiException extends RuntimeException
{
    public string $errorCode;
    public int $statusCode;

    public function __construct(string $errorCode, string $message, int $statusCode = 400)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
    }
}

/**
 * Pull a typed value out of an array with simple coercion. Used for query
 * params and JSON body fields.
 */
function svc_arg(array $src, string $key, $default = null)
{
    if (!array_key_exists($key, $src)) return $default;
    $v = $src[$key];
    if ($v === '' || $v === null) return $default;
    return $v;
}

function svc_int(array $src, string $key, ?int $default = null): ?int
{
    $v = svc_arg($src, $key, null);
    if ($v === null) return $default;
    if (!is_numeric($v)) {
        throw new ApiException('invalid_argument', "$key must be an integer", 400);
    }
    return (int)$v;
}

function svc_str(array $src, string $key, ?string $default = null): ?string
{
    $v = svc_arg($src, $key, null);
    if ($v === null) return $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function svc_required_str(array $src, string $key): string
{
    $v = svc_str($src, $key, null);
    if ($v === null || $v === '') {
        throw new ApiException('invalid_argument', "$key is required", 400);
    }
    return $v;
}

/**
 * Pagination: clamp limit to [1, max] (default 50, max 200) and parse offset.
 */
function svc_paginate(array $src, int $defaultLimit = 50, int $maxLimit = 200): array
{
    $limit  = svc_int($src, 'limit', $defaultLimit);
    $offset = svc_int($src, 'offset', 0);
    if ($limit === null || $limit < 1) $limit = $defaultLimit;
    if ($limit > $maxLimit) $limit = $maxLimit;
    if ($offset === null || $offset < 0) $offset = 0;
    return [$limit, $offset];
}

/**
 * Parse ISO-8601 / YYYY-MM-DD into a DB-friendly DATETIME string.
 * Returns null when input is empty; throws on garbage.
 */
function svc_parse_date(?string $value, string $fieldName): ?string
{
    if ($value === null || $value === '') return null;
    $ts = strtotime($value);
    if ($ts === false) {
        throw new ApiException('invalid_argument', "$fieldName is not a valid date", 400);
    }
    return date('Y-m-d H:i:s', $ts);
}

/**
 * Generate a v4-ish UUID. We don't need crypto-grade — these are confirm
 * tokens that also act as pending_operation primary keys.
 */
function svc_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
        substr($hex, 16, 4), substr($hex, 20, 12));
}
