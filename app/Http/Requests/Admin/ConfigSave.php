<?php

namespace App\Http\Requests\Admin;

use App\Services\GeoIpService;
use App\Services\SubscribeIngressService;
use Illuminate\Foundation\Http\FormRequest;

class ConfigSave extends FormRequest
{
    const RULES = [
        // deposit
        'deposit_bounus' => [
            'nullable',
            'array',
        ],
        // invite & commission
        'ticket_status' => 'in:0,1,2',
        'invite_force' => 'in:0,1',
        'invite_commission' => 'integer',
        'invite_gen_limit' => 'integer',
        'invite_never_expire' => 'in:0,1',
        'commission_first_time_enable' => 'in:0,1',
        'commission_auto_check_enable' => 'in:0,1',
        'commission_withdraw_limit' => 'nullable|numeric',
        'commission_withdraw_method' => 'nullable|array',
        'withdraw_close_enable' => 'in:0,1',
        'commission_distribution_enable' => 'in:0,1',
        'commission_distribution_l1' => 'nullable|numeric',
        'commission_distribution_l2' => 'nullable|numeric',
        'commission_distribution_l3' => 'nullable|numeric',
        // site
        'logo' => 'nullable|url',
        'force_https' => 'in:0,1',
        'stop_register' => 'in:0,1',
        'app_name' => '',
        'app_description' => '',
        'app_url' => 'nullable|url',
        'subscribe_url' => 'nullable',
        'subscribe_path' => 'nullable|regex:/^\\//',
        'try_out_enable' => 'in:0,1',
        'try_out_plan_id' => 'integer',
        'try_out_hour' => 'numeric',
        'tos_url' => 'nullable|url',
        'currency' => '',
        'currency_symbol' => '',
        'checkin_enable' => 'in:0,1',
        'lucky_checkin_enable' => 'in:0,1',
        // subscribe
        'plan_change_enable' => 'in:0,1',
        'reset_traffic_method' => 'in:0,1,2,3,4',
        'surplus_enable' => 'in:0,1',
        'allow_new_period' => 'in:0,1',
        'new_order_event_id' => 'in:0,1',
        'renew_order_event_id' => 'in:0,1',
        'change_order_event_id' => 'in:0,1',
        'show_info_to_server_enable' => 'in:0,1',
        'show_subscribe_method' => 'in:0,1,2',
        'show_subscribe_expire' => 'nullable|integer',
        'subscribe_ingress_proxies' => 'nullable|string|max:4096',
        'subscribe_ingress_allow_ips' => 'nullable|string|max:16384',
        'subscribe_ingress_cloud_providers' => 'array',
        'subscribe_ingress_block_ua' => 'nullable|string|max:4096',
        'subscribe_ingress_block_oversea' => 'in:0,1',
        'subscribe_ingress_allow_hk_mo_tw' => 'in:0,1',
        'subscribe_ingress_block_browser' => 'in:0,1',
        'subscribe_ingress_trust_header' => 'in:0,1',
        'trust_cf_connecting_ip' => 'in:0,1',
        'client_ip_header' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]*$/D'],
        'subscribe_display_rules' => 'nullable|string|max:65535|json',
        'subscribe_notice_enable' => 'in:0,1',
        'subscribe_lumi_enable' => 'in:0,1',
        // server
        'server_api_url' => 'nullable|string',
        'server_token' => 'nullable|min:16',
        'server_pull_interval' => 'integer',
        'server_push_interval' => 'integer',
        'device_limit_mode' => 'in:0,1',
        'server_node_report_min_traffic' => 'integer', 
        'server_device_online_min_traffic' => 'integer', 
        'server_status_report_hour' => 'integer',
        // frontend
        'frontend_theme' => '',
        'frontend_theme_sidebar' => 'nullable|in:dark,light',
        'frontend_theme_header' => 'nullable|in:dark,light',
        'frontend_theme_color' => 'nullable|in:default,darkblue,black,green',
        'frontend_background_url' => 'nullable|url',
        // email
        'email_template' => '',
        'email_host' => '',
        'email_port' => '',
        'email_username' => '',
        'email_password' => '',
        'email_encryption' => '',
        'email_from_address' => '',
        'remind_expire_days' => 'integer|min:1|max:365',
        'remind_expire_times' => 'integer|min:1|max:24',
        'remind_traffic_percent' => 'integer|min:1|max:100',
        'remind_expire_default' => 'in:0,1',
        'remind_traffic_default' => 'in:0,1',
        // telegram
        'telegram_bot_enable' => 'in:0,1',
        'telegram_bot_token' => '',
        'telegram_discuss_id' => '',
        'telegram_channel_id' => '',
        'telegram_discuss_link' => 'nullable|url',
        // app
        'windows_version' => '',
        'windows_download_url' => '',
        'macos_version' => '',
        'macos_download_url' => '',
        'android_version' => '',
        'android_download_url' => '',
        // safe
        'email_whitelist_enable' => 'in:0,1',
        'email_whitelist_suffix' => 'nullable|array',
        'email_gmail_limit_enable' => 'in:0,1',
        'recaptcha_enable' => 'in:0,1',
        'recaptcha_key' => '',
        'recaptcha_site_key' => '',
        'email_verify' => 'in:0,1',
        'safe_mode_enable' => 'in:0,1',
        'register_limit_by_ip_enable' => 'in:0,1',
        'register_limit_count' => 'integer',
        'register_limit_expire' => 'integer',
        'secure_path' => 'min:8|regex:/^[\w-]*$/',
        'password_limit_enable' => 'in:0,1',
        'password_limit_count' => 'integer',
        'password_limit_expire' => 'integer',
        // insider guard（风控中心）
        'insider_guard_risk_threshold' => 'integer|min:0|max:100',
        'insider_guard_retention_days' => 'integer|min:1|max:365',
        'insider_guard_auto_notify' => 'in:0,1',
        'insider_guard_auto_restrict' => 'in:0,1',
        'insider_guard_auto_periods' => 'integer|min:1|max:20',
    ];
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = self::RULES;
        $rules['subscribe_ingress_cloud_providers.*'] = 'required|string|distinct|in:'
            . implode(',', GeoIpService::cloudProviders());


        $rules['deposit_bounus'][] = function ($attribute, $value, $fail) {
            foreach ($value as $tier) {
                if (!preg_match('/^\d+(\.\d+)?:\d+(\.\d+)?$/', $tier)) {
                    if($tier == '') {
                        continue;
                    }
                    $fail('充值奖励格式不正确，必须为充值金额:奖励金额');
                }
            }
        };
        foreach (['subscribe_ingress_proxies', 'subscribe_ingress_allow_ips'] as $field) {
            $rules[$field] = array_merge(explode('|', $rules[$field]), [function ($attribute, $value, $fail) {
                if (!is_string($value)) {
                    return;
                }
                foreach (SubscribeIngressService::entries($value) as $entry) {
                    $parts = explode('/', $entry, 2);
                    $address = filter_var($parts[0], FILTER_VALIDATE_IP);
                    $max = strpos($parts[0], ':') === false ? 32 : 128;
                    if (!$address || (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > $max))) {
                        $fail('请输入有效的 IPv4、IPv6 或 CIDR 地址');
                        return;
                    }
                }
            }]);
        }
        $rules['subscribe_display_rules'] = array_merge(explode('|', $rules['subscribe_display_rules']), [function ($attribute, $value, $fail) {
            $rules = is_string($value) ? json_decode($value) : null;
            if (!is_array($rules)) {
                $fail('展示规则必须是 JSON 数组');
                return;
            }
            foreach ($rules as $rule) {
                if (!is_object($rule) || !in_array($rule->match ?? null, ['all', 'email_exact', 'email_suffix', 'email_keyword', 'ua_contains', 'ua_not_contains'], true)
                    || ($rule->match !== 'all' && (!is_string($rule->value ?? null) || trim($rule->value) === ''))
                    || (isset($rule->title) && !is_string($rule->title))
                    || (isset($rule->replacements) && !is_object($rule->replacements))) {
                    $fail('展示规则的匹配方式、匹配值、标题或替换项无效');
                    return;
                }
                foreach ($rule->replacements ?? [] as $from => $to) {
                    if ($from === '' || !is_string($to)) {
                        $fail('展示规则的替换项必须由非空文本和替换文本组成');
                        return;
                    }
                }
            }
        }]);
        return $rules;
    }

    public function messages()
    {
        // illiteracy prompt
        return [
            'app_url.url' => '站点URL格式不正确，必须携带http(s)://',
            'subscribe_url.url' => '订阅URL格式不正确，必须携带http(s)://',
            'subscribe_path.regex' => '订阅路径必须以/开头',
            'server_token.min' => '通讯密钥长度必须大于16位',
            'tos_url.url' => '服务条款URL格式不正确，必须携带http(s)://',
            'telegram_discuss_link.url' => 'Telegram群组地址必须为URL格式，必须携带http(s)://',
            'logo.url' => 'LOGO URL格式不正确，必须携带https(s)://',
            'secure_path.min' => '后台路径长度最小为8位',
            'secure_path.regex' => '后台路径只能为字母或数字',
        ];
    }
}
