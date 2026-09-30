<?php

namespace App\Exceptions;

class SubscriptionExpiredException extends ApiException
{
    protected string $errorCode = 'SUBSCRIPTION_EXPIRED';

    protected int $status = 403;

    public function __construct(string $message = 'This restaurant\'s subscription is not active.')
    {
        parent::__construct($message);
    }
}
