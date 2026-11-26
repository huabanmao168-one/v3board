// 风控设置（独占一个标签页）：config/fetch?key=insider_guard 读取初值，整组提交 config/save
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Button, Input, InputNumber, Select, Switch } from 'antd'
import { useState, type ReactNode } from 'react'
import { unwrap } from '@/api/request'
import { fetchConfig, saveConfig } from '@/api/services/config'
import { message } from '@/app/staticApi'
import { Loading } from '@/components/Loading'
import { useGeoIpStatus } from './GeoIpPanel'
import { Failed, guardKeys, MUTED, Note, Section } from './shared'

const CLOUD_LABELS: Record<string, string> = {
  aliyun: '阿里云', tencent: '腾讯云', huawei: '华为云', bytedance: '火山引擎', baidu: '百度智能云',
  jdcloud: '京东云', kingsoft: '金山云', ucloud: 'UCloud', qingcloud: '青云',
  aws: 'AWS', azure: 'Microsoft Azure', gcp: 'Google Cloud', oracle: 'Oracle Cloud',
  vultr: 'Vultr', digitalocean: 'DigitalOcean', linode: 'Linode', hetzner: 'Hetzner',
  ovh: 'OVHcloud', akamai: 'Akamai', cloudflare: 'Cloudflare', fastly: 'Fastly',
}

const ingressLists = [
  ['subscribe_ingress_proxies', '订阅入口可信代理', '填写 Nginx 来源 IP 或网段，每行一个。配置后仅接受这些代理。'],
  ['subscribe_ingress_allow_ips', '订阅入口 IP 例外', '填写允许跳过入口拦截的 IP 或网段，每行一个。'],
  ['subscribe_ingress_block_ua', '禁止的 UA 关键词', '每行一个关键词，忽略大小写。'],
] as const

const ingressSwitches = [
  ['subscribe_ingress_block_oversea', '禁止海外 IP 拉取', '使用可信代理提供的地区代码。缺少地区信息时拒绝。'],
  ['subscribe_ingress_allow_hk_mo_tw', '允许港澳台 IP', '开启海外限制时，同时允许 HK、MO、TW。'],
  ['subscribe_ingress_block_browser', '禁止浏览器直访', '拦截请求 HTML 的浏览器，常见订阅客户端除外。'],
] as const

type IngressListKey = (typeof ingressLists)[number][0]
type IngressSwitchKey = (typeof ingressSwitches)[number][0]

interface GuardConfig extends Record<IngressListKey, string>, Record<IngressSwitchKey, number> {
  subscribe_ingress_cloud_providers: string[]
  /** 高风险阈值：评分达到该值标为高风险 */
  insider_guard_risk_threshold: number
  /** 审计记录保留天数 1～365 */
  insider_guard_retention_days: number
}

type NumberKey = Exclude<keyof GuardConfig, 'subscribe_ingress_cloud_providers' | IngressListKey>

const DEFAULTS: GuardConfig = {
  subscribe_ingress_cloud_providers: [],
  subscribe_ingress_proxies: '',
  subscribe_ingress_allow_ips: '',
  subscribe_ingress_block_ua: '',
  subscribe_ingress_block_oversea: 0,
  subscribe_ingress_allow_hk_mo_tw: 0,
  subscribe_ingress_block_browser: 0,
  insider_guard_risk_threshold: 50,
  insider_guard_retention_days: 3,
}

/** 后端返回的值可能是字符串，统一转成整数；不是数字时用默认值 */
function normalize(raw: Record<string, unknown> | undefined): GuardConfig {
  const int = (key: NumberKey) => {
    const value = Number.parseInt(String(raw?.[key] ?? ''), 10)
    return Number.isNaN(value) ? DEFAULTS[key] : value
  }
  const providers = raw?.subscribe_ingress_cloud_providers
  return {
    subscribe_ingress_cloud_providers: Array.isArray(providers) ? providers.filter((value): value is string => typeof value === 'string') : [],
    subscribe_ingress_proxies: String(raw?.subscribe_ingress_proxies ?? ''),
    subscribe_ingress_allow_ips: String(raw?.subscribe_ingress_allow_ips ?? ''),
    subscribe_ingress_block_ua: String(raw?.subscribe_ingress_block_ua ?? ''),
    subscribe_ingress_block_oversea: int('subscribe_ingress_block_oversea'),
    subscribe_ingress_allow_hk_mo_tw: int('subscribe_ingress_allow_hk_mo_tw'),
    subscribe_ingress_block_browser: int('subscribe_ingress_block_browser'),
    insider_guard_risk_threshold: int('insider_guard_risk_threshold'),
    insider_guard_retention_days: int('insider_guard_retention_days'),
  }
}

/** 输入框后面的单位 */
function Unit({ children }: { children: ReactNode }) {
  return <span style={{ ...MUTED, marginLeft: 8 }}>{children}</span>
}

/** 一行设置：左边标题和说明，右边控件（与系统配置页同一种左文右控件的布局） */
function Row({ title, description, children }: { title: string; description: string; children: ReactNode }) {
  return (
    <div className="row" style={{ padding: '16px 0', borderBottom: '1px solid var(--v2b-line-soft, #eee)' }}>
      <div className="col-lg-8">
        <div style={{ fontWeight: 'bold', marginBottom: 5 }}>{title}</div>
        <div style={MUTED}>{description}</div>
      </div>
      <div className="col-lg-4 text-right">{children}</div>
    </div>
  )
}

export function GuardSettings() {
  const config = useQuery({
    queryKey: guardKeys.config,
    queryFn: () => unwrap(fetchConfig('insider_guard')),
  })
  const retry = (
    <Button size="small" loading={config.isFetching} onClick={() => void config.refetch()}>
      重试
    </Button>
  )
  return (
    <Section>
      <Loading loading={config.isLoading}>
        {config.data ? (
          <>
            {/* 已经读到过配置、重新读取失败：表单不重建，保留当前显示的值（含未保存的修改） */}
            {config.isError && <Note style={{ padding: '12px 0 0' }}>重新读取失败，表单保留当前显示的值 {retry}</Note>}
            {/* 重新读取到配置时（key 变化）按新值重建表单，覆盖未保存的修改 */}
            <SettingsForm key={config.dataUpdatedAt} initial={normalize(config.data.insider_guard)} raw={config.data.insider_guard} />
          </>
        ) : config.isError ? (
          <Failed>读取失败：风控设置暂时无法加载 {retry}</Failed>
        ) : (
          <div style={{ minHeight: 120 }} />
        )}
      </Loading>
    </Section>
  )
}

function SettingsForm({ initial, raw }: { initial: GuardConfig; raw?: Record<string, unknown> }) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState<GuardConfig>(initial)
  const [saving, setSaving] = useState(false)
  const geoIp = useGeoIpStatus()
  const geoRulesEnabled = form.subscribe_ingress_block_oversea === 1 || form.subscribe_ingress_cloud_providers.length > 0
  const missingFamilies = geoIp.data ? (['ipv4', 'ipv6'] as const).filter((family) => geoIp.data[family] !== 'ready') : []
  const providerOptions = Array.isArray(raw?.cloud_provider_options)
    ? raw.cloud_provider_options.filter((value): value is string => typeof value === 'string')
    : []


  const set = <K extends keyof GuardConfig>(key: K, value: GuardConfig[K] | null) => {
    if (value === null) return
    setForm((f) => ({ ...f, [key]: value }))
  }

  const save = async () => {
    if (form.insider_guard_retention_days < 1 || form.insider_guard_retention_days > 365) {
      return message.error('审计保留天数需在 1～365 之间')
    }
    setSaving(true)
    const res = await saveConfig(form, true)
    setSaving(false)
    if (res.code !== 200 || res.data !== true) return
    message.success('保存成功')
    // 阈值影响风险用户的高风险判定与用户详情里的「需人工复核」
    void queryClient.invalidateQueries({ queryKey: guardKeys.config })
    void queryClient.invalidateQueries({ queryKey: guardKeys.usersAll })
    void queryClient.invalidateQueries({ queryKey: guardKeys.userDetail })
  }

  return (
    <>
      <h4 style={{ marginTop: 16 }}>订阅入口防护</h4>
      {geoRulesEnabled && missingFamilies.length > 0 && (
        <Note style={{ marginTop: 8 }}>
          {missingFamilies.map((family) => family === 'ipv4' ? 'IPv4' : 'IPv6').join('、')} 本地 GeoIP 库尚未通过查询检查。
          云厂商规则依赖本地库。海外限制可用可信 Nginx 提供的地区代码，缺少地区代码时会拒绝请求。
        </Note>
      )}
      {ingressSwitches.map(([key, title, description]) => (
        <Row key={key} title={title} description={description}>
          <Switch aria-label={title} checked={form[key] === 1} onChange={(value) => set(key, value ? 1 : 0)} />
        </Row>
      ))}
      {ingressLists.map(([key, title, description]) => (
        <Row key={key} title={title} description={description}>
          <Input.TextArea aria-label={title} rows={2} value={form[key]} onChange={(event) => set(key, event.target.value)} />
        </Row>
      ))}
      <Row title="禁止的云厂商" description={raw?.cloud_legacy_cidrs_active ? '保存后改用 XDB 厂商名单，原手填网段停止生效。' : '根据本地 XDB 识别厂商。留空表示关闭此项拦截。'}>
        <Select
          mode="multiple"
          allowClear
          aria-label="禁止的云厂商"
          placeholder="选择云厂商"
          style={{ width: '100%' }}
          options={providerOptions.map((value) => ({ value, label: CLOUD_LABELS[value] ?? value }))}
          value={form.subscribe_ingress_cloud_providers}
          onChange={(value) => set('subscribe_ingress_cloud_providers', value)}
        />
      </Row>
      <h4 style={{ marginTop: 24 }}>审计与账号处置</h4>
      <Row
        title="高风险阈值"
        description="风险评分达到该值的账号标为高风险。当前评分规则最高 50 分（订阅次数、来源 IP 数各 25 分），默认 50"
      >
        <InputNumber
          min={0}
          max={100}
          value={form.insider_guard_risk_threshold}
          onChange={(v) => set('insider_guard_risk_threshold', v)}
        />
      </Row>
      <Row
        title="审计保留天数"
        description="登录 / 订阅事件、节点下发记录的保留天数，1～365，默认 3；小于 2 天时节点异常的候选账号可能不全。每个活跃设备每天约 24 行、每行约 0.4 KB"
      >
        <InputNumber
          min={1}
          max={365}
          value={form.insider_guard_retention_days}
          onChange={(v) => set('insider_guard_retention_days', v)}
        />
        <Unit>天</Unit>
      </Row>
      <div style={{ padding: '16px 0' }}>
        <Button type="primary" loading={saving} onClick={() => void save()}>
          保存
        </Button>
      </div>
    </>
  )
}
