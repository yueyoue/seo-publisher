#!/usr/bin/env php
<?php
/**
 * SEO Publisher - 定时发布任务
 * 
 * 用法：
 *   php /path/to/cron/publish.php
 * 
 * 建议通过系统cron每分钟执行一次：
 *   * * * * * php /path/to/cron/publish.php >> /path/to/cron/publish.log 2>&1
 * 
 * 或者使用crontab -e添加上述规则
 */

// 关闭错误输出到stdout（避免干扰日志）
error_reporting(E_ERROR | E_PARSE);

// 加载项目配置
require_once dirname(__DIR__) . '/config/init.php';
require_once ROOT_PATH . '/includes/Functions.php';

// 支持CLI和Web两种触发方式
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    // Web访问：支持两种认证方式
    // 1. URL参数 ?key=你的密钥 （宝塔计划任务推荐用这种方式）
    // 2. Session登录认证
    $cronKey = $_GET['key'] ?? '';
    $validKey = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : 'seo-publisher-cron-2024';
    
    if ($cronKey === $validKey) {
        // 密钥认证通过，无需session
        // 关闭session避免锁
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    } else {
        // 尝试session认证
        session_start();
        if (empty($_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized. 请在URL中添加 ?key=密钥 参数，或先登录系统。']);
            exit(1);
        }
        session_write_close();
    }
    header('Content-Type: application/json; charset=utf-8');
}

$db = Database::getInstance();
$logFile = ROOT_PATH . '/cron/publish.log';

// 确保articles表有publish_at字段
try {
    $db->fetchOne("SELECT publish_at FROM articles LIMIT 1");
} catch (Exception $e) {
    try { $db->query("ALTER TABLE articles ADD COLUMN publish_at DATETIME DEFAULT NULL AFTER published_at"); } catch (Exception $e2) {}
}

// 确保articles.status枚举包含scheduled状态
try {
    $col = $db->fetchOne("SHOW COLUMNS FROM articles WHERE Field='status'");
    if ($col && strpos($col['Type'], 'scheduled') === false) {
        $db->query("ALTER TABLE articles MODIFY COLUMN status ENUM('pending','generating','generated','scheduled','publishing','published','failed') NOT NULL DEFAULT 'pending'");
    }
} catch (Exception $e) {}

/**
 * 记录日志
 */
function cronLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if (php_sapi_name() === 'cli') {
        echo $line;
    }
}

/**
 * 发布单篇文章
 */
function cronPublishOne($db, $article, $userId) {
    $articleSiteId = $article['publish_site_id'] ?? 0;
    $articleCategoryId = $article['publish_category_id'] ?? 0;

    if (!$articleSiteId) {
        $globalConfig = $db->fetchOne("SELECT * FROM global_config WHERE user_id=?", [$userId]);
        $articleSiteId = $globalConfig['site_id'] ?? 0;
        $articleCategoryId = $articleCategoryId ?: ($globalConfig['category_id'] ?? 0);
    }

    if (!$articleSiteId) {
        $db->update('articles', [
            'status' => 'failed',
            'error_message' => '未设置发布目标站点',
        ], 'id=?', [$article['id']]);
        return ['success' => false, 'message' => '未设置发布目标'];
    }

    $site = $db->fetchOne("SELECT * FROM sites WHERE id=?", [$articleSiteId]);
    if (!$site) {
        $db->update('articles', [
            'status' => 'failed',
            'error_message' => '站点不存在',
        ], 'id=?', [$article['id']]);
        return ['success' => false, 'message' => '站点不存在'];
    }

    $db->update('articles', ['status' => 'publishing'], 'id=?', [$article['id']]);

    $publisher = new WordPressPublisher($site['domain'], $site['username'], $site['password']);
    $result = $publisher->publishPost(
        $article['title'],
        $article['content'],
        $articleCategoryId ? intval($articleCategoryId) : 0
    );

    if ($result['success']) {
        $db->update('articles', [
            'status' => 'published',
            'publish_site' => $site['domain'],
            'publish_post_id' => $result['post_id'],
            'published_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$article['id']]);

        $db->update('sites', ['last_publish' => date('Y-m-d H:i:s')], 'id=?', [$site['id']]);

        $db->insert('publish_logs', [
            'site_id' => $site['id'],
            'article_id' => $article['id'],
            'user_id' => $userId,
            'action' => '定时发布',
            'status' => 'success',
            'message' => '文章ID: ' . $result['post_id'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['success' => true, 'post_id' => $result['post_id']];
    } else {
        $errorMsg = $result['message'] ?? '发布失败';
        $db->update('articles', [
            'status' => 'failed',
            'error_message' => $errorMsg,
        ], 'id=?', [$article['id']]);

        $db->insert('publish_logs', [
            'site_id' => $site['id'],
            'article_id' => $article['id'],
            'user_id' => $userId,
            'action' => '定时发布',
            'status' => 'failed',
            'message' => $errorMsg,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['success' => false, 'message' => $errorMsg];
    }
}

// ========== 主逻辑 ==========
cronLog("=== 开始执行定时发布任务 ===");

try {
    // 查找所有到期需要发布的文章
    $articles = $db->fetchAll(
        "SELECT * FROM articles WHERE status='scheduled' AND publish_at <= NOW() ORDER BY publish_at ASC LIMIT 50"
    );

    if (empty($articles)) {
        cronLog("没有到期需要发布的文章");
        exit(0);
    }

    cronLog("找到 " . count($articles) . " 篇到期文章");

    $successCount = 0;
    $failCount = 0;

    // 按用户和站点分组检查每日限制
    $dailyLimits = [];

    foreach ($articles as $article) {
        $userId = $article['user_id'];
        $siteId = $article['publish_site_id'] ?? 0;

        if (!$siteId) {
            $globalConfig = $db->fetchOne("SELECT * FROM global_config WHERE user_id=?", [$userId]);
            $siteId = $globalConfig['site_id'] ?? 0;
        }

        // 检查每日限制
        $limitKey = $userId . '_' . $siteId;
        if (!isset($dailyLimits[$limitKey])) {
            $dailyLimits[$limitKey] = true;
            try {
                $settings = $db->fetchOne("SELECT daily_max FROM site_publish_settings WHERE site_id=? AND user_id=?", [$siteId, $userId]);
                $dailyMax = intval($settings['daily_max'] ?? 0);
                if ($dailyMax > 0) {
                    $todayPublished = $db->count('articles',
                        "user_id=? AND publish_site_id=? AND status='published' AND DATE(published_at)=CURDATE()",
                        [$userId, $siteId]);
                    if ($todayPublished >= $dailyMax) {
                        // 超过每日限制，推迟到明天
                        $tomorrow = date('Y-m-d 08:00:00', strtotime('+1 day'));
                        $db->update('articles', [
                            'publish_at' => $tomorrow,
                        ], 'id=?', [$article['id']]);
                        cronLog("文章ID:{$article['id']} 超过每日限制({$todayPublished}/{$dailyMax})，推迟到 {$tomorrow}");
                        continue;
                    }
                }
            } catch (Exception $e) {}
        }

        $result = cronPublishOne($db, $article, $userId);
        if ($result['success']) {
            $successCount++;
            cronLog("✓ 文章ID:{$article['id']} [{$article['keyword']}] 发布成功 -> POST #{$result['post_id']}");
        } else {
            $failCount++;
            cronLog("✗ 文章ID:{$article['id']} [{$article['keyword']}] 发布失败: {$result['message']}");
        }

        // 发布间隔（避免API限流）
        usleep(500000); // 0.5秒
    }

    cronLog("=== 任务完成: 成功{$successCount}篇, 失败{$failCount}篇 ===");

// Web模式返回JSON结果
if (!$isCli) {
    echo json_encode([
        'success' => true,
        'published' => $successCount,
        'failed' => $failCount,
        'total' => count($articles),
        'time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
}

} catch (Exception $e) {
    cronLog("ERROR: " . $e->getMessage());
    if (!$isCli) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit(1);
}
