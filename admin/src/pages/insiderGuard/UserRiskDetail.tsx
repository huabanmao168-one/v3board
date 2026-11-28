// 用户风险详情（「用户详情」标签页）：按用户 ID 查询评分摘要、最近事件（按小时汇总）、被下发过的节点（每人每节点一行）、审核记录；查到后可直接弹窗审核
import { SearchOutlined } from '@ant-design/icons'
import { useQuery } from '@tanstack/react-query'
import { Button, Input, Popconfirm, Tag, type TableColumnsType } from 'antd'
import { useState, type ReactNode } from 'react'
import { unwrap } from '@/api/request'
import { fetchGuardDevices, fetchGuardUserDetail, resetGuardSubscription } from '@/api/services/insiderGuard'
import type { GuardDevice, GuardEvent, GuardReview, GuardUserDetail } from '@/api/types'
import { message } from '@/app/staticApi'
import { get } from '@/api/request'
import { adminPath } from '@/app/settings'
import { Loading } from '@/components/Loading'
import { ReviewModal } from './ReviewModal'
import {
  Ellipsis,
  eventColumns,
  Failed,
  guardKeys,
  guardTime,
  MUTED,
  NodeLabel,
  Note,
  parseUserId,
  REVIEW_ACTION_TEXT,
  Section,
  SubTable,
} from './shared'

const eventTableColumns = eventColumns({ withUser: false, typeTag: false })

type Delivery = GuardUserDetail['deliveries'][number]
const deliveryColumns: TableColumnsType<Delivery> = [
  {
    title: '节点',
    key: 'node',
    render: (_: unknown, row) => <NodeLabel type={row.node_type} id={row.node_id} name={row.node_name} />,
  },
  { title: '下发记录', dataIndex: 'times', key: 'times', width: 100 },
  { title: '首次下发', dataIndex: 'first_at', key: 'first_at', width: 170, render: (s: number) => guardTime(s) },
  { title: '最近下发', dataIndex: 'last_at', key: 'last_at', width: 170, render: (s: number) => guardTime(s) },
]

const reviewColumns: TableColumnsType<GuardReview> = [
  { title: '时间', dataIndex: 'created_at', key: 'created_at', width: 170, render: (s: number) => guardTime(s) },
  {
    title: '动作',
    dataIndex: 'action',
    key: 'action',
    width: 140,
    render: (action: GuardReview['action']) => REVIEW_ACTION_TEXT[action] ?? action,
  },
  {
    title: '原因',
    dataIndex: 'reason',
    key: 'reason',
    render: (reason: string) => <Ellipsis text={reason} width={360} />,
  },
  { title: '管理员 ID', dataIndex: 'admin_id', key: 'admin_id', width: 100 },
]

/** 评分摘要里的一项 */
function Metric({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="pr-4 pr-sm-5" style={{ minWidth: 120 }}>
      <div style={MUTED}>{label}</div>
      <div style={{ fontSize: 20, fontWeight: 400 }}>{value}</div>
    </div>
  )
}

interface UserRiskDetailProps {
  /** 挂载时直接查询的用户：风险用户表点「详情」时页面以新 key 重建本组件并传入该用户 */
  initialUserId?: number
  /** 审核保存成功后刷新概览与本组件的查询 */
  onReviewed: () => void
}

export function UserRiskDetail({ initialUserId, onReviewed }: UserRiskDetailProps) {
  const [draft, setDraft] = useState(initialUserId ? String(initialUserId) : '')
  const [emailDraft, setEmailDraft] = useState('')
  const [userId, setUserId] = useState<number | undefined>(initialUserId)

  const detail = useQuery({
    queryKey: [...guardKeys.userDetail, userId],
    queryFn: () => unwrap(fetchGuardUserDetail(userId as number)),
    enabled: userId !== undefined,
  })

  const search = () => {
    // 只接受纯数字且 ≥ 1 的用户 ID（与「最近事件」一致）
    const id = parseUserId(draft)
    if (id === undefined) {
      message.error('请输入有效的用户 ID')
      return
    }
    // 同一个用户再次点「查询」：重新请求
    if (id === userId) void detail.refetch()
    else setUserId(id)
  }

  const searchByEmail = async () => {
    const email = emailDraft.trim()
    if (!email) {
      message.error('请输入邮箱')
      return
    }
    // 通过邮箱搜索用户
    try {
      const res = await get<{ user_id: number; email: string }>(adminPath('/user/search'), { email })
      if (res.code !== 200 || !res.data) {
        message.error('未找到该邮箱对应的用户')
        return
      }
      setUserId(res.data.user_id)
      setDraft(String(res.data.user_id))
    } catch (error) {
      message.error('查询失败，请重试')
    }
  }

  const data = detail.data
  return (
    <Section
      extra={
        <>
          <Input
            style={{ width: 160 }}
            placeholder="用户 ID"
            allowClear
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') search()
            }}
          />
          <Input
            style={{ width: 200 }}
            placeholder="或输入邮箱搜索"
            allowClear
            value={emailDraft}
            onChange={(e) => setEmailDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') searchByEmail()
            }}
          />
          <Button type="primary" icon={<SearchOutlined />} loading={detail.isFetching} onClick={search}>
            查询
          </Button>
          {data && (
            <>
            <ReviewModal
              user={{
                id: data.user_id,
                email: data.email,
                restricted: data.restriction !== null,
                summary: `最近 1 小时：评分 ${data.risk.score}，订阅 ${data.risk.subscription_requests_1h} 次，IP ${data.risk.distinct_ips_1h} 个`,
              }}
              onSaved={onReviewed}
            >
              <Button>审核此用户</Button>
            </ReviewModal>
            <Popconfirm
              title="重置订阅后旧链接和旧节点凭据立即失效，确定吗？"
              onConfirm={async () => {
                const res = await resetGuardSubscription(data.user_id)
                if (res.code !== 200 || res.data !== true) return
                message.success('订阅已重置，旧链接已失效')
              }}
            >
              <Button danger>重置订阅</Button>
            </Popconfirm>
            </>
          )}
        </>
      }
    >
      {userId === undefined ? (
        <Failed>输入用户 ID 后查询该账号的风险评分、事件与审核记录</Failed>
      ) : (
        <Loading loading={detail.isLoading}>
          {detail.isError && !data && <Failed>查询失败：用户不存在或接口不可用</Failed>}
          {data && (
            <div style={{ paddingBottom: 16 }}>
              <div className="d-flex flex-wrap align-items-center" style={{ gap: SPACING.md, marginBottom: SPACING.md }}>
                <span>
                  <span style={MUTED}>邮箱：</span>
                  {data.email}
                </span>
                <span>
                  <span style={MUTED}>用户 ID：</span>#{data.user_id}
                </span>
                <span>
                  <span style={MUTED}>账号状态：</span>
                  {data.banned ? <Tag color="red">已封禁</Tag> : <Tag color="green">正常</Tag>}
                </span>
                <span>
                  <span style={MUTED}>限制状态：</span>
                  {data.restriction ? <Tag color="red">已限制</Tag> : <Tag>未限制</Tag>}
                </span>
              </div>
              {data.restriction && (
                <Note style={{ marginBottom: SPACING.md }}>
                  <div>
                    <strong>⚠️ 此用户的订阅已被限制</strong> · 限制于 {guardTime(data.restriction.created_at)} · 管理员 #{data.restriction.admin_id ?? '-'}
                  </div>
                  <div style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', marginTop: 4 }}>原因：{data.restriction.reason}</div>
                  <div style={{ marginTop: SPACING.sm, color: '#666' }}>订阅与客户端配置请求已被限制，仅下发提示节点。如需解除，点击右上角「审核此用户」→ 选择「解除限制」</div>
                </Note>
              )}
              <div className="d-flex flex-wrap" style={{ marginBottom: SPACING.lg }}>
                <Metric
                  label="评分（最近 1 小时）"
                  value={
                    data.risk.manual_review_required ? <Tag color="orange">{data.risk.score}</Tag> : data.risk.score
                  }
                />
                <Metric label="1 小时订阅次数" value={data.risk.subscription_requests_1h} />
                <Metric label="1 小时 IP 数" value={data.risk.distinct_ips_1h} />
                <Metric label="需人工复核" value={data.risk.manual_review_required ? '是' : '否'} />
              </div>
              <DeviceList id={data.user_id} />
              <div style={{ fontWeight: 500, marginBottom: SPACING.sm }}>最近事件</div>
              <SubTable<GuardEvent> dataSource={data.events} columns={eventTableColumns} scroll={{ x: 1010 }} />
              <div style={{ fontWeight: 500, marginBottom: SPACING.sm }}>被下发过的节点</div>
              <SubTable<Delivery>
                rowKey={(row) => `${row.node_type}:${row.node_id}`}
                dataSource={data.deliveries}
                columns={deliveryColumns}
                scroll={{ x: 690 }}
              />
              <div style={{ fontWeight: 500, marginBottom: SPACING.sm }}>审核记录</div>
              <SubTable<GuardReview>
                dataSource={data.reviews}
                columns={reviewColumns}
                scroll={{ x: 770 }}
                style={{ marginBottom: 0 }}
              />
            </div>
          )}
        </Loading>
      )}
    </Section>
  )
}

function DeviceList({ id }: { id: number }) {
  const query = useQuery({ queryKey: ['insiderGuard', 'devices', id], queryFn: () => unwrap(fetchGuardDevices(id)) })
  const columns: TableColumnsType<GuardDevice> = [
    { title: '客户端 UA', dataIndex: 'ua', key: 'ua', render: (ua: string | null) => <Ellipsis text={ua} width={300} empty="未提供" /> },
    { title: '订阅次数', dataIndex: 'pulls', key: 'pulls', width: 90 },
    { title: 'IP 数', dataIndex: 'ips', key: 'ips', width: 80 },
    { title: '地区', dataIndex: 'locations', key: 'locations', render: (l: string[]) => (l.length ? l.join('、') : '-') },
    { title: '最近活动', dataIndex: 'last_at', key: 'last_at', width: 170, render: (t: number) => guardTime(t) },
  ]
  if (query.isError && !query.data) return <Failed>读取失败：设备信息暂时无法加载</Failed>
  return (
    <>
      <div style={{ fontWeight: 500, marginBottom: SPACING.sm }}>活跃设备（最近 24 小时，按 UA 汇总）</div>
      <SubTable<GuardDevice> rowKey="ua_hash" loading={query.isLoading} dataSource={query.data ?? []} columns={columns} scroll={{ x: 760 }} />
    </>
  )
}
