<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\ClashNyanpasu;
use App\Protocols\ClashVerge;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Services\InsiderGuardService;
use App\Services\LumiSubscribeService;
use App\Services\ServerService;
use App\Services\SubscribeDisplayService;
use App\Services\SubscriptionNotice;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $userAgent = (string) $request->userAgent();
        $user = $request->user;
        if ($user->banned) {
            abort(403, '账号已被封禁');
        }
        $notice = SubscriptionNotice::forUser($user);
        if ($notice !== null && !config('v2board.subscribe_notice_enable', 1)) {
            return response('', 403);
        }
        $display = $notice === null ? SubscribeDisplayService::matchRule($request) : [];
        $response = $this->buildSubscribe($request, $userAgent, $display, $notice);
        $response = SubscribeDisplayService::rewriteResponse($response, $display);
        if (config('v2board.subscribe_lumi_enable', false) && stripos($userAgent, 'lumi') !== false) {
            return LumiSubscribeService::encryptResponse($response, $request->input('token'));
        }
        return $response;
    }

    private function buildSubscribe(Request $request, $userAgent, array $display, ?string $notice)
    {
        $explicitFlag = $request->input('flag');
        $flag = strtolower($explicitFlag ?? $userAgent);
        // 显式 flag 参数沿用 sing 匹配；UA 只认 sing-box / singbox，不再按 sing 子串匹配
        $isSingBox = $explicitFlag !== null
            ? strpos($flag, 'sing') !== false
            : preg_match('/sing[-_ ]?box/', $flag) === 1;
        $user = $request->user;
        if ($notice === null) {
            $servers = (new ServerService())->getAvailableServers($user);
            $this->applyDomainRewriteRules($servers, $flag);
            $this->applyNodeWhitelistRules($servers, $flag);
            InsiderGuardService::recordSubscribe($user, $request, $servers);
        } else {
            $user = clone $user;
            $user->uuid = SubscriptionNotice::PASSWORD;
            $servers = [SubscriptionNotice::node($notice)];
        }

        $protocol = null;
        $version = null;
        if ($flag) {
            if (!$isSingBox) {
                if ($notice === null) {
                    $this->setSubscribeInfoToServers($servers, $user);
                }
                foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                    $file = 'App\\Protocols\\' . basename($file, '.php');
                    if (strpos($flag, get_class_vars($file)['flag']) !== false) {
                        $protocol = $file;
                        break;
                    }
                }
            } else {
                // 保留预发布后缀（1.13.0-beta.2），后续按版本下发新字段时才不会把预发布版当成正式版
                if (preg_match('/sing-box\s+([0-9]+(?:\.[0-9]+)*(?:-[0-9A-Za-z.]+)?)/i', $flag, $matches)) {
                    $version = $matches[1];
                }
                if (!is_null($version) && $version >= '1.12.0') {
                    $protocol = Singbox::class;
                } else {
                    $protocol = SingboxOld::class;
                }
            }
        }

        $this->applySelfSignedFallback($servers, $protocol, $version);
        if (!empty($display['replacements'])) {
            SubscribeDisplayService::renameNodes($servers, $display);
        }

        if ($protocol === Singbox::class) {
            return (new Singbox($user, $servers, ['version' => $version]))->handle();
        }
        $protocol = $protocol ?? General::class;
        return (new $protocol($user, $servers))->handle();
    }

    /**
     * 上游做法：Clash 系、sing-box 订阅里「自签名（面板下发）」证书的 v2node 节点改成允许不安全，否则连不上。
     * 本仓库能给客户端下发证书固定的（mihomo 的 fingerprint、sing-box 1.13+ 的公钥固定）保持原样，pin 照常校验；
     */
    private function applySelfSignedFallback(array &$servers, ?string $protocol, ?string $version)
    {
        if (!in_array($protocol, [
            Clash::class, ClashMeta::class, ClashNyanpasu::class, ClashVerge::class,
            Singbox::class, SingboxOld::class,
        ], true)) {
            return;
        }
        foreach ($servers as $key => $server) {
            if ($server['type'] !== 'v2node' || (int)$server['tls'] !== 1
                || ($server['tls_settings']['cert_mode'] ?? null) !== 'remote') {
                continue;
            }
            if (in_array($protocol, [ClashMeta::class, ClashNyanpasu::class, ClashVerge::class], true)
                && Helper::clientPinSha256($server) !== '') {
                continue;
            }
            if ($protocol === Singbox::class && version_compare($version, '1.13.0', '>=')
                && Helper::pinnedPublicKeySha256($server) !== '') {
                continue;
            }
            $servers[$key]['tls_settings']['allow_insecure'] = 1;
            if ($server['protocol'] === 'vmess') {
                // Clash Meta's VMess builders read the legacy spelling.
                $servers[$key]['tls_settings']['allowInsecure'] = 1;
            }
        }
    }

    private function applyDomainRewriteRules(&$servers, $ua)
    {
        $rules = config('v2board.subscribe_domain_rewrite_rules', []);
        if (empty($rules) || empty($ua)) return;

        $matchedRules = [];
        foreach ($rules as $rule) {
            if (empty($rule['ua']) || empty($rule['domain']) || empty($rule['ip'])) continue;
            if (stripos($ua, $rule['ua']) !== false) {
                $matchedRules[] = $rule;
            }
        }
        if (empty($matchedRules)) return;

        $addressFields = ['host', 'server', 'address'];
        foreach ($servers as &$server) {
            foreach ($matchedRules as $rule) {
                foreach ($addressFields as $field) {
                    if (isset($server[$field]) && strtolower($server[$field]) === strtolower($rule['domain'])) {
                        $server[$field] = $rule['ip'];
                    }
                }
            }
        }
        unset($server);
    }

    private function applyNodeWhitelistRules(&$servers, $ua)
    {
        $rules = config('v2board.subscribe_node_whitelist_rules', []);
        if (empty($rules) || !is_array($rules)) return;

        // Build map: "type:id" => [allowed UA keyword, ...]
        $restricted = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            $ruleUa = isset($rule['ua']) ? trim((string)$rule['ua']) : '';
            if ($ruleUa === '') continue;
            if (empty($rule['nodes']) || !is_array($rule['nodes'])) continue;
            foreach ($rule['nodes'] as $node) {
                if (!is_array($node) || empty($node['type']) || !isset($node['id'])) continue;
                $key = $node['type'] . ':' . (int)$node['id'];
                $restricted[$key][] = $ruleUa;
            }
        }
        if (empty($restricted)) return;

        $servers = array_values(array_filter($servers, function ($server) use ($restricted, $ua) {
            $key = ($server['type'] ?? '') . ':' . (isset($server['id']) ? (int)$server['id'] : '');
            if (!isset($restricted[$key])) {
                return true;
            }
            if (empty($ua)) {
                return false;
            }
            foreach ($restricted[$key] as $allowedUa) {
                if ($allowedUa === '') continue;
                if (stripos($ua, $allowedUa) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}
