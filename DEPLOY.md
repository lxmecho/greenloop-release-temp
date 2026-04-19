# 公网部署说明

这个项目已经可以部署到你自己的云服务器上。当前版本已经支持 `JSON` 和 `MySQL` 两种存储，下面按你现在更需要的 `Ubuntu + Nginx + PHP-FPM + MariaDB(MySQL)` 方案说明。

## 适用环境

- Ubuntu 22.04 / 24.04
- Nginx
- PHP 8.1+
- 一个公网 IP
- 可选但强烈推荐：域名

## 项目内已经准备好的部署文件

- [config.example.php](/d:/桌面/课程/节能减排大赛/config.example.php)
- [deploy/config.production.example.php](/d:/桌面/课程/节能减排大赛/deploy/config.production.example.php)
- [deploy/nginx-greenloop.conf.example](/d:/桌面/课程/节能减排大赛/deploy/nginx-greenloop.conf.example)
- [deploy/setup-ubuntu.sh](/d:/桌面/课程/节能减排大赛/deploy/setup-ubuntu.sh)
- [deploy/mysql-create-db.example.sql](/d:/桌面/课程/节能减排大赛/deploy/mysql-create-db.example.sql)
- [scripts/migrate_json_to_mysql.php](/d:/桌面/课程/节能减排大赛/scripts/migrate_json_to_mysql.php)
- [data/.htaccess](/d:/桌面/课程/节能减排大赛/data/.htaccess)
- [includes/.htaccess](/d:/桌面/课程/节能减排大赛/includes/.htaccess)

## 推荐目录结构

```text
/var/www/greenloop
/var/www/greenloop-data
```

说明：

- `/var/www/greenloop` 放网站代码
- `/var/www/greenloop-data` 放账号、申请、消息等私有数据
- 上传图片仍放在网站目录下的 `uploads/`

## 一次性初始化服务器

把 [deploy/setup-ubuntu.sh](/d:/桌面/课程/节能减排大赛/deploy/setup-ubuntu.sh) 上传到服务器后执行：

```bash
chmod +x setup-ubuntu.sh
./setup-ubuntu.sh
```

如果你想自定义路径：

```bash
APP_ROOT=/var/www/greenloop PRIVATE_DATA_ROOT=/var/www/greenloop-data PHP_VERSION=8.2 ./setup-ubuntu.sh
```

## 上传网站代码

把整个项目上传到：

```text
/var/www/greenloop
```

然后在服务器上：

1. 复制 `config.example.php` 为 `config.php`
2. 或直接参考 `deploy/config.production.example.php`
3. 设置真实域名与数据目录

生产环境建议 `config.php` 形如：

```php
<?php
declare(strict_types=1);

define('APP_NAME', '绿循校园');
define('STORAGE_DRIVER', 'mysql');
define('APP_BASE_URL', 'https://your-domain.com');
define('ADMIN_LOGIN_PAGE', 'xmu-greenloop-admin-change-this-path');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'greenloop');
define('DB_USER', 'greenloop_user');
define('DB_PASSWORD', 'ChangeThisPasswordNow123!');
define('DB_CHARSET', 'utf8mb4');
define('DATA_DIR', '/var/www/greenloop-data');
define('UPLOAD_DIR', __DIR__ . '/uploads');
```

管理员登录地址采用隐藏路径，示例：

```text
https://your-domain.com/index.php?page=<ADMIN_LOGIN_PAGE>
```

## 初始化 MySQL 数据库

如果你用服务器本机的 MariaDB：

```bash
sudo mysql < /var/www/greenloop/deploy/mysql-create-db.example.sql
```

然后根据你的真实密码修改 `config.php`。

## 从当前 JSON 数据迁移到 MySQL

如果你已经有本地 JSON 数据，上传后可执行：

```bash
php /var/www/greenloop/scripts/migrate_json_to_mysql.php /var/www/greenloop/data
```

如果是全新站点，不需要这一步，系统会自动初始化管理员账号、回收点和积分商品。

## 配置 Nginx

参考 [deploy/nginx-greenloop.conf.example](/d:/桌面/课程/节能减排大赛/deploy/nginx-greenloop.conf.example)。

典型流程：

1. 复制配置到 `/etc/nginx/sites-available/greenloop`
2. 建立软链接到 `/etc/nginx/sites-enabled/greenloop`
3. 测试配置
4. 重载 Nginx

命令如下：

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## 开放端口

云服务器安全组至少放行：

- `80`
- `443`
- `22`

## HTTPS

如果你已经绑定域名，推荐签发 HTTPS 证书：

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

## 上线后首次检查

依次确认：

1. 首页可以打开
2. 注册和登录可用
3. 图片上传成功
4. 管理员可以登录
5. `data/` 目录无法被浏览器直接访问
6. `uploads/` 可以正常显示图片

## 当前版本的上线定位

这个版本适合：

- 课程实践
- 比赛展示
- 中小规模校内试运行

现在已经支持 MySQL 存储。如果你们后面还要继续做得更正式，我下一步建议再把“按数据集整表 JSON”进一步升级为更规范的业务表结构，这样统计报表和后台筛选会更强。
