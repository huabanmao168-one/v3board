<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 风控中心的账号管理：白名单（不参与评分与自动处置）、重置订阅链接、用户设备视图。
 */
class InsiderGuardManageController extends Controller
{
    const MAX_ID = 2147483647;
    const DEVICE_WINDOW_SECONDS = 86400;
    const DEVICES_LIMIT = 50;

    /** 白名单列表：服务端分页，最近加入的在前 */
    public function whitelist(Request $request)
    {
        $pageSize = max(10, min(100, (int)$request->input('pageSize', 20)));
        $current = max(1, min(100000, (int)$request->input('current', 1)));
        $total = DB::table('v2_insider_guard_whitelist')->count();
        $rows = DB::table('v2_insider_guard_whitelist')
            ->orderByDesc('created_at')
            ->orderByDesc('user_id')
            ->forPage($current, $pageSize)
            ->get(['user_id', 'admin_id', 'reason', 'created_at']);
        $emails = $rows->isEmpty() ? collect() : User::whereIn('id', $rows->pluck('user_id'))->pluck('email', 'id');
        foreach ($rows as $row) {
            $row->email = $emails[$row->user_id] ?? null;
        }
        return response(['data' => $rows->values(), 'total' => $total]);
    }

    /** 加入白名单：不再进入高风险列表，也不会被自动处置；需填写原因 */
    public function whitelistAdd(Request $request)
    {
        $id = $this->userId($request);
        $reason = $this->reason($request, 8, '白名单原因需 8～1000 字');
        if (!User::find($id)) abort(500, '用户不存在');
        DB::table('v2_insider_guard_whitelist')->upsert(
            [['user_id' => $id, 'admin_id' => (int)$request->user['id'], 'reason' => $reason, 'created_at' => time()]],
            ['user_id'],
            ['admin_id', 'reason']
        );
        return response(['data' => true]);
    }

    public function whitelistRemove(Request $request)
    {
        $id = $this->userId($request);
        DB::table('v2_insider_guard_whitelist')->where('user_id', $id)->delete();
        return response(['data' => true]);
    }

    /** 重置订阅：换 token 和 uuid，旧订阅链接与旧节点凭据立即失效；不改变限制状态 */
    public function resetSubscription(Request $request)
    {
        $id = $this->userId($request);
        $user = User::find($id);
        if (!$user) abort(500, '用户不存在');
        if ($user->is_admin) abort(422, '不能重置管理员账号的订阅');
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) abort(500, '重置失败');
        Log::info('InsiderGuard subscription reset', ['user_id' => $id, 'admin_id' => (int)$request->user['id']]);
        return response(['data' => true]);
    }

    /** 设备视图：最近 24 小时按 UA 汇总的订阅活动（IP 数、地区、次数、最近时间） */
    public function devices(Request $request)
    {
        $id = $this->userId($request);
        $since = time() - self::DEVICE_WINDOW_SECONDS;
        $rows = DB::table('v2_insider_guard_activity as a')
            ->leftJoin('v2_insider_guard_ua as ua', 'ua.ua_hash', '=', 'a.ua_hash')
            ->where('a.user_id', $id)
            ->where('a.event_type', 'subscription')
            ->where('a.last_at', '>=', $since)
            ->groupBy('a.ua_hash')
            ->orderByRaw('MAX(a.last_at) DESC')
            ->limit(self::DEVICES_LIMIT)
            ->selectRaw('a.ua_hash, MAX(ua.ua) as ua, SUM(a.times) as pulls, COUNT(DISTINCT NULLIF(a.ip_address, \'\')) as ips, MAX(a.last_at) as last_at')
            ->get();
        $locations = DB::table('v2_insider_guard_activity')
            ->where('user_id', $id)
            ->where('event_type', 'subscription')
            ->where('last_at', '>=', $since)
            ->where('ip_location', '<>', '')
            ->whereNotNull('ip_location')
            ->groupBy('ua_hash', 'ip_location')
            ->get(['ua_hash', 'ip_location']);
        $byUa = [];
        foreach ($locations as $l) {
            $byUa[$l->ua_hash][] = $l->ip_location;
        }
        $data = $rows->map(function ($r) use ($byUa) {
            return [
                'ua' => $r->ua,
                'ua_hash' => $r->ua_hash,
                'pulls' => (int)$r->pulls,
                'ips' => (int)$r->ips,
                'locations' => array_slice(array_values(array_unique($byUa[$r->ua_hash] ?? [])), 0, 5),
                'last_at' => (int)$r->last_at,
            ];
        })->values();
        return response(['data' => $data]);
    }

    private function userId(Request $request): int
    {
        $value = $request->input('id');
        if (!is_scalar($value) || !preg_match('/^\d{1,10}$/', (string)$value) || (int)$value < 1 || (int)$value > self::MAX_ID) {
            abort(422, '用户 ID 无效');
        }
        return (int)$value;
    }

    private function reason(Request $request, int $min, string $error): string
    {
        $value = $request->input('reason');
        $reason = trim(is_scalar($value) ? (string)$value : '');
        if (mb_strlen($reason) < $min || mb_strlen($reason) > 1000) abort(422, $error);
        return $reason;
    }
}
