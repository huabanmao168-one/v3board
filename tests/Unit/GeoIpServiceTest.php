<?php

namespace Tests\Unit;

use App\Services\GeoIpService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GeoIpServiceTest extends TestCase
{
    #[DataProvider('records')]
    public function testCountryCode(string $raw, string $expected): void
    {
        $this->assertSame($expected, GeoIpService::countryCode(GeoIpService::parse($raw)));
    }

    public static function records(): array
    {
        return [
            'legacy mainland' => ['中国|0|广东省|深圳市|电信', 'CN'],
            'legacy hong kong as province' => ['中国|0|香港|0|电讯盈科', 'HK'],
            'legacy taiwan province' => ['中国|0|台湾省|台北市|中华电信', 'TW'],
            'legacy macau as country' => ['澳门|0|0|0|澳门电讯', 'MO'],
            'new format with iso' => ['日本|东京都|东京|NTT|JP', 'JP'],
            'new format china iso, hong kong province' => ['中国|香港|0|PCCW|CN', 'HK'],
            'foreign city named macau without iso' => ['France|0|Gironde|Macau|Orange', ''],
            'foreign city named macau, unknown iso' => ['Brazil|0|Rio Grande do Norte|Macau|Claro', ''],
            'unknown' => ['0|0|0|内网IP|内网IP', ''],
        ];
    }
}
