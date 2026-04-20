<?php
declare(strict_types=1);

const DATASETS = [
    'users',
    'items',
    'applications',
    'messages',
    'rewards',
    'redemptions',
    'pickup_points',
    'announcements',
];

function storage_driver(): string
{
    return strtolower((string) (defined('STORAGE_DRIVER') ? STORAGE_DRIVER : 'json'));
}

function is_mysql_storage(): bool
{
    return storage_driver() === 'mysql';
}

function dataset_path(string $name): string
{
    return DATA_DIR . '/' . $name . '.json';
}

function storage_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('当前 PHP 环境未安装 pdo_mysql 扩展，无法使用 MySQL 存储。');
    }

    $host = (string) (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
    $port = (int) (defined('DB_PORT') ? DB_PORT : 3306);
    $name = (string) (defined('DB_NAME') ? DB_NAME : '');
    $user = (string) (defined('DB_USER') ? DB_USER : '');
    $password = (string) (defined('DB_PASSWORD') ? DB_PASSWORD : '');
    $charset = (string) (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');

    if ($name === '' || $user === '') {
        throw new RuntimeException('MySQL 配置不完整，请在 config.php 中设置数据库连接信息。');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function ensure_mysql_storage_schema(): void
{
    $pdo = storage_pdo();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS storage_records (
            dataset VARCHAR(64) NOT NULL,
            record_id INT NOT NULL,
            payload_json LONGTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (dataset, record_id),
            KEY idx_dataset (dataset)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function load_dataset(string $name): array
{
    if (is_mysql_storage()) {
        $statement = storage_pdo()->prepare('SELECT payload_json FROM storage_records WHERE dataset = :dataset ORDER BY record_id ASC');
        $statement->execute(['dataset' => $name]);

        $records = [];
        foreach ($statement->fetchAll() as $row) {
            $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }
        return $records;
    }

    $path = dataset_path($name);
    if (!file_exists($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    if ($contents === false || trim($contents) === '') {
        return [];
    }

    $decoded = json_decode($contents, true);
    return is_array($decoded) ? $decoded : [];
}

function save_dataset(string $name, array $records): void
{
    if (is_mysql_storage()) {
        $pdo = storage_pdo();
        $pdo->beginTransaction();

        try {
            $recordIds = [];
            foreach ($records as $record) {
                $recordId = (int) ($record['id'] ?? 0);
                if ($recordId <= 0) {
                    throw new RuntimeException('MySQL 存储要求每条记录都包含有效的 id。');
                }
                $recordIds[] = $recordId;
            }

            if ($recordIds === []) {
                $deleteAll = $pdo->prepare('DELETE FROM storage_records WHERE dataset = :dataset');
                $deleteAll->execute(['dataset' => $name]);
            } else {
                $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
                $deleteSql = "DELETE FROM storage_records WHERE dataset = ? AND record_id NOT IN ($placeholders)";
                $deleteStatement = $pdo->prepare($deleteSql);
                $deleteStatement->execute(array_merge([$name], $recordIds));
            }

            $upsert = $pdo->prepare(
                'REPLACE INTO storage_records (dataset, record_id, payload_json) VALUES (:dataset, :record_id, :payload_json)'
            );

            foreach ($records as $record) {
                $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    throw new RuntimeException('数据序列化失败。');
                }

                $upsert->execute([
                    'dataset' => $name,
                    'record_id' => (int) $record['id'],
                    'payload_json' => $json,
                ]);
            }

            $pdo->commit();
            return;
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    $path = dataset_path($name);
    $json = json_encode(array_values($records), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('数据序列化失败。');
    }

    file_put_contents($path, $json, LOCK_EX);
}

function next_id(array $records): int
{
    $maxId = 0;
    foreach ($records as $record) {
        $maxId = max($maxId, (int) ($record['id'] ?? 0));
    }
    return $maxId + 1;
}

function find_record(array $records, int $id): ?array
{
    foreach ($records as $record) {
        if ((int) ($record['id'] ?? 0) === $id) {
            return $record;
        }
    }
    return null;
}

function replace_record(array &$records, array $updatedRecord): void
{
    foreach ($records as $index => $record) {
        if ((int) ($record['id'] ?? 0) === (int) ($updatedRecord['id'] ?? 0)) {
            $records[$index] = $updatedRecord;
            return;
        }
    }
    $records[] = $updatedRecord;
}

function initialize_storage(): void
{
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0777, true);
    }

    if (is_mysql_storage()) {
        ensure_mysql_storage_schema();
    } else {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0777, true);
        }

        foreach (DATASETS as $dataset) {
            $path = dataset_path($dataset);
            if (!file_exists($path)) {
                file_put_contents($path, '[]', LOCK_EX);
            }
        }
    }

    seed_users();
    seed_pickup_points();
    seed_rewards();
    seed_announcements();
    migrate_item_records();
    migrate_application_records();
}

function migrate_item_records(): void
{
    $items = load_dataset('items');
    if ($items === []) {
        return;
    }

    $changed = false;
    foreach ($items as &$item) {
        $defaults = [
            'pickup_point_id' => 0,
            'pickup_campus' => '',
            'pickup_zone' => '',
            'pickup_subpoint' => '',
            'pickup_display' => '',
            'pickup_time' => '',
            'target_group' => '',
            'donation_reason' => '',
            'expected_price' => 0,
            'admin_note' => '',
            'item_code' => '',
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
            'door_pickup_campus' => '',
            'door_pickup_building' => '',
            'door_pickup_floor' => '',
            'door_pickup_room' => '',
            'door_pickup_slot' => '',
        ];

        foreach ($defaults as $field => $defaultValue) {
            if (!array_key_exists($field, $item)) {
                $item[$field] = $defaultValue;
                $changed = true;
            }
        }

        if (($item['disposal_type'] ?? '') === 'donation' && (int) ($item['exchange_points'] ?? 0) <= 0) {
            $item['exchange_points'] = calculate_item_exchange_points($item);
            $changed = true;
        }

        if (($item['disposal_type'] ?? '') !== 'donation' && (int) ($item['exchange_points'] ?? 0) !== 0) {
            $item['exchange_points'] = 0;
            $changed = true;
        }

        if (((string) ($item['item_code'] ?? '')) === '' && in_array((string) ($item['status'] ?? ''), ['published', 'dropoff_ready', 'dropoff_delivered', 'pickup_scheduled', 'picked_up', 'matched', 'completed'], true)) {
            $item['item_code'] = generate_item_code($item);
            $changed = true;
        }

        if ((float) ($item['reference_price'] ?? 0) > 0) {
            $calculatedRewardPoints = reward_points_from_reference_price((float) $item['reference_price']);
            if ((int) ($item['reward_points'] ?? 0) !== $calculatedRewardPoints) {
                $item['reward_points'] = $calculatedRewardPoints;
                $changed = true;
            }
        }
    }
    unset($item);

    if ($changed) {
        save_dataset('items', $items);
    }
}

function migrate_application_records(): void
{
    $applications = load_dataset('applications');
    if ($applications === []) {
        return;
    }

    $changed = false;
    foreach ($applications as &$application) {
        $defaults = [
            'reserved_points' => 0,
            'points_refunded' => false,
        ];

        foreach ($defaults as $field => $defaultValue) {
            if (!array_key_exists($field, $application)) {
                $application[$field] = $defaultValue;
                $changed = true;
            }
        }
    }
    unset($application);

    if ($changed) {
        save_dataset('applications', $applications);
    }
}

function seed_users(): void
{
    $users = load_dataset('users');
    if ($users !== []) {
        return;
    }

    $users[] = [
        'id' => 1,
        'role' => 'admin',
        'phone' => '18800000000',
        'password_hash' => password_hash('admin123456', PASSWORD_DEFAULT),
        'nickname' => '平台管理员',
        'campus' => '思明校区',
        'points' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    save_dataset('users', $users);
}

function seed_pickup_points(): void
{
    $points = load_dataset('pickup_points');
    if ($points !== []) {
        return;
    }

    $points = [
        [
            'id' => 1,
            'name' => '思明校区芙蓉区回收点',
            'campus' => '思明校区',
            'location' => '芙蓉区生活服务点旁分类投放位',
            'open_slots' => '每日 09:00-21:00',
            'description' => '覆盖思明校区生活区，适合投放损坏充电器、耳机、数据线等小型电子废物。',
            'active' => true,
        ],
        [
            'id' => 2,
            'name' => '翔安校区国光区回收点',
            'campus' => '翔安校区',
            'location' => '国光区宿舍组团集中回收位',
            'open_slots' => '每日 17:00-21:30',
            'description' => '重点服务国光 1 至 15 号楼宿舍区域，便于统一收集废旧电子产品并集中处理。',
            'active' => true,
        ],
        [
            'id' => 3,
            'name' => '翔安校区凌云区回收点',
            'campus' => '翔安校区',
            'location' => '凌云区宿舍楼下分类回收区域',
            'open_slots' => '每日 17:00-21:30',
            'description' => '重点服务凌云 1 至 7 号楼宿舍区域，适合集中投放损坏电子设备和老旧配件。',
            'active' => true,
        ],
    ];

    save_dataset('pickup_points', $points);
}

function seed_announcements(): void
{
    $announcements = load_dataset('announcements');
    if ($announcements !== []) {
        return;
    }

    $announcements[] = [
        'id' => 1,
        'title' => '关于物品兑换',
        'content' => '思明校区统一在每周一或者周五的 9:00-17:00 到学生活动中心 203 领取，翔安校区统一在每周一或者周五的 9:00-17:00 到学生活动中心 104 领取。',
        'active' => true,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    save_dataset('announcements', $announcements);
}

function seed_rewards(): void
{
    $rewards = load_dataset('rewards');
    if ($rewards !== []) {
        return;
    }

    $rewards = [
        [
            'id' => 1,
            'name' => '环保主题徽章',
            'points_cost' => 30,
            'stock' => 40,
            'description' => '比赛展示和环保宣传都很合适的小徽章。',
            'image' => '',
            'active' => true,
        ],
        [
            'id' => 2,
            'name' => '数据线收纳盒',
            'points_cost' => 60,
            'stock' => 25,
            'description' => '帮助整理桌面与宿舍常用电子配件。',
            'image' => '',
            'active' => true,
        ],
        [
            'id' => 3,
            'name' => '便携清洁套装',
            'points_cost' => 80,
            'stock' => 18,
            'description' => '适合清洁耳机、键盘、手机等常用电子产品。',
            'image' => '',
            'active' => true,
        ],
    ];

    save_dataset('rewards', $rewards);
}

function add_message(int $userId, string $title, string $content, string $link = ''): void
{
    $messages = load_dataset('messages');
    $messages[] = [
        'id' => next_id($messages),
        'user_id' => $userId,
        'title' => $title,
        'content' => $content,
        'link' => $link,
        'is_read' => false,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    save_dataset('messages', $messages);
}

function adjust_user_points(int $userId, int $delta, string $title = '', string $content = '', string $link = ''): void
{
    $users = load_dataset('users');
    foreach ($users as &$user) {
        if ((int) ($user['id'] ?? 0) !== $userId) {
            continue;
        }

        $user['points'] = max(0, (int) ($user['points'] ?? 0) + $delta);
        break;
    }
    unset($user);

    save_dataset('users', $users);

    if ($title !== '' || $content !== '') {
        add_message($userId, $title, $content, $link);
    }
}

function mark_message_as_read(int $messageId, int $userId): void
{
    $messages = load_dataset('messages');
    foreach ($messages as &$message) {
        if ((int) ($message['id'] ?? 0) === $messageId && (int) ($message['user_id'] ?? 0) === $userId) {
            $message['is_read'] = true;
        }
    }
    unset($message);
    save_dataset('messages', $messages);
}

function unread_message_count(int $userId): int
{
    $count = 0;
    foreach (load_dataset('messages') as $message) {
        if ((int) ($message['user_id'] ?? 0) === $userId && empty($message['is_read'])) {
            $count++;
        }
    }
    return $count;
}
