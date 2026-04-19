# 绿循校园

一个面向厦门大学校园场景的电子废物捐赠流转、固定回收点处理与上门回收网站，适合课程实践、比赛答辩和本地试运行展示。

## 当前版本特点

- 处理方式共三种：
  - 捐赠给有需要的同学
  - 投放固定回收点
  - 上门回收
- 前端页面已按汇报展示目标精简
- 支持移动端和 PC 端自适应显示
- 支持用户端提交、管理员审核、公示流转、回收点投放、上门回收和积分激励闭环

## 当前已实现

- 手机号注册与登录
- 提交电子废物信息与图片
- 管理员审核物品
- 公示大厅展示可继续使用的捐赠物品
- 固定回收点投放流程
- 上门回收流程
- 同学提交申领申请
- 管理员审核申请并发送站内通知
- 捐赠 / 固定回收点审核通过后发放 5 积分
- 上门回收完成后发放 5 积分
- 积分兑换模块
- 回收点位管理

## 默认管理员账号

- 手机号：`18800000000`
- 初始密码：`admin123456`

建议上线后第一时间修改管理员密码。

## 本地运行

```powershell
php -S 127.0.0.1:8000
```

访问：

```text
http://127.0.0.1:8000/index.php
```

## 短信验证码模式

- 演示模式（默认）：`DEMO_SMS_MODE=true`，验证码显示在站内提示中
- 真实短信模式：`DEMO_SMS_MODE=false`，并在 `config.php` 配置短信网关参数
  - `SMS_GATEWAY_URL`
  - `SMS_GATEWAY_TOKEN`
  - `SMS_TEMPLATE_ID`
  - `SMS_SIGN_NAME`

## 线上部署

项目支持 `JSON` 与 `MySQL` 两种存储方式：

- `JSON`：适合本地开发与演示
- `MySQL`：适合部署到云服务器供同学通过公网访问

部署相关文件：

- [DEPLOY.md](/d:/桌面/课程/节能减排大赛/DEPLOY.md)
- [config.example.php](/d:/桌面/课程/节能减排大赛/config.example.php)
- [deploy/config.production.example.php](/d:/桌面/课程/节能减排大赛/deploy/config.production.example.php)

## 展示内容补充

如果要继续把首页补成更完整的汇报展示版本，建议优先准备这些信息：

- 学校全称
- 校区名称
- 真实回收点名称、位置、开放时间
- 1 条捐赠案例
- 1 条固定回收点投放案例

详细清单见：

- [展示数据清单.md](/d:/桌面/课程/节能减排大赛/展示数据清单.md)

## 主要文件

- [index.php](/d:/桌面/课程/节能减排大赛/index.php)：主入口与页面逻辑
- [includes/bootstrap.php](/d:/桌面/课程/节能减排大赛/includes/bootstrap.php)：配置、权限、公共函数
- [includes/storage.php](/d:/桌面/课程/节能减排大赛/includes/storage.php)：数据读写
- [includes/ui.php](/d:/桌面/课程/节能减排大赛/includes/ui.php)：公共页面结构
- [assets/css/style.css](/d:/桌面/课程/节能减排大赛/assets/css/style.css)：前端样式
- `data/`：本地数据目录
- `uploads/`：上传图片目录

## 说明

当前项目已经是完整网站，不是静态演示页。  
如果需要继续优化到正式汇报版本，建议下一步优先做三件事：

1. 用真实校区与回收点信息进一步精修首页
2. 用两个测试账号完整走一遍业务流程
3. 修改默认管理员账号密码并备份配置与数据库

## 额外文档

- [ADMIN_ACCESS.md](/d:/桌面/课程/节能减排大赛/ADMIN_ACCESS.md)
- [TROUBLESHOOTING.md](/d:/桌面/课程/节能减排大赛/TROUBLESHOOTING.md)
- [网站设计文档.md](/d:/桌面/课程/节能减排大赛/网站设计文档.md)
- [网站使用文档-用户版.md](/d:/桌面/课程/节能减排大赛/网站使用文档-用户版.md)
- [网站使用文档-管理员版.md](/d:/桌面/课程/节能减排大赛/网站使用文档-管理员版.md)

本地生成“可续写摘要”（用于网络不稳定时继续开发）：

```powershell
php scripts/local_context_compact.php
```
