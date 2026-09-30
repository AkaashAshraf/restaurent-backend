<?php

namespace App\Providers;

use App\Contracts\PushGateway;
use App\Contracts\SmsGateway;
use App\Services\Push\LogPushGateway;
use App\Services\Sms\LogSmsGateway;
use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One TenantContext per request — set by IdentifyTenant middleware,
        // read by every tenant-scoped model query.
        $this->app->singleton(TenantContext::class);

        // 'log' is the only driver implemented in this phase; the match
        // is written to make adding a real provider a one-line change
        // once notifications.sms.driver/notifications.push.driver point
        // at one, without touching SmsChannel/PushChannel or any
        // Notification class that depends on the contract.
        $this->app->bind(SmsGateway::class, fn () => match (config('notifications.sms.driver', 'log')) {
            default => new LogSmsGateway(),
        });

        $this->app->bind(PushGateway::class, fn () => match (config('notifications.push.driver', 'log')) {
            default => new LogPushGateway(),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
