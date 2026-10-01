<?php

namespace App\Exceptions;

/** A customer who signed in with Google / Apple has to add a phone number before ordering. */
class PhoneRequiredException extends ApiException
{
    protected string $errorCode = 'PHONE_REQUIRED';

    protected int $status = 422;

    public function __construct()
    {
        parent::__construct('Please add your phone number before placing an order.');
    }
}
