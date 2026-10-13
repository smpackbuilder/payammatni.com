<?php

declare(strict_types=1);

namespace Payammatni\Sms;

use Illuminate\Support\ServiceProvider;

class PayammatniSmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/payammatni-sms.php',
            'payammatni-sms'
        );

        $this->app->singleton(SmsService::class, function ($app) {
            return new SmsService(
                (array) $app['config']->get('payammatni-sms', []),
                null,
                $app->make('log')
            );
        });

        $this->app->alias(SmsService::class, 'payammatni-sms');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/payammatni-sms.php' => config_path('payammatni-sms.php'),
            ], 'payammatni-sms-config');
        }
    }
}
