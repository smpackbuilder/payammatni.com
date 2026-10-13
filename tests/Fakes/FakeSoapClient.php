<?php

declare(strict_types=1);

namespace Payammatni\Sms\Tests\Fakes;

use SoapClient;
use Throwable;

/**
 * کلاینت SOAP جعلی: سازنده‌ی SoapClient عمداً صدا زده نمی‌شود
 * تا نیازی به WSDL یا اینترنت نباشد.
 */
class FakeSoapClient extends SoapClient
{
    /** @var array<int, array{0: string, 1: array}> */
    public array $calls = [];

    public function __construct(private readonly mixed $response = null)
    {
        // parent::__construct() عمداً فراخوانی نمی‌شود.
    }

    public function __soapCall(
        string $name,
        array $args,
        ?array $options = null,
        $inputHeaders = null,
        &$outputHeaders = null
    ): mixed {
        $this->calls[] = [$name, $args];

        if ($this->response instanceof Throwable) {
            throw $this->response;
        }

        return $this->response;
    }
}
