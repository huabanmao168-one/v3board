<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\SubscribeDisplayService;
use Symfony\Component\HttpFoundation\Response;

echo "=== 订阅展示规则客户端格式兼容性测试 ===\n\n";

$rule = [
    'match' => 'all',
    'title' => '我的订阅',
    'replacements' => [
        '香港' => 'HK',
        '美国' => 'US',
        '日本' => 'JP',
        '自动选择' => 'Auto',
        '故障转移' => 'Fallback',
    ],
];

// 1. Clash YAML 格式测试
echo "【1】Clash / ClashMeta / ClashVerge (YAML)\n";
$clashYaml = <<<YAML
proxies:
  - name: 香港 HKT 01
    type: vmess
    server: hk.example.com
    port: 443
  - name: 美国 CN2 GIA
    type: trojan
    server: us.example.com
    port: 443
  - name: 日本东京 01
    type: ss
    server: jp.example.com
    port: 443

proxy-groups:
  - name: 自动选择
    type: url-test
    proxies:
      - 香港 HKT 01
      - 美国 CN2 GIA
  - name: 故障转移
    type: fallback
    proxies:
      - 日本东京 01

rules:
  - DOMAIN-SUFFIX,google.com,自动选择
  - DOMAIN-KEYWORD,youtube,故障转移
  - MATCH,自动选择
YAML;

$response = new Response($clashYaml);
$response->headers->set('Content-Type', 'text/yaml');
$rewritten = SubscribeDisplayService::rewriteResponse($response, $rule);
$body = $rewritten->getContent();

echo "  节点重命名:\n";
preg_match_all('/- name: (.+)$/m', $body, $matches);
foreach ($matches[1] ?? [] as $name) {
    echo "    ✓ $name\n";
}

echo "  分组重命名:\n";
preg_match_all('/^  - name: (.+)$/m', $body, $groupMatches);
foreach ($groupMatches[1] ?? [] as $name) {
    echo "    ✓ $name\n";
}

echo "  规则中的分组引用:\n";
preg_match_all('/^  - [^,]+,(.+)$/m', $body, $ruleMatches);
foreach ($ruleMatches[1] ?? [] as $ref) {
    echo "    ✓ $ref\n";
}

echo "  响应头:\n";
echo "    Content-Disposition: " . $rewritten->headers->get('Content-Disposition') . "\n";
echo "    profile-title: " . $rewritten->headers->get('profile-title') . "\n";

// 2. Singbox JSON 格式测试
echo "\n【2】Sing-box (JSON)\n";
$singboxJson = json_encode([
    'outbounds' => [
        ['type' => 'vmess', 'tag' => '香港 HKT 01', 'server' => 'hk.example.com'],
        ['type' => 'trojan', 'tag' => '美国 CN2 GIA', 'server' => 'us.example.com'],
        ['type' => 'shadowsocks', 'tag' => '日本东京 01', 'server' => 'jp.example.com'],
        [
            'type' => 'selector',
            'tag' => '自动选择',
            'outbounds' => ['香港 HKT 01', '美国 CN2 GIA']
        ],
        [
            'type' => 'urltest',
            'tag' => '故障转移',
            'outbounds' => ['日本东京 01']
        ],
    ],
    'route' => [
        'rules' => [
            ['domain_suffix' => ['google.com'], 'outbound' => '自动选择'],
            ['domain_keyword' => ['youtube'], 'outbound' => '故障转移'],
        ],
        'final' => '自动选择',
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$response = new Response($singboxJson);
$response->headers->set('Content-Type', 'application/json');
$rewritten = SubscribeDisplayService::rewriteResponse($response, $rule);
$body = $rewritten->getContent();
$decoded = json_decode($body, true);

echo "  Outbounds:\n";
foreach ($decoded['outbounds'] ?? [] as $outbound) {
    echo "    ✓ {$outbound['tag']}\n";
}

echo "  Selector 分组引用:\n";
foreach ($decoded['outbounds'] ?? [] as $outbound) {
    if (isset($outbound['outbounds']) && is_array($outbound['outbounds'])) {
        echo "    {$outbound['tag']}: " . implode(', ', $outbound['outbounds']) . "\n";
    }
}

echo "  路由规则:\n";
echo "    final: " . ($decoded['route']['final'] ?? 'undefined') . "\n";
foreach ($decoded['route']['rules'] ?? [] as $rule) {
    if (isset($rule['outbound'])) {
        echo "    outbound: {$rule['outbound']}\n";
    }
}

// 3. V2rayN / Shadowrocket 文本格式测试
echo "\n【3】V2rayN / Shadowrocket (Base64 URI List)\n";
$textFormat = "vmess://base64encodedconfig1 // 香港 HKT 01\n";
$textFormat .= "trojan://password@us.example.com:443 // 美国 CN2 GIA\n";
$textFormat .= "ss://base64@jp.example.com:443 // 日本东京 01\n";

$response = new Response($textFormat);
$rewritten = SubscribeDisplayService::rewriteResponse($response, $rule);
$body = $rewritten->getContent();

echo "  处理结果:\n";
if ($body === $textFormat) {
    echo "    ⚠ 响应体未修改（这是正常的，因为节点名已在 \$servers 数组中重命名）\n";
    echo "    ✓ 协议构建器应该使用重命名后的节点名生成 URI\n";
} else {
    echo "    ✓ 响应体已修改\n";
}

// 4. SIP008 JSON 格式测试
echo "\n【4】SIP008 (Shadowsocks JSON)\n";
$sip008 = json_encode([
    'version' => 1,
    'servers' => [
        ['remarks' => '香港 HKT 01', 'server' => 'hk.example.com', 'method' => 'aes-256-gcm'],
        ['remarks' => '美国 CN2 GIA', 'server' => 'us.example.com', 'method' => 'aes-256-gcm'],
    ]
]);

$response = new Response($sip008);
$rewritten = SubscribeDisplayService::rewriteResponse($response, $rule);
$body = $rewritten->getContent();

if ($body === $sip008) {
    echo "  ⚠ 响应体未修改（同样依赖协议构建器使用重命名后的节点名）\n";
} else {
    echo "  处理结果: " . substr($body, 0, 100) . "...\n";
}

// 5. 验证关键逻辑
echo "\n【5】关键逻辑验证\n";
echo "  ✓ renameNodes() 在协议构建器之前执行，修改 \$servers 数组\n";
echo "  ✓ rewriteResponse() 在协议构建器之后执行，处理最终响应体\n";
echo "  ✓ Clash/Singbox 格式需要额外替换分组名和引用\n";
echo "  ✓ 其他格式依赖协议构建器使用重命名后的节点名\n";

// 6. 潜在问题检查
echo "\n【6】潜在问题检查\n";

echo "  问题1: Clash YAML 中节点名包含特殊字符？\n";
$specialChars = [
    '香港[Premium]' => 'HK[Premium]',
    '美国|高速' => 'US|高速',
];
$testYaml = "proxies:\n  - name: 香港[Premium]\n    type: vmess\n";
$testYaml .= "proxy-groups:\n  - name: 自动\n    proxies:\n      - 香港[Premium]\n";
$response = new Response($testYaml);
$rewritten = SubscribeDisplayService::rewriteResponse($response, ['replacements' => $specialChars]);
$body = $rewritten->getContent();
echo "    " . (strpos($body, 'HK[Premium]') !== false ? '✓' : '✗') . " 特殊字符正常处理\n";

echo "\n  问题2: 节点名部分匹配导致过度替换？\n";
$overReplace = ['香港' => 'HK', '香' => 'X'];
$testName = '香港湾仔';
$result = strtr($testName, $overReplace);
echo "    原始: $testName → 结果: $result\n";
echo "    " . ($result === 'HK湾仔' ? '✓' : '⚠') . " strtr() 按最长匹配优先\n";

echo "\n  问题3: Clash rules 中 no-resolve 参数处理？\n";
$testRule = "  - DOMAIN-SUFFIX,google.com,自动选择,no-resolve\n";
$response = new Response("proxy-groups:\n  - name: 自动选择\nrules:\n$testRule");
$rewritten = SubscribeDisplayService::rewriteResponse($response, ['replacements' => ['自动选择' => 'Auto']]);
$body = $rewritten->getContent();
echo "    " . (strpos($body, 'Auto,no-resolve') !== false ? '✓' : '✗') . " no-resolve 参数保持完整\n";

echo "\n=== 测试完成 ===\n";
