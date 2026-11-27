<?php

namespace Tests\Unit;

use App\Http\Middleware\CloudflareRealIp;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class CloudflareRealIpTest extends TestCase
{
    private $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $container = new Container();
        $container->instance('config', new Repository(['v2board' => []]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    private function ip(array $server): string
    {
        $request = Request::create('/', 'GET', [], [], [], $server);
        (new CloudflareRealIp())->handle($request, function ($r) {
            return $r;
        });
        return (string) $request->ip();
    }

    public function testHeaderIsIgnoredWhileTheSwitchIsOff(): void
    {
        $this->assertSame('172.64.0.1', $this->ip(['REMOTE_ADDR' => '172.64.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9']));
    }

    public function testHeaderReplacesThePeerAddressWhenEnabled(): void
    {
        config(['v2board.trust_cf_connecting_ip' => 1]);
        $this->assertSame('203.0.113.9', $this->ip(['REMOTE_ADDR' => '172.64.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9']));
    }

    public function testConfiguredHeaderNameIsUsed(): void
    {
        config(['v2board.client_ip_header' => 'Ali-Cdn-Real-Ip']);
        $this->assertSame('203.0.113.9', $this->ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_ALI_CDN_REAL_IP' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1']));
    }

    public function testFirstPresentHeaderOfSeveralWins(): void
    {
        config(['v2board.client_ip_header' => "CF-Connecting-IP, X-Real-IP\nX-Forwarded-For"]);
        $this->assertSame('203.0.113.9', $this->ip(['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_REAL_IP' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1']));
    }

    public function testForwardedListAndPortAreParsed(): void
    {
        $this->assertSame('203.0.113.9', CloudflareRealIp::parse('203.0.113.9, 10.0.0.1'));
        $this->assertSame('203.0.113.9', CloudflareRealIp::parse('203.0.113.9:51234'));
        $this->assertSame('2001:db8::1', CloudflareRealIp::parse('[2001:db8::1]:443'));
        $this->assertSame('', CloudflareRealIp::parse('unknown'));
    }

    public function testInvalidHeaderKeepsThePeerAddress(): void
    {
        config(['v2board.trust_cf_connecting_ip' => 1]);
        $this->assertSame('172.64.0.1', $this->ip(['REMOTE_ADDR' => '172.64.0.1', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip']));
    }
}
