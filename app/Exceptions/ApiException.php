<?php

namespace App\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $codeName,
        string $message,
        public readonly int $status
    ) {
        parent::__construct($message);
    }
}
