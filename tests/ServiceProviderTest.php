<?php

declare(strict_types=1);

namespace Payammatni\Sms\Tests;

use Payammatni\Sms\PayammatniSmsServiceProvider;
use Payammatni\Sms\Facades\PayammatniSms;
use Payammatni\Sms\SmsService;
use Orchestra\Testbench\TestCase;

class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PayammatniSmsServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['PayammatniSms' => PayammatniSms::class];
    }

    public function test_default_config_is_merged(): void
    {
        $this->assertSame(20, config('payammatni-sms.timeout'));
        $this->assertTrue(config('payammatni-sms.verify_ssl'));
        $this->assertFalse(config('payammatni-sms.password_md5'));
    }

    public function test_service_is_registered_as_singleton(): void
    {
        $this->assertInstanceOf(SmsService::class, $this->app->make(SmsService::class));
        $this->assertSame(
            $this->app->make(SmsService::class),
            $this->app->make(SmsService::class)
        );
        $this->assertSame(
            $this->app->make(SmsService::class),
            $this->app->make('payammatni-sms')
        );
    }

    public function test_facade_resolves_to_the_service(): void
    {
        $this->assertInstanceOf(SmsService::class, PayammatniSms::getFacadeRoot());
    }
}
