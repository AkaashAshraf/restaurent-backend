<?php

namespace App\Exceptions;

class BranchInactiveException extends ApiException
{
    protected string $errorCode = 'BRANCH_INACTIVE';

    protected int $status = 403;

    public function __construct(string $message = 'This branch is not currently accepting operations.')
    {
        parent::__construct($message);
    }
}
