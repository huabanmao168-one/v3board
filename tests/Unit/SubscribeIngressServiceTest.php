<?php

namespace Tests\Unit;

use App\Services\SubscribeIngressService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscribeIngressServiceTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('config', new Repository(['v2board' => [
            'subscribe_ingress_proxies' => '127.0.0.1',
            'subscribe_ingress_block_oversea' => 1,
        ]]));
        $container->instance('log', new class {
            public function notice($message, $context): void
            {
            }
        });
        // GeoIpService resolves the XDB location through storage_path(); no database here.
        $container->instance('path.storage', sys_get_temp_dir() . '/v3board-test-storage');
        Container::setInstance($container);
        Facade::clearResolvedInstance('log');
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('log');
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testDirectRequestCannotSpoofCountry(): void
    {
        $this->assertDenied($this->request('192.0.2.100', 'CN'), 404);
    }

    public function testUnknownCountryIsRejected(): void
    {
        $this->assertDenied($this->request('127.0.0.1', ''), 403);
    }

    public function testTrustedProxyPassesTheIpv6AddressToSubscriptionLogging(): void
    {
        $request = $this->request('127.0.0.1', 'CN');
        SubscribeIngressService::enforce($request);
        $this->assertSame('2001:db8::4', $request->attributes->get('subscription_client_ip'));
    }

    public function testAddressExceptionCanPermitAnOverseasRequest(): void
    {
        config(['v2board.subscribe_ingress_allow_ips' => '2001:db8::/32']);
        $request = $this->request('127.0.0.1', 'JP');
        SubscribeIngressService::enforce($request);
        $this->assertSame('2001:db8::4', $request->attributes->get('subscription_client_ip'));
    }

    private function request(string $peer, string $country): Request
    {
        return Request::create('/subscribe', 'GET', [], [], [], [
            'REMOTE_ADDR' => $peer,
            'HTTP_X_SUBSCRIPTION_CLIENT_IP' => '2001:db8::4',
            'HTTP_X_SUBSCRIPTION_COUNTRY' => $country,
        ]);
    }

    private function assertDenied(Request $request, int $status): void
    {
        try {
            SubscribeIngressService::enforce($request);
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
            return;
        }
        $this->fail('Expected the subscription request to be blocked');
    }
}
