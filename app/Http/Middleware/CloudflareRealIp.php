<?php

namespace App\Http\Middleware;

use Closure;

/**
 * 站点在 Cloudflare 后面时，把来源地址还原成 CF-Connecting-IP，登录、订阅、工单等记录的 IP 才是访客的。
 * Cloudflare 回源时总会覆盖这个请求头，经过 Cloudflare 的请求伪造不了；绕过 Cloudflare 直连源站的请求可以伪造，
 * 所以只有开启开关后才生效，源站应只允许 Cloudflare 回源。
 */
class CloudflareRealIp
{
    public function handle($request, Closure $next)
    {
        if ((int) config('v2board.trust_cf_connecting_ip', 0) === 1) {
            $ip = trim((string) $request->headers->get('CF-Connecting-IP'));
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                $request->server->set('REMOTE_ADDR', $ip);
            }
        }
        return $next($request);
    }
}
