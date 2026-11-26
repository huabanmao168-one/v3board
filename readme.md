<img src="https://avatars.githubusercontent.com/u/56885001?s=200&v=4" alt="logo" width="130" height="130" align="right"/>

# V3Board - V2Board 增强版

基于 [wyx2685/v2board](https://github.com/wyx2685/v2board) 二次开发，定期合并上游，数据库与上游兼容，可以从原版无缝迁移。

## 仓库组成

| 部分 | 位置 | 说明 |
|------|------|------|
| 后端 | 本仓库（Laravel） | 接口、定时任务、Telegram 机器人、数据库脚本 |
| 用户端前端 | 本仓库 `public/theme/default` | 与上游一致 |
| 后台管理前端 | 本仓库 `admin/` | React 19 + antd 6，本仓库在 admin/ 维护源码，内置构建产物 `public/assets/admin`，由 `build-admin.sh` 生成，部署和升级都不需要 Node |
| 节点端 | [修改版 V2bX](https://github.com/wyx2685/V2bX)、[v2node](https://github.com/wyx2685/v2node) | 与上游相同 |

## 相对上游新增的功能

### 风控中心（InsiderGuard）

后台「用户 → 风控中心」，用于排查订阅泄露和异常账号。

- 记录登录（仅密码登录）与订阅拉取的来源 IP、UA（同一用户、IP、UA 按小时合并计数，不会撑大表），以及向每个用户下发过哪些节点（每人每节点一行）
- 风险用户：按时段统计订阅次数与来源 IP 数并评分，达到阈值标为高风险
- 节点异常登记：查看异常前 24 小时内被下发过该节点的候选账号与时间线（按首次 / 最近下发时间估算，可能多列），只是线索，不能证明泄露
- 节点 TCP 健康探测（可选）：每 5 分钟探测一次，连续两次不通时通过 Telegram 通知管理员（需开启机器人并绑定管理员，同一告警默认 2 小时内只发一次）
- 人工审核与订阅限制：限制生效后，订阅和 App 配置按提示节点开关返回一条提示节点或空正文 HTTP 403。用户可继续登录面板。
- 订阅入口防护：订阅和 App 配置共用可信代理、地区、浏览器、UA 与云厂商规则。可在「订阅拦截」按 IP 查记录，在「GeoIP 库」检查 IPv4/IPv6 XDB 或手动查 IP。详见 [订阅入口切换](docs/sub-panel-cutover.md)。
- 审计数据保留 1～365 天（默认 3；每个活跃设备每天约 24 行、每行约 0.4 KB，万级用户不建议超过 7 天），每小时自动清理；数据含 IP 与 UA，请按当地法规设置保留期
- 设置在「风控中心 → 风控设置」；定时任务已加入调度，不需要另加 crontab，排障时可手动执行 `php artisan insider-guard:health`、`php artisan insider-guard:cleanup`；缓存依赖 Redis；站点在反向代理 / CDN 后面且未配置信任代理时，拿到的来源 IP 是代理地址，IP 维度的评分无效

### 订阅

- 订阅加密：订阅内容用 AES-CBC 加密后以 Base64 输出，可按 User-Agent 关键词和套餐 ID 限定范围，IV 优先取请求头 `X-IV`
- 订阅域名重写规则：客户端 UA 命中关键词时，把节点中的指定域名替换为指定 IP 下发
- 订阅节点 UA 白名单：规则里出现过的节点只对命中 UA 的客户端下发，其他节点不受影响
- 无订阅用户也可以查看节点列表
- mihomo 系订阅开启 `support-x25519mlkem768`；只按 `sing-box` 关键字识别 sing-box，避免 UA 里带 sing 的系统语言名被误判；Shadowrocket 订阅状态行正确处理永久有效用户

上述开关及展示、域名改写、节点 UA 白名单均在「系统配置 → 订阅」管理。

### 订单与订阅周期

- 提前续期记录：用户流量用尽后提前开启下一计费周期（扣除订阅时长）的记录，后台「财务 → 提前续期记录」
- 订阅覆盖记录：购买不同订阅导致原订阅被覆盖时，记录覆盖前的到期时间与剩余流量，后台「财务 → 订阅覆盖记录」，订单列表和详情可直接跳转查看
- 已取消订单补单：已取消的订单可以标记为已支付并由系统开通，备注必填并作为标注显示在订单上
- 订单列表显示下单用户邮箱；修复订单取消与支付回调之间的竞态；支付回调核对支付方式，避免重复开通

### 用户与后台

- 用户列表显示距下次流量重置的天数，编辑抽屉同步提示
- 仪表盘新增总用户、累计付费用户、有效付费用户（含占比），「实时注册」改为「今日注册」
- 用户端支持更换邮箱
- 移除未完成的 staff 接口（存在越权读取用户信息的风险）

### 签到系统

用户可通过每日签到获得随机流量奖励，签到通过减少已用流量实现，续费时不会重置。

- 普通签到（`/sign`）：每日一次，随机获得 10MB ~ 1GB 流量
- 运气签到（`/sign2 <数值><单位>`）：输入想要赌的流量（如 `100GB`），获得 -100% 到 +100% 的浮动流量，支持 MB 和 GB
- 支持 Web 接口和 Telegram 机器人两种方式
- 「系统配置 → 站点」可分别开关普通签到和运气签到
- 命令 `php artisan checkin:clear-cache` 清理签到缓存（未加入调度，需要时手动执行）

### OAuth 第三方登录

- Google OAuth 登录 / 注册：完整的 OAuth 2.0 流程，新用户自动注册并发送含随机密码的欢迎邮件，老用户直接登录
- Telegram 登录：用户向机器人发送验证码完成登录，前端轮询接口检查登录状态
- 支持邀请码关联
- Google 登录需要在 `.env` 配置 `GOOGLE_CLIENT_ID` 与 `GOOGLE_CLIENT_SECRET`

### 节点监控增强

| 功能 | 原版 | 增强版 |
|------|------|--------|
| 掉线检测 | 30 分钟超时立即通知 | 5 分钟超时，连续 5 次才通知 |
| 重复通知 | 每次检测都通知 | 24 小时内不重复 |
| 恢复通知 | 无 | 节点恢复时发送通知 |
| 状态报告 | 无 | 定时发送全节点状态报告 |

- 连续掉线计数，避免短暂网络波动造成误报
- 状态报告频率在「系统配置 → 节点」设置（单位小时，0 为不发送），隐藏节点（`show=0`）不参与

### Telegram 机器人扩展

| 命令 | 功能 | 状态 |
|------|------|------|
| `/start` | 欢迎消息和快捷指令 | 新增 |
| `/help` | 显示帮助信息 | 新增 |
| `/info` | 查询账户信息 | 新增 |
| `/sign` | 普通签到 | 新增 |
| `/sign2` | 运气签到 | 新增 |
| `/login <验证码>` | Telegram 登录验证 | 新增 |
| `/bind` | 绑定账号 | 增强 |
| `/unbind` | 解绑账号 | 原有 |
| `/traffic` | 查询流量 | 原有 |
| `/getlatesturl` | 获取最新网址 | 原有 |
| `/replyticket` | 回复工单 | 原有 |

TelegramService 新增 MarkdownV2 安全转义（保护代码块）、统一的 `sendReply()`，以及更完整的错误处理与日志。命令 `php artisan telegram:update-commands` 把命令菜单同步到 Telegram（更换机器人后执行一次）。

### 邮件

- 邮件提醒可配置：到期前提醒天数与次数、流量使用阈值、新用户注册后是否默认开启（「系统配置 → 邮件提醒」），由定时任务 `send:remindMail` 发送
- 新增 fashion 邮件主题（完整 6 个模板）和 OAuth 用户欢迎邮件模板 `googleWelcome.blade.php`

## 后台管理前端

后台 React 源码位于 admin/，发布产物位于 public/assets/admin。GitHub Actions 在推送和 PR 时构建，并上传 v3board-admin-ui 产物。

本地构建需要 Node ≥ 22.22 和 pnpm 11。提交前端源码后运行 ./build-admin.sh，再提交更新后的 public/assets/admin。服务器可直接使用已提交的静态文件。

## 安装与升级

环境要求（与上游一致）：

- PHP7.3+
- Composer
- MySQL5.5+
- Redis
- Laravel

仓库为私有仓库，部署服务器需要有读取权限。使用 SSH 部署密钥时，可通过 V3BOARD_UPDATE_REPOSITORY 指定 SSH 仓库地址。

全新安装：

```shell
git clone --branch main https://github.com/huabanmao168-one/v3board.git
cd v3board
bash init.sh
```

从兼容的 v2board Git 部署迁移前，先备份数据库和站点配置。更新脚本直接拉取本仓库的 main 并要求快进合并。遇到分叉时，请在备份后使用新检出的 V3Board 目录部署。

```shell
git remote set-url origin https://github.com/huabanmao168-one/v3board.git
./update.sh
```

然后把缓存驱动改为 redis，刷新设置缓存并重启队列：

```shell
sed -i 's/^CACHE_DRIVER=.*/CACHE_DRIVER=redis/' .env
php artisan config:clear
php artisan config:cache
php artisan horizon:terminate
```

最后进入后台重新保存主题：主题配置 → 选择 default 主题 → 主题设置 → 确定保存。

日常升级：

```shell
./update.sh
```

`update.sh` 会拉取代码、更新依赖并执行 `database/update.sql`（新表、新字段都在里面，重复执行安全）。升级后台前端后如果站点前面有 CDN 缓存了静态文件，清一次 `/assets/admin/` 的缓存（分包文件名带哈希不会串，真正要换新的是 `assets/admin.js`、`admin.css`、`favicon.png`，CDN 忽略查询串时旧的 admin.js 会去找已删除的分包导致后台白屏）。php-fpm 开了 opcache 且不校验文件时间戳的，升级后 reload 一次 php-fpm，否则可能还在执行旧的编译视图。使用 webman 常驻模式的站点升级后需要重启 webman。

合并上游 wyx2685/v2board 有冲突时：

```shell
sh merge.sh
```

上游重新打包旧后台时会改 `public/assets/admin/umi.js` 等文件，而本仓库已经删除它们，合并会报 modify/delete 冲突，直接 `git rm` 这些文件即可。

## 社区与反馈

- Telegram 群组：[@v3board_group](https://t.me/v3board_group)
- 上游文档：[v2board.com](https://v2board.com)
- 提问请按 issue 模板填写，便于跟进
- 感谢 [JetBrains](https://www.jetbrains.com/) 提供的开源项目许可
