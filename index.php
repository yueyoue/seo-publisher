<?php
/**
 * 控制台首页
 */
$pageTitle = '控制台';
$page = 'dashboard';
require_once __DIR__ . '/includes/layout/header.php';
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$isAdmin = Auth::isAdmin();

// 统计数据
$where = $isAdmin ? '1=1' : 'user_id=' . $userId;
$siteCount = $db->count('sites', $where);
$articleCount = $db->count('articles', $where);
$keywordCount = $db->count('keyword_tasks', $where);
$publishedCount = $db->count('articles', $where . " AND status='published'");

// 最近文章
$recentArticles = $db->fetchAll(
    "SELECT * FROM articles WHERE {$where} ORDER BY created_at DESC LIMIT 5"
);

// 最近日志
$recentLogs = $db->fetchAll(
    "SELECT * FROM logs WHERE {$where} ORDER BY created_at DESC LIMIT 10"
);
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-speedometer2"></i> 控制台</h4>
        <span class="text-muted">欢迎回来，<?php echo e($_SESSION['username']); ?>！</span>
    </div>

    <!-- 统计卡片 -->
    <div class="row mb-4">
        <div class="col-md-3 col-6 mb-3">
            <div class="stat-card fade-in">
                <div class="stat-icon bg-primary"><i class="bi bi-globe"></i></div>
                <div class="stat-number"><?php echo $siteCount; ?></div>
                <div class="stat-label">管理站点</div>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stat-card fade-in">
                <div class="stat-icon bg-success"><i class="bi bi-file-earmark-text"></i></div>
                <div class="stat-number"><?php echo $articleCount; ?></div>
                <div class="stat-label">生成文章</div>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stat-card fade-in">
                <div class="stat-icon bg-warning"><i class="bi bi-key"></i></div>
                <div class="stat-number"><?php echo $keywordCount; ?></div>
                <div class="stat-label">挖词任务</div>
            </div>
        </div>
        <div class="col-md-3 col-6 mb-3">
            <div class="stat-card fade-in">
                <div class="stat-icon bg-info"><i class="bi bi-cloud-upload"></i></div>
                <div class="stat-number"><?php echo $publishedCount; ?></div>
                <div class="stat-label">已发布</div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- 最近文章 -->
        <div class="col-lg-8 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clock-history"></i> 最近文章</span>
                    <a href="/modules/article/index.php" class="btn btn-sm btn-outline-primary">查看全部</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>标题</th>
                                    <th>关键词</th>
                                    <th>状态</th>
                                    <th>时间</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentArticles)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">暂无数据</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentArticles as $article): ?>
                                    <tr>
                                        <td><?php echo e(mb_substr($article['title'], 0, 30)); ?></td>
                                        <td><span class="badge bg-light text-dark"><?php echo e($article['keyword']); ?></span></td>
                                        <td><?php echo statusText($article['status']); ?></td>
                                        <td class="text-muted"><?php echo timeAgo($article['created_at']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- 快捷操作 -->
        <div class="col-lg-4 mb-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-lightning"></i> 快捷操作</div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="/modules/site/index.php?action=add" class="btn btn-outline-primary">
                            <i class="bi bi-plus-circle"></i> 添加网站
                        </a>
                        <a href="/modules/keyword/index.php" class="btn btn-outline-warning">
                            <i class="bi bi-search"></i> 挖掘关键词
                        </a>
                        <a href="/modules/article/index.php" class="btn btn-outline-success">
                            <i class="bi bi-pencil-square"></i> 生成文章
                        </a>
                        <a href="/modules/user/orders.php" class="btn btn-outline-info">
                            <i class="bi bi-receipt"></i> 我的订单
                        </a>
                    </div>
                </div>
            </div>

            <!-- 最近日志 -->
            <div class="card mt-3">
                <div class="card-header"><i class="bi bi-journal-text"></i> 最近日志</div>
                <div class="card-body p-0" style="max-height:300px;overflow-y:auto">
                    <?php if (empty($recentLogs)): ?>
                        <p class="text-center text-muted py-3">暂无日志</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentLogs as $log): ?>
                            <div class="list-group-item py-2">
                                <small class="text-muted"><?php echo timeAgo($log['created_at']); ?></small>
                                <span class="ms-2"><?php echo e($log['module'] . ' - ' . $log['action']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout/footer.php'; ?>
