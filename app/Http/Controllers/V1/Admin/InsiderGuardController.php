<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GeoIpService;
use App\Services\InsiderGuardService;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 风控中心：风险用户、活动记录、人工审核与限制。
 * 节点一律用 node_type + node_id 标识。活动表按小时合并（见 InsiderGuardService），时间字段为 first_at / last_at。
 */
class InsiderGuardController extends Controller
{
    const HOURS = [1, 8, 24, 72, 168];
    /** 活动记录对外的字段（联表取 UA 文本） */
    const ACTIVITY_COLUMNS = ['a.id', 'a.user_id', 'a.event_type', 'a.ip_address', 'a.ip_location', 'a.ip_isp', 'a.ip_usage', 'a.ua_hash', 'a.node_count', 'a.times', 'a.first_at', 'a.last_at', 'ua.ua'];
    const REVIEW_ACTIONS = ['watch', 'clear', 'restrict_recommended'];
    // 概览「近 24 小时」
    const OVERVIEW_WINDOW_SECONDS = 86400;
    // 风险用户最多返回这么多人；时段 ≥ 24 小时要扫几天的活动，聚合结果缓存一分钟；概览的 24 小时总数缓存两分钟
    const USERS_LIMIT = 500;
    const USERS_CACHE_FROM_HOURS = 24;
    const USERS_CACHE_SECONDS = 60;
    const OVERVIEW_CACHE_SECONDS = 120;
    // 各列表上限
    const USER_EVENTS_LIMIT = 100;
    const USER_NODES_LIMIT = 100;
    const USER_REVIEWS_LIMIT = 30;
    // int(11) 列能存的最大 ID
    const MAX_ID = 2147483647;

    public function subscriptionBlocks(Request $request)
    {
        $rows = DB::table('v2_insider_guard_subscription_block');
        if ($request->filled('ip')) {
            $ip = $request->input('ip');
            if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
                abort(422, '请输入有效的 IPv4 或 IPv6 地址');
            }
            $rows->where('ip_address', inet_ntop(inet_pton($ip)));
        }
        $pageSize = max(10, min(100, (int) $request->input('pageSize', 20)));
        $current = max(1, min(100000, (int) $request->input('current', 1)));
        return response([
            'total' => (clone $rows)->count(),
            'data' => $rows->orderByDesc('last_at')->orderByDesc('id')->forPage($current, $pageSize)->get(),
        ]);
    }

    public function geoIp(Request $request)
    {
        $ip = $request->input('ip');
        if ($ip === null) {
            return response(['data' => GeoIpService::status()]);
        }
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            abort(422, '请输入有效的 IPv4 或 IPv6 地址');
        }
        $result = GeoIpService::lookup($ip);
        return response(['data' => [
            'result' => $result ?: null,
            'status' => $result ? 'ready' : GeoIpService::databaseStatus(strpos($ip, ':') === false ? 4 : 6),
        ]]);
    }

    public function overview(Request $request)
    {
        // 近 24 小时活动总数要扫整个窗口，缓存两分钟；审核数、限制数、异常列表都是小表，实时
        $recent = Cache::remember('insider_guard_overview_recent', self::OVERVIEW_CACHE_SECONDS, function () {
            return (int)DB::table('v2_insider_guard_activity')
                ->where('last_at', '>=', time() - self::OVERVIEW_WINDOW_SECONDS)->sum('times');
        });
        $top = Cache::remember('insider_guard_top_subscribe_ips', self::OVERVIEW_CACHE_SECONDS, function () {
            return DB::table('v2_insider_guard_activity')
                ->where('last_at', '>=', time() - self::OVERVIEW_WINDOW_SECONDS)
                ->where('event_type', 'subscription')->where('ip_address', '<>', '')
                ->selectRaw('ip_address, SUM(times) AS requests, MAX(ip_location) AS ip_location, MAX(ip_isp) AS ip_isp')
                ->groupBy('ip_address')->orderByDesc('requests')->orderBy('ip_address')->limit(10)->get();
        });
        return response([
            'data' => [
                'recent_events' => (int)$recent,
                'top_ips' => $top,
                'reviews' => DB::table('v2_insider_guard_review')->count(),
                'restricted' => DB::table('v2_insider_guard_restrict')->count(),
            ]
        ]);
    }

    /** 限制中的账号：服务端分页，最近限制的在前，带邮箱、限制原因与操作管理员；可按用户 ID、邮箱搜索 */
    public function restrictedUsers(Request $request)
    {
        $pageSize = max(10, min(100, (int)$request->input('pageSize', 20)));
        $current = max(1, min(100000, (int)$request->input('current', 1)));
        $query = DB::table('v2_insider_guard_restrict');
        // 按用户 ID 筛选
        if ($request->filled('user_id')) {
            $userId = $this->positiveInt($request->input('user_id'));
            if ($userId === null) abort(422, '用户 ID 无效');
            $query->where('user_id', $userId);
        }
        // 按邮箱筛选（需要联表）
        if ($request->filled('email')) {
            $email = mb_substr(trim($this->str($request, 'email')), 0, 255);
            if ($email !== '') {
                // 转义 LIKE 通配符防止被当作模糊匹配符号
                $email = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $email);
                $matchedIds = User::where('email', 'like', '%' . $email . '%')->limit(1000)->pluck('id');
                if ($matchedIds->isEmpty()) {
                    // 没有匹配的用户，直接返回空
                    return response(['data' => [], 'total' => 0]);
                }
                if ($matchedIds->count() >= 1000) {
                    abort(422, '匹配用户过多，请输入更精确的搜索词');
                }
                $query->whereIn('user_id', $matchedIds);
            }
        }
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')
            ->orderByDesc('user_id')
            ->forPage($current, $pageSize)
            ->get(['user_id', 'admin_id', 'reason', 'created_at']);
        return response([
            'data' => $this->withEmails($rows)->values(),
            'total' => $total,
        ]);
    }

    /** 观察中的账号：被标记为「持续观察」但未被限制的用户，显示最近一次观察记录；可按用户 ID、邮箱搜索 */
    public function watchedUsers(Request $request)
    {
        $pageSize = max(10, min(100, (int)$request->input('pageSize', 20)));
        $current = max(1, min(100000, (int)$request->input('current', 1)));
        // 子查询：每个用户最新的 watch 审核记录 ID
        $latestReviews = DB::table('v2_insider_guard_review')
            ->select('user_id', DB::raw('MAX(id) as latest_id'))
            ->where('action', 'watch')
            ->groupBy('user_id');
        // 按用户 ID 筛选
        if ($request->filled('user_id')) {
            $userId = $this->positiveInt($request->input('user_id'));
            if ($userId === null) abort(422, '用户 ID 无效');
            $latestReviews->where('user_id', $userId);
        }
        // 按邮箱筛选（需要先查出匹配的用户 ID）
        if ($request->filled('email')) {
            $email = mb_substr(trim($this->str($request, 'email')), 0, 255);
            if ($email !== '') {
                // 转义 LIKE 通配符防止被当作模糊匹配符号
                $email = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $email);
                $matchedIds = User::where('email', 'like', '%' . $email . '%')->limit(1000)->pluck('id');
                if ($matchedIds->isEmpty()) {
                    return response(['data' => [], 'total' => 0]);
                }
                if ($matchedIds->count() >= 1000) {
                    abort(422, '匹配用户过多，请输入更精确的搜索词');
                }
                $latestReviews->whereIn('user_id', $matchedIds);
            }
        }
        // 主查询：联表取最新观察记录的详情，排除已被限制的用户
        $rows = DB::table('v2_insider_guard_review as r')
            ->joinSub($latestReviews, 'latest', function ($join) {
                $join->on('r.id', '=', 'latest.latest_id');
            })
            ->leftJoin('v2_insider_guard_restrict as restrict', 'r.user_id', '=', 'restrict.user_id')
            ->whereNull('restrict.user_id')
            ->orderByDesc('r.created_at')
            ->orderByDesc('r.user_id');
        $total = (clone $rows)->count();
        $data = $rows->forPage($current, $pageSize)->get(['r.user_id', 'r.admin_id', 'r.reason', 'r.created_at']);
        // 附加邮箱和当前风险评分
        $items = $this->withEmails($data)->map(function ($row) {
            $risk = InsiderGuardService::score($row->user_id);
            return [
                'user_id' => (int)$row->user_id,
                'email' => $row->email,
                'admin_id' => $row->admin_id,
                'reason' => $row->reason,
                'created_at' => (int)$row->created_at,
                'risk_score' => $risk['score'] ?? 0,
            ];
        });
        return response([
            'data' => $items->values(),
            'total' => $total,
        ]);
    }

    /** 风险用户：按时段统计订阅次数、来源 IP 数并评分 */
    public function users(Request $request)
    {
        $hours = (int)$request->input('hours', 1);
        if (!in_array($hours, self::HOURS, true)) abort(422, '不支持的统计时间');
        $threshold = (int)InsiderGuardService::setting('risk_threshold', InsiderGuardService::DEFAULT_RISK_THRESHOLD);
        $aggregate = function () use ($hours) {
            return $this->aggregateUsers($hours);
        };
        $rows = $hours >= self::USERS_CACHE_FROM_HOURS
            ? Cache::remember('insider_guard_users:' . $hours, self::USERS_CACHE_SECONDS, $aggregate)
            : $aggregate();
        // 限制标记不进缓存，限制 / 解除后列表立刻能反映
        $userIds = array_column($rows, 'user_id');
        $restricted = $userIds
            ? DB::table('v2_insider_guard_restrict')->whereIn('user_id', $userIds)->pluck('user_id')->flip()->all()
            : [];
        $whitelisted = $userIds
            ? DB::table('v2_insider_guard_whitelist')->whereIn('user_id', $userIds)->pluck('user_id')->flip()->all()
            : [];
        // SQL 已按「超阈值维度数 DESC, pulls DESC」排好，等于评分降序，这里不再排
        $items = collect($rows)->map(function ($r) use ($threshold, $restricted, $whitelisted) {
            $score = InsiderGuardService::calcScore($r['pulls'], $r['ips']);
            return $r + [
                'score' => $score,
                'high_risk' => $score >= $threshold && !isset($whitelisted[$r['user_id']]),
                'restricted' => isset($restricted[$r['user_id']]),
            ];
        })->values();
        return response([
            'data' => $items,
            'hours' => $hours,
            'threshold' => $threshold,
        ]);
    }

    /**
     * 时段内每个用户的订阅次数、IP 数、最近活动、最近 UA、邮箱（纯数组，便于缓存）。
     * 先在活动表上聚合并截断（任一维度超过评分阈值的排前面，再按次数），邮箱、UA 另查，不把用户表 JOIN 进全窗口扫描
     */
    private function aggregateUsers(int $hours): array
    {
        $window = time() - $hours * 3600;
        $pulls = 'SUM(CASE WHEN event_type = ? THEN times ELSE 0 END)';
        $rows = DB::table('v2_insider_guard_activity')
            ->select('user_id')
            ->selectRaw("{$pulls} as pulls", [InsiderGuardService::EVENT_SUBSCRIPTION])
            // 溢出桶（ip_address、ua_hash 为空）不算一个 IP；最新 UA 优先取有 UA 的桶
            ->selectRaw("COUNT(DISTINCT NULLIF(ip_address, '')) as ips")
            ->selectRaw('MAX(last_at) as last_seen')
            ->selectRaw("COALESCE(MAX(CASE WHEN ua_hash <> '' THEN id END), MAX(id)) as latest_id")
            ->where('last_at', '>=', $window)
            ->groupBy('user_id')
            ->orderByRaw("({$pulls} > ?) + (COUNT(DISTINCT NULLIF(ip_address, '')) > ?) DESC, pulls DESC", [
                InsiderGuardService::EVENT_SUBSCRIPTION,
                InsiderGuardService::SCORE_PULLS_THRESHOLD,
                InsiderGuardService::SCORE_IPS_THRESHOLD,
            ])
            ->limit(self::USERS_LIMIT)
            ->get();
        if ($rows->isEmpty()) return [];
        $emails = User::whereIn('id', $rows->pluck('user_id'))->pluck('email', 'id');
        // latest_id 对应的桶的 UA
        $uas = DB::table('v2_insider_guard_activity as a')
            ->leftJoin('v2_insider_guard_ua as ua', 'ua.ua_hash', '=', 'a.ua_hash')
            ->whereIn('a.id', $rows->pluck('latest_id'))
            ->pluck('ua.ua', 'a.user_id');
        return $rows->map(function ($r) use ($emails, $uas) {
            return [
                'user_id' => (int)$r->user_id,
                'email' => $emails[$r->user_id] ?? null,
                'ua' => $uas[$r->user_id] ?? null,
                'pulls' => (int)$r->pulls,
                'ips' => (int)$r->ips,
                'last_seen' => (int)$r->last_seen,
            ];
        })->all();
    }

    /** 活动记录（分页，按小时合并的行，最近活动在前），可按用户 ID、事件类型筛选 */
    public function events(Request $request)
    {
        $current = max(1, (int)$request->input('current', 1));
        $pageSize = max(10, min(500, (int)$request->input('pageSize', 20)));
        // 总数不联 UA 字典，只走活动表的索引
        $count = DB::table('v2_insider_guard_activity');
        $rows = $this->activities();
        if ($request->filled('user_id')) {
            $userId = $this->positiveInt($request->input('user_id'));
            if ($userId === null) abort(422, '用户 ID 无效');
            $count->where('user_id', $userId);
            $rows->where('a.user_id', $userId);
        }
        if ($request->filled('event_type')) {
            $eventType = $this->str($request, 'event_type');
            if (!in_array($eventType, [InsiderGuardService::EVENT_LOGIN, InsiderGuardService::EVENT_SUBSCRIPTION], true)) {
                abort(422, '事件类型无效');
            }
            $count->where('event_type', $eventType);
            $rows->where('a.event_type', $eventType);
        }
        return response([
            'data' => $this->withEmails($rows->forPage($current, $pageSize)->get(self::ACTIVITY_COLUMNS)),
            'total' => $count->count(),
        ]);
    }

    /** 用户风险详情：评分、最近活动、被下发过的节点、审核记录、限制状态 */
    public function userDetail(Request $request)
    {
        $id = $this->userId($request);
        $user = User::find($id);
        if (!$user) abort(500, '用户不存在');
        $events = $this->activities()->where('a.user_id', $id)->limit(self::USER_EVENTS_LIMIT)->get(self::ACTIVITY_COLUMNS);
        $deliveries = $this->withNodeNames(
            DB::table('v2_insider_guard_node_user')
                ->where('user_id', $id)
                ->orderByDesc('times')
                ->limit(self::USER_NODES_LIMIT)
                ->get(['node_type', 'node_id', 'times', 'first_at', 'last_at'])
        );
        return response([
            'data' => [
                'user_id' => $id,
                'email' => $user->email,
                'banned' => (int)$user->banned,
                'risk' => InsiderGuardService::score($id),
                'restriction' => $this->restriction($id),
                'events' => $events,
                'deliveries' => $deliveries,
                'reviews' => DB::table('v2_insider_guard_review')
                    ->where('user_id', $id)->orderByDesc('id')->limit(self::USER_REVIEWS_LIMIT)->get(),
            ]
        ]);
    }

    /** 人工审核记录：不改变账号状态 */
    public function review(Request $request)
    {
        $id = $this->userId($request);
        $action = $this->str($request, 'action');
        if (!in_array($action, self::REVIEW_ACTIONS, true)) abort(422, '审核动作无效');
        $reason = $this->reason($request, 8, '审核原因需 8～1000 字');
        if (!User::find($id)) abort(500, '用户不存在');
        $this->logReview($id, (int)$request->user['id'], $action, $reason);
        return response([
            'data' => true
        ]);
    }

    /** 限制账号：订阅与客户端配置仅下发提示节点；需显式确认与原因，并留下审核记录 */
    public function restrict(Request $request)
    {
        $id = $this->userId($request);
        $reason = $this->reason($request, 12, '限制原因需 12～1000 字');
        if (!in_array($this->str($request, 'confirm'), ['1', 'true', 'yes', 'on'], true)) abort(422, '请确认限制操作');
        $adminId = (int)$request->user['id'];
        if ($id === $adminId) abort(422, '不能限制当前登录的管理员账号');
        $user = User::find($id);
        if (!$user) abort(500, '用户不存在');
        if ($user->is_admin) abort(422, '不能限制管理员账号');
        DB::transaction(function () use ($id, $adminId, $reason) {
            InsiderGuardService::restrict($id, $adminId, $reason);
            $this->logReview($id, $adminId, 'restrict', $reason);
        });
        InsiderGuardService::refreshRestricted();
        return response([
            'data' => true
        ]);
    }

    /** 解除限制：恢复真实入口，并留下审核记录 */
    public function release(Request $request)
    {
        $id = $this->userId($request);
        $reason = $this->reason($request, 8, '解除原因需 8～1000 字');
        if (!User::find($id)) abort(500, '用户不存在');
        if (!DB::table('v2_insider_guard_restrict')->where('user_id', $id)->exists()) abort(422, '该用户未被限制');
        $adminId = (int)$request->user['id'];
        DB::transaction(function () use ($id, $adminId, $reason) {
            InsiderGuardService::release($id);
            $this->logReview($id, $adminId, 'release', $reason);
        });
        InsiderGuardService::refreshRestricted();
        return response([
            'data' => true
        ]);
    }

    /** 正整数且不超过 int(11)，否则 null（数组、负数、超大数都拒绝） */
    private function positiveInt($value): ?int
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,10}$/', (string)$value)) return null;
        $int = (int)$value;
        return $int >= 1 && $int <= self::MAX_ID ? $int : null;
    }

    private function userId(Request $request): int
    {
        $id = $this->positiveInt($request->input('id'));
        if ($id === null) abort(422, '用户 ID 无效');
        return $id;
    }

    /** 审核 / 限制 / 解除的原因：去首尾空白，按字符数限制 $min～1000 */
    private function reason(Request $request, int $min, string $error): string
    {
        $reason = trim($this->str($request, 'reason'));
        if (mb_strlen($reason) < $min || mb_strlen($reason) > 1000) abort(422, $error);
        return $reason;
    }

    /** 取字符串参数；传成数组（a[]=x）时按空串处理，而不是 500 */
    private function str(Request $request, string $key): string
    {
        $value = $request->input($key);
        return is_scalar($value) ? (string)$value : '';
    }

    private function logReview(int $userId, int $adminId, string $action, string $reason): void
    {
        DB::table('v2_insider_guard_review')->insert([
            'user_id' => $userId,
            'admin_id' => $adminId,
            'action' => $action,
            'reason' => $reason,
            'created_at' => time(),
        ]);
    }

    /** 当前的限制记录，未限制为 null */
    private function restriction(int $userId)
    {
        return DB::table('v2_insider_guard_restrict')->where('user_id', $userId)->first(['admin_id', 'reason', 'created_at']);
    }

    /** 活动记录联 UA 字典，最近活动在前 */
    private function activities()
    {
        return DB::table('v2_insider_guard_activity as a')
            ->leftJoin('v2_insider_guard_ua as ua', 'ua.ua_hash', '=', 'a.ua_hash')
            ->orderByDesc('a.last_at')
            ->orderByDesc('a.id');
    }

    /** 给带 user_id 的行补 email（用户已删除为 null） */
    private function withEmails(Collection $rows): Collection
    {
        if ($rows->isEmpty()) return $rows;
        $emails = User::whereIn('id', $rows->pluck('user_id')->unique())->pluck('email', 'id');
        foreach ($rows as $row) {
            $row->email = $emails[$row->user_id] ?? null;
        }
        return $rows;
    }

    /** 给带 node_type / node_id 的行补 node_name（节点已删除为 null） */
    private function withNodeNames(Collection $rows): Collection
    {
        if ($rows->isEmpty()) return $rows;
        $names = InsiderGuardService::nodeNames();
        foreach ($rows as $row) {
            $row->node_name = $names[$row->node_type . ':' . $row->node_id] ?? null;
        }
        return $rows;
    }
}
