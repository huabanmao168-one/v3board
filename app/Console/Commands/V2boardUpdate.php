<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class V2boardUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'v2board 更新';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        try {
            \Artisan::call('config:cache');
        } catch (\Exception $e) {
            $this->error('配置缓存失败: ' . $e->getMessage());
        }

        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $this->error('数据库连接失败: ' . $e->getMessage());
            return 1;
        }

        $file = \File::get(base_path() . '/database/update.sql');
        if (!$file) {
            $this->error('数据库文件不存在');
            return 1;
        }
        $sql = str_replace("\n", "", $file);
        $sql = preg_split("/;/", $sql);
        if (!is_array($sql)) {
            $this->error('数据库文件格式有误');
            return 1;
        }
        $this->info('正在导入数据库请稍等...');
        $success = 0;
        $failed = 0;
        foreach ($sql as $item) {
            $item = trim($item);
            if (!$item) continue;
            try {
                DB::statement($item);
                $success++;
            } catch (\Exception $e) {
                $failed++;
                // Most statements are ALTER TABLE for columns that may already exist
                // Silently continue on errors (duplicates, missing tables in old versions)
            }
        }
        $this->info("数据库升级完成: $success 条成功, $failed 条跳过");

        try {
            \Artisan::call('horizon:terminate');
            $this->info('队列服务已重启');
        } catch (\Exception $e) {
            $this->warn('队列服务重启失败（可能未安装 Horizon）: ' . $e->getMessage());
        }

        $this->info('更新完毕');
        return 0;
    }
}
