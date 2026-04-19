<?php
declare(strict_types=1);

session_start();
date_default_timezone_set('Asia/Shanghai');

if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

defined('APP_NAME') || define('APP_NAME', '绿循校园');
defined('APP_BASE_URL') || define('APP_BASE_URL', '');
defined('STORAGE_DRIVER') || define('STORAGE_DRIVER', 'json');
defined('DATA_DIR') || define('DATA_DIR', __DIR__ . '/../data');
defined('UPLOAD_DIR') || define('UPLOAD_DIR', __DIR__ . '/../uploads');
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', 3306);
defined('DB_NAME') || define('DB_NAME', '');
defined('DB_USER') || define('DB_USER', '');
defined('DB_PASSWORD') || define('DB_PASSWORD', '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');
defined('DEMO_SMS_MODE') || define('DEMO_SMS_MODE', true);
defined('SMS_GATEWAY_URL') || define('SMS_GATEWAY_URL', '');
defined('SMS_GATEWAY_TOKEN') || define('SMS_GATEWAY_TOKEN', '');
defined('SMS_TEMPLATE_ID') || define('SMS_TEMPLATE_ID', '');
defined('SMS_SIGN_NAME') || define('SMS_SIGN_NAME', '');
defined('ADMIN_LOGIN_PAGE') || define('ADMIN_LOGIN_PAGE', 'xmu-greenloop-admin-6f9c2d71');

require_once __DIR__ . '/storage.php';

initialize_storage();

function current_user(): ?array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return null;
    }

    foreach (load_dataset('users') as $user) {
        if ((int) ($user['id'] ?? 0) === $userId) {
            return $user;
        }
    }

    return null;
}

function is_admin(?array $user): bool
{
    return $user !== null && ($user['role'] ?? '') === 'admin';
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        flash('error', '请先登录后再继续操作。');
        redirect('index.php?page=login');
    }

    return $user;
}

function require_admin_user(): array
{
    $user = require_login();
    if (!is_admin($user)) {
        flash('error', '该页面仅管理员可访问。');
        redirect('index.php');
    }

    return $user;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function utf8_chars(string $value): array
{
    if ($value === '') {
        return [];
    }

    if (preg_match_all('/./us', $value, $matches) === false) {
        return str_split($value);
    }

    return $matches[0] ?? [];
}

function text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return count(utf8_chars($value));
}

function text_lower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

function text_excerpt(string $value, int $width, string $trimMarker = '...'): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $width, $trimMarker, 'UTF-8');
    }

    $chars = utf8_chars($value);
    if (count($chars) <= $width) {
        return $value;
    }

    $trimLength = count(utf8_chars($trimMarker));
    $sliceWidth = max(0, $width - $trimLength);

    return implode('', array_slice($chars, 0, $sliceWidth)) . $trimMarker;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);

    return is_array($flashes) ? $flashes : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        flash('error', '表单已失效，请刷新页面后重试。');
        redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
    }
}

function mask_phone(string $phone): string
{
    if (strlen($phone) !== 11) {
        return $phone;
    }

    return substr($phone, 0, 3) . '****' . substr($phone, -4);
}

function app_url(array $params = []): string
{
    $path = 'index.php' . ($params === [] ? '' : '?' . http_build_query($params));
    if (APP_BASE_URL === '') {
        return $path;
    }

    return rtrim(APP_BASE_URL, '/') . '/' . $path;
}

function post_value(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function get_value(string $key, mixed $default = ''): mixed
{
    return $_GET[$key] ?? $default;
}

function remember_form_state(string $key, array $values): void
{
    $_SESSION['form_state'][$key] = $values;
}

function consume_form_state(string $key): array
{
    $values = $_SESSION['form_state'][$key] ?? [];
    unset($_SESSION['form_state'][$key]);

    return is_array($values) ? $values : [];
}

function phone_code_state_key(string $purpose, string $phone): string
{
    return $purpose . ':' . $phone;
}

function issue_phone_code(string $purpose, string $phone): string
{
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['phone_codes'][phone_code_state_key($purpose, $phone)] = [
        'code' => $code,
        'expires_at' => time() + 300,
    ];

    return $code;
}

function verify_phone_code(string $purpose, string $phone, string $code): bool
{
    $state = $_SESSION['phone_codes'][phone_code_state_key($purpose, $phone)] ?? null;
    if (!is_array($state)) {
        return false;
    }

    if ((int) ($state['expires_at'] ?? 0) < time()) {
        unset($_SESSION['phone_codes'][phone_code_state_key($purpose, $phone)]);
        return false;
    }

    $valid = hash_equals((string) ($state['code'] ?? ''), $code);
    if ($valid) {
        unset($_SESSION['phone_codes'][phone_code_state_key($purpose, $phone)]);
    }

    return $valid;
}

function clear_phone_code(string $purpose, string $phone): void
{
    unset($_SESSION['phone_codes'][phone_code_state_key($purpose, $phone)]);
}

function post_json(string $url, array $payload, array $headers = [], int $timeout = 8): array
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '短信请求参数编码失败。'];
    }

    $requestHeaders = array_merge(['Content-Type: application/json'], $headers);
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '短信网关初始化失败。'];
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        $body = (string) curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($error !== '') {
            return ['ok' => false, 'status' => $status, 'body' => $body, 'error' => '短信网关连接失败：' . $error];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body, 'error' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $requestHeaders),
            'content' => $json,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }

    if ($body === false) {
        return ['ok' => false, 'status' => $status, 'body' => '', 'error' => '短信网关连接失败。'];
    }

    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $body, 'error' => ''];
}

function send_verification_sms(string $phone, string $code): array
{
    if (DEMO_SMS_MODE) {
        return ['ok' => true, 'message' => '验证码已发送，5 分钟内有效。 当前验证码：' . $code];
    }

    if (SMS_GATEWAY_URL === '') {
        return ['ok' => false, 'message' => '短信网关未配置，请联系管理员设置 SMS_GATEWAY_URL。'];
    }

    $headers = [];
    if (SMS_GATEWAY_TOKEN !== '') {
        $headers[] = 'Authorization: Bearer ' . SMS_GATEWAY_TOKEN;
    }

    $response = post_json(SMS_GATEWAY_URL, [
        'phone' => $phone,
        'code' => $code,
        'template_id' => SMS_TEMPLATE_ID,
        'sign_name' => SMS_SIGN_NAME,
    ], $headers);

    if (!$response['ok']) {
        $message = $response['error'] !== '' ? $response['error'] : '短信发送失败，HTTP 状态码：' . (string) $response['status'];
        return ['ok' => false, 'message' => $message];
    }

    $body = trim((string) $response['body']);
    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $success = ($decoded['success'] ?? null) === true
                || ((int) ($decoded['code'] ?? -1) === 0)
                || in_array(strtolower((string) ($decoded['status'] ?? '')), ['ok', 'success'], true);
            if (!$success) {
                $gatewayMessage = (string) ($decoded['message'] ?? $decoded['msg'] ?? '');
                if ($gatewayMessage === '') {
                    $gatewayMessage = '短信网关返回未通过。';
                }
                return ['ok' => false, 'message' => '短信发送失败：' . $gatewayMessage];
            }
        }
    }

    return ['ok' => true, 'message' => '验证码已发送，5 分钟内有效。'];
}

function active_pickup_points(): array
{
    return array_values(array_filter(load_dataset('pickup_points'), static fn(array $point): bool => !empty($point['active'])));
}

function pickup_zone_catalog(): array
{
    return [
        '思明校区' => [
            '芙蓉区',
            '石井区',
            '南光区',
            '凌云区',
            '勤业区',
            '海滨新区',
            '丰庭区',
            '海韵学生公寓',
            '曾厝安学生公寓',
            '留学生区',
        ],
        '翔安校区' => [
            '芙蓉区',
            '南安区',
            '南光区',
            '笃行区',
            '丰庭区',
            '国光区',
            '博学区',
            '凌云区',
            '映雪区',
            '毓秀区',
            '至善区',
            '博士后公寓区',
        ],
    ];
}

function pickup_subpoint_catalog(): array
{
    return [
        '翔安校区|国光区' => [
            '翔安国光3',
            '翔安国光7',
            '翔安国光13',
        ],
        '翔安校区|凌云区' => [
            '翔安校区凌云3',
            '翔安校区凌云5',
            '翔安校区凌云7',
        ],
    ];
}

function pickup_subpoints_for(string $campus, string $zone): array
{
    return pickup_subpoint_catalog()[$campus . '|' . $zone] ?? [];
}

function pickup_requires_subpoint(string $campus, string $zone): bool
{
    return pickup_subpoints_for($campus, $zone) !== [];
}

function pickup_location_display(string $campus, string $zone, string $subpoint = ''): string
{
    $parts = array_values(array_filter([$campus, $zone]));
    if ($subpoint !== '') {
        $parts[] = $subpoint;
    }

    return implode(' / ', $parts);
}

function pickup_showcase_summary(): array
{
    return [
        [
            'title' => '思明校区开放园区',
            'content' => '芙蓉区、石井区、南光区、凌云区、勤业区、海滨新区、丰庭区、海韵学生公寓、曾厝安学生公寓、留学生区',
        ],
        [
            'title' => '翔安校区开放园区',
            'content' => '芙蓉区、南安区、南光区、笃行区、丰庭区、国光区、博学区、凌云区、映雪区、毓秀区、至善区、博士后公寓区',
        ],
        [
            'title' => '翔安重点展示点位',
            'content' => '国光区可选：翔安国光3、翔安国光7、翔安国光13；凌云区可选：翔安校区凌云3、翔安校区凌云5、翔安校区凌云7',
        ],
    ];
}

function door_pickup_building_catalog(): array
{
    return [
        '思明校区' => [
            '芙蓉1号楼',
            '芙蓉2号楼',
            '石井1号楼',
            '石井2号楼',
            '南光1号楼',
            '南光2号楼',
            '凌云1号楼',
            '凌云2号楼',
        ],
        '翔安校区' => [
            '国光1号楼',
            '国光3号楼',
            '国光7号楼',
            '国光13号楼',
            '凌云3号楼',
            '凌云5号楼',
            '凌云7号楼',
            '映雪1号楼',
            '映雪2号楼',
        ],
    ];
}

function door_pickup_buildings_for(string $campus): array
{
    $catalog = door_pickup_building_catalog();
    if ($campus !== '' && isset($catalog[$campus])) {
        return $catalog[$campus];
    }

    return array_values(array_unique(array_merge(...array_values($catalog))));
}

function door_pickup_time_slot_options(): array
{
    return [
        '工作日 18:00-19:30',
        '工作日 19:30-21:00',
        '周三 14:30-17:30',
        '周六 10:00-12:00',
        '周六 15:00-18:00',
    ];
}

function door_pickup_address_display(array $item): string
{
    $parts = array_values(array_filter([
        (string) ($item['door_pickup_campus'] ?? ''),
        (string) ($item['door_pickup_building'] ?? ''),
        (string) ($item['door_pickup_floor'] ?? '') !== '' ? ((string) ($item['door_pickup_floor'] ?? '') . '层') : '',
        (string) ($item['door_pickup_room'] ?? '') !== '' ? ('房间 ' . (string) ($item['door_pickup_room'] ?? '')) : '',
    ]));

    return implode(' / ', $parts);
}

function upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '图片超过服务器允许的上传大小，请压缩到 5MB 以内后重新上传。',
        UPLOAD_ERR_PARTIAL => '图片上传中断，请重新选择图片后提交。',
        UPLOAD_ERR_NO_FILE => '请上传清晰的物品图片。',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => '服务器暂时无法保存图片，请联系管理员检查上传目录。',
        default => '图片上传失败，请重新选择图片后提交。',
    };
}

function detect_image_mime(string $tmpName): string
{
    if ($tmpName === '' || !is_file($tmpName)) {
        return '';
    }

    if (function_exists('finfo_open')) {
        $info = finfo_open(FILEINFO_MIME_TYPE);
        if ($info !== false) {
            $mime = (string) finfo_file($info, $tmpName);
            finfo_close($info);
            return $mime;
        }
    }

    if (function_exists('mime_content_type')) {
        return (string) mime_content_type($tmpName);
    }

    return '';
}

function upload_image(array $file): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($error));
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('图片上传失败，请重新选择图片后提交。');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('图片文件为空，请重新选择图片。');
    }
    if ($size > 5 * 1024 * 1024) {
        throw new RuntimeException('图片大小需控制在 5MB 以内。');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $mime = detect_image_mime($tmpName);
    $mimeExtensions = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    if ($extension === '' && isset($mimeExtensions[$mime])) {
        $extension = $mimeExtensions[$mime];
    }
    if ($extension === 'jpeg') {
        $extension = 'jpg';
    }

    if (in_array($mime, ['image/heic', 'image/heif'], true) || in_array($extension, ['heic', 'heif'], true)) {
        throw new RuntimeException('暂不支持 HEIC/HEIF 图片，请在手机相册中转为 JPG 后重新上传。');
    }

    if (!in_array($extension, $allowedExtensions, true) || ($mime !== '' && !isset($mimeExtensions[$mime]))) {
        throw new RuntimeException('仅支持 JPG、PNG、WebP、GIF 格式图片。');
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true)) {
        throw new RuntimeException('服务器上传目录不可用，请联系管理员处理。');
    }

    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extension;
    $target = UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($tmpName, $target)) {
        throw new RuntimeException('图片上传失败，请稍后重试。');
    }

    return 'uploads/' . $filename;
}

function status_label(string $status): string
{
    return match ($status) {
        'pending_review' => '待审核',
        'published' => '公示中',
        'dropoff_ready' => '待投放',
        'pickup_scheduled' => '待上门回收',
        'matched' => '已匹配',
        'completed' => '已完成',
        'rejected' => '已驳回',
        'cancelled' => '已取消',
        default => '处理中',
    };
}

function application_status_label(string $status): string
{
    return match ($status) {
        'pending' => '待审核',
        'approved' => '已通过',
        'rejected' => '已驳回',
        default => '处理中',
    };
}

function reward_status_label(string $status): string
{
    return match ($status) {
        'pending' => '待处理',
        'fulfilled' => '已发放',
        'rejected' => '已驳回',
        default => '处理中',
    };
}

function disposal_label(string $type): string
{
    return match ($type) {
        'donation' => '捐赠给校内有需要的用户',
        'recycle' => '投放固定回收点',
        'door_pickup' => '上门回收',
        default => '未设置',
    };
}

function condition_label(string $condition): string
{
    return match ($condition) {
        'almost_new' => '近乎全新',
        'good' => '功能良好',
        'fair' => '基本可用',
        'damaged' => '损坏待回收',
        default => '未说明',
    };
}

function campus_options(): array
{
    return array_keys(pickup_zone_catalog());
}

function category_options(): array
{
    return [
        '手机与平板',
        '笔记本与电脑配件',
        '耳机与音响',
        '充电器与数据线',
        '智能穿戴设备',
        '小型电子元件',
        '其他电子产品',
    ];
}
