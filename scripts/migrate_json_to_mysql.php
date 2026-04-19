<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_mysql_storage()) {
    fwrite(STDERR, "当前 STORAGE_DRIVER 不是 mysql，请先在 config.php 中切换到 mysql。\n");
    exit(1);
}

$sourceDir = $argv[1] ?? (__DIR__ . '/../data');
$sourceDir = rtrim($sourceDir, '/\\');

initialize_storage();

foreach (DATASETS as $dataset) {
    $path = $sourceDir . DIRECTORY_SEPARATOR . $dataset . '.json';
    if (!file_exists($path)) {
        echo "[skip] {$dataset}: 未找到 {$path}\n";
        continue;
    }

    $contents = file_get_contents($path);
    $decoded = json_decode((string) $contents, true);
    if (!is_array($decoded)) {
        echo "[skip] {$dataset}: JSON 解析失败\n";
        continue;
    }

    save_dataset($dataset, $decoded);
    echo "[ok] {$dataset}: 已导入 " . count($decoded) . " 条记录\n";
}

echo "迁移完成。\n";
