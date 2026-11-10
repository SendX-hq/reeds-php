<?php

declare(strict_types=1);

namespace Reeds\Core;

use Reeds\Exception\WebhookSignatureException;

/**
 * Webhook signatures, the same for every Reeds API:
 *
 *     Reeds-Signature: t=<unix seconds>,v1=<hex>[,v1=<hex>]
 *
 * Each v1 is the hex HMAC-SHA256 of `t + "." + raw body`, keyed with the
 * endpoint's signing secret (whsec_test_... or whsec_live_...). During a secret
 * rotation the header carries two v1 values for 24 hours; any match is enough.
 * Then `t` must be within 5 minutes of now: every attempt, retries included,
 * is signed afresh when it is sent.
 *
 * @internal Use Reeds\Webhooks.
 */
final class Signature
{
    /** Seconds a signed event stays valid either side of now. */
    public const DEFAULT_TOLERANCE = 300;

    private function __construct()
    {
    }

    /**
     * @param string|list<string> $secret
     * @param array{tolerance?: int, now?: int} $options
     */
    public static function verify(mixed $payload, ?string $header, string|array $secret, array $options = []): void
    {
        $secrets = self::secrets($secret);
        if (!is_string($payload)) {
            self::fail(
                'RAW_BODY_REQUIRED',
                'Pass the raw request body (a string), not decoded JSON: a re-encoded body never matches the signature. See the README for your framework.',
            );
        }
        [$timestamp, $signatures] = self::parse($header);
        $signed = false;
        foreach ($secrets as $value) {
            $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $value);
            foreach ($signatures as $given) {
                if (strlen($given) === strlen($expected) && ctype_xdigit($given) && hash_equals($expected, strtolower($given))) {
                    $signed = true;
                }
            }
        }
        if (!$signed) {
            self::fail('SIGNATURE_MISMATCH', 'No signature in the Reeds-Signature header matches this body and secret.');
        }
        $now = $options['now'] ?? time();
        $tolerance = $options['tolerance'] ?? self::DEFAULT_TOLERANCE;
        $age = abs($now - $timestamp);
        if ($age > $tolerance) {
            self::fail('TIMESTAMP_OUTSIDE_TOLERANCE', "The event was signed {$age} s from now; the limit is {$tolerance} s.");
        }
    }

    /**
     * @param string|list<string> $secret
     * @return list<string>
     */
    private static function secrets(string|array $secret): array
    {
        $secrets = is_array($secret) ? array_values($secret) : [$secret];
        foreach ($secrets as $value) {
            if (!is_string($value) || $value === '') {
                $secrets = [];
                break;
            }
        }
        if ($secrets === []) {
            self::fail('NO_SECRET', "Pass your webhook endpoint's signing secret (whsec_...), from Dashboard → Developers.");
        }
        foreach ($secrets as $value) {
            if (str_starts_with($value, 'rcs_')) {
                self::fail('WRONG_SECRET_TYPE', "That's a client secret. Webhooks are signed with the endpoint's signing secret (whsec_...), from Dashboard → Developers.");
            }
        }
        return $secrets;
    }

    /** @return array{0: int, 1: list<string>} */
    private static function parse(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            self::fail('NO_SIGNATURE_HEADER', 'The request has no Reeds-Signature header.');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $at = strpos($part, '=');
            if ($at === false) {
                continue;
            }
            $key = trim(substr($part, 0, $at));
            $value = trim(substr($part, $at + 1));
            if ($key === 't' && preg_match('/^\d+$/', $value) === 1) {
                $timestamp = (int) $value;
            }
            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }
        if ($timestamp === null) {
            self::fail('INVALID_SIGNATURE_HEADER', 'The Reeds-Signature header has no valid t= timestamp.');
        }
        if ($signatures === []) {
            self::fail('NO_SIGNATURE', 'The Reeds-Signature header has no v1= signature.');
        }
        return [$timestamp, $signatures];
    }

    private static function fail(string $code, string $message): never
    {
        throw new WebhookSignatureException($code, $message);
    }
}
