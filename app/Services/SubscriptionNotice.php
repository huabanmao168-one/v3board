<?php

namespace App\Services;

use App\Models\User;

class SubscriptionNotice
{
    const PASSWORD = 'subscription-notice-only';

    public static function forUser(User $user): ?string
    {
        if (InsiderGuardService::isRestricted((int) $user->id)) {
            $notice = '订阅已被限制，请联系管理员';
        } else {
            $notice = self::planNotice($user, time());
        }
        return $notice;
    }

    public static function planNotice(User $user, int $now): ?string
    {
        if (!$user->plan_id) {
            return '暂无有效套餐，请先订购';
        }
        if ($user->expired_at !== null && (int) $user->expired_at <= $now) {
            return '套餐已到期，请续费';
        }
        if ((int) $user->u + (int) $user->d >= (int) $user->transfer_enable) {
            return '流量已用完，请重置流量或续费';
        }
        return null;
    }

    public static function node(string $message): array
    {
        return [
            'id' => '00000000-0000-4000-8000-000000000001',
            'name' => $message,
            'type' => 'shadowsocks',
            'host' => 'subscription-notice.invalid',
            'port' => 443,
            'cipher' => 'aes-128-gcm',
            'obfs' => null,
        ];
    }
}
