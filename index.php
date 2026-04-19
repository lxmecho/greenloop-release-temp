<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ui.php';

$currentUser = current_user();

function user_lookup(): array
{
    $indexed = [];
    foreach (load_dataset('users') as $user) {
        $indexed[(int) $user['id']] = $user;
    }
    return $indexed;
}

function item_lookup(): array
{
    $indexed = [];
    foreach (load_dataset('items') as $item) {
        $indexed[(int) $item['id']] = $item;
    }
    return $indexed;
}

function reward_lookup(): array
{
    $indexed = [];
    foreach (load_dataset('rewards') as $reward) {
        $indexed[(int) $reward['id']] = $reward;
    }
    return $indexed;
}

function point_lookup(): array
{
    $indexed = [];
    foreach (load_dataset('pickup_points') as $point) {
        $indexed[(int) $point['id']] = $point;
    }
    return $indexed;
}

function active_announcements(): array
{
    $announcements = array_values(array_filter(
        load_dataset('announcements'),
        static fn(array $announcement): bool => !empty($announcement['active'])
    ));

    usort($announcements, static function (array $left, array $right): int {
        return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
    });

    return $announcements;
}

function item_badge_class(string $status): string
{
    return match ($status) {
        'rejected' => 'badge-danger',
        'pending_review', 'dropoff_ready', 'pickup_scheduled' => 'badge-warn',
        'completed' => 'badge-neutral',
        default => '',
    };
}

function application_badge_class(string $status): string
{
    return match ($status) {
        'approved' => '',
        'rejected' => 'badge-danger',
        default => 'badge-warn',
    };
}

function reward_badge_class(string $status): string
{
    return match ($status) {
        'fulfilled' => '',
        'rejected' => 'badge-danger',
        default => 'badge-warn',
    };
}

function site_stats(): array
{
    $items = load_dataset('items');
    $users = load_dataset('users');
    $applications = load_dataset('applications');
    $completed = count(array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') === 'completed'));
    $listed = count(array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') === 'published'));
    $dropoffReady = count(array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') === 'dropoff_ready'));
    $pickupScheduled = count(array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') === 'pickup_scheduled'));
    $pending = count(array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') === 'pending_review'));

    return [
        'users' => max(0, count($users) - 1),
        'items' => count($items),
        'listed' => $listed,
        'dropoff_ready' => $dropoffReady,
        'pickup_scheduled' => $pickupScheduled,
        'completed' => $completed,
        'pending' => $pending,
        'applications' => count($applications),
    ];
}

function find_item(int $id): ?array
{
    return find_record(load_dataset('items'), $id);
}

function find_application(int $id): ?array
{
    return find_record(load_dataset('applications'), $id);
}

function find_reward(int $id): ?array
{
    return find_record(load_dataset('rewards'), $id);
}

function is_demo_record(array $record): bool
{
    return str_starts_with((string) ($record['demo_key'] ?? ''), 'demo_flow_');
}

function initialize_demo_flow_data(): array
{
    $now = now();
    $demoPhones = ['13900000001', '13900000002', '13900000003'];
    $demoTitles = [
        '罗技无线鼠标',
        '倍思蓝牙耳机',
        '损坏充电器',
        '旧数据线',
        '废旧插线板',
        '演示用无线鼠标',
        '演示用蓝牙耳机',
        '演示用损坏充电器',
        '演示用旧数据线',
        '演示用上门回收设备',
    ];

    $users = load_dataset('users');
    $oldDemoUserIds = [];
    foreach ($users as $user) {
        if (in_array((string) ($user['phone'] ?? ''), $demoPhones, true) || is_demo_record($user)) {
            $oldDemoUserIds[] = (int) ($user['id'] ?? 0);
        }
    }

    $users = array_values(array_filter($users, static function (array $user) use ($demoPhones): bool {
        return !in_array((string) ($user['phone'] ?? ''), $demoPhones, true) && !is_demo_record($user);
    }));

    $demoUserSpecs = [
        [
            'key' => 'donor',
            'phone' => '13900000001',
            'nickname' => '思明捐赠账号',
            'campus' => '思明校区',
            'points' => 15,
        ],
        [
            'key' => 'recycler',
            'phone' => '13900000002',
            'nickname' => '翔安回收账号',
            'campus' => '翔安校区',
            'points' => 5,
        ],
        [
            'key' => 'applicant',
            'phone' => '13900000003',
            'nickname' => '公示申领账号',
            'campus' => '翔安校区',
            'points' => 0,
        ],
    ];

    $demoUserIds = [];
    foreach ($demoUserSpecs as $spec) {
        $id = next_id($users);
        $users[] = [
            'id' => $id,
            'role' => 'user',
            'phone' => $spec['phone'],
            'password_hash' => password_hash('demo123456', PASSWORD_DEFAULT),
            'nickname' => $spec['nickname'],
            'campus' => $spec['campus'],
            'points' => $spec['points'],
            'demo_key' => 'demo_flow_user_' . $spec['key'],
            'created_at' => $now,
        ];
        $demoUserIds[$spec['key']] = $id;
    }
    save_dataset('users', $users);

    $items = load_dataset('items');
    $oldDemoItemIds = [];
    foreach ($items as $item) {
        if (is_demo_record($item)
            || in_array((int) ($item['user_id'] ?? 0), $oldDemoUserIds, true)
            || in_array((string) ($item['title'] ?? ''), $demoTitles, true)
        ) {
            $oldDemoItemIds[] = (int) ($item['id'] ?? 0);
        }
    }
    $items = array_values(array_filter($items, static function (array $item) use ($oldDemoUserIds, $demoTitles): bool {
        $userId = (int) ($item['user_id'] ?? 0);
        return !is_demo_record($item)
            && !in_array($userId, $oldDemoUserIds, true)
            && !in_array((string) ($item['title'] ?? ''), $demoTitles, true);
    }));

    $publishedDonationId = next_id($items);
    $items[] = [
        'id' => $publishedDonationId,
        'user_id' => $demoUserIds['donor'],
        'title' => '罗技无线鼠标',
        'category' => '笔记本与电脑配件',
        'brand' => 'Logitech / 罗技',
        'condition' => 'good',
        'description' => '鼠标连接稳定，左右键和滚轮功能正常，适合日常课程学习、图书馆自习或办公使用。',
        'image' => 'assets/images/demo-donation.svg',
        'disposal_type' => 'donation',
        'target_group' => '有日常学习或办公需要的校内用户',
        'donation_reason' => '设备仍可继续使用，希望通过平台转给真正需要的人。',
        'expected_price' => 0,
        'pickup_point_id' => 0,
        'pickup_campus' => '',
        'pickup_zone' => '',
        'pickup_subpoint' => '',
        'pickup_display' => '',
        'pickup_time' => '',
        'door_pickup_campus' => '',
        'door_pickup_building' => '',
        'door_pickup_floor' => '',
        'door_pickup_room' => '',
        'door_pickup_slot' => '',
        'status' => 'published',
        'admin_note' => '已审核通过并进入公示大厅。',
        'points_awarded' => true,
        'matched_application_id' => 0,
        'demo_key' => 'demo_flow_item_published_donation',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $pendingDonationId = next_id($items);
    $items[] = [
        'id' => $pendingDonationId,
        'user_id' => $demoUserIds['applicant'],
        'title' => '倍思蓝牙耳机',
        'category' => '耳机与音响',
        'brand' => 'Baseus / 倍思',
        'condition' => 'fair',
        'description' => '耳机可正常连接，电量续航一般，适合短时间听课或通勤使用。',
        'image' => 'assets/images/demo-donation.svg',
        'disposal_type' => 'donation',
        'target_group' => '需要临时听课或自习使用的校内用户',
        'donation_reason' => '希望交给仍能使用它的人，减少闲置浪费。',
        'expected_price' => 0,
        'pickup_point_id' => 0,
        'pickup_campus' => '',
        'pickup_zone' => '',
        'pickup_subpoint' => '',
        'pickup_display' => '',
        'pickup_time' => '',
        'door_pickup_campus' => '',
        'door_pickup_building' => '',
        'door_pickup_floor' => '',
        'door_pickup_room' => '',
        'door_pickup_slot' => '',
        'status' => 'pending_review',
        'admin_note' => '',
        'points_awarded' => false,
        'matched_application_id' => 0,
        'demo_key' => 'demo_flow_item_pending_donation',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $dropoffRecycleId = next_id($items);
    $items[] = [
        'id' => $dropoffRecycleId,
        'user_id' => $demoUserIds['recycler'],
        'title' => '损坏充电器',
        'category' => '充电器与数据线',
        'brand' => 'Apple / 苹果',
        'condition' => 'damaged',
        'description' => '充电头外壳老化，已不建议继续使用，适合投放到固定回收点统一处理。',
        'image' => 'assets/images/demo-recycle.svg',
        'disposal_type' => 'recycle',
        'target_group' => '',
        'donation_reason' => '',
        'expected_price' => 0,
        'pickup_point_id' => 0,
        'pickup_campus' => '翔安校区',
        'pickup_zone' => '国光区',
        'pickup_subpoint' => '翔安国光7',
        'pickup_display' => pickup_location_display('翔安校区', '国光区', '翔安国光7'),
        'pickup_time' => '周三 18:00-20:00',
        'door_pickup_campus' => '',
        'door_pickup_building' => '',
        'door_pickup_floor' => '',
        'door_pickup_room' => '',
        'door_pickup_slot' => '',
        'status' => 'dropoff_ready',
        'admin_note' => '已审核通过，等待按预约时间投放。',
        'points_awarded' => true,
        'matched_application_id' => 0,
        'demo_key' => 'demo_flow_item_dropoff_recycle',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $completedRecycleId = next_id($items);
    $items[] = [
        'id' => $completedRecycleId,
        'user_id' => $demoUserIds['recycler'],
        'title' => '旧数据线',
        'category' => '充电器与数据线',
        'brand' => '通用配件',
        'condition' => 'damaged',
        'description' => '接口接触不良，已完成固定回收点投放并由管理人员统一收集。',
        'image' => 'assets/images/demo-recycle.svg',
        'disposal_type' => 'recycle',
        'target_group' => '',
        'donation_reason' => '',
        'expected_price' => 0,
        'pickup_point_id' => 0,
        'pickup_campus' => '翔安校区',
        'pickup_zone' => '凌云区',
        'pickup_subpoint' => '翔安校区凌云5',
        'pickup_display' => pickup_location_display('翔安校区', '凌云区', '翔安校区凌云5'),
        'pickup_time' => '上周五 19:00',
        'door_pickup_campus' => '',
        'door_pickup_building' => '',
        'door_pickup_floor' => '',
        'door_pickup_room' => '',
        'door_pickup_slot' => '',
        'status' => 'completed',
        'admin_note' => '已完成回收点投放与集中处理。',
        'points_awarded' => true,
        'matched_application_id' => 0,
        'demo_key' => 'demo_flow_item_completed_recycle',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $doorPickupId = next_id($items);
    $items[] = [
        'id' => $doorPickupId,
        'user_id' => $demoUserIds['recycler'],
        'title' => '废旧插线板',
        'category' => '其他电子产品',
        'brand' => '公牛',
        'condition' => 'damaged',
        'description' => '插孔接触不稳定，宿舍内不建议继续使用，已申请管理员上门回收。',
        'image' => 'assets/images/demo-recycle.svg',
        'disposal_type' => 'door_pickup',
        'target_group' => '',
        'donation_reason' => '',
        'expected_price' => 0,
        'pickup_point_id' => 0,
        'pickup_campus' => '',
        'pickup_zone' => '',
        'pickup_subpoint' => '',
        'pickup_display' => '',
        'pickup_time' => '',
        'door_pickup_campus' => '翔安校区',
        'door_pickup_building' => '国光7号楼',
        'door_pickup_floor' => '5',
        'door_pickup_room' => '507',
        'door_pickup_slot' => '工作日 19:30-21:00',
        'status' => 'pickup_scheduled',
        'admin_note' => '已审核通过，等待按预约时段上门回收。',
        'points_awarded' => false,
        'matched_application_id' => 0,
        'demo_key' => 'demo_flow_item_door_pickup',
        'created_at' => $now,
        'updated_at' => $now,
    ];
    save_dataset('items', $items);

    $applications = load_dataset('applications');
    $applications = array_values(array_filter($applications, static function (array $application) use ($oldDemoUserIds, $oldDemoItemIds): bool {
        return !is_demo_record($application)
            && !in_array((int) ($application['applicant_id'] ?? 0), $oldDemoUserIds, true)
            && !in_array((int) ($application['item_id'] ?? 0), $oldDemoItemIds, true);
    }));
    $applications[] = [
        'id' => next_id($applications),
        'item_id' => $publishedDonationId,
        'applicant_id' => $demoUserIds['applicant'],
        'purpose' => '课程作业和图书馆自习需要使用鼠标，希望申请领取继续使用。',
        'status' => 'pending',
        'admin_reply' => '',
        'demo_key' => 'demo_flow_application_pending',
        'created_at' => $now,
        'updated_at' => $now,
    ];
    save_dataset('applications', $applications);

    $rewards = load_dataset('rewards');
    if ($rewards === []) {
        $rewards[] = [
            'id' => 1,
            'name' => '环保主题徽章',
            'points_cost' => 30,
            'stock' => 20,
            'description' => '用于鼓励持续参与校园电子废物规范处理。',
            'image' => '',
            'active' => true,
        ];
    }
    $rewardId = (int) ($rewards[0]['id'] ?? 1);
    $rewards[0]['active'] = true;
    $rewards[0]['stock'] = max(10, (int) ($rewards[0]['stock'] ?? 0));
    save_dataset('rewards', $rewards);

    $redemptions = load_dataset('redemptions');
    $redemptions = array_values(array_filter($redemptions, static function (array $redemption) use ($oldDemoUserIds): bool {
        return !is_demo_record($redemption)
            && !in_array((int) ($redemption['user_id'] ?? 0), $oldDemoUserIds, true);
    }));
    $redemptions[] = [
        'id' => next_id($redemptions),
        'user_id' => $demoUserIds['donor'],
        'reward_id' => $rewardId,
        'points_spent' => (int) ($rewards[0]['points_cost'] ?? 30),
        'status' => 'pending',
        'admin_note' => '',
        'refunded' => false,
        'demo_key' => 'demo_flow_redemption_pending',
        'created_at' => $now,
    ];
    save_dataset('redemptions', $redemptions);

    $messages = load_dataset('messages');
    $messages = array_values(array_filter($messages, static function (array $message) use ($oldDemoUserIds): bool {
        return !is_demo_record($message)
            && !in_array((int) ($message['user_id'] ?? 0), $oldDemoUserIds, true);
    }));
    $messageTemplates = [
        [
            'user_id' => $demoUserIds['donor'],
            'title' => '捐赠物品已公示',
            'content' => '你提交的《罗技无线鼠标》已审核通过并进入公示大厅。',
            'link' => app_url(['page' => 'dashboard']),
        ],
        [
            'user_id' => $demoUserIds['recycler'],
            'title' => '回收点投放已确认',
            'content' => '你提交的《损坏充电器》已通过审核，请按预约时间投放至翔安校区 / 国光区 / 翔安国光7。',
            'link' => app_url(['page' => 'dashboard']),
        ],
        [
            'user_id' => $demoUserIds['recycler'],
            'title' => '上门回收已预约',
            'content' => '你提交的《废旧插线板》已通过审核，管理员会在预约时段上门回收。',
            'link' => app_url(['page' => 'dashboard']),
        ],
        [
            'user_id' => $demoUserIds['applicant'],
            'title' => '申请待审核',
            'content' => '你已提交《罗技无线鼠标》的申领申请，请等待管理员审核。',
            'link' => app_url(['page' => 'dashboard', 'section' => 'applications']),
        ],
    ];
    foreach ($messageTemplates as $template) {
        $messages[] = [
            'id' => next_id($messages),
            'user_id' => $template['user_id'],
            'title' => $template['title'],
            'content' => $template['content'],
            'link' => $template['link'],
            'is_read' => false,
            'demo_key' => 'demo_flow_message_' . next_id($messages),
            'created_at' => $now,
        ];
    }
    save_dataset('messages', $messages);

    return [
        'users' => count($demoUserSpecs),
        'items' => 5,
        'applications' => 1,
        'redemptions' => 1,
        'password' => 'demo123456',
        'phones' => $demoPhones,
    ];
}

function process_post(?array $currentUser): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    switch ($action) {
        case 'send_register_code':
            $phone = trim((string) post_value('phone'));
            $nickname = trim((string) post_value('nickname'));
            $campus = trim((string) post_value('campus'));

            remember_form_state('register', [
                'phone' => $phone,
                'nickname' => $nickname,
                'campus' => $campus,
            ]);

            if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
                flash('error', '请输入正确的 11 位手机号后再获取验证码。');
                redirect(app_url(['page' => 'login', 'tab' => 'register']));
            }

            $users = load_dataset('users');
            foreach ($users as $user) {
                if (($user['phone'] ?? '') === $phone) {
                    flash('error', '该手机号已注册，请直接登录。');
                    redirect(app_url(['page' => 'login', 'tab' => 'register']));
                }
            }

            $code = issue_phone_code('register', $phone);
            $smsResult = send_verification_sms($phone, $code);
            if (!($smsResult['ok'] ?? false)) {
                clear_phone_code('register', $phone);
                flash('error', (string) ($smsResult['message'] ?? '验证码发送失败，请稍后重试。'));
                redirect(app_url(['page' => 'login', 'tab' => 'register']));
            }

            flash('success', (string) ($smsResult['message'] ?? '验证码已发送，5 分钟内有效。'));
            redirect(app_url(['page' => 'login', 'tab' => 'register']));

        case 'register':
            $phone = trim((string) post_value('phone'));
            $code = trim((string) post_value('verification_code'));
            $password = (string) post_value('password');
            $nickname = trim((string) post_value('nickname'));
            $campus = trim((string) post_value('campus'));

            remember_form_state('register', [
                'phone' => $phone,
                'nickname' => $nickname,
                'campus' => $campus,
            ]);

            if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
                flash('error', '请输入正确的 11 位手机号。');
                redirect(app_url(['page' => 'login', 'tab' => 'register']));
            }
            if (text_length($password) < 6) {
                flash('error', '密码长度至少为 6 位。');
                redirect(app_url(['page' => 'login', 'tab' => 'register']));
            }
            $campusValid = $campus !== '' && !str_contains($campus, '请选择') && in_array($campus, campus_options(), true);
            if ($nickname === '' || !$campusValid) {
                flash('error', '请完整填写昵称和校区。');
                redirect(app_url(['page' => 'login', 'tab' => 'register']));
            }
            if (!preg_match('/^\d{6}$/', $code)) {
                flash('error', '请输入 6 位短信验证码。');
                redirect(app_url(['page' => 'login', 'tab' => 'register']));
            }
            if (!verify_phone_code('register', $phone, $code)) {
                flash('error', '验证码无效或已过期，请重新获取。');
                redirect(app_url(['page' => 'login', 'tab' => 'register']));
            }

            $users = load_dataset('users');
            foreach ($users as $user) {
                if (($user['phone'] ?? '') === $phone) {
                    flash('error', '该手机号已注册，请直接登录。');
                    redirect(app_url(['page' => 'login', 'tab' => 'register']));
                }
            }

            $users[] = [
                'id' => next_id($users),
                'role' => 'user',
                'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'nickname' => $nickname,
                'campus' => $campus,
                'points' => 0,
                'created_at' => now(),
            ];
            save_dataset('users', $users);
            $_SESSION['user_id'] = (int) end($users)['id'];
            remember_form_state('register', []);
            flash('success', '注册成功，已自动登录。');
            redirect(app_url(['page' => 'dashboard']));

        case 'login':
            $phone = trim((string) post_value('phone'));
            $password = (string) post_value('password');
            $scope = (string) post_value('login_scope', 'user');
            remember_form_state('login', ['phone' => $phone]);
            foreach (load_dataset('users') as $user) {
                if (($user['phone'] ?? '') === $phone && password_verify($password, (string) ($user['password_hash'] ?? ''))) {
                    if ($scope === 'user' && is_admin($user)) {
                        flash('error', '该账号为管理员账号，请使用管理员专用入口登录。');
                        redirect(app_url(['page' => ADMIN_LOGIN_PAGE]));
                    }
                    if ($scope === 'admin' && !is_admin($user)) {
                        flash('error', '当前入口仅供管理员使用，请返回普通登录页。');
                        redirect(app_url(['page' => 'login']));
                    }
                    $_SESSION['user_id'] = (int) $user['id'];
                    remember_form_state('login', []);
                    flash('success', '登录成功，欢迎回来。');
                    redirect(app_url(['page' => is_admin($user) ? 'admin' : 'dashboard']));
                }
            }
            flash('error', '手机号或密码错误。');
            redirect(app_url(['page' => $scope === 'admin' ? ADMIN_LOGIN_PAGE : 'login']));

        case 'submit_item':
            $user = require_login();
            $title = trim((string) post_value('title'));
            $category = trim((string) post_value('category'));
            $condition = trim((string) post_value('condition'));
            $description = trim((string) post_value('description'));
            $disposalType = trim((string) post_value('disposal_type'));
            $brand = trim((string) post_value('brand'));
            $targetGroup = $disposalType === 'donation'
                ? trim((string) post_value('donation_target_group'))
                : '';
            $donationReason = trim((string) post_value('donation_reason'));
            $pickupCampus = trim((string) post_value('pickup_campus'));
            $pickupZone = trim((string) post_value('pickup_zone'));
            $pickupSubpoint = trim((string) post_value('pickup_subpoint'));
            $pickupTime = trim((string) post_value('pickup_time'));
            $doorPickupCampus = (string) ($user['campus'] ?? '');
            $doorPickupBuilding = trim((string) post_value('door_pickup_building'));
            $doorPickupFloor = trim((string) post_value('door_pickup_floor'));
            $doorPickupRoom = trim((string) post_value('door_pickup_room'));
            $doorPickupSlot = trim((string) post_value('door_pickup_slot'));
            $pickupPointId = 0;

            if ($title === '' || $category === '' || $condition === '' || $description === '' || $disposalType === '') {
                flash('error', '请完整填写物品名称、类别、成色、描述和处理方式。');
                redirect(app_url(['page' => 'submit']));
            }

            if (!in_array($disposalType, ['donation', 'recycle', 'door_pickup'], true)) {
                flash('error', '当前仅支持“捐赠”“投放固定回收点”和“上门回收”三种处理方式。');
                redirect(app_url(['page' => 'submit']));
            }

            try {
                $imagePath = upload_image($_FILES['image'] ?? []);
            } catch (RuntimeException $exception) {
                flash('error', $exception->getMessage());
                redirect(app_url(['page' => 'submit']));
            }

            if ($disposalType === 'donation' && $donationReason === '') {
                flash('error', '捐赠类物品请填写你的捐赠说明。');
                redirect(app_url(['page' => 'submit']));
            }

            $pickupDisplay = '';
            if ($disposalType === 'recycle') {
                $zoneCatalog = pickup_zone_catalog();
                if ($pickupCampus === '' || !isset($zoneCatalog[$pickupCampus])) {
                    flash('error', '请选择投放回收点所属校区。');
                    redirect(app_url(['page' => 'submit']));
                }

                $zones = $zoneCatalog[$pickupCampus];
                if ($pickupZone === '' || !in_array($pickupZone, $zones, true)) {
                    flash('error', '请选择正确的投放园区。');
                    redirect(app_url(['page' => 'submit']));
                }

                $subpoints = pickup_subpoints_for($pickupCampus, $pickupZone);
                if ($subpoints !== []) {
                    if ($pickupSubpoint === '' || !in_array($pickupSubpoint, $subpoints, true)) {
                        flash('error', '该园区需要继续选择具体投放点位。');
                        redirect(app_url(['page' => 'submit']));
                    }
                } else {
                    $pickupSubpoint = '';
                }

                if ($pickupTime === '') {
                    flash('error', '请补充预计投递时间。');
                    redirect(app_url(['page' => 'submit']));
                }

                $pickupDisplay = pickup_location_display($pickupCampus, $pickupZone, $pickupSubpoint);
            }

            if ($disposalType === 'door_pickup') {
                $availableBuildings = door_pickup_buildings_for($doorPickupCampus);
                if ($doorPickupBuilding === '' || !in_array($doorPickupBuilding, $availableBuildings, true)) {
                    flash('error', '请选择宿舍楼栋。');
                    redirect(app_url(['page' => 'submit']));
                }

                if ($doorPickupFloor === '' || !ctype_digit($doorPickupFloor) || (int) $doorPickupFloor < 1 || (int) $doorPickupFloor > 30) {
                    flash('error', '请填写正确的宿舍楼层。');
                    redirect(app_url(['page' => 'submit']));
                }

                if ($doorPickupRoom === '' || text_length($doorPickupRoom) > 20) {
                    flash('error', '请填写房间号，长度不超过 20 个字符。');
                    redirect(app_url(['page' => 'submit']));
                }

                $availableSlots = door_pickup_time_slot_options();
                if ($doorPickupSlot === '' || !in_array($doorPickupSlot, $availableSlots, true)) {
                    flash('error', '请选择可预约时间段。');
                    redirect(app_url(['page' => 'submit']));
                }

                $pickupCampus = '';
                $pickupZone = '';
                $pickupSubpoint = '';
                $pickupTime = '';
            }

            $items = load_dataset('items');
            $items[] = [
                'id' => next_id($items),
                'user_id' => (int) $user['id'],
                'title' => $title,
                'category' => $category,
                'brand' => $brand,
                'condition' => $condition,
                'description' => $description,
                'image' => $imagePath,
                'disposal_type' => $disposalType,
                'target_group' => $targetGroup,
                'donation_reason' => $donationReason,
                'expected_price' => 0,
                'pickup_point_id' => $pickupPointId,
                'pickup_campus' => $pickupCampus,
                'pickup_zone' => $pickupZone,
                'pickup_subpoint' => $pickupSubpoint,
                'pickup_display' => $pickupDisplay,
                'pickup_time' => $pickupTime,
                'door_pickup_campus' => $disposalType === 'door_pickup' ? $doorPickupCampus : '',
                'door_pickup_building' => $disposalType === 'door_pickup' ? $doorPickupBuilding : '',
                'door_pickup_floor' => $disposalType === 'door_pickup' ? $doorPickupFloor : '',
                'door_pickup_room' => $disposalType === 'door_pickup' ? $doorPickupRoom : '',
                'door_pickup_slot' => $disposalType === 'door_pickup' ? $doorPickupSlot : '',
                'status' => 'pending_review',
                'admin_note' => '',
                'points_awarded' => false,
                'matched_application_id' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            save_dataset('items', $items);
            flash('success', '物品提交成功，管理员审核通过后会进入公示或回收流程。');
            redirect(app_url(['page' => 'dashboard']));

        case 'apply_item':
            $user = require_login();
            $itemId = (int) post_value('item_id', 0);
            $purpose = trim((string) post_value('purpose'));
            $item = find_item($itemId);

            if ($item === null || ($item['status'] ?? '') !== 'published') {
                flash('error', '该物品当前不可申请。');
                redirect(app_url(['page' => 'listings']));
            }
            if ((int) ($item['user_id'] ?? 0) === (int) $user['id']) {
                flash('error', '不能申请自己发布的物品。');
                redirect(app_url(['page' => 'item', 'id' => $itemId]));
            }
            if ($purpose === '') {
                flash('error', '请填写申请原因或用途说明。');
                redirect(app_url(['page' => 'item', 'id' => $itemId]));
            }

            $applications = load_dataset('applications');
            foreach ($applications as $application) {
                if ((int) ($application['item_id'] ?? 0) === $itemId && (int) ($application['applicant_id'] ?? 0) === (int) $user['id'] && ($application['status'] ?? '') === 'pending') {
                    flash('error', '你已经提交过申请，请等待管理员审核。');
                    redirect(app_url(['page' => 'item', 'id' => $itemId]));
                }
            }

            $applications[] = [
                'id' => next_id($applications),
                'item_id' => $itemId,
                'applicant_id' => (int) $user['id'],
                'purpose' => $purpose,
                'status' => 'pending',
                'admin_reply' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            save_dataset('applications', $applications);
            add_message((int) $item['user_id'], '有新的物品申请', '你发布的《' . $item['title'] . '》收到了新的申请，管理员审核后会继续通知你。', app_url(['page' => 'dashboard']));
            flash('success', '申请已提交，请耐心等待管理员审核。');
            redirect(app_url(['page' => 'dashboard']));

        case 'redeem_reward':
            $user = require_login();
            $rewardId = (int) post_value('reward_id', 0);
            $reward = find_reward($rewardId);

            if ($reward === null || empty($reward['active'])) {
                flash('error', '该兑换商品当前不可用。');
                redirect(app_url(['page' => 'points']));
            }
            if ((int) ($reward['stock'] ?? 0) <= 0) {
                flash('error', '该商品库存不足。');
                redirect(app_url(['page' => 'points']));
            }
            if ((int) ($user['points'] ?? 0) < (int) ($reward['points_cost'] ?? 0)) {
                flash('error', '当前积分不足，暂时无法兑换。');
                redirect(app_url(['page' => 'points']));
            }

            $rewards = load_dataset('rewards');
            foreach ($rewards as &$storedReward) {
                if ((int) ($storedReward['id'] ?? 0) === $rewardId) {
                    $storedReward['stock'] = max(0, (int) ($storedReward['stock'] ?? 0) - 1);
                }
            }
            unset($storedReward);
            save_dataset('rewards', $rewards);

            adjust_user_points((int) $user['id'], -((int) $reward['points_cost']), '积分兑换申请已提交', '你已申请兑换《' . $reward['name'] . '》，管理员处理后会发送发放通知。', app_url(['page' => 'points']));

            $redemptions = load_dataset('redemptions');
            $redemptions[] = [
                'id' => next_id($redemptions),
                'reward_id' => $rewardId,
                'user_id' => (int) $user['id'],
                'points_spent' => (int) $reward['points_cost'],
                'status' => 'pending',
                'admin_note' => '',
                'refunded' => false,
                'created_at' => now(),
            ];
            save_dataset('redemptions', $redemptions);
            flash('success', '兑换申请已提交，积分已暂扣，等待管理员确认。');
            redirect(app_url(['page' => 'points']));

        case 'mark_message_read':
            $user = require_login();
            mark_message_as_read((int) post_value('message_id', 0), (int) $user['id']);
            redirect(app_url(['page' => 'dashboard', 'section' => 'messages']));

        case 'admin_seed_demo_data':
            require_admin_user();
            $summary = initialize_demo_flow_data();
            flash(
                'success',
                '演示流程数据已初始化：生成 '
                . $summary['users'] . ' 个演示账号、'
                . $summary['items'] . ' 条物品、'
                . $summary['applications'] . ' 条申领申请、'
                . $summary['redemptions'] . ' 条积分兑换申请。演示账号：'
                . implode(' / ', $summary['phones'])
                . '，统一密码：' . $summary['password'] . '。'
            );
            redirect(app_url(['page' => 'admin', 'tab' => 'overview']));

        case 'admin_update_announcement':
            require_admin_user();
            $title = trim((string) post_value('title'));
            $content = trim((string) post_value('content'));
            $active = post_value('active', '0') === '1';

            if ($title === '' || $content === '') {
                flash('error', '请填写公告标题和公告内容。');
                redirect(app_url(['page' => 'admin', 'tab' => 'announcements']));
            }

            $announcements = load_dataset('announcements');
            if ($announcements === []) {
                $announcements[] = [
                    'id' => 1,
                    'title' => $title,
                    'content' => $content,
                    'active' => $active,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } else {
                $announcements[0]['title'] = $title;
                $announcements[0]['content'] = $content;
                $announcements[0]['active'] = $active;
                $announcements[0]['updated_at'] = now();
            }

            save_dataset('announcements', $announcements);
            flash('success', '公告已更新，首页会同步显示最新内容。');
            redirect(app_url(['page' => 'admin', 'tab' => 'announcements']));

        case 'admin_review_item':
            require_admin_user();
            $itemId = (int) post_value('item_id', 0);
            $decision = (string) post_value('decision');
            $note = trim((string) post_value('admin_note'));
            $items = load_dataset('items');

            foreach ($items as &$item) {
                if ((int) ($item['id'] ?? 0) !== $itemId) {
                    continue;
                }

                $item['admin_note'] = $note;
                $item['updated_at'] = now();

                if ($decision === 'approve') {
                    $disposalType = (string) ($item['disposal_type'] ?? '');
                    $item['status'] = match ($disposalType) {
                        'recycle' => 'dropoff_ready',
                        'door_pickup' => 'pickup_scheduled',
                        default => 'published',
                    };
                    if ($disposalType !== 'door_pickup' && empty($item['points_awarded'])) {
                        $item['points_awarded'] = true;
                        adjust_user_points((int) $item['user_id'], 5, '物品审核通过，积分到账', '你提交的《' . $item['title'] . '》已通过审核，系统已发放 5 积分。', app_url(['page' => 'dashboard']));
                    }
                    if ($disposalType === 'door_pickup') {
                        add_message((int) $item['user_id'], '上门回收已预约', '你提交的《' . $item['title'] . '》已通过审核，管理员会按预约时段上门回收。回收完成后系统会再发放 5 积分。' . ($note !== '' ? '管理员备注：' . $note : ''), app_url(['page' => 'dashboard']));
                    } else {
                        add_message((int) $item['user_id'], '物品审核通过', '你提交的《' . $item['title'] . '》已通过审核。' . ($note !== '' ? '管理员备注：' . $note : ''), app_url(['page' => 'dashboard']));
                    }
                } else {
                    $item['status'] = 'rejected';
                    add_message((int) $item['user_id'], '物品审核未通过', '你提交的《' . $item['title'] . '》未通过审核。' . ($note !== '' ? '原因：' . $note : '请补充更准确的物品信息后重新提交。'), app_url(['page' => 'submit']));
                }
                break;
            }
            unset($item);

            save_dataset('items', $items);
            flash('success', '物品审核结果已保存。');
            redirect(app_url(['page' => 'admin', 'tab' => 'items']));

        case 'admin_complete_item':
            require_admin_user();
            $itemId = (int) post_value('item_id', 0);
            $note = trim((string) post_value('admin_note'));
            $items = load_dataset('items');
            $applications = load_dataset('applications');
            $redirectTab = 'items';

            foreach ($items as &$item) {
                if ((int) ($item['id'] ?? 0) !== $itemId) {
                    continue;
                }

                $item['status'] = 'completed';
                $item['admin_note'] = $note;
                $item['updated_at'] = now();
                if (($item['disposal_type'] ?? '') === 'door_pickup' && empty($item['points_awarded'])) {
                    $item['points_awarded'] = true;
                    adjust_user_points((int) $item['user_id'], 5, '上门回收完成，积分到账', '你提交的《' . $item['title'] . '》已完成上门回收，系统已发放 5 积分。', app_url(['page' => 'dashboard']));
                }
                if (($item['disposal_type'] ?? '') === 'door_pickup') {
                    $redirectTab = 'door_pickups';
                }
                add_message((int) $item['user_id'], '物品流程已完成', '《' . $item['title'] . '》已完成后续处理。' . ($note !== '' ? '管理员备注：' . $note : ''), app_url(['page' => 'dashboard']));

                $matchedId = (int) ($item['matched_application_id'] ?? 0);
                if ($matchedId > 0) {
                    foreach ($applications as $application) {
                        if ((int) ($application['id'] ?? 0) === $matchedId) {
                            add_message((int) $application['applicant_id'], '申请物品已完成交接', '《' . $item['title'] . '》的交接状态已被管理员标记为完成。', app_url(['page' => 'dashboard']));
                            break;
                        }
                    }
                }
                break;
            }
            unset($item);

            save_dataset('items', $items);
            flash('success', '物品已标记为完成。');
            redirect(app_url(['page' => 'admin', 'tab' => $redirectTab]));

        case 'admin_review_application':
            require_admin_user();
            $applicationId = (int) post_value('application_id', 0);
            $decision = (string) post_value('decision');
            $reply = trim((string) post_value('admin_reply'));
            $applications = load_dataset('applications');
            $items = load_dataset('items');

            $targetApplication = null;
            foreach ($applications as &$application) {
                if ((int) ($application['id'] ?? 0) !== $applicationId) {
                    continue;
                }
                $application['status'] = $decision === 'approve' ? 'approved' : 'rejected';
                $application['admin_reply'] = $reply;
                $application['updated_at'] = now();
                $targetApplication = $application;
                break;
            }
            unset($application);

            if ($targetApplication === null) {
                flash('error', '申请记录不存在。');
                redirect(app_url(['page' => 'admin', 'tab' => 'applications']));
            }

            foreach ($items as &$item) {
                if ((int) ($item['id'] ?? 0) !== (int) ($targetApplication['item_id'] ?? 0)) {
                    continue;
                }

                if ($decision === 'approve') {
                    $item['status'] = 'matched';
                    $item['matched_application_id'] = $applicationId;
                    $item['updated_at'] = now();

                    add_message((int) $targetApplication['applicant_id'], '物品申请已通过', '你申请的《' . $item['title'] . '》已通过审核。' . ($reply !== '' ? '领取说明：' . $reply : '请等待管理员进一步通知领取安排。'), app_url(['page' => 'dashboard']));
                    add_message((int) $item['user_id'], '你的物品已匹配成功', '《' . $item['title'] . '》已有申请通过审核。' . ($reply !== '' ? '管理员说明：' . $reply : ''), app_url(['page' => 'dashboard']));

                    foreach ($applications as &$otherApplication) {
                        if ((int) ($otherApplication['item_id'] ?? 0) === (int) ($item['id'] ?? 0) && (int) ($otherApplication['id'] ?? 0) !== $applicationId && ($otherApplication['status'] ?? '') === 'pending') {
                            $otherApplication['status'] = 'rejected';
                            $otherApplication['admin_reply'] = '该物品已匹配给其他同学，感谢你的关注。';
                            $otherApplication['updated_at'] = now();
                            add_message((int) $otherApplication['applicant_id'], '物品申请未通过', '你申请的《' . $item['title'] . '》已分配给其他同学，本次申请未通过。', app_url(['page' => 'listings']));
                        }
                    }
                    unset($otherApplication);
                } else {
                    add_message((int) $targetApplication['applicant_id'], '物品申请未通过', '你申请的《' . $item['title'] . '》未通过审核。' . ($reply !== '' ? '原因：' . $reply : ''), app_url(['page' => 'dashboard']));
                }
                break;
            }
            unset($item);

            save_dataset('applications', $applications);
            save_dataset('items', $items);
            flash('success', '申请审核结果已保存。');
            redirect(app_url(['page' => 'admin', 'tab' => 'applications']));

        case 'admin_create_reward':
            require_admin_user();
            $name = trim((string) post_value('name'));
            $pointsCost = (int) post_value('points_cost', 0);
            $stock = (int) post_value('stock', 0);
            $description = trim((string) post_value('description'));

            if ($name === '' || $pointsCost <= 0 || $stock < 0) {
                flash('error', '请完整填写兑换商品名称、所需积分和库存。');
                redirect(app_url(['page' => 'admin', 'tab' => 'rewards']));
            }

            $rewards = load_dataset('rewards');
            $rewards[] = [
                'id' => next_id($rewards),
                'name' => $name,
                'points_cost' => $pointsCost,
                'stock' => $stock,
                'description' => $description,
                'image' => '',
                'active' => true,
            ];
            save_dataset('rewards', $rewards);
            flash('success', '积分商品已新增。');
            redirect(app_url(['page' => 'admin', 'tab' => 'rewards']));

        case 'admin_update_reward':
            require_admin_user();
            $rewardId = (int) post_value('reward_id', 0);
            $pointsCost = (int) post_value('points_cost', 0);
            $stock = (int) post_value('stock', 0);
            $active = post_value('active', '0') === '1';

            $rewards = load_dataset('rewards');
            foreach ($rewards as &$reward) {
                if ((int) ($reward['id'] ?? 0) !== $rewardId) {
                    continue;
                }
                $reward['points_cost'] = max(1, $pointsCost);
                $reward['stock'] = max(0, $stock);
                $reward['active'] = $active;
                break;
            }
            unset($reward);
            save_dataset('rewards', $rewards);
            flash('success', '积分商品信息已更新。');
            redirect(app_url(['page' => 'admin', 'tab' => 'rewards']));

        case 'admin_handle_redemption':
            require_admin_user();
            $redemptionId = (int) post_value('redemption_id', 0);
            $decision = (string) post_value('decision');
            $note = trim((string) post_value('admin_note'));
            $redemptions = load_dataset('redemptions');
            $rewards = load_dataset('rewards');

            foreach ($redemptions as &$redemption) {
                if ((int) ($redemption['id'] ?? 0) !== $redemptionId || ($redemption['status'] ?? '') !== 'pending') {
                    continue;
                }

                $redemption['admin_note'] = $note;
                if ($decision === 'approve') {
                    $redemption['status'] = 'fulfilled';
                    add_message((int) $redemption['user_id'], '积分兑换已通过', '你申请兑换的商品已准备发放。' . ($note !== '' ? '领取说明：' . $note : ''), app_url(['page' => 'points']));
                } else {
                    $redemption['status'] = 'rejected';
                    if (empty($redemption['refunded'])) {
                        $redemption['refunded'] = true;
                        adjust_user_points((int) $redemption['user_id'], (int) ($redemption['points_spent'] ?? 0), '积分已退回', '你的兑换申请未通过，系统已退回积分。' . ($note !== '' ? '原因：' . $note : ''), app_url(['page' => 'points']));
                    }
                    foreach ($rewards as &$reward) {
                        if ((int) ($reward['id'] ?? 0) === (int) ($redemption['reward_id'] ?? 0)) {
                            $reward['stock'] = (int) ($reward['stock'] ?? 0) + 1;
                            break;
                        }
                    }
                    unset($reward);
                }
                break;
            }
            unset($redemption);

            save_dataset('redemptions', $redemptions);
            save_dataset('rewards', $rewards);
            flash('success', '兑换申请处理完成。');
            redirect(app_url(['page' => 'admin', 'tab' => 'rewards']));

        case 'admin_create_pickup_point':
            require_admin_user();
            $name = trim((string) post_value('name'));
            $campus = trim((string) post_value('campus'));
            $location = trim((string) post_value('location'));
            $slots = trim((string) post_value('open_slots'));
            $description = trim((string) post_value('description'));

            if ($name === '' || $campus === '' || $location === '' || $slots === '') {
                flash('error', '请完整填写回收点名称、校区、位置和开放时间。');
                redirect(app_url(['page' => 'admin', 'tab' => 'points']));
            }

            $points = load_dataset('pickup_points');
            $points[] = [
                'id' => next_id($points),
                'name' => $name,
                'campus' => $campus,
                'location' => $location,
                'open_slots' => $slots,
                'description' => $description,
                'active' => true,
            ];
            save_dataset('pickup_points', $points);
            flash('success', '回收点已创建。');
            redirect(app_url(['page' => 'admin', 'tab' => 'points']));

        default:
            flash('error', '未识别的操作请求。');
            redirect(app_url());
    }
}

process_post($currentUser);
$currentUser = current_user();
$flashes = consume_flashes();
$page = (string) get_value('page', 'home');
$headerFlashes = in_array($page, ['login', ADMIN_LOGIN_PAGE], true) ? [] : $flashes;
$stats = site_stats();
$usersById = user_lookup();
$items = load_dataset('items');
$applications = load_dataset('applications');
$rewards = load_dataset('rewards');
$redemptions = load_dataset('redemptions');
$pickupPoints = load_dataset('pickup_points');
$announcements = load_dataset('announcements');

if ($page === 'logout') {
    session_destroy();
    session_start();
    flash('success', '你已安全退出登录。');
    redirect(app_url());
}

if (in_array($page, ['submit', 'dashboard'], true)) {
    $currentUser = require_login();
}

if ($page === 'admin') {
    $currentUser = require_admin_user();
}

render_header(match ($page) {
    'login' => '登录与注册',
    ADMIN_LOGIN_PAGE => '管理员登录',
    'submit' => '提交物品',
    'listings' => '公示大厅',
    'item' => '物品详情',
    'dashboard' => '个人中心',
    'points' => '积分兑换',
    'admin' => '后台管理',
    default => '首页',
}, $currentUser, $headerFlashes);

if ($page === 'home'):
    $latestItems = array_slice(array_values(array_filter(array_reverse($items), static fn(array $item): bool => ($item['status'] ?? '') === 'published')), 0, 3);
    $pickupShowcase = pickup_showcase_summary();
    $homeAnnouncements = array_slice(active_announcements(), 0, 3);
    ?>
    <section class="hero-grid hero-grid-compact">
        <div class="hero-card platform-card">
            <div class="platform-card-header">
                <div class="platform-card-copy">
                    <div class="pill-row">
                        <span class="pill">厦门大学 · 思明校区 / 翔安校区</span>
                        <span class="pill">捐赠 · 固定回收点 · 上门回收</span>
                    </div>
                    <h1>校园电子废物捐赠、定点回收与上门回收</h1>
                    <p>统一完成提交、审核、公示、通知与积分兑换，帮助校内成员更轻松地处理闲置或损坏电子设备。</p>
                </div>
                <div class="hero-actions">
                    <a class="button" href="<?= e(app_url(['page' => 'submit'])) ?>">提交待处理物品</a>
                    <a class="button button-secondary" href="<?= e(app_url(['page' => 'listings'])) ?>">查看公示大厅</a>
                </div>
                <?php if ($homeAnnouncements !== []): ?>
                    <div class="announcement-board" aria-label="公告栏">
                        <div class="announcement-board-title">
                            <span>公告栏</span>
                        </div>
                        <div class="announcement-list">
                            <?php foreach ($homeAnnouncements as $announcement): ?>
                                <article class="announcement-item">
                                    <h2><?= e($announcement['title'] ?? '公告') ?></h2>
                                    <p><?= nl2br(e((string) ($announcement['content'] ?? ''))) ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="hero-side-stack">
            <article class="hero-card platform-summary-card">
                <h2 class="section-title">平台功能</h2>
                <div class="platform-function-list">
                    <div class="platform-function-item">
                        <strong>捐赠流转</strong>
                        <span>功能完好的设备审核通过后进入公示大厅，供校内有需要的师生申请。</span>
                    </div>
                    <div class="platform-function-item">
                        <strong>固定回收点投放</strong>
                        <span>损坏或老旧设备可预约思明校区、翔安校区固定回收点，统一回收处理。</span>
                    </div>
                    <div class="platform-function-item">
                        <strong>上门回收</strong>
                        <span>宿舍内不便搬运的损坏设备可填写楼栋、楼层、房间号和预约时段，等待管理员上门回收。</span>
                    </div>
                    <div class="platform-function-item">
                        <strong>进度与通知</strong>
                        <span>提交结果、申请审核和领取安排都会通过站内通知同步到个人中心。</span>
                    </div>
                    <div class="platform-function-item">
                        <strong>积分兑换</strong>
                        <span>每条审核通过记录奖励 5 积分，可在平台兑换环保类小物品。</span>
                    </div>
                </div>
            </article>
            <article class="hero-card hero-guide-card">
                <h2 class="section-title">使用说明</h2>
                <div class="usage-accordion">
                    <details open>
                        <summary>登录与提交</summary>
                        <p>首次使用请先注册账号，登录后即可提交电子废物信息、上传图片并查看处理进度。</p>
                    </details>
                    <details>
                        <summary>捐赠说明</summary>
                        <p>仍可继续使用的设备请选择“捐赠”，审核通过后会进入公示大厅，由校内有需要的师生提交申请。</p>
                    </details>
                    <details>
                        <summary>固定回收点</summary>
                        <div class="pickup-summary-list">
                            <p>损坏、老旧或不再适合继续使用的设备请选择“投放固定回收点”，按预约时间投放即可。</p>
                            <?php foreach ($pickupShowcase as $summary): ?>
                                <div class="pickup-summary-item">
                                    <strong><?= e($summary['title']) ?></strong>
                                    <span><?= e($summary['content']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <details>
                        <summary>上门回收</summary>
                        <p>若设备体积较大或不方便自行搬运，可选择“上门回收”，填写宿舍楼栋、楼层、房间号和可预约时间段，管理员确认后会上门处理。</p>
                    </details>
                    <details>
                        <summary>隐私与积分</summary>
                        <p>公示页面不展示手机号等个人信息；捐赠和固定回收点在审核通过后奖励 5 积分，上门回收在回收完成后再奖励 5 积分。</p>
                    </details>
                </div>
            </article>
        </div>
    </section>

    <section class="panel latest-panel">
        <div class="panel-header latest-panel-header">
            <div>
                <h2 class="section-title">公示大厅最新捐赠</h2>
            </div>
            <a class="button button-secondary" href="<?= e(app_url(['page' => 'listings'])) ?>">进入公示大厅</a>
        </div>
        <?php if ($latestItems === []): ?>
            <div class="empty-state">
                <p>当前还没有可申领的捐赠物品，审核通过后会在这里显示。</p>
            </div>
        <?php else: ?>
            <div class="latest-grid<?= count($latestItems) === 1 ? ' latest-grid-single' : '' ?>">
                <?php foreach ($latestItems as $item): ?>
                    <article class="latest-card<?= count($latestItems) === 1 ? ' latest-card-featured' : '' ?>">
                        <img class="item-image latest-card-image" src="<?= e($item['image']) ?>" alt="<?= e($item['title']) ?>">
                        <div class="latest-card-body">
                            <div class="latest-card-head">
                                <div class="latest-card-title-group">
                                    <h3><?= e($item['title']) ?></h3>
                                    <p class="latest-card-meta"><?= e($item['category']) ?> · <?= e(condition_label((string) $item['condition'])) ?></p>
                                </div>
                                <span class="badge">可申请</span>
                            </div>
                            <div class="listing-card-tags listing-card-tags-compact">
                                <span class="badge badge-soft">捐赠</span>
                                <?php if (($item['target_group'] ?? '') !== ''): ?>
                                    <span class="chip chip-accent">适用：<?= e($item['target_group']) ?></span>
                                <?php endif; ?>
                            </div>
                            <p class="latest-card-text"><?= e(text_excerpt((string) $item['description'], 58)) ?></p>
                            <div class="latest-card-actions">
                                <a class="button button-secondary button-block" href="<?= e(app_url(['page' => 'item', 'id' => (int) $item['id']])) ?>">查看详情</a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
elseif ($page === 'login' || $page === ADMIN_LOGIN_PAGE):
    $isAdminLoginPage = $page === ADMIN_LOGIN_PAGE;
    $loginForm = consume_form_state('login');
    $registerForm = consume_form_state('register');
    $authTab = (string) get_value('tab', $registerForm !== [] ? 'register' : 'login');
    ?>
    <?php if ($isAdminLoginPage): ?>
        <section class="auth-admin-wrap">
            <article class="auth-card auth-admin-card">
                <h2>管理员登录</h2>
                <p class="section-lead">此入口仅供平台管理员使用。登录成功后会自动进入后台管理页面。</p>
                <?php if ($currentUser !== null): ?>
                    <div class="auth-status-card">
                        <div>
                            <strong>当前已登录：</strong>
                            <span><?= e($currentUser['nickname'] ?? $currentUser['name'] ?? '用户') ?>（<?= is_admin($currentUser) ? '管理员' : '平台账号' ?>）</span>
                        </div>
                        <p><?= is_admin($currentUser) ? '你可以直接进入后台继续处理审核、交接与回收点维护。' : '当前账号为普通账号，如需进入后台，请退出后使用管理员账号登录。' ?></p>
                        <div class="inline-actions">
                            <?php if (is_admin($currentUser)): ?>
                                <a class="button button-secondary" href="<?= e(app_url(['page' => 'admin'])) ?>">进入后台管理</a>
                            <?php else: ?>
                                <a class="button button-secondary" href="<?= e(app_url(['page' => 'dashboard'])) ?>">进入个人中心</a>
                            <?php endif; ?>
                            <a class="button button-secondary" href="<?= e(app_url(['page' => 'logout'])) ?>">退出当前账号</a>
                        </div>
                    </div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="login_scope" value="admin">
                    <div class="form-grid" style="grid-template-columns:1fr;">
                        <div class="field">
                            <label for="portal-admin-phone">手机号</label>
                            <input id="portal-admin-phone" name="phone" maxlength="11" inputmode="numeric" autocomplete="username" value="<?= e((string) ($loginForm['phone'] ?? '')) ?>" placeholder="请输入管理员手机号" required>
                        </div>
                        <div class="field">
                            <label for="portal-admin-password">密码</label>
                            <input id="portal-admin-password" name="password" type="password" autocomplete="current-password" placeholder="请输入管理员密码" required>
                        </div>
                        <div class="field">
                            <input type="submit" value="进入后台">
                        </div>
                    </div>
                </form>
            </article>
        </section>
    <?php else: ?>
        <section class="auth-single-wrap">
            <article class="auth-card auth-main-card auth-single-card">
                <div class="auth-card-intro">
                    <h2>登录 / 注册</h2>
                    <p>使用手机号完成账号登录、注册、提交物品和查看审核进度。</p>
                </div>
                <?php if ($currentUser !== null): ?>
                    <div class="auth-status-card">
                        <div>
                            <strong>当前已登录：</strong>
                            <span><?= e($currentUser['nickname'] ?? $currentUser['name'] ?? '用户') ?>（<?= is_admin($currentUser) ? '管理员' : '平台账号' ?>）</span>
                        </div>
                        <p><?= is_admin($currentUser) ? '当前账号为管理员账号。如需使用普通入口，请先退出当前账号。' : '你可以直接进入个人中心查看提交记录、通知和积分变化。' ?></p>
                        <div class="inline-actions">
                            <?php if (!is_admin($currentUser)): ?>
                                <a class="button button-secondary" href="<?= e(app_url(['page' => 'dashboard'])) ?>">进入个人中心</a>
                            <?php endif; ?>
                            <a class="button button-secondary" href="<?= e(app_url(['page' => 'logout'])) ?>">退出当前账号</a>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="auth-tabs" role="tablist" aria-label="登录注册">
                    <button type="button" class="auth-tab-button<?= $authTab === 'login' ? ' is-active' : '' ?>" data-auth-tab-trigger="login">手机号登录</button>
                    <button type="button" class="auth-tab-button<?= $authTab === 'register' ? ' is-active' : '' ?>" data-auth-tab-trigger="register">注册账号</button>
                </div>

                <div class="auth-tab-panel<?= $authTab === 'login' ? ' is-active' : '' ?>" data-auth-tab-panel="login">
                    <h2>账号登录</h2>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="login_scope" value="user">
                        <div class="form-grid" style="grid-template-columns:1fr;">
                            <?php if ($authTab === 'login' && $flashes !== []): ?>
                                <div class="field full">
                                    <?php render_flash_messages($flashes, 'auth-inline-flashes'); ?>
                                </div>
                            <?php endif; ?>
                            <div class="field">
                                <label for="login-phone">手机号</label>
                                <input id="login-phone" name="phone" maxlength="11" inputmode="numeric" autocomplete="username" value="<?= e((string) ($loginForm['phone'] ?? '')) ?>" placeholder="请输入手机号" required>
                            </div>
                            <div class="field">
                                <label for="login-password">密码</label>
                                <input id="login-password" name="password" type="password" autocomplete="current-password" placeholder="请输入密码" required>
                            </div>
                            <div class="field">
                                <input type="submit" value="登录">
                            </div>
                        </div>
                    </form>
                </div>

                <div class="auth-tab-panel<?= $authTab === 'register' ? ' is-active' : '' ?>" data-auth-tab-panel="register">
                    <h2>注册账号</h2>
                    <p class="auth-code-note">首次注册请先获取短信验证码，再填写账号信息。</p>

                    <form method="post" id="register-code-form" class="visually-hidden" data-register-code-form>
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="send_register_code">
                        <input type="hidden" id="register-code-phone-proxy" name="phone" value="<?= e((string) ($registerForm['phone'] ?? '')) ?>">
                        <input type="hidden" id="register-code-nickname-proxy" name="nickname" value="<?= e((string) ($registerForm['nickname'] ?? '')) ?>">
                        <input type="hidden" id="register-code-campus-proxy" name="campus" value="<?= e((string) ($registerForm['campus'] ?? '')) ?>">
                    </form>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="register">
                        <div class="form-grid">
                            <div class="field register-phone-field full">
                                <label for="register-phone">手机号</label>
                                <div class="code-row">
                                    <input id="register-phone" name="phone" maxlength="11" inputmode="numeric" autocomplete="tel" value="<?= e((string) ($registerForm['phone'] ?? '')) ?>" placeholder="请输入手机号" required>
                                    <button type="submit" class="button button-secondary" form="register-code-form">获取验证码</button>
                                </div>
                                <?php if ($authTab === 'register' && $flashes !== []): ?>
                                    <?php render_flash_messages($flashes, 'auth-inline-flashes auth-inline-flashes-compact'); ?>
                                <?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="register-code">短信验证码</label>
                                <input id="register-code" name="verification_code" maxlength="6" inputmode="numeric" placeholder="请输入 6 位验证码" required>
                            </div>
                            <div class="field">
                                <label for="register-password">设置密码</label>
                                <input id="register-password" name="password" type="password" autocomplete="new-password" minlength="6" placeholder="不少于 6 位" required>
                            </div>
                            <div class="field">
                                <label for="register-nickname">昵称</label>
                                <input id="register-nickname" name="nickname" value="<?= e((string) ($registerForm['nickname'] ?? '')) ?>" placeholder="请输入姓名或昵称" required>
                            </div>
                            <div class="field">
                                <label for="register-campus">所属校区</label>
                                <select id="register-campus" name="campus" required>
                                    <option value="" disabled hidden <?= (($registerForm['campus'] ?? '') === '') ? 'selected' : '' ?>>请选择校区</option>
                                    <?php foreach (campus_options() as $campus): ?>
                                        <option value="<?= e($campus) ?>" <?= (($registerForm['campus'] ?? '') === $campus) ? 'selected' : '' ?>><?= e($campus) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field full">
                                <input type="submit" value="注册并进入平台">
                            </div>
                        </div>
                    </form>
                </div>
            </article>
        </section>
    <?php endif; ?>
    <?php
elseif ($page === 'submit'):
    $user = require_login();
    $doorPickupBuildings = door_pickup_buildings_for((string) ($user['campus'] ?? ''));
    $doorPickupSlots = door_pickup_time_slot_options();
    ?>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2 class="section-title">提交待处理电子产品</h2>
                <p class="section-lead">请尽量填写准确、完整的信息。系统会先由管理员审核，通过后再进入公示或回收流程；其中上门回收会在回收完成后再发放 5 积分。</p>
            </div>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="submit_item">
            <div class="form-grid">
                <div class="field">
                    <label for="title">物品名称</label>
                    <input id="title" name="title" placeholder="例如：闲置蓝牙耳机 / 旧手机充电器" required>
                </div>
                <div class="field">
                    <label for="category">类别</label>
                    <select id="category" name="category" required>
                        <option value="">请选择类别</option>
                        <?php foreach (category_options() as $category): ?>
                            <option value="<?= e($category) ?>"><?= e($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="brand">品牌 / 型号</label>
                    <input id="brand" name="brand" placeholder="可选填写，方便识别">
                </div>
                <div class="field">
                    <label for="condition">成色 / 状态</label>
                    <select id="condition" name="condition" required>
                        <option value="">请选择状态</option>
                        <option value="almost_new">近乎全新</option>
                        <option value="good">功能良好</option>
                        <option value="fair">基本可用</option>
                        <option value="damaged">损坏待回收</option>
                    </select>
                </div>
                <div class="field full">
                    <label for="description">文字说明</label>
                    <textarea id="description" name="description" placeholder="请描述功能情况、配件是否齐全、是否存在瑕疵、希望如何处理等。" required></textarea>
                </div>
                <div class="field">
                    <label for="image">实物图片</label>
                    <input id="image" name="image" type="file" accept="image/*" data-image-input="preview-image" data-image-feedback="image-feedback" required>
                    <span class="hint">支持 JPG、PNG、WebP、GIF，建议上传清晰正面图，大小不超过 5MB。</span>
                    <span id="image-feedback" class="image-upload-feedback" aria-live="polite"></span>
                    <img id="preview-image" class="preview-image" alt="图片预览">
                </div>
                <div class="field">
                    <label for="disposal_type">处理方式</label>
                    <select id="disposal_type" name="disposal_type" data-disposal-select required>
                        <option value="donation">捐赠</option>
                        <option value="recycle">投递固定回收点</option>
                        <option value="door_pickup">上门回收</option>
                    </select>
                    <span class="hint">可继续使用的设备请选择捐赠；损坏设备可选择固定回收点或上门回收。</span>
                </div>
            </div>

            <div class="divider"></div>

            <div class="form-grid" data-disposal-panel="donation">
                <div class="field full">
                    <label for="donation_reason">捐赠说明</label>
                    <textarea id="donation_reason" name="donation_reason" placeholder="例如：希望优先转给有课程学习、科研使用或公益活动需求的校内师生。"></textarea>
                </div>
                <div class="field full">
                    <label for="donation_target_group">希望优先面向的使用人群（可选）</label>
                    <input id="donation_target_group" name="donation_target_group" placeholder="例如：课程学习、科研使用、公益项目">
                </div>
            </div>

            <div class="form-grid" data-disposal-panel="recycle" style="display:none;">
                <div class="field">
                    <label for="pickup_campus">校区</label>
                    <select id="pickup_campus" name="pickup_campus" data-pickup-campus>
                        <option value="" disabled selected hidden>请选择校区</option>
                        <?php foreach (campus_options() as $campus): ?>
                            <option value="<?= e($campus) ?>"><?= e($campus) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="pickup_zone">园区</label>
                    <select id="pickup_zone" name="pickup_zone" data-pickup-zone data-selected="">
                        <option value="">请选择园区</option>
                    </select>
                </div>
                <div class="field" data-pickup-subpoint-wrap style="display:none;">
                    <label for="pickup_subpoint">具体点位</label>
                    <select id="pickup_subpoint" name="pickup_subpoint" data-pickup-subpoint data-selected="">
                        <option value="">请选择具体点位</option>
                    </select>
                    <span class="hint">翔安校区国光区、凌云区需要继续选择具体投放点位。</span>
                </div>
                <div class="field">
                    <label for="pickup_time">预计投递时间</label>
                    <input id="pickup_time" name="pickup_time" placeholder="例如：周三下午 16:00 左右">
                </div>
            </div>

            <div class="form-grid" data-disposal-panel="door_pickup" style="display:none;">
                <div class="field">
                    <label for="door_pickup_building">宿舍楼栋</label>
                    <select id="door_pickup_building" name="door_pickup_building">
                        <option value="" disabled selected hidden>请选择宿舍楼栋</option>
                        <?php foreach ($doorPickupBuildings as $building): ?>
                            <option value="<?= e($building) ?>"><?= e($building) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">默认按你当前账号所属校区 <?= e((string) ($user['campus'] ?? '')) ?> 提供楼栋选项。</span>
                </div>
                <div class="field">
                    <label for="door_pickup_floor">楼层</label>
                    <select id="door_pickup_floor" name="door_pickup_floor">
                        <option value="" disabled selected hidden>请选择楼层</option>
                        <?php for ($floor = 1; $floor <= 20; $floor++): ?>
                            <option value="<?= e((string) $floor) ?>"><?= e((string) $floor) ?> 层</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="door_pickup_room">房间号</label>
                    <input id="door_pickup_room" name="door_pickup_room" placeholder="例如：507 / A302">
                </div>
                <div class="field">
                    <label for="door_pickup_slot">可预约时间段</label>
                    <select id="door_pickup_slot" name="door_pickup_slot">
                        <option value="" disabled selected hidden>请选择可预约时间段</option>
                        <?php foreach ($doorPickupSlots as $slot): ?>
                            <option value="<?= e($slot) ?>"><?= e($slot) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="inline-actions" style="margin-top:20px;">
                <input type="submit" value="提交审核">
                <a class="button button-secondary" href="<?= e(app_url(['page' => 'dashboard'])) ?>">返回个人中心</a>
            </div>
        </form>
    </section>
    <?php
elseif ($page === 'listings'):
    $keyword = trim((string) get_value('keyword'));
    $publicItems = array_values(array_filter($items, static function (array $item) use ($keyword): bool {
        if (($item['status'] ?? '') !== 'published') {
            return false;
        }
        if ($keyword !== '' && !str_contains(text_lower(($item['title'] ?? '') . ' ' . ($item['description'] ?? '') . ' ' . ($item['category'] ?? '')), text_lower($keyword))) {
            return false;
        }
        return true;
    }));
    ?>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2 class="section-title">公示大厅</h2>
            </div>
        </div>
        <form method="get" class="form-grid" style="margin-bottom:20px;">
            <input type="hidden" name="page" value="listings">
            <div class="field full">
                <label for="keyword">关键词搜索</label>
                <input id="keyword" name="keyword" value="<?= e($keyword) ?>" placeholder="搜索物品名称或类别">
            </div>
            <div class="field full">
                <div class="inline-actions">
                    <button type="submit">筛选</button>
                    <a class="button button-secondary" href="<?= e(app_url(['page' => 'listings'])) ?>">重置</a>
                </div>
            </div>
        </form>

        <?php if ($publicItems === []): ?>
            <div class="empty-state">
                <p>当前没有符合条件的捐赠公示物品。</p>
            </div>
        <?php else: ?>
            <div class="listing-grid">
                <?php foreach ($publicItems as $item): ?>
                    <article class="item-card listing-card">
                        <img class="item-image" src="<?= e($item['image']) ?>" alt="<?= e($item['title']) ?>">
                        <div class="listing-card-head">
                            <div class="listing-card-title">
                                <h3><?= e($item['title']) ?></h3>
                                <p><?= e($item['category']) ?> · <?= e(condition_label((string) $item['condition'])) ?></p>
                            </div>
                            <div class="listing-card-tags">
                                <span class="badge badge-soft">捐赠</span>
                                <?php if (($item['target_group'] ?? '') !== ''): ?>
                                    <span class="chip chip-accent">适用：<?= e($item['target_group']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="listing-card-desc"><?= e(text_excerpt((string) $item['description'], 120)) ?></p>
                        <a class="button button-secondary button-block" href="<?= e(app_url(['page' => 'item', 'id' => (int) $item['id']])) ?>">查看详情</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
elseif ($page === 'item'):
    $itemId = (int) get_value('id', 0);
    $item = find_item($itemId);
    $pointById = point_lookup();
    if ($item === null || (($item['status'] ?? '') !== 'published' && !is_admin($currentUser) && (int) ($item['user_id'] ?? 0) !== (int) ($currentUser['id'] ?? 0))):
        ?>
        <section class="empty-state">
            <p>未找到对应的物品信息，或者你当前没有查看权限。</p>
        </section>
        <?php
    else:
        $owner = $usersById[(int) $item['user_id']] ?? null;
        $pickupDisplay = trim((string) ($item['pickup_display'] ?? ''));
        $doorPickupAddress = door_pickup_address_display($item);
        if ($pickupDisplay === '' && (int) ($item['pickup_point_id'] ?? 0) > 0) {
            $pickupDisplay = (string) ($pointById[(int) ($item['pickup_point_id'] ?? 0)]['name'] ?? '');
        }
        if ($pickupDisplay === '' && (($item['pickup_campus'] ?? '') !== '' || ($item['pickup_zone'] ?? '') !== '')) {
            $pickupDisplay = pickup_location_display((string) ($item['pickup_campus'] ?? ''), (string) ($item['pickup_zone'] ?? ''), (string) ($item['pickup_subpoint'] ?? ''));
        }
        ?>
        <section class="content-grid">
            <article class="panel">
                <img class="item-image" src="<?= e($item['image']) ?>" alt="<?= e($item['title']) ?>" style="margin-bottom:18px;">
                <div class="item-title-row">
                    <div>
                        <h2 class="section-title"><?= e($item['title']) ?></h2>
                        <p class="section-lead"><?= e($item['category']) ?> · <?= e(condition_label((string) $item['condition'])) ?></p>
                    </div>
                    <span class="badge <?= e(item_badge_class((string) $item['status'])) ?>"><?= e(status_label((string) $item['status'])) ?></span>
                </div>
                <div class="key-value">
                    <div><span>处理方式</span><strong><?= e(disposal_label((string) $item['disposal_type'])) ?></strong></div>
                    <div><span>品牌 / 型号</span><strong><?= e($item['brand'] ?: '未填写') ?></strong></div>
                    <div><span>发布校区</span><strong><?= e($owner['campus'] ?? '未知') ?></strong></div>
                    <?php if (($item['disposal_type'] ?? '') === 'recycle'): ?>
                        <div><span>回收点</span><strong><?= e($pickupDisplay !== '' ? $pickupDisplay : '未设置') ?></strong></div>
                        <div><span>预计投递时间</span><strong><?= e($item['pickup_time'] ?: '未填写') ?></strong></div>
                    <?php endif; ?>
                    <?php if (($item['disposal_type'] ?? '') === 'door_pickup'): ?>
                        <div><span>上门地址</span><strong><?= e($doorPickupAddress !== '' ? $doorPickupAddress : '未设置') ?></strong></div>
                        <div><span>预约时段</span><strong><?= e($item['door_pickup_slot'] ?: '未填写') ?></strong></div>
                    <?php endif; ?>
                    <?php if (($item['target_group'] ?? '') !== ''): ?>
                        <div><span>适用人群</span><strong><?= e($item['target_group']) ?></strong></div>
                    <?php endif; ?>
                </div>
                <div class="divider"></div>
                <h3>物品说明</h3>
                <p><?= nl2br(e($item['description'])) ?></p>
                <?php if (($item['donation_reason'] ?? '') !== ''): ?>
                    <div class="divider"></div>
                    <h3>捐赠说明</h3>
                    <p><?= nl2br(e($item['donation_reason'])) ?></p>
                <?php endif; ?>
                <?php if (($item['admin_note'] ?? '') !== ''): ?>
                    <div class="divider"></div>
                    <h3>管理员备注</h3>
                    <p><?= nl2br(e($item['admin_note'])) ?></p>
                <?php endif; ?>
            </article>

            <article class="panel">
                <div class="panel-header">
                    <div>
                        <h2 class="section-title">申请说明</h2>
                        <p class="section-lead">通过平台提交用途或申请原因，管理员审核通过后会安排领取方式，不公开提交人联系方式。</p>
                    </div>
                </div>
                <div class="feature-card" style="margin-bottom:16px;">
                    <h3>发布人信息保护</h3>
                    <p>手机号：<?= e($owner ? mask_phone((string) $owner['phone']) : '未公开') ?></p>
                    <p>昵称：<?= e($owner['nickname'] ?? '匿名用户') ?></p>
                    <p>详细联系方式不会在公示页展示。</p>
                </div>
                <?php if (($item['disposal_type'] ?? '') !== 'donation'): ?>
                    <div class="empty-state">
                        <p>该记录已进入回收流程，仅用于展示回收安排，不开放申领。</p>
                    </div>
                <?php elseif (($item['status'] ?? '') === 'published' && $currentUser !== null && (int) ($item['user_id'] ?? 0) !== (int) $currentUser['id']): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="apply_item">
                        <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                        <div class="field">
                            <label for="purpose">申请原因 / 用途说明</label>
                            <textarea id="purpose" name="purpose" placeholder="例如：课程设计需要该设备、个人学习使用、家庭经济困难希望获得帮助。" required></textarea>
                        </div>
                        <div class="inline-actions" style="margin-top:16px;">
                            <input type="submit" value="提交申领申请">
                        </div>
                    </form>
                <?php elseif ($currentUser === null): ?>
                    <div class="empty-state">
                        <p>登录后才能提交申请。</p>
                        <a class="button" href="<?= e(app_url(['page' => 'login'])) ?>">去登录</a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <p>该物品当前不可申请，或这是你自己发布的物品。</p>
                    </div>
                <?php endif; ?>
            </article>
        </section>
        <?php
    endif;
elseif ($page === 'dashboard'):
    $user = require_login();
    $section = (string) get_value('section', 'overview');
    $myItems = array_values(array_filter($items, static fn(array $item): bool => (int) ($item['user_id'] ?? 0) === (int) $user['id']));
    $myApplications = array_values(array_filter($applications, static fn(array $application): bool => (int) ($application['applicant_id'] ?? 0) === (int) $user['id']));
    $myMessages = array_values(array_filter(array_reverse(load_dataset('messages')), static fn(array $message): bool => (int) ($message['user_id'] ?? 0) === (int) $user['id']));
    $myRedemptions = array_values(array_filter(array_reverse($redemptions), static fn(array $redemption): bool => (int) ($redemption['user_id'] ?? 0) === (int) $user['id']));
    $itemById = item_lookup();
    $rewardById = reward_lookup();
    ?>
    <section class="dashboard-grid">
        <article class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="section-title"><?= e($user['nickname']) ?> 的个人中心</h2>
                    <p class="section-lead">查看你提交的电子产品、申请进度、站内通知以及积分兑换情况。</p>
                </div>
                <a class="button" href="<?= e(app_url(['page' => 'submit'])) ?>">继续提交</a>
            </div>
            <div class="stats-grid" style="grid-template-columns:repeat(3, minmax(0,1fr)); margin-bottom:18px;">
                <div class="stat-card">
                    <strong><?= e((string) $user['points']) ?></strong>
                    <h3>当前积分</h3>
                    <p>捐赠和固定回收点在审核通过后累计，上门回收在完成后累计。</p>
                </div>
                <div class="stat-card">
                    <strong><?= e((string) count($myItems)) ?></strong>
                    <h3>我的提交</h3>
                    <p>包含待审核、公示中、已完成等状态。</p>
                </div>
                <div class="stat-card">
                    <strong><?= e((string) count($myApplications)) ?></strong>
                    <h3>我的申请</h3>
                    <p>可查看是否通过审核及领取说明。</p>
                </div>
            </div>

            <div class="inline-actions" style="margin-bottom:16px;">
                <a class="button <?= $section === 'overview' ? '' : 'button-secondary' ?>" href="<?= e(app_url(['page' => 'dashboard', 'section' => 'overview'])) ?>">我的提交</a>
                <a class="button <?= $section === 'applications' ? '' : 'button-secondary' ?>" href="<?= e(app_url(['page' => 'dashboard', 'section' => 'applications'])) ?>">我的申请</a>
                <a class="button <?= $section === 'messages' ? '' : 'button-secondary' ?>" href="<?= e(app_url(['page' => 'dashboard', 'section' => 'messages'])) ?>">站内通知</a>
                <a class="button <?= $section === 'redemptions' ? '' : 'button-secondary' ?>" href="<?= e(app_url(['page' => 'dashboard', 'section' => 'redemptions'])) ?>">兑换记录</a>
            </div>

            <?php if ($section === 'overview'): ?>
                <?php if ($myItems === []): ?>
                    <div class="empty-state">
                        <p>你还没有提交过电子产品。上传一条物品后，管理员审核通过即可进入流程；若选择上门回收，则完成回收后再获得积分。</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>物品</th>
                                <th>处理方式</th>
                                <th>状态</th>
                                <th>提交时间</th>
                                <th>管理员备注</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_reverse($myItems) as $item): ?>
                                <tr>
                                    <td data-label="物品"><a href="<?= e(app_url(['page' => 'item', 'id' => (int) $item['id']])) ?>"><?= e($item['title']) ?></a></td>
                                    <td data-label="处理方式"><?= e(disposal_label((string) $item['disposal_type'])) ?></td>
                                    <td data-label="状态"><span class="badge <?= e(item_badge_class((string) $item['status'])) ?>"><?= e(status_label((string) $item['status'])) ?></span></td>
                                    <td data-label="提交时间"><?= e($item['created_at']) ?></td>
                                    <td data-label="管理员备注"><?= e($item['admin_note'] ?: '暂无') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php elseif ($section === 'applications'): ?>
                <?php if ($myApplications === []): ?>
                    <div class="empty-state">
                        <p>你还没有申请过公示中的物品。</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>申请物品</th>
                                <th>申请说明</th>
                                <th>状态</th>
                                <th>管理员回复</th>
                                <th>提交时间</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($myApplications as $application): ?>
                                <tr>
                                    <td data-label="申请物品"><?= e($itemById[(int) $application['item_id']]['title'] ?? '未知物品') ?></td>
                                    <td data-label="申请说明"><?= e($application['purpose']) ?></td>
                                    <td data-label="状态"><span class="badge <?= e(application_badge_class((string) $application['status'])) ?>"><?= e(application_status_label((string) $application['status'])) ?></span></td>
                                    <td data-label="管理员回复"><?= e($application['admin_reply'] ?: '暂无') ?></td>
                                    <td data-label="提交时间"><?= e($application['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php elseif ($section === 'messages'): ?>
                <?php if ($myMessages === []): ?>
                    <div class="empty-state">
                        <p>暂时没有新的站内通知。</p>
                    </div>
                <?php else: ?>
                    <div class="cards-grid" style="grid-template-columns:1fr;">
                        <?php foreach ($myMessages as $message): ?>
                            <article class="feature-card">
                                <div class="item-title-row">
                                    <div>
                                        <h3><?= e($message['title']) ?></h3>
                                        <p><?= e($message['created_at']) ?></p>
                                    </div>
                                    <span class="badge <?= !empty($message['is_read']) ? 'badge-neutral' : 'badge-warn' ?>"><?= !empty($message['is_read']) ? '已读' : '未读' ?></span>
                                </div>
                                <p><?= nl2br(e($message['content'])) ?></p>
                                <div class="inline-actions" style="margin-top:14px;">
                                    <?php if (($message['link'] ?? '') !== ''): ?>
                                        <a class="button button-secondary" href="<?= e($message['link']) ?>">查看相关页面</a>
                                    <?php endif; ?>
                                    <?php if (empty($message['is_read'])): ?>
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="mark_message_read">
                                            <input type="hidden" name="message_id" value="<?= e((string) $message['id']) ?>">
                                            <button type="submit" class="button button-secondary">标记已读</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($myRedemptions === []): ?>
                    <div class="empty-state">
                        <p>你还没有提交过积分兑换申请。</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>兑换商品</th>
                                <th>消耗积分</th>
                                <th>状态</th>
                                <th>管理员说明</th>
                                <th>时间</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($myRedemptions as $redemption): ?>
                                <tr>
                                    <td data-label="兑换商品"><?= e($rewardById[(int) $redemption['reward_id']]['name'] ?? '未知商品') ?></td>
                                    <td data-label="消耗积分"><?= e((string) $redemption['points_spent']) ?></td>
                                    <td data-label="状态"><span class="badge <?= e(reward_badge_class((string) $redemption['status'])) ?>"><?= e(reward_status_label((string) $redemption['status'])) ?></span></td>
                                    <td data-label="管理员说明"><?= e($redemption['admin_note'] ?: '暂无') ?></td>
                                    <td data-label="时间"><?= e($redemption['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </article>

        <article class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="section-title">账户信息</h2>
                    <p class="section-lead">你的注册信息与使用概况。</p>
                </div>
            </div>
            <div class="key-value">
                <div><span>手机号</span><strong><?= e(mask_phone((string) $user['phone'])) ?></strong></div>
                <div><span>昵称</span><strong><?= e($user['nickname']) ?></strong></div>
                <div><span>校区</span><strong><?= e($user['campus']) ?></strong></div>
                <div><span>未读通知</span><strong><?= e((string) unread_message_count((int) $user['id'])) ?></strong></div>
                <div><span>注册时间</span><strong><?= e($user['created_at']) ?></strong></div>
            </div>
            <div class="divider"></div>
            <h3>使用建议</h3>
            <p>如果你希望增加成功匹配率，建议在物品描述中写清楚功能状态、适用场景和配件情况；如果是损坏物品，可根据搬运便利性选择固定回收点或上门回收。</p>
        </article>
    </section>
    <?php
elseif ($page === 'points'):
    $user = $currentUser;
    $activeRewards = array_values(array_filter($rewards, static fn(array $reward): bool => !empty($reward['active'])));
    ?>
    <section class="content-grid">
        <article class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="section-title">积分商城</h2>
                    <p class="section-lead">捐赠和固定回收点在审核通过后奖励 5 积分，上门回收在完成后奖励 5 积分，积分可用于兑换环保主题小物品。</p>
                </div>
                <?php if ($user !== null): ?>
                    <span class="badge">当前积分 <?= e((string) $user['points']) ?></span>
                <?php endif; ?>
            </div>
            <div class="cards-grid">
                <?php foreach ($activeRewards as $reward): ?>
                    <article class="item-card">
                        <div class="item-image" style="display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;color:var(--brand-deep);">
                            <?= e($reward['name']) ?>
                        </div>
                        <div class="item-title-row">
                            <div>
                                <h3><?= e($reward['name']) ?></h3>
                                <p><?= e($reward['description']) ?></p>
                            </div>
                        </div>
                        <div class="meta-row">
                            <span class="pill"><?= e((string) $reward['points_cost']) ?> 积分</span>
                            <span class="pill">库存 <?= e((string) $reward['stock']) ?></span>
                        </div>
                        <?php if ($user !== null): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="redeem_reward">
                                <input type="hidden" name="reward_id" value="<?= e((string) $reward['id']) ?>">
                                <button type="submit" <?= ((int) $reward['stock'] <= 0 || (int) $user['points'] < (int) $reward['points_cost']) ? 'disabled' : '' ?>>立即兑换</button>
                            </form>
                        <?php else: ?>
                            <a class="button button-secondary" href="<?= e(app_url(['page' => 'login'])) ?>">登录后兑换</a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="section-title">积分规则</h2>
                    <p class="section-lead">积分和真实处理进度挂钩，鼓励同学持续参与电子废物规范处理与校园资源流转。</p>
                </div>
            </div>
            <div class="cards-grid" style="grid-template-columns:1fr;">
                <div class="feature-card">
                    <h3>5 积分 / 条</h3>
                    <p>捐赠 / 固定回收点审核通过后发放；上门回收在管理员确认完成后发放。</p>
                </div>
                <div class="feature-card">
                    <h3>积分扣减规则</h3>
                    <p>提交兑换申请时先暂扣积分，若管理员驳回则自动退回。</p>
                </div>
                <div class="feature-card">
                    <h3>适合后续扩展</h3>
                    <p>后续可叠加“环保达人榜”“学院排行”“志愿服务时长联动”等机制。</p>
                </div>
            </div>
        </article>
    </section>
    <?php
elseif ($page === 'admin'):
    require_admin_user();
    $tab = (string) get_value('tab', 'overview');
    $pendingItems = array_values(array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') === 'pending_review'));
    $activeItems = array_values(array_filter($items, static fn(array $item): bool => in_array($item['status'] ?? '', ['published', 'dropoff_ready', 'matched'], true) && ($item['disposal_type'] ?? '') !== 'door_pickup'));
    $pendingDoorPickupItems = array_values(array_filter($items, static fn(array $item): bool => ($item['disposal_type'] ?? '') === 'door_pickup' && ($item['status'] ?? '') === 'pickup_scheduled'));
    $doorPickupHistoryItems = array_values(array_filter($items, static fn(array $item): bool => ($item['disposal_type'] ?? '') === 'door_pickup' && ($item['status'] ?? '') === 'completed'));
    $pendingApplications = array_values(array_filter($applications, static fn(array $application): bool => ($application['status'] ?? '') === 'pending'));
    $pendingRedemptions = array_values(array_filter($redemptions, static fn(array $redemption): bool => ($redemption['status'] ?? '') === 'pending'));
    $pointById = point_lookup();
    $rewardById = reward_lookup();
    $itemById = item_lookup();
    $currentAnnouncement = $announcements[0] ?? [
        'title' => '关于物品兑换',
        'content' => '',
        'active' => true,
    ];
    ?>
    <section class="panel">
        <div class="panel-header">
            <div>
                <h2 class="section-title">后台管理</h2>
                <p class="section-lead">管理员可在这里审核物品、处理申请、管理兑换商品与回收点，维护平台日常运行。</p>
            </div>
        </div>
        <div class="inline-actions">
            <a class="button <?= $tab === 'overview' ? '' : 'button-secondary' ?>" href="<?= e(app_url(['page' => 'admin', 'tab' => 'overview'])) ?>">概览</a>
            <a class="button <?= $tab === 'items' ? '' : 'button-secondary' ?>" href="<?= e(app_url(['page' => 'admin', 'tab' => 'items'])) ?>">物品审核</a>
            <a class="button <?= $tab === 'door_pickups' ? '' : 'button-secondary' ?>" href="<?= e(app_url(['page' => 'admin', 'tab' => 'door_pickups'])) ?>">上门回收</a>
            <a class="button <?= $tab === 'applications' ? '' : 'button-secondary' ?>" href="<?= e(app_url(['page' => 'admin', 'tab' => 'applications'])) ?>">申请审核</a>
            <a class="button <?= $tab === 'rewards' ? '' : 'button-secondary' ?>" href="<?= e(app_url(['page' => 'admin', 'tab' => 'rewards'])) ?>">积分兑换</a>
            <a class="button <?= $tab === 'points' ? '' : 'button-secondary' ?>" href="<?= e(app_url(['page' => 'admin', 'tab' => 'points'])) ?>">回收点管理</a>
            <a class="button <?= $tab === 'announcements' ? '' : 'button-secondary' ?>" href="<?= e(app_url(['page' => 'admin', 'tab' => 'announcements'])) ?>">公告管理</a>
        </div>
    </section>

    <?php if ($tab === 'overview'): ?>
        <section class="stats-grid">
            <article class="stat-card">
                <strong><?= e((string) count($pendingItems)) ?></strong>
                <h3>待审核物品</h3>
                <p>新提交的物品会先进入这里。</p>
            </article>
            <article class="stat-card">
                <strong><?= e((string) count($pendingDoorPickupItems)) ?></strong>
                <h3>待上门回收</h3>
                <p>已预约的上门回收订单可在独立列表中处理。</p>
            </article>
            <article class="stat-card">
                <strong><?= e((string) count($pendingApplications)) ?></strong>
                <h3>待审核申请</h3>
                <p>同学提交的申领申请需要管理员审核把关。</p>
            </article>
            <article class="stat-card">
                <strong><?= e((string) count($pendingRedemptions)) ?></strong>
                <h3>待处理兑换</h3>
                <p>积分商城兑换申请待确认发放。</p>
            </article>
            <article class="stat-card">
                <strong><?= e((string) count(active_pickup_points())) ?></strong>
                <h3>启用中的回收点</h3>
                <p>覆盖两个校区的回收场景。</p>
            </article>
        </section>

        <section class="panel demo-seed-panel">
            <div class="panel-header">
                <div>
                    <h2 class="section-title">演示流程数据</h2>
                    <p class="section-lead">一键重置固定演示账号与流程数据，便于现场从普通用户端和管理员端完整演示。</p>
                </div>
                <form method="post" onsubmit="return confirm('将重置固定演示账号及其相关演示记录，不会删除其他真实数据。确认继续吗？');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="admin_seed_demo_data">
                    <button type="submit">初始化演示数据</button>
                </form>
            </div>
            <div class="cards-grid demo-account-grid">
                <div class="feature-card">
                    <h3>普通用户演示账号</h3>
                    <p>13900000001 / 13900000002 / 13900000003</p>
                </div>
                <div class="feature-card">
                    <h3>统一密码</h3>
                    <p>demo123456</p>
                </div>
                <div class="feature-card">
                    <h3>初始化内容</h3>
                    <p>待审核物品、公示捐赠、待投放回收、待审核申领和待处理积分兑换。</p>
                </div>
            </div>
        </section>

        <section class="content-grid">
            <article class="panel">
                <h2 class="section-title">最近提交</h2>
                <?php if ($items === []): ?>
                    <div class="empty-state"><p>暂时还没有用户提交物品。</p></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>物品</th>
                                <th>提交人</th>
                                <th>方式</th>
                                <th>状态</th>
                                <th>时间</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_slice(array_reverse($items), 0, 6) as $item): ?>
                                <tr>
                                    <td data-label="物品"><?= e($item['title']) ?></td>
                                    <td data-label="提交人"><?= e($usersById[(int) $item['user_id']]['nickname'] ?? '未知用户') ?></td>
                                    <td data-label="方式"><?= e(disposal_label((string) $item['disposal_type'])) ?></td>
                                    <td data-label="状态"><span class="badge <?= e(item_badge_class((string) $item['status'])) ?>"><?= e(status_label((string) $item['status'])) ?></span></td>
                                    <td data-label="时间"><?= e($item['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </article>

            <article class="panel">
                <h2 class="section-title">管理提醒</h2>
                <div class="cards-grid" style="grid-template-columns:1fr;">
                    <div class="feature-card">
                        <h3>审核顺序</h3>
                        <p>优先处理待审核物品，再处理公示申请与积分兑换，避免提交者等待过久。</p>
                    </div>
                    <div class="feature-card">
                        <h3>隐私保护</h3>
                        <p>对外公示时不展示手机号等个人信息，联系与交接通过平台审核通知完成。</p>
                    </div>
                    <div class="feature-card">
                        <h3>回收点维护</h3>
                        <p>回收点开放时间或位置有变动时，请先在后台更新，保证用户提交时看到的是最新信息。</p>
                    </div>
                </div>
            </article>
        </section>
    <?php elseif ($tab === 'items'): ?>
        <section class="table-card">
            <h2>待审核物品</h2>
            <?php if ($pendingItems === []): ?>
                <div class="empty-state"><p>目前没有待审核的物品。</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>物品</th>
                            <th>提交人</th>
                            <th>处理方式</th>
                            <th>说明</th>
                            <th>审核操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pendingItems as $item): ?>
                            <tr>
                                <td data-label="物品">
                                    <strong><?= e($item['title']) ?></strong><br>
                                    <span class="muted"><?= e($item['category']) ?> · <?= e(condition_label((string) $item['condition'])) ?></span><br>
                                    <a href="<?= e(app_url(['page' => 'item', 'id' => (int) $item['id']])) ?>">查看详情</a>
                                </td>
                                <td data-label="提交人">
                                    <?= e($usersById[(int) $item['user_id']]['nickname'] ?? '未知用户') ?><br>
                                    <span class="muted"><?= e(mask_phone((string) ($usersById[(int) $item['user_id']]['phone'] ?? ''))) ?></span>
                                </td>
                                <td data-label="处理方式"><?= e(disposal_label((string) $item['disposal_type'])) ?></td>
                                <td data-label="说明"><?= e(text_excerpt((string) $item['description'], 90)) ?></td>
                                <td data-label="审核操作">
                                    <div class="table-actions">
                                        <form method="post">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="admin_review_item">
                                            <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                                            <textarea name="admin_note" placeholder="审核备注或驳回原因"></textarea>
                                            <div class="inline-actions">
                                                <button type="submit" name="decision" value="approve">通过</button>
                                                <button type="submit" class="button-danger" name="decision" value="reject">驳回</button>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="table-card" style="margin-top:18px;">
            <h2>流程中的物品</h2>
            <?php if ($activeItems === []): ?>
                <div class="empty-state"><p>当前没有进行中的物品流程。</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>物品</th>
                            <th>提交人</th>
                            <th>状态</th>
                            <th>管理员操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeItems as $item): ?>
                            <tr>
                                <td data-label="物品"><?= e($item['title']) ?><br><span class="muted"><?= e(disposal_label((string) $item['disposal_type'])) ?></span></td>
                                <td data-label="提交人"><?= e($usersById[(int) $item['user_id']]['nickname'] ?? '未知用户') ?></td>
                                <td data-label="状态"><span class="badge <?= e(item_badge_class((string) $item['status'])) ?>"><?= e(status_label((string) $item['status'])) ?></span></td>
                                <td data-label="管理员操作">
                                    <form method="post" class="table-actions">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="admin_complete_item">
                                        <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                                        <textarea name="admin_note" placeholder="填写交接完成、已投递回收等备注"></textarea>
                                        <button type="submit">标记完成</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($tab === 'door_pickups'): ?>
        <section class="table-card">
            <h2>待上门回收订单</h2>
            <?php if ($pendingDoorPickupItems === []): ?>
                <div class="empty-state"><p>当前没有待处理的上门回收订单。</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>物品</th>
                            <th>提交人</th>
                            <th>宿舍信息</th>
                            <th>预约时段</th>
                            <th>处理</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_reverse($pendingDoorPickupItems) as $item): ?>
                            <tr>
                                <td data-label="物品">
                                    <strong><?= e($item['title']) ?></strong><br>
                                    <span class="muted"><?= e($item['category']) ?> · <?= e(condition_label((string) $item['condition'])) ?></span><br>
                                    <a href="<?= e(app_url(['page' => 'item', 'id' => (int) $item['id']])) ?>">查看详情</a>
                                </td>
                                <td data-label="提交人">
                                    <?= e($usersById[(int) $item['user_id']]['nickname'] ?? '未知用户') ?><br>
                                    <span class="muted"><?= e(mask_phone((string) ($usersById[(int) $item['user_id']]['phone'] ?? ''))) ?></span>
                                </td>
                                <td data-label="宿舍信息"><?= e(door_pickup_address_display($item) ?: '未填写') ?></td>
                                <td data-label="预约时段"><?= e((string) ($item['door_pickup_slot'] ?? '未填写')) ?></td>
                                <td data-label="处理">
                                    <form method="post" class="table-actions">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="admin_complete_item">
                                        <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                                        <textarea name="admin_note" placeholder="填写已上门回收时间、交接情况等备注"></textarea>
                                        <button type="submit">标记已上门回收</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="table-card" style="margin-top:18px;">
            <h2>上门回收完成记录</h2>
            <?php if ($doorPickupHistoryItems === []): ?>
                <div class="empty-state"><p>目前还没有已完成的上门回收记录。</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>物品</th>
                            <th>提交人</th>
                            <th>宿舍信息</th>
                            <th>管理员备注</th>
                            <th>完成时间</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_reverse($doorPickupHistoryItems) as $item): ?>
                            <tr>
                                <td data-label="物品"><?= e($item['title']) ?></td>
                                <td data-label="提交人"><?= e($usersById[(int) $item['user_id']]['nickname'] ?? '未知用户') ?></td>
                                <td data-label="宿舍信息"><?= e(door_pickup_address_display($item) ?: '未填写') ?><br><span class="muted"><?= e((string) ($item['door_pickup_slot'] ?? '')) ?></span></td>
                                <td data-label="管理员备注"><?= e((string) ($item['admin_note'] ?? '暂无')) ?></td>
                                <td data-label="完成时间"><?= e((string) ($item['updated_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($tab === 'applications'): ?>
        <section class="table-card">
            <h2>待审核申请</h2>
            <?php if ($pendingApplications === []): ?>
                <div class="empty-state"><p>目前没有待处理的物品申请。</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>申请物品</th>
                            <th>申请人</th>
                            <th>用途说明</th>
                            <th>处理</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pendingApplications as $application): ?>
                            <?php $item = $itemById[(int) $application['item_id']] ?? null; ?>
                            <tr>
                                <td data-label="申请物品"><?= e($item['title'] ?? '未知物品') ?><br><span class="muted"><?= e($item ? disposal_label((string) $item['disposal_type']) : '') ?></span></td>
                                <td data-label="申请人"><?= e($usersById[(int) $application['applicant_id']]['nickname'] ?? '未知用户') ?><br><span class="muted"><?= e(mask_phone((string) ($usersById[(int) $application['applicant_id']]['phone'] ?? ''))) ?></span></td>
                                <td data-label="用途说明"><?= e($application['purpose']) ?></td>
                                <td data-label="处理">
                                    <form method="post" class="table-actions">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="admin_review_application">
                                        <input type="hidden" name="application_id" value="<?= e((string) $application['id']) ?>">
                                        <textarea name="admin_reply" placeholder="填写通过后的领取说明，或驳回原因"></textarea>
                                        <div class="inline-actions">
                                            <button type="submit" name="decision" value="approve">通过</button>
                                            <button type="submit" class="button-danger" name="decision" value="reject">驳回</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php elseif ($tab === 'rewards'): ?>
        <section class="content-grid">
            <article class="panel">
                <h2 class="section-title">新增积分商品</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="admin_create_reward">
                    <div class="form-grid">
                        <div class="field">
                            <label>商品名称</label>
                            <input name="name" required>
                        </div>
                        <div class="field">
                            <label>所需积分</label>
                            <input name="points_cost" type="number" min="1" required>
                        </div>
                        <div class="field">
                            <label>库存</label>
                            <input name="stock" type="number" min="0" required>
                        </div>
                        <div class="field full">
                            <label>商品说明</label>
                            <textarea name="description"></textarea>
                        </div>
                        <div class="field full">
                            <button type="submit">新增商品</button>
                        </div>
                    </div>
                </form>
            </article>

            <article class="panel">
                <h2 class="section-title">待处理兑换申请</h2>
                <?php if ($pendingRedemptions === []): ?>
                    <div class="empty-state"><p>当前没有待处理的兑换申请。</p></div>
                <?php else: ?>
                    <div class="cards-grid" style="grid-template-columns:1fr;">
                        <?php foreach ($pendingRedemptions as $redemption): ?>
                            <div class="feature-card">
                                <h3><?= e($rewardById[(int) $redemption['reward_id']]['name'] ?? '未知商品') ?></h3>
                                <p>申请人：<?= e($usersById[(int) $redemption['user_id']]['nickname'] ?? '未知用户') ?> · 消耗 <?= e((string) $redemption['points_spent']) ?> 积分</p>
                                <p class="muted"><?= e($redemption['created_at']) ?></p>
                                <form method="post" class="table-actions" style="margin-top:14px;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="admin_handle_redemption">
                                    <input type="hidden" name="redemption_id" value="<?= e((string) $redemption['id']) ?>">
                                    <textarea name="admin_note" placeholder="填写领取地点、发放时间，或驳回原因"></textarea>
                                    <div class="inline-actions">
                                        <button type="submit" name="decision" value="approve">确认发放</button>
                                        <button type="submit" class="button-danger" name="decision" value="reject">驳回并退回积分</button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        </section>

        <section class="table-card" style="margin-top:18px;">
            <h2>积分商品管理</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>商品</th>
                        <th>说明</th>
                        <th>管理</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rewards as $reward): ?>
                        <tr>
                            <td data-label="商品"><?= e($reward['name']) ?><br><span class="muted"><?= e((string) $reward['points_cost']) ?> 积分 · 库存 <?= e((string) $reward['stock']) ?></span></td>
                            <td data-label="说明"><?= e($reward['description']) ?></td>
                            <td data-label="管理">
                                <form method="post" class="table-actions">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="admin_update_reward">
                                    <input type="hidden" name="reward_id" value="<?= e((string) $reward['id']) ?>">
                                    <input name="points_cost" type="number" min="1" value="<?= e((string) $reward['points_cost']) ?>">
                                    <input name="stock" type="number" min="0" value="<?= e((string) $reward['stock']) ?>">
                                    <select name="active">
                                        <option value="1" <?= !empty($reward['active']) ? 'selected' : '' ?>>启用</option>
                                        <option value="0" <?= empty($reward['active']) ? 'selected' : '' ?>>停用</option>
                                    </select>
                                    <button type="submit">更新商品</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($tab === 'points'): ?>
        <section class="content-grid">
            <article class="panel">
                <h2 class="section-title">新增回收点</h2>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="admin_create_pickup_point">
                    <div class="form-grid">
                        <div class="field">
                            <label>点位名称</label>
                            <input name="name" required>
                        </div>
                        <div class="field">
                            <label>所属校区</label>
                            <select name="campus" required>
                                <?php foreach (campus_options() as $campus): ?>
                                    <option value="<?= e($campus) ?>"><?= e($campus) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field full">
                            <label>具体位置</label>
                            <input name="location" required>
                        </div>
                        <div class="field full">
                            <label>开放时间</label>
                            <input name="open_slots" required>
                        </div>
                        <div class="field full">
                            <label>说明</label>
                            <textarea name="description"></textarea>
                        </div>
                        <div class="field full">
                            <button type="submit">新增回收点</button>
                        </div>
                    </div>
                </form>
            </article>

            <article class="panel">
                <h2 class="section-title">回收点位管理建议</h2>
                <div class="cards-grid" style="grid-template-columns:1fr;">
                    <div class="feature-card">
                        <h3>思明校区建议</h3>
                        <p>优先布设在芙蓉区等生活区，以及图书馆、食堂周边等高频公共空间。</p>
                    </div>
                    <div class="feature-card">
                        <h3>翔安校区建议</h3>
                        <p>优先布设在国光区、凌云区等宿舍园区，并与教学楼入口、生活服务点联动设置。</p>
                    </div>
                    <div class="feature-card">
                        <h3>后续扩展</h3>
                        <p>可增加回收点启停、联系人和值班排班等高级管理功能。</p>
                    </div>
                </div>
            </article>
        </section>

        <section class="table-card" style="margin-top:18px;">
            <h2>现有回收点位</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>名称</th>
                        <th>校区</th>
                        <th>位置</th>
                        <th>开放时间</th>
                        <th>说明</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pickupPoints as $point): ?>
                        <tr>
                            <td data-label="名称"><?= e($point['name']) ?></td>
                            <td data-label="校区"><?= e($point['campus']) ?></td>
                            <td data-label="位置"><?= e($point['location']) ?></td>
                            <td data-label="开放时间"><?= e($point['open_slots']) ?></td>
                            <td data-label="说明"><?= e($point['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php elseif ($tab === 'announcements'): ?>
        <section class="content-grid">
            <article class="panel">
                <h2 class="section-title">公告管理</h2>
                <p class="section-lead">这里发布的公告会同步显示在首页公告栏，适合放置兑换领取、回收安排等通知。</p>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="admin_update_announcement">
                    <div class="form-grid">
                        <div class="field full">
                            <label>公告标题</label>
                            <input name="title" value="<?= e((string) ($currentAnnouncement['title'] ?? '')) ?>" required>
                        </div>
                        <div class="field full">
                            <label>公告内容</label>
                            <textarea name="content" rows="7" required><?= e((string) ($currentAnnouncement['content'] ?? '')) ?></textarea>
                        </div>
                        <div class="field">
                            <label>显示状态</label>
                            <select name="active">
                                <option value="1" <?= !empty($currentAnnouncement['active']) ? 'selected' : '' ?>>显示</option>
                                <option value="0" <?= empty($currentAnnouncement['active']) ? 'selected' : '' ?>>隐藏</option>
                            </select>
                        </div>
                        <div class="field full">
                            <button type="submit">保存公告</button>
                        </div>
                    </div>
                </form>
            </article>

            <article class="panel">
                <h2 class="section-title">首页预览</h2>
                <?php if (empty($currentAnnouncement['active'])): ?>
                    <div class="empty-state"><p>当前公告已隐藏，首页不会显示公告栏。</p></div>
                <?php else: ?>
                    <div class="announcement-board announcement-preview">
                        <div class="announcement-board-title">
                            <span>公告栏</span>
                        </div>
                        <article class="announcement-item">
                            <h2><?= e($currentAnnouncement['title'] ?? '公告') ?></h2>
                            <p><?= nl2br(e((string) ($currentAnnouncement['content'] ?? ''))) ?></p>
                        </article>
                    </div>
                <?php endif; ?>
            </article>
        </section>
    <?php else: ?>
        <section class="empty-state">
            <p>后台页面不存在，请返回概览继续处理。</p>
            <a class="button" href="<?= e(app_url(['page' => 'admin', 'tab' => 'overview'])) ?>">返回概览</a>
        </section>
    <?php endif; ?>
    <?php
else:
    ?>
    <section class="empty-state">
        <p>页面不存在，请返回首页继续使用。</p>
        <a class="button" href="<?= e(app_url()) ?>">返回首页</a>
    </section>
    <?php
endif;

render_footer();
