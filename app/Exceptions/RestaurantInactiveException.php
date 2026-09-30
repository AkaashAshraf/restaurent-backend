<?php

namespace App\Exceptions;

class RestaurantInactiveException extends ApiException
{
    protected string $errorCode = 'RESTAURANT_INACTIVE';

    protected int $status = 403;

    public function __construct(string $message = 'This restaurant is not currently active.')
    {
        parent::__construct($message);
    }
}
