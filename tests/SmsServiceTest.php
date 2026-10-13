<?php

declare(strict_types=1);

namespace Payammatni\Sms\Tests;

use Payammatni\Sms\Exceptions\ConfigurationException;
use Payammatni\Sms\SmsService;
use Payammatni\Sms\Tests\Fakes\FakeSoapClient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SoapFault;

class SmsServiceTest extends TestCase
{
    private function config(array $override = []): array
    {
        return array_merge([
            'wsdl' => 'https://example.test/sms?wsdl',
            'username' => 'user',
            'password' => 'secret',
            'password_md5' => false,
            'sender' => '30001234',
            'timeout' => 5,
            'verify_ssl' => true,
        ], $override);
    }

    private function service(mixed $response, array $config = []): array
    {
        $client = new FakeSoapClient($response);

        return [new SmsService($this->config($config), $client), $client];
    }

    // ---------------------------------------------------------------- send

    public function test_send_success_passes_arguments_in_expected_order(): void
    {
        [$sms, $client] = $this->service((object) ['Return' => ['9988776655']]);

        $result = $sms->send('09120000000', 'سلام');

        $this->assertTrue($result['success']);
        $this->assertSame('SendSMS', $result['method']);
        $this->assertSame(
            ['SendSMS', ['30001234', ['09120000000'], 'سلام', '0', 'user', 'secret']],
            $client->calls[0]
        );
    }

    public function test_send_accepts_multiple_recipients_custom_sender_and_flash_type(): void
    {
        [$sms, $client] = $this->service('123456789');

        $result = $sms->send(['09120000000', 9121111111], 'hi', 1, '5000');

        $this->assertTrue($result['success']);
        $this->assertSame(
            ['5000', ['09120000000', '9121111111'], 'hi', '1', 'user', 'secret'],
            $client->calls[0][1]
        );
    }

    public function test_send_numeric_message_id_is_treated_as_success(): void
    {
        [$sms] = $this->service(987654321);

        $this->assertTrue($sms->send('09120000000', 'hi')['success']);
    }

    public function test_send_maps_known_api_error_code(): void
    {
        [$sms] = $this->service((object) ['Return' => ['1']]);

        $result = $sms->send('09120000000', 'hi');

        $this->assertFalse($result['success']);
        $this->assertSame('1', $result['error_code']);
        $this->assertSame('اعتبار پنل برای ارسال کافی نیست.', $result['message']);
    }

    public function test_password_is_md5_hashed_when_enabled(): void
    {
        [$sms, $client] = $this->service('1234567', ['password_md5' => true]);

        $sms->send('09120000000', 'hi');

        $this->assertSame(md5('secret'), $client->calls[0][1][5]);
    }

    public function test_send_rejects_empty_message(): void
    {
        [$sms] = $this->service('x');

        $this->expectException(InvalidArgumentException::class);
        $sms->send('09120000000', '   ');
    }

    public function test_send_rejects_empty_recipient_list(): void
    {
        [$sms] = $this->service('x');

        $this->expectException(InvalidArgumentException::class);
        $sms->send([], 'hi');
    }

    public function test_send_rejects_more_than_150_recipients(): void
    {
        [$sms] = $this->service('x');

        $this->expectException(InvalidArgumentException::class);
        $sms->send(array_fill(0, 151, '09120000000'), 'hi');
    }

    public function test_send_rejects_invalid_type(): void
    {
        [$sms] = $this->service('x');

        $this->expectException(InvalidArgumentException::class);
        $sms->send('09120000000', 'hi', 2);
    }

    // ----------------------------------------------------------- sendMulti

    public function test_send_multi_success(): void
    {
        [$sms, $client] = $this->service((object) ['Return' => ['111', '222']]);

        $result = $sms->sendMulti(
            ['3000', '3000'],
            ['0912', '0913'],
            ['a', 'b'],
            [0, 1]
        );

        $this->assertTrue($result['success']);
        $this->assertSame('SendMultiSMS', $client->calls[0][0]);
        $this->assertSame(
            [['3000', '3000'], ['0912', '0913'], ['a', 'b'], ['0', '1'], 'user', 'secret'],
            $client->calls[0][1]
        );
    }

    public function test_send_multi_requires_equal_array_lengths(): void
    {
        [$sms] = $this->service('x');

        $this->expectException(InvalidArgumentException::class);
        $sms->sendMulti(['3000'], ['0912', '0913'], ['a', 'b'], [0, 0]);
    }

    // ----------------------------------------------------------- getCredit

    public function test_get_credit_returns_numeric_credit(): void
    {
        [$sms] = $this->service('1500.5');

        $result = $sms->getCredit();

        $this->assertTrue($result['success']);
        $this->assertSame(1500.5, $result['credit']);
    }

    public function test_get_credit_parses_thousands_separator(): void
    {
        [$sms] = $this->service('971,333');

        $result = $sms->getCredit();

        $this->assertTrue($result['success']);
        $this->assertSame(971333.0, $result['credit']);
    }

    public function test_get_credit_parses_persian_digits_and_separators(): void
    {
        [$sms] = $this->service('۱,۲۳۴,۵۶۷');

        $result = $sms->getCredit();

        $this->assertTrue($result['success']);
        $this->assertSame(1234567.0, $result['credit']);
    }

    public function test_get_credit_maps_wrapped_error_code(): void
    {
        [$sms] = $this->service((object) ['Return' => '0']);

        $result = $sms->getCredit();

        $this->assertFalse($result['success']);
        $this->assertSame('0', $result['error_code']);
    }

    public function test_get_credit_unrecognised_structure_is_failure(): void
    {
        [$sms] = $this->service('not-a-number');

        $this->assertFalse($sms->getCredit()['success']);
    }

    // ------------------------------------------------------------ getStatus

    public function test_get_status_success(): void
    {
        [$sms, $client] = $this->service((object) ['Return' => ['1', '2']]);

        $result = $sms->getStatus(['111', '222']);

        $this->assertTrue($result['success']);
        $this->assertSame(['user', 'secret', ['111', '222']], $client->calls[0][1]);
    }

    public function test_get_status_maps_error_code(): void
    {
        [$sms] = $this->service((object) ['Return' => ['14']]);

        $result = $sms->getStatus('111');

        $this->assertFalse($result['success']);
        $this->assertSame('14', $result['error_code']);
    }

    public function test_get_status_rejects_more_than_50_ids(): void
    {
        [$sms] = $this->service('x');

        $this->expectException(InvalidArgumentException::class);
        $sms->getStatus(array_fill(0, 51, '1'));
    }

    // ---------------------------------------------------------- config / io

    public function test_missing_username_throws_configuration_exception(): void
    {
        [$sms] = $this->service('x', ['username' => '']);

        $this->expectException(ConfigurationException::class);
        $sms->send('09120000000', 'hi');
    }

    public function test_missing_password_throws_configuration_exception(): void
    {
        [$sms] = $this->service('x', ['password' => null]);

        $this->expectException(ConfigurationException::class);
        $sms->getCredit();
    }

    public function test_missing_sender_throws_configuration_exception(): void
    {
        [$sms] = $this->service('x', ['sender' => '']);

        $this->expectException(ConfigurationException::class);
        $sms->send('09120000000', 'hi');
    }

    public function test_missing_wsdl_throws_configuration_exception(): void
    {
        $sms = new SmsService($this->config(['wsdl' => null]));

        $this->expectException(ConfigurationException::class);
        $sms->getCredit();
    }

    public function test_soap_fault_is_returned_as_failure_array(): void
    {
        [$sms] = $this->service(new SoapFault('Server', 'boom'));

        $result = $sms->send('09120000000', 'hi');

        $this->assertFalse($result['success']);
        $this->assertSame('SOAP_FAULT', $result['error_code']);
    }

    public function test_unexpected_exception_is_returned_as_connection_error(): void
    {
        [$sms] = $this->service(new RuntimeException('network down'));

        $result = $sms->getCredit();

        $this->assertFalse($result['success']);
        $this->assertSame('CONNECTION_ERROR', $result['error_code']);
    }

    public function test_configuration_exception_is_a_invalid_argument_exception(): void
    {
        $this->assertInstanceOf(
            InvalidArgumentException::class,
            new ConfigurationException()
        );
    }
}
