<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SubscribeIngressService
{
    private const CLIENTS = 'clash,mihomo,v2ray,sing-box,singbox,shadowrocket,shadowsocks,quantumult,surge,loon,stash,neko,hiddify,streisand,surfboard,lumi';

    public static function enforce(Request $request): void
    {
        $proxies = self::entries(config('v2board.subscribe_ingress_proxies'));
        $peer = (string) $request->server('REMOTE_ADDR');
        $trusted = IpUtils::checkIp($peer, $proxies);
        // Without ingress proxies this is the TrustProxies-resolved address, the same one the
        // activity log records. It is only as trustworthy as TrustProxies::$proxies: a wildcard
        // there lets a direct client pick its own address via X-Forwarded-For.
        $ip = $proxies ? (string) $request->header('X-Subscription-Client-IP') : (string) $request->ip();
        if (self::trustsHeader()) {
            // Header-trust mode: the relay's header is taken at face value whatever the TCP peer is
            // (the peer is a CDN edge when the relay goes through one). Anyone who can reach this
            // endpoint can forge it; requests without a valid header use the resolved address.
            $header = self::headerIp($request);
            $trusted = $header !== '';
            $ip = $trusted ? $header : (string) $request->ip();
        } elseif ($proxies && (!$trusted || !filter_var($ip, FILTER_VALIDATE_IP))) {
            self::deny($request, $peer, 'untrusted_ingress', 404);
        }
        $request->attributes->set('subscription_client_ip', $ip);
        if (IpUtils::checkIp($ip, self::entries(config('v2board.subscribe_ingress_allow_ips')))) {
            return;
        }

        $geo = GeoIpService::lookup($ip);
        $request->attributes->set('subscription_geoip', $geo);
        $ua = (string) $request->userAgent();
        if (self::containsAny($ua, self::entries(config('v2board.subscribe_ingress_block_ua')))) {
            self::deny($request, $ip, 'ua_blacklist');
        }
        if (config('v2board.subscribe_ingress_block_oversea')) {
            $country = GeoIpService::countryCode($geo);
            if ($country === '' && $trusted) {
                $country = strtoupper(trim((string) $request->header('X-Subscription-Country')));
            }
            $allowed = config('v2board.subscribe_ingress_allow_hk_mo_tw') ? ['CN', 'HK', 'MO', 'TW'] : ['CN'];
            if (!in_array($country, $allowed, true)) {
                self::deny($request, $ip, $country !== 'XX' && preg_match('/^[A-Z]{2}$/D', $country) ? 'oversea:' . $country : 'oversea:unknown');
            }
        }
        $provider = $geo['cloud_provider'] ?? '';
        if ($provider !== '' && in_array($provider, GeoIpService::blockedCloudProviders(), true)) {
            self::deny($request, $ip, 'cloud_ip:' . $provider);
        }
        if (config('v2board.subscribe_ingress_cloud_providers') === null
            && IpUtils::checkIp($ip, self::entries(config('v2board.subscribe_ingress_cloud_ips')))) {
            self::deny($request, $ip, 'cloud_ip');
        }
        if (config('v2board.subscribe_ingress_block_browser')
            && stripos((string) $request->header('Accept'), 'text/html') !== false
            && !self::containsAny($ua, explode(',', self::CLIENTS))) {
            self::deny($request, $ip, 'browser');
        }
    }

    public static function ipLocation(Request $request, string $ip): ?string
    {
        if ($ip === '') {
            return null;
        }
        if (self::trustsHeader()) {
            if ($ip !== self::headerIp($request)) {
                return null;
            }
        } elseif ($ip !== $request->header('X-Subscription-Client-IP')
            || !IpUtils::checkIp((string) $request->server('REMOTE_ADDR'), self::entries(config('v2board.subscribe_ingress_proxies')))) {
            return null;
        }
        $country = strtoupper(trim((string) $request->header('X-Subscription-Country')));
        if (!preg_match('/^[A-Z]{2}$/D', $country) || $country === 'XX') {
            return null;
        }
        $parts = [$country];
        foreach (['X-Subscription-Region', 'X-Subscription-City'] as $header) {
            $value = trim((string) $request->header($header));
            if ($value !== '' && preg_match('/^[^\p{C}]+$/uD', $value)) {
                $parts[] = mb_substr($value, 0, 60);
            }
        }
        return implode(' · ', array_unique($parts));
    }

    public static function trustsHeader(): bool
    {
        return (int) config('v2board.subscribe_ingress_trust_header', 0) === 1;
    }

    /** Client IP from the relay headers (the Sub-Panel header name is accepted too); '' when absent or invalid. */
    private static function headerIp(Request $request): string
    {
        foreach (['X-Subscription-Client-IP', 'X-Sub-Panel-Client-IP'] as $name) {
            $value = trim((string) $request->header($name));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }
        return '';
    }

    public static function entries($value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string) $value)), 'strlen'));
    }

    private static function containsAny(string $value, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword !== '' && stripos($value, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function deny(Request $request, string $ip, string $reason, int $status = 403): void
    {
        try {
            $now = time();
            $geo = $request->attributes->get('subscription_geoip', []);
            DB::table('v2_insider_guard_subscription_block')->upsert([[
                'hour_start' => $now - $now % 3600,
                'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ? inet_ntop(inet_pton($ip)) : '',
                'ip_location' => GeoIpService::location($geo)
                    ?? ($reason === 'untrusted_ingress' ? null : self::ipLocation($request, $ip)),
                'ua' => mb_substr(mb_convert_encoding((string) $request->userAgent(), 'UTF-8', 'UTF-8'), 0, 512),
                'reason' => $reason,
                'status' => $status,
                'times' => 1,
                'last_at' => $now,
            ]], ['hour_start', 'ip_address', 'reason'], [
                'ip_location', 'ua', 'last_at', 'times' => DB::raw('times + 1'),
            ]);
        } catch (\Throwable $e) {
            Log::notice('Subscription request blocked', ['ip' => $ip, 'reason' => $reason]);
        }
        throw new HttpException($status);
    }
}
