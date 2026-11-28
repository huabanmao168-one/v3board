<?php

namespace Tests\Unit;

use App\Services\SubscribeFilterService;
use Tests\TestCase;

class SubscribeFilterServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testNodeWhitelistFiltersCorrectly()
    {
        config(['v2board.subscribe_node_whitelist_rules' => [
            [
                'ua' => 'clash',
                'nodes' => [
                    ['type' => 'shadowsocks', 'id' => 1],
                    ['type' => 'vmess', 'id' => 5],
                ]
            ],
            [
                'ua' => 'sing-box',
                'nodes' => [
                    ['type' => 'shadowsocks', 'id' => 3],
                ]
            ],
        ]]);

        $servers = [
            ['type' => 'shadowsocks', 'id' => 1, 'name' => 'SS-1 (clash only)'],
            ['type' => 'shadowsocks', 'id' => 2, 'name' => 'SS-2 (unrestricted)'],
            ['type' => 'shadowsocks', 'id' => 3, 'name' => 'SS-3 (sing-box only)'],
            ['type' => 'vmess', 'id' => 5, 'name' => 'VMess-5 (clash only)'],
            ['type' => 'vmess', 'id' => 4, 'name' => 'VMess-4 (unrestricted)'],
        ];

        // Test with Clash UA
        $filtered = $servers;
        SubscribeFilterService::applyNodeWhitelistRules($filtered, 'clash-verge/1.0');
        $this->assertCount(4, $filtered);
        $names = array_column($filtered, 'name');
        $this->assertContains('SS-1 (clash only)', $names);
        $this->assertContains('SS-2 (unrestricted)', $names);
        $this->assertNotContains('SS-3 (sing-box only)', $names);
        $this->assertContains('VMess-5 (clash only)', $names);
        $this->assertContains('VMess-4 (unrestricted)', $names);

        // Test with sing-box UA
        $filtered = $servers;
        SubscribeFilterService::applyNodeWhitelistRules($filtered, 'sing-box/1.8.0');
        $this->assertCount(3, $filtered);
        $names = array_column($filtered, 'name');
        $this->assertNotContains('SS-1 (clash only)', $names);
        $this->assertContains('SS-2 (unrestricted)', $names);
        $this->assertContains('SS-3 (sing-box only)', $names);
        $this->assertNotContains('VMess-5 (clash only)', $names);
        $this->assertContains('VMess-4 (unrestricted)', $names);

        // Test with unknown UA
        $filtered = $servers;
        SubscribeFilterService::applyNodeWhitelistRules($filtered, 'unknown-client/1.0');
        $this->assertCount(2, $filtered);
        $names = array_column($filtered, 'name');
        $this->assertNotContains('SS-1 (clash only)', $names);
        $this->assertContains('SS-2 (unrestricted)', $names);
        $this->assertNotContains('SS-3 (sing-box only)', $names);
        $this->assertNotContains('VMess-5 (clash only)', $names);
        $this->assertContains('VMess-4 (unrestricted)', $names);

        // Test with empty UA
        $filtered = $servers;
        SubscribeFilterService::applyNodeWhitelistRules($filtered, '');
        $this->assertCount(2, $filtered);
        $names = array_column($filtered, 'name');
        $this->assertContains('SS-2 (unrestricted)', $names);
        $this->assertContains('VMess-4 (unrestricted)', $names);
    }

    public function testNodeWithoutIdIsUnrestricted()
    {
        config(['v2board.subscribe_node_whitelist_rules' => [
            [
                'ua' => 'clash',
                'nodes' => [
                    ['type' => 'shadowsocks', 'id' => 1],
                ]
            ],
        ]]);

        $servers = [
            ['type' => 'shadowsocks', 'id' => 1, 'name' => 'SS-1 (restricted)'],
            ['type' => 'shadowsocks', 'name' => 'SS-no-id (missing id field)'],
            ['type' => 'vmess', 'id' => 2, 'name' => 'VMess-2 (unrestricted)'],
        ];

        // Security fix: nodes without ID should be treated as unrestricted (default behavior)
        // This prevents construction of invalid keys like "shadowsocks:" that would match nothing
        $filtered = $servers;
        SubscribeFilterService::applyNodeWhitelistRules($filtered, 'unknown-client');
        $this->assertCount(2, $filtered);

        $names = array_column($filtered, 'name');
        $this->assertNotContains('SS-1 (restricted)', $names);
        $this->assertContains('SS-no-id (missing id field)', $names); // should be delivered
        $this->assertContains('VMess-2 (unrestricted)', $names);
    }

    public function testEmptyRulesDoesNotFilterAnything()
    {
        config(['v2board.subscribe_node_whitelist_rules' => []]);

        $servers = [
            ['type' => 'shadowsocks', 'id' => 1, 'name' => 'SS-1'],
            ['type' => 'vmess', 'id' => 2, 'name' => 'VMess-2'],
        ];

        $filtered = $servers;
        SubscribeFilterService::applyNodeWhitelistRules($filtered, 'any-ua');
        $this->assertCount(2, $filtered);
    }

    public function testDomainRewriteRulesWorkCorrectly()
    {
        config(['v2board.subscribe_domain_rewrite_rules' => [
            [
                'ua' => 'clash',
                'domain' => 'node1.example.com',
                'ip' => '1.2.3.4',
            ],
            [
                'ua' => 'sing-box',
                'domain' => 'node2.example.com',
                'ip' => '5.6.7.8',
            ],
        ]]);

        $servers = [
            ['type' => 'shadowsocks', 'id' => 1, 'host' => 'node1.example.com'],
            ['type' => 'vmess', 'id' => 2, 'server' => 'node2.example.com'],
            ['type' => 'trojan', 'id' => 3, 'address' => 'node3.example.com'],
        ];

        // Test with Clash UA
        $filtered = $servers;
        SubscribeFilterService::applyDomainRewriteRules($filtered, 'clash-verge');
        $this->assertEquals('1.2.3.4', $filtered[0]['host']);
        $this->assertEquals('node2.example.com', $filtered[1]['server']); // not rewritten
        $this->assertEquals('node3.example.com', $filtered[2]['address']); // not rewritten

        // Test with sing-box UA
        $filtered = $servers;
        SubscribeFilterService::applyDomainRewriteRules($filtered, 'sing-box');
        $this->assertEquals('node1.example.com', $filtered[0]['host']); // not rewritten
        $this->assertEquals('5.6.7.8', $filtered[1]['server']);
        $this->assertEquals('node3.example.com', $filtered[2]['address']); // not rewritten
    }
}
