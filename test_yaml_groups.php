<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\SubscribeDisplayService;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\Response;

echo "=== Clash YAML 分组处理验证 ===\n\n";

$rule = ['replacements' => ['自动选择' => 'Auto', '故障转移' => 'Fallback', '香港' => 'HK']];

$yamlContent = <<<'YAML'
proxies:
  - name: 香港 01
    type: vmess
    server: test.com
    port: 443

proxy-groups:
  - name: 自动选择
    type: url-test
    proxies:
      - 香港 01
  - name: 故障转移
    type: fallback
    proxies:
      - 香港 01

rules:
  - DOMAIN-SUFFIX,google.com,自动选择
  - DOMAIN,youtube.com,故障转移,no-resolve
  - MATCH,自动选择
YAML;

echo "原始 YAML:\n";
echo "----------------------------------------\n";
echo $yamlContent;
echo "\n----------------------------------------\n\n";

echo "【1】检查 proxy-groups 是否存在\n";
if (preg_match('/^proxy-groups:/m', $yamlContent)) {
    echo "  ✓ 找到 proxy-groups\n\n";
} else {
    echo "  ✗ 未找到 proxy-groups\n\n";
}

echo "【2】YAML 解析\n";
$parsed = Yaml::parse($yamlContent);
echo "  proxy-groups 类型: " . gettype($parsed['proxy-groups'] ?? null) . "\n";
if (isset($parsed['proxy-groups'])) {
    echo "  分组数量: " . count($parsed['proxy-groups']) . "\n";
    foreach ($parsed['proxy-groups'] as $i => $group) {
        echo "    分组 $i: name='{$group['name']}', type={$group['type']}\n";
    }
}
echo "\n";

echo "【3】执行 rewriteResponse\n";
$response = new Response($yamlContent);
$rewritten = SubscribeDisplayService::rewriteResponse($response, $rule);
$rewrittenBody = $rewritten->getContent();

echo "  处理后的分组:\n";
$reparsed = Yaml::parse($rewrittenBody);
foreach ($reparsed['proxy-groups'] ?? [] as $i => $group) {
    echo "    ✓ {$group['name']}\n";
}

echo "\n  处理后的分组引用 (proxies 列表):\n";
foreach ($reparsed['proxy-groups'] ?? [] as $group) {
    if (isset($group['proxies'])) {
        echo "    {$group['name']}: [" . implode(', ', $group['proxies']) . "]\n";
    }
}

echo "\n  处理后的规则:\n";
foreach ($reparsed['rules'] ?? [] as $rule) {
    echo "    • $rule\n";
}

echo "\n【4】完整输出对比\n";
echo "原始 rules:\n";
foreach ($parsed['rules'] as $r) echo "  $r\n";
echo "\n处理后 rules:\n";
foreach ($reparsed['rules'] as $r) echo "  $r\n";

echo "\n【5】验证各种 Clash 变体\n";

// ClashMeta 格式 (带 proxy-providers)
$metaYaml = <<<'YAML'
proxy-groups:
  - name: 自动选择
    type: select
    use:
      - provider1
  - name: 故障转移
    type: fallback
    proxies:
      - 香港 01

rules:
  - DOMAIN,test.com,自动选择
YAML;

echo "  ClashMeta (use/proxy-providers):\n";
$response = new Response($metaYaml);
$rewritten = SubscribeDisplayService::rewriteResponse($response, ['replacements' => ['自动选择' => 'Auto', '故障转移' => 'FB']]);
$body = $rewritten->getContent();
$parsed = Yaml::parse($body);
echo "    分组: " . implode(', ', array_column($parsed['proxy-groups'], 'name')) . "\n";
echo "    规则: {$parsed['rules'][0]}\n";

echo "\n=== 测试完成 ===\n";
