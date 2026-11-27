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

        // Use Application instead of bare Container for storage_path() support in Laravel 13
        $app = new \Illuminate\Foundation\Application(dirname(__DIR__, 2));
        $app->useStoragePath(sys_get_temp_dir() . '/v3board-test-storage');
        $app->instance('config', new Repository(['v2board' => [
            'subscribe_ingress_proxies' => '127.0.0.1',
            'subscribe_ingress_block_oversea' => 1,
        ]]));
        $app->instance('log', new class {
            public function notice($message, $context): void
            {
            }
        });
        Container::setInstance($app);
        Facade::clearResolvedInstance('log');
        Facade::setFacadeApplication($app);
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

    public function testHeaderTrustAcceptsTheRelayHeaderFromAnyPeer(): void
    {
        config(['v2board.subscribe_ingress_trust_header' => 1]);
        $request = $this->request('192.0.2.100', 'CN');
        SubscribeIngressService::enforce($request);
        $this->assertSame('2001:db8::4', $request->attributes->get('subscription_client_ip'));
    }

    public function testHeaderTrustFallsBackToTheResolvedAddressWithoutAHeader(): void
    {
        config(['v2board.subscribe_ingress_trust_header' => 1, 'v2board.subscribe_ingress_block_oversea' => 0]);
        $request = Request::create('/subscribe', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.100']);
        SubscribeIngressService::enforce($request);
        $this->assertSame('192.0.2.100', $request->attributes->get('subscription_client_ip'));
    }

    public function testHeaderTrustReadsTheSubPanelHeaderName(): void
    {
        config(['v2board.subscribe_ingress_trust_header' => 1, 'v2board.subscribe_ingress_block_oversea' => 0]);
        $request = Request::create('/subscribe', 'GET', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.100',
            'HTTP_X_SUB_PANEL_CLIENT_IP' => '198.51.100.7',
        ]);
        SubscribeIngressService::enforce($request);
        $this->assertSame('198.51.100.7', $request->attributes->get('subscription_client_ip'));
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
