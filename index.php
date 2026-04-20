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
        'pending_review', 'dropoff_ready', 'dropoff_delivered', 'pickup_scheduled', 'picked_up' => 'badge-warn',
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

function refund_application_reserved_points(array &$application, ?array $item, string $reason = ''): void
{
    $reservedPoints = (int) ($application['reserved_points'] ?? 0);
    if ($reservedPoints <= 0 || !empty($application['points_refunded'])) {
        return;
    }

    $title = $item !== null ? (string) ($item['title'] ?? '该物品') : '该物品';
    $content = '你申请《' . $title . '》暂扣的 ' . $reservedPoints . ' 积分已退回。';
    if ($reason !== '') {
        $content .= '原因：' . $reason;
    }

    adjust_user_points(
        (int) ($application['applicant_id'] ?? 0),
        $reservedPoints,
        '物品兑换积分已退回',
        $content,
        app_url(['page' => 'dashboard', 'section' => 'applications'])
    );

    $application['points_refunded'] = true;
    $application['updated_at'] = now();
}

function sync_door_pickup_confirmation_status(array &$item): bool
{
    if (($item['disposal_type'] ?? '') !== 'door_pickup') {
        return false;
    }

    if (!empty($item['user_pickup_confirmed']) && !empty($item['admin_pickup_confirmed']) && ($item['status'] ?? '') !== 'picked_up') {
        $item['status'] = 'picked_up';
        $item['updated_at'] = now();
        return true;
    }

    return false;
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

                if ($doorPickupFloor === '' || !ctype_digit($doorPickupFloor) || (int) $doorPickupFloor < 1 || (int) $doorPickupFloor > 13) {
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
                'item_code' => '',
                'status' => 'pending_review',
                'admin_note' => '',
                'approved_at' => '',
                'completed_at' => '',
                'reference_price' => 0,
                'exchange_points' => 0,
                'reward_points' => 0,
                'points_awarded' => false,
                'matched_application_id' => 0,
                'user_dropoff_confirmed' => false,
                'user_dropoff_confirmed_at' => '',
                'user_pickup_confirmed' => false,
                'user_pickup_confirmed_at' => '',
                'admin_pickup_confirmed' => false,
                'admin_pickup_confirmed_at' => '',
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
            $exchangePoints = $item !== null ? item_exchange_points($item) : 0;

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
            if ($exchangePoints > 0 && (int) ($user['points'] ?? 0) < $exchangePoints) {
                flash('error', '当前积分不足，暂时无法申请该可再利用物品。');
                redirect(app_url(['page' => 'item', 'id' => $itemId]));
            }

            $applications = load_dataset('applications');
            foreach ($applications as $application) {
                if ((int) ($application['item_id'] ?? 0) === $itemId && (int) ($application['applicant_id'] ?? 0) === (int) $user['id'] && ($application['status'] ?? '') === 'pending') {
                    flash('error', '你已经提交过申请，请等待管理员审核。');
                    redirect(app_url(['page' => 'item', 'id' => $itemId]));
                }
            }

            if ($exchangePoints > 0) {
                adjust_user_points(
                    (int) $user['id'],
                    -$exchangePoints,
                    '可再利用物品申请已提交',
                    '你已申请《' . $item['title'] . '》，系统暂扣 ' . $exchangePoints . ' 积分，若申请未通过会自动退回。',
                    app_url(['page' => 'dashboard', 'section' => 'applications'])
                );
            }

            $applications[] = [
                'id' => next_id($applications),
                'item_id' => $itemId,
                'applicant_id' => (int) $user['id'],
                'purpose' => $purpose,
                'status' => 'pending',
                'admin_reply' => '',
                'reserved_points' => $exchangePoints,
                'points_refunded' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            save_dataset('applications', $applications);
            add_message((int) $item['user_id'], '有新的物品申请', '你发布的《' . $item['title'] . '》收到了新的申请，管理员审核后会继续通知你。', app_url(['page' => 'dashboard']));
            flash('success', $exchangePoints > 0 ? '申请已提交，所需积分已暂扣，请等待管理员审核。' : '申请已提交，请耐心等待管理员审核。');
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

        case 'user_confirm_dropoff':
            $user = require_login();
            $itemId = (int) post_value('item_id', 0);
            $items = load_dataset('items');

            foreach ($items as &$item) {
                if ((int) ($item['id'] ?? 0) !== $itemId || (int) ($item['user_id'] ?? 0) !== (int) $user['id']) {
                    continue;
                }

                if (($item['disposal_type'] ?? '') !== 'recycle' || ($item['status'] ?? '') !== 'dropoff_ready') {
                    flash('error', '该记录当前无法确认已投递。');
                    redirect(app_url(['page' => 'dashboard']));
                }

                $item['user_dropoff_confirmed'] = true;
                $item['user_dropoff_confirmed_at'] = now();
                $item['status'] = 'dropoff_delivered';
                $item['updated_at'] = now();
                save_dataset('items', $items);
                flash('success', '已确认物品投递，回收人员后续会根据编号统一回收。');
                redirect(app_url(['page' => 'dashboard']));
            }
            unset($item);

            flash('error', '未找到可操作的投递记录。');
            redirect(app_url(['page' => 'dashboard']));

        case 'user_confirm_pickup':
            $user = require_login();
            $itemId = (int) post_value('item_id', 0);
            $items = load_dataset('items');

            foreach ($items as &$item) {
                if ((int) ($item['id'] ?? 0) !== $itemId || (int) ($item['user_id'] ?? 0) !== (int) $user['id']) {
                    continue;
                }

                if (($item['disposal_type'] ?? '') !== 'door_pickup' || !in_array((string) ($item['status'] ?? ''), ['pickup_scheduled', 'picked_up'], true)) {
                    flash('error', '该记录当前无法确认已取走。');
                    redirect(app_url(['page' => 'dashboard']));
                }

                $item['user_pickup_confirmed'] = true;
                $item['user_pickup_confirmed_at'] = now();
                $statusChanged = sync_door_pickup_confirmation_status($item);
                if (!$statusChanged) {
                    $item['updated_at'] = now();
                }

                save_dataset('items', $items);
                flash('success', $statusChanged ? '已完成双方取走确认，等待管理员入仓核验。' : '已记录你的取走确认，等待回收人员同步确认。');
                redirect(app_url(['page' => 'dashboard']));
            }
            unset($item);

            flash('error', '未找到可操作的上门回收记录。');
            redirect(app_url(['page' => 'dashboard']));

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
            $exchangePoints = (int) post_value('exchange_points', 0);
            $items = load_dataset('items');

            foreach ($items as &$item) {
                if ((int) ($item['id'] ?? 0) !== $itemId) {
                    continue;
                }

                $item['admin_note'] = $note;
                $item['updated_at'] = now();

                if ($decision === 'approve') {
                    $disposalType = (string) ($item['disposal_type'] ?? '');
                    if ($disposalType === 'donation') {
                        $item['exchange_points'] = max(1, $exchangePoints > 0 ? $exchangePoints : calculate_item_exchange_points($item));
                    } else {
                        $item['exchange_points'] = 0;
                    }

                    $item['reward_points'] = 0;
                    $item['reference_price'] = (float) ($item['reference_price'] ?? 0);
                    $item['approved_at'] = now();
                    $item['item_code'] = ((string) ($item['item_code'] ?? '')) !== '' ? (string) $item['item_code'] : generate_item_code($item);
                    $item['user_dropoff_confirmed'] = false;
                    $item['user_dropoff_confirmed_at'] = '';
                    $item['user_pickup_confirmed'] = false;
                    $item['user_pickup_confirmed_at'] = '';
                    $item['admin_pickup_confirmed'] = false;
                    $item['admin_pickup_confirmed_at'] = '';

                    $item['status'] = match ($disposalType) {
                        'recycle' => 'dropoff_ready',
                        'door_pickup' => 'pickup_scheduled',
                        default => 'published',
                    };

                    $message = '你提交的《' . $item['title'] . '》已通过审核，系统编号为 ' . $item['item_code'] . '。';
                    if ($disposalType === 'donation') {
                        $message .= '该物品已进入公示大厅，兑换所需积分为 ' . $item['exchange_points'] . ' 分；完成交接后，系统会根据最终核验参考价自动计算并发放积分。';
                    } elseif ($disposalType === 'recycle') {
                        $message .= '请在投递前将该编号写在便签纸上贴在物品上，投递到固定回收点后记得在系统中确认“已投递”。入仓核验无误后，系统会根据最终参考回收价自动发放积分。';
                    } else {
                        $message .= '请在回收人员上门前将该编号标记在物品上；物品被取走后，回收人员和你都需要在系统中确认“已取走”。入仓核验无误后，系统会根据最终参考回收价自动发放积分。';
                    }
                    if ($note !== '') {
                        $message .= '管理员备注：' . $note;
                    }

                    add_message(
                        (int) $item['user_id'],
                        $disposalType === 'door_pickup' ? '物品审核通过，等待上门回收' : '物品审核通过',
                        $message,
                        app_url(['page' => 'item', 'id' => (int) $item['id']])
                    );
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

        case 'admin_confirm_pickup':
            require_admin_user();
            $itemId = (int) post_value('item_id', 0);
            $note = trim((string) post_value('admin_note'));
            $items = load_dataset('items');

            foreach ($items as &$item) {
                if ((int) ($item['id'] ?? 0) !== $itemId) {
                    continue;
                }

                if (($item['disposal_type'] ?? '') !== 'door_pickup' || !in_array((string) ($item['status'] ?? ''), ['pickup_scheduled', 'picked_up'], true)) {
                    flash('error', '该记录当前无法标记为已取走。');
                    redirect(app_url(['page' => 'admin', 'tab' => 'door_pickups']));
                }

                $item['admin_pickup_confirmed'] = true;
                $item['admin_pickup_confirmed_at'] = now();
                if ($note !== '') {
                    $item['admin_note'] = $note;
                }

                $statusChanged = sync_door_pickup_confirmation_status($item);
                if (!$statusChanged) {
                    $item['updated_at'] = now();
                }

                save_dataset('items', $items);
                add_message(
                    (int) $item['user_id'],
                    $statusChanged ? '物品已完成双方取走确认' : '回收人员已确认取走',
                    $statusChanged
                        ? '《' . $item['title'] . '》已完成双方取走确认，管理员接下来会在集中分类仓库完成核验。'
                        : '回收人员已确认取走《' . $item['title'] . '》，请你也在系统中确认“已取走”，方便后续入仓核验。',
                    app_url(['page' => 'dashboard'])
                );
                flash('success', $statusChanged ? '已完成双方取走确认，等待入仓核验。' : '已记录回收人员取走确认。');
                redirect(app_url(['page' => 'admin', 'tab' => 'door_pickups']));
            }
            unset($item);

            flash('error', '未找到可操作的上门回收记录。');
            redirect(app_url(['page' => 'admin', 'tab' => 'door_pickups']));

        case 'admin_complete_item':
            require_admin_user();
            $itemId = (int) post_value('item_id', 0);
            $note = trim((string) post_value('admin_note'));
            $referencePrice = round((float) post_value('reference_price', 0), 2);
            $items = load_dataset('items');
            $applications = load_dataset('applications');
            $redirectTab = 'items';

            foreach ($items as &$item) {
                if ((int) ($item['id'] ?? 0) !== $itemId) {
                    continue;
                }

                if (!in_array((string) ($item['status'] ?? ''), ['dropoff_delivered', 'picked_up', 'matched'], true)) {
                    flash('error', '该记录当前还不能直接完结，请先完成前置确认。');
                    redirect(app_url(['page' => 'admin', 'tab' => ($item['disposal_type'] ?? '') === 'door_pickup' ? 'door_pickups' : 'items']));
                }
                if ($referencePrice <= 0) {
                    flash('error', '请先填写最终核验参考价，系统会据此自动换算积分。');
                    redirect(app_url(['page' => 'admin', 'tab' => ($item['disposal_type'] ?? '') === 'door_pickup' ? 'door_pickups' : 'items']));
                }

                $item['status'] = 'completed';
                $item['admin_note'] = $note;
                $item['reference_price'] = $referencePrice;
                $item['completed_at'] = now();
                $item['updated_at'] = now();

                $rewardPoints = reward_points_from_reference_price($referencePrice);
                $item['reward_points'] = $rewardPoints;
                if (empty($item['points_awarded'])) {
                    $item['points_awarded'] = true;
                    adjust_user_points(
                        (int) $item['user_id'],
                        $rewardPoints,
                        '物品流程完成，积分到账',
                        '你提交的《' . $item['title'] . '》已完成最终核验，参考价为 ' . number_format($referencePrice, 2) . ' 元，系统已按规则发放 ' . $rewardPoints . ' 积分。',
                        app_url(['page' => 'dashboard'])
                    );
                } else {
                    add_message((int) $item['user_id'], '物品流程已完成', '《' . $item['title'] . '》已完成最终处理。' . ($note !== '' ? '管理员备注：' . $note : ''), app_url(['page' => 'dashboard']));
                }

                if (($item['disposal_type'] ?? '') === 'door_pickup') {
                    $redirectTab = 'door_pickups';
                }

                $matchedId = (int) ($item['matched_application_id'] ?? 0);
                if ($matchedId > 0) {
                    foreach ($applications as $application) {
                        if ((int) ($application['id'] ?? 0) !== $matchedId) {
                            continue;
                        }

                        $extra = item_exchange_points($item) > 0
                            ? '本次领取共消耗 ' . item_exchange_points($item) . ' 积分。'
                            : '';
                        add_message((int) $application['applicant_id'], '申请物品已完成交接', '《' . $item['title'] . '》已完成交接。' . $extra . ($note !== '' ? '管理员备注：' . $note : ''), app_url(['page' => 'dashboard']));
                        break;
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

            $targetIndex = null;
            foreach ($applications as $index => &$application) {
                if ((int) ($application['id'] ?? 0) !== $applicationId) {
                    continue;
                }
                $application['status'] = $decision === 'approve' ? 'approved' : 'rejected';
                $application['admin_reply'] = $reply;
                $application['updated_at'] = now();
                $targetIndex = $index;
                break;
            }
            unset($application);

            if ($targetIndex === null) {
                flash('error', '申请记录不存在。');
                redirect(app_url(['page' => 'admin', 'tab' => 'applications']));
            }

            $targetApplication = $applications[$targetIndex];

            foreach ($items as &$item) {
                if ((int) ($item['id'] ?? 0) !== (int) ($targetApplication['item_id'] ?? 0)) {
                    continue;
                }

                $requiredPoints = item_exchange_points($item);
                if ($decision === 'approve') {
                    if ($requiredPoints > 0 && (int) ($applications[$targetIndex]['reserved_points'] ?? 0) <= 0) {
                        $applicant = find_record(load_dataset('users'), (int) ($targetApplication['applicant_id'] ?? 0));
                        if ($applicant === null || (int) ($applicant['points'] ?? 0) < $requiredPoints) {
                            flash('error', '该申请人当前积分不足，无法通过审核。');
                            redirect(app_url(['page' => 'admin', 'tab' => 'applications']));
                        }

                        adjust_user_points(
                            (int) $targetApplication['applicant_id'],
                            -$requiredPoints,
                            '可再利用物品审核通过',
                            '《' . $item['title'] . '》审核通过，系统已扣除 ' . $requiredPoints . ' 积分。',
                            app_url(['page' => 'dashboard', 'section' => 'applications'])
                        );
                        $applications[$targetIndex]['reserved_points'] = $requiredPoints;
                        $applications[$targetIndex]['points_refunded'] = false;
                    }

                    $item['status'] = 'matched';
                    $item['matched_application_id'] = $applicationId;
                    $item['updated_at'] = now();

                    add_message(
                        (int) $targetApplication['applicant_id'],
                        '物品申请已通过',
                        '你申请的《' . $item['title'] . '》已通过审核。'
                        . ($requiredPoints > 0 ? '该物品兑换所需的 ' . $requiredPoints . ' 积分已为你锁定。' : '')
                        . ($reply !== '' ? '领取说明：' . $reply : '请等待管理员进一步通知领取安排。'),
                        app_url(['page' => 'dashboard'])
                    );
                    add_message((int) $item['user_id'], '你的物品已匹配成功', '《' . $item['title'] . '》已有申请通过审核。' . ($reply !== '' ? '管理员说明：' . $reply : ''), app_url(['page' => 'dashboard']));

                    foreach ($applications as $otherIndex => &$otherApplication) {
                        if ((int) ($otherApplication['item_id'] ?? 0) !== (int) ($item['id'] ?? 0) || (int) ($otherApplication['id'] ?? 0) === $applicationId || ($otherApplication['status'] ?? '') !== 'pending') {
                            continue;
                        }

                        $otherApplication['status'] = 'rejected';
                        $otherApplication['admin_reply'] = '该物品已匹配给其他同学，感谢你的关注。';
                        $otherApplication['updated_at'] = now();
                        refund_application_reserved_points($otherApplication, $item, '该物品已分配给其他申请人。');
                        add_message((int) $otherApplication['applicant_id'], '物品申请未通过', '你申请的《' . $item['title'] . '》已分配给其他同学，本次申请未通过。', app_url(['page' => 'listings']));
                        $applications[$otherIndex] = $otherApplication;
                    }
                    unset($otherApplication);
                } else {
                    refund_application_reserved_points($applications[$targetIndex], $item, $reply !== '' ? $reply : '管理员未通过该申请。');
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
                        <span>积分会按最终核验参考价分段发放，可兑换环保小礼品或公示大厅中的可再利用设备。</span>
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
                        <p>公示页面不展示手机号等个人信息；积分会在物品完成交接或入仓核验后发放，并可用于兑换礼品或申请公示大厅中的可再利用设备。</p>
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
    $pickupCampusOptions = campus_options();
    $defaultPickupCampus = in_array((string) ($user['campus'] ?? ''), $pickupCampusOptions, true)
        ? (string) $user['campus']
        : '';
    $defaultPickupZones = $defaultPickupCampus !== ''
        ? (pickup_zone_catalog()[$defaultPickupCampus] ?? [])
        : [];
    $doorPickupBuildings = door_pickup_buildings_for((string) ($user['campus'] ?? ''));
    $doorPickupSlots = door_pickup_time_slot_options();
    ?>
    <section class="panel">
        <div class="panel-header">
                <div>
                    <h2 class="section-title">提交待处理电子产品</h2>
                    <p class="section-lead">请尽量填写准确、完整的信息。管理员审核通过后会生成物品编号，再进入公示或回收流程；积分会在最终交接或入仓核验后发放。</p>
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
                        <option value="" <?= $defaultPickupCampus === '' ? 'selected' : '' ?> disabled hidden>请选择校区</option>
                        <?php foreach ($pickupCampusOptions as $campus): ?>
                            <option value="<?= e($campus) ?>" <?= $campus === $defaultPickupCampus ? 'selected' : '' ?>><?= e($campus) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="pickup_zone">园区</label>
                    <select id="pickup_zone" name="pickup_zone" data-pickup-zone data-selected="">
                        <option value="" selected disabled hidden>请选择园区</option>
                        <?php foreach ($defaultPickupZones as $zone): ?>
                            <option value="<?= e($zone) ?>"><?= e($zone) ?></option>
                        <?php endforeach; ?>
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
                        <?php for ($floor = 1; $floor <= 13; $floor++): ?>
                            <option value="<?= e((string) $floor) ?>"><?= e((string) $floor) ?> 层</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="door_pickup_room">房间号</label>
                    <input id="door_pickup_room" name="door_pickup_room" placeholder="例如1105/1209">
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
                                <span class="pill"><?= e((string) item_exchange_points($item)) ?> 积分兑换</span>
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
        $exchangePoints = item_exchange_points($item);
        $rewardPoints = item_reward_points($item);
        $rewardPointsLabel = item_reward_points_label($item);
        $canViewItemCode = $currentUser !== null && (is_admin($currentUser) || (int) ($item['user_id'] ?? 0) === (int) ($currentUser['id'] ?? 0));
        ?>
        <section class="content-grid">
            <article class="panel">
                <img class="item-image" src="<?= e($item['image']) ?>" alt="<?= e($item['title']) ?>" style="margin-bottom:18px;">
                <div class="item-title-row">
                    <div>
                        <h2 class="section-title"><?= e($item['title']) ?></h2>
                        <p class="section-lead"><?= e($item['category']) ?> · <?= e(condition_label((string) $item['condition'])) ?></p>
                    </div>
                    <span class="badge <?= e(item_badge_class((string) $item['status'])) ?>"><?= e(item_status_label((string) $item['status'])) ?></span>
                </div>
                <div class="key-value">
                    <div><span>处理方式</span><strong><?= e(disposal_label((string) $item['disposal_type'])) ?></strong></div>
                    <div><span>品牌 / 型号</span><strong><?= e($item['brand'] ?: '未填写') ?></strong></div>
                    <div><span>发布校区</span><strong><?= e($owner['campus'] ?? '未知') ?></strong></div>
                    <div><span>完成后奖励积分</span><strong><?= e($rewardPointsLabel) ?></strong></div>
                    <?php if ((float) ($item['reference_price'] ?? 0) > 0): ?>
                        <div><span>最终核验参考价</span><strong><?= e(number_format((float) $item['reference_price'], 2)) ?> 元</strong></div>
                    <?php endif; ?>
                    <?php if (($item['disposal_type'] ?? '') === 'donation'): ?>
                        <div><span>兑换所需积分</span><strong><?= e((string) $exchangePoints) ?></strong></div>
                    <?php endif; ?>
                    <?php if ($canViewItemCode && ((string) ($item['item_code'] ?? '')) !== ''): ?>
                        <div><span>物品编号</span><strong><?= e((string) $item['item_code']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (($item['disposal_type'] ?? '') === 'recycle'): ?>
                        <div><span>回收点</span><strong><?= e($pickupDisplay !== '' ? $pickupDisplay : '未设置') ?></strong></div>
                        <div><span>预计投递时间</span><strong><?= e($item['pickup_time'] ?: '未填写') ?></strong></div>
                        <?php if (!empty($item['user_dropoff_confirmed'])): ?>
                            <div><span>投递确认</span><strong>已确认投递</strong></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (($item['disposal_type'] ?? '') === 'door_pickup'): ?>
                        <div><span>上门地址</span><strong><?= e($doorPickupAddress !== '' ? $doorPickupAddress : '未设置') ?></strong></div>
                        <div><span>预约时段</span><strong><?= e($item['door_pickup_slot'] ?: '未填写') ?></strong></div>
                        <div><span>用户确认取走</span><strong><?= !empty($item['user_pickup_confirmed']) ? '已确认' : '待确认' ?></strong></div>
                        <div><span>回收人员确认取走</span><strong><?= !empty($item['admin_pickup_confirmed']) ? '已确认' : '待确认' ?></strong></div>
                    <?php endif; ?>
                    <?php if (($item['target_group'] ?? '') !== ''): ?>
                        <div><span>适用人群</span><strong><?= e($item['target_group']) ?></strong></div>
                    <?php endif; ?>
                </div>
                <?php if ($canViewItemCode && ((string) ($item['item_code'] ?? '')) !== '' && in_array((string) ($item['status'] ?? ''), ['dropoff_ready', 'dropoff_delivered', 'pickup_scheduled', 'picked_up'], true)): ?>
                    <div class="divider"></div>
                    <div class="feature-card">
                        <h3>编号标记提醒</h3>
                        <p>请在物品上贴好编号 <?= e((string) $item['item_code']) ?>，可用便签纸手写后贴在物品表面，便于回收人员现场识别。</p>
                    </div>
                <?php endif; ?>
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
                        <div class="feature-card" style="margin-bottom:16px;">
                            <h3><?= e((string) $exchangePoints) ?> 积分兑换</h3>
                            <p>提交申请后系统会先暂扣所需积分，若管理员未通过申请会自动退回。</p>
                            <?php if ($currentUser !== null): ?>
                                <p>你当前可用积分：<?= e((string) ($currentUser['points'] ?? 0)) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="field">
                            <label for="purpose">申请原因 / 用途说明</label>
                            <textarea id="purpose" name="purpose" placeholder="例如：课程设计需要该设备、个人学习使用、家庭经济困难希望获得帮助。" required></textarea>
                        </div>
                        <div class="inline-actions" style="margin-top:16px;">
                            <input type="submit" value="提交申领申请" <?= ((int) ($currentUser['points'] ?? 0) < $exchangePoints) ? 'disabled' : '' ?>>
                        </div>
                        <?php if ((int) ($currentUser['points'] ?? 0) < $exchangePoints): ?>
                            <p class="muted" style="margin-top:12px;">当前积分不足，暂时无法提交该物品的兑换申请。</p>
                        <?php endif; ?>
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
                    <p>积分会在物品完成交接或入仓核验后发放，申请可再利用物品时会先暂扣所需积分。</p>
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
                        <p>你还没有提交过电子产品。提交后系统会先审核并生成物品编号，再根据处理方式进入公示、投递或上门回收流程。</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>物品</th>
                                <th>物品编号</th>
                                <th>处理方式</th>
                                <th>完成奖励</th>
                                <th>状态</th>
                                <th>提交时间</th>
                                <th>管理员备注</th>
                                <th>操作</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_reverse($myItems) as $item): ?>
                                <tr>
                                    <td data-label="物品"><a href="<?= e(app_url(['page' => 'item', 'id' => (int) $item['id']])) ?>"><?= e($item['title']) ?></a></td>
                                    <td data-label="物品编号"><?= e((string) ($item['item_code'] ?? '待审核后生成')) ?></td>
                                    <td data-label="处理方式"><?= e(disposal_label((string) $item['disposal_type'])) ?></td>
                                    <td data-label="完成奖励"><?= e(item_reward_points_label($item)) ?></td>
                                    <td data-label="状态"><span class="badge <?= e(item_badge_class((string) $item['status'])) ?>"><?= e(item_status_label((string) $item['status'])) ?></span></td>
                                    <td data-label="提交时间"><?= e($item['created_at']) ?></td>
                                    <td data-label="管理员备注"><?= e($item['admin_note'] ?: '暂无') ?></td>
                                    <td data-label="操作">
                                        <?php if (($item['status'] ?? '') === 'dropoff_ready'): ?>
                                            <form method="post" class="table-actions">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="user_confirm_dropoff">
                                                <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                                                <button type="submit">确认已投递</button>
                                            </form>
                                        <?php elseif (($item['status'] ?? '') === 'pickup_scheduled' && empty($item['user_pickup_confirmed'])): ?>
                                            <form method="post" class="table-actions">
                                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="user_confirm_pickup">
                                                <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                                                <button type="submit">确认已取走</button>
                                            </form>
                                        <?php elseif (($item['status'] ?? '') === 'dropoff_delivered'): ?>
                                            <span class="muted">等待回收人员统一回收</span>
                                        <?php elseif (($item['status'] ?? '') === 'pickup_scheduled' && !empty($item['user_pickup_confirmed'])): ?>
                                            <span class="muted">已确认，等待回收人员确认</span>
                                        <?php elseif (($item['status'] ?? '') === 'picked_up'): ?>
                                            <span class="muted">等待管理员入仓核验</span>
                                        <?php else: ?>
                                            <span class="muted">暂无操作</span>
                                        <?php endif; ?>
                                    </td>
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
                                <th>暂扣积分</th>
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
                                    <td data-label="暂扣积分"><?= e((string) ((int) ($application['reserved_points'] ?? 0))) ?></td>
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
    $rewardPointTiers = reward_points_tiers();
    ?>
    <section class="content-grid">
        <article class="panel">
            <div class="panel-header">
                <div>
                    <h2 class="section-title">积分商城</h2>
                    <p class="section-lead">积分可用于兑换环保主题小物品，也可用于在公示大厅申请可继续使用的电子废弃物；积分会在最终交接或入仓核验后发放。</p>
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
                    <h3>参考价分段换积分</h3>
                    <p>管理员会在最终完成环节录入参考价，系统再按分段自动换算积分，并在完成核验后一次性发放。</p>
                </div>
                <div class="feature-card">
                    <h3>积分扣减规则</h3>
                    <p>提交积分商品兑换或公示大厅可再利用物品申请时会先暂扣积分，若管理员驳回则自动退回。</p>
                </div>
            </div>
            <div class="table-wrap" style="margin-top:16px;">
                <table>
                    <thead>
                    <tr>
                        <th>参考价区间</th>
                        <th>对应积分</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rewardPointTiers as $tier): ?>
                        <tr>
                            <td data-label="参考价区间">
                                <?= e(rtrim(rtrim(number_format((float) $tier['min'], 2), '0'), '.')) ?> 元
                                <?php if ($tier['max'] !== null): ?>
                                    - <?= e(rtrim(rtrim(number_format((float) $tier['max'], 2), '0'), '.')) ?> 元
                                <?php else: ?>
                                    及以上
                                <?php endif; ?>
                            </td>
                            <td data-label="对应积分"><?= e((string) $tier['points']) ?> 积分</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
    <?php
elseif ($page === 'admin'):
    require_admin_user();
    $tab = (string) get_value('tab', 'overview');
    $pendingItems = array_values(array_filter($items, static fn(array $item): bool => ($item['status'] ?? '') === 'pending_review'));
    $activeItems = array_values(array_filter($items, static fn(array $item): bool => in_array($item['status'] ?? '', ['published', 'dropoff_ready', 'dropoff_delivered', 'matched'], true) && ($item['disposal_type'] ?? '') !== 'door_pickup'));
    $pendingDoorPickupItems = array_values(array_filter($items, static fn(array $item): bool => ($item['disposal_type'] ?? '') === 'door_pickup' && ($item['status'] ?? '') === 'pickup_scheduled'));
    $pickedUpDoorPickupItems = array_values(array_filter($items, static fn(array $item): bool => ($item['disposal_type'] ?? '') === 'door_pickup' && ($item['status'] ?? '') === 'picked_up'));
    $doorPickupHistoryItems = array_values(array_filter($items, static fn(array $item): bool => ($item['disposal_type'] ?? '') === 'door_pickup' && ($item['status'] ?? '') === 'completed'));
    $warehousePendingItems = array_values(array_filter($items, static fn(array $item): bool => in_array(($item['status'] ?? ''), ['dropoff_delivered', 'picked_up'], true)));
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
                <strong><?= e((string) count($warehousePendingItems)) ?></strong>
                <h3>待入仓核验</h3>
                <p>已完成投递或取走确认，等待集中分类仓库核验。</p>
            </article>
            <article class="stat-card">
                <strong><?= e((string) count(active_pickup_points())) ?></strong>
                <h3>启用中的回收点</h3>
                <p>覆盖两个校区的回收场景。</p>
            </article>
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
                                    <td data-label="状态"><span class="badge <?= e(item_badge_class((string) $item['status'])) ?>"><?= e(item_status_label((string) $item['status'])) ?></span></td>
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
                                            <?php if (($item['disposal_type'] ?? '') === 'donation'): ?>
                                                <input name="exchange_points" type="number" min="1" value="<?= e((string) item_exchange_points($item)) ?>" placeholder="公示兑换所需积分">
                                            <?php endif; ?>
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
                            <th>编号 / 积分</th>
                            <th>状态</th>
                            <th>管理员操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeItems as $item): ?>
                            <tr>
                                <td data-label="物品"><?= e($item['title']) ?><br><span class="muted"><?= e(disposal_label((string) $item['disposal_type'])) ?></span></td>
                                <td data-label="提交人"><?= e($usersById[(int) $item['user_id']]['nickname'] ?? '未知用户') ?></td>
                                <td data-label="编号 / 积分">
                                    <span class="muted"><?= e((string) ($item['item_code'] ?? '待生成')) ?></span><br>
                                    <span class="muted">
                                        奖励 <?= e(item_reward_points_label($item)) ?>
                                        <?php if (($item['disposal_type'] ?? '') === 'donation'): ?>
                                            · 兑换 <?= e((string) item_exchange_points($item)) ?> 分
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td data-label="状态"><span class="badge <?= e(item_badge_class((string) $item['status'])) ?>"><?= e(item_status_label((string) $item['status'])) ?></span></td>
                                <td data-label="管理员操作">
                                    <?php if (($item['status'] ?? '') === 'dropoff_delivered' || ($item['status'] ?? '') === 'matched'): ?>
                                        <form method="post" class="table-actions">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="admin_complete_item">
                                            <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                                            <input name="reference_price" type="number" min="0.01" step="0.01" placeholder="<?= ($item['status'] ?? '') === 'matched' ? '最终流转参考价（元）' : '最终回收参考价（元）' ?>" required>
                                            <textarea name="admin_note" placeholder="<?= ($item['status'] ?? '') === 'matched' ? '填写交接完成备注' : '填写入仓核验备注' ?>"></textarea>
                                            <button type="submit"><?= ($item['status'] ?? '') === 'matched' ? '确认交接完成' : '确认入仓并发积分' ?></button>
                                        </form>
                                    <?php elseif (($item['status'] ?? '') === 'dropoff_ready'): ?>
                                        <span class="muted">等待用户按编号完成投递</span>
                                    <?php else: ?>
                                        <span class="muted">流程进行中，暂不需要后台操作</span>
                                    <?php endif; ?>
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
                            <th>物品编号</th>
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
                                <td data-label="物品编号">
                                    <?= e((string) ($item['item_code'] ?? '待生成')) ?><br>
                                    <span class="muted">用户<?= !empty($item['user_pickup_confirmed']) ? '已' : '未' ?>确认 · 回收人员<?= !empty($item['admin_pickup_confirmed']) ? '已' : '未' ?>确认</span>
                                </td>
                                <td data-label="宿舍信息"><?= e(door_pickup_address_display($item) ?: '未填写') ?></td>
                                <td data-label="预约时段"><?= e((string) ($item['door_pickup_slot'] ?? '未填写')) ?></td>
                                <td data-label="处理">
                                    <?php if (empty($item['admin_pickup_confirmed'])): ?>
                                        <form method="post" class="table-actions">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="admin_confirm_pickup">
                                            <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                                            <textarea name="admin_note" placeholder="填写已取走时间、交接情况等备注"></textarea>
                                            <button type="submit">确认已取走</button>
                                        </form>
                                    <?php elseif (!empty($item['user_pickup_confirmed'])): ?>
                                        <span class="muted">双方已确认，等待系统转入入仓核验</span>
                                    <?php else: ?>
                                        <span class="muted">已记录回收人员确认，等待用户确认</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="table-card" style="margin-top:18px;">
            <h2>待入仓核验的上门回收记录</h2>
            <?php if ($pickedUpDoorPickupItems === []): ?>
                <div class="empty-state"><p>当前没有待入仓核验的上门回收记录。</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>物品</th>
                            <th>编号</th>
                            <th>宿舍信息</th>
                            <th>处理</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_reverse($pickedUpDoorPickupItems) as $item): ?>
                            <tr>
                                <td data-label="物品"><?= e($item['title']) ?><br><span class="muted"><?= e($usersById[(int) $item['user_id']]['nickname'] ?? '未知用户') ?></span></td>
                                <td data-label="编号"><?= e((string) ($item['item_code'] ?? '')) ?><br><span class="muted">奖励 <?= e(item_reward_points_label($item)) ?></span></td>
                                <td data-label="宿舍信息"><?= e(door_pickup_address_display($item) ?: '未填写') ?><br><span class="muted"><?= e((string) ($item['door_pickup_slot'] ?? '')) ?></span></td>
                                <td data-label="处理">
                                    <form method="post" class="table-actions">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="admin_complete_item">
                                        <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                                        <input name="reference_price" type="number" min="0.01" step="0.01" placeholder="最终回收参考价（元）" required>
                                        <textarea name="admin_note" placeholder="填写入仓核验结果与分类备注"></textarea>
                                        <button type="submit">确认入仓并发积分</button>
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
                            <th>暂扣积分</th>
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
                                <td data-label="暂扣积分"><?= e((string) ((int) ($application['reserved_points'] ?? 0))) ?></td>
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
