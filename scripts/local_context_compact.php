<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dataDir = $root . DIRECTORY_SEPARATOR . 'data';
$outputPath = $root . DIRECTORY_SEPARATOR . 'LOCAL_CONTEXT_SUMMARY.md';

function read_json_array(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function find_admin_login_page(string $bootstrapPath): string
{
    if (!is_file($bootstrapPath)) {
        return 'xmu-greenloop-admin-6f9c2d71';
    }

    $content = file_get_contents($bootstrapPath);
    if ($content === false) {
        return 'xmu-greenloop-admin-6f9c2d71';
    }

    if (preg_match("/define\\('ADMIN_LOGIN_PAGE',\\s*'([^']+)'\\)/", $content, $matches) === 1) {
        return $matches[1];
    }

    return 'xmu-greenloop-admin-6f9c2d71';
}

$users = read_json_array($dataDir . DIRECTORY_SEPARATOR . 'users.json');
$items = read_json_array($dataDir . DIRECTORY_SEPARATOR . 'items.json');
$applications = read_json_array($dataDir . DIRECTORY_SEPARATOR . 'applications.json');
$redemptions = read_json_array($dataDir . DIRECTORY_SEPARATOR . 'redemptions.json');
$announcements = read_json_array($dataDir . DIRECTORY_SEPARATOR . 'announcements.json');

$adminLoginPage = find_admin_login_page($root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'bootstrap.php');

$pendingReview = 0;
$dropoffReady = 0;
$pickupScheduled = 0;
$completed = 0;

foreach ($items as $item) {
    $status = (string) ($item['status'] ?? '');
    if ($status === 'pending_review') {
        $pendingReview++;
    } elseif ($status === 'dropoff_ready') {
        $dropoffReady++;
    } elseif ($status === 'pickup_scheduled') {
        $pickupScheduled++;
    } elseif ($status === 'completed') {
        $completed++;
    }
}

$activeAnnouncement = '';
foreach ($announcements as $announcement) {
    if (!empty($announcement['active'])) {
        $activeAnnouncement = (string) ($announcement['title'] ?? '');
        break;
    }
}

$markdown = [];
$markdown[] = '# Local Project Context Summary';
$markdown[] = '';
$markdown[] = 'Generated at: ' . date('Y-m-d H:i:s');
$markdown[] = '';
$markdown[] = '## Snapshot';
$markdown[] = '';
$markdown[] = '- Users: ' . count($users);
$markdown[] = '- Items: ' . count($items);
$markdown[] = '- Applications: ' . count($applications);
$markdown[] = '- Redemptions: ' . count($redemptions);
$markdown[] = '- Pending review: ' . $pendingReview;
$markdown[] = '- Dropoff ready: ' . $dropoffReady;
$markdown[] = '- Pickup scheduled: ' . $pickupScheduled;
$markdown[] = '- Completed: ' . $completed;
$markdown[] = '- Active announcement: ' . ($activeAnnouncement !== '' ? $activeAnnouncement : '(none)');
$markdown[] = '';
$markdown[] = '## Runtime URLs';
$markdown[] = '';
$markdown[] = '- Home: `/index.php`';
$markdown[] = '- Submit item: `/index.php?page=submit`';
$markdown[] = '- Points: `/index.php?page=points`';
$markdown[] = '- User auth: `/index.php?page=login`';
$markdown[] = '- Hidden admin login: `/index.php?page=' . $adminLoginPage . '`';
$markdown[] = '- Admin console: `/index.php?page=admin` (after admin login)';
$markdown[] = '';
$markdown[] = '## Current Product Rules';
$markdown[] = '';
$markdown[] = '- Disposal types: fixed pickup point + door pickup.';
$markdown[] = '- Account key: phone number.';
$markdown[] = '- Points: determined by final reference price.';
$markdown[] = '- Admin entry is hidden behind `ADMIN_LOGIN_PAGE`.';
$markdown[] = '';
$markdown[] = '## Quick Continuation Checklist';
$markdown[] = '';
$markdown[] = '- Verify homepage announcement content is up to date.';
$markdown[] = '- Verify register/login flow and one-phone-input UX.';
$markdown[] = '- Verify submit page only exposes recycle + door pickup options.';
$markdown[] = '- Verify admin can edit announcements and review flows.';
$markdown[] = '';
$markdown[] = '## Note about compact errors';
$markdown[] = '';
$markdown[] = '- If remote compact fails, keep using this file as local continuation context.';
$markdown[] = '- Regenerate by running: `php scripts/local_context_compact.php`';

$result = file_put_contents($outputPath, implode(PHP_EOL, $markdown) . PHP_EOL);
if ($result === false) {
    fwrite(STDERR, "Failed to write $outputPath" . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Generated: $outputPath" . PHP_EOL);
