<?php
/**
 * 套餐管理（管理员）
 */
$pageTitle = '套餐管理';
$page = 'packages';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

if (!Auth::isAdmin()) {
    header('Location: /');
    exit;
}

$db = Database::getInstance();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'add_package') {
        $db->insert('packages', [
            'name' => trim($_POST['name'] ?? ''),
            'price' => floatval($_POST['price'] ?? 0),
            'article_limit' => intval($_POST['article_limit'] ?? 0),
            'keyword_limit' => intval($_POST['keyword_limit'] ?? 0),
            'description' => trim($_POST['description'] ?? ''),
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $message = '套餐添加成功';
    }

    if ($postAction === 'update_package') {
        $pkgId = intval($_POST['package_id'] ?? 0);
        $db->update('packages', [
            'name' => trim($_POST['name'] ?? ''),
            'price' => floatval($_POST['price'] ?? 0),
            'article_limit' => intval($_POST['article_limit'] ?? 0),
            'keyword_limit' => intval($_POST['keyword_limit'] ?? 0),
            'description' => trim($_POST['description'] ?? ''),
        ], 'id=?', [$pkgId]);
        $message = '套餐更新成功';
    }

    if ($postAction === 'delete_package') {
        $pkgId = intval($_POST['package_id'] ?? 0);
        $db->delete('packages', 'id=?', [$pkgId]);
        $message = '套餐已删除';
    }
}

$packages = $db->fetchAll("SELECT * FROM packages ORDER BY price ASC");
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-box"></i> 套餐管理</h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPackageModal">
            <i class="bi bi-plus-circle"></i> 添加套餐
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-auto-dismiss"><i class="bi bi-check-circle"></i> <?php echo e($message); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>套餐名称</th>
                            <th>价格</th>
                            <th>文章额度</th>
                            <th>关键词额度</th>
                            <th>说明</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($packages)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">暂无套餐</td></tr>
                        <?php else: ?>
                            <?php foreach ($packages as $pkg): ?>
                            <tr>
                                <td><?php echo e($pkg['name']); ?></td>
                                <td>¥<?php echo number_format($pkg['price'], 2); ?></td>
                                <td><?php echo $pkg['article_limit']; ?>篇/月</td>
                                <td><?php echo $pkg['keyword_limit']; ?>个/月</td>
                                <td><small><?php echo e($pkg['description'] ?? '-'); ?></small></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" onclick="editPackage(<?php echo htmlspecialchars(json_encode($pkg), ENT_QUOTES); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="delete_package">
                                            <input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" onclick="return confirm('确认删除？')">
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
</div>

<!-- 添加套餐弹窗 -->
<div class="modal fade" id="addPackageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> 添加套餐</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_package">
                    <div class="mb-3">
                        <label class="form-label">套餐名称</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-4">
                            <label class="form-label">价格</label>
                            <input type="number" name="price" class="form-control" step="0.01" value="0">
                        </div>
                        <div class="col-4">
                            <label class="form-label">文章额度/月</label>
                            <input type="number" name="article_limit" class="form-control" value="100">
                        </div>
                        <div class="col-4">
                            <label class="form-label">关键词额度/月</label>
                            <input type="number" name="keyword_limit" class="form-control" value="500">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">说明</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 编辑套餐弹窗 -->
<div class="modal fade" id="editPackageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil"></i> 编辑套餐</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_package">
                    <input type="hidden" name="package_id" id="editPkgId">
                    <div class="mb-3">
                        <label class="form-label">套餐名称</label>
                        <input type="text" name="name" id="editPkgName" class="form-control" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-4">
                            <label class="form-label">价格</label>
                            <input type="number" name="price" id="editPkgPrice" class="form-control" step="0.01">
                        </div>
                        <div class="col-4">
                            <label class="form-label">文章额度/月</label>
                            <input type="number" name="article_limit" id="editPkgArticle" class="form-control">
                        </div>
                        <div class="col-4">
                            <label class="form-label">关键词额度/月</label>
                            <input type="number" name="keyword_limit" id="editPkgKeyword" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">说明</label>
                        <textarea name="description" id="editPkgDesc" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraJs = '<script>
function editPackage(pkg) {
    document.getElementById("editPkgId").value = pkg.id;
    document.getElementById("editPkgName").value = pkg.name;
    document.getElementById("editPkgPrice").value = pkg.price;
    document.getElementById("editPkgArticle").value = pkg.article_limit;
    document.getElementById("editPkgKeyword").value = pkg.keyword_limit;
    document.getElementById("editPkgDesc").value = pkg.description || "";
    new bootstrap.Modal(document.getElementById("editPackageModal")).show();
}
</script>';

require_once __DIR__ . '/../../includes/layout/footer.php';
?>
