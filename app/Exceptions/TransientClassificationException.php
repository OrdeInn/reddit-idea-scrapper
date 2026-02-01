<?php

namespace App\Exceptions;

/**
 * Thrown when a classification fails due to a transient error.
 * Job should retry on non-final attempts.
 *
 * Examples: Network timeouts, 5xx errors, rate limiting (429)
 */
class TransientClassificationException extends ClassificationException
{
    //
}
