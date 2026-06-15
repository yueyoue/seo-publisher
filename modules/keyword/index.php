<?php
/**
 * 实时关键词挖掘
 */
ob_start();
$pageTitle = '关键词挖掘';
$page = 'keyword';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$message = '';
$error = '';

// 处理POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'add_task') {
        $keyword = trim($_POST['keyword'] ?? '');
        $keywordCount = intval($_POST['keyword_count'] ?? 50);
        $mustContain = trim($_POST['must_contain'] ?? '');
        $sourceTypes = isset($_POST['source_types']) ? implode(',', $_POST['source_types']) : 'suggest,related,also_search';
        $miningType = $_POST['mining_type'] ?? 'search_engine';
        $competitorUrl = trim($_POST['competitor_url'] ?? '');

        if ($miningType === 'competitor') {
            // 竞争对手模式：keyword字段用URL代替，competitor_url存URL
            if (empty($competitorUrl)) {
                $error = '请输入竞争站网址';
            } else {
                $taskId = $db->insert('keyword_tasks', [
                    'user_id' => $userId,
                    'keyword' => $competitorUrl,
                    'must_contain' => '',
                    'keyword_count' => $keywordCount,
                    'source_types' => '',
                    'mining_type' => 'competitor',
                    'competitor_url' => $competitorUrl,
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                $miner = new KeywordMiner();
                $count = $miner->executeTask($taskId);
                
                $message = "竞争站挖词完成！从 {$competitorUrl} 找到 {$count} 个关键词";
                writeLog('keyword', '竞争站挖词', "URL:{$competitorUrl}, 找到:{$count}个");
            }
        } else {
            if (empty($keyword)) {
                $error = '请输入关键词';
            } else {
                $taskId = $db->insert('keyword_tasks', [
                    'user_id' => $userId,
                    'keyword' => $keyword,
                    'must_contain' => $mustContain,
                    'keyword_count' => $keywordCount,
                    'source_types' => $sourceTypes,
                    'mining_type' => 'search_engine',
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                $miner = new KeywordMiner();
                $count = $miner->executeTask($taskId);
                
                $message = "挖词完成！找到 {$count} 个相关关键词";
                writeLog('keyword', '挖词任务', "关键词:{$keyword}, 找到:{$count}个");
            }
        }
    }

    if ($postAction === 'delete_task') {
        $taskId = intval($_POST['task_id'] ?? 0);
        $db->delete('keyword_tasks', 'id=? AND user_id=?', [$taskId, $userId]);
        $db->delete('keyword_results', 'task_id=? AND user_id=?', [$taskId, $userId]);
        $message = '任务已删除';
    }

    if ($postAction === 'import_to_articles') {
        $taskId = intval($_POST['task_id'] ?? 0);
        $results = $db->fetchAll("SELECT * FROM keyword_results WHERE task_id=? AND user_id=?", [$taskId, $userId]);
        
        // 获取任务的绑定信息
        $task = $db->fetchOne("SELECT * FROM keyword_tasks WHERE id=? AND user_id=?", [$taskId, $userId]);
        
        $count = 0;
        foreach ($results as $row) {
            $exists = $db->count('articles', 'user_id=? AND keyword=?', [$userId, $row['keyword']]);
            if ($exists) continue;

            $articleData = [
                'user_id' => $userId,
                'keyword' => $row['keyword'],
                'title' => $row['keyword'],
                'content' => '',
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
            ];
            // 继承任务绑定的站点和模板
            if ($task && !empty($task['bound_site_id'])) {
                $articleData['publish_site_id'] = $task['bound_site_id'];
            }
            if ($task && !empty($task['bound_category_id'])) {
                $articleData['publish_category_id'] = $task['bound_category_id'];
            }
            if ($task && !empty($task['template_id'])) {
                $articleData['template_id'] = $task['template_id'];
            }
            $db->insert('articles', $articleData);
            $count++;
        }
        $message = "已导入 {$count} 个关键词到文章生成";
    }

    // 生成单篇文章（继承任务绑定）
    if ($postAction === 'generate_single_article') {
        $keyword = trim($_POST['keyword'] ?? '');
        $fromTaskId = intval($_POST['from_task_id'] ?? 0);
        if ($keyword) {
            $exists = $db->count('articles', 'user_id=? AND keyword=?', [$userId, $keyword]);
            if (!$exists) {
                $articleData = [
                    'user_id' => $userId,
                    'keyword' => $keyword,
                    'title' => $keyword,
                    'content' => '',
                    'status' => 'pending',
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                // 继承任务绑定的站点和模板
                if ($fromTaskId) {
                    $fromTask = $db->fetchOne("SELECT * FROM keyword_tasks WHERE id=? AND user_id=?", [$fromTaskId, $userId]);
                    if ($fromTask) {
                        if (!empty($fromTask['bound_site_id'])) $articleData['publish_site_id'] = $fromTask['bound_site_id'];
                        if (!empty($fromTask['bound_category_id'])) $articleData['publish_category_id'] = $fromTask['bound_category_id'];
                        if (!empty($fromTask['template_id'])) $articleData['template_id'] = $fromTask['template_id'];
                    }
                }
                $db->insert('articles', $articleData);
                $message = "已添加「{$keyword}」到文章生成列表";
            } else {
                $error = '该关键词已存在';
            }
        }
    }

    // 删除单条关键词结果
    if ($postAction === 'delete_keyword_result') {
        $resultId = intval($_POST['result_id'] ?? 0);
        $db->delete('keyword_results', 'id=? AND user_id=?', [$resultId, $userId]);
        $message = '已删除';
    }

    // 批量绑定栏目（绑定到任务主词）
    if ($postAction === 'bind_tasks_category') {
        $taskIds = $_POST['task_ids'] ?? [];
        $bindSiteId = intval($_POST['bind_site_id'] ?? 0);
        $bindCategoryId = $_POST['bind_category_id'] ?? '';

        if (!empty($taskIds) && $bindSiteId) {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $db->query(
                "UPDATE keyword_tasks SET bound_site_id=?, bound_category_id=? WHERE id IN ({$placeholders}) AND user_id=?",
                array_merge([$bindSiteId, $bindCategoryId], $taskIds, [$userId])
            );
            // 同步更新已导入到文章的关联数据（Fix #6）
            foreach ($taskIds as $taskId) {
                $task = $db->fetchOne("SELECT keyword FROM keyword_tasks WHERE id=? AND user_id=?", [$taskId, $userId]);
                if ($task) {
                    $db->query(
                        "UPDATE articles SET publish_site_id=?, publish_category_id=? WHERE user_id=? AND keyword=? AND status IN ('pending','generated')",
                        [$bindSiteId, $bindCategoryId, $userId, $task['keyword']]
                    );
                }
            }
            $message = '已绑定 ' . count($taskIds) . ' 个任务';
        }
    }

    // 批量选择模板（绑定到任务主词）
    if ($postAction === 'bind_tasks_template') {
        $taskIds = $_POST['task_ids'] ?? [];
        $templateId = intval($_POST['template_id'] ?? 0);

        if (!empty($taskIds) && $templateId) {
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $db->query(
                "UPDATE keyword_tasks SET template_id=? WHERE id IN ({$placeholders}) AND user_id=?",
                array_merge([$templateId], $taskIds, [$userId])
            );
            // 同步更新已导入到文章的关联数据（Fix #6）
            foreach ($taskIds as $taskId) {
                $task = $db->fetchOne("SELECT keyword FROM keyword_tasks WHERE id=? AND user_id=?", [$taskId, $userId]);
                if ($task) {
                    $db->query(
                        "UPDATE articles SET template_id=? WHERE user_id=? AND keyword=? AND status IN ('pending','generated')",
                        [$templateId, $userId, $task['keyword']]
                    );
                }
            }
            $message = '已为 ' . count($taskIds) . ' 个任务设置模板';
        }
    }

    // 单个任务绑定栏目
    if ($postAction === 'bind_single_category') {
        $taskId = intval($_POST['task_id'] ?? 0);
        $bindSiteId = intval($_POST['bind_site_id'] ?? 0);
        $bindCategoryId = $_POST['bind_category_id'] ?? '';

        if ($taskId && $bindSiteId) {
            $db->update('keyword_tasks', [
                'bound_site_id' => $bindSiteId,
                'bound_category_id' => $bindCategoryId,
            ], 'id=? AND user_id=?', [$taskId, $userId]);
            // 同步更新已导入到文章的关联数据
            $task = $db->fetchOne("SELECT keyword FROM keyword_tasks WHERE id=? AND user_id=?", [$taskId, $userId]);
            if ($task) {
                $db->query(
                    "UPDATE articles SET publish_site_id=?, publish_category_id=? WHERE user_id=? AND keyword=? AND status IN ('pending','generated')",
                    [$bindSiteId, $bindCategoryId, $userId, $task['keyword']]
                );
            }
            $message = '已更新绑定栏目';
        }
    }

    // 单个任务绑定模板
    if ($postAction === 'bind_single_template') {
        $taskId = intval($_POST['task_id'] ?? 0);
        $templateId = intval($_POST['template_id'] ?? 0);

        if ($taskId && $templateId) {
            $db->update('keyword_tasks', [
                'template_id' => $templateId,
            ], 'id=? AND user_id=?', [$taskId, $userId]);
            // 同步更新已导入到文章的关联数据
            $task = $db->fetchOne("SELECT keyword FROM keyword_tasks WHERE id=? AND user_id=?", [$taskId, $userId]);
            if ($task) {
                $db->query(
                    "UPDATE articles SET template_id=? WHERE user_id=? AND keyword=? AND status IN ('pending','generated')",
                    [$templateId, $userId, $task['keyword']]
                );
            }
            $message = '已更新绑定模板';
        }
    }
}

// 确保keyword_tasks有bound_site_id, bound_category_id, template_id字段
try {
    $db->fetchOne("SELECT bound_site_id FROM keyword_tasks LIMIT 1");
} catch (Exception $e) {
    try { $db->query("ALTER TABLE keyword_tasks ADD COLUMN bound_site_id INT UNSIGNED DEFAULT NULL"); } catch (Exception $e2) {}
    try { $db->query("ALTER TABLE keyword_tasks ADD COLUMN bound_category_id VARCHAR(50) DEFAULT NULL"); } catch (Exception $e2) {}
    try { $db->query("ALTER TABLE keyword_tasks ADD COLUMN template_id INT UNSIGNED DEFAULT NULL"); } catch (Exception $e2) {}
}

// 确保keyword_tasks有mining_type和competitor_url字段（新版本）
try {
    $db->fetchOne("SELECT mining_type FROM keyword_tasks LIMIT 1");
} catch (Exception $e) {
    try { $db->query("ALTER TABLE keyword_tasks ADD COLUMN mining_type ENUM('search_engine','competitor') NOT NULL DEFAULT 'search_engine' AFTER source_types"); } catch (Exception $e2) {}
    try { $db->query("ALTER TABLE keyword_tasks ADD COLUMN competitor_url VARCHAR(500) DEFAULT NULL AFTER mining_type"); } catch (Exception $e2) {}
}

// 任务列表
$pageNum = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

$tasks = $db->fetchAll(
    "SELECT * FROM keyword_tasks WHERE user_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?",
    [$userId, $perPage, $offset]
);
$total = $db->count('keyword_tasks', 'user_id=?', [$userId]);

// 当前任务结果
$currentTaskId = intval($_GET['task_id'] ?? 0);
$results = [];
$resultTotal = 0;
if ($currentTaskId) {
    $resultPage = max(1, intval($_GET['rpage'] ?? 1));
    $resultOffset = ($resultPage - 1) * 50;
    $results = $db->fetchAll(
        "SELECT * FROM keyword_results WHERE task_id=? ORDER BY id DESC LIMIT 50 OFFSET ?",
        [$currentTaskId, $resultOffset]
    );
    $resultTotal = $db->count('keyword_results', 'task_id=?', [$currentTaskId]);
}

// 获取站点列表（用于绑定栏目）
$sites = $db->fetchAll("SELECT * FROM sites WHERE user_id=? AND status=1", [$userId]);

// 获取模板列表
$templates = [];
try {
    $templates = $db->fetchAll("SELECT * FROM article_templates WHERE user_id=? ORDER BY is_default DESC, id ASC", [$userId]);
} catch (Exception $e) {}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-key"></i> 实时关键词挖掘</h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTaskModal">
            <i class="bi bi-plus-circle"></i> 添加挖词任务
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-auto-dismiss"><i class="bi bi-check-circle"></i> <?php echo e($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-auto-dismiss"><i class="bi bi-exclamation-circle"></i> <?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="row">
        <!-- 任务列表 -->
        <div class="col-lg-<?php echo $currentTaskId ? '5' : '12'; ?> mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-list-task"></i> 挖词任务</span>
                    <div>
                        <button class="btn btn-sm btn-outline-primary" onclick="batchBindCategory()" title="批量绑定栏目">
                            <i class="bi bi-link"></i> 批量绑定栏目
                        </button>
                        <button class="btn btn-sm btn-outline-info" onclick="batchSelectTemplate()" title="批量绑定模板">
                            <i class="bi bi-file-earmark-richtext"></i> 批量绑定模板
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th width="30"><input type="checkbox" id="selectAllTasks" title="全选"></th>
                                    <th>关键词</th>
                                    <th>来源</th>
                                    <th>绑定网站和栏目</th>
                                    <th>绑定模板</th>
                                    <th>找到</th>
                                    <th>状态</th>
                                    <th>时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($tasks)): ?>
                                    <tr><td colspan="10" class="text-center text-muted py-4">暂无任务</td></tr>
                                <?php else: ?>
                                    <?php foreach ($tasks as $task): ?>
                                    <tr class="<?php echo $currentTaskId == $task['id'] ? 'table-active' : ''; ?>">
                                        <td><input type="checkbox" class="task-checkbox" value="<?php echo $task['id']; ?>"></td>
                                        <td>
                                            <a href="?task_id=<?php echo $task['id']; ?>" class="text-decoration-none fw-bold">
                                                <?php echo e($task['keyword']); ?>
                                            </a>
                                            <?php if ($task['must_contain']): ?>
                                                <br><small class="text-muted">包含: <?php echo e($task['must_contain']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><small><?php
                                            $sourceNames = ['suggest' => '百度下拉词', 'related' => '百度相关词', 'also_search' => '大家还在搜', 'longtail' => '长尾词挖掘', 'bidwords' => '竞价词挖掘', 'indexwords' => '指数词挖掘', 'competitor' => '竞争对手'];
                                            if (($task['mining_type'] ?? 'search_engine') === 'competitor') {
                                                echo '<span class="badge bg-danger">竞争对手</span> ' . e($task['competitor_url'] ?? '');
                                            } else {
                                                $sources = explode(',', $task['source_types']);
                                                $display = [];
                                                foreach ($sources as $s) {
                                                    $display[] = $sourceNames[trim($s)] ?? trim($s);
                                                }
                                                echo e(implode(', ', $display));
                                            }
                                        ?></small></td>
                                        <td><?php
                                            if (!empty($task['bound_site_id'])) {
                                                $tSite = $db->fetchOne("SELECT name FROM sites WHERE id=?", [$task['bound_site_id']]);
                                                $tCatName = '';
                                                if (!empty($task['bound_category_id'])) {
                                                    $tCat = $db->fetchOne("SELECT category_name FROM site_categories WHERE site_id=? AND category_id=?", [$task['bound_site_id'], $task['bound_category_id']]);
                                                    $tCatName = $tCat ? $tCat['category_name'] : $task['bound_category_id'];
                                                }
                                                echo '<small class="text-primary"><i class="bi bi-globe"></i> ' . e(($tSite['name'] ?? '?') . ($tCatName ? ' > ' . $tCatName : '')) . '</small>';
                                            } else {
                                                echo '<small class="text-muted">-</small>';
                                            }
                                        ?></td>
                                        <td><?php
                                            if (!empty($task['template_id'])) {
                                                $tTpl = $db->fetchOne("SELECT name FROM article_templates WHERE id=?", [$task['template_id']]);
                                                echo '<small class="text-info"><i class="bi bi-file-earmark-richtext"></i> ' . e($tTpl['name'] ?? '-') . '</small>';
                                            } else {
                                                echo '<small class="text-muted">-</small>';
                                            }
                                        ?></td>
                                        <td><span class="badge bg-primary"><?php echo $task['found_count']; ?></span></td>
                                        <td><?php echo statusText($task['status']); ?></td>
                                        <td><small class="text-muted"><?php echo timeAgo($task['created_at']); ?></small></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?task_id=<?php echo $task['id']; ?>" class="btn btn-outline-info" title="查看结果">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <button class="btn btn-outline-primary" onclick="editTaskBindCategory(<?php echo $task['id']; ?>)" title="修改绑定栏目">
                                                    <i class="bi bi-link"></i>
                                                </button>
                                                <button class="btn btn-outline-warning" onclick="editTaskBindTemplate(<?php echo $task['id']; ?>)" title="修改绑定模板">
                                                    <i class="bi bi-file-earmark-richtext"></i>
                                                </button>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="import_to_articles">
                                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-success" title="导入到文章">
                                                        <i class="bi bi-arrow-right-circle"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="delete_task">
                                                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-danger" title="删除" onclick="return confirm('确认删除？')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
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

        <!-- 结果列表 -->
        <?php if ($currentTaskId): ?>
        <div class="col-lg-7 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-list-ul"></i> 挖掘结果 (共<?php echo $resultTotal; ?>个)</span>
                    <div>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="import_to_articles">
                            <input type="hidden" name="task_id" value="<?php echo $currentTaskId; ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="全部导入到文章">
                                <i class="bi bi-arrow-right-circle"></i> 全部导入到文章
                            </button>
                        </form>
                        <button class="btn btn-sm btn-outline-secondary" onclick="copyAllKeywords()" title="复制全部关键词">
                            <i class="bi bi-clipboard"></i> 复制全部
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0" id="keywordsTable">
                            <thead>
                                <tr>
                                    <th width="40">#</th>
                                    <th>关键词</th>
                                    <th>来源</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $idx => $row): ?>
                                <tr>
                                    <td><?php echo $idx + 1; ?></td>
                                    <td><?php echo e($row['keyword']); ?></td>
                                    <td><span class="badge bg-light text-dark"><?php
                                        $sourceNames = ['suggest' => '百度下拉词', 'related' => '百度相关词', 'also_search' => '大家还在搜', 'baidu' => '百度', 'longtail' => '长尾词挖掘', 'bidwords' => '竞价词挖掘', 'indexwords' => '指数词挖掘', 'competitor' => '竞争对手'];
                                        echo e($sourceNames[$row['source']] ?? $row['source']);
                                    ?></span></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-sm btn-outline-primary" onclick="APP.copyText('<?php echo e($row['keyword']); ?>')" title="复制关键词">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="generate_single_article">
                                                <input type="hidden" name="keyword" value="<?php echo e($row['keyword']); ?>">
                                                <input type="hidden" name="from_task_id" value="<?php echo $currentTaskId; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success" title="生成文章">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="delete_keyword_result">
                                                <input type="hidden" name="result_id" value="<?php echo $row['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="删除" onclick="return confirm('确认删除？')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php
            $resultTotalPages = ceil($resultTotal / 50);
            if ($resultTotalPages > 1) {
                echo '<nav><ul class="pagination pagination-sm">';
                for ($i = 1; $i <= $resultTotalPages; $i++) {
                    $active = $i == $resultPage ? ' active' : '';
                    echo "<li class=\"page-item{$active}\"><a class=\"page-link\" href=\"?task_id={$currentTaskId}&rpage={$i}\">{$i}</a></li>";
                }
                echo '</ul></nav>';
            }
            ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 添加挖词任务弹窗 -->
<div class="modal fade" id="addTaskModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> 添加挖词任务</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="addTaskForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_task">
                    
                    <!-- 挖词模式选择 -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">挖词模式</label>
                        <div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="mining_type" id="modeSearchEngine" value="search_engine" checked onchange="toggleMiningMode()">
                                <label class="form-check-label" for="modeSearchEngine">
                                    <i class="bi bi-search"></i> A. 挖掘搜索引擎
                                </label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="mining_type" id="modeCompetitor" value="competitor" onchange="toggleMiningMode()">
                                <label class="form-check-label" for="modeCompetitor">
                                    <i class="bi bi-globe"></i> B. 挖掘竞争对手
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- 搜索引擎模式区域 -->
                    <div id="searchEngineSection">
                        <div class="mb-3">
                            <label class="form-label">挖词来源</label>
                            <div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="source_types[]" value="suggest" checked>
                                    <label class="form-check-label">百度下拉词</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="source_types[]" value="related" checked>
                                    <label class="form-check-label">百度相关词</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="source_types[]" value="also_search" checked>
                                    <label class="form-check-label">大家还在搜</label>
                                </div>
                            </div>
                            <div class="mt-1">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="source_types[]" value="longtail">
                                    <label class="form-check-label">长尾词挖掘</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="source_types[]" value="bidwords">
                                    <label class="form-check-label">竞价词挖掘</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="source_types[]" value="indexwords">
                                    <label class="form-check-label">指数词挖掘</label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">关键词数量</label>
                            <input type="number" name="keyword_count" class="form-control" value="50" min="10" max="500">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">关键词/主词 <span class="text-danger">*</span></label>
                            <input type="text" name="keyword" class="form-control" placeholder="如：SWITCH游戏">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">必须包含词（选填，空格分隔）</label>
                            <input type="text" name="must_contain" class="form-control" placeholder="如：seo 文章 生成">
                        </div>
                    </div>

                    <!-- 竞争对手模式区域 -->
                    <div id="competitorSection" style="display:none">
                        <div class="mb-3">
                            <label class="form-label">关键词数量</label>
                            <input type="number" name="keyword_count_comp" class="form-control" value="50" min="10" max="500" id="keywordCountComp">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">竞争站网址 <span class="text-danger">*</span></label>
                            <input type="text" name="competitor_url" class="form-control" id="competitorUrlInput" placeholder="请输入竞争站网址，如 https://example.com">
                            <small class="form-text text-muted">系统将自动分析竞争站的标题、关键词、内容等，提取有流量的关键词</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary" id="btnStartMining"><i class="bi bi-search"></i> 开始挖词</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 批量绑定栏目弹窗 -->
<div class="modal fade" id="bindCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-link"></i> 批量绑定栏目</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bind_tasks_category">
                    <div id="bindTaskIds"></div>
                    <div class="mb-3">
                        <label class="form-label">选择网站</label>
                        <select name="bind_site_id" class="form-select" id="bindSiteSelect" onchange="loadBindCategories(this.value)" required>
                            <option value="">请选择</option>
                            <?php foreach ($sites as $site): ?>
                            <option value="<?php echo $site['id']; ?>"><?php echo e($site['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">选择栏目</label>
                        <select name="bind_category_id" class="form-select" id="bindCategorySelect">
                            <option value="">全部栏目</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 绑定</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 批量选择模板弹窗 -->
<div class="modal fade" id="selectTemplateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-richtext"></i> 批量选择模板</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bind_tasks_template">
                    <div id="templateTaskIds"></div>
                    <div class="mb-3">
                        <label class="form-label">选择模板</label>
                        <select name="template_id" class="form-select" required>
                            <option value="">请选择</option>
                            <?php foreach ($templates as $tpl): ?>
                            <option value="<?php echo $tpl['id']; ?>"><?php echo e($tpl['name']); ?><?php echo $tpl['is_default'] ? ' (默认)' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 确定</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 单个任务绑定栏目弹窗 -->
<div class="modal fade" id="singleBindCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-link"></i> 修改绑定栏目</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bind_single_category">
                    <input type="hidden" name="task_id" id="singleBindTaskId">
                    <div class="mb-3">
                        <label class="form-label">选择网站</label>
                        <select name="bind_site_id" class="form-select" id="singleBindSiteSelect" onchange="loadSingleBindCategories(this.value)" required>
                            <option value="">请选择</option>
                            <?php foreach ($sites as $site): ?>
                            <option value="<?php echo $site['id']; ?>"><?php echo e($site['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">选择栏目</label>
                        <select name="bind_category_id" class="form-select" id="singleBindCategorySelect">
                            <option value="">全部栏目</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 单个任务绑定模板弹窗 -->
<div class="modal fade" id="singleBindTemplateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-richtext"></i> 修改绑定模板</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="bind_single_template">
                    <input type="hidden" name="task_id" id="singleBindTplTaskId">
                    <div class="mb-3">
                        <label class="form-label">选择模板</label>
                        <select name="template_id" class="form-select" id="singleBindTplSelect" required>
                            <option value="">请选择</option>
                            <?php foreach ($templates as $tpl): ?>
                            <option value="<?php echo $tpl['id']; ?>"><?php echo e($tpl['name']); ?><?php echo $tpl['is_default'] ? ' (默认)' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraJs = '<script>
function copyAllKeywords() {
    const rows = document.querySelectorAll("#keywordsTable tbody tr");
    const keywords = [];
    rows.forEach(row => {
        const kw = row.cells[2]?.textContent.trim();
        if (kw) keywords.push(kw);
    });
    APP.copyText(keywords.join("\\n"));
}

// 切换挖词模式
function toggleMiningMode() {
    const isCompetitor = document.getElementById("modeCompetitor").checked;
    document.getElementById("searchEngineSection").style.display = isCompetitor ? "none" : "block";
    document.getElementById("competitorSection").style.display = isCompetitor ? "block" : "none";
}

// AJAX挖词 - Fix #11
document.getElementById("addTaskForm").addEventListener("submit", function(e) {
    e.preventDefault();
    const form = this;
    const btn = document.getElementById("btnStartMining");
    const isCompetitor = document.getElementById("modeCompetitor").checked;

    // 验证
    if (isCompetitor) {
        const url = document.getElementById("competitorUrlInput").value.trim();
        if (!url) {
            alert("请输入竞争站网址");
            return;
        }
        // 同步keyword_count
        const countComp = document.getElementById("keywordCountComp");
        if (countComp) {
            const mainCount = form.querySelector("input[name=keyword_count]");
            if (mainCount) mainCount.value = countComp.value;
        }
        // 竞争对手模式下设置keyword为URL
        const kwInput = form.querySelector("input[name=keyword]");
        if (kwInput) kwInput.value = url;
    } else {
        const kw = form.querySelector("input[name=keyword]").value.trim();
        if (!kw) {
            alert("请输入关键词");
            return;
        }
    }

    const formData = new FormData(form);
    btn.disabled = true;
    btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> 挖掘中...\';

    fetch(window.location.href, {
        method: "POST",
        body: formData
    })
    .then(r => r.text())
    .then(() => {
        btn.disabled = false;
        btn.innerHTML = \'<i class="bi bi-search"></i> 开始挖词\';
        bootstrap.Modal.getInstance(document.getElementById("addTaskModal")).hide();
        location.reload();
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = \'<i class="bi bi-search"></i> 开始挖词\';
        alert("请求失败: " + err.message);
    });
});

// 全选任务
document.getElementById("selectAllTasks")?.addEventListener("change", function() {
    document.querySelectorAll(".task-checkbox").forEach(cb => cb.checked = this.checked);
});

function getSelectedTaskIds() {
    const ids = [];
    document.querySelectorAll(".task-checkbox:checked").forEach(cb => ids.push(cb.value));
    return ids;
}

function batchBindCategory() {
    const ids = getSelectedTaskIds();
    if (ids.length === 0) {
        alert("请先选择挖词任务");
        return;
    }
    let html = "";
    ids.forEach(id => { html += \'<input type="hidden" name="task_ids[]" value="\' + id + \'">\'; });
    document.getElementById("bindTaskIds").innerHTML = html;
    new bootstrap.Modal(document.getElementById("bindCategoryModal")).show();
}

function batchSelectTemplate() {
    const ids = getSelectedTaskIds();
    if (ids.length === 0) {
        alert("请先选择挖词任务");
        return;
    }
    let html = "";
    ids.forEach(id => { html += \'<input type="hidden" name="task_ids[]" value="\' + id + \'">\'; });
    document.getElementById("templateTaskIds").innerHTML = html;
    new bootstrap.Modal(document.getElementById("selectTemplateModal")).show();
}

function loadBindCategories(siteId) {
    if (!siteId) {
        document.getElementById("bindCategorySelect").innerHTML = "<option value=\"\">全部栏目</option>";
        return;
    }
    fetch("/api/site.php?action=get_categories&site_id=" + siteId)
        .then(r => r.json())
        .then(data => {
            let html = "<option value=\"\">全部栏目</option>";
            if (data.success) {
                data.categories.forEach(cat => {
                    html += "<option value=\"" + cat.category_id + "\">" + cat.category_name + "</option>";
                });
            }
            document.getElementById("bindCategorySelect").innerHTML = html;
        });
}

// 单个任务 - 修改绑定栏目
function editTaskBindCategory(taskId) {
    document.getElementById("singleBindTaskId").value = taskId;
    document.getElementById("singleBindSiteSelect").value = "";
    document.getElementById("singleBindCategorySelect").innerHTML = "<option value=\"\">全部栏目</option>";
    new bootstrap.Modal(document.getElementById("singleBindCategoryModal")).show();
}

// 单个任务 - 修改绑定模板
function editTaskBindTemplate(taskId) {
    document.getElementById("singleBindTplTaskId").value = taskId;
    document.getElementById("singleBindTplSelect").value = "";
    new bootstrap.Modal(document.getElementById("singleBindTemplateModal")).show();
}

function loadSingleBindCategories(siteId) {
    if (!siteId) {
        document.getElementById("singleBindCategorySelect").innerHTML = "<option value=\"\">全部栏目</option>";
        return;
    }
    fetch("/api/site.php?action=get_categories&site_id=" + siteId)
        .then(r => r.json())
        .then(data => {
            let html = "<option value=\"\">全部栏目</option>";
            if (data.success) {
                data.categories.forEach(cat => {
                    html += "<option value=\"" + cat.category_id + "\">" + cat.category_name + "</option>";
                });
            }
            document.getElementById("singleBindCategorySelect").innerHTML = html;
        });
}
</script>';

require_once __DIR__ . '/../../includes/layout/footer.php';
?>
