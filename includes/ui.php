<?php
declare(strict_types=1);

function render_flash_messages(array $flashes, string $wrapperClass = ''): void
{
    if ($flashes === []) {
        return;
    }

    $classAttr = trim('flash-stack ' . $wrapperClass);
    ?>
    <div class="<?= e($classAttr) ?>">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash flash-<?= e($flash['type'] ?? 'info') ?>">
                <?= e($flash['message'] ?? '') ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
}

function render_header(string $title, ?array $user, array $flashes = []): void
{
    $unreadCount = $user ? unread_message_count((int) $user['id']) : 0;
    $pageTitle = $title . ' | ' . APP_NAME;
    $styleVersion = (string) (@filemtime(__DIR__ . '/../assets/css/style.css') ?: '1');
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= e($styleVersion) ?>">
</head>
<body>
<div class="site-shell">
    <header class="site-header">
        <div class="brand-block">
            <a class="brand-mark" href="<?= e(app_url()) ?>">绿循校园</a>
            <p class="brand-subtitle">校园电子废物定点回收与上门回收平台</p>
        </div>
        <nav class="main-nav" aria-label="主导航">
            <a href="<?= e(app_url()) ?>">首页</a>
            <a href="<?= e(app_url(['page' => 'submit'])) ?>">提交物品</a>
            <a href="<?= e(app_url(['page' => 'points'])) ?>">积分兑换</a>
            <?php if ($user !== null): ?>
                <a href="<?= e(app_url(['page' => 'dashboard'])) ?>">个人中心<?= $unreadCount > 0 ? '（' . $unreadCount . '）' : '' ?></a>
                <a href="<?= e(app_url(['page' => 'logout'])) ?>">退出登录</a>
            <?php else: ?>
                <a href="<?= e(app_url(['page' => 'login'])) ?>">登录 / 注册</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="main-content">
        <?php render_flash_messages($flashes); ?>
<?php
}

function render_footer(): void
{
    $pickupZoneJson = json_encode(pickup_zone_catalog(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pickupSubpointJson = json_encode(pickup_subpoint_catalog(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>
    </main>
</div>

<script>
window.pickupZoneCatalog = <?= $pickupZoneJson ?: '{}' ?>;
window.pickupSubpointCatalog = <?= $pickupSubpointJson ?: '{}' ?>;

document.querySelectorAll('[data-disposal-select]').forEach(function (select) {
    function syncDisposalFields() {
        var value = select.value;
        document.querySelectorAll('[data-disposal-panel]').forEach(function (panel) {
            panel.style.display = panel.dataset.disposalPanel === value ? 'grid' : 'none';
        });
    }

    select.addEventListener('change', syncDisposalFields);
    syncDisposalFields();
});

document.querySelectorAll('[data-auth-tab-trigger]').forEach(function (button) {
    button.addEventListener('click', function () {
        var target = button.getAttribute('data-auth-tab-trigger');
        document.querySelectorAll('[data-auth-tab-trigger]').forEach(function (item) {
            item.classList.toggle('is-active', item === button);
        });
        document.querySelectorAll('[data-auth-tab-panel]').forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-auth-tab-panel') === target);
        });
    });
});

document.querySelectorAll('[data-register-code-form]').forEach(function (form) {
    form.addEventListener('submit', function () {
        var phoneInput = document.getElementById('register-phone');
        var nicknameInput = document.getElementById('register-nickname');
        var campusInput = document.getElementById('register-campus');
        var phoneProxy = document.getElementById('register-code-phone-proxy');
        var nicknameProxy = document.getElementById('register-code-nickname-proxy');
        var campusProxy = document.getElementById('register-code-campus-proxy');

        if (phoneInput && phoneProxy) {
            phoneProxy.value = phoneInput.value;
        }
        if (nicknameInput && nicknameProxy) {
            nicknameProxy.value = nicknameInput.value;
        }
        if (campusInput && campusProxy) {
            campusProxy.value = campusInput.value;
        }
    });
});

document.querySelectorAll('[data-image-input]').forEach(function (input) {
    input.addEventListener('change', function () {
        var targetId = input.getAttribute('data-image-input');
        var feedbackId = input.getAttribute('data-image-feedback');
        var preview = document.getElementById(targetId);
        var feedback = feedbackId ? document.getElementById(feedbackId) : null;

        function setFeedback(message, isError) {
            if (!feedback) {
                return;
            }

            feedback.textContent = message;
            feedback.classList.toggle('is-error', Boolean(isError));
        }

        function formatSize(bytes) {
            if (!bytes) {
                return '0 MB';
            }

            return (bytes / 1024 / 1024).toFixed(2) + ' MB';
        }

        if (!preview || !input.files || !input.files[0]) {
            setFeedback('', false);
            return;
        }

        var file = input.files[0];
        var fileName = file.name || '';
        var extension = fileName.split('.').pop().toLowerCase();
        var maxSize = 5 * 1024 * 1024;

        if (file.size > maxSize) {
            input.value = '';
            preview.removeAttribute('src');
            preview.style.display = 'none';
            setFeedback('当前图片约 ' + formatSize(file.size) + '，请压缩到 5MB 以内后重新上传。', true);
            return;
        }

        if (['heic', 'heif'].indexOf(extension) !== -1 || ['image/heic', 'image/heif'].indexOf(file.type) !== -1) {
            input.value = '';
            preview.removeAttribute('src');
            preview.style.display = 'none';
            setFeedback('暂不支持 HEIC/HEIF 图片，请在相册中转为 JPG 后重新上传。', true);
            return;
        }

        preview.src = URL.createObjectURL(file);
        preview.style.display = 'block';
        setFeedback('已选择：' + (fileName || '图片') + '（' + formatSize(file.size) + '）', false);
    });
});

document.querySelectorAll('[data-pickup-campus]').forEach(function (campusSelect) {
    var scope = campusSelect.closest('form') || document;
    var zoneSelect = scope.querySelector('[data-pickup-zone]');
    var subpointSelect = scope.querySelector('[data-pickup-subpoint]');
    var subpointWrap = scope.querySelector('[data-pickup-subpoint-wrap]');

    if (!zoneSelect || !subpointSelect || !subpointWrap) {
        return;
    }

    function setOptions(select, items, placeholder, selectedValue) {
        select.innerHTML = '';

        var placeholderOption = document.createElement('option');
        placeholderOption.value = '';
        placeholderOption.textContent = placeholder;
        placeholderOption.disabled = true;
        placeholderOption.hidden = true;
        placeholderOption.selected = !selectedValue;
        select.appendChild(placeholderOption);

        items.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item;
            option.textContent = item;
            if (item === selectedValue) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }

    function syncZones() {
        var campus = campusSelect.value;
        var zones = window.pickupZoneCatalog[campus] || [];
        var selectedZone = zones.indexOf(zoneSelect.dataset.selected || zoneSelect.value) !== -1
            ? (zoneSelect.dataset.selected || zoneSelect.value)
            : '';

        setOptions(zoneSelect, zones, '请选择园区', selectedZone);
        zoneSelect.disabled = zones.length === 0;
        zoneSelect.dataset.selected = '';
        syncSubpoints();
    }

    function syncSubpoints() {
        var campus = campusSelect.value;
        var zone = zoneSelect.value;
        var key = campus + '|' + zone;
        var subpoints = window.pickupSubpointCatalog[key] || [];
        var selectedSubpoint = subpoints.indexOf(subpointSelect.dataset.selected || subpointSelect.value) !== -1
            ? (subpointSelect.dataset.selected || subpointSelect.value)
            : '';

        setOptions(subpointSelect, subpoints, '请选择具体点位', selectedSubpoint);
        subpointSelect.disabled = subpoints.length === 0;
        subpointSelect.dataset.selected = '';
        subpointWrap.style.display = subpoints.length > 0 ? 'grid' : 'none';
    }

    campusSelect.addEventListener('change', syncZones);
    zoneSelect.addEventListener('change', syncSubpoints);
    syncZones();
});
</script>
</body>
</html>
<?php
}
