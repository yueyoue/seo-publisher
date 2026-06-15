<?php
/**
 * 发布日志页面
 */
$pageTitle = '发布日志';
$page = 'site';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$siteId = intval($_GET['site_id'] ?? 0);

$site = $db->fetchOne("SELECT * FROM sites WHERE id=? AND user_id=?", [$siteId, $userId]);
if (!$site) {
    header('Location: /modules/site/index.php');
    exit;
}

$pageNum = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

$logs = $db->fetchAll(
    "SELECT pl.*, a.title as article_title FROM publish_logs pl LEFT JOIN articles a ON pl.article_id = a.id WHERE pl.site_id=? AND pl.user_id=? ORDER BY pl.created_at DESC LIMIT ? OFFSET ?",
    [$siteId, $userId, $perPage, $offset]
);
$total = $db->count('publish_logs', 'site_id=? AND user_id=?', [$siteId, $userId]);
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-journal-text"></i> 发布日志 - <?php echo e($site['name']); ?></h4>
        <a href="/modules/site/index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> 返回</a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>文章标题</th>
                            <th>操作</th>
                            <th>状态</th>
                            <th>消息</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">暂无日志</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo $log['created_at']; ?></td>
                                <td><?php echo e($log['article_title'] ?? '-'); ?></td>
                                <td><?php echo e($log['action']); ?></td>
                                <td><?php echo statusText($log['status']); ?></td>
                                <td><?php echo e($log['message'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php echo pagination($total, $pageNum, $perPage); ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
