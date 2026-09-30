<?php

namespace App\Exceptions;

/**
 * Business-rule violations on a payment: an amount that overpays an
 * order's outstanding balance, confirming/refunding a payment that isn't
 * in the right state for that transition, recording a payment against a
 * cancelled order, and so on.
 */
class PaymentException extends ApiException
{
    protected string $errorCode = 'PAYMENT_ERROR';

    protected int $status = 422;

    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
