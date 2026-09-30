<?php

namespace App\Exceptions;

/**
 * A coupon code that's missing, inactive, outside its validity window,
 * below its minimum order amount, or past its usage/per-customer limit.
 * One class, message set per call site — same convention as
 * OrderValidationException.
 */
class CouponException extends ApiException
{
    protected string $errorCode = 'INVALID_COUPON';

    protected int $status = 422;

    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
