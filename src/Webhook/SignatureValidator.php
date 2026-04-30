<?php

namespace Kicol\FullFlow\Webhook;

class SignatureValidator
{
    /**
     * Valida assinatura HMAC-SHA256 enviada pelo FullFlow.
     *
     * Use SEMPRE hash_equals para evitar timing attacks.
     */
    public static function isValid(string $rawBody, string $providedSignature, string $secret): bool
    {
        if ($secret === '' || $providedSignature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $providedSignature);
    }

    /**
     * Valida tolerância de timestamp (replay protection).
     */
    public static function isTimestampValid(string $isoTimestamp, int $toleranceMinutes = 5): bool
    {
        try {
            $ts = strtotime($isoTimestamp);
            if ($ts === false) {
                return false;
            }
            $diffMin = abs(time() - $ts) / 60;
            return $diffMin <= $toleranceMinutes;
        } catch (\Throwable) {
            return false;
        }
    }
}
