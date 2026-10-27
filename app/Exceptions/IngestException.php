<?php

namespace App\Exceptions;

use RuntimeException;

class IngestException extends RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $httpStatus = 422, public ?array $details = null)
    {
        parent::__construct($message);
    }
}
