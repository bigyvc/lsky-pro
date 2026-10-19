<?php
namespace App\Exceptions;

class TelegramRateLimitException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message);
    }
}
