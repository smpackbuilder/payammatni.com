# Laravel Payammatni SMS (پنل پیامک پیام متنی)

پکیج لاراول برای ارسال پیامک، دریافت اعتبار پنل و پیگیری وضعیت تحویل از طریق وب‌سرویس SOAP پنل پیامکی پیام متنی ([payammatni.com](https://payammatni.com)).

## امکانات

- ارسال پیامک با متن مشترک به یک یا چند گیرنده (`send`)
- ارسال پیامک‌های متفاوت به گیرندگان مختلف (`sendMulti`)
- دریافت اعتبار پنل (`getCredit`)
- دریافت وضعیت تحویل پیامک‌ها (`getStatus`)
- Auto-Discovery، Facade و فایل کانفیگ قابل انتشار
- پیام خطای فارسی برای کدهای خطای سرویس

## نیازمندی‌ها

- PHP 8.1 یا بالاتر
- افزونه‌ی PHP به نام `soap` (`ext-soap`)
- لاراول 10، 11، 12 یا 13

## نصب

```bash
composer require smpackbuilder/laravel-payammatni-sms
```

پکیج به‌صورت خودکار شناسایی می‌شود و نیازی به ثبت دستی Service Provider نیست.

برای انتشار فایل کانفیگ (اختیاری):

```bash
php artisan vendor:publish --tag=payammatni-sms-config
```

## تنظیمات

مقادیر زیر را در فایل `.env` قرار دهید.

> **توجه:** آدرس WSDL در مثال زیر فقط نمونه است. آدرس واقعی را از پنل یا مستندات وب‌سرویس [payammatni.com](https://payammatni.com) بردارید.

```env
PAYAMMATNI_SMS_WSDL=https://payammatni.com/webservice/send.php?wsdl
PAYAMMATNI_SMS_USERNAME=your-username
PAYAMMATNI_SMS_PASSWORD=your-password
PAYAMMATNI_SMS_SENDER=3000xxxx

# اختیاری
PAYAMMATNI_SMS_PASSWORD_MD5=false
PAYAMMATNI_SMS_TIMEOUT=20
PAYAMMATNI_SMS_VERIFY_SSL=true
```

| کلید                                                  | توضیح                                             |
| ----------------------------------------------------- | ------------------------------------------------- |
| `PAYAMMATNI_SMS_WSDL`                                 | آدرس WSDL سرویس (از پنل یا مستندات سرویس‌دهنده)   |
| `PAYAMMATNI_SMS_USERNAME` / `PAYAMMATNI_SMS_PASSWORD` | اطلاعات ورود به پنل                               |
| `PAYAMMATNI_SMS_SENDER`                               | شماره اختصاصی فرستنده‌ی پیش‌فرض                   |
| `PAYAMMATNI_SMS_PASSWORD_MD5`                         | اگر سرویس رمز را به‌صورت MD5 می‌خواهد `true` کنید |
| `PAYAMMATNI_SMS_TIMEOUT`                              | مهلت اتصال (ثانیه)، پیش‌فرض ۲۰                    |
| `PAYAMMATNI_SMS_VERIFY_SSL`                           | بررسی گواهی SSL، پیش‌فرض `true`                   |

## استفاده

### با Facade

```php
use Payammatni\Sms\Facades\PayammatniSms;

// یک گیرنده
$result = PayammatniSms::send('09120000000', 'سلام، این یک پیامک آزمایشی است.');

// چند گیرنده با متن مشترک (حداکثر ۱۵۰ نفر)
$result = PayammatniSms::send(['09120000000', '09130000000'], 'متن پیامک');

// پیامک فلش (type = 1) و شماره فرستنده‌ی دلخواه
$result = PayammatniSms::send('09120000000', 'متن پیامک', 1, '3000xxxx');

if ($result['success']) {
    // ...
}
```

### ارسال پیامک‌های متفاوت (SendMultiSMS)

هر چهار آرایه باید هم‌اندازه باشند (حداکثر ۶۰ مورد):

```php
PayammatniSms::sendMulti(
    ['3000xxxx', '3000xxxx'],            // فرستنده‌ها
    ['09120000000', '09130000000'],      // گیرنده‌ها
    ['متن اول', 'متن دوم'],              // متن‌ها
    [0, 0],                              // نوع‌ها: 0 معمولی، 1 فلش
);
```

### اعتبار و وضعیت

```php
$credit = PayammatniSms::getCredit();
// ['success' => true, 'credit' => 1500.0, ...]

$status = PayammatniSms::getStatus(['شناسه۱', 'شناسه۲']); // حداکثر ۵۰ شناسه
```

### با تزریق وابستگی

```php
use Payammatni\Sms\SmsService;

class OtpController
{
    public function send(SmsService $sms)
    {
        $result = $sms->send('09120000000', 'کد تایید شما: 12345');

        return response()->json($result, $result['success'] ? 200 : 422);
    }
}
```

## ساختار پاسخ

همه‌ی متدها یک آرایه برمی‌گردانند.

موفق:

```php
[
    'success' => true,
    'method'  => 'SendSMS',
    'message' => 'پاسخ از سرویس دریافت شد.',
    'data'    => [...],   // پاسخ خام سرویس
]
```

خطای سرویس (کد خطای مستندات):

```php
[
    'success'    => false,
    'method'     => 'SendSMS',
    'error_code' => '1',
    'message'    => 'اعتبار پنل برای ارسال کافی نیست.',
    'data'       => [...],
]
```

خطای ارتباط:

| `error_code`       | معنی                                            |
| ------------------ | ----------------------------------------------- |
| `SOAP_FAULT`       | خطای SOAP یا در دسترس نبودن سرویس               |
| `CONNECTION_ERROR` | هر خطای پیش‌بینی‌نشده‌ی دیگر در ارتباط با سرویس |

جزئیات خطاهای ارتباطی در لاگ لاراول ثبت می‌شود (نام کاربری و رمز عبور هرگز لاگ نمی‌شوند).

## استثناها (Exceptions)

خطاهای ارتباطی و خطاهای سرویس **به‌صورت آرایه** برمی‌گردند، اما خطاهای برنامه‌نویسی **Exception** پرتاب می‌کنند:

- `InvalidArgumentException`: ورودی نامعتبر (متن خالی، گیرنده‌ی خالی، بیش از حد مجاز، نوع پیامک نامعتبر، ...)
- `Payammatni\Sms\Exceptions\ConfigurationException` (زیرکلاس `InvalidArgumentException`): تنظیمات ناقص (WSDL، نام کاربری، رمز عبور یا شماره‌ی فرستنده)

## کدهای خطا

**ارسال (`send` / `sendMulti`)**

| کد  | معنی                                |
| --- | ----------------------------------- |
| 0   | نام کاربری یا رمز عبور اشتباه است   |
| 1   | اعتبار پنل برای ارسال کافی نیست     |
| 2   | شماره فرستنده معتبر نیست            |
| 4   | امکان ارسال غیرفعال است             |
| 5   | پنل پیامکی غیرفعال است              |
| 6   | پنل پیامکی منقضی شده است            |
| 7   | متن پیامک خالی است                  |
| 9   | هیچ گیرنده‌ای وارد نشده است         |
| 10  | محدودیت زمانی ارسال از خطوط عمومی   |
| 11  | خطای نامشخص در سرویس پیامک          |
| 16  | تعداد گیرندگان بیشتر از حد مجاز است |
| 17  | تعداد آرایه‌های ورودی برابر نیست    |

**اعتبار (`getCredit`)**: ۰ (نام کاربری/رمز اشتباه)، ۵ (پنل غیرفعال)، ۶ (پنل منقضی)

**وضعیت (`getStatus`)**: ۱۴ (شناسه نامعتبر)، ۱۵ (نام کاربری/رمز اشتباه)، ۱۶ (تعداد شناسه‌ها بیش از حد مجاز)، ۱۷ (پنل غیرفعال)، ۱۸ (پنل منقضی)

## نکته‌ی مهم درباره‌ی `getCredit`

- اعتبار با جداکننده‌ی هزارگان (مثل `971,333`) یا ارقام فارسی هم شناسایی می‌شود و در کلید `credit` به‌صورت عدد (`971333.0`) برمی‌گردد.
- اگر سرویس خطا و اعتبار را هر دو به‌صورت یک عدد ساده برگرداند (مثلاً `0` یا `5`)، تشخیص «اعتبار صفر» از «خطای ۰» ممکن نیست و عدد به‌عنوان اعتبار تفسیر می‌شود. خطاها فقط وقتی شناسایی می‌شوند که پاسخ در ساختار `Return` یا آرایه برگردد.

## تست

```bash
composer install
composer test
```

## مشارکت و گزارش مشکل

مشکلات و پیشنهادها را در [Issues](https://github.com/smpackbuilder/laravel-payammatni-sms/issues) مخزن گزارش کنید.

## مجوز

این پکیج تحت مجوز [MIT](LICENSE) منتشر شده است.
