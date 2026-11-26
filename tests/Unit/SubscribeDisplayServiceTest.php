<?php

namespace Tests\Unit;

use App\Services\SubscribeDisplayService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class SubscribeDisplayServiceTest extends TestCase
{
    public function testRenamesGroupsAndReferencesWithoutChangingConnections(): void
    {
        $response = new Response('{
            "outbounds": [{"type":"selector","tag":"Old Group","outbounds":["Node"]},
                          {"type":"trojan","tag":"Node","server":"Old.example.com","tls":{}}],
            "route":{"final":"Old Group"}
        }');
        $result = SubscribeDisplayService::rewriteResponse($response, [
            'replacements' => ['Old' => 'New'],
            'title' => 'New',
        ]);

        $config = json_decode($result->getContent());
        $this->assertSame('New Group', $config->outbounds[0]->tag);
        $this->assertSame('New Group', $config->route->final);
        $this->assertSame('Old.example.com', $config->outbounds[1]->server);
        $this->assertInstanceOf(\stdClass::class, $config->outbounds[1]->tls);
        $this->assertSame('base64:TmV3', $result->headers->get('profile-title'));
    }
}
