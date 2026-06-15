<?php
/**
 * 发布队列 - 查看所有发布状态
 */
$pageTitle = '发布队列';
$page = 'queue';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$statusFilter = $_GET['status'] ?? 'scheduled';
$pageNum = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

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

// 统计数据
$stats = [
    'scheduled' => 0,
    'publishing' => 0,
    'published_today' => 0,
    'published_total' => 0,
    'failed' => 0,
];
try {
    $stats['scheduled'] = $db->count('articles', "user_id=? AND status='scheduled'", [$userId]);
    $stats['publishing'] = $db->count('articles', "user_id=? AND status='publishing'", [$userId]);
    $stats['published_today'] = $db->count('articles', "user_id=? AND status='published' AND DATE(published_at)=CURDATE()", [$userId]);
    $stats['published_total'] = $db->count('articles', "user_id=? AND status='published'", [$userId]);
    $stats['failed'] = $db->count('articles', "user_id=? AND status='failed' AND publish_at IS NOT NULL", [$userId]);
} catch (Exception $e) {}

// 查询列表
$whereClause = "a.user_id=?";
$whereParams = [$userId];

switch ($statusFilter) {
    case 'scheduled':
        $whereClause .= " AND a.status IN ('scheduled','publishing')";
        break;
    case 'published':
        $whereClause .= " AND a.status='published'";
        break;
    case 'failed':
        $whereClause .= " AND a.status='failed' AND a.publish_at IS NOT NULL";
        break;
    case 'all':
        $whereClause .= " AND a.publish_at IS NOT NULL";
        break;
}

$total = 0;
$articles = [];
try {
    $total = $db->fetchColumn(
        "SELECT COUNT(*) FROM articles a WHERE {$whereClause}",
        $whereParams
    );

    $articles = $db->fetchAll(
        "SELECT a.*, s.name AS site_name, s.domain AS site_domain 
         FROM articles a 
         LEFT JOIN sites s ON a.publish_site_id = s.id 
         WHERE {$whereClause} 
         ORDER BY a.publish_at " . ($statusFilter === 'scheduled' ? 'ASC' : 'DESC') . " 
         LIMIT ? OFFSET ?",
        array_merge($whereParams, [$perPage, $offset])
    );
} catch (Exception $e) {
    $articles = [];
}

// 获取栏目名称
function getCategoryName($db, $siteId, $categoryId) {
    if (!$siteId || !$categoryId) return '-';
    try {
        $cat = $db->fetchOne("SELECT category_name FROM site_categories WHERE site_id=? AND category_id=?", [$siteId, $categoryId]);
        return $cat ? $cat['category_name'] : $categoryId;
    } catch (Exception $e) {
        return $categoryId;
    }
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-clock-history"></i> 发布队列</h4>
        <div>
            <button class="btn btn-warning btn-sm" id="btnBatchCancel" onclick="batchCancelPublish()" style="display:none">
                <i class="bi bi-x-circle"></i> 批量取消发送
            </button>
            <button class="btn btn-success btn-sm ms-1" id="btnProcessQueue" onclick="processQueue()">
                <i class="bi bi-play-circle"></i> 立即处理队列
            </button>
            <button class="btn btn-outline-secondary btn-sm ms-1" onclick="location.reload()">
                <i class="bi bi-arrow-clockwise"></i> 刷新
            </button>
            <div class="form-check form-check-inline ms-3 mb-0">
                <input class="form-check-input" type="checkbox" id="autoRefresh" checked>
                <label class="form-check-label small" for="autoRefresh">自动刷新</label>
            </div>
        </div>
    </div>

    <!-- 统计卡片 -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card text-center border-primary">
                <div class="card-body py-3">
                    <div class="display-6 text-primary"><?php echo $stats['scheduled']; ?></div>
                    <small class="text-muted">排队中</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-info">
                <div class="card-body py-3">
                    <div class="display-6 text-info"><?php echo $stats['publishing']; ?></div>
                    <small class="text-muted">发布中</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-success">
                <div class="card-body py-3">
                    <div class="display-6 text-success"><?php echo $stats['published_today']; ?></div>
                    <small class="text-muted">今日已发布</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-secondary">
                <div class="card-body py-3">
                    <div class="display-6 text-secondary"><?php echo $stats['published_total']; ?></div>
                    <small class="text-muted">历史已发布</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-danger">
                <div class="card-body py-3">
                    <div class="display-6 text-danger"><?php echo $stats['failed']; ?></div>
                    <small class="text-muted">发布失败</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-warning">
                <div class="card-body py-3">
                    <?php
                    // 计算下一次发布时间
                    $nextPublish = $db->fetchOne(
                        "SELECT publish_at FROM articles WHERE user_id=? AND status='scheduled' ORDER BY publish_at ASC LIMIT 1",
                        [$userId]
                    );
                    if ($nextPublish) {
                        $diff = strtotime($nextPublish['publish_at']) - time();
                        if ($diff <= 0) {
                            echo '<div class="text-warning fw-bold" style="font-size:1.2rem">即将发布</div>';
                        } elseif ($diff < 3600) {
                            echo '<div class="display-6 text-warning">' . ceil($diff / 60) . '</div>';
                            echo '<small class="text-muted">分钟后发布</small>';
                        } else {
                            echo '<div class="text-warning fw-bold" style="font-size:1.2rem">' . date('H:i', strtotime($nextPublish['publish_at'])) . '</div>';
                            echo '<small class="text-muted">下次发布时间</small>';
                        }
                    } else {
                        echo '<div class="text-muted" style="font-size:1.2rem">-</div>';
                        echo '<small class="text-muted">无排队任务</small>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 筛选标签 -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?php echo $statusFilter === 'scheduled' ? 'active' : ''; ?>" href="?status=scheduled">
                <i class="bi bi-clock"></i> 排队中 <span class="badge bg-primary"><?php echo $stats['scheduled']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $statusFilter === 'published' ? 'active' : ''; ?>" href="?status=published">
                <i class="bi bi-check-circle"></i> 已发布 <span class="badge bg-success"><?php echo $stats['published_today']; ?> 今日</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $statusFilter === 'failed' ? 'active' : ''; ?>" href="?status=failed">
                <i class="bi bi-x-circle"></i> 发布失败 <span class="badge bg-danger"><?php echo $stats['failed']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $statusFilter === 'all' ? 'active' : ''; ?>" href="?status=all">
                <i class="bi bi-list"></i> 全部
            </a>
        </li>
    </ul>

    <!-- 文章列表 -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th width="30"><input type="checkbox" id="selectAllQueue" title="全选"></th>
                            <th>关键词 / 标题</th>
                            <th>发布目标</th>
                            <th>栏目</th>
                            <th>计划时间</th>
                            <th>状态</th>
                            <th>结果</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($articles)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">
                                <?php
                                switch ($statusFilter) {
                                    case 'scheduled': echo '暂无排队中的文章'; break;
                                    case 'published': echo '暂无已发布的文章'; break;
                                    case 'failed': echo '暂无发布失败的文章'; break;
                                    default: echo '暂无发布记录'; break;
                                }
                                ?>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($articles as $article): ?>
                            <tr>
                                <td><input type="checkbox" class="queue-checkbox" value="<?php echo $article['id']; ?>"></td>
                                <td>
                                    <div><strong><?php echo e(mb_substr($article['keyword'], 0, 30)); ?></strong></div>
                                    <?php if (!empty($article['title'])): ?>
                                        <small class="text-muted"><?php echo e(mb_substr($article['title'], 0, 50)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($article['site_name'])): ?>
                                        <span class="badge bg-info"><?php echo e($article['site_name']); ?></span>
                                        <br><small class="text-muted"><?php echo e($article['site_domain'] ?? ''); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">未设置</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $catName = getCategoryName($db, $article['publish_site_id'] ?? 0, $article['publish_category_id'] ?? '');
                                    echo $catName !== '-' ? '<span class="badge bg-light text-dark">' . e($catName) . '</span>' : '<span class="text-muted">默认</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php if ($article['publish_at']): ?>
                                        <?php
                                        $pubTime = strtotime($article['publish_at']);
                                        $now = time();
                                        $diff = $pubTime - $now;
                                        ?>
                                        <div><?php echo date('m-d H:i', $pubTime); ?></div>
                                        <?php if ($article['status'] === 'scheduled'): ?>
                                            <?php if ($diff <= 0): ?>
                                                <small class="text-success fw-bold">⏰ 已到时间</small>
                                            <?php elseif ($diff < 3600): ?>
                                                <small class="text-muted">还有 <?php echo ceil($diff / 60); ?> 分钟</small>
                                            <?php elseif ($diff < 86400): ?>
                                                <small class="text-muted">还有 <?php echo floor($diff / 3600); ?> 小时</small>
                                            <?php else: ?>
                                                <small class="text-muted">还有 <?php echo floor($diff / 86400); ?> 天</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo statusText($article['status']); ?></td>
                                <td>
                                    <?php if ($article['status'] === 'published'): ?>
                                        <span class="text-success"><i class="bi bi-check-circle"></i></span>
                                        <?php if (!empty($article['publish_post_id'])): ?>
                                            <small class="text-muted">ID: <?php echo $article['publish_post_id']; ?></small>
                                        <?php endif; ?>
                                        <?php if ($article['published_at']): ?>
                                            <br><small class="text-muted"><?php echo date('m-d H:i', strtotime($article['published_at'])); ?></small>
                                        <?php endif; ?>
                                    <?php elseif ($article['status'] === 'failed'): ?>
                                        <span class="text-danger"><i class="bi bi-exclamation-triangle"></i></span>
                                        <small class="text-danger"><?php echo e(mb_substr($article['error_message'] ?? '未知错误', 0, 60)); ?></small>
                                    <?php elseif ($article['status'] === 'scheduled'): ?>
                                        <span class="text-primary"><i class="bi bi-hourglass-split"></i></span>
                                        <small class="text-muted">等待中</small>
                                    <?php elseif ($article['status'] === 'publishing'): ?>
                                        <span class="spinner-border spinner-border-sm text-info"></span>
                                        <small class="text-info">发布中...</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($article['status'] === 'scheduled' || $article['status'] === 'publishing'): ?>
                                        <button class="btn btn-outline-warning btn-sm" onclick="cancelPublish([<?php echo $article['id']; ?>])" title="取消发送">
                                            <i class="bi bi-x-circle"></i> 取消
                                        </button>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php echo pagination($total, $pageNum, $perPage); ?>

    <?php if (Auth::isAdmin()): ?>
    <!-- Cron配置提示 -->
    <div class="card mt-4 border-warning">
        <div class="card-body">
            <h6><i class="bi bi-info-circle text-warning"></i> 后台自动发布配置</h6>
            <p class="mb-2 small text-muted">要实现7×24小时自动发布（无需打开浏览器），请在服务器上配置定时任务：</p>
            
            <h6 class="mt-3"><i class="bi bi-terminal"></i> 方式一：CLI模式（推荐）</h6>
            <div class="bg-dark text-light p-2 rounded small">
                <code>* * * * * php <?php echo ROOT_PATH; ?>/cron/publish.php >> <?php echo ROOT_PATH; ?>/cron/publish.log 2>&1</code>
            </div>
            <p class="mt-1 mb-2 small text-muted">
                宝塔面板用户：面板 → 计划任务 → 添加「Shell脚本」类型任务，粘贴上方命令，执行周期选"每分钟"。<br>
                <strong>注意：</strong>宝塔添加时请确保使用的是「Shell脚本」类型，而非「PHP脚本」类型，避免产生 BT-Panel 报错。
            </p>

            <h6 class="mt-3"><i class="bi bi-globe"></i> 方式二：URL触发（宝塔URL任务推荐）</h6>
            <div class="bg-dark text-light p-2 rounded small">
                <code><?php echo (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '你的域名') . '/cron/publish.php?key=seo-publisher-cron-2024'; ?></code>
            </div>
            <p class="mt-1 mb-0 small text-muted">
                宝塔面板用户：面板 → 计划任务 → 添加「访问URL」类型任务，粘贴上方地址，执行周期选"每分钟"。<br>
                文章生成：<code>/cron/generate.php?key=seo-publisher-cron-2024</code>（建议每2分钟执行一次）
            </p>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$extraJs = '<script>
// 取消发送（单选或批量）
function cancelPublish(ids) {
    if (!confirm("确认取消发送？取消后文章将回到「已生成」状态。")) return;
    fetch("/api/article.php?action=cancel_publish", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ids: ids})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message || "取消成功");
            location.reload();
        } else {
            alert(data.message || "取消失败");
        }
    })
    .catch(err => alert("请求失败: " + err.message));
}

// 批量取消
function batchCancelPublish() {
    const ids = [];
    document.querySelectorAll(".queue-checkbox:checked").forEach(cb => ids.push(parseInt(cb.value)));
    if (ids.length === 0) {
        alert("请先选择要取消的文章");
        return;
    }
    cancelPublish(ids);
}

// 全选/取消全选
document.getElementById("selectAllQueue")?.addEventListener("change", function() {
    document.querySelectorAll(".queue-checkbox").forEach(cb => cb.checked = this.checked);
    updateBatchCancelBtn();
});

// 监听checkbox变化，显示/隐藏批量取消按钮
document.querySelectorAll(".queue-checkbox").forEach(cb => {
    cb.addEventListener("change", updateBatchCancelBtn);
});

function updateBatchCancelBtn() {
    const checked = document.querySelectorAll(".queue-checkbox:checked").length;
    const btn = document.getElementById("btnBatchCancel");
    if (btn) btn.style.display = checked > 0 ? "inline-block" : "none";
}

// 自动刷新（每30秒）
let refreshTimer = null;
function startAutoRefresh() {
    if (document.getElementById("autoRefresh").checked) {
        refreshTimer = setTimeout(() => location.reload(), 30000);
    }
}
function stopAutoRefresh() {
    if (refreshTimer) clearTimeout(refreshTimer);
}
document.getElementById("autoRefresh").addEventListener("change", function() {
    if (this.checked) startAutoRefresh();
    else stopAutoRefresh();
});
startAutoRefresh();

// 立即处理队列
function processQueue() {
    const btn = document.getElementById("btnProcessQueue");
    btn.disabled = true;
    btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> 处理中...\';

    fetch("/api/article.php?action=process_queue&limit=5")
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = \'<i class="bi bi-play-circle"></i> 立即处理队列\';
            if (data.success) {
                if (data.processed > 0) {
                    // 显示处理结果
                    let msg = "已处理 " + data.processed + " 篇文章";
                    if (data.results) {
                        const successes = data.results.filter(r => r.success).length;
                        const fails = data.results.filter(r => !r.success).length;
                        if (fails > 0) msg += "（成功" + successes + "篇，失败" + fails + "篇）";
                    }
                    if (data.remaining > 0) msg += "\\n还有 " + data.remaining + " 篇等待发布";
                    alert(msg);
                    location.reload();
                } else {
                    let msg = "没有到期需要发布的文章";
                    if (data.total_scheduled > 0) {
                        msg += "\\n当前有 " + data.total_scheduled + " 篇文章在排队等待发布";
                        if (data.next_publish) {
                            msg += "\\n下一篇发布时间: " + data.next_publish;
                        }
                    }
                    alert(msg);
                }
            } else {
                alert(data.message || "处理失败");
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = \'<i class="bi bi-play-circle"></i> 立即处理队列\';
            alert("请求失败: " + err.message);
        });
}
</script>';

require_once __DIR__ . '/../../includes/layout/footer.php';
?>
