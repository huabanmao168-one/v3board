<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== 生产环境订阅展示规则诊断 ===\n\n";

// 1. 配置文件状态
echo "【1/5】配置文件状态\n";
$configPath = base_path('config/v2board.php');
if (!file_exists($configPath)) {
    echo "❌ config/v2board.php 不存在\n";
    echo "   解决：在后台保存一次系统配置，或手动创建该文件\n\n";
    exit(1);
}

$perms = substr(sprintf('%o', fileperms($configPath)), -4);
$owner = posix_getpwuid(fileowner($configPath));
echo "✓ 文件存在\n";
echo "  路径: $configPath\n";
echo "  权限: $perms\n";
echo "  所有者: {$owner['name']}\n";
echo "  大小: " . filesize($configPath) . " 字节\n\n";

// 2. 配置内容检查
echo "【2/5】配置内容检查\n";
$config = include $configPath;
if (!is_array($config)) {
    echo "❌ 配置文件格式错误（不是数组）\n\n";
    exit(1);
}

if (!array_key_exists('subscribe_display_rules', $config)) {
    echo "❌ subscribe_display_rules 键不存在\n\n";
    exit(1);
}

$rulesJson = $config['subscribe_display_rules'];
echo "✓ subscribe_display_rules 存在\n";
echo "  类型: " . gettype($rulesJson) . "\n";

if ($rulesJson === null) {
    echo "⚠ 值为 NULL（规则未配置）\n\n";
} else {
    echo "  长度: " . strlen($rulesJson) . " 字符\n";
    $rules = json_decode($rulesJson, true);
    if (!is_array($rules)) {
        echo "❌ JSON 解析失败\n";
        echo "  原始值: " . substr($rulesJson, 0, 200) . "\n\n";
        exit(1);
    }
    echo "✓ JSON 格式正确\n";
    echo "  规则数: " . count($rules) . " 条\n\n";

    foreach ($rules as $i => $rule) {
        echo "  规则 " . ($i + 1) . ":\n";
        echo "    匹配: {$rule['match']}";
        if (isset($rule['value'])) echo " = {$rule['value']}";
        echo "\n";
        if (isset($rule['title'])) echo "    标题: {$rule['title']}\n";
        if (isset($rule['replacements']) && is_array($rule['replacements'])) {
            echo "    替换: " . count($rule['replacements']) . " 个";
            if (count($rule['replacements']) > 0) {
                $first = array_key_first($rule['replacements']);
                echo " (如: '{$first}' → '{$rule['replacements'][$first]}')";
            }
            echo "\n";
        }
    }
    echo "\n";
}

// 3. config() 函数读取
echo "【3/5】运行时配置读取\n";
$runtimeRules = config('v2board.subscribe_display_rules');
if ($runtimeRules === $rulesJson) {
    echo "✓ config() 返回值与文件一致\n\n";
} else {
    echo "❌ config() 返回值与文件不一致\n";
    echo "  文件值: " . substr((string)$rulesJson, 0, 100) . "...\n";
    echo "  运行时: " . substr((string)$runtimeRules, 0, 100) . "...\n";
    echo "  可能原因: 配置缓存过期，OPcache 未清除\n\n";
}

// 4. OPcache 状态
echo "【4/5】OPcache 状态\n";
if (!function_exists('opcache_get_status')) {
    echo "- OPcache 未安装\n\n";
} else {
    $status = opcache_get_status(false);
    if ($status === false) {
        echo "- OPcache 已禁用\n\n";
    } else {
        echo "✓ OPcache 已启用\n";
        $scripts = opcache_get_status(true)['scripts'] ?? [];
        $cached = isset($scripts[$configPath]);
        echo "  config/v2board.php: " . ($cached ? "✓ 已缓存" : "未缓存") . "\n";
        if ($cached) {
            echo "  ⚠ 修改配置后需要执行 opcache_reset() 或重启 PHP-FPM\n";
        }
        echo "\n";
    }
}

// 5. 配置缓存
echo "【5/5】Laravel 配置缓存\n";
$cachedPath = base_path('bootstrap/cache/config.php');
if (!file_exists($cachedPath)) {
    echo "- 未使用配置缓存\n\n";
} else {
    $cached = include $cachedPath;
    $cachedRules = $cached['v2board']['subscribe_display_rules'] ?? null;
    if ($cachedRules === $rulesJson) {
        echo "✓ 缓存配置与文件一致\n\n";
    } else {
        echo "❌ 缓存配置过期\n";
        echo "  解决：执行 php artisan config:cache\n\n";
    }
}

// 6. 实际测试
if (!empty($rules)) {
    echo "【测试】实际规则匹配\n";
    $testEmail = 'test@example.com';
    $testUa = 'clash-verge/1.0';

    foreach ($rules as $rule) {
        $value = $rule['value'] ?? '';
        $matched = false;
        switch ($rule['match']) {
            case 'all': $matched = true; break;
            case 'email_exact': $matched = strcasecmp($testEmail, $value) === 0; break;
            case 'email_suffix': $matched = strcasecmp(substr($testEmail, -strlen($value)), $value) === 0; break;
            case 'email_keyword': $matched = stripos($testEmail, $value) !== false; break;
            case 'ua_contains': $matched = stripos($testUa, $value) !== false; break;
            case 'ua_not_contains': $matched = $testUa !== '' && stripos($testUa, $value) === false; break;
        }
        if ($matched) {
            echo "✓ 测试用户 ({$testEmail}, {$testUa}) 匹配规则 '{$rule['match']}'\n";
            if (isset($rule['title'])) echo "  订阅标题将显示为: {$rule['title']}\n";
            if (!empty($rule['replacements'])) {
                echo "  节点名称将替换:\n";
                $count = 0;
                foreach ($rule['replacements'] as $from => $to) {
                    echo "    '{$from}' → '{$to}'\n";
                    if (++$count >= 3) {
                        $remaining = count($rule['replacements']) - 3;
                        if ($remaining > 0) echo "    ... 还有 {$remaining} 个\n";
                        break;
                    }
                }
            }
            break;
        }
    }
    echo "\n";
}

echo "=== 诊断完成 ===\n\n";

echo "常见问题排查:\n";
echo "1. 如果配置文件正确但不生效 → 清除 OPcache: opcache_reset() 或重启 PHP-FPM\n";
echo "2. 如果使用了配置缓存 → 执行: php artisan config:cache\n";
echo "3. 如果使用 Webman/Workerman → 重启进程（配置中的 WEBMANPID）\n";
echo "4. 检查后台保存时的错误日志: storage/logs/laravel.log\n";
