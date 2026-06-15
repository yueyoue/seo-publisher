<?php
/**
 * 文章API
 */
ob_start();
require_once __DIR__ . '/../config/init.php';
require_once ROOT_PATH . '/includes/Functions.php';
Auth::check();
if (ob_get_level()) ob_end_clean();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// 确保articles表有publish_at字段（定时发布用）
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
 * 发布单篇文章到WordPress
 * @return bool 是否成功
 */
function publishOneArticle($db, $article, $userId) {
    // 确定发布目标
    $articleSiteId = $article['publish_site_id'] ?? 0;
    $articleCategoryId = $article['publish_category_id'] ?? 0;

    if (!$articleSiteId) {
        // 回退到全局配置
        $globalConfig = $db->fetchOne("SELECT * FROM global_config WHERE user_id=?", [$userId]);
        $articleSiteId = $globalConfig['site_id'] ?? 0;
        $articleCategoryId = $articleCategoryId ?: ($globalConfig['category_id'] ?? 0);
    }

    if (!$articleSiteId) {
        $db->update('articles', [
            'status' => 'failed',
            'error_message' => '未设置发布目标站点',
        ], 'id=?', [$article['id']]);
        return false;
    }

    $site = $db->fetchOne("SELECT * FROM sites WHERE id=?", [$articleSiteId]);
    if (!$site) {
        $db->update('articles', [
            'status' => 'failed',
            'error_message' => '站点不存在(ID:' . $articleSiteId . ')',
        ], 'id=?', [$article['id']]);
        return false;
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
            'action' => '发布文章',
            'status' => 'success',
            'message' => '文章ID: ' . $result['post_id'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return true;
    } else {
        $db->update('articles', [
            'status' => 'failed',
            'error_message' => $result['message'] ?? '发布失败',
        ], 'id=?', [$article['id']]);

        $db->insert('publish_logs', [
            'site_id' => $site['id'],
            'article_id' => $article['id'],
            'user_id' => $userId,
            'action' => '发布文章',
            'status' => 'failed',
            'message' => $result['message'] ?? '发布失败',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return false;
    }
}

switch ($action) {
    case 'test_connection':
        // 清除之前可能存在的任何输出（PHP警告等）
        if (ob_get_level()) ob_end_clean();
        
        $input = json_decode(file_get_contents('php://input'), true);
        $model = $input['model'] ?? 'deepseek';
        $apiKey = $input['api_key'] ?? '';
        $customEndpoint = $input['endpoint'] ?? '';

        if (empty($apiKey)) {
            jsonResponse(['success' => false, 'message' => '请输入API Key']);
        }

        try {
            $generator = new AIGenerator();
            $result = $generator->testConnection($model, $apiKey, $customEndpoint);
            jsonResponse($result);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '错误: ' . $e->getMessage()]);
        }
        break;

    case 'preview':
        $id = intval($_GET['id'] ?? 0);
        $article = $db->fetchOne("SELECT * FROM articles WHERE id=? AND user_id=?", [$id, $userId]);
        if ($article) {
            jsonResponse(['success' => true, 'title' => $article['title'], 'content' => $article['content']]);
        }
        jsonResponse(['success' => false, 'message' => '文章不存在']);
        break;

    case 'delete':
        $id = intval($_GET['id'] ?? 0);
        $db->delete('articles', 'id=? AND user_id=?', [$id, $userId]);
        jsonResponse(['success' => true]);
        break;

    case 'batch_generate':
        // 允许长时间运行，浏览器断开也不中断
        ignore_user_abort(true);
        set_time_limit(0);

        // 检查是否有正在生成的文章（恢复场景）
        $existingGenerating = $db->fetchAll(
            "SELECT * FROM articles WHERE user_id=? AND status='generating' ORDER BY id ASC",
            [$userId]
        );

        $pending = [];
        $isResume = false;

        if (!empty($existingGenerating)) {
            // 检查是否卡住（进度文件超过15分钟没更新则认为卡住）
            $progressFile = UPLOAD_PATH . "progress_{$userId}.json";
            $isStuck = false;
            if (file_exists($progressFile)) {
                $progress = json_decode(file_get_contents($progressFile), true);
                $startedAt = $progress['started_at'] ?? '';
                if ($startedAt && (time() - strtotime($startedAt)) > 900) {
                    $isStuck = true;
                }
            } else {
                // 没有进度文件但有generating文章，也认为卡住
                $isStuck = true;
            }

            if ($isStuck) {
                // 自动重置卡住的生成任务
                $db->update('articles', ['status' => 'pending', 'error_message' => null], 'user_id=? AND status="generating"', [$userId]);
                if (file_exists($progressFile)) {
                    @unlink($progressFile);
                }
                // 继续到下方获取新任务
            } else {
                // 恢复：直接处理已标记为generating的文章
                $pending = $existingGenerating;
                $isResume = true;
            }
        }

        // 新任务或卡住重置后：获取待生成文章
        if (empty($pending)) {
            $input = json_decode(file_get_contents('php://input'), true);
            $selectedIds = $input['ids'] ?? [];

            if (!empty($selectedIds)) {
                $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                $pending = $db->fetchAll(
                    "SELECT * FROM articles WHERE user_id=? AND id IN ({$placeholders}) AND status='pending' LIMIT 50",
                    array_merge([$userId], $selectedIds)
                );
            } else {
                $pending = $db->fetchAll(
                    "SELECT * FROM articles WHERE user_id=? AND status='pending' LIMIT 50",
                    [$userId]
                );
            }

            if (empty($pending)) {
                jsonResponse(['success' => false, 'message' => '没有待生成的文章', 'total' => 0]);
            }

            // 获取配置（检查是否有可用的API Key）
            $hasApiKey = false;
            try {
                $hasApiKey = $db->count('article_templates', 'user_id=? AND api_key IS NOT NULL AND api_key != ""', [$userId]) > 0;
            } catch (Exception $e) {}
            if (!$hasApiKey) {
                $config = $db->fetchOne("SELECT * FROM global_config WHERE user_id=?", [$userId]);
                $hasApiKey = $config && !empty($config['api_key']);
            }
            if (!$hasApiKey) {
                jsonResponse(['success' => false, 'message' => '请先配置API Key（在模板或全局配置中）', 'total' => 0]);
            }

            // 标记为生成中
            foreach ($pending as $article) {
                $db->update('articles', ['status' => 'generating'], 'id=?', [$article['id']]);
            }
        }

        // 初始化进度文件
        $progressFile = UPLOAD_PATH . "progress_{$userId}.json";
        $totalArticles = count($pending);
        $startedAt = date('Y-m-d H:i:s');
        file_put_contents($progressFile, json_encode([
            'total' => $totalArticles,
            'done' => 0,
            'current' => '',
            'current_error' => '',
            'started_at' => $startedAt,
        ]));

        writeLog('article', '批量生成', "启动生成，共{$totalArticles}篇文章");

        // 释放session锁，让generate_status轮询可以并发
        session_write_close();

        // 逐篇生成（服务端循环，浏览器断开也不会中断）
        $doneCount = 0;
        $failCount = 0;

        foreach ($pending as $article) {
            // 重新检查状态（可能已被其他进程处理）
            $current = $db->fetchOne("SELECT status FROM articles WHERE id=?", [$article['id']]);
            if (!$current || $current['status'] !== 'generating') continue;

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
                        [$userId, $article['keyword']]
                    );
                    if ($kwTask) {
                        if (!$config && !empty($kwTask['template_id'])) {
                            $config = $db->fetchOne("SELECT * FROM article_templates WHERE id=? AND user_id=?", [$kwTask['template_id'], $userId]);
                        }
                        if (empty($article['publish_site_id']) && !empty($kwTask['bound_site_id'])) {
                            $db->update('articles', [
                                'publish_site_id' => $kwTask['bound_site_id'],
                                'publish_category_id' => $kwTask['bound_category_id'],
                            ], 'id=?', [$article['id']]);
                        }
                    }
                } catch (Exception $e) {}
            }

            if (!$config) {
                $config = $db->fetchOne("SELECT * FROM global_config WHERE user_id=?", [$userId]);
            }

            if (!$config || empty($config['api_key'])) {
                $db->update('articles', ['status' => 'failed', 'error_message' => '未配置API Key'], 'id=?', [$article['id']]);
                $failCount++;
                $currentError = '未配置API Key';
            } else {
                $generator = new AIGenerator();
                $result = $generator->generateArticle($article['keyword'], $config);

                if ($result['success']) {
                    $wordCount = mb_strlen(strip_tags($result['content']));
                    $db->update('articles', [
                        'title' => $result['title'],
                        'content' => $result['content'],
                        'content_type' => ($config['export_content_type'] ?? 'html') === 'html' ? 'html' : 'text',
                        'word_count' => $wordCount,
                        'status' => 'generated',
                    ], 'id=?', [$article['id']]);
                    $doneCount++;
                    $currentError = '';
                } else {
                    $db->update('articles', [
                        'status' => 'failed',
                        'error_message' => $result['message'] ?? '生成失败',
                    ], 'id=?', [$article['id']]);
                    $failCount++;
                    $currentError = $result['message'] ?? '生成失败';
                }
            }

            // 更新进度文件（前端轮询读取）
            file_put_contents($progressFile, json_encode([
                'total' => $totalArticles,
                'done' => $doneCount + $failCount,
                'current' => $article['keyword'],
                'current_error' => $currentError,
                'started_at' => $startedAt,
            ]));

            // 短暂间隔避免API限流
            usleep(500000);
        }

        // 最终进度
        file_put_contents($progressFile, json_encode([
            'total' => $totalArticles,
            'done' => $doneCount + $failCount,
            'current' => '完成',
            'current_error' => '',
            'started_at' => $startedAt,
        ]));

        writeLog('article', '批量生成完成', "成功{$doneCount}篇，失败{$failCount}篇");
        jsonResponse(['success' => true, 'total' => $totalArticles, 'done' => $doneCount + $failCount, 'failed' => $failCount]);
        break;

    case 'generate_status':
        $progressFile = UPLOAD_PATH . "progress_{$userId}.json";

        if (file_exists($progressFile)) {
            $progress = json_decode(file_get_contents($progressFile), true);
            // 实时统计
            $totalGenerated = $db->count('articles', 'user_id=? AND status="generated"', [$userId]);
            $totalFailed = $db->count('articles', 'user_id=? AND status="failed"', [$userId]);
            $stillGenerating = $db->count('articles', 'user_id=? AND status="generating"', [$userId]);

            $progress['done'] = $totalGenerated + $totalFailed;
            if ($stillGenerating === 0 && ($progress['current'] ?? '') !== '完成') {
                $progress['current'] = '完成';
            }
            jsonResponse($progress);
        }
        jsonResponse(['total' => 0, 'done' => 0, 'current' => '', 'current_error' => '']);
        break;

    case 'start_publish':
        try {
            $articles = $db->fetchAll(
                "SELECT * FROM articles WHERE user_id=? AND status='generated' LIMIT 50",
                [$userId]
            );

            if (empty($articles)) {
                jsonResponse(['success' => false, 'message' => '没有待发布的文章，请先生成文章']);
            }

            // 获取全局配置作为回退
            $globalConfig = $db->fetchOne("SELECT * FROM global_config WHERE user_id=?", [$userId]);
            $globalSiteId = intval($globalConfig['site_id'] ?? 0);
            $globalCategoryId = $globalConfig['category_id'] ?? 0;

            $scheduledCount = 0;
            $skippedCount = 0;

            // 按站点分组，获取每个站点的发布设置
            $siteSettings = [];
            foreach ($articles as $article) {
                $sid = !empty($article['publish_site_id']) ? intval($article['publish_site_id']) : $globalSiteId;
                if ($sid && !isset($siteSettings[$sid])) {
                    try {
                        $settings = $db->fetchOne("SELECT * FROM site_publish_settings WHERE site_id=? AND user_id=?", [$sid, $userId]);
                        $siteSettings[$sid] = $settings ?: null;
                    } catch (Exception $e) {
                        $siteSettings[$sid] = null;
                    }
                }
            }

            // 计算每篇文章的发布时间（按站点顺序递增，每篇间隔设定时间）
            $siteLastTime = []; // 跟踪每个站点上次排程的时间戳
            $siteDailyQueued = []; // 跟踪每个站点今日已排队数

            foreach ($articles as $article) {
                $articleSiteId = !empty($article['publish_site_id']) ? intval($article['publish_site_id']) : $globalSiteId;
                $articleCategoryId = !empty($article['publish_category_id']) ? $article['publish_category_id'] : $globalCategoryId;

                if (!$articleSiteId) {
                    $skippedCount++;
                    continue;
                }

                $settings = $siteSettings[$articleSiteId] ?? null;

                // 从站点设置中获取发布参数
                $interval = intval($settings['publish_interval'] ?? 0);
                $randomInterval = intval($settings['random_interval'] ?? 0) === 1;
                $dailyMax = intval($settings['daily_max'] ?? 0);
                $publishTimes = trim($settings['publish_time'] ?? '');

                // 检查每日限制
                if ($dailyMax > 0) {
                    if (!isset($siteDailyQueued[$articleSiteId])) {
                        $todayPublished = $db->count('articles',
                            "user_id=? AND publish_site_id=? AND status='published' AND DATE(published_at)=CURDATE()",
                            [$userId, $articleSiteId]);
                        $todayQueued = $db->count('articles',
                            "user_id=? AND publish_site_id=? AND status='scheduled' AND DATE(publish_at)=CURDATE()",
                            [$userId, $articleSiteId]);
                        $siteDailyQueued[$articleSiteId] = $todayPublished + $todayQueued;
                    }
                    if ($siteDailyQueued[$articleSiteId] >= $dailyMax) {
                        // 超过每日限制，安排到明天
                        $publishAt = date('Y-m-d 08:00:00', strtotime('+1 day'));
                        $scheduledCount++;
                        $db->update('articles', [
                            'status' => 'scheduled',
                            'publish_at' => $publishAt,
                        ], 'id=?', [$article['id']]);
                        $siteDailyQueued[$articleSiteId]++;
                        continue;
                    }
                }

                // 确定本篇文章的发布时间
                if (!isset($siteLastTime[$articleSiteId])) {
                    // 该站点第一篇文章：取第一个可用时间槽
                    if (!empty($publishTimes)) {
                        $timeSlots = array_map('trim', explode(',', $publishTimes));
                        $now = time();
                        $firstSlot = null;
                        foreach ($timeSlots as $slot) {
                            $slotTime = strtotime('today ' . $slot);
                            if ($slotTime && $slotTime > $now) {
                                $firstSlot = $slotTime;
                                break;
                            }
                        }
                        if (!$firstSlot) {
                            $firstSlot = strtotime('tomorrow ' . $timeSlots[0]);
                        }
                        $publishAt = date('Y-m-d H:i:s', $firstSlot);
                        $siteLastTime[$articleSiteId] = $firstSlot;
                    } else {
                        // 无固定时间点，从当前时间开始
                        $publishAt = date('Y-m-d H:i:s');
                        $siteLastTime[$articleSiteId] = time();
                    }
                } else {
                    // 后续文章：在上一篇基础上加间隔
                    if ($interval > 0) {
                        $delay = $randomInterval ? rand(1, $interval * 60) : $interval * 60;
                        $publishAt = date('Y-m-d H:i:s', $siteLastTime[$articleSiteId] + $delay);
                    } else {
                        // 无间隔设置，至少隔30秒避免时间完全相同
                        $publishAt = date('Y-m-d H:i:s', $siteLastTime[$articleSiteId] + 30);
                    }
                    $siteLastTime[$articleSiteId] = strtotime($publishAt);
                }

                $db->update('articles', [
                    'status' => 'scheduled',
                    'publish_at' => $publishAt,
                ], 'id=?', [$article['id']]);

                if (isset($siteDailyQueued[$articleSiteId])) {
                    $siteDailyQueued[$articleSiteId]++;
                }
                $scheduledCount++;
            }

            writeLog('article', '批量发布', "已排程{$scheduledCount}篇，跳过{$skippedCount}篇");

            // 所有文章已排入队列，用户可从队列页面手动触发"立即处理队列"或等待cron自动处理
            $message = "已排程 {$scheduledCount} 篇文章到发布队列。";
            if ($skippedCount > 0) {
                $message .= " 跳过 {$skippedCount} 篇（未设置发布目标站点，请先在文章列表中批量设置栏目）。";
            }
            $message .= " 请点击「发布队列」查看，或配置cron定时任务实现自动发布。";

            jsonResponse([
                'success' => true,
                'message' => $message,
                'scheduled' => $scheduledCount,
                'skipped' => $skippedCount,
            ]);

        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '发布失败: ' . $e->getMessage()]);
        }
        break;

    case 'process_queue':
        // 处理发布队列 - 由cron或前端调用，每次处理一篇
        try {
            $limit = intval($_GET['limit'] ?? 5);
            $processed = 0;
            $results = [];

            for ($i = 0; $i < $limit; $i++) {
                $article = $db->fetchOne(
                    "SELECT * FROM articles WHERE user_id=? AND status='scheduled' AND publish_at <= NOW() ORDER BY publish_at ASC LIMIT 1",
                    [$userId]
                );

                if (!$article) break;

                $ok = publishOneArticle($db, $article, $userId);
                $processed++;
                $results[] = [
                    'id' => $article['id'],
                    'keyword' => $article['keyword'],
                    'success' => $ok,
                ];

                // 短暂间隔避免API限流
                if ($i < $limit - 1) {
                    usleep(500000); // 0.5秒
                }
            }

            $remaining = $db->count('articles', "user_id=? AND status='scheduled' AND publish_at <= NOW()", [$userId]);
            $totalScheduled = $db->count('articles', "user_id=? AND status='scheduled'", [$userId]);
            $nextPublish = null;
            if ($processed === 0 && $totalScheduled > 0) {
                $next = $db->fetchOne(
                    "SELECT publish_at FROM articles WHERE user_id=? AND status='scheduled' AND publish_at > NOW() ORDER BY publish_at ASC LIMIT 1",
                    [$userId]
                );
                $nextPublish = $next ? $next['publish_at'] : null;
            }
            jsonResponse([
                'success' => true,
                'processed' => $processed,
                'remaining' => $remaining,
                'total_scheduled' => $totalScheduled,
                'next_publish' => $nextPublish,
                'results' => $results,
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '处理队列失败: ' . $e->getMessage()]);
        }
        break;

    case 'queue_status':
        // 查看发布队列状态
        try {
            $scheduled = $db->fetchAll(
                "SELECT id, keyword, title, publish_at, publish_site_id FROM articles WHERE user_id=? AND status='scheduled' ORDER BY publish_at ASC LIMIT 20",
                [$userId]
            );
            $totalScheduled = $db->count('articles', "user_id=? AND status='scheduled'", [$userId]);
            $readyCount = $db->count('articles', "user_id=? AND status='scheduled' AND publish_at <= NOW()", [$userId]);

            jsonResponse([
                'success' => true,
                'total_scheduled' => $totalScheduled,
                'ready_count' => $readyCount,
                'queue' => $scheduled,
            ]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => '获取队列状态失败: ' . $e->getMessage()]);
        }
        break;

    case 'export':
        $format = $_GET['format'] ?? 'html';
        $articles = $db->fetchAll(
            "SELECT * FROM articles WHERE user_id=? AND status IN ('generated','published') ORDER BY created_at DESC",
            [$userId]
        );

        if ($format === 'excel') {
            // 导出CSV（Excel兼容）
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=articles_' . date('YmdHis') . '.csv');
            echo "\xEF\xBB\xBF"; // UTF-8 BOM
            echo "ID,关键词,标题,字数,状态,发布时间\n";
            foreach ($articles as $a) {
                echo implode(',', [
                    $a['id'],
                    '"' . str_replace('"', '""', $a['keyword']) . '"',
                    '"' . str_replace('"', '""', $a['title']) . '"',
                    $a['word_count'],
                    $a['status'],
                    $a['published_at'] ?? '',
                ]) . "\n";
            }
            exit;
        }

        if ($format === 'txt') {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename=articles_' . date('YmdHis') . '.txt');
            foreach ($articles as $a) {
                echo "标题: " . $a['title'] . "\n";
                echo "关键词: " . $a['keyword'] . "\n";
                echo "---\n";
                echo strip_tags($a['content']) . "\n";
                echo "========================================\n\n";
            }
            exit;
        }

        // HTML格式 - 打包为ZIP
        $zipFile = UPLOAD_PATH . 'articles_' . $userId . '_' . date('YmdHis') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) === true) {
            foreach ($articles as $idx => $a) {
                $html = "<!DOCTYPE html><html><head><meta charset='utf-8'><title>" . htmlspecialchars($a['title']) . "</title></head><body>";
                $html .= "<h1>" . htmlspecialchars($a['title']) . "</h1>";
                $html .= "<p><strong>关键词:</strong> " . htmlspecialchars($a['keyword']) . "</p>";
                $html .= $a['content'];
                $html .= "</body></html>";
                $zip->addFromString(($idx + 1) . '_' . preg_replace('/[^\w\x{4e00}-\x{9fa5}]/u', '_', $a['title']) . '.html', $html);
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename=' . basename($zipFile));
            readfile($zipFile);
            unlink($zipFile);
            exit;
        }
        jsonResponse(['success' => false, 'message' => '导出失败']);
        break;

    default:
        jsonResponse(['success' => false, 'message' => '未知操作'], 400);
}
