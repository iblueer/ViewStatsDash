<?php
/**
 * 独立打点脚本（推荐用容器内 CLI 执行）：
 *   php /app/usr/plugins/ViewStatsDash/cron.php
 *
 * 功能：
 * - 计算当日总阅读量（仅 type='post'）
 * - 将总量写入/更新到 {prefix}viewstats_daily（若表不存在会自动创建）
 *
 * 依赖：
 * - 通过 include /app/config.inc.php 初始化 Typecho 与 DB
 */

declare(strict_types=1);

// 仅允许本机/容器回环通过 HTTP 访问（CLI 不受限）
$remote = $_SERVER['REMOTE_ADDR'] ?? null;
if ($remote !== null && !in_array($remote, ['127.0.0.1','::1'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'forbidden: remote must be 127.0.0.1'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) 引入 Typecho 配置（这一步会完成 Common::init() 和 Db::set(...)）
$CONFIG_FILES = ['/app/config.inc.php', '/app/var/config.inc.php'];
$configPath = null;
foreach ($CONFIG_FILES as $p) { if (is_file($p)) { $configPath = $p; break; } }
if (!$configPath) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'config.inc.php not found','tried'=>$CONFIG_FILES], JSON_UNESCAPED_UNICODE);
    exit;
}
@include $configPath;

// 2) 取 Typecho 的 DB 句柄
try {
    // 兼容新命名空间类 \Typecho\Db 与旧别名 Typecho_Db
    if (class_exists('\\Typecho\\Db')) {
        $db = \Typecho\Db::get();
    } elseif (class_exists('Typecho_Db')) {
        $db = \Typecho_Db::get();
    } else {
        throw new \RuntimeException('Typecho Db class not found after include config.inc.php');
    }
} catch (\Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'get db failed','detail'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $prefix   = $db->getPrefix();
    $tblDaily = $prefix . 'viewstats_daily';
    $tblPosts = $prefix . 'contents';

    // 3) 建表（若不存在）
    $sqlCreate = "CREATE TABLE IF NOT EXISTS `{$tblDaily}` (
        `day` DATE NOT NULL PRIMARY KEY,
        `total_views` INT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $db->query($sqlCreate, \Typecho\Db::WRITE);

    // 4) 检查 posts 表是否有 views 列
    $cols = $db->fetchAll($db->query("SHOW COLUMNS FROM `{$tblPosts}`", \Typecho\Db::READ));
    $hasViews = false;
    foreach ($cols as $c) { if (isset($c['Field']) && $c['Field'] === 'views') { $hasViews = true; break; } }
    if (!$hasViews) {
        echo json_encode(['ok'=>false,'error'=>"column `views` not found in {$tblPosts}"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 5) 汇总总阅读量（仅 post）
    $sumSelect = $db->select('SUM(views) AS total')->from($tblPosts)->where('type = ?', 'post');
    $sumRow = $db->fetchRow($sumSelect);
    $total = (int)($sumRow['total'] ?? 0);

    // 6) 当天 upsert
    $today = date('Y-m-d');
    $exists = $db->fetchRow($db->select('day')->from($tblDaily)->where('day = ?', $today));
    if (!$exists) {
        $db->query($db->insert($tblDaily)->rows(['day'=>$today, 'total_views'=>$total])); // WRITE
        $status = 'inserted';
    } else {
        $db->query($db->update($tblDaily)->rows(['total_views'=>$total])->where('day = ?', $today)); // WRITE
        $status = 'updated';
    }

    echo json_encode(['ok'=>true, 'status'=>$status, 'day'=>$today, 'total_views'=>$total], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
