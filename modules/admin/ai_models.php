<?php
/**
 * AI模型管理 - 管理员
 */
$pageTitle = 'AI模型管理';
$page = 'admin';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

// 检查管理员权限
if (!Auth::isAdmin()) {
    header('Location: /');
    exit;
}

$db = Database::getInstance();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-cpu"></i> AI模型管理</h4>
        <button class="btn btn-primary" onclick="showAddModel()">
            <i class="bi bi-plus-circle"></i> 添加模型
        </button>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>模型标识</th>
                            <th>模型名称</th>
                            <th>模型ID</th>
                            <th>API端点</th>
                            <th>类型</th>
                            <th>状态</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="modelTableBody">
                        <tr><td colspan="7" class="text-center text-muted py-4">加载中...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 添加模型弹窗 -->
<div class="modal fade" id="addModelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> 添加AI模型</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">模型标识 <span class="text-danger">*</span></label>
                    <input type="text" id="addModelKey" class="form-control" placeholder="如：qwen, glm, claude（英文，唯一）">
                    <small class="form-text text-muted">用于系统内部识别，不可修改，如 qwen、glm</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">模型名称 <span class="text-danger">*</span></label>
                    <input type="text" id="addModelName" class="form-control" placeholder="如：通义千问、ChatGLM">
                </div>
                <div class="mb-3">
                    <label class="form-label">API端点 <span class="text-danger">*</span></label>
                    <input type="text" id="addApiEndpoint" class="form-control" placeholder="如：https://api.xiaomimimo.com/v1">
                    <small class="form-text text-muted">完整的Chat Completions接口地址，末尾会自动补全 /chat/completions</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">模型ID <span class="text-danger">*</span></label>
                    <input type="text" id="addModelId" class="form-control" placeholder="如：gpt-4o-mini、deepseek-chat、qwen-turbo">
                    <small class="form-text text-muted">API请求中使用的模型标识符</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-info" onclick="testAddModel()">
                    <i class="bi bi-lightning"></i> 测试连接
                </button>
                <button type="button" class="btn btn-primary" onclick="saveModel()">
                    <i class="bi bi-check-lg"></i> 保存
                </button>
            </div>
            <div id="addModelResult" class="px-3 pb-3" style="display:none"></div>
        </div>
    </div>
</div>

<!-- 编辑模型弹窗 -->
<div class="modal fade" id="editModelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil"></i> 编辑AI模型</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editModelId_db">
                <div class="mb-3">
                    <label class="form-label">模型标识</label>
                    <input type="text" id="editModelKey" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">模型名称 <span class="text-danger">*</span></label>
                    <input type="text" id="editModelName" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">API端点 <span class="text-danger">*</span></label>
                    <input type="text" id="editApiEndpoint" class="form-control">
                    <small class="form-text text-muted">完整的Chat Completions接口地址，如 https://api.xiaomimimo.com/v1（末尾会自动补全 /chat/completions）</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">模型ID <span class="text-danger">*</span></label>
                    <input type="text" id="editModelId" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">状态</label>
                    <select id="editModelStatus" class="form-select">
                        <option value="1">启用</option>
                        <option value="0">禁用</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-info" onclick="testEditModel()">
                    <i class="bi bi-lightning"></i> 测试连接
                </button>
                <button type="button" class="btn btn-primary" onclick="updateModel()">
                    <i class="bi bi-check-lg"></i> 保存修改
                </button>
            </div>
            <div id="editModelResult" class="px-3 pb-3" style="display:none"></div>
        </div>
    </div>
</div>

<?php
$extraJs = '<script>
// 加载模型列表
function loadModels() {
    fetch("/api/admin.php?action=list_models")
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById("modelTableBody").innerHTML = \'<tr><td colspan="7" class="text-center text-danger">加载失败</td></tr>\';
                return;
            }
            const models = data.models;
            if (models.length === 0) {
                document.getElementById("modelTableBody").innerHTML = \'<tr><td colspan="7" class="text-center text-muted py-4">暂无模型</td></tr>\';
                return;
            }
            let html = "";
            models.forEach(m => {
                const builtin = m.is_builtin == 1 ? \'<span class="badge bg-info">内置</span>\' : \'<span class="badge bg-success">自定义</span>\';
                const status = m.status == 1 ? \'<span class="badge bg-success">启用</span>\' : \'<span class="badge bg-secondary">禁用</span>\';
                const delBtn = m.is_builtin == 1 ? "" : \'<button class="btn btn-outline-danger btn-sm" onclick="deleteModel(\' + m.id + \')" title="删除"><i class="bi bi-trash"></i></button>\';
                html += \'<tr>\';
                html += \'<td><code>\' + escapeHtml(m.model_key) + \'</code></td>\';
                html += \'<td>\' + escapeHtml(m.model_name) + \'</td>\';
                html += \'<td><code>\' + escapeHtml(m.model_id) + \'</code></td>\';
                html += \'<td><small class="text-muted">\' + escapeHtml(m.api_endpoint) + \'</small></td>\';
                html += \'<td>\' + builtin + \'</td>\';
                html += \'<td>\' + status + \'</td>\';
                html += \'<td><div class="btn-group btn-group-sm">\' +
                    \'<button class="btn btn-outline-warning" onclick="editModel(\' + m.id + ", \'" + escapeJs(m.model_key) + "\', \'" + escapeJs(m.model_name) + "\', \'" + escapeJs(m.api_endpoint) + "\', \'" + escapeJs(m.model_id) + "\', " + m.status + \')" title="编辑"><i class="bi bi-pencil"></i></button>\' +
                    delBtn + \'</div></td>\';
                html += \'</tr>\';
            });
            document.getElementById("modelTableBody").innerHTML = html;
        })
        .catch(err => {
            document.getElementById("modelTableBody").innerHTML = \'<tr><td colspan="7" class="text-center text-danger">请求失败: \' + err.message + \'</td></tr>\';
        });
}

function escapeHtml(str) {
    const div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
}

function escapeJs(str) {
    return str.replace(/\\\\/g, "\\\\\\\\").replace(/\'/g, "\\\\\'").replace(/"/g, "\\\\\"");
}

function showAddModel() {
    document.getElementById("addModelKey").value = "";
    document.getElementById("addModelName").value = "";
    document.getElementById("addApiEndpoint").value = "";
    document.getElementById("addModelId").value = "";
    document.getElementById("addModelResult").style.display = "none";
    new bootstrap.Modal(document.getElementById("addModelModal")).show();
}

function saveModel() {
    const modelKey = document.getElementById("addModelKey").value.trim();
    const modelName = document.getElementById("addModelName").value.trim();
    const apiEndpoint = document.getElementById("addApiEndpoint").value.trim();
    const modelId = document.getElementById("addModelId").value.trim();

    if (!modelKey || !modelName || !apiEndpoint || !modelId) {
        alert("请填写所有必填项");
        return;
    }

    const formData = new FormData();
    formData.append("model_key", modelKey);
    formData.append("model_name", modelName);
    formData.append("api_endpoint", apiEndpoint);
    formData.append("model_id", modelId);

    fetch("/api/admin.php?action=add_model", { method: "POST", body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById("addModelModal")).hide();
                loadModels();
            } else {
                alert(data.message || "添加失败");
            }
        })
        .catch(err => alert("请求失败: " + err.message));
}

function editModel(id, key, name, endpoint, modelId, status) {
    document.getElementById("editModelId_db").value = id;
    document.getElementById("editModelKey").value = key;
    document.getElementById("editModelName").value = name;
    document.getElementById("editApiEndpoint").value = endpoint;
    document.getElementById("editModelId").value = modelId;
    document.getElementById("editModelStatus").value = status;
    document.getElementById("editModelResult").style.display = "none";
    new bootstrap.Modal(document.getElementById("editModelModal")).show();
}

function updateModel() {
    const id = document.getElementById("editModelId_db").value;
    const modelName = document.getElementById("editModelName").value.trim();
    const apiEndpoint = document.getElementById("editApiEndpoint").value.trim();
    const modelId = document.getElementById("editModelId").value.trim();
    const status = document.getElementById("editModelStatus").value;

    if (!modelName || !apiEndpoint || !modelId) {
        alert("请填写所有必填项");
        return;
    }

    const formData = new FormData();
    formData.append("id", id);
    formData.append("model_name", modelName);
    formData.append("api_endpoint", apiEndpoint);
    formData.append("model_id", modelId);
    formData.append("status", status);

    fetch("/api/admin.php?action=edit_model", { method: "POST", body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById("editModelModal")).hide();
                loadModels();
            } else {
                alert(data.message || "修改失败");
            }
        })
        .catch(err => alert("请求失败: " + err.message));
}

function deleteModel(id) {
    if (!confirm("确认删除该模型？")) return;
    const formData = new FormData();
    formData.append("id", id);
    fetch("/api/admin.php?action=delete_model", { method: "POST", body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) loadModels();
            else alert(data.message || "删除失败");
        })
        .catch(err => alert("请求失败: " + err.message));
}

function testAddModel() {
    const endpoint = document.getElementById("addApiEndpoint").value.trim();
    const apiKey = prompt("请输入该模型的API Key进行测试:");
    if (!apiKey) return;
    const modelId = document.getElementById("addModelId").value.trim();
    if (!endpoint || !modelId) {
        alert("请先填写API端点和模型ID");
        return;
    }
    doTestConnection(endpoint, apiKey, modelId, "addModelResult");
}

function testEditModel() {
    const endpoint = document.getElementById("editApiEndpoint").value.trim();
    const apiKey = prompt("请输入该模型的API Key进行测试:");
    if (!apiKey) return;
    const modelId = document.getElementById("editModelId").value.trim();
    if (!endpoint || !modelId) {
        alert("请先填写API端点和模型ID");
        return;
    }
    doTestConnection(endpoint, apiKey, modelId, "editModelResult");
}

function doTestConnection(endpoint, apiKey, modelId, resultDivId) {
    const resultDiv = document.getElementById(resultDivId);
    resultDiv.style.display = "block";
    resultDiv.innerHTML = \'<div class="alert alert-info mb-0"><span class="spinner-border spinner-border-sm"></span> 测试中...</div>\';

    fetch("/api/admin.php?action=test_model", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({endpoint: endpoint, api_key: apiKey, model_id: modelId})
    })
    .then(r => {
        return r.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                const preview = text.substring(0, 200).replace(/<[^>]*>/g, \'\');
                throw new Error("服务器返回了非JSON响应: " + preview);
            }
        });
    })
    .then(data => {
        if (data.success) {
            resultDiv.innerHTML = \'<div class="alert alert-success mb-0"><i class="bi bi-check-circle"></i> \' + data.message + \'</div>\';
        } else {
            resultDiv.innerHTML = \'<div class="alert alert-danger mb-0"><i class="bi bi-x-circle"></i> \' + data.message + \'</div>\';
        }
    })
    .catch(err => {
        resultDiv.innerHTML = \'<div class="alert alert-danger mb-0"><i class="bi bi-x-circle"></i> 请求失败: \' + err.message + \'</div>\';
    });
}

// 页面加载时获取模型列表
document.addEventListener("DOMContentLoaded", loadModels);
</script>';

require_once __DIR__ . '/../../includes/layout/footer.php';
?>
