<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Base exception for classification errors.
 * Subclasses determine retry behavior.
 */
abstract class ClassificationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
