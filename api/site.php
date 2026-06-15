<?php
/**
 * 站点API
 */
ob_start();
require_once __DIR__ . '/../config/init.php';
require_once ROOT_PATH . '/includes/Functions.php';
Auth::check();
if (ob_get_level()) ob_end_clean();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_categories':
        $siteId = intval($_GET['site_id'] ?? 0);
        $categories = $db->fetchAll(
            "SELECT * FROM site_categories WHERE site_id=? ORDER BY id",
            [$siteId]
        );
        jsonResponse(['success' => true, 'categories' => $categories]);
        break;

    case 'sync_categories':
        $siteId = intval($_GET['site_id'] ?? 0);
        $site = $db->fetchOne("SELECT * FROM sites WHERE id=? AND user_id=?", [$siteId, $userId]);
        if (!$site) {
            jsonResponse(['success' => false, 'message' => '站点不存在']);
        }

        $publisher = new WordPressPublisher($site['domain'], $site['username'], $site['password']);
        $categories = $publisher->getCategories();

        if ($categories) {
            // 先删除旧的
            $db->delete('site_categories', 'site_id=?', [$siteId]);
            // 保存新的
            foreach ($categories as $cat) {
                $db->insert('site_categories', [
                    'site_id' => $siteId,
                    'category_id' => $cat['id'],
                    'category_name' => $cat['name'],
                    'synced_at' => date('Y-m-d H:i:s'),
                ]);
            }
            writeLog('site', '同步栏目', "站点:{$site['name']}, " . count($categories) . "个栏目");
            // 返回格式与 get_categories 一致（用 category_id / category_name 字段）
            $formatted = [];
            foreach ($categories as $cat) {
                $formatted[] = [
                    'category_id' => $cat['id'],
                    'category_name' => $cat['name'],
                ];
            }
            jsonResponse(['success' => true, 'message' => '同步成功，共 ' . count($categories) . ' 个栏目', 'categories' => $formatted, 'debug' => $publisher->getDebug()]);
        } else {
            $debugInfo = implode(' | ', $publisher->getDebug());
            jsonResponse(['success' => false, 'message' => '同步失败，请检查网站配置是否正确（域名、账号密码）。调试信息: ' . $debugInfo, 'debug' => $publisher->getDebug()]);
        }
        break;

    case 'test_publish':
        $siteId = intval($_GET['site_id'] ?? 0);
        $site = $db->fetchOne("SELECT * FROM sites WHERE id=? AND user_id=?", [$siteId, $userId]);
        if (!$site) {
            jsonResponse(['success' => false, 'message' => '站点不存在']);
        }

        $publisher = new WordPressPublisher($site['domain'], $site['username'], $site['password']);
        $result = $publisher->publishPost(
            '【测试文章】SEO Publisher 测试发布 - ' . date('Y-m-d H:i:s'),
            '<p>这是一篇由 SEO Publisher 系统自动发布的测试文章。</p><p>发布时间：' . date('Y-m-d H:i:s') . '</p><p>如果您看到这篇文章，说明网站连接配置正确。</p>',
            0,
            'publish'
        );

        if ($result['success']) {
            $db->update('sites', ['last_publish' => date('Y-m-d H:i:s')], 'id=?', [$siteId]);
            $db->insert('publish_logs', [
                'site_id' => $siteId,
                'user_id' => $userId,
                'action' => '测试发布',
                'status' => 'success',
                'message' => '发布成功，文章ID: ' . $result['post_id'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            writeLog('site', '测试发布', '成功，文章ID: ' . $result['post_id']);
            jsonResponse(['success' => true, 'message' => '测试发布成功！文章ID: ' . $result['post_id']]);
        } else {
            $db->insert('publish_logs', [
                'site_id' => $siteId,
                'user_id' => $userId,
                'action' => '测试发布',
                'status' => 'failed',
                'message' => $result['message'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $debugInfo = implode(' | ', $publisher->getDebug());
            writeLog('site', '测试发布失败', $result['message']);
            jsonResponse(['success' => false, 'message' => '测试发布失败: ' . $result['message'] . "\n调试信息: " . $debugInfo]);
        }
        break;

    case 'get_logs':
        $siteId = intval($_GET['site_id'] ?? 0);
        $logs = $db->fetchAll(
            "SELECT * FROM publish_logs WHERE site_id=? AND user_id=? ORDER BY created_at DESC LIMIT 50",
            [$siteId, $userId]
        );
        jsonResponse(['success' => true, 'logs' => $logs]);
        break;

    case 'get_publish_settings':
        $siteId = intval($_GET['site_id'] ?? 0);
        $settings = null;
        try {
            $settings = $db->fetchOne("SELECT * FROM site_publish_settings WHERE site_id=? AND user_id=?", [$siteId, $userId]);
        } catch (Exception $e) {}
        jsonResponse(['success' => true, 'settings' => $settings]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => '未知操作'], 400);
}
