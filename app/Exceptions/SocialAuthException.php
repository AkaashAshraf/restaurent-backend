<?php

namespace App\Exceptions;

/** A Google / Apple sign-in token that can't be trusted, or a provider that isn't set up for this app. */
class SocialAuthException extends ApiException
{
    protected string $errorCode = 'SOCIAL_AUTH_FAILED';

    protected int $status = 401;

    public function __construct(string $message, ?int $status = null, ?string $code = null)
    {
        parent::__construct($message);
        if ($status !== null) {
            $this->status = $status;
        }
        if ($code !== null) {
            $this->errorCode = $code;
        }
    }
}
