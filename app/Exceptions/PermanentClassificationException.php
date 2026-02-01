<?php

namespace App\Exceptions;

/**
 * Thrown when a classification fails due to a permanent error.
 * Job should use fallback immediately, no retry.
 *
 * Examples: 4xx errors (invalid auth, bad request), invalid API key
 */
class PermanentClassificationException extends ClassificationException
{
    //
}
