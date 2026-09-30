<?php

namespace App\Services\Push;

use App\Contracts\PushGateway;
use Illuminate\Support\Facades\Log;

/** Default push driver — see LogSmsGateway's docblock for the same reasoning. */
class LogPushGateway implements PushGateway
{
    public function send(string $deviceToken, string $title, string $body, array $data = []): void
    {
        Log::info("[push] token={$deviceToken} title=\"{$title}\" body=\"{$body}\"");
    }
}
