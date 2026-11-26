<?php

namespace App\Services;

use ip2region\xdb\IPv4;
use ip2region\xdb\IPv6;
use ip2region\xdb\Searcher;
use ip2region\xdb\Util;

class GeoIpService
{
    private const CLOUD = [
        'aliyun' => '阿里,aliyun,alibaba', 'tencent' => '腾讯,tencent', 'huawei' => '华为,huawei',
        'bytedance' => '字节,火山,bytedance', 'baidu' => '百度,baidu', 'jdcloud' => '京东',
        'kingsoft' => '金山', 'ucloud' => 'ucloud', 'qingcloud' => '青云,qingcloud',
        'aws' => '亚马逊,amazon,aws', 'azure' => '微软,microsoft,azure', 'gcp' => '谷歌,google',
        'oracle' => 'oracle,甲骨文', 'vultr' => 'vultr', 'digitalocean' => 'digitalocean',
        'linode' => 'linode', 'hetzner' => 'hetzner', 'ovh' => 'ovh', 'akamai' => 'akamai',
        'cloudflare' => 'cloudflare', 'fastly' => 'fastly',
    ];

    public static function cloudProviders(): array
    {
        return array_keys(self::CLOUD);
    }

    public static function blockedCloudProviders(): array
    {
        return config('v2board.subscribe_ingress_cloud_providers')
            ?? (config('v2board.subscribe_ingress_block_cloud') ? self::cloudProviders() : []);
    }

    private static function path(int $version): string
    {
        return storage_path('app/geoip/ip2region_v' . $version . '.xdb');
    }

    public static function status(): array
    {
        return ['ipv4' => self::databaseStatus(4), 'ipv6' => self::databaseStatus(6)];
    }

    public static function databaseStatus(int $version): string
    {
        $path = self::path($version);
        if (!is_file($path)) {
            return 'missing';
        }
        if (!is_readable($path)) {
            return 'unreadable';
        }
        require_once base_path('library/ip2region/Searcher.class.php');
        try {
            $header = Util::loadHeaderFromFile($path);
            if (!$header || Util::verifyFromFile($path) !== null) {
                return 'invalid';
            }
            // Legacy XDB structure 2 is IPv4 only.
            $family = $header['version'] === 2 ? 4 : $header['ipVersion'];
            if ($family !== $version) {
                return 'wrong_family';
            }
        } catch (\Throwable $e) {
            return 'invalid';
        }
        $probe = $version === 4 ? '223.5.5.5' : '2001:4860:4860::8888';
        return self::lookup($probe) ? 'ready' : 'probe_failed';
    }

    public static function lookup(string $ip): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return [];
        }
        $v6 = strpos($ip, ':') !== false;
        $path = self::path($v6 ? 6 : 4);
        if (!is_readable($path)) {
            return [];
        }
        require_once base_path('library/ip2region/Searcher.class.php');
        try {
            $searcher = Searcher::newWithFileOnly($v6 ? IPv6::default() : IPv4::default(), $path);
            try {
                $raw = $searcher->search($ip);
            } finally {
                $searcher->close();
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $raw ? self::parse($raw) : [];
    }

    public static function parse(string $raw): array
    {
        $parts = array_map(static function ($field) {
            $field = trim($field);
            return $field === '0' ? '' : $field;
        }, explode('|', $raw));
        $keys = ['continent', 'country', 'province', 'city', 'district', 'isp', 'lon', 'lat', 'area_code', 'dial_code', 'zip_code', 'timezone', 'currency', 'asn', 'usage_type'];
        $iso = strtoupper(end($parts));
        $iso = preg_match('/^[A-Z]{2}$/D', $iso) ? $iso : '';
        if (count($parts) >= count($keys)) {
            $info = array_combine($keys, array_slice($parts, 0, count($keys)));
        } elseif (count($parts) === 5) {
            $fields = $iso !== '' ? ['country', 'province', 'city', 'isp', 'iso_code'] : ['country', 'region', 'province', 'city', 'isp'];
            $info = array_combine($fields, $parts);
        } else {
            return [];
        }
        $info['iso_code'] = $iso;
        $info['cloud_provider'] = '';
        foreach (self::CLOUD as $provider => $keywords) {
            foreach (explode(',', $keywords) as $keyword) {
                if (stripos($info['isp'], $keyword) !== false) {
                    $info['cloud_provider'] = $provider;
                    return $info;
                }
            }
        }
        return $info;
    }

    public static function countryCode(array $info): string
    {
        $iso = strtoupper(trim($info['iso_code'] ?? ''));
        if ($iso !== '' && $iso !== 'CN' && $iso !== 'XX') {
            return $iso;
        }
        $region = implode(' ', [$info['country'] ?? '', $info['region'] ?? '', $info['province'] ?? '', $info['city'] ?? '']);
        foreach (['HK' => '/香港|Hong\s*Kong/iu', 'MO' => '/澳门|澳門|Maca[ou]/iu', 'TW' => '/台湾|臺灣|台灣|Taiwan/iu'] as $code => $pattern) {
            if (preg_match($pattern, $region)) {
                return $code;
            }
        }
        return $iso === 'CN' || in_array(trim($info['country'] ?? ''), ['中国', '中國', 'China'], true) ? 'CN' : '';
    }

    public static function location(array $info): ?string
    {
        $parts = array_filter([$info['country'] ?? '', $info['province'] ?? '', $info['city'] ?? '', $info['district'] ?? '']);
        return $parts ? mb_substr(implode(' · ', array_unique($parts)), 0, 160) : null;
    }
}
