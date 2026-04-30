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
