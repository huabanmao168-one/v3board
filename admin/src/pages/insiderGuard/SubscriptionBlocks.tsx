import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Button, Input, type TableColumnsType } from 'antd'
import { useState } from 'react'
import { ApiError, get } from '@/api/request'
import { adminPath } from '@/app/settings'
import { formatTime } from '@/utils/format'
import { Ellipsis, Failed, NOWRAP, Section, SubTable, userLabel } from './shared'

interface Block {
  id: number
  ip_address: string
  ip_location: string | null
  user_id: number | null
  email: string | null
  reason: string
  status: number
  times: number
  ua: string
  last_at: number
}

function reasonLabel(reason: string): string {
  const [kind, detail] = reason.split(':', 2)
  switch (kind) {
    case 'untrusted_ingress': return '可信代理校验失败'
    case 'ua_blacklist': return '命中禁止 UA'
    case 'oversea': return detail === 'unknown' ? '归属地未知' : '地区限制（' + detail + '）'
    case 'cloud_ip': return detail ? '云厂商限制（' + detail + '）' : '云厂商网段限制'
    case 'browser': return '浏览器直访'
    default: return reason
  }
}

const columns: TableColumnsType<Block> = [
  { title: '最近拦截时间', dataIndex: 'last_at', width: 170, render: (value: number) => formatTime(value, 'YYYY-MM-DD HH:mm:ss') },
  { title: '用户', key: 'user', width: 200, render: (_: unknown, row) => row.user_id ? userLabel(row.email, row.user_id) : '-' },
  { title: 'IP', dataIndex: 'ip_address', width: 150, render: (ip: string) => <span style={NOWRAP}>{ip}</span> },
  { title: '归属地', dataIndex: 'ip_location', width: 165, render: (value: string | null) => <span style={NOWRAP}>{value || '未知'}</span> },
  { title: '拦截原因', dataIndex: 'reason', width: 175, render: (r: string) => <span style={NOWRAP}>{reasonLabel(r)}</span> },
  { title: 'UA', dataIndex: 'ua', width: 300, render: (ua: string) => <Ellipsis text={ua} width={300} /> },
  { title: '状态码', dataIndex: 'status', width: 80 },
  { title: '次数', dataIndex: 'times', width: 75 },
]

export function SubscriptionBlocks() {
  const [draftIp, setDraftIp] = useState('')
  const [draftEmail, setDraftEmail] = useState('')
  const [draftUserId, setDraftUserId] = useState('')
  const [filter, setFilter] = useState({ ip: '', email: '', user_id: '', current: 1, pageSize: 20 })
  const query = useQuery({
    queryKey: ['insiderGuard', 'subscriptionBlocks', filter],
    queryFn: async () => {
      const res = await get<Block[]>(adminPath('/insiderGuard/subscriptionBlocks'), {
        current: filter.current,
        pageSize: filter.pageSize,
        ip: filter.ip || undefined,
        email: filter.email || undefined,
        user_id: filter.user_id || undefined,
      })
      if (res.code !== 200) throw new ApiError(res.code, res.msg)
      return { rows: res.data ?? [], total: res.total ?? 0 }
    },
    placeholderData: keepPreviousData,
    retry: false,
  })
  const search = () => {
    const ip = draftIp.trim()
    const email = draftEmail.trim()
    const user_id = draftUserId.trim()
    if (ip === filter.ip && email === filter.email && user_id === filter.user_id && filter.current === 1) {
      void query.refetch()
    } else {
      setFilter((previous) => ({ ...previous, ip, email, user_id, current: 1 }))
    }
  }
  return (
    <Section extra={<>
      <Input aria-label="来源 IP" placeholder="IP" allowClear value={draftIp} onChange={(e) => setDraftIp(e.target.value)} onPressEnter={search} style={{ width: 140 }} />
      <Input aria-label="邮箱" placeholder="邮箱" allowClear value={draftEmail} onChange={(e) => setDraftEmail(e.target.value)} onPressEnter={search} style={{ width: 160 }} />
      <Input aria-label="用户ID" placeholder="用户 ID" allowClear value={draftUserId} onChange={(e) => setDraftUserId(e.target.value)} onPressEnter={search} style={{ width: 100 }} />
      <Button type="primary" onClick={search}>筛选</Button>
    </>}>
      {query.isError && <Failed>拦截记录读取失败，请点击筛选重试。</Failed>}
      <SubTable<Block>
        rowKey="id"
        dataSource={query.data?.rows ?? []}
        columns={columns}
        loading={query.isFetching}
        scroll={{ x: 1240 }}
        pagination={{ current: filter.current, pageSize: filter.pageSize, total: query.data?.total ?? 0 }}
        onChange={(next) => setFilter((previous) => ({ ...previous, current: next.current ?? 1, pageSize: next.pageSize ?? 20 }))}
      />
    </Section>
  )
}
