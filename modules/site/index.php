<?php
/**
 * 站点管理 - 列表 & 添加
 */
ob_start();
$pageTitle = '站点管理';
$page = 'site';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// 确保site_publish_settings表存在
try {
    $db->fetchOne("SELECT 1 FROM site_publish_settings LIMIT 1");
} catch (Exception $e) {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS site_publish_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            site_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            publish_time VARCHAR(50) DEFAULT '',
            publish_interval INT DEFAULT 0,
            random_interval TINYINT DEFAULT 0,
            daily_max INT DEFAULT 0,
            created_at DATETIME,
            updated_at DATETIME
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e2) {}
}

// 确保random_interval字段存在
try {
    $db->fetchOne("SELECT random_interval FROM site_publish_settings LIMIT 1");
} catch (Exception $e) {
    try { $db->query("ALTER TABLE site_publish_settings ADD COLUMN random_interval TINYINT DEFAULT 0 AFTER publish_interval"); } catch (Exception $e2) {}
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'add_site') {
        $name = trim($_POST['name'] ?? '');
        $siteType = $_POST['site_type'] ?? 'wordpress';
        $bindType = $_POST['bind_type'] ?? 'account';
        $domain = rtrim(trim($_POST['domain'] ?? ''), '/');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($name) || empty($domain) || empty($username) || empty($password)) {
            $error = '请填写所有必填项';
        } elseif (!preg_match('/^https?:\/\//', $domain)) {
            $error = '网站域名必须包含http://或https://';
        } else {
            $db->insert('sites', [
                'user_id' => $userId,
                'name' => $name,
                'site_type' => $siteType,
                'bind_type' => $bindType,
                'domain' => $domain,
                'username' => $username,
                'password' => $password,
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            writeLog('site', '添加站点', $name);
            header('Location: /modules/site/index.php?msg=添加成功');
            exit;
        }
    }

    // 处理编辑站点
    if ($postAction === 'edit_site') {
        $siteId = intval($_POST['site_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $siteType = $_POST['site_type'] ?? 'wordpress';
        $bindType = $_POST['bind_type'] ?? 'account';
        $domain = rtrim(trim($_POST['domain'] ?? ''), '/');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($name) || empty($domain) || empty($username)) {
            $error = '请填写所有必填项';
        } elseif (!preg_match('/^https?:\/\//', $domain)) {
            $error = '网站域名必须包含http://或https://';
        } else {
            $updateData = [
                'name' => $name,
                'site_type' => $siteType,
                'bind_type' => $bindType,
                'domain' => $domain,
                'username' => $username,
            ];
            // 密码不为空时才更新
            if (!empty($password)) {
                $updateData['password'] = $password;
            }
            $db->update('sites', $updateData, 'id=? AND user_id=?', [$siteId, $userId]);
            writeLog('site', '编辑站点', $name);
            header('Location: /modules/site/index.php?msg=修改成功');
            exit;
        }
    }

    if ($postAction === 'delete_site') {
        $siteId = intval($_POST['site_id'] ?? 0);
        $db->delete('sites', 'id=? AND user_id=?', [$siteId, $userId]);
        writeLog('site', '删除站点', "ID:{$siteId}");
        header('Location: /modules/site/index.php?msg=删除成功');
        exit;
    }

    // 保存发布设置
    if ($postAction === 'save_publish_settings') {
        $siteId = intval($_POST['site_id'] ?? 0);
        $publishTime = trim($_POST['publish_time'] ?? '');
        $publishInterval = intval($_POST['publish_interval'] ?? 0);
        $randomInterval = isset($_POST['random_interval']) ? 1 : 0;
        $dailyMax = intval($_POST['daily_max'] ?? 0);

        $existing = $db->fetchOne("SELECT id FROM site_publish_settings WHERE site_id=? AND user_id=?", [$siteId, $userId]);
        if ($existing) {
            $db->update('site_publish_settings', [
                'publish_time' => $publishTime,
                'publish_interval' => $publishInterval,
                'random_interval' => $randomInterval,
                'daily_max' => $dailyMax,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'site_id=? AND user_id=?', [$siteId, $userId]);
        } else {
            $db->insert('site_publish_settings', [
                'site_id' => $siteId,
                'user_id' => $userId,
                'publish_time' => $publishTime,
                'publish_interval' => $publishInterval,
                'random_interval' => $randomInterval,
                'daily_max' => $dailyMax,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        writeLog('site', '保存发布设置', "站点ID:{$siteId}");
        $message = '发布设置保存成功';
    }

    // 同步栏目和测试发布已移至API处理
}

// 获取站点列表
$sites = $db->fetchAll("SELECT * FROM sites WHERE user_id=? ORDER BY created_at DESC", [$userId]);

// 获取栏目（如果有site_id参数）
$categories = [];
$currentSiteId = intval($_GET['site_id'] ?? 0);
if ($currentSiteId) {
    $categories = $db->fetchAll("SELECT * FROM site_categories WHERE site_id=? ORDER BY id", [$currentSiteId]);
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-globe"></i> 站点管理</h4>
        <div>
            <a href="/modules/article/templates.php" class="btn btn-outline-info me-1">
                <i class="bi bi-file-earmark-richtext"></i> 发布模板管理
            </a>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSiteModal">
                <i class="bi bi-plus-circle"></i> 添加网站
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-auto-dismiss"><i class="bi bi-check-circle"></i> <?php echo e($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-auto-dismiss"><i class="bi bi-exclamation-circle"></i> <?php echo e($error); ?></div>
    <?php endif; ?>

    <!-- 站点列表 -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>域名</th>
                            <th>网站名称</th>
                            <th>网站类型</th>
                            <th>发布时间</th>
                            <th>状态</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sites)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">暂无站点，请先添加网站</td></tr>
                        <?php else: ?>
                            <?php foreach ($sites as $site): ?>
                            <tr>
                                <td>
                                    <a href="javascript:void(0)" onclick="showCategories(<?php echo $site['id']; ?>)" class="text-decoration-none">
                                        <?php echo e($site['domain']); ?>
                                    </a>
                                </td>
                                <td><?php echo e($site['name']); ?></td>
                                <td><span class="badge bg-info"><?php echo strtoupper($site['site_type']); ?></span></td>
                                <td>
                                    <?php if ($site['last_publish']): ?>
                                        <small class="text-muted">上次: <?php echo timeAgo($site['last_publish']); ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">未发布</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $site['status'] ? '<span class="badge bg-success">正常</span>' : '<span class="badge bg-secondary">禁用</span>'; ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-warning" onclick="editSite(<?php echo $site['id']; ?>, '<?php echo e($site['name']); ?>', '<?php echo e($site['site_type']); ?>', '<?php echo e($site['bind_type']); ?>', '<?php echo e($site['domain']); ?>', '<?php echo e($site['username']); ?>')" title="编辑">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-info" onclick="showCategories(<?php echo $site['id']; ?>)" title="同步栏目">
                                            <i class="bi bi-arrow-repeat"></i>
                                        </button>
                                        <button class="btn btn-outline-success" onclick="testPublish(<?php echo $site['id']; ?>, event)" title="测试发布">
                                            <i class="bi bi-send"></i>
                                        </button>
                                        <button class="btn btn-outline-primary" onclick="showPublishSettings(<?php echo $site['id']; ?>, '<?php echo e($site['name']); ?>')" title="发布设置">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                        <a href="/modules/site/logs.php?site_id=<?php echo $site['id']; ?>" class="btn btn-outline-secondary" title="发布日志">
                                            <i class="bi bi-journal-text"></i>
                                        </a>
                                        <button class="btn btn-outline-danger" onclick="deleteSite(<?php echo $site['id']; ?>)" title="删除">
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
</div>

<!-- 添加网站弹窗 -->
<div class="modal fade" id="addSiteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> 添加网站</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_site">
                    <div class="mb-3">
                        <label class="form-label">网站名称 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="如：我的博客">
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">网站类型</label>
                            <select name="site_type" class="form-select">
                                <option value="wordpress">WordPress</option>
                                <option value="empirecms" disabled>帝国CMS (即将支持)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">绑定类型</label>
                            <select name="bind_type" class="form-select">
                                <option value="account">账户绑定</option>
                                <option value="cookie" disabled>Cookie绑定 (即将支持)</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">网站域名 <span class="text-danger">*</span></label>
                        <input type="url" name="domain" class="form-control" required placeholder="https://example.com">
                        <small class="form-text text-muted">注意填写http://或https://</small>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">网站账号 <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">网站密码 <span class="text-danger">*</span></label>
                            <input type="password" name="password" class="form-control" required>
                            <small class="form-text text-muted">WordPress需使用「应用程序密码」，请到WP后台→用户→应用程序密码中生成</small>
                        </div>
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

<!-- 编辑网站弹窗 -->
<div class="modal fade" id="editSiteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil"></i> 编辑网站</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_site">
                    <input type="hidden" name="site_id" id="edit-site-id">
                    <div class="mb-3">
                        <label class="form-label">网站名称 <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit-site-name" class="form-control" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">网站类型</label>
                            <select name="site_type" id="edit-site-type" class="form-select">
                                <option value="wordpress">WordPress</option>
                                <option value="empirecms" disabled>帝国CMS (即将支持)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">绑定类型</label>
                            <select name="bind_type" id="edit-bind-type" class="form-select">
                                <option value="account">账户绑定</option>
                                <option value="cookie" disabled>Cookie绑定 (即将支持)</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">网站域名 <span class="text-danger">*</span></label>
                        <input type="url" name="domain" id="edit-site-domain" class="form-control" required>
                        <small class="form-text text-muted">注意填写http://或https://</small>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">网站账号 <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="edit-site-username" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">网站密码</label>
                            <input type="password" name="password" id="edit-site-password" class="form-control" placeholder="留空则不修改">
                            <small class="form-text text-muted">WordPress需使用「应用程序密码」</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 保存修改</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 栏目同步弹窗 -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-folder"></i> 网站栏目</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height:60vh;overflow-y:auto">
                <div id="categoryContent">
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary"></div>
                        <p class="mt-2">正在同步栏目...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                <button type="button" class="btn btn-primary" id="btnSyncCategories" onclick="syncCategories()">
                    <i class="bi bi-arrow-repeat"></i> 同步栏目
                </button>
            </div>
        </div>
    </div>
</div>

<!-- 发布日志弹窗 -->
<div class="modal fade" id="logModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-journal-text"></i> 发布日志</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="logContent"></div>
            </div>
        </div>
    </div>
</div>

<!-- 发布设置弹窗 -->
<div class="modal fade" id="publishSettingsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clock-history"></i> 发布设置 - <span id="publishSettingsSiteName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_publish_settings">
                    <input type="hidden" name="site_id" id="publishSettingsSiteId">
                    <div class="mb-3">
                        <label class="form-label">发布时间设置</label>
                        <input type="text" name="publish_time" id="publishTime" class="form-control" placeholder="如: 08:00,12:00,18:00（逗号分隔多个时间点）">
                        <small class="form-text text-muted">设置每天固定的发布时间点，留空则不限制</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">发布间隔（分钟）</label>
                        <input type="number" name="publish_interval" id="publishInterval" class="form-control" value="0" min="0">
                        <small class="form-text text-muted">每篇文章之间的间隔时间，0表示不间隔</small>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="random_interval" id="randomInterval" value="1">
                            <label class="form-check-label" for="randomInterval">随机时间间隔</label>
                        </div>
                        <small class="form-text text-muted">勾选后，每篇文章的发布间隔将在设定值以内随机（如设定10分钟，则每篇间隔为0~10分钟的随机数）</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">每日最多发布</label>
                        <input type="number" name="daily_max" id="dailyMax" class="form-control" value="0" min="0">
                        <small class="form-text text-muted">每天最多发布多少篇文章，0表示不限制</small>
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
// 额外的modal backdrop清理 - 防止暗色遮罩残留
document.addEventListener("hidden.bs.modal", function(e) {
    // 确保所有backdrop被移除
    var backdrops = document.querySelectorAll(".modal-backdrop");
    backdrops.forEach(function(el) { el.remove(); });
    document.body.classList.remove("modal-open");
    document.body.style.removeProperty("padding-right");
    document.body.style.removeProperty("overflow");
});

let currentSiteId = 0;

function editSite(id, name, siteType, bindType, domain, username) {
    document.getElementById("edit-site-id").value = id;
    document.getElementById("edit-site-name").value = name;
    document.getElementById("edit-site-type").value = siteType;
    document.getElementById("edit-bind-type").value = bindType;
    document.getElementById("edit-site-domain").value = domain;
    document.getElementById("edit-site-username").value = username;
    document.getElementById("edit-site-password").value = "";
    const modal = new bootstrap.Modal(document.getElementById("editSiteModal"));
    modal.show();
}

function showCategories(siteId) {
    currentSiteId = siteId;
    const modal = new bootstrap.Modal(document.getElementById("categoryModal"));
    document.getElementById("categoryContent").innerHTML = \'<div class="text-center py-3"><div class="spinner-border text-primary"></div><p class="mt-2">加载中...</p></div>\';
    modal.show();

    fetch("/api/site.php?action=get_categories&site_id=" + siteId)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.categories.length > 0) {
                let html = \'<table class="table table-hover"><thead><tr><th>栏目ID</th><th>栏目名称</th></tr></thead><tbody>\';
                data.categories.forEach(cat => {
                    html += \'<tr><td>\' + cat.category_id + \'</td><td>\' + cat.category_name + \'</td></tr>\';
                });
                html += "</tbody></table>";
                document.getElementById("categoryContent").innerHTML = html;
            } else {
                document.getElementById("categoryContent").innerHTML = \'<div class="text-center text-muted py-3">暂无栏目数据，请点击同步按钮获取</div>\';
            }
        });
}

function syncCategories() {
    const btn = document.getElementById("btnSyncCategories");
    btn.disabled = true;
    btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span> 同步中...\';

    fetch("/api/site.php?action=sync_categories&site_id=" + currentSiteId)
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = \'<i class="bi bi-arrow-repeat"></i> 同步栏目\';
            if (data.success) {
                // 直接在当前弹窗中重新加载栏目列表
                if (data.categories && data.categories.length > 0) {
                    let html = \'<div class="alert alert-success"><i class="bi bi-check-circle"></i> 同步成功，共 \' + data.categories.length + \' 个栏目</div>\';
                    html += \'<table class="table table-hover"><thead><tr><th>栏目ID</th><th>栏目名称</th></tr></thead><tbody>\';
                    data.categories.forEach(cat => {
                        html += \'<tr><td>\' + cat.category_id + \'</td><td>\' + cat.category_name + \'</td></tr>\';
                    });
                    html += "</tbody></table>";
                    document.getElementById("categoryContent").innerHTML = html;
                } else {
                    showCategories(currentSiteId);
                }
            } else {
                document.getElementById("categoryContent").innerHTML = \'<div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> \' + (data.message || "同步失败") + \'</div>\';
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = \'<i class="bi bi-arrow-repeat"></i> 同步栏目\';
            document.getElementById("categoryContent").innerHTML = \'<div class="alert alert-danger">请求失败: \' + err.message + \'</div>\';
        });
}

function testPublish(siteId, event) {
    if (!confirm("确认要测试发布一篇文章到该网站吗？")) return;

    const btn = event ? event.target.closest("button") : document.querySelector("button[onclick*=\\"" + siteId + "\\"]");
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = \'<span class="spinner-border spinner-border-sm"></span>\';
    }

    fetch("/api/site.php?action=test_publish&site_id=" + siteId)
        .then(r => r.json())
        .then(data => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = \'<i class="bi bi-send"></i>\';
            }
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert(data.message || "测试发布失败");
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = \'<i class="bi bi-send"></i>\';
            }
            alert("请求失败: " + err.message);
        });
}

function deleteSite(siteId) {
    if (!confirm("确认要删除该站点吗？此操作不可恢复！")) return;
    const form = document.createElement("form");
    form.method = "POST";
    form.innerHTML = \'<input type="hidden" name="action" value="delete_site"><input type="hidden" name="site_id" value="\' + siteId + \'">\';
    document.body.appendChild(form);
    form.submit();
}

function showPublishSettings(siteId, siteName) {
    document.getElementById("publishSettingsSiteId").value = siteId;
    document.getElementById("publishSettingsSiteName").textContent = siteName;
    
    // 加载现有设置
    fetch("/api/site.php?action=get_publish_settings&site_id=" + siteId)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.settings) {
                document.getElementById("publishTime").value = data.settings.publish_time || "";
                document.getElementById("publishInterval").value = data.settings.publish_interval || 0;
                document.getElementById("randomInterval").checked = parseInt(data.settings.random_interval || 0) === 1;
                document.getElementById("dailyMax").value = data.settings.daily_max || 0;
            } else {
                document.getElementById("publishTime").value = "";
                document.getElementById("publishInterval").value = 0;
                document.getElementById("randomInterval").checked = false;
                document.getElementById("dailyMax").value = 0;
            }
        })
        .catch(() => {
            document.getElementById("publishTime").value = "";
            document.getElementById("publishInterval").value = 0;
            document.getElementById("randomInterval").checked = false;
            document.getElementById("dailyMax").value = 0;
        });
    
    new bootstrap.Modal(document.getElementById("publishSettingsModal")).show();
}

function showLogs(siteId) {
    const modal = new bootstrap.Modal(document.getElementById("logModal"));
    document.getElementById("logContent").innerHTML = \'<div class="text-center py-3"><div class="spinner-border text-primary"></div></div>\';
    modal.show();

    fetch("/api/site.php?action=get_logs&site_id=" + siteId)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.logs.length > 0) {
                let html = \'<table class="table table-sm"><thead><tr><th>时间</th><th>操作</th><th>状态</th><th>消息</th></tr></thead><tbody>\';
                data.logs.forEach(log => {
                    html += \'<tr><td>\' + log.created_at + \'</td><td>\' + log.action + \'</td><td>\' + (log.status === "success" ? "<span class=\'badge bg-success\'>成功</span>" : "<span class=\'badge bg-danger\'>失败</span>") + \'</td><td>\' + (log.message || "-") + \'</td></tr>\';
                });
                html += "</tbody></table>";
                document.getElementById("logContent").innerHTML = html;
            } else {
                document.getElementById("logContent").innerHTML = \'<div class="text-center text-muted py-3">暂无日志</div>\';
            }
        });
}
</script>';

require_once __DIR__ . '/../../includes/layout/footer.php';
?>
