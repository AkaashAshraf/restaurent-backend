<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Base class for every domain-level API error. Each subclass fixes a
 * standard `code` (spec #123) so clients can branch on it rather than
 * parsing messages.
 */
abstract class ApiException extends Exception
{
    protected string $errorCode = 'SERVER_ERROR';

    protected int $status = 400;

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->errorCode, $this->getMessage(), $this->status);
    }
}
