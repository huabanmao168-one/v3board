# Sub-Panel 订阅入口切换

目标链路为客户端 → Nginx → V3Board。保留现有订阅域名、TLS 和对外路径。先在测试入口验证，下例中的地址和路径需要替换成实际值。

## Nginx

在订阅域名的现有 server 块内修改对应 location。以下示例假设旧入口为 /sub/cat，V3Board 内部订阅入口为 /api/v1/client/subscribe，并且已经加载 Nginx GeoIP2 模块。

在 http 块中配置支持 IPv4 和 IPv6 的国家数据库。若使用 Country 库，可只保留国家代码变量，并将 Region 和 City 请求头设为空字符串。已有 V3Board 安装需先运行常规数据库升级，风控记录才能保存归属地。


ginx 配置示例


géo


~~~nginx
geoip2 /etc/nginx/geoip/GeoLite2-City.mmdb {
    auto_reload 1h;
    $subscription_country country iso_code;
    $subscription_region subdivisions 0 names en;
    $subscription_city city names en;
}
~~~

建议将以下请求头设置放入订阅域名专用的配置片段，在每个订阅 location 中引用。自定义头都由 Nginx 覆盖。若 Nginx 前面另有 CDN 或代理，先在 Nginx realip 配置中限定可信来源，再使用这里的变量。

~~~nginx
proxy_set_header Host $host;
proxy_set_header User-Agent $http_user_agent;
proxy_set_header X-Subscription-Client-IP $remote_addr;
proxy_set_header X-Subscription-Country $subscription_country;
proxy_set_header X-Subscription-Region $subscription_region;
proxy_set_header X-Subscription-City $subscription_city;
~~~

同一 Nginx 上其他转发到 V3Board 的 location 也需要覆盖或清除所有订阅归属地和来源 IP 请求头，避免客户端经另一 location 伪造头部。限制内部订阅路径只能经映射访问。

查询参数中的 token 会随原查询字符串转发。已有路径内 token 的部署可以用单独的 location 提取，示例只匹配 URL 安全的 token。

~~~nginx
location = /sub/cat {
    include snippets/v3board-subscription-headers.conf;
    proxy_pass http://127.0.0.1:8000/api/v1/client/subscribe;
}

location ~ ^/sub/cat/(?<subscription_token>[A-Za-z0-9_-]+)/?$ {
    include snippets/v3board-subscription-headers.conf;
    if ($arg_token = "") {
        rewrite ^ /api/v1/client/subscribe?token=$subscription_token break;
    }
    rewrite ^ /api/v1/client/subscribe break;
    proxy_pass http://127.0.0.1:8000;
}
~~~

## 本地 GeoIP XDB

V3Board 使用与 Sub-Panel 同系列的 ip2region XDB。将 IPv4 文件放到 storage/app/geoip/ip2region_v4.xdb，将 IPv6 文件放到 storage/app/geoip/ip2region_v6.xdb，并让 PHP 进程可读。可复用 Sub-Panel 安装的 IPv4 ip2region.xdb。优先选带区县和 IP 用途的满载版，精简版只提供其文件内已有的字段。读取器来自 ip2region 的 PHP binding，版本为 fac3d0376a7c，许可证随代码保存。

在「风控中心 → GeoIP 库」检查两种地址库的状态并试查 IP。新活动会保存归属地、运营商和用途。订阅地域规则优先读本地库，缺少地区代码时可使用可信 Nginx 提供的地区代码。使用本地库时，只需 Nginx 传入真实客户端 IP，GeoIP2 头可留空。海外限制仍会拒绝未知地区。

## V3Board 后台

1. 在「风控中心 → 风控设置」填入 V3Board 实际看到的 Nginx TCP 来源地址。启用后，订阅入口要求来源匹配且代理提供有效 IP。
2. 启用海外限制前，先核对 Nginx 的国家代码。默认只允许 CN，可另开港澳台。归属地未知的 IP 会被拒绝。按需填写可信 IP 和 UA 关键词，并在下拉框选择要拦截的 XDB 云厂商。
3. 在 V3Board 后台按需新建订阅展示规则。由 V3Board 直接生成订阅的入口可以启用 Lumi。仍经过 Sub-Panel 加密的入口需要保持 V3Board 的 Lumi 关闭，或先关闭网关端 Lumi。
4. 在测试入口验证旧链接、IPv4、IPv6、地区策略、客户端兼容和登录保护。拦截原因可在 V3Board 应用日志中查询。
5. 正式切换前备份原 Nginx 配置和 Sub-Panel 数据。验证稳定后停用 Sub-Panel，并清空后台的上报 URL。回滚时恢复原反代配置及原 Lumi 开关。
