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
     * Valida o esquema v2 (header X-Fullflow-Signature-V2): "t=<unix>,v1=<hmac>",
     * onde hmac = HMAC-SHA256("<t>.<rawBody>", secret).
     *
     * Diferença para o v1: o timestamp participa da assinatura, então o
     * anti-replay não depende de um header forjável — capturar um webhook
     * e reenviá-lo com timestamp novo invalida o HMAC.
     */
    public static function isValidV2(string $rawBody, string $providedHeader, string $secret, int $toleranceMinutes = 5): bool
    {
        if ($secret === '' || $providedHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $providedHeader) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$key] = $value;
        }

        $t = $parts['t'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if ($t === '' || $v1 === '' || !ctype_digit($t)) {
            return false;
        }

        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        if (!hash_equals($expected, $v1)) {
            return false;
        }

        return abs(time() - (int) $t) <= $toleranceMinutes * 60;
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
