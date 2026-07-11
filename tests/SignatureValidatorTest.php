<?php

namespace Kicol\FullFlow\Tests;

use Kicol\FullFlow\Webhook\SignatureValidator;
use PHPUnit\Framework\TestCase;

class SignatureValidatorTest extends TestCase
{
    public function test_valid_signature_passes(): void
    {
        $body = '{"foo":"bar"}';
        $secret = 'my_test_secret';
        $signature = hash_hmac('sha256', $body, $secret);

        $this->assertTrue(SignatureValidator::isValid($body, $signature, $secret));
    }

    public function test_invalid_signature_fails(): void
    {
        $body = '{"foo":"bar"}';
        $secret = 'my_test_secret';

        $this->assertFalse(SignatureValidator::isValid($body, 'wrong', $secret));
    }

    public function test_empty_secret_fails(): void
    {
        $this->assertFalse(SignatureValidator::isValid('body', 'sig', ''));
    }

    public function test_empty_signature_fails(): void
    {
        $this->assertFalse(SignatureValidator::isValid('body', '', 'secret'));
    }

    public function test_tampered_body_fails(): void
    {
        $secret = 's';
        $signature = hash_hmac('sha256', 'original', $secret);

        $this->assertFalse(SignatureValidator::isValid('tampered', $signature, $secret));
    }

    public function test_v2_valid_signature_passes(): void
    {
        $body = '{"foo":"bar"}';
        $secret = 'my_test_secret';
        $t = time();
        $header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);

        $this->assertTrue(SignatureValidator::isValidV2($body, $header, $secret));
    }

    public function test_v2_wrong_hmac_fails(): void
    {
        $t = time();
        $header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.other-body', 's');

        $this->assertFalse(SignatureValidator::isValidV2('body', $header, 's'));
    }

    public function test_v2_expired_timestamp_fails_even_with_valid_hmac(): void
    {
        $body = 'body';
        $secret = 's';
        $t = time() - 3600;
        $header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);

        $this->assertFalse(SignatureValidator::isValidV2($body, $header, $secret, 5));
    }

    public function test_v2_replayed_with_new_timestamp_fails(): void
    {
        // O ataque que o v1 permitia: reenviar um webhook capturado trocando
        // o header de timestamp. No v2 o t assinado invalida o HMAC.
        $body = 'body';
        $secret = 's';
        $captured = time() - 3600;
        $hmacCapturado = hash_hmac('sha256', $captured . '.' . $body, $secret);
        $headerAdulterado = 't=' . time() . ',v1=' . $hmacCapturado;

        $this->assertFalse(SignatureValidator::isValidV2($body, $headerAdulterado, $secret));
    }

    public function test_v2_malformed_header_fails(): void
    {
        $this->assertFalse(SignatureValidator::isValidV2('body', 'garbage', 's'));
        $this->assertFalse(SignatureValidator::isValidV2('body', 't=abc,v1=def', 's'));
        $this->assertFalse(SignatureValidator::isValidV2('body', 't=123', 's'));
        $this->assertFalse(SignatureValidator::isValidV2('body', '', 's'));
    }

    public function test_v2_empty_secret_fails(): void
    {
        $t = time();
        $header = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.body', '');

        $this->assertFalse(SignatureValidator::isValidV2('body', $header, ''));
    }

    public function test_recent_timestamp_passes(): void
    {
        $this->assertTrue(SignatureValidator::isTimestampValid(date('c'), 5));
    }

    public function test_old_timestamp_fails(): void
    {
        $old = date('c', time() - 3600);
        $this->assertFalse(SignatureValidator::isTimestampValid($old, 5));
    }

    public function test_invalid_timestamp_string_fails(): void
    {
        $this->assertFalse(SignatureValidator::isTimestampValid('not-a-date', 5));
    }
}
