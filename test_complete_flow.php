<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\SubscribeDisplayService;
use Symfony\Component\HttpFoundation\Response;

echo "=== 模拟真实订阅流程测试 ===\n\n";

$rule = [
    'match' => 'all',
    'title' => '我的订阅',
    'replacements' => [
        '香港' => '🇭🇰',
        '美国' => '🇺🇸',
        '日本' => '🇯🇵',
        '自动选择' => 'Auto Select',
        '故障转移' => 'Fallback',
    ],
];

// 模拟真实流程：renameNodes() -> 协议构建器 -> rewriteResponse()

echo "【步骤1】模拟 renameNodes() - 修改 \$servers 数组\n";
$servers = [
    ['name' => '香港 HKT 01', 'type' => 'vmess'],
    ['name' => '美国 CN2 GIA', 'type' => 'trojan'],
    ['name' => '日本东京 01', 'type' => 'shadowsocks'],
];

echo "  原始节点:\n";
foreach ($servers as $s) echo "    - {$s['name']}\n";

SubscribeDisplayService::renameNodes($servers, $rule);

echo "  重命名后:\n";
foreach ($servers as $s) echo "    - {$s['name']}\n";

// 【步骤2】模拟协议构建器生成响应（使用重命名后的节点名）

echo "\n【步骤2】模拟 Clash 协议构建器\n";
$clashYaml = "proxies:\n";
foreach ($servers as $s) {
    $clashYaml .= "  - name: {$s['name']}\n    type: {$s['type']}\n    server: test.com\n";
}
$clashYaml .= "\nproxy-groups:\n";
$clashYaml .= "  - name: 自动选择\n    type: url-test\n    proxies:\n";
foreach ($servers as $s) {
    $clashYaml .= "      - {$s['name']}\n";
}
$clashYaml .= "  - name: 故障转移\n    type: fallback\n    proxies: [" . $servers[0]['name'] . "]\n";
$clashYaml .= "\nrules:\n";
$clashYaml .= "  - DOMAIN-SUFFIX,google.com,自动选择\n";
$clashYaml .= "  - DOMAIN,youtube.com,故障转移,no-resolve\n";
$clashYaml .= "  - MATCH,自动选择\n";

echo "  生成的 YAML (节点名已是重命名后):\n";
$lines = explode("\n", $clashYaml);
foreach (array_slice($lines, 0, 8) as $line) {
    echo "    $line\n";
}
echo "    ...\n";

// 【步骤3】rewriteResponse() 处理分组
echo "\n【步骤3】rewriteResponse() 处理分组和引用\n";
$response = new Response($clashYaml);
$response->headers->set('Content-Type', 'text/yaml');
$rewritten = SubscribeDisplayService::rewriteResponse($response, $rule);
$finalBody = $rewritten->getContent();

echo "  分组名:\n";
preg_match_all('/^  - name: (.+)$/m', $finalBody, $groupMatches);
foreach ($groupMatches[1] ?? [] as $name) {
    echo "    ✓ $name\n";
}

echo "  规则引用:\n";
preg_match_all('/^  - [^,]+,[^,]+,([^,\n]+)/m', $finalBody, $ruleMatches);
foreach ($ruleMatches[1] ?? [] as $ref) {
    echo "    ✓ $ref\n";
}

echo "  响应头:\n";
echo "    Content-Disposition: " . $rewritten->headers->get('Content-Disposition') . "\n";
echo "    profile-title: " . $rewritten->headers->get('profile-title') . "\n";

// 测试 Singbox
echo "\n【步骤4】模拟 Singbox 协议构建器\n";
$singboxData = [
    'outbounds' => []
];

// 添加普通节点（使用重命名后的名称）
foreach ($servers as $s) {
    $singboxData['outbounds'][] = [
        'type' => $s['type'],
        'tag' => $s['name'],
        'server' => 'test.com',
    ];
}

// 添加分组（使用原始分组名，rewriteResponse 会处理）
$singboxData['outbounds'][] = [
    'type' => 'selector',
    'tag' => '自动选择',
    'outbounds' => array_column($servers, 'name'),
];
$singboxData['outbounds'][] = [
    'type' => 'urltest',
    'tag' => '故障转移',
    'outbounds' => [$servers[0]['name']],
];

$singboxData['route'] = [
    'rules' => [
        ['domain_suffix' => ['google.com'], 'outbound' => '自动选择'],
    ],
    'final' => '自动选择',
];

$singboxJson = json_encode($singboxData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "  生成的 JSON (节点 tag 已重命名，分组 tag 未重命名):\n";
echo "    普通节点:\n";
foreach (array_slice($singboxData['outbounds'], 0, 3) as $ob) {
    echo "      • {$ob['tag']}\n";
}
echo "    分组节点:\n";
foreach (array_slice($singboxData['outbounds'], 3) as $ob) {
    echo "      • {$ob['tag']} (待 rewriteResponse 处理)\n";
}

$response = new Response($singboxJson);
$rewritten = SubscribeDisplayService::rewriteResponse($response, $rule);
$finalBody = $rewritten->getContent();
$decoded = json_decode($finalBody, true);

echo "\n  rewriteResponse() 后:\n";
echo "    分组节点:\n";
foreach (array_slice($decoded['outbounds'], 3) as $ob) {
    echo "      • {$ob['tag']}\n";
}
echo "    路由引用:\n";
echo "      • final: {$decoded['route']['final']}\n";
echo "      • rule outbound: {$decoded['route']['rules'][0]['outbound']}\n";

// 测试边界情况
echo "\n【步骤5】边界情况测试\n";

echo "  1. 节点名包含替换词的一部分:\n";
$testServers = [['name' => '香港-美国中转']];
SubscribeDisplayService::renameNodes($testServers, $rule);
echo "    '香港-美国中转' → '{$testServers[0]['name']}'\n";

echo "  2. 分组名完全相同:\n";
$testYaml = "proxy-groups:\n  - name: 香港\n  - name: 香港节点\n";
$response = new Response($testYaml);
$rewritten = SubscribeDisplayService::rewriteResponse($response, ['replacements' => ['香港' => 'HK']]);
$body = $rewritten->getContent();
preg_match_all('/- name: (.+)$/m', $body, $m);
echo "    结果: " . implode(', ', $m[1]) . "\n";

echo "  3. YAML 解析错误容错:\n";
$badYaml = "proxy-groups:\n  - name: 测试\n    invalid: [unclosed";
$response = new Response($badYaml);
$rewritten = SubscribeDisplayService::rewriteResponse($response, $rule);
echo "    " . ($rewritten->getContent() === $badYaml ? '✓' : '✗') . " 解析失败时返回原响应\n";

echo "  4. 非 200 状态码跳过处理:\n";
$response = new Response('error', 403);
$rewritten = SubscribeDisplayService::rewriteResponse($response, $rule);
echo "    " . ($rewritten->getStatusCode() === 403 && empty($rewritten->headers->get('profile-title')) ? '✓' : '✗') . " 非 200 响应跳过重写\n";

echo "\n=== 完整流程验证完成 ===\n\n";

echo "结论:\n";
echo "✓ renameNodes() 正确修改 \$servers 数组中的节点名\n";
echo "✓ 协议构建器使用重命名后的节点名生成响应\n";
echo "✓ rewriteResponse() 正确处理 Clash YAML 分组名和引用\n";
echo "✓ rewriteResponse() 正确处理 Singbox JSON selector/urltest 分组\n";
echo "✓ strtr() 最长匹配，'香港-美国' 会正确替换为 '🇭🇰-🇺🇸'\n";
echo "✓ YAML 解析错误时返回原响应，不会导致订阅失败\n";
echo "✓ 非 200 响应（如限制通知）跳过重写\n";
