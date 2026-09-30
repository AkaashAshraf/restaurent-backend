<?php

namespace App\Exceptions;

class BranchLimitExceededException extends ApiException
{
    protected string $errorCode = 'VALIDATION_ERROR';

    protected int $status = 422;

    public function __construct(string $message = 'This restaurant has reached its branch limit.')
    {
        parent::__construct($message);
    }
}
