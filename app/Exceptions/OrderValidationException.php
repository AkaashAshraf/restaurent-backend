<?php

namespace App\Exceptions;

/**
 * Business-rule violations on an order request that aren't a missing
 * feature flag (see FeatureDisabledException for that) — an order type
 * a branch has switched off, a table that's already occupied, an amount
 * below the branch's minimum, an unavailable product, or an invalid
 * modifier selection. One class, message set per call site, rather than
 * a exception subclass per rule.
 */
class OrderValidationException extends ApiException
{
    protected string $errorCode = 'VALIDATION_ERROR';

    protected int $status = 422;

    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
