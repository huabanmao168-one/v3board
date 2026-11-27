<?php

namespace App\Http\Middleware;

use Closure;

/**
 * 站点在 CDN 后面时，把来源地址还原成 CDN 带来的访客 IP 请求头，登录、订阅、工单等记录的 IP 才是访客的。
 * 请求头名称由后台设置（各家 CDN 不同，如 CF-Connecting-IP、Ali-Cdn-Real-Ip、X-Real-IP、X-Forwarded-For），留空不处理。
 * 只有 CDN 回源时会覆盖的请求头才可信；绕过 CDN 直连源站的请求可以伪造，源站应只允许 CDN 回源。
 */
class CloudflareRealIp
{
    public function handle($request, Closure $next)
    {
        // 保留改写前的来源地址，供后台「检测请求头」显示
        $request->attributes->set('original_remote_addr', (string) $request->server->get('REMOTE_ADDR'));
        $name = trim((string) config('v2board.client_ip_header', ''));
        // 旧开关：等同于填写 CF-Connecting-IP
        if ($name === '' && (int) config('v2board.trust_cf_connecting_ip', 0) === 1) {
            $name = 'CF-Connecting-IP';
        }
        if ($name !== '' && preg_match('/^[A-Za-z0-9-]{1,64}$/D', $name)) {
            $ip = self::parse((string) $request->headers->get($name));
            if ($ip !== '') {
                $request->server->set('REMOTE_ADDR', $ip);
            }
        }
        return $next($request);
    }

    /** 取列表里的第一个地址（X-Forwarded-For 形式），去掉 IPv4 后面的端口；不是合法 IP 返回空串 */
    public static function parse(string $value): string
    {
        $first = trim(explode(',', $value, 2)[0]);
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d{1,5}$/D', $first, $m)) {
            $first = $m[1];
        } elseif (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::\d{1,5})?$/D', $first, $m)) {
            $first = $m[1];
        }
        return filter_var($first, FILTER_VALIDATE_IP) ? $first : '';
    }
}
