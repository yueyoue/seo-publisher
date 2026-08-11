<?php
/**
 * 生成队列 - 排队生成文章
 */
$pageTitle = '生成队列';
$page = 'genqueue';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$statusFilter = $_GET['status'] ?? 'pending';
$pageNum = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

// 确保articles表有publish_at字段
try {
    $db->fetchOne("SELECT publish_at FROM articles LIMIT 1");
} catch (Exception $e) {
    try { $db->query("ALTER TABLE articles ADD COLUMN publish_at DATETIME DEFAULT NULL AFTER published_at"); } catch (Exception $e2) {}
}

// 统计数据
$stats = [
    'pending' => $db->count('articles', 'user_id=? AND status="pending"', [$userId]),
    'generating' => $db->count('articles', 'user_id=? AND status="generating"', [$userId]),
    'generated' => $db->count('articles', 'user_id=? AND status="generated"', [$userId]),
    'failed' => $db->count('articles', 'user_id=? AND status="failed"', [$userId]),
    'scheduled' => $db->count('articles', 'user_id=? AND status="scheduled"', [$userId]),
    'published' => $db->count('articles', 'user_id=? AND status="published"', [$userId]),
];

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // 批量加入生成队列（从生成文章页面调用）
    if ($postAction === 'add_to_queue') {
        $articleIds = $_POST['article_ids'] ?? [];
        if (!empty($articleIds)) {
            $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
            $db->query(
                "UPDATE articles SET status='pending' WHERE id IN ({$placeholders}) AND user_id=? AND status IN ('pending','failed')",
                array_merge($articleIds, [$userId])
            );
            $message = '已添加 ' . count($articleIds) . ' 篇文章到生成队列';
        }
    }

    // 重置生成中
    if ($postAction === 'reset_generating') {
        $db->update('articles', ['status' => 'pending', 'error_message' => null], 'user_id=? AND status="generating"', [$userId]);
        $progressFile = UPLOAD_PATH . "progress_{$userId}.json";
        if (file_exists($progressFile)) @unlink($progressFile);
        $message = '已重置生成中的文章';
    }
}

// 查询列表
$whereClause = "user_id=?";
$whereParams = [$userId];

switch ($statusFilter) {
    case 'pending':
        $whereClause .= " AND status='pending'";
        break;
    case 'generating':
        $whereClause .= " AND status='generating'";
        break;
    case 'generated':
        $whereClause .= " AND status='generated'";
        break;
    case 'failed':
        $whereClause .= " AND status='failed'";
        break;
    case 'all':
        // 生成队列相关的所有状态
        $whereClause .= " AND status IN ('pending','generating','generated','failed')";
        break;
}

$total = $db->fetchColumn("SELECT COUNT(*) FROM articles WHERE {$whereClause}", $whereParams);
$articles = $db->fetchAll(
    "SELECT * FROM articles WHERE {$whereClause} ORDER BY id ASC LIMIT ? OFFSET ?",
    array_merge($whereParams, [$perPage, $offset])
);

// 加载站点和模板（用于显示）
$sites = $db->fetchAll("SELECT * FROM sites WHERE user_id=? AND status=1", [$userId]);
$templates = [];
try {
    $templates = $db->fetchAll("SELECT * FROM article_templates WHERE user_id=? ORDER BY is_default DESC, id ASC", [$userId]);
} catch (Exception $e) {}

// 加载全局配置（检查API Key）
$config = $db->fetchOne("SELECT * FROM global_config WHERE user_id=?", [$userId]);
$hasApiKey = !empty($config['api_key'] ?? '');
if (!$hasApiKey) {
    try {
        $hasApiKey = $db->count('article_templates', 'user_id=? AND api_key IS NOT NULL AND api_key != ""', [$userId]) > 0;
    } catch (Exception $e) {}
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-hourglass-split"></i> 生成队列</h4>
        <div>
            <button class="btn btn-success btn-sm" id="btnStartGenerate" onclick="startGenerate()">
                <i class="bi bi-play-circle"></i> 开始生成
            </button>
            <button class="btn btn-primary btn-sm ms-1" id="btnSendToPublish" onclick="batchSendToPublish()" style="display:none">
                <i class="bi bi-send"></i> 发布选中文章
            </button>
            <button class="btn btn-outline-danger btn-sm ms-1" id="btnBatchDelete" onclick="batchDelete()" style="display:none">
                <i class="bi bi-trash"></i> 删除选中
            </button>
            <button class="btn btn-outline-danger btn-sm ms-1" id="btnStopGenerateHeader" onclick="stopGenerate()" <?php echo $stats['generating'] > 0 ? '' : 'style="display:none"'; ?>>
                <i class="bi bi-stop-circle"></i> 停止生成
            </button>
            <form method="POST" class="d-inline ms-1">
                <input type="hidden" name="action" value="reset_generating">
                <button type="submit" class="btn btn-outline-warning btn-sm" onclick="return confirm('确认重置？这会停止当前正在进行的生成任务')">
                    <i class="bi bi-arrow-counterclockwise"></i> 重置生成
                </button>
            </form>
            <button class="btn btn-outline-secondary btn-sm ms-1" onclick="location.reload()">
                <i class="bi bi-arrow-clockwise"></i> 刷新
            </button>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success alert-auto-dismiss"><i class="bi bi-check-circle"></i> <?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if (!$hasApiKey): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>未检测到API Key！</strong> 请先在
            <a href="#" data-bs-toggle="modal" data-bs-target="#configModal">全局配置</a> 或
            <a href="/modules/article/templates.php">模板管理</a> 中设置API Key。
        </div>
    <?php endif; ?>

    <!-- 生成进度 -->
    <div id="generateProgress" style="display:none" class="mb-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span><i class="bi bi-gear spin"></i> 生成进度</span>
                    <span id="progressText">0 / 0</span>
                </div>
                <div class="progress">
                    <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                </div>
                <div class="mt-2">
                    <small id="progressKeyword" class="text-muted d-block"></small>
                    <small id="progressError" class="text-danger d-block" style="display:none"></small>
                    <small id="progressStatus" class="text-muted d-block"></small>
                </div>
                <div class="mt-2">
                    <button class="btn btn-danger btn-sm" id="btnStopGenerate" style="display:none" onclick="stopGenerate()">
                        <i class="bi bi-stop-circle"></i> 停止生成
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 统计卡片 -->
    <div class="row mb-4">
        <div class="col-md-2">
            <div class="card text-center border-warning">
                <div class="card-body py-3">
                    <div class="display-6 text-warning"><?php echo $stats['pending']; ?></div>
                    <small class="text-muted">排队中</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-info">
                <div class="card-body py-3">
                    <div class="display-6 text-info"><?php echo $stats['generating']; ?></div>
                    <small class="text-muted">生成中</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-success">
                <div class="card-body py-3">
                    <div class="display-6 text-success"><?php echo $stats['generated']; ?></div>
                    <small class="text-muted">已生成</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-danger">
                <div class="card-body py-3">
                    <div class="display-6 text-danger"><?php echo $stats['failed']; ?></div>
                    <small class="text-muted">失败</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-primary">
                <div class="card-body py-3">
                    <div class="display-6 text-primary"><?php echo $stats['scheduled']; ?></div>
                    <small class="text-muted">待发布</small>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card text-center border-secondary">
                <div class="card-body py-3">
                    <div class="display-6 text-secondary"><?php echo $stats['published']; ?></div>
                    <small class="text-muted">已发布</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 筛选标签 -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>" href="?status=pending">
                <i class="bi bi-clock"></i> 排队中 <span class="badge bg-warning text-dark"><?php echo $stats['pending']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $statusFilter === 'generating' ? 'active' : ''; ?>" href="?status=generating">
                <i class="bi bi-gear"></i> 生成中 <span class="badge bg-info"><?php echo $stats['generating']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $statusFilter === 'generated' ? 'active' : ''; ?>" href="?status=generated">
                <i class="bi bi-check-circle"></i> 已生成 <span class="badge bg-success"><?php echo $stats['generated']; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $statusFilter === 'failed' ? 'active' : ''; ?>" href="?status=failed">
                <i class="bi bi-x-circle"></i> 失败 <span class="badge bg-danger"><?php echo $stats['failed']; ?></span>
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
                            <th width="30"><input type="checkbox" id="selectAll"></th>
                            <th>ID</th>
                            <th>关键词 / 标题</th>
                            <th>发布目标</th>
                            <th>模板</th>
                            <th>字数</th>
                            <th>状态</th>
                            <th>失败原因</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($articles)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">
                                <?php
                                switch ($statusFilter) {
                                    case 'pending': echo '暂无排队中的文章，请先在「生成文章」页面添加'; break;
                                    case 'generating': echo '暂无正在生成的文章'; break;
                                    case 'generated': echo '暂无已生成的文章'; break;
                                    case 'failed': echo '暂无失败的文章'; break;
                                    default: echo '生成队列为空'; break;
                                }
                                ?>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($articles as $article): ?>
                            <tr>
                                <td><input type="checkbox" class="queue-checkbox" value="<?php echo $article['id']; ?>" data-status="<?php echo $article['status']; ?>"></td>
                                <td><small class="text-muted">#<?php echo $article['id']; ?></small></td>
                                <td>
                                    <div><strong><?php echo e(mb_substr($article['keyword'], 0, 40)); ?></strong></div>
                                    <?php if (!empty($article['title']) && $article['title'] !== $article['keyword']): ?>
                                        <small class="text-muted"><?php echo e(mb_substr($article['title'], 0, 50)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php
                                    if (!empty($article['publish_site_id'])) {
                                        $pubSite = $db->fetchOne("SELECT name FROM sites WHERE id=?", [$article['publish_site_id']]);
                                        $pubCatName = '';
                                        if (!empty($article['publish_category_id'])) {
                                            $pubCat = $db->fetchOne("SELECT category_name FROM site_categories WHERE site_id=? AND category_id=?", [$article['publish_site_id'], $article['publish_category_id']]);
                                            $pubCatName = $pubCat ? $pubCat['category_name'] : $article['publish_category_id'];
                                        }
                                        echo '<span class="badge bg-info">' . e(($pubSite['name'] ?? '?')) . '</span>';
                                        if ($pubCatName) echo '<br><small class="text-muted">' . e($pubCatName) . '</small>';
                                    } else {
                                        echo '<small class="text-muted">未设置</small>';
                                    }
                                ?></td>
                                <td><?php
                                    if (!empty($article['template_id'])) {
                                        $tpl = $db->fetchOne("SELECT name FROM article_templates WHERE id=?", [$article['template_id']]);
                                        echo '<small>' . e($tpl['name'] ?? '-') . '</small>';
                                    } else {
                                        echo '<small class="text-muted">默认</small>';
                                    }
                                ?></td>
                                <td><?php echo $article['word_count'] ?: '-'; ?><?php echo $article['word_count'] ? '字' : ''; ?></td>
                                <td><?php echo statusText($article['status']); ?></td>
                                <td><?php if ($article['status'] === 'failed' && !empty($article['error_message'])): ?>
                                    <small class="text-danger" title="<?php echo e($article['error_message']); ?>"><?php echo e(mb_substr($article['error_message'], 0, 50)); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($article['status'] === 'generated'): ?>
                                            <button class="btn btn-outline-primary" onclick="previewArticle(<?php echo $article['id']; ?>)" title="预览">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn btn-outline-success" onclick="sendToPublish(<?php echo $article['id']; ?>)" title="发布">
                                                <i class="bi bi-send"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($article['status'] === 'failed'): ?>
                                            <button class="btn btn-outline-warning" onclick="retryGenerate(<?php echo $article['id']; ?>)" title="重试">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($article['status'] !== 'generating'): ?>
                                            <button class="btn btn-outline-danger" onclick="deleteFromQueue(<?php echo $article['id']; ?>)" title="删除">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
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
</div>

<!-- 文章预览弹窗 -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye"></i> 文章预览</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h5 id="previewTitle"></h5>
                <hr>
                <div id="previewContent" class="article-preview"></div>
            </div>
        </div>
    </div>
</div>

<?php
$extraJs = '<script>
// 全选
document.getElementById("selectAll")?.addEventListener("change", function() {
    document.querySelectorAll(".queue-checkbox").forEach(cb => cb.checked = this.checked);
    updateActionButtons();
});

// 监听checkbox变化
document.querySelectorAll(".queue-checkbox").forEach(cb => {
    cb.addEventListener("change", updateActionButtons);
});

function updateActionButtons() {
    const checked = document.querySelectorAll(".queue-checkbox:checked");
    const hasGenerated = Array.from(checked).some(cb => cb.dataset.status === "generated");
    const hasNonGenerating = Array.from(checked).some(cb => cb.dataset.status !== "generating");
    
    document.getElementById("btnSendToPublish").style.display = hasGenerated ? "inline-block" : "none";
    document.getElementById("btnBatchDelete").style.display = hasNonGenerating ? "inline-block" : "none";
}

// 开始生成
function startGenerate() {
    const btn = document.getElementById("btnStartGenerate");

    // 检查是否有选中的文章
    const checked = document.querySelectorAll(".queue-checkbox:checked");
    const selectedIds = [];
    checked.forEach(cb => {
        if (cb.dataset.status === "pending") selectedIds.push(parseInt(cb.value));
    });

    btn.disabled = true;
    btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> 启动中...\';

    // 立即显示进度条（不等fetch返回）
    const progressDiv = document.getElementById("generateProgress");
    progressDiv.style.display = "block";
    document.getElementById("progressStatus").textContent = "正在启动生成...";
    document.getElementById("progressKeyword").textContent = "";
    document.getElementById("progressError").style.display = "none";
    document.getElementById("btnStopGenerate").style.display = "inline-block";
    document.getElementById("btnStopGenerateHeader").style.display = "inline-block";
    btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> 生成中...\';

    // 立即开始轮询进度（不等batch_generate返回）
    pollProgress(0);

    // 发送生成请求（服务端会持续运行，不阻塞前端）
    fetch("/api/article.php?action=batch_generate", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ids: selectedIds})
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            alert(data.message || "启动失败");
            progressDiv.style.display = "none";
            btn.disabled = false;
            btn.innerHTML = \'<i class="bi bi-play-circle"></i> 开始生成\';
        }
    })
    .catch(() => {
        // fetch失败不影响，服务端可能已在运行，轮询会检测到
    });
}

// 停止生成
function stopGenerate() {
    if (!confirm("确认停止生成？\\n正在生成的当前文章会完成，之后的任务将停止。")) return;
    document.getElementById("btnStopGenerate").disabled = true;
    fetch("/api/article.php?action=stop_generate", {method:"POST"})
        .then(r => r.json())
        .then(data => {
            document.getElementById("progressStatus").textContent = "已停止生成";
            document.getElementById("progressStatus").className = "text-warning d-block";
            document.getElementById("btnStopGenerate").style.display = "none";
            document.getElementById("btnStopGenerateHeader").style.display = "none";
            document.getElementById("btnStartGenerate").disabled = false;
            document.getElementById("btnStartGenerate").innerHTML = \'<i class="bi bi-play-circle"></i> 开始生成\';
            setTimeout(() => location.reload(), 1500);
        });
}

// 轮询进度
let lastPolledKeyword = "";
function pollProgress(total) {
    fetch("/api/article.php?action=generate_status")
        .then(r => r.json())
        .then(data => {
            let done = data.done || 0;
            let t = data.total || total;
            if (done > t) done = t;
            const pct = t > 0 ? Math.round((done / t) * 100) : 0;
            
            document.getElementById("progressBar").style.width = pct + "%";
            document.getElementById("progressText").textContent = done + " / " + t;

            const currentKeyword = data.current || "";
            const currentError = data.current_error || "";

            if (currentKeyword !== lastPolledKeyword) {
                lastPolledKeyword = currentKeyword;
            }

            document.getElementById("progressKeyword").textContent = "当前: " + (currentKeyword || "处理中...");

            if (currentError) {
                document.getElementById("progressError").textContent = "⚠️ " + currentError;
                document.getElementById("progressError").style.display = "block";
            } else {
                document.getElementById("progressError").style.display = "none";
            }

            if (currentKeyword !== "完成" && currentKeyword !== "已停止" && (done < t || data.current !== "完成")) {
                setTimeout(() => pollProgress(t), 3000);
            } else if (currentKeyword === "已停止") {
                document.getElementById("progressStatus").textContent = "已停止生成";
                document.getElementById("progressStatus").className = "text-warning d-block";
                document.getElementById("btnStopGenerate").style.display = "none";
                document.getElementById("btnStopGenerateHeader").style.display = "none";
                document.getElementById("btnStartGenerate").disabled = false;
                document.getElementById("btnStartGenerate").innerHTML = \'<i class="bi bi-play-circle"></i> 开始生成\';
                setTimeout(() => location.reload(), 2000);
            } else {
                document.getElementById("progressBar").style.width = "100%";
                document.getElementById("progressBar").className = "progress-bar bg-success";
                document.getElementById("progressKeyword").textContent = "";
                document.getElementById("progressError").style.display = "none";
                document.getElementById("progressStatus").textContent = "生成完成！";
                document.getElementById("progressStatus").className = "text-success d-block";
                document.getElementById("btnStopGenerate").style.display = "none";
                document.getElementById("btnStopGenerateHeader").style.display = "none";
                document.getElementById("btnStartGenerate").disabled = false;
                document.getElementById("btnStartGenerate").innerHTML = \'<i class="bi bi-play-circle"></i> 开始生成\';
                setTimeout(() => location.reload(), 2000);
            }
        })
        .catch(() => {
            setTimeout(() => pollProgress(total), 5000);
        });
}

// 预览文章
function previewArticle(id) {
    fetch("/api/article.php?action=preview&id=" + id)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById("previewTitle").textContent = data.title;
                document.getElementById("previewContent").innerHTML = data.content;
                new bootstrap.Modal(document.getElementById("previewModal")).show();
            }
        });
}

// 发送到发布队列
function sendToPublish(id) {
    if (!confirm("确认将此文章加入发布队列？")) return;
    fetch("/api/article.php?action=start_publish", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ids: [id]})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message || "已加入发布队列");
            location.reload();
        } else {
            alert(data.message || "操作失败");
        }
    })
    .catch(err => alert("请求失败: " + err.message));
}

// 批量发送到发布队列
function batchSendToPublish() {
    const ids = [];
    document.querySelectorAll(".queue-checkbox:checked").forEach(cb => {
        if (cb.dataset.status === "generated") ids.push(parseInt(cb.value));
    });
    if (ids.length === 0) {
        alert("请先选择已生成的文章");
        return;
    }
    if (!confirm("确认将 " + ids.length + " 篇文章加入发布队列？")) return;
    fetch("/api/article.php?action=start_publish", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ids: ids})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message || "已加入发布队列");
            location.reload();
        } else {
            alert(data.message || "操作失败");
        }
    })
    .catch(err => alert("请求失败: " + err.message));
}

// 重试生成
function retryGenerate(id) {
    fetch("/api/article.php?action=retry_generate", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ids: [id]})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || "操作失败");
        }
    });
}

// 删除
function deleteFromQueue(id) {
    if (!confirm("确认删除？")) return;
    fetch("/api/article.php?action=delete&id=" + id, {method:"POST"})
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        });
}

// 批量删除
function batchDelete() {
    const ids = [];
    document.querySelectorAll(".queue-checkbox:checked").forEach(cb => {
        if (cb.dataset.status !== "generating") ids.push(parseInt(cb.value));
    });
    if (ids.length === 0) {
        alert("请先选择要删除的文章");
        return;
    }
    if (!confirm("确认删除选中的 " + ids.length + " 篇文章？")) return;
    
    let deleted = 0;
    ids.forEach(id => {
        fetch("/api/article.php?action=delete&id=" + id, {method:"POST"})
            .then(r => r.json())
            .then(data => {
                deleted++;
                if (deleted === ids.length) location.reload();
            });
    });
}

// 页面加载时检查是否有正在生成的任务或待生成的文章
</script>';

if ($stats['generating'] > 0) {
    $extraJs .= '<script>
(function() {
    const progressDiv = document.getElementById("generateProgress");
    progressDiv.style.display = "block";
    document.getElementById("progressStatus").textContent = "检测到 ' . intval($stats['generating']) . ' 篇正在生成的文章，自动恢复进度监控...";
    document.getElementById("btnStartGenerate").disabled = true;
    document.getElementById("btnStartGenerate").innerHTML = \'<span class="spinner-border spinner-border-sm"></span> 生成中...\';
    document.getElementById("btnStopGenerateHeader").style.display = "inline-block";
    pollProgress();
})();
</script>';
}



require_once __DIR__ . '/../../includes/layout/footer.php';
?>
