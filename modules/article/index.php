<?php
/**
 * 生成文章 - 主页面
 */
$pageTitle = '生成文章';
$page = 'article';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// 确保articles表有publish_site_id和publish_category_id字段
try {
    $db->fetchOne("SELECT publish_site_id FROM articles LIMIT 1");
} catch (Exception $e) {
    try { $db->query("ALTER TABLE articles ADD COLUMN publish_site_id INT UNSIGNED DEFAULT NULL"); } catch (Exception $e2) {}
    try { $db->query("ALTER TABLE articles ADD COLUMN publish_category_id VARCHAR(50) DEFAULT NULL"); } catch (Exception $e2) {}
    try { $db->query("ALTER TABLE articles ADD COLUMN template_id INT UNSIGNED DEFAULT NULL"); } catch (Exception $e2) {}
}

// 确保articles表有publish_at字段（定时发布用）
try {
    $db->fetchOne("SELECT publish_at FROM articles LIMIT 1");
} catch (Exception $e) {
    try { $db->query("ALTER TABLE articles ADD COLUMN publish_at DATETIME DEFAULT NULL AFTER published_at"); } catch (Exception $e2) {}
}

// 确保article_templates表存在
try {
    $db->fetchOne("SELECT 1 FROM article_templates LIMIT 1");
} catch (Exception $e) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS article_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            article_type VARCHAR(20) DEFAULT 'short',
            custom_template TEXT,
            model VARCHAR(50) DEFAULT 'deepseek',
            api_key VARCHAR(500) DEFAULT '',
            api_endpoint VARCHAR(500) DEFAULT '',
            export_format VARCHAR(20) DEFAULT 'html',
            export_content_type VARCHAR(20) DEFAULT 'html',
            title_type VARCHAR(20) DEFAULT 'original',
            language VARCHAR(10) DEFAULT 'zh',
            sensitive_words TEXT,
            ad_paragraph_pos VARCHAR(30) DEFAULT '',
            ad_paragraph TEXT,
            ad_ending_pos VARCHAR(30) DEFAULT '',
            ad_ending TEXT,
            image_source VARCHAR(20) DEFAULT 'none',
            image_urls TEXT,
            image_position VARCHAR(30) DEFAULT '',
            image_max_count INT DEFAULT 2,
            custom_prompt TEXT,
            is_default TINYINT DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e2) {}
}

// 加载全局配置
$config = $db->fetchOne("SELECT * FROM global_config WHERE user_id=?", [$userId]);

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // 导入关键词
    if ($postAction === 'import_keywords') {
        $keywords = trim($_POST['keywords'] ?? '');
        $keywordList = array_filter(array_map('trim', explode("\n", $keywords)));
        
        $savedCount = 0;
        foreach ($keywordList as $kw) {
            if (empty($kw)) continue;
            $db->insert('articles', [
                'user_id' => $userId,
                'keyword' => $kw,
                'title' => $kw,
                'content' => '',
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $savedCount++;
        }
        
        writeLog('article', '导入关键词', "导入{$savedCount}个");
        $message = "成功导入 {$savedCount} 个关键词";
    }

    // 保存全局配置
    if ($postAction === 'save_config') {
        $configData = [
            'user_id' => $userId,
            'article_type' => $_POST['article_type'] ?? 'short',
            'custom_template' => $_POST['custom_template'] ?? '',
            'model' => $_POST['model'] ?? 'deepseek',
            'api_key' => $_POST['api_key'] ?? '',
            'api_endpoint' => $_POST['api_endpoint'] ?? '',
            'export_format' => $_POST['export_format'] ?? 'html',
            'export_content_type' => $_POST['export_content_type'] ?? 'html',
            'title_type' => $_POST['title_type'] ?? 'original',
            'language' => $_POST['language'] ?? 'zh',
            'sensitive_words' => $_POST['sensitive_words'] ?? '',
            'ad_paragraph_pos' => $_POST['ad_paragraph_pos'] ?? '',
            'ad_paragraph' => $_POST['ad_paragraph'] ?? '',
            'ad_ending_pos' => $_POST['ad_ending_pos'] ?? '',
            'ad_ending' => $_POST['ad_ending'] ?? '',
            'image_source' => $_POST['image_source'] ?? 'none',
            'image_urls' => $_POST['image_urls'] ?? '',
            'image_position' => $_POST['image_position'] ?? '',
            'image_max_count' => intval($_POST['image_max_count'] ?? 2),
            'site_id' => intval($_POST['site_id'] ?? 0),
            'category_id' => $_POST['category_id'] ?? '',
            'custom_prompt' => $_POST['custom_prompt'] ?? '',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $existing = $db->fetchOne("SELECT id FROM global_config WHERE user_id=?", [$userId]);
        if ($existing) {
            $db->update('global_config', $configData, 'user_id=?', [$userId]);
        } else {
            $db->insert('global_config', $configData);
        }
        $config = $configData;
        $message = '配置保存成功';
        writeLog('article', '保存配置');
    }

    // 开始生成
    if ($postAction === 'start_generate') {
        // 检查API Key
        $apiKey = $config['api_key'] ?? $_POST['api_key'] ?? '';
        if (empty($apiKey) && empty($_POST['api_key'])) {
            $error = '请先在全局配置中设置API Key';
        } else {
            $pendingArticles = $db->fetchAll(
                "SELECT * FROM articles WHERE user_id=? AND status='pending' LIMIT 50",
                [$userId]
            );

            if (empty($pendingArticles)) {
                $error = '没有待生成的文章，请先导入关键词';
            } else {
                // 启动后台生成（通过AJAX逐个生成）
                $message = '开始生成，共 ' . count($pendingArticles) . ' 篇文章待处理';
            }
        }
    }

    // 清空文章
    if ($postAction === 'clear_articles') {
        $db->delete('articles', 'user_id=? AND status IN ("pending","failed")', [$userId]);
        $message = '已清空待处理和失败的文章';
    }

    // 重置失败
    if ($postAction === 'reset_failed') {
        $db->update('articles', ['status' => 'pending', 'error_message' => null], 'user_id=? AND status="failed"', [$userId]);
        $message = '已重置失败文章';
    }

    // 批量设置栏目
    if ($postAction === 'batch_set_category') {
        $articleIds = $_POST['article_ids'] ?? [];
        $pubSiteId = intval($_POST['publish_site_id'] ?? 0);
        $pubCategoryId = $_POST['publish_category_id'] ?? '';

        if (!empty($articleIds) && $pubSiteId) {
            $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
            $db->query(
                "UPDATE articles SET publish_site_id=?, publish_category_id=? WHERE id IN ({$placeholders}) AND user_id=?",
                array_merge([$pubSiteId, $pubCategoryId], $articleIds, [$userId])
            );
            $message = '已设置 ' . count($articleIds) . ' 篇文章的发布目标';
        }
    }

    // 批量设置模板
    if ($postAction === 'batch_set_template') {
        $articleIds = $_POST['article_ids'] ?? [];
        $templateId = intval($_POST['template_id'] ?? 0);

        if (!empty($articleIds) && $templateId) {
            $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
            $db->query(
                "UPDATE articles SET template_id=? WHERE id IN ({$placeholders}) AND user_id=?",
                array_merge([$templateId], $articleIds, [$userId])
            );
            $message = '已设置 ' . count($articleIds) . ' 篇文章的模板';
        }
    }
}

// 获取站点列表（用于配置选择和批量设置）
$sites = $db->fetchAll("SELECT * FROM sites WHERE user_id=? AND status=1", [$userId]);

// 获取模板列表
$templates = [];
try {
    $templates = $db->fetchAll("SELECT * FROM article_templates WHERE user_id=? ORDER BY is_default DESC, id ASC", [$userId]);
} catch (Exception $e) {}

// 文章列表查询条件
$pageNum = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;
$statusFilter = $_GET['status'] ?? '';

$whereClause = "user_id=?";
$whereParams = [$userId];
if ($statusFilter) {
    $whereClause .= " AND status=?";
    $whereParams[] = $statusFilter;
}

// 总字数统计
$totalWordCount = $db->fetchColumn(
    "SELECT COALESCE(SUM(word_count),0) FROM articles WHERE {$whereClause}",
    $whereParams
);

// 文章列表
$articles = $db->fetchAll(
    "SELECT * FROM articles WHERE {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?",
    array_merge($whereParams, [$perPage, $offset])
);
$total = $db->fetchColumn(
    "SELECT COUNT(*) FROM articles WHERE {$whereClause}",
    $whereParams
);

// 统计
$stats = [
    'pending' => $db->count('articles', 'user_id=? AND status="pending"', [$userId]),
    'generating' => $db->count('articles', 'user_id=? AND status="generating"', [$userId]),
    'generated' => $db->count('articles', 'user_id=? AND status="generated"', [$userId]),
    'scheduled' => $db->count('articles', 'user_id=? AND status="scheduled"', [$userId]),
    'published' => $db->count('articles', 'user_id=? AND status="published"', [$userId]),
    'failed' => $db->count('articles', 'user_id=? AND status="failed"', [$userId]),
];
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-file-earmark-text"></i> 生成文章</h4>
        <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="bi bi-upload"></i> 导入关键词
            </button>
            <a href="/modules/user/packages.php" class="btn btn-outline-warning">
                <i class="bi bi-cart"></i> 套餐购买
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-auto-dismiss"><i class="bi bi-check-circle"></i> <?php echo e($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-auto-dismiss"><i class="bi bi-exclamation-circle"></i> <?php echo e($error); ?></div>
    <?php endif; ?>

    <!-- 统计 -->
    <div class="row mb-4">
        <div class="col">
            <div class="toolbar">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <span class="badge bg-secondary me-2"><i class="bi bi-fonts"></i> 总字数: <?php echo number_format($totalWordCount); ?></span>
                        <span class="badge bg-warning me-2">待生成: <?php echo $stats['pending']; ?></span>
                        <span class="badge bg-info me-2">生成中: <?php echo $stats['generating']; ?></span>
                        <span class="badge bg-primary me-2">已生成: <?php echo $stats['generated']; ?></span>
                        <span class="badge bg-secondary me-2">待发布: <?php echo $stats['scheduled']; ?></span>
                        <span class="badge bg-success me-2">已发布: <?php echo $stats['published']; ?></span>
                        <span class="badge bg-danger">失败: <?php echo $stats['failed']; ?></span>
                    </div>
                    <div class="col-auto ms-auto">
                        <button type="button" class="btn btn-success btn-sm" id="btnGenerate">
                                <i class="bi bi-play-circle"></i> 开始生成
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" id="btnPublish">
                                <i class="bi bi-send"></i> 开始发布
                            </button>
                        <button class="btn btn-outline-secondary btn-sm ms-1" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise"></i> 刷新
                        </button>
                        <button class="btn btn-outline-primary btn-sm ms-1" onclick="batchSetCategory()">
                            <i class="bi bi-link"></i> 批量设置栏目
                        </button>
                        <button class="btn btn-outline-info btn-sm ms-1" onclick="batchSetTemplate()">
                            <i class="bi bi-file-earmark-richtext"></i> 批量设置模板
                        </button>
                        <div class="btn-group btn-group-sm ms-1">
                            <a href="/api/article.php?action=export&format=html" class="btn btn-outline-info btn-sm"><i class="bi bi-download"></i> 导出HTML</a>
                            <a href="/api/article.php?action=export&format=txt" class="btn btn-outline-info btn-sm"><i class="bi bi-download"></i> 导出TXT</a>
                            <a href="/api/article.php?action=export&format=excel" class="btn btn-outline-info btn-sm"><i class="bi bi-download"></i> 导出Excel</a>
                        </div>
                        <form method="POST" class="d-inline ms-1">
                            <input type="hidden" name="action" value="reset_failed">
                            <button type="submit" class="btn btn-outline-warning btn-sm"><i class="bi bi-arrow-counterclockwise"></i> 重置失败</button>
                        </form>
                        <form method="POST" class="d-inline ms-1">
                            <input type="hidden" name="action" value="clear_articles">
                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('确认清空？')"><i class="bi bi-trash"></i> 清空文章</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 生成进度 -->
    <div id="generateProgress" style="display:none" class="mb-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>生成进度</span>
                    <span id="progressText">0 / 0</span>
                </div>
                <div class="progress">
                    <div id="progressBar" class="progress-bar" style="width:0%"></div>
                </div>
                <small id="progressStatus" class="text-muted mt-2 d-block"></small>
            </div>
        </div>
    </div>

    <!-- 发布队列状态 -->
    <?php if ($stats['scheduled'] > 0): ?>
    <div class="card mb-3" id="queueStatusCard">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-clock text-primary"></i>
                    <strong>发布队列：</strong>
                    <span id="queueTotal"><?php echo $stats['scheduled']; ?></span> 篇文章等待发布
                    <span id="queueReady" class="text-success ms-2"></span>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-primary" onclick="checkQueueStatus()">
                        <i class="bi bi-arrow-clockwise"></i> 刷新队列
                    </button>
                    <button class="btn btn-sm btn-success ms-1" id="btnProcessQueue" onclick="processQueue()">
                        <i class="bi bi-play-circle"></i> 立即处理队列
                    </button>
                </div>
            </div>
            <div id="queueDetail" class="mt-2" style="display:none"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 筛选 -->
    <div class="mb-3">
        <div class="btn-group btn-group-sm">
            <a href="?status=" class="btn btn-outline-secondary <?php echo !$statusFilter ? 'active' : ''; ?>">全部</a>
            <a href="?status=pending" class="btn btn-outline-warning <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">待生成</a>
            <a href="?status=generated" class="btn btn-outline-primary <?php echo $statusFilter === 'generated' ? 'active' : ''; ?>">已生成</a>
            <a href="?status=scheduled" class="btn btn-outline-secondary <?php echo $statusFilter === 'scheduled' ? 'active' : ''; ?>">待发布</a>
            <a href="?status=published" class="btn btn-outline-success <?php echo $statusFilter === 'published' ? 'active' : ''; ?>">已发布</a>
            <a href="?status=failed" class="btn btn-outline-danger <?php echo $statusFilter === 'failed' ? 'active' : ''; ?>">失败</a>
        </div>
    </div>

    <!-- 文章列表 -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th width="30"><input type="checkbox" id="selectAll"></th>
                            <th>关键词</th>
                            <th>标题</th>
                            <th>发布目标</th>
                            <th>模板</th>
                            <th>字数</th>
                            <th>图片</th>
                            <th>状态</th>
                            <th>发布时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($articles)): ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">暂无文章，请先导入关键词</td></tr>
                        <?php else: ?>
                            <?php foreach ($articles as $article): ?>
                            <tr>
                                <td><input type="checkbox" class="article-checkbox" value="<?php echo $article['id']; ?>"></td>
                                <td><span class="badge bg-light text-dark"><?php echo e($article['keyword']); ?></span></td>
                                <td><?php echo e(mb_substr($article['title'], 0, 40)); ?></td>
                                <td><?php
                                    if (!empty($article['publish_site_id'])) {
                                        $pubSite = $db->fetchOne("SELECT name FROM sites WHERE id=?", [$article['publish_site_id']]);
                                        $pubCatName = '';
                                        if (!empty($article['publish_category_id'])) {
                                            $pubCat = $db->fetchOne("SELECT category_name FROM site_categories WHERE site_id=? AND category_id=?", [$article['publish_site_id'], $article['publish_category_id']]);
                                            $pubCatName = $pubCat ? $pubCat['category_name'] : $article['publish_category_id'];
                                        }
                                        echo '<small>' . e(($pubSite['name'] ?? '?') . ($pubCatName ? ' > ' . $pubCatName : '')) . '</small>';
                                    } else {
                                        echo '<small class="text-muted">-</small>';
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
                                <td><?php echo $article['word_count']; ?>字</td>
                                <td><?php
                                    $imgCount = substr_count($article['content'] ?? '', '<img');
                                    echo $imgCount > 0 ? '<span class="badge bg-info">' . $imgCount . '</span>' : '<span class="text-muted">0</span>';
                                ?></td>
                                <td><?php echo statusText($article['status']); ?></td>
                                <td><small class="text-muted"><?php echo $article['published_at'] ? timeAgo($article['published_at']) : ($article['publish_at'] && $article['status'] === 'scheduled' ? '计划: ' . date('m-d H:i', strtotime($article['publish_at'])) : '-'); ?></small></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($article['status'] === 'generated'): ?>
                                            <button class="btn btn-outline-primary" onclick="previewArticle(<?php echo $article['id']; ?>)" title="预览">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($article['status'] === 'published'): ?>
                                            <button class="btn btn-outline-success" onclick="APP.copyText('<?php echo e($article['title']); ?>')" title="复制标题">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn btn-outline-danger" onclick="deleteArticle(<?php echo $article['id']; ?>)" title="删除">
                                            <i class="bi bi-trash"></i>
                                        </button>
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

<!-- 导入关键词弹窗 -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload"></i> 导入关键词/标题</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="import_keywords">
                    <div class="mb-3">
                        <label class="form-label">关键词/标题（一行一个）</label>
                        <textarea name="keywords" class="form-control" rows="10" placeholder="请输入关键词，一行一个&#10;例如：&#10;SEO优化技巧&#10;如何提高网站排名&#10;关键词挖掘方法"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">或导入TXT文件</label>
                        <input type="file" class="form-control" id="importFile" accept=".txt">
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

<!-- 全局配置弹窗 -->
<div class="modal fade" id="configModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-gear"></i> 全局配置</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_config">
                    
                    <div class="row">
                        <!-- 左列 -->
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="bi bi-file-text"></i> 文章设置</h6>
                            
                            <div class="mb-3">
                                <label class="form-label">文章类型</label>
                                <select name="article_type" class="form-select" id="articleType">
                                    <option value="short" <?php echo ($config['article_type'] ?? '') === 'short' ? 'selected' : ''; ?>>短文章（1000字左右）</option>
                                    <option value="long" <?php echo ($config['article_type'] ?? '') === 'long' ? 'selected' : ''; ?>>长文章（2000字左右）</option>
                                    <option value="custom" <?php echo ($config['article_type'] ?? '') === 'custom' ? 'selected' : ''; ?>>自定义提示/模板</option>
                                </select>
                            </div>

                            <div class="mb-3" id="customTemplateDiv" style="display:none">
                                <label class="form-label">自定义提示词</label>
                                <textarea name="custom_template" class="form-control" rows="3" placeholder="请输入自定义的写作要求"><?php echo e($config['custom_template'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">AI模型</label>
                                <select name="model" class="form-select" id="modelSelect">
                                    <?php
                                    // 从数据库加载模型（含内置+自定义）
                                    $aiModels = [];
                                    try {
                                        $aiModels = $db->fetchAll("SELECT * FROM ai_models WHERE status=1 ORDER BY is_builtin DESC, sort_order ASC, id ASC");
                                    } catch (Exception $e) {}
                                    if (empty($aiModels)) {
                                        // 回退到内置模型
                                        $aiModels = [
                                            ['model_key' => 'mimo', 'model_name' => 'MiMo'],
                                            ['model_key' => 'gpt', 'model_name' => 'GPT'],
                                            ['model_key' => 'deepseek', 'model_name' => 'DeepSeek'],
                                        ];
                                    }
                                    foreach ($aiModels as $m):
                                    ?>
                                    <option value="<?php echo e($m['model_key']); ?>" <?php echo ($config['model'] ?? '') === $m['model_key'] ? 'selected' : ''; ?>>
                                        <?php echo e($m['model_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (Auth::isAdmin()): ?>
                                <small class="form-text text-muted"><a href="/modules/admin/ai_models.php" target="_blank"><i class="bi bi-gear"></i> 管理模型</a></small>
                                <?php endif; ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">API Key</label>
                                <div class="input-group">
                                    <input type="password" name="api_key" id="apiKeyInput" class="form-control" value="<?php echo e($config['api_key'] ?? ''); ?>" placeholder="请输入对应模型的API Key">
                                    <button type="button" class="btn btn-outline-info" id="btnTestApi" onclick="testApiConnection()">
                                        <i class="bi bi-lightning"></i> 测试连接
                                    </button>
                                </div>
                                <div id="testApiResult" class="mt-1" style="display:none"></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">API Endpoint <small class="text-muted">（选填，留空使用默认）</small></label>
                                <input type="text" name="api_endpoint" id="apiEndpointInput" class="form-control" value="<?php echo e($config['api_endpoint'] ?? ''); ?>" placeholder="如: https://api.deepseek.com/v1/chat/completions">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">导出文章格式</label>
                                <select name="export_format" class="form-select">
                                    <option value="html" <?php echo ($config['export_format'] ?? '') === 'html' ? 'selected' : ''; ?>>HTML文件</option>
                                    <option value="txt" <?php echo ($config['export_format'] ?? '') === 'txt' ? 'selected' : ''; ?>>TXT文件</option>
                                    <option value="excel" <?php echo ($config['export_format'] ?? '') === 'excel' ? 'selected' : ''; ?>>Excel文件</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">导出内容格式</label>
                                <select name="export_content_type" class="form-select">
                                    <option value="html" <?php echo ($config['export_content_type'] ?? '') === 'html' ? 'selected' : ''; ?>>带HTML标签</option>
                                    <option value="text" <?php echo ($config['export_content_type'] ?? '') === 'text' ? 'selected' : ''; ?>>纯文本</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">导出标题</label>
                                <select name="title_type" class="form-select">
                                    <option value="original" <?php echo ($config['title_type'] ?? '') === 'original' ? 'selected' : ''; ?>>原标题</option>
                                    <option value="generate" <?php echo ($config['title_type'] ?? '') === 'generate' ? 'selected' : ''; ?>>生成标题</option>
                                    <option value="double" <?php echo ($config['title_type'] ?? '') === 'double' ? 'selected' : ''; ?>>双标题</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">生成语言</label>
                                <select name="language" class="form-select">
                                    <option value="zh" <?php echo ($config['language'] ?? '') === 'zh' ? 'selected' : ''; ?>>中文</option>
                                    <option value="en" <?php echo ($config['language'] ?? '') === 'en' ? 'selected' : ''; ?>>英文</option>
                                </select>
                            </div>
                        </div>

                        <!-- 右列 -->
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="bi bi-shield-check"></i> 内容处理</h6>

                            <div class="mb-3">
                                <label class="form-label">敏感词过滤</label>
                                <textarea name="sensitive_words" class="form-control" rows="3" placeholder="一行一个敏感词"><?php echo e($config['sensitive_words'] ?? ''); ?></textarea>
                            </div>

                            <h6 class="text-primary mt-4"><i class="bi bi-megaphone"></i> 段落广告</h6>

                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label">文章段落广告位置</label>
                                    <select name="ad_paragraph_pos" class="form-select">
                                        <option value="">不插入</option>
                                        <option value="before_first" <?php echo ($config['ad_paragraph_pos'] ?? '') === 'before_first' ? 'selected' : ''; ?>>第一段前</option>
                                        <option value="after_first" <?php echo ($config['ad_paragraph_pos'] ?? '') === 'after_first' ? 'selected' : ''; ?>>第一段后</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">文章末段广告位置</label>
                                    <select name="ad_ending_pos" class="form-select">
                                        <option value="">不插入</option>
                                        <option value="before_last" <?php echo ($config['ad_ending_pos'] ?? '') === 'before_last' ? 'selected' : ''; ?>>最后一段前</option>
                                        <option value="after_last" <?php echo ($config['ad_ending_pos'] ?? '') === 'after_last' ? 'selected' : ''; ?>>最后一段后</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">段落广告内容（一行一条，发布时随机选一条）</label>
                                <textarea name="ad_paragraph" class="form-control" rows="2" placeholder="可以是HTML标签或纯文本，一行一个"><?php echo e($config['ad_paragraph'] ?? ''); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">末段广告内容（一行一条）</label>
                                <textarea name="ad_ending" class="form-control" rows="2" placeholder="可以是HTML标签或纯文本，一行一个"><?php echo e($config['ad_ending'] ?? ''); ?></textarea>
                            </div>

                            <h6 class="text-primary mt-4"><i class="bi bi-image"></i> 插入图片</h6>

                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label">图片来源</label>
                                    <select name="image_source" class="form-select" id="imageSource">
                                        <option value="none" <?php echo ($config['image_source'] ?? '') === 'none' ? 'selected' : ''; ?>>不插入</option>
                                        <option value="web" <?php echo ($config['image_source'] ?? '') === 'web' ? 'selected' : ''; ?>>网络图片</option>
                                        <option value="ai" <?php echo ($config['image_source'] ?? '') === 'ai' ? 'selected' : ''; ?>>AI生成</option>
                                        <option value="custom" <?php echo ($config['image_source'] ?? '') === 'custom' ? 'selected' : ''; ?>>导入图片链接</option>
                                    </select>
                                </div>
                                <div class="col-3">
                                    <label class="form-label">插入规则</label>
                                    <select name="image_position" class="form-select">
                                        <option value="before_first" <?php echo ($config['image_position'] ?? '') === 'before_first' ? 'selected' : ''; ?>>第一段前</option>
                                        <option value="after_first" <?php echo ($config['image_position'] ?? '') === 'after_first' ? 'selected' : ''; ?>>第一段后</option>
                                        <option value="after_1" <?php echo ($config['image_position'] ?? '') === 'after_1' ? 'selected' : ''; ?>>1段后插入</option>
                                        <option value="after_2" <?php echo ($config['image_position'] ?? '') === 'after_2' ? 'selected' : ''; ?>>2段后插入</option>
                                        <option value="after_3" <?php echo ($config['image_position'] ?? '') === 'after_3' ? 'selected' : ''; ?>>3段后插入</option>
                                    </select>
                                </div>
                                <div class="col-3">
                                    <label class="form-label">最多数量</label>
                                    <select name="image_max_count" class="form-select">
                                        <option value="1" <?php echo ($config['image_max_count'] ?? 2) == 1 ? 'selected' : ''; ?>>1张</option>
                                        <option value="2" <?php echo ($config['image_max_count'] ?? 2) == 2 ? 'selected' : ''; ?>>2张</option>
                                        <option value="3" <?php echo ($config['image_max_count'] ?? 2) == 3 ? 'selected' : ''; ?>>3张</option>
                                        <option value="4" <?php echo ($config['image_max_count'] ?? 2) == 4 ? 'selected' : ''; ?>>4张</option>
                                        <option value="5" <?php echo ($config['image_max_count'] ?? 2) == 5 ? 'selected' : ''; ?>>5张</option>
                                        <option value="6" <?php echo ($config['image_max_count'] ?? 2) == 6 ? 'selected' : ''; ?>>6张</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3" id="imageUrlDiv" style="display:none">
                                <label class="form-label">图片链接（一行一个，发布时随机抽取）</label>
                                <textarea name="image_urls" class="form-control" rows="3" placeholder="https://example.com/image1.jpg&#10;https://example.com/image2.jpg"><?php echo e($config['image_urls'] ?? ''); ?></textarea>
                            </div>

                            <h6 class="text-primary mt-4"><i class="bi bi-brush"></i> 自定义提示</h6>

                            <div class="mb-3">
                                <label class="form-label">自定义提示词</label>
                                <textarea name="custom_prompt" class="form-control" rows="3" placeholder="输入额外的写作要求，会追加到系统提示词后"><?php echo e($config['custom_prompt'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 保存配置</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 批量设置栏目弹窗 -->
<div class="modal fade" id="batchCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-link"></i> 批量设置发布目标</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="batch_set_category">
                    <div id="batchArticleIds"></div>
                    <div class="mb-3">
                        <label class="form-label">选择网站</label>
                        <select name="publish_site_id" class="form-select" id="batchSiteSelect" onchange="loadBatchCategories(this.value)" required>
                            <option value="">请选择</option>
                            <?php foreach ($sites as $site): ?>
                            <option value="<?php echo $site['id']; ?>"><?php echo e($site['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">选择栏目</label>
                        <select name="publish_category_id" class="form-select" id="batchCategorySelect">
                            <option value="">全部栏目</option>
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

<!-- 批量设置模板弹窗 -->
<div class="modal fade" id="batchTemplateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-richtext"></i> 批量设置模板</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="batch_set_template">
                    <div id="batchTemplateArticleIds"></div>
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
// 测试API连接
function testApiConnection() {
    const model = document.querySelector("select[name=model]").value;
    const apiKey = document.getElementById("apiKeyInput").value.trim();
    const endpoint = document.getElementById("apiEndpointInput").value.trim();
    const resultDiv = document.getElementById("testApiResult");
    const btn = document.getElementById("btnTestApi");

    if (!apiKey) {
        resultDiv.style.display = "block";
        resultDiv.innerHTML = \'<span class="text-danger"><i class="bi bi-x-circle"></i> 请先输入API Key</span>\';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> 测试中...\';
    resultDiv.style.display = "block";
    resultDiv.innerHTML = \'<span class="text-muted"><i class="bi bi-hourglass-split"></i> 正在测试连接...</span>\';

    fetch("/api/article.php?action=test_connection", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({model: model, api_key: apiKey, endpoint: endpoint})
    })
    .then(r => {
        // 先获取原始文本，避免因Content-Type不对导致解析失败
        return r.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                // 非JSON响应，提取摘要
                const preview = text.substring(0, 200).replace(/<[^>]*>/g, \'\');
                throw new Error("服务器返回了非JSON响应: " + preview);
            }
        });
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = \'<i class="bi bi-lightning"></i> 测试连接\';
        if (data.success) {
            resultDiv.innerHTML = \'<span class="text-success"><i class="bi bi-check-circle"></i> \' + (data.message || "连接成功！") + \'</span>\';
        } else {
            resultDiv.innerHTML = \'<span class="text-danger"><i class="bi bi-x-circle"></i> \' + (data.message || "连接失败") + \'</span>\';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = \'<i class="bi bi-lightning"></i> 测试连接\';
        resultDiv.innerHTML = \'<span class="text-danger"><i class="bi bi-x-circle"></i> 请求失败: \' + err.message + \'</span>\';
    });
}

// 文件导入
document.getElementById("importFile").addEventListener("change", function(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        document.querySelector("textarea[name=keywords]").value = e.target.result;
    };
    reader.readAsText(file);
});

// 自定义模板显示
document.getElementById("articleType").addEventListener("change", function() {
    document.getElementById("customTemplateDiv").style.display = this.value === "custom" ? "block" : "none";
});
if (document.getElementById("articleType").value === "custom") {
    document.getElementById("customTemplateDiv").style.display = "block";
}

// 图片链接输入框显示
document.getElementById("imageSource").addEventListener("change", function() {
    document.getElementById("imageUrlDiv").style.display = this.value === "custom" ? "block" : "none";
});
if (document.getElementById("imageSource").value === "custom") {
    document.getElementById("imageUrlDiv").style.display = "block";
}

// 加载网站栏目
function loadSiteCategories(siteId) {
    if (!siteId) {
        document.getElementById("categorySelect").innerHTML = "<option value=\"\">请先选择网站</option>";
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
            document.getElementById("categorySelect").innerHTML = html;
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

// 删除文章
function deleteArticle(id) {
    if (!confirm("确认删除？")) return;
    fetch("/api/article.php?action=delete&id=" + id, {method:"POST"})
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.message);
        });
}

// 全选
document.getElementById("selectAll")?.addEventListener("change", function() {
    document.querySelectorAll(".article-checkbox").forEach(cb => cb.checked = this.checked);
});

// 后台生成 - 按钮直接绑定AJAX
document.getElementById("btnGenerate")?.addEventListener("click", function(e) {
    e.preventDefault();

    // 获取勾选的文章ID
    const checked = document.querySelectorAll(".article-checkbox:checked");
    const ids = [];
    checked.forEach(cb => ids.push(parseInt(cb.value)));

    const progressDiv = document.getElementById("generateProgress");
    progressDiv.style.display = "block";
    document.getElementById("progressStatus").textContent = "正在启动...";

    // 调用批量生成（服务端会循环处理所有文章，支持断点恢复）
    fetch("/api/article.php?action=batch_generate", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({ids: ids})
    })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.total > 0) {
                document.getElementById("progressStatus").textContent = "生成完成！共处理 " + data.total + " 篇";
                document.getElementById("progressStatus").className = "text-success mt-2 d-block";
                setTimeout(() => location.reload(), 2000);
            } else {
                alert(data.message || "没有待生成的文章");
                progressDiv.style.display = "none";
            }
        })
        .catch(err => {
            // 浏览器断开不影响服务端继续生成
            document.getElementById("progressStatus").textContent = "连接中断，服务端仍在生成中，刷新页面可查看进度";
            document.getElementById("progressStatus").className = "text-warning mt-2 d-block";
        });

    // 同时启动轮询，实时显示进度
    pollProgress();
});

// 开始发布
document.getElementById("btnPublish")?.addEventListener("click", function(e) {
    e.preventDefault();
    if (!confirm("确认发布已生成的文章到网站吗？\n\n如有发布间隔设置，文章将按设定时间排队发布。")) return;

    const btn = this;
    btn.disabled = true;
    btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> 处理中...\';

    fetch("/api/article.php?action=start_publish", {method:"POST"})
        .then(r => {
            return r.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error("服务器返回了非JSON响应: " + text.substring(0, 200).replace(/<[^>]*>/g, \'\'));
                }
            });
        })
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = \'<i class="bi bi-send"></i> 开始发布\';
            if (data.success) {
                alert(data.message || "操作完成");
                location.reload();
            } else {
                alert("发布失败: " + (data.message || "未知错误"));
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = \'<i class="bi bi-send"></i> 开始发布\';
            alert("请求失败: " + err.message);
        });
});

// 跟踪上一次的关键词，用于判断是否切换到新文章
let lastPolledKeyword = \'\';

function pollProgress(total) {
    fetch("/api/article.php?action=generate_status")
        .then(r => r.json())
        .then(data => {
            const done = data.done || 0;
            const t = data.total || total;
            const pct = t > 0 ? Math.round((done / t) * 100) : 0;
            document.getElementById("progressBar").style.width = pct + "%";
            document.getElementById("progressText").textContent = done + " / " + t;

            const currentKeyword = data.current || "";
            const currentError = data.current_error || "";

            // 切换到新文章时，清除上一条的错误信息
            if (currentKeyword !== lastPolledKeyword) {
                lastPolledKeyword = currentKeyword;
            }

            // 只显示当前文章的状态和错误
            let statusText = "当前: " + (currentKeyword || "处理中...");
            if (currentError) {
                statusText += " ⚠️ " + currentError;
                document.getElementById("progressStatus").className = "text-danger mt-2 d-block";
            } else {
                document.getElementById("progressStatus").className = "text-muted mt-2 d-block";
            }
            document.getElementById("progressStatus").textContent = statusText;
            
            if (currentKeyword !== "完成" && (done < t || data.current !== "完成")) {
                setTimeout(() => pollProgress(t), 3000);
            } else {
                document.getElementById("progressBar").style.width = "100%";
                const failedCount = (data.done || 0) - (data.done - (data.failed || 0));
                document.getElementById("progressStatus").textContent = "生成完成！";
                document.getElementById("progressStatus").className = "text-success mt-2 d-block";
                setTimeout(() => location.reload(), 2000);
            }
        })
        .catch(() => {
            setTimeout(() => pollProgress(total), 5000);
        });
}

// 批量设置栏目
function batchSetCategory() {
    const ids = [];
    document.querySelectorAll(".article-checkbox:checked").forEach(cb => ids.push(cb.value));
    if (ids.length === 0) {
        alert("请先选择文章");
        return;
    }
    let html = "";
    ids.forEach(id => { html += \'<input type="hidden" name="article_ids[]" value="\' + id + \'">\'; });
    document.getElementById("batchArticleIds").innerHTML = html;
    new bootstrap.Modal(document.getElementById("batchCategoryModal")).show();
}

function loadBatchCategories(siteId) {
    if (!siteId) {
        document.getElementById("batchCategorySelect").innerHTML = "<option value=\"\">全部栏目</option>";
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
            document.getElementById("batchCategorySelect").innerHTML = html;
        });
}

// 批量设置模板
function batchSetTemplate() {
    const ids = [];
    document.querySelectorAll(".article-checkbox:checked").forEach(cb => ids.push(cb.value));
    if (ids.length === 0) {
        alert("请先选择文章");
        return;
    }
    let html = "";
    ids.forEach(id => { html += \'<input type="hidden" name="article_ids[]" value="\' + id + \'">\'; });
    document.getElementById("batchTemplateArticleIds").innerHTML = html;
    new bootstrap.Modal(document.getElementById("batchTemplateModal")).show();
}

// 检查发布队列状态
function checkQueueStatus() {
    fetch("/api/article.php?action=queue_status")
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const readyEl = document.getElementById("queueReady");
                const totalEl = document.getElementById("queueTotal");
                const detailEl = document.getElementById("queueDetail");

                if (readyEl) {
                    readyEl.textContent = data.ready_count > 0 ? "(" + data.ready_count + " 篇已到发布时间)" : "";
                }
                if (totalEl) {
                    totalEl.textContent = data.total_scheduled;
                }

                if (data.queue && data.queue.length > 0 && detailEl) {
                    detailEl.style.display = "block";
                    let html = \'<table class="table table-sm mb-0"><thead><tr><th>关键词</th><th>计划发布时间</th></tr></thead><tbody>\';
                    data.queue.forEach(item => {
                        const pubTime = item.publish_at ? item.publish_at.substring(5, 16) : "-";
                        html += \'<tr><td><small>\' + (item.keyword || item.title || "-") + \'</small></td><td><small>\' + pubTime + \'</small></td></tr>\';
                    });
                    html += "</tbody></table>";
                    detailEl.innerHTML = html;
                }
            }
        })
        .catch(err => {
            console.error("获取队列状态失败:", err);
        });
}

// 手动处理发布队列
function processQueue() {
    const btn = document.getElementById("btnProcessQueue");
    if (!btn) return;
    btn.disabled = true;
    btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> 处理中...\';

    fetch("/api/article.php?action=process_queue&limit=5")
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = \'<i class="bi bi-play-circle"></i> 立即处理队列\';
            if (data.success) {
                if (data.processed > 0) {
                    alert("已处理 " + data.processed + " 篇文章，剩余 " + data.remaining + " 篇待发布");
                } else {
                    alert("没有到期需要发布的文章");
                }
                location.reload();
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

// 页面加载时检查队列状态
if (document.getElementById("queueStatusCard")) {
    checkQueueStatus();
}
</script>';

// 页面加载时自动恢复：如果有正在生成的文章，自动追加到extraJs
if ($stats['generating'] > 0) {
    $extraJs .= '<script>
    (function() {
        var generatingCount = ' . intval($stats['generating']) . ';
        var progressDiv = document.getElementById("generateProgress");
        if (progressDiv) {
            progressDiv.style.display = "block";
            document.getElementById("progressStatus").textContent = "检测到 " + generatingCount + " 篇正在生成的文章，自动恢复进度监控...";
            if (typeof pollProgress === "function") pollProgress();
        }
    })();
    </script>';
}

require_once __DIR__ . '/../../includes/layout/footer.php';
?>
