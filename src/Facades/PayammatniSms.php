<?php

declare(strict_types=1);

namespace Payammatni\Sms\Facades;

use Payammatni\Sms\SmsService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array send(array|string $recipients, string $message, int $type = 0, ?string $sender = null)
 * @method static array sendMulti(array $senders, array $recipients, array $messages, array $types)
 * @method static array getCredit()
 * @method static array getStatus(array|string $uniqueIds)
 *
 * @see \Payammatni\Sms\SmsService
 */
class PayammatniSms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SmsService::class;
    }
}
