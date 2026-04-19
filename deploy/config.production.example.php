<?php
declare(strict_types=1);

define('APP_NAME', '绿循校园');
define('STORAGE_DRIVER', 'mysql');
define('APP_BASE_URL', 'https://134139.xyz');
define('ADMIN_LOGIN_PAGE', 'xmu-greenloop-admin-change-this-path');

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'greenloop');
define('DB_USER', 'greenloop_user');
define('DB_PASSWORD', 'ChangeThisPasswordNow123!');
define('DB_CHARSET', 'utf8mb4');

/*
|--------------------------------------------------------------------------
| 短信验证码（生产环境建议关闭演示模式）
|--------------------------------------------------------------------------
*/
define('DEMO_SMS_MODE', false);
define('SMS_GATEWAY_URL', 'https://your-sms-gateway.example.com/send');
define('SMS_GATEWAY_TOKEN', 'your-token');
define('SMS_TEMPLATE_ID', 'your-template-id');
define('SMS_SIGN_NAME', '绿循校园');

/*
|--------------------------------------------------------------------------
| 强烈建议放在网站根目录之外
|--------------------------------------------------------------------------
*/
define('DATA_DIR', '/var/www/greenloop-data');

/*
|--------------------------------------------------------------------------
| 上传目录保留在网站根目录内，便于图片访问
|--------------------------------------------------------------------------
*/
define('UPLOAD_DIR', __DIR__ . '/uploads');
