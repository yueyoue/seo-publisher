#!/usr/bin/env php
<?php
/**
 * SEO Publisher - 定时生成文章任务
 * 
 * 用法：
 *   php /path/to/cron/generate.php
 * 
 * 建议通过系统cron每2分钟执行一次：
 *   */2 * * * * php /path/to/cron/generate.php >> /path/to/cron/generate.log 2>&1
 * 
 * 此脚本自动处理状态为 generating 的文章，配合前端"开始生成"使用。
 * 前端点击"开始生成"后，文章标记为 generating，此脚本在后台逐篇处理。
 */

error_reporting(E_ERROR | E_PARSE);

require_once dirname(__DIR__) . '/config/init.php';
require_once ROOT_PATH . '/includes/Functions.php';

// 支持CLI和Web两种触发方式
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    $cronKey = $_GET['key'] ?? '';
    $validKey = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : 'seo-publisher-cron-2024';
    
    if ($cronKey === $validKey) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    } else {
        session_start();
        if (empty($_SESSION['user_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            exit(1);
        }
        session_write_close();
    }
    header('Content-Type: application/json; charset=utf-8');
}

$logFile = ROOT_PATH . '/cron/generate.log';

/**
 * 记录日志
 */
function genLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $line = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if (php_sapi_name() === 'cli') {
        echo $line;
    }
}

$db = Database::getInstance();

// 确保字段存在
try {
    $db->fetchOne("SELECT publish_at FROM articles LIMIT 1");
} catch (Exception $e) {
    try { $db->query("ALTER TABLE articles ADD COLUMN publish_at DATETIME DEFAULT NULL AFTER published_at"); } catch (Exception $e2) {}
}

genLog("=== 开始执行定时生成任务 ===");

try {
    // 查找所有正在生成的文章（按用户分组处理）
    $articles = $db->fetchAll(
        "SELECT * FROM articles WHERE status='generating' ORDER BY user_id ASC, id ASC LIMIT 50"
    );

    if (empty($articles)) {
        genLog("没有正在生成的文章");
        exit(0);
    }

    genLog("找到 " . count($articles) . " 篇待生成文章");

    $successCount = 0;
    $failCount = 0;

    foreach ($articles as $article) {
        $userId = $article['user_id'];
        $articleId = $article['id'];
        $keyword = $article['keyword'];

        // 获取配置 - 优先使用文章绑定的模板
        $config = null;
        if (!empty($article['template_id'])) {
            try {
                $config = $db->fetchOne("SELECT * FROM article_templates WHERE id=? AND user_id=?", [$article['template_id'], $userId]);
            } catch (Exception $e) {}
        }

        // 从挖词任务继承模板和发布目标
        if (!$config || empty($article['publish_site_id'])) {
            try {
                $kwTask = $db->fetchOne(
                    "SELECT kt.bound_site_id, kt.bound_category_id, kt.template_id FROM keyword_results kr LEFT JOIN keyword_tasks kt ON kr.task_id = kt.id WHERE kr.user_id=? AND kr.keyword=? AND kt.id IS NOT NULL LIMIT 1",
                    [$userId, $keyword]
                );
                if ($kwTask) {
                    if (!$config && !empty($kwTask['template_id'])) {
                        $config = $db->fetchOne("SELECT * FROM article_templates WHERE id=? AND user_id=?", [$kwTask['template_id'], $userId]);
                    }
                    if (empty($article['publish_site_id']) && !empty($kwTask['bound_site_id'])) {
                        $db->update('articles', [
                            'publish_site_id' => $kwTask['bound_site_id'],
                            'publish_category_id' => $kwTask['bound_category_id'],
                        ], 'id=?', [$articleId]);
                    }
                }
            } catch (Exception $e) {}
        }

        if (!$config) {
            $config = $db->fetchOne("SELECT * FROM global_config WHERE user_id=?", [$userId]);
        }

        if (!$config || empty($config['api_key'])) {
            $db->update('articles', [
                'status' => 'failed',
                'error_message' => '未配置API Key',
            ], 'id=?', [$articleId]);
            $failCount++;
            genLog("✗ 文章ID:{$articleId} [{$keyword}] 失败: 未配置API Key");
            continue;
        }

        // 生成文章
        $generator = new AIGenerator();
        $result = $generator->generateArticle($keyword, $config);

        if ($result['success']) {
            $wordCount = mb_strlen(strip_tags($result['content']));
            $db->update('articles', [
                'title' => $result['title'],
                'content' => $result['content'],
                'content_type' => ($config['export_content_type'] ?? 'html') === 'html' ? 'html' : 'text',
                'word_count' => $wordCount,
                'status' => 'generated',
            ], 'id=?', [$articleId]);
            $successCount++;
            genLog("✓ 文章ID:{$articleId} [{$keyword}] 生成成功 ({$wordCount}字)");
        } else {
            $errorMsg = $result['message'] ?? '生成失败';
            $db->update('articles', [
                'status' => 'failed',
                'error_message' => $errorMsg,
            ], 'id=?', [$articleId]);
            $failCount++;
            genLog("✗ 文章ID:{$articleId} [{$keyword}] 生成失败: {$errorMsg}");
        }

        // 更新进度文件（供前端轮询）
        $progressFile = ROOT_PATH . "/uploads/progress_{$userId}.json";
        $totalGenerated = $db->count('articles', 'user_id=? AND status="generated"', [$userId]);
        $totalFailed = $db->count('articles', 'user_id=? AND status="failed"', [$userId]);
        $totalGenerating = $db->count('articles', 'user_id=? AND status="generating"', [$userId]);
        $total = $totalGenerated + $totalFailed + $totalGenerating;

        file_put_contents($progressFile, json_encode([
            'total' => $total,
            'done' => $totalGenerated + $totalFailed,
            'current' => $keyword,
            'current_error' => $result['success'] ? '' : ($result['message'] ?? ''),
            'started_at' => date('Y-m-d H:i:s'),
        ]));

        // 间隔避免API限流
        usleep(500000); // 0.5秒
    }

    genLog("=== 任务完成: 成功{$successCount}篇, 失败{$failCount}篇 ===");

    if (!$isCli) {
        echo json_encode([
            'success' => true,
            'generated' => $successCount,
            'failed' => $failCount,
            'total' => count($articles),
            'time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    genLog("ERROR: " . $e->getMessage());
    if (!$isCli) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit(1);
}
