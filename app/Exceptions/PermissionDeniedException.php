<?php

namespace App\Exceptions;

class PermissionDeniedException extends ApiException
{
    protected string $errorCode = 'FORBIDDEN';

    protected int $status = 403;

    public function __construct(string $message = 'You do not have permission to perform this action.')
    {
        parent::__construct($message);
    }
}
