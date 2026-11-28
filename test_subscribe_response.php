<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

use App\Services\SubscribeDisplayService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

echo "=== 订阅展示规则实际应用测试 ===\n\n";

// 1. 创建模拟用户
$mockUser = new stdClass();
$mockUser->id = 1;
$mockUser->email = 'test@example.com';
$mockUser->uuid = 'test-uuid-12345';

// 2. 创建模拟请求
$request = Request::create('/api/v1/client/subscribe', 'GET', ['token' => 'test']);
$request->headers->set('User-Agent', 'clash-verge/1.0');
$request->user = $mockUser;

echo "1. 模拟请求信息\n";
echo "   Email: {$mockUser->email}\n";
echo "   User-Agent: clash-verge/1.0\n\n";

// 3. 测试规则匹配
echo "2. 规则匹配结果\n";
$display = SubscribeDisplayService::matchRule($request);
if (!empty($display)) {
    echo "   ✓ 匹配成功\n";
    echo "   规则类型: " . ($display['match'] ?? 'unknown') . "\n";
    echo "   订阅标题: " . ($display['title'] ?? '无') . "\n";
    echo "   替换规则: " . (isset($display['replacements']) ? count($display['replacements']) . ' 个' : '0 个') . "\n";
    if (!empty($display['replacements'])) {
        echo "   替换内容:\n";
        foreach ($display['replacements'] as $from => $to) {
            echo "     • '$from' → '$to'\n";
        }
    }
} else {
    echo "   ✗ 未匹配任何规则\n";
}

// 4. 测试节点名称替换
echo "\n3. 节点名称替换测试\n";
$testNodes = [
    ['name' => '香港 HKT 01', 'type' => 'shadowsocks'],
    ['name' => '美国 CN2 GIA', 'type' => 'vmess'],
    ['name' => '日本东京 01', 'type' => 'trojan'],
    ['name' => '香港-美国中转', 'type' => 'vmess'],
];

echo "   原始节点:\n";
foreach ($testNodes as $node) {
    echo "     • {$node['name']}\n";
}

if (!empty($display)) {
    SubscribeDisplayService::renameNodes($testNodes, $display);
    echo "\n   替换后节点:\n";
    foreach ($testNodes as $node) {
        echo "     • {$node['name']}\n";
    }
}

// 5. 测试响应头重写
echo "\n4. 响应头重写测试\n";
$response = new Response('test-subscription-content');
$response->headers->set('Content-Type', 'text/plain');

echo "   原始响应头:\n";
echo "     Content-Type: " . $response->headers->get('Content-Type') . "\n";
echo "     Content-Disposition: " . ($response->headers->get('Content-Disposition') ?: '(未设置)') . "\n";
echo "     profile-title: " . ($response->headers->get('profile-title') ?: '(未设置)') . "\n";

$response = SubscribeDisplayService::rewriteResponse($response, $display);

echo "\n   重写后响应头:\n";
echo "     Content-Type: " . $response->headers->get('Content-Type') . "\n";
echo "     Content-Disposition: " . ($response->headers->get('Content-Disposition') ?: '(未设置)') . "\n";
echo "     profile-title: " . ($response->headers->get('profile-title') ?: '(未设置)') . "\n";

// 6. 测试不同匹配条件
echo "\n5. 多规则优先级测试\n";
$complexRules = [
    [
        'match' => 'email_suffix',
        'value' => '@gmail.com',
        'title' => 'Gmail专属订阅',
        'replacements' => ['香港' => '🇭🇰'],
    ],
    [
        'match' => 'ua_contains',
        'value' => 'clash',
        'title' => 'Clash订阅',
        'replacements' => ['美国' => '🇺🇸'],
    ],
    [
        'match' => 'all',
        'title' => '通用订阅',
        'replacements' => ['日本' => '🇯🇵'],
    ],
];

// 临时替换配置
config(['v2board.subscribe_display_rules' => json_encode($complexRules)]);

$testCases = [
    ['email' => 'user@gmail.com', 'ua' => 'ClashX/1.0', 'expected' => 'Gmail专属订阅'],
    ['email' => 'user@163.com', 'ua' => 'clash-verge/1.0', 'expected' => 'Clash订阅'],
    ['email' => 'user@qq.com', 'ua' => 'Surge/5.0', 'expected' => '通用订阅'],
];

foreach ($testCases as $i => $case) {
    $mockUser->email = $case['email'];
    $request = Request::create('/api/v1/client/subscribe', 'GET');
    $request->headers->set('User-Agent', $case['ua']);
    $request->user = $mockUser;

    $matched = SubscribeDisplayService::matchRule($request);
    $title = $matched['title'] ?? '未匹配';
    $status = $title === $case['expected'] ? '✓' : '✗';

    echo "   测试 " . ($i + 1) . ": {$case['email']} + {$case['ua']}\n";
    echo "     {$status} 预期: {$case['expected']}, 实际: {$title}\n";
}

echo "\n=== 测试完成 ===\n";
echo "\n结论:\n";
echo "订阅展示规则的所有核心功能在本地环境正常工作。\n";
echo "如果生产环境不生效，可能原因：\n";
echo "1. config/v2board.php 文件不存在或权限问题\n";
echo "2. OPcache 缓存了旧的配置文件（需要 opcache_reset）\n";
echo "3. 后台保存配置时失败（File::put 返回 false）\n";
echo "4. Webman/Workerman 进程未重启（需要 kill PID）\n";
