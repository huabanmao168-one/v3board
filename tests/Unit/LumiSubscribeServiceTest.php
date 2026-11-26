<?php

namespace Tests\Unit;

use App\Services\LumiSubscribeService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class LumiSubscribeServiceTest extends TestCase
{
    public function testEncryptsTheSubscriptionUsingTheLumiTokenFormat(): void
    {
        $response = new Response('proxies: []', 200, [
            'ETag' => 'original',
            'subscription-userinfo' => 'upload=0; download=0',
        ]);

        $encrypted = LumiSubscribeService::encryptResponse($response, '01234568-unused-abcdefgh');

        $this->assertSame('kpmOO4IVVKRLVXEk188s0Q==', $encrypted->getContent());
        $this->assertSame('24', $encrypted->headers->get('Content-Length'));
        $this->assertSame('upload=0; download=0', $encrypted->headers->get('subscription-userinfo'));
        $this->assertFalse($encrypted->headers->has('ETag'));
    }

    public function testLeavesAnErrorResponseIntact(): void
    {
        $response = new Response('Forbidden', 403);

        $this->assertSame($response, LumiSubscribeService::encryptResponse($response, ''));
        $this->assertSame('Forbidden', $response->getContent());
    }
}
