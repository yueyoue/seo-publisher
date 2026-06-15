<?php
/**
 * 模板管理 - 文章生成模板
 */
ob_start();
$pageTitle = '发布模板管理';
$page = 'site';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$message = '';
$error = '';

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

// 从global_config迁移默认模板（如果还没有模板）
$existingTemplates = $db->fetchAll("SELECT * FROM article_templates WHERE user_id=?", [$userId]);
if (empty($existingTemplates)) {
    $config = $db->fetchOne("SELECT * FROM global_config WHERE user_id=?", [$userId]);
    if ($config) {
        $db->insert('article_templates', [
            'user_id' => $userId,
            'name' => '默认模板',
            'article_type' => $config['article_type'] ?? 'short',
            'custom_template' => $config['custom_template'] ?? '',
            'model' => $config['model'] ?? 'deepseek',
            'api_key' => $config['api_key'] ?? '',
            'api_endpoint' => $config['api_endpoint'] ?? '',
            'export_format' => $config['export_format'] ?? 'html',
            'export_content_type' => $config['export_content_type'] ?? 'html',
            'title_type' => $config['title_type'] ?? 'original',
            'language' => $config['language'] ?? 'zh',
            'sensitive_words' => $config['sensitive_words'] ?? '',
            'ad_paragraph_pos' => $config['ad_paragraph_pos'] ?? '',
            'ad_paragraph' => $config['ad_paragraph'] ?? '',
            'ad_ending_pos' => $config['ad_ending_pos'] ?? '',
            'ad_ending' => $config['ad_ending'] ?? '',
            'image_source' => $config['image_source'] ?? 'none',
            'image_urls' => $config['image_urls'] ?? '',
            'image_position' => $config['image_position'] ?? '',
            'image_max_count' => intval($config['image_max_count'] ?? 2),
            'custom_prompt' => $config['custom_prompt'] ?? '',
            'is_default' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    } else {
        // 没有global_config，创建一个空的默认模板
        $db->insert('article_templates', [
            'user_id' => $userId,
            'name' => '默认模板',
            'is_default' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
    $existingTemplates = $db->fetchAll("SELECT * FROM article_templates WHERE user_id=?", [$userId]);
}

// 处理POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    // 保存模板
    if ($postAction === 'save_template') {
        $templateId = intval($_POST['template_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            $error = '请输入模板名称';
        } else {
            $data = [
                'name' => $name,
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
                'custom_prompt' => $_POST['custom_prompt'] ?? '',
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            if ($templateId > 0) {
                $db->update('article_templates', $data, 'id=? AND user_id=?', [$templateId, $userId]);
                $message = '模板已更新';
            } else {
                $data['user_id'] = $userId;
                $data['created_at'] = date('Y-m-d H:i:s');
                $db->insert('article_templates', $data);
                $message = '模板已创建';
            }
            writeLog('article', '保存模板', $name);
        }
    }

    // 删除模板
    if ($postAction === 'delete_template') {
        $templateId = intval($_POST['template_id'] ?? 0);
        $tpl = $db->fetchOne("SELECT * FROM article_templates WHERE id=? AND user_id=?", [$templateId, $userId]);
        if ($tpl && !$tpl['is_default']) {
            $db->delete('article_templates', 'id=? AND user_id=?', [$templateId, $userId]);
            $message = '模板已删除';
        } else {
            $error = '不能删除默认模板';
        }
    }

    // 设为默认
    if ($postAction === 'set_default') {
        $templateId = intval($_POST['template_id'] ?? 0);
        $db->update('article_templates', ['is_default' => 0], 'user_id=?', [$userId]);
        $db->update('article_templates', ['is_default' => 1], 'id=? AND user_id=?', [$templateId, $userId]);
        $message = '已设为默认模板';
    }

    // 刷新列表
    $existingTemplates = $db->fetchAll("SELECT * FROM article_templates WHERE user_id=? ORDER BY is_default DESC, id ASC", [$userId]);
}

// 获取AI模型列表
$aiModels = [];
try {
    $aiModels = $db->fetchAll("SELECT * FROM ai_models WHERE status=1 ORDER BY is_builtin DESC, sort_order ASC, id ASC");
} catch (Exception $e) {}
if (empty($aiModels)) {
    $aiModels = [
        ['model_key' => 'mimo', 'model_name' => 'MiMo'],
        ['model_key' => 'gpt', 'model_name' => 'GPT'],
        ['model_key' => 'deepseek', 'model_name' => 'DeepSeek'],
    ];
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-file-earmark-richtext"></i> 发布模板管理</h4>
        <div>
            <button class="btn btn-primary" onclick="showTemplateForm(0)">
                <i class="bi bi-plus-circle"></i> 新建模板
            </button>
            <a href="/modules/site/index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> 返回
            </a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-auto-dismiss"><i class="bi bi-check-circle"></i> <?php echo e($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-auto-dismiss"><i class="bi bi-exclamation-circle"></i> <?php echo e($error); ?></div>
    <?php endif; ?>

    <!-- 模板列表 -->
    <div class="row">
        <?php foreach ($existingTemplates as $tpl): ?>
        <div class="col-lg-4 col-md-6 mb-4">
            <div class="card h-100 <?php echo $tpl['is_default'] ? 'border-primary' : ''; ?>">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>
                        <?php echo e($tpl['name']); ?>
                        <?php if ($tpl['is_default']): ?>
                            <span class="badge bg-primary ms-1">默认</span>
                        <?php endif; ?>
                    </span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick="showTemplateForm(<?php echo $tpl['id']; ?>)" title="编辑">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn btn-outline-success" onclick="duplicateTemplate(<?php echo $tpl['id']; ?>)" title="复制">
                            <i class="bi bi-copy"></i>
                        </button>
                        <?php if (!$tpl['is_default']): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="set_default">
                            <input type="hidden" name="template_id" value="<?php echo $tpl['id']; ?>">
                            <button type="submit" class="btn btn-outline-info btn-sm" title="设为默认">
                                <i class="bi bi-star"></i>
                            </button>
                        </form>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="delete_template">
                            <input type="hidden" name="template_id" value="<?php echo $tpl['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm" title="删除" onclick="return confirm('确认删除此模板？')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <small class="text-muted d-block mb-1"><strong>模型:</strong> <?php echo e($tpl['model']); ?></small>
                    <small class="text-muted d-block mb-1"><strong>类型:</strong> <?php
                        $typeMap = ['short' => '短文章', 'long' => '长文章', 'custom' => '自定义'];
                        echo $typeMap[$tpl['article_type']] ?? $tpl['article_type'];
                    ?></small>
                    <small class="text-muted d-block mb-1"><strong>语言:</strong> <?php echo $tpl['language'] === 'en' ? '英文' : '中文'; ?></small>
                    <small class="text-muted d-block mb-1"><strong>标题:</strong> <?php
                        $titleMap = ['original' => '原标题', 'generate' => '生成标题', 'double' => '双标题'];
                        echo $titleMap[$tpl['title_type']] ?? $tpl['title_type'];
                    ?></small>
                    <small class="text-muted d-block"><strong>图片:</strong> <?php
                        $imgMap = ['none' => '不插入', 'web' => '网络图片', 'ai' => 'AI生成', 'custom' => '自定义'];
                        echo $imgMap[$tpl['image_source']] ?? $tpl['image_source'];
                    ?></small>
                </div>
                <div class="card-footer text-muted">
                    <small>更新: <?php echo timeAgo($tpl['updated_at']); ?></small>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 模板编辑弹窗 -->
<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="templateModalTitle"><i class="bi bi-file-earmark-richtext"></i> 编辑模板</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="templateForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_template">
                    <input type="hidden" name="template_id" id="tpl_id" value="0">

                    <div class="mb-3">
                        <label class="form-label">模板名称 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="tpl_name" class="form-control" required placeholder="如：SEO长文章模板">
                    </div>

                    <div class="row">
                        <!-- 左列 -->
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="bi bi-file-text"></i> 文章设置</h6>
                            
                            <div class="mb-3">
                                <label class="form-label">文章类型</label>
                                <select name="article_type" class="form-select" id="tpl_article_type">
                                    <option value="short">短文章（1000字左右）</option>
                                    <option value="long">长文章（2000字左右）</option>
                                    <option value="custom">自定义提示/模板</option>
                                </select>
                            </div>

                            <div class="mb-3" id="tplCustomTemplateDiv" style="display:none">
                                <label class="form-label">自定义提示词</label>
                                <textarea name="custom_template" id="tpl_custom_template" class="form-control" rows="3" placeholder="请输入自定义的写作要求"></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">AI模型</label>
                                <select name="model" class="form-select" id="tpl_model">
                                    <?php foreach ($aiModels as $m): ?>
                                    <option value="<?php echo e($m['model_key']); ?>"><?php echo e($m['model_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">API Key</label>
                                <div class="input-group">
                                    <input type="password" name="api_key" id="tpl_api_key" class="form-control" placeholder="留空则使用全局配置的Key">
                                    <button type="button" class="btn btn-outline-info" id="btnTestTplApi" onclick="testTplApiConnection()">
                                        <i class="bi bi-lightning"></i> 测试连接
                                    </button>
                                </div>
                                <div id="testTplApiResult" class="mt-1" style="display:none"></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">API Endpoint <small class="text-muted">（选填）</small></label>
                                <input type="text" name="api_endpoint" id="tpl_api_endpoint" class="form-control" placeholder="留空使用默认">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">导出文章格式</label>
                                <select name="export_format" class="form-select" id="tpl_export_format">
                                    <option value="html">HTML文件</option>
                                    <option value="txt">TXT文件</option>
                                    <option value="excel">Excel文件</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">导出内容格式</label>
                                <select name="export_content_type" class="form-select" id="tpl_export_content_type">
                                    <option value="html">带HTML标签</option>
                                    <option value="text">纯文本</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">导出标题</label>
                                <select name="title_type" class="form-select" id="tpl_title_type">
                                    <option value="original">原标题</option>
                                    <option value="generate">生成标题</option>
                                    <option value="double">双标题</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">生成语言</label>
                                <select name="language" class="form-select" id="tpl_language">
                                    <option value="zh">中文</option>
                                    <option value="en">英文</option>
                                </select>
                            </div>
                        </div>

                        <!-- 右列 -->
                        <div class="col-md-6">
                            <h6 class="text-primary"><i class="bi bi-shield-check"></i> 内容处理</h6>

                            <div class="mb-3">
                                <label class="form-label">敏感词过滤</label>
                                <textarea name="sensitive_words" id="tpl_sensitive_words" class="form-control" rows="3" placeholder="一行一个敏感词"></textarea>
                            </div>

                            <h6 class="text-primary mt-4"><i class="bi bi-megaphone"></i> 段落广告</h6>

                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label">段落广告位置</label>
                                    <select name="ad_paragraph_pos" class="form-select" id="tpl_ad_paragraph_pos">
                                        <option value="">不插入</option>
                                        <option value="before_first">第一段前</option>
                                        <option value="after_first">第一段后</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">末段广告位置</label>
                                    <select name="ad_ending_pos" class="form-select" id="tpl_ad_ending_pos">
                                        <option value="">不插入</option>
                                        <option value="before_last">最后一段前</option>
                                        <option value="after_last">最后一段后</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">段落广告内容</label>
                                <textarea name="ad_paragraph" id="tpl_ad_paragraph" class="form-control" rows="2" placeholder="一行一条"></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">末段广告内容</label>
                                <textarea name="ad_ending" id="tpl_ad_ending" class="form-control" rows="2" placeholder="一行一条"></textarea>
                            </div>

                            <h6 class="text-primary mt-4"><i class="bi bi-image"></i> 插入图片</h6>

                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label">图片来源</label>
                                    <select name="image_source" class="form-select" id="tpl_image_source">
                                        <option value="none">不插入</option>
                                        <option value="web">网络图片</option>
                                        <option value="ai">AI生成</option>
                                        <option value="custom">导入图片链接</option>
                                    </select>
                                </div>
                                <div class="col-3">
                                    <label class="form-label">插入规则</label>
                                    <select name="image_position" class="form-select" id="tpl_image_position">
                                        <option value="before_first">第一段前</option>
                                        <option value="after_first">第一段后</option>
                                        <option value="after_1">1段后插入</option>
                                        <option value="after_2">2段后插入</option>
                                        <option value="after_3">3段后插入</option>
                                    </select>
                                </div>
                                <div class="col-3">
                                    <label class="form-label">最多数量</label>
                                    <select name="image_max_count" class="form-select" id="tpl_image_max_count">
                                        <option value="1">1张</option>
                                        <option value="2">2张</option>
                                        <option value="3">3张</option>
                                        <option value="4">4张</option>
                                        <option value="5">5张</option>
                                        <option value="6">6张</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-3" id="tplImageUrlDiv" style="display:none">
                                <label class="form-label">图片链接（一行一个）</label>
                                <textarea name="image_urls" id="tpl_image_urls" class="form-control" rows="3" placeholder="https://example.com/image1.jpg"></textarea>
                            </div>

                            <h6 class="text-primary mt-4"><i class="bi bi-brush"></i> 自定义提示</h6>

                            <div class="mb-3">
                                <label class="form-label">自定义提示词</label>
                                <textarea name="custom_prompt" id="tpl_custom_prompt" class="form-control" rows="3" placeholder="额外的写作要求"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 保存模板</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// 模板数据JSON供JS使用
$templatesJson = json_encode($existingTemplates, JSON_UNESCAPED_UNICODE);

$extraJs = '<script>
const templates = ' . $templatesJson . ';

// 显示模板表单
function showTemplateForm(templateId) {
    const modal = new bootstrap.Modal(document.getElementById("templateModal"));
    
    if (templateId === 0) {
        // 新建
        document.getElementById("templateModalTitle").innerHTML = \'<i class="bi bi-plus-circle"></i> 新建模板\';
        document.getElementById("tpl_id").value = 0;
        document.getElementById("tpl_name").value = "";
        resetTemplateForm();
    } else {
        // 编辑
        const tpl = templates.find(t => t.id == templateId);
        if (!tpl) return;
        document.getElementById("templateModalTitle").innerHTML = \'<i class="bi bi-pencil"></i> 编辑模板\';
        fillTemplateForm(tpl);
        document.getElementById("tpl_id").value = tpl.id;
    }
    modal.show();
}

// 复制模板
function duplicateTemplate(templateId) {
    const tpl = templates.find(t => t.id == templateId);
    if (!tpl) return;
    document.getElementById("templateModalTitle").innerHTML = \'<i class="bi bi-copy"></i> 复制模板\';
    document.getElementById("tpl_id").value = 0;
    document.getElementById("tpl_name").value = tpl.name + " (副本)";
    fillTemplateForm(tpl);
    new bootstrap.Modal(document.getElementById("templateModal")).show();
}

function fillTemplateForm(tpl) {
    // tpl_id 由调用方设置（编辑=原ID，复制=0）
    document.getElementById("tpl_name").value = tpl.name || "";
    document.getElementById("tpl_article_type").value = tpl.article_type || "short";
    document.getElementById("tpl_custom_template").value = tpl.custom_template || "";
    document.getElementById("tpl_model").value = tpl.model || "deepseek";
    document.getElementById("tpl_api_key").value = tpl.api_key || "";
    document.getElementById("tpl_api_endpoint").value = tpl.api_endpoint || "";
    document.getElementById("tpl_export_format").value = tpl.export_format || "html";
    document.getElementById("tpl_export_content_type").value = tpl.export_content_type || "html";
    document.getElementById("tpl_title_type").value = tpl.title_type || "original";
    document.getElementById("tpl_language").value = tpl.language || "zh";
    document.getElementById("tpl_sensitive_words").value = tpl.sensitive_words || "";
    document.getElementById("tpl_ad_paragraph_pos").value = tpl.ad_paragraph_pos || "";
    document.getElementById("tpl_ad_paragraph").value = tpl.ad_paragraph || "";
    document.getElementById("tpl_ad_ending_pos").value = tpl.ad_ending_pos || "";
    document.getElementById("tpl_ad_ending").value = tpl.ad_ending || "";
    document.getElementById("tpl_image_source").value = tpl.image_source || "none";
    document.getElementById("tpl_image_urls").value = tpl.image_urls || "";
    document.getElementById("tpl_image_position").value = tpl.image_position || "";
    document.getElementById("tpl_image_max_count").value = tpl.image_max_count || 2;
    document.getElementById("tpl_custom_prompt").value = tpl.custom_prompt || "";
    
    // 触发联动显示
    document.getElementById("tplCustomTemplateDiv").style.display = tpl.article_type === "custom" ? "block" : "none";
    document.getElementById("tplImageUrlDiv").style.display = tpl.image_source === "custom" ? "block" : "none";
}

function resetTemplateForm() {
    document.getElementById("tpl_article_type").value = "short";
    document.getElementById("tpl_custom_template").value = "";
    document.getElementById("tpl_model").value = "deepseek";
    document.getElementById("tpl_api_key").value = "";
    document.getElementById("tpl_api_endpoint").value = "";
    document.getElementById("tpl_export_format").value = "html";
    document.getElementById("tpl_export_content_type").value = "html";
    document.getElementById("tpl_title_type").value = "original";
    document.getElementById("tpl_language").value = "zh";
    document.getElementById("tpl_sensitive_words").value = "";
    document.getElementById("tpl_ad_paragraph_pos").value = "";
    document.getElementById("tpl_ad_paragraph").value = "";
    document.getElementById("tpl_ad_ending_pos").value = "";
    document.getElementById("tpl_ad_ending").value = "";
    document.getElementById("tpl_image_source").value = "none";
    document.getElementById("tpl_image_urls").value = "";
    document.getElementById("tpl_image_position").value = "";
    document.getElementById("tpl_image_max_count").value = 2;
    document.getElementById("tpl_custom_prompt").value = "";
    document.getElementById("tplCustomTemplateDiv").style.display = "none";
    document.getElementById("tplImageUrlDiv").style.display = "none";
}

// 联动事件
document.getElementById("tpl_article_type").addEventListener("change", function() {
    document.getElementById("tplCustomTemplateDiv").style.display = this.value === "custom" ? "block" : "none";
});
document.getElementById("tpl_image_source").addEventListener("change", function() {
    document.getElementById("tplImageUrlDiv").style.display = this.value === "custom" ? "block" : "none";
});

// 测试模板API连接
function testTplApiConnection() {
    const model = document.getElementById("tpl_model").value;
    const apiKey = document.getElementById("tpl_api_key").value.trim();
    const endpoint = document.getElementById("tpl_api_endpoint").value.trim();
    const resultDiv = document.getElementById("testTplApiResult");
    const btn = document.getElementById("btnTestTplApi");

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
    .then(r => r.text().then(text => {
        try { return JSON.parse(text); } catch (e) {
            throw new Error("服务器返回了非JSON响应: " + text.substring(0, 200).replace(/<[^>]*>/g, \'\'));
        }
    }))
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
</script>';

require_once __DIR__ . '/../../includes/layout/footer.php';
?>
