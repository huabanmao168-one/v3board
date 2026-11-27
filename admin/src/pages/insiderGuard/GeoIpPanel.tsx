import { useQuery } from '@tanstack/react-query'
import { Button, Input } from 'antd'
import { ReloadOutlined } from '@ant-design/icons'
import { useState } from 'react'
import { get, unwrap } from '@/api/request'
import { adminPath } from '@/app/settings'
import { Note, Section } from './shared'

const endpoint = adminPath('/insiderGuard/geoIp')
type DatabaseStatus = 'ready' | 'missing' | 'unreadable' | 'invalid' | 'wrong_family' | 'probe_failed'

const statusLabel: Record<DatabaseStatus, string> = {
  ready: '可查询', missing: '缺少文件', unreadable: '文件不可读',
  invalid: '库文件无效', wrong_family: '地址族不匹配', probe_failed: '样例 IP 查询失败',
}

export function useGeoIpStatus() {
  return useQuery({
    queryKey: ['insiderGuard', 'geoIpStatus'],
    queryFn: () => unwrap(get<{ ipv4: DatabaseStatus; ipv6: DatabaseStatus }>(endpoint)),
  })
}

const fields = [
  ['country', '国家'], ['province', '省份'], ['city', '城市'], ['district', '区县'],
  ['isp', '运营商'], ['usage_type', 'IP 用途'], ['cloud_provider', '云厂商'], ['asn', 'ASN'],
  ['iso_code', '国家代码'], ['timezone', '时区'], ['zip_code', '邮编'], ['lon', '经度'], ['lat', '纬度'],
] as const

export function GeoIpPanel() {
  const status = useGeoIpStatus()
  const [input, setInput] = useState('')
  const [ip, setIp] = useState('')
  const lookup = useQuery({
    queryKey: ['insiderGuard', 'geoIpLookup', ip],
    queryFn: () => unwrap(get<{ result: Record<string, string> | null; status: DatabaseStatus }>(endpoint, { ip })),
    enabled: Boolean(ip),
    retry: false,
  })
  const submit = () => {
    const value = input.trim()
    if (!value) return
    if (value === ip) void lookup.refetch()
    else setIp(value)
  }
  const result = lookup.data?.result
  return (
    <Section>
      <h4>GeoIP 数据库</h4>
      <Note style={{ marginBottom: 12 }}>
        数据库文件放在站点根目录下的 <code>storage/app/geoip/</code>：IPv4 文件名 <code>ip2region_v4.xdb</code>，IPv6 文件名 <code>ip2region_v6.xdb</code>，并确保 PHP 进程可读。优先选带区县和 IP 用途的满载版，精简版只提供文件内已有的字段。
      </Note>
      <div className="d-flex align-items-center" style={{ gap: 12, marginBottom: 16 }}>
        {status.data ? <p className="mb-0">IPv4 {statusLabel[status.data.ipv4]} · IPv6 {statusLabel[status.data.ipv6]}</p> : <p className="mb-0">{status.isError ? '读取数据库状态失败' : '正在读取数据库状态'}</p>}
        <Button icon={<ReloadOutlined />} loading={status.isFetching} onClick={() => void status.refetch()} size="small">刷新</Button>
      </div>
      <div className="d-flex" style={{ gap: 8, maxWidth: 420 }}>
        <Input aria-label="IP 地址" placeholder="输入 IPv4 或 IPv6 地址" value={input} onChange={(event) => setInput(event.target.value)} onPressEnter={submit} />
        <Button type="primary" loading={lookup.isFetching} onClick={submit}>查询</Button>
      </div>
      {lookup.data && !result && <p className="mt-3">{lookup.data.status === 'ready' ? '未查到该 IP 的归属地' : statusLabel[lookup.data.status]}</p>}
      {result && <dl className="mt-3">
        {fields.filter(([key]) => result[key]).map(([key, label]) => (
          <div className="d-flex" key={key} style={{ gap: 12 }}><dt>{label}</dt><dd>{result[key]}</dd></div>
        ))}
      </dl>}
    </Section>
  )
}
