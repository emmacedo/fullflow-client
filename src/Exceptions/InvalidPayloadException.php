<?php

namespace Kicol\FullFlow\Exceptions;

class InvalidPayloadException extends FullFlowException
{
    public function __construct(string $message = '', public array $errors = [])
    {
        parent::__construct($message);
    }
}
