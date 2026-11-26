// 限制中账号（独占一个标签页）：列出所有被限制订阅的账号，可查看详情或直接解除限制
import { ReloadOutlined } from '@ant-design/icons'
import { keepPreviousData, useQuery, type QueryKey } from '@tanstack/react-query'
import { Button, type TableColumnsType, type TablePaginationConfig } from 'antd'
import { useState } from 'react'
import { ApiError } from '@/api/request'
import { fetchGuardRestrictedUsers } from '@/api/services/insiderGuard'
import type { GuardRestrictedUser } from '@/api/types'
import { paginationProps } from '@/hooks/usePagedList'
import { useUi } from '@/stores/appearance'
import { ReviewModal } from './ReviewModal'
import { Ellipsis, Failed, guardKeys, guardTime, Note, Section, SubTable, userLabel } from './shared'

interface RestrictedUsersProps {
  /** 「详情」：切到「用户详情」标签页查询该用户 */
  onDetail: (userId: number) => void
  /** 解除限制成功后刷新概览、风险用户表与用户详情 */
  onReviewed: () => void
}

export function RestrictedUsers({ onDetail, onReviewed }: RestrictedUsersProps) {
  const ui = useUi()
  const [page, setPage] = useState({ current: 1, pageSize: 20 })
  const query = useQuery({
    queryKey: [...guardKeys.restricted, page] as QueryKey,
    queryFn: async () => {
      const res = await fetchGuardRestrictedUsers(page)
      if (res.code !== 200) throw new ApiError(res.code, res.msg)
      return { rows: res.data ?? [], total: res.total ?? 0 }
    },
    placeholderData: keepPreviousData,
  })

  const columns: TableColumnsType<GuardRestrictedUser> = [
    { title: '用户 ID', dataIndex: 'user_id', key: 'user_id', width: 90 },
    { title: '邮箱', key: 'email', render: (_: unknown, row) => userLabel(row.email, row.user_id) },
    { title: '限制时间', dataIndex: 'created_at', key: 'created_at', width: 170, render: (s: number) => guardTime(s) },
    { title: '管理员 ID', dataIndex: 'admin_id', key: 'admin_id', width: 100, render: (id: number | null) => id ?? '-' },
    { title: '限制原因', dataIndex: 'reason', key: 'reason', render: (reason: string) => <Ellipsis text={reason} width={360} /> },
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
            user={{ id: row.user_id, email: row.email, restricted: true, summary: '当前处于限制状态，订阅仅下发提示节点' }}
            onSaved={() => {
              void query.refetch()
              onReviewed()
            }}
          >
            <Button size="small" type="primary" danger>
              解除限制
            </Button>
          </ReviewModal>
        </div>
      ),
    },
  ]

  const pagination: TablePaginationConfig = { ...paginationProps(ui), ...page, total: query.data?.total ?? 0 }

  return (
    <Section
      extra={
        <Button icon={<ReloadOutlined />} loading={query.isFetching} onClick={() => void query.refetch()}>
          刷新
        </Button>
      }
    >
      <Note style={{ marginBottom: 12 }}>
        被限制的账号拉取订阅和客户端配置时只会得到提示节点，仍可登录面板。点「解除限制」恢复真实节点，需填写原因并留下审核记录。
      </Note>
      {query.isError && !query.data ? (
        <Failed>读取失败：限制中账号暂时无法加载，请点「刷新」重试</Failed>
      ) : (
        <SubTable<GuardRestrictedUser>
          rowKey="user_id"
          loading={query.isFetching}
          dataSource={query.data?.rows ?? []}
          columns={columns}
          pagination={pagination}
          scroll={{ x: 900 }}
          locale={{ emptyText: '当前没有被限制的账号' }}
          onChange={(next) => setPage({ current: next.current ?? 1, pageSize: next.pageSize ?? page.pageSize })}
        />
      )}
    </Section>
  )
}
