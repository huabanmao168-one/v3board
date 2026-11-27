import { adminPath } from '@/app/settings'
import { get, post } from '../request'
import type {
  GuardEvent,
  GuardEventType,
  GuardHours,
  GuardOverview,
  GuardReviewAction,
  GuardRestrictedUser,
  GuardWatchedUser,
  GuardRiskUser,
  GuardUserDetail,
  GuardWhitelistUser,
  GuardDevice,
} from '../types'

// 风控中心（v3board 独有接口）：GET 用 query，POST 用表单；节点一律用 node_type + node_id 标识
const guardPath = (name: string) => adminPath(`/insiderGuard/${name}`)

/** 概览：近 24 小时事件数、审核总数、限制中账号数、最近 20 条节点异常 */
export const fetchGuardOverview = () => get<GuardOverview>(guardPath('overview'))
/** 风险用户：按时段统计订阅次数与来源 IP 数并评分（响应顶层另有 hours / threshold） */
export const fetchGuardUsers = (hours: GuardHours) => get<GuardRiskUser[]>(guardPath('users'), { hours })
/** 限制中的账号（服务端分页，顶层 total），最近限制的在前 */
export const fetchGuardRestrictedUsers = (params: { current: number; pageSize: number; user_id?: number; email?: string }) =>
  get<GuardRestrictedUser[]>(guardPath('restrictedUsers'), params)
/** 观察中的账号（服务端分页，顶层 total），最近标记的在前 */
export const fetchGuardWatchedUsers = (params: { current: number; pageSize: number; user_id?: number; email?: string }) =>
  get<GuardWatchedUser[]>(guardPath('watchedUsers'), params)
/** 事件列表（服务端分页，顶层 total），可按用户 ID、事件类型筛选 */
export const fetchGuardEvents = (params: {
  current: number
  pageSize: number
  user_id?: number
  event_type?: GuardEventType
}) => get<GuardEvent[]>(guardPath('events'), params)
export const fetchGuardUserDetail = (id: number) => get<GuardUserDetail>(guardPath('userDetail'), { id })
/** 人工审核记录（不改变账号状态）：原因 8～1000 字 */
export const saveGuardReview = (params: { id: number; action: GuardReviewAction; reason: string }) =>
  post<boolean>(guardPath('review'), params)
/** 限制：订阅与客户端配置仅下发提示节点；原因 12～1000 字，后端要求显式确认 */
export const restrictGuardUser = (params: { id: number; reason: string }) =>
  post<boolean>(guardPath('restrict'), { ...params, confirm: 1 })
/** 解除限制：恢复真实入口；原因 8～1000 字 */
export const releaseGuardUser = (params: { id: number; reason: string }) => post<boolean>(guardPath('release'), params)
/** 白名单（服务端分页，顶层 total）：不参与风险评分与自动处置 */
export const fetchGuardWhitelist = (params: { current: number; pageSize: number }) =>
  get<GuardWhitelistUser[]>(guardPath('whitelist'), params)
export const addGuardWhitelist = (params: { id: number; reason: string }) => post<boolean>(guardPath('whitelistAdd'), params)
export const removeGuardWhitelist = (id: number) => post<boolean>(guardPath('whitelistRemove'), { id })
/** 重置订阅：换 token 和 uuid，旧链接立即失效 */
export const resetGuardSubscription = (id: number) => post<boolean>(guardPath('resetSubscription'), { id })
/** 用户设备视图：最近 24 小时按 UA 汇总的订阅活动 */
export const fetchGuardDevices = (id: number) => get<GuardDevice[]>(guardPath('devices'), { id })
