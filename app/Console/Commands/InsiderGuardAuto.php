<?php

namespace App\Console\Commands;

use App\Services\InsiderGuardService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class InsiderGuardAuto extends Command
{
    protected $signature = 'insider-guard:auto';

    protected $description = '风控中心：对连续多个周期超过风险阈值的账号发送 Telegram 通知或自动限制订阅';

    public function handle()
    {
        try {
            $result = InsiderGuardService::autoHandle();
        } catch (QueryException $e) {
            $this->warn($e->getCode() === '42S02'
                ? '风控中心的数据表不存在，请先执行 php artisan v2board:update'
                : '风控中心数据库错误：' . $e->getMessage());
            return 1;
        }
        if (!empty($result['disabled'])) {
            $this->info('自动处置未开启');
            return 0;
        }
        $this->info("命中 {$result['hit']} 个账号，通知 {$result['notified']} 个，自动限制 {$result['restricted']} 个");
        return 0;
    }
}
