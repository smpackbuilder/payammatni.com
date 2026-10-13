<?php

declare(strict_types=1);

namespace Payammatni\Sms;

use Payammatni\Sms\Exceptions\ConfigurationException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SoapClient;
use SoapFault;
use Throwable;

class SmsService
{
    private const SEND_ERRORS = [
        '0'  => 'نام کاربری یا رمز عبور اشتباه است.',
        '1'  => 'اعتبار پنل برای ارسال کافی نیست.',
        '2'  => 'شماره فرستنده معتبر نیست.',
        '4'  => 'امکان ارسال غیرفعال است.',
        '5'  => 'پنل پیامکی غیرفعال است.',
        '6'  => 'پنل پیامکی منقضی شده است.',
        '7'  => 'متن پیامک خالی است.',
        '9'  => 'هیچ گیرنده‌ای وارد نشده است.',
        '10' => 'محدودیت زمانی ارسال از خطوط عمومی.',
        '11' => 'خطای نامشخص در سرویس پیامک.',
        '16' => 'تعداد گیرندگان بیشتر از حد مجاز است.',
        '17' => 'تعداد آرایه‌های ورودی برابر نیست.',
    ];

    private const CREDIT_ERRORS = [
        '0' => 'نام کاربری یا رمز عبور اشتباه است.',
        '5' => 'پنل پیامکی غیرفعال است.',
        '6' => 'پنل پیامکی منقضی شده است.',
    ];

    private const STATUS_ERRORS = [
        '14' => 'شناسه وضعیت نامعتبر است.',
        '15' => 'نام کاربری یا رمز عبور اشتباه است.',
        '16' => 'تعداد شناسه‌ها بیشتر از حد مجاز است.',
        '17' => 'پنل پیامکی غیرفعال است.',
        '18' => 'پنل پیامکی منقضی شده است.',
    ];

    private const MAX_SEND_RECIPIENTS = 150;
    private const MAX_MULTI_ITEMS = 60;
    private const MAX_STATUS_IDS = 50;

    private LoggerInterface $logger;

    /**
     * @param  array<string, mixed>  $config  کلیدها: wsdl, username, password,
     *                                        password_md5, sender, timeout, verify_ssl
     * @param  SoapClient|null  $client  برای تست یا استفاده از کلاینت سفارشی
     */
    public function __construct(
        private readonly array $config,
        private ?SoapClient $client = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * ارسال پیامک به یک یا چند گیرنده با متن مشترک.
     *
     * @param  array<int, string|int>|string  $recipients
     * @param  int  $type  0 = معمولی، 1 = فلش
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException ورودی نامعتبر
     * @throws ConfigurationException تنظیمات ناقص
     */
    public function send(
        array|string $recipients,
        string $message,
        int $type = 0,
        ?string $sender = null
    ): array {
        $recipients = is_array($recipients)
            ? array_values($recipients)
            : [$recipients];

        if (count($recipients) < 1) {
            throw new InvalidArgumentException(
                'حداقل یک گیرنده لازم است.'
            );
        }

        if (count($recipients) > self::MAX_SEND_RECIPIENTS) {
            throw new InvalidArgumentException(
                'حداکثر تعداد گیرندگان SendSMS برابر 150 است.'
            );
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException(
                'متن پیامک نمی‌تواند خالی باشد.'
            );
        }

        foreach ($recipients as $recipient) {
            if (! is_string($recipient) && ! is_numeric($recipient)) {
                throw new InvalidArgumentException(
                    'شماره گیرنده نامعتبر است.'
                );
            }

            if (trim((string) $recipient) === '') {
                throw new InvalidArgumentException(
                    'شماره گیرنده نمی‌تواند خالی باشد.'
                );
            }
        }

        $this->validateType($type);

        return $this->request(
            'SendSMS',
            [
                $this->sender($sender),
                array_map('strval', $recipients),
                $message,
                (string) $type,
                ...$this->credentials(),
            ],
            self::SEND_ERRORS
        );
    }

    /**
     * ارسال پیامک‌های متفاوت به گیرندگان مختلف.
     *
     * تعداد اعضای هر چهار آرایه باید برابر باشد.
     *
     * @param  array<int, string>  $senders
     * @param  array<int, string|int>  $recipients
     * @param  array<int, string>  $messages
     * @param  array<int, int>  $types  هر عضو: 0 = معمولی، 1 = فلش
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException ورودی نامعتبر
     * @throws ConfigurationException تنظیمات ناقص
     */
    public function sendMulti(
        array $senders,
        array $recipients,
        array $messages,
        array $types
    ): array {
        $senders = array_values($senders);
        $recipients = array_values($recipients);
        $messages = array_values($messages);
        $types = array_values($types);

        $count = count($recipients);

        if ($count < 1) {
            throw new InvalidArgumentException(
                'حداقل یک گیرنده لازم است.'
            );
        }

        if ($count > self::MAX_MULTI_ITEMS) {
            throw new InvalidArgumentException(
                'حداکثر تعداد موارد SendMultiSMS برابر 60 است.'
            );
        }

        if (
            count($senders) !== $count ||
            count($messages) !== $count ||
            count($types) !== $count
        ) {
            throw new InvalidArgumentException(
                'تعداد اعضای آرایه‌های فرستنده، گیرنده، متن و نوع باید برابر باشد.'
            );
        }

        foreach ($senders as $value) {
            if (trim((string) $value) === '') {
                throw new InvalidArgumentException(
                    'شماره فرستنده نمی‌تواند خالی باشد.'
                );
            }
        }

        foreach ($recipients as $value) {
            if (trim((string) $value) === '') {
                throw new InvalidArgumentException(
                    'شماره گیرنده نمی‌تواند خالی باشد.'
                );
            }
        }

        foreach ($messages as $message) {
            if (trim((string) $message) === '') {
                throw new InvalidArgumentException(
                    'متن پیامک نمی‌تواند خالی باشد.'
                );
            }
        }

        foreach ($types as $type) {
            $this->validateType((int) $type);
        }

        return $this->request(
            'SendMultiSMS',
            [
                array_map('strval', $senders),
                array_map('strval', $recipients),
                array_map('strval', $messages),
                array_map(
                    static fn ($type) => (string) $type,
                    $types
                ),
                ...$this->credentials(),
            ],
            self::SEND_ERRORS
        );
    }

    /**
     * دریافت اعتبار پنل پیامکی.
     *
     * @return array<string, mixed>
     *
     * @throws ConfigurationException تنظیمات ناقص
     */
    public function getCredit(): array
    {
        return $this->request(
            'GetCredit',
            $this->credentials(),
            self::CREDIT_ERRORS,
            true
        );
    }

    /**
     * دریافت وضعیت تحویل پیامک‌ها.
     *
     * @param  array<int, string|int>|string  $uniqueIds
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException ورودی نامعتبر
     * @throws ConfigurationException تنظیمات ناقص
     */
    public function getStatus(array|string $uniqueIds): array
    {
        $uniqueIds = is_array($uniqueIds)
            ? array_values($uniqueIds)
            : [$uniqueIds];

        if (count($uniqueIds) < 1) {
            throw new InvalidArgumentException(
                'حداقل یک شناسه وضعیت لازم است.'
            );
        }

        if (count($uniqueIds) > self::MAX_STATUS_IDS) {
            throw new InvalidArgumentException(
                'حداکثر تعداد شناسه‌ها در GetStatus برابر 50 است.'
            );
        }

        foreach ($uniqueIds as $id) {
            if (trim((string) $id) === '') {
                throw new InvalidArgumentException(
                    'شناسه وضعیت نمی‌تواند خالی باشد.'
                );
            }
        }

        return $this->request(
            'GetStatus',
            [
                ...$this->credentials(),
                array_map('strval', $uniqueIds),
            ],
            self::STATUS_ERRORS
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function credentials(): array
    {
        $username = $this->config['username'] ?? null;
        $password = $this->config['password'] ?? null;

        if (! is_string($username) || trim($username) === '') {
            throw new ConfigurationException(
                'نام کاربری پنل پیامک تنظیم نشده است.'
            );
        }

        if (! is_string($password) || $password === '') {
            throw new ConfigurationException(
                'رمز عبور پنل پیامک تنظیم نشده است.'
            );
        }

        if (! empty($this->config['password_md5'])) {
            $password = md5($password);
        }

        return [$username, $password];
    }

    private function client(): SoapClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $wsdl = $this->config['wsdl'] ?? null;

        if (! is_string($wsdl) || trim($wsdl) === '') {
            throw new ConfigurationException(
                'آدرس WSDL سرویس پیامک تنظیم نشده است.'
            );
        }

        $timeout = max(1, (int) ($this->config['timeout'] ?? 20));
        $verifySsl = (bool) ($this->config['verify_ssl'] ?? true);

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
            ],
            'ssl' => [
                'verify_peer' => $verifySsl,
                'verify_peer_name' => $verifySsl,
            ],
        ]);

        return $this->client = new SoapClient($wsdl, [
            'exceptions' => true,
            'trace' => false,
            'cache_wsdl' => WSDL_CACHE_NONE,
            'connection_timeout' => $timeout,
            'stream_context' => $context,
        ]);
    }

    private function validateType(int $type): void
    {
        if (! in_array($type, [0, 1], true)) {
            throw new InvalidArgumentException(
                'نوع پیامک باید 0 معمولی یا 1 فلش باشد.'
            );
        }
    }

    private function sender(?string $sender = null): string
    {
        $sender ??= (string) ($this->config['sender'] ?? '');

        if (trim($sender) === '') {
            throw new ConfigurationException(
                'شماره اختصاصی فرستنده تنظیم نشده است.'
            );
        }

        return $sender;
    }

    /**
     * اجرای متد SOAP و استانداردسازی پاسخ.
     *
     * توجه: شکل دقیق آرگومان‌ها باید با WSDL واقعی تأیید شود.
     *
     * @param  array<int, mixed>  $arguments
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        array $arguments,
        array $errors,
        bool $credit = false
    ): array {
        try {
            $response = $this->client()->__soapCall(
                $method,
                $arguments
            );

            $data = $this->normalize($response);
            $values = $this->extractValues($data);

            $first = isset($values[0]) && is_scalar($values[0])
                ? (string) $values[0]
                : null;

            // در GetCredit، خروجی عددی مثبت یا صفر می‌تواند اعتبار باشد.
            if ($credit) {
                $amount = $this->parseCredit($data);

                if ($amount !== null) {
                    return [
                        'success' => true,
                        'method' => $method,
                        'message' => 'اعتبار پنل دریافت شد.',
                        'credit' => $amount,
                        'data' => $data,
                    ];
                }

                if (
                    $first !== null &&
                    array_key_exists($first, $errors)
                ) {
                    return $this->apiError(
                        $method,
                        $first,
                        $errors[$first],
                        $data
                    );
                }

                return [
                    'success' => false,
                    'method' => $method,
                    'message' => 'ساختار پاسخ اعتبار قابل تشخیص نیست.',
                    'data' => $data,
                ];
            }

            // خطاهای شناخته‌شده‌ای که طبق مستندات
            // به شکل خروجی تک‌عضوی برگردانده می‌شوند.
            if (
                count($values) === 1 &&
                $first !== null &&
                array_key_exists($first, $errors)
            ) {
                return $this->apiError(
                    $method,
                    $first,
                    $errors[$first],
                    $data
                );
            }

            if (
                ! is_array($data) &&
                ! is_string($data) &&
                ! is_int($data) &&
                ! is_float($data)
            ) {
                return [
                    'success' => false,
                    'method' => $method,
                    'message' => 'ساختار پاسخ سرویس قابل تشخیص نیست.',
                    'data' => $data,
                ];
            }

            return [
                'success' => true,
                'method' => $method,
                'message' => 'پاسخ از سرویس دریافت شد.',
                'data' => $data,
            ];
        } catch (ConfigurationException $e) {
            // خطای تنظیمات یک خطای برنامه‌نویسی/استقرار است و پنهان نمی‌شود.
            throw $e;
        } catch (SoapFault $e) {
            $this->logger->warning('Payammatni SMS SOAP fault', [
                'method' => $method,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'method' => $method,
                'error_code' => 'SOAP_FAULT',
                'message' => 'ارتباط SOAP با پنل پیامکی ناموفق بود.',
                'data' => null,
            ];
        } catch (Throwable $e) {
            $this->logger->error('Payammatni SMS service error', [
                'method' => $method,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'method' => $method,
                'error_code' => 'CONNECTION_ERROR',
                'message' => 'خطا در ارتباط با پنل پیامکی.',
                'data' => null,
            ];
        }
    }

    /**
     * تبدیل مقدار اعتبار به عدد.
     *
     * سرویس ممکن است اعتبار را با جداکننده‌ی هزارگان (مثل 971,333)
     * یا با ارقام فارسی/عربی برگرداند.
     */
    private function parseCredit(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 ? (float) $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.',
        ]);

        // جداکننده‌های هزارگان (لاتین، فارسی) و فاصله
        $value = str_replace([',', '٬', '،', ' '], '', $value);

        return preg_match('/^\d+(\.\d+)?$/', $value) === 1
            ? (float) $value
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function apiError(
        string $method,
        string $code,
        string $message,
        mixed $data
    ): array {
        return [
            'success' => false,
            'method' => $method,
            'error_code' => $code,
            'message' => $message,
            'data' => $data,
        ];
    }

    private function normalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = $this->normalize($item);
            }

            return $result;
        }

        return $value;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function extractValues(mixed $data): array
    {
        if (! is_array($data)) {
            return [$data];
        }

        if (array_key_exists('Return', $data)) {
            return $this->extractValues($data['Return']);
        }

        if (array_is_list($data)) {
            return $data;
        }

        if (count($data) === 1) {
            return $this->extractValues(reset($data));
        }

        return $data;
    }
}
