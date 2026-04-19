<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 生产环境配置示例
|--------------------------------------------------------------------------
| 1. 复制本文件为 config.php
| 2. 根据你的服务器环境修改下面几个值
| 3. 强烈建议把 DATA_DIR 放到网站根目录之外
*/

define('APP_NAME', '绿循校园');
define('STORAGE_DRIVER', 'json');

/*
|--------------------------------------------------------------------------
| 网站访问地址
|--------------------------------------------------------------------------
| 例如：
| https://greenloop.yourschool.edu.cn
| https://your-domain.com/project
*/
define('APP_BASE_URL', '');
/*
|--------------------------------------------------------------------------
| Hidden admin login path
|--------------------------------------------------------------------------
| Example final URL:
| https://your-domain.com/index.php?page=<ADMIN_LOGIN_PAGE>
| Change this value before production deployment.
*/
define('ADMIN_LOGIN_PAGE', 'xmu-greenloop-admin-change-this-path');

/*
|--------------------------------------------------------------------------
| MySQL 配置
|--------------------------------------------------------------------------
| 当 STORAGE_DRIVER = 'mysql' 时生效
*/
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_CHARSET', 'utf8mb4');

/*
|--------------------------------------------------------------------------
| 短信验证码配置
|--------------------------------------------------------------------------
| DEMO_SMS_MODE = true：演示模式（验证码直接显示在站内提示）
| DEMO_SMS_MODE = false：真实短信模式（调用你配置的短信网关）
|
| 短信网关约定：
| POST JSON 到 SMS_GATEWAY_URL，默认 payload:
| {
|   "phone": "手机号",
|   "code": "6位验证码",
|   "template_id": "模板ID",
|   "sign_name": "签名"
| }
|
| 若配置 SMS_GATEWAY_TOKEN，会在请求头带上：
| Authorization: Bearer <token>
*/
define('DEMO_SMS_MODE', true);
define('SMS_GATEWAY_URL', '');
define('SMS_GATEWAY_TOKEN', '');
define('SMS_TEMPLATE_ID', '');
define('SMS_SIGN_NAME', '');

/*
|--------------------------------------------------------------------------
| 私有数据目录
|--------------------------------------------------------------------------
| 建议改成站点根目录外的真实路径，例如：
| /www/wwwroot_private/greenloop-data
| D:/webdata/greenloop-data
|
| 如果你暂时不会改，也至少保留 data/.htaccess，避免被直接下载。
*/
define('DATA_DIR', __DIR__ . '/data');

/*
|--------------------------------------------------------------------------
| 用户上传图片目录
|--------------------------------------------------------------------------
| 该目录需要可写，并且应当能被网站访问。
*/
define('UPLOAD_DIR', __DIR__ . '/uploads');
