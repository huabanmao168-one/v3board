<?php

// 模拟生产环境的订阅展示规则测试

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

echo "=== 订阅展示规则完整流程测试 ===\n\n";

// 1. 检查配置文件状态
echo "1. 配置文件检查\n";
$configPath = base_path('config/v2board.php');
if (file_exists($configPath)) {
    echo "   ✓ config/v2board.php 存在\n";
    $configContent = include $configPath;
    if (isset($configContent['subscribe_display_rules'])) {
        echo "   ✓ subscribe_display_rules 键存在\n";
        $rulesJson = $configContent['subscribe_display_rules'];
        echo "   值: " . (is_null($rulesJson) ? 'NULL' : substr($rulesJson, 0, 100) . '...') . "\n";
    } else {
        echo "   ✗ subscribe_display_rules 键不存在\n";
    }
} else {
    echo "   ✗ config/v2board.php 不存在\n";
}

// 2. 检查 config() 函数读取结果
echo "\n2. config() 函数读取\n";
$rules = config('v2board.subscribe_display_rules');
echo "   config('v2board.subscribe_display_rules'): " . ($rules === null ? 'NULL' : gettype($rules)) . "\n";
if (is_string($rules)) {
    echo "   原始值: " . substr($rules, 0, 100) . (strlen($rules) > 100 ? '...' : '') . "\n";
    $decoded = json_decode($rules, true);
    echo "   解码后: " . (is_array($decoded) ? count($decoded) . ' 条规则' : 'JSON 解码失败') . "\n";
    if (is_array($decoded) && count($decoded) > 0) {
        echo "   第一条规则:\n";
        echo "     - match: " . ($decoded[0]['match'] ?? 'undefined') . "\n";
        echo "     - title: " . ($decoded[0]['title'] ?? 'undefined') . "\n";
        echo "     - replacements: " . (isset($decoded[0]['replacements']) ? count($decoded[0]['replacements']) . ' 个' : '0 个') . "\n";
    }
}

// 3. 模拟 SubscribeDisplayService::matchRule() 的逻辑
echo "\n3. 规则匹配逻辑测试\n";
$testCases = [
    ['email' => 'test@example.com', 'ua' => 'clash-verge/1.0'],
    ['email' => 'admin@gmail.com', 'ua' => 'ClashX/1.0'],
    ['email' => 'user@163.com', 'ua' => 'Surge/5.0'],
];

foreach ($testCases as $i => $case) {
    echo "   测试用例 " . ($i + 1) . ": {$case['email']} | {$case['ua']}\n";

    $rulesArray = json_decode((string) config('v2board.subscribe_display_rules'), true) ?: [];
    $matched = false;
    $matchedRule = null;

    foreach ($rulesArray as $rule) {
        $value = $rule['value'] ?? '';
        switch ($rule['match']) {
            case 'all':
                $matched = true;
                break;
            case 'email_exact':
                $matched = strcasecmp($case['email'], $value) === 0;
                break;
            case 'email_suffix':
                $matched = strcasecmp(substr($case['email'], -strlen($value)), $value) === 0;
                break;
            case 'email_keyword':
                $matched = stripos($case['email'], $value) !== false;
                break;
            case 'ua_contains':
                $matched = stripos($case['ua'], $value) !== false;
                break;
            case 'ua_not_contains':
                $matched = $case['ua'] !== '' && stripos($case['ua'], $value) === false;
                break;
            default:
                $matched = false;
        }
        if ($matched) {
            $matchedRule = $rule;
            break;
        }
    }

    if ($matchedRule) {
        echo "     ✓ 匹配规则: {$matchedRule['match']}" . (isset($matchedRule['value']) ? " ({$matchedRule['value']})" : '') . "\n";
        echo "     ✓ 新标题: " . ($matchedRule['title'] ?? '无') . "\n";
        echo "     ✓ 替换规则: " . (isset($matchedRule['replacements']) ? count($matchedRule['replacements']) . ' 个' : '0 个') . "\n";
    } else {
        echo "     ✗ 无匹配规则\n";
    }
}

// 4. 检查 opcache 状态
echo "\n4. OPcache 状态\n";
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    if ($status !== false) {
        echo "   ✓ OPcache 已启用\n";
        echo "   缓存命中率: " . number_format($status['opcache_statistics']['opcache_hit_rate'], 2) . "%\n";
        echo "   已缓存文件: " . $status['opcache_statistics']['num_cached_scripts'] . "\n";
    } else {
        echo "   ✗ OPcache 已禁用\n";
    }
} else {
    echo "   - OPcache 函数不可用\n";
}

// 5. 检查配置缓存
echo "\n5. 配置缓存状态\n";
$cachedConfigPath = base_path('bootstrap/cache/config.php');
if (file_exists($cachedConfigPath)) {
    echo "   ✓ 配置已缓存: bootstrap/cache/config.php\n";
    $cachedConfig = include $cachedConfigPath;
    if (isset($cachedConfig['v2board']['subscribe_display_rules'])) {
        echo "   ✓ 缓存中包含 subscribe_display_rules\n";
        $cachedRules = $cachedConfig['v2board']['subscribe_display_rules'];
        echo "   值: " . (is_null($cachedRules) ? 'NULL' : substr($cachedRules, 0, 80) . '...') . "\n";
    } else {
        echo "   ✗ 缓存中不包含 subscribe_display_rules\n";
    }
} else {
    echo "   - 未使用配置缓存\n";
}

echo "\n=== 测试完成 ===\n";
