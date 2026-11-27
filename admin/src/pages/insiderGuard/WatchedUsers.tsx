// 观察中的账号（独占一个标签页）：列出所有被标记"持续观察"但未被限制的用户，显示当前风险评分
import { ReloadOutlined, SearchOutlined } from '@ant-design/icons'
import { keepPreviousData, useQuery, type QueryKey } from '@tanstack/react-query'
import { Button, Input, Tag, type TableColumnsType, type TablePaginationConfig } from 'antd'
import { useState } from 'react'
import { ApiError } from '@/api/request'
import { fetchGuardWatchedUsers } from '@/api/services/insiderGuard'
import type { GuardWatchedUser } from '@/api/types'
import { paginationProps } from '@/hooks/usePagedList'
import { useUi } from '@/stores/appearance'
import { ReviewModal } from './ReviewModal'
import { Ellipsis, Failed, guardKeys, guardTime, Note, parseUserId, Section, SubTable, userLabel } from './shared'

interface WatchedUsersProps {
  /** 「详情」：切到「用户详情」标签页查询该用户 */
  onDetail: (userId: number) => void
  /** 审核成功后刷新概览、风险用户表与用户详情 */
  onReviewed: () => void
}

export function WatchedUsers({ onDetail, onReviewed }: WatchedUsersProps) {
  const ui = useUi()
  const [page, setPage] = useState({ current: 1, pageSize: 20 })
  const [userIdInput, setUserIdInput] = useState('')
  const [emailInput, setEmailInput] = useState('')
  const [filters, setFilters] = useState<{ user_id?: number; email?: string }>({})

  const query = useQuery({
    queryKey: [...guardKeys.watched, page, filters] as QueryKey,
    queryFn: async () => {
      const res = await fetchGuardWatchedUsers({ ...page, ...filters })
      if (res.code !== 200) throw new ApiError(res.code, res.msg)
      return { rows: res.data ?? [], total: res.total ?? 0 }
    },
    placeholderData: keepPreviousData,
  })

  const search = () => {
    const userId = parseUserId(userIdInput)
    const email = emailInput.trim()
    setFilters({ user_id: userId, email: email || undefined })
    setPage({ current: 1, pageSize: page.pageSize })
  }

  const clearSearch = () => {
    setUserIdInput('')
    setEmailInput('')
    setFilters({})
    setPage({ current: 1, pageSize: page.pageSize })
  }

  const columns: TableColumnsType<GuardWatchedUser> = [
    { title: '用户 ID', dataIndex: 'user_id', key: 'user_id', width: 90 },
    { title: '邮箱', key: 'email', render: (_: unknown, row) => userLabel(row.email, row.user_id) },
    {
      title: '当前评分',
      dataIndex: 'risk_score',
      key: 'risk_score',
      width: 100,
      render: (score: number) => (
        <Tag color={score >= 80 ? 'red' : score >= 50 ? 'orange' : 'default'}>{score}</Tag>
      ),
    },
    { title: '标记时间', dataIndex: 'created_at', key: 'created_at', width: 170, render: (s: number) => guardTime(s) },
    { title: '管理员 ID', dataIndex: 'admin_id', key: 'admin_id', width: 100, render: (id: number | null) => id ?? '-' },
    { title: '观察原因', dataIndex: 'reason', key: 'reason', render: (reason: string) => <Ellipsis text={reason} width={360} /> },
    {
      title: '操作',
      key: 'action',
      width: 170,
      render: (_: unknown, row) => (
        <div className="d-flex" style={{ gap: 8 }}>
          <Button size="small" onClick={() => onDetail(row.user_id)}>
            详情
          </Button>
          <ReviewModal
            user={{ id: row.user_id, email: row.email, restricted: false, summary: `当前评分 ${row.risk_score}，标记于 ${guardTime(row.created_at)}` }}
            onSaved={() => {
              void query.refetch()
              onReviewed()
            }}
          >
            <Button size="small" type="primary">
              审核
            </Button>
          </ReviewModal>
        </div>
      ),
    },
  ]

  const pagination: TablePaginationConfig = { ...paginationProps(ui), ...page, total: query.data?.total ?? 0 }
  const hasFilters = filters.user_id !== undefined || filters.email !== undefined

  return (
    <Section
      extra={
        <div className="d-flex" style={{ gap: 8 }}>
          <Input
            style={{ width: 120 }}
            placeholder="用户 ID"
            allowClear
            value={userIdInput}
            onChange={(e) => setUserIdInput(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') search()
            }}
          />
          <Input
            style={{ width: 160 }}
            placeholder="邮箱关键词"
            allowClear
            value={emailInput}
            onChange={(e) => setEmailInput(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') search()
            }}
          />
          <Button type="primary" icon={<SearchOutlined />} loading={query.isFetching} onClick={search}>
            搜索
          </Button>
          {hasFilters && (
            <Button onClick={clearSearch}>
              清除筛选
            </Button>
          )}
          <Button icon={<ReloadOutlined />} loading={query.isFetching} onClick={() => void query.refetch()}>
            刷新
          </Button>
        </div>
      }
    >
      <Note style={{ marginBottom: 12 }}>
        被标记「持续观察」的账号会在此列出（不含已被限制的账号）。显示最近一次标记观察的记录和当前风险评分。
      </Note>
      {query.isError && !query.data ? (
        <Failed>读取失败：观察中账号暂时无法加载，请点「刷新」重试</Failed>
      ) : (
        <SubTable<GuardWatchedUser>
          rowKey="user_id"
          loading={query.isFetching}
          dataSource={query.data?.rows ?? []}
          columns={columns}
          pagination={pagination}
          scroll={{ x: 1000 }}
          locale={{ emptyText: hasFilters ? '没有符合条件的记录' : '当前没有被标记观察的账号' }}
          onChange={(next) => setPage({ current: next.current ?? 1, pageSize: next.pageSize ?? page.pageSize })}
        />
      )}
    </Section>
  )
}
