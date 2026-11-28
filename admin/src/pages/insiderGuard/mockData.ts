// 风控中心的 Mock 数据，用于开发预览
import type { GuardOverview, GuardRiskUser, GuardEvent, GuardUserDetail, GuardRestrictedUser, GuardWatchedUser, GuardWhitelistUser } from '@/api/types'

export const mockOverview: GuardOverview = {
  recent_events: 1247,
  reviews: 23,
  restricted: 5,
  top_ips: [
    { ip_address: '103.45.67.89', ip_location: '香港', ip_isp: 'HKT', requests: 234 },
    { ip_address: '45.123.78.12', ip_location: '新加坡', ip_isp: 'Singtel', requests: 189 },
    { ip_address: '218.76.34.90', ip_location: '北京 联通', ip_isp: '中国联通', requests: 156 },
  ],
}

export const mockRiskUsers: GuardRiskUser[] = [
  {
    user_id: 10001,
    email: 'user1@example.com',
    ua: 'clash-verge/1.3.8',
    pulls: 45,
    ips: 8,
    score: 75,
    high_risk: true,
    restricted: false,
    last_seen: Date.now() / 1000 - 300,
  },
  {
    user_id: 10002,
    email: 'user2@example.com',
    ua: 'Shadowrocket/2.2.33',
    pulls: 32,
    ips: 5,
    score: 50,
    high_risk: true,
    restricted: false,
    last_seen: Date.now() / 1000 - 600,
  },
  {
    user_id: 10003,
    email: 'user3@example.com',
    ua: 'sing-box/1.8.0',
    pulls: 28,
    ips: 4,
    score: 42,
    high_risk: false,
    restricted: true,
    last_seen: Date.now() / 1000 - 1200,
  },
]

export const mockEvents: GuardEvent[] = [
  {
    id: 1,
    user_id: 10001,
    email: 'user1@example.com',
    event_type: 'subscription',
    ip_address: '103.45.67.89',
    ip_location: '香港',
    ip_isp: 'HKT',
    ip_usage: '家宽',
    ua_hash: 'abc123',
    ua: 'clash-verge/1.3.8',
    times: 12,
    node_count: 5,
    first_at: Date.now() / 1000 - 3000,
    last_at: Date.now() / 1000 - 300,
  },
  {
    id: 2,
    user_id: 10001,
    email: 'user1@example.com',
    event_type: 'login',
    ip_address: '45.123.78.12',
    ip_location: '新加坡',
    ip_isp: 'Singtel',
    ip_usage: null,
    ua_hash: 'def456',
    ua: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
    times: 3,
    node_count: null,
    first_at: Date.now() / 1000 - 7200,
    last_at: Date.now() / 1000 - 1800,
  },
]

export const mockUserDetail: GuardUserDetail = {
  user_id: 10001,
  email: 'user1@example.com',
  banned: 0,
  risk: {
    score: 75,
    subscription_requests_1h: 15,
    distinct_ips_1h: 3,
    manual_review_required: true,
  },
  restriction: null,
  events: mockEvents,
  deliveries: [
    {
      node_type: 'vmess',
      node_id: 101,
      node_name: '香港 01',
      times: 45,
      first_at: Date.now() / 1000 - 86400,
      last_at: Date.now() / 1000 - 300,
    },
    {
      node_type: 'trojan',
      node_id: 102,
      node_name: '新加坡 01',
      times: 32,
      first_at: Date.now() / 1000 - 72000,
      last_at: Date.now() / 1000 - 600,
    },
  ],
  reviews: [
    {
      id: 1,
      user_id: 10001,
      admin_id: 1,
      action: 'watch',
      reason: '短时间内多个 IP 频繁拉取订阅，先观察一段时间看是否正常使用',
      created_at: Date.now() / 1000 - 86400,
    },
  ],
}

export const mockRestrictedUsers: GuardRestrictedUser[] = [
  {
    user_id: 10003,
    email: 'user3@example.com',
    admin_id: 1,
    reason: '订阅链接在公开渠道流传，已确认外泄',
    created_at: Date.now() / 1000 - 172800,
  },
]

export const mockWatchedUsers: GuardWatchedUser[] = [
  {
    user_id: 10001,
    email: 'user1@example.com',
    admin_id: 1,
    reason: '短时间内多个 IP 频繁拉取订阅，先观察',
    created_at: Date.now() / 1000 - 86400,
    risk_score: 75,
  },
]

export const mockWhitelistUsers: GuardWhitelistUser[] = [
  {
    user_id: 10005,
    email: 'vip@example.com',
    admin_id: 1,
    reason: 'VIP 用户，多设备使用正常',
    created_at: Date.now() / 1000 - 259200,
  },
]

export const mockGuardConfig = {
  client_ip_header: 'CF-Connecting-IP',
  subscribe_ingress_proxies: '10.0.0.1\n10.0.0.2',
  subscribe_ingress_allow_ips: '',
  subscribe_ingress_block_ua: 'curl\nwget',
  subscribe_ingress_block_oversea: 0,
  subscribe_ingress_allow_hk_mo_tw: 1,
  subscribe_ingress_block_browser: 1,
  subscribe_ingress_trust_header: 0,
  subscribe_ingress_cloud_providers: ['aliyun', 'tencent'],
  insider_guard_risk_threshold: 50,
  insider_guard_retention_days: 3,
  insider_guard_auto_notify: 1,
  insider_guard_auto_restrict: 0,
  insider_guard_auto_periods: 3,
  cloud_provider_options: ['aliyun', 'tencent', 'huawei', 'aws', 'gcp', 'azure', 'cloudflare'],
}
