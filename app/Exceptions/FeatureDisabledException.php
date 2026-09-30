<?php

namespace App\Exceptions;

class FeatureDisabledException extends ApiException
{
    protected string $errorCode = 'FEATURE_DISABLED';

    protected int $status = 403;

    public function __construct(string $featureKey)
    {
        parent::__construct("This feature ({$featureKey}) is not enabled for this restaurant.");
    }
}
