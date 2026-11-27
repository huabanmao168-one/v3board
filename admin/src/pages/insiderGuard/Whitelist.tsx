// 白名单（独占一个标签页）：白名单用户不进高风险列表，也不参与自动处置；加入需填写原因
import { ReloadOutlined } from '@ant-design/icons'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Button, Input, Popconfirm, type TableColumnsType, type TablePaginationConfig } from 'antd'
import { useState } from 'react'
import { ApiError } from '@/api/request'
import { addGuardWhitelist, fetchGuardWhitelist, removeGuardWhitelist } from '@/api/services/insiderGuard'
import type { GuardWhitelistUser } from '@/api/types'
import { message } from '@/app/staticApi'
import { paginationProps } from '@/hooks/usePagedList'
import { useUi } from '@/stores/appearance'
import { Ellipsis, Failed, guardTime, Note, parseUserId, Section, SubTable, userLabel } from './shared'

interface WhitelistProps {
  /** 加入 / 移出成功后刷新风险用户表与用户详情 */
  onChanged: () => void
}

export function Whitelist({ onChanged }: WhitelistProps) {
  const ui = useUi()
  const [page, setPage] = useState({ current: 1, pageSize: 20 })
  const [id, setId] = useState('')
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)

  const query = useQuery({
    queryKey: ['insiderGuard', 'whitelist', page],
    queryFn: async () => {
      const res = await fetchGuardWhitelist(page)
      if (res.code !== 200) throw new ApiError(res.code, res.msg)
      return { rows: res.data ?? [], total: res.total ?? 0 }
    },
    placeholderData: keepPreviousData,
  })

  const add = async () => {
    const userId = parseUserId(id)
    if (userId === undefined) return message.error('请输入有效的用户 ID')
    const text = reason.trim()
    if ([...text].length < 8) return message.error('原因至少 8 个字')
    setSaving(true)
    const res = await addGuardWhitelist({ id: userId, reason: text })
    setSaving(false)
    if (res.code !== 200 || res.data !== true) return
    message.success('已加入白名单')
    setId('')
    setReason('')
    void query.refetch()
    onChanged()
  }

  const remove = async (userId: number) => {
    const res = await removeGuardWhitelist(userId)
    if (res.code !== 200 || res.data !== true) return
    message.success('已移出白名单')
    void query.refetch()
    onChanged()
  }

  const columns: TableColumnsType<GuardWhitelistUser> = [
    { title: '用户 ID', dataIndex: 'user_id', key: 'user_id', width: 90 },
    { title: '邮箱', key: 'email', render: (_: unknown, row) => userLabel(row.email, row.user_id) },
    { title: '加入时间', dataIndex: 'created_at', key: 'created_at', width: 170, render: (s: number) => guardTime(s) },
    { title: '管理员 ID', dataIndex: 'admin_id', key: 'admin_id', width: 100, render: (v: number | null) => v ?? '-' },
    { title: '原因', dataIndex: 'reason', key: 'reason', render: (text: string) => <Ellipsis text={text} width={360} /> },
    {
      title: '操作',
      key: 'action',
      width: 100,
      render: (_: unknown, row) => (
        <Popconfirm title='移出白名单后会重新参与风险评分，确定吗？' onConfirm={() => void remove(row.user_id)}>
          <Button size='small'>移出</Button>
        </Popconfirm>
      ),
    },
  ]

  const pagination: TablePaginationConfig = { ...paginationProps(ui), ...page, total: query.data?.total ?? 0 }

  return (
    <Section
      extra={
        <>
          <Input style={{ width: 140 }} placeholder='用户 ID' allowClear value={id} onChange={(e) => setId(e.target.value)} />
          <Input
            style={{ width: 260 }}
            placeholder='原因，至少 8 个字，例如：已确认为多设备自用'
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            onPressEnter={() => void add()}
          />
          <Button type='primary' loading={saving} onClick={() => void add()}>
            加入白名单
          </Button>
          <Button icon={<ReloadOutlined />} loading={query.isFetching} onClick={() => void query.refetch()}>
            刷新
          </Button>
        </>
      }
    >
      <Note style={{ marginBottom: 12 }}>
        白名单账号不会出现在高风险列表，也不会被自动处置，用于已确认的多设备自用或共享出口用户。
      </Note>
      {query.isError && !query.data ? (
        <Failed>读取失败：白名单暂时无法加载，请点「刷新」重试</Failed>
      ) : (
        <SubTable<GuardWhitelistUser>
          rowKey='user_id'
          loading={query.isFetching}
          dataSource={query.data?.rows ?? []}
          columns={columns}
          pagination={pagination}
          scroll={{ x: 860 }}
          locale={{ emptyText: '白名单为空' }}
          onChange={(next) => setPage({ current: next.current ?? 1, pageSize: next.pageSize ?? page.pageSize })}
        />
      )}
    </Section>
  )
}
