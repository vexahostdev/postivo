<?php

namespace App\Exceptions;

use RuntimeException;

class RelayException extends RuntimeException
{
    public function __construct(string $message, public bool $transient, public ?int $smtpCode = null)
    {
        parent::__construct($message);
    }
}
