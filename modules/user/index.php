<?php
/**
 * 用户管理（管理员）
 */
$pageTitle = '用户管理';
$page = 'user';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

if (!Auth::isAdmin()) {
    header('Location: /');
    exit;
}

$db = Database::getInstance();
$message = '';
$error = '';

// 处理POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';

        if (empty($username) || empty($email) || empty($password)) {
            $error = '请填写所有必填项';
        } elseif (strlen($password) < 6) {
            $error = '密码至少6位';
        } else {
            $exists = $db->count('users', 'username=? OR email=?', [$username, $email]);
            if ($exists) {
                $error = '用户名或邮箱已存在';
            } else {
                $db->insert('users', [
                    'username' => $username,
                    'email' => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => in_array($role, ['admin', 'user']) ? $role : 'user',
                    'status' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $message = "用户 {$username} 添加成功";
            }
        }
    }

    if ($postAction === 'toggle_user') {
        $uid = intval($_POST['user_id'] ?? 0);
        $user = $db->fetchOne("SELECT status FROM users WHERE id=?", [$uid]);
        if ($user) {
            $newStatus = $user['status'] ? 0 : 1;
            $db->update('users', ['status' => $newStatus], 'id=?', [$uid]);
            $message = '用户状态已更新';
        }
    }

    if ($postAction === 'toggle_role') {
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid == $_SESSION['user_id']) {
            $error = '不能修改自己的角色';
        } else {
            $user = $db->fetchOne("SELECT role FROM users WHERE id=?", [$uid]);
            if ($user) {
                $newRole = $user['role'] === 'admin' ? 'user' : 'admin';
                $db->update('users', ['role' => $newRole], 'id=?', [$uid]);
                $message = '用户角色已更新';
            }
        }
    }

    if ($postAction === 'delete_user') {
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid == $_SESSION['user_id']) {
            $error = '不能删除自己';
        } else {
            $db->delete('users', 'id=?', [$uid]);
            $message = '用户已删除';
        }
    }
}

// 用户列表
$pageNum = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;
$users = $db->fetchAll("SELECT * FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?", [$perPage, $offset]);
$totalUsers = $db->count('users');
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-people"></i> 用户管理</h4>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-plus-circle"></i> 添加用户
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-auto-dismiss"><i class="bi bi-check-circle"></i> <?php echo e($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo e($error); ?></div>
    <?php endif; ?>

    <!-- 用户列表 -->
    <div class="card">
        <div class="card-header"><i class="bi bi-person"></i> 用户列表</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>邮箱</th>
                            <th>角色</th>
                            <th>状态</th>
                            <th>最后登录</th>
                            <th>注册时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td><?php echo e($user['username']); ?></td>
                            <td><?php echo e($user['email']); ?></td>
                            <td><?php echo $user['role'] === 'admin' ? '<span class="badge bg-danger">管理员</span>' : '<span class="badge bg-info">普通用户</span>'; ?></td>
                            <td><?php echo $user['status'] ? '<span class="badge bg-success">正常</span>' : '<span class="badge bg-secondary">禁用</span>'; ?></td>
                            <td><small class="text-muted"><?php echo $user['last_login'] ? timeAgo($user['last_login']) : '-'; ?></small></td>
                            <td><small class="text-muted"><?php echo $user['created_at']; ?></small></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <!-- 切换角色 -->
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_role">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-outline-<?php echo $user['role'] === 'admin' ? 'secondary' : 'danger'; ?>" title="<?php echo $user['role'] === 'admin' ? '取消管理员' : '设为管理员'; ?>">
                                            <i class="bi bi-<?php echo $user['role'] === 'admin' ? 'person' : 'person-gear'; ?>"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <!-- 启用/禁用 -->
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_user">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-outline-<?php echo $user['status'] ? 'warning' : 'success'; ?>" title="<?php echo $user['status'] ? '禁用' : '启用'; ?>">
                                            <i class="bi bi-<?php echo $user['status'] ? 'pause' : 'play'; ?>"></i>
                                        </button>
                                    </form>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <!-- 删除 -->
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="删除" onclick="return confirm('确认删除？')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php echo pagination($totalUsers, $pageNum, $perPage); ?>
</div>

<!-- 添加用户弹窗 -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> 添加用户</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_user">
                    <div class="mb-3">
                        <label class="form-label">用户名 <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">邮箱 <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">密码 <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                        <small class="form-text text-muted">至少6位</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">用户角色</label>
                        <select name="role" class="form-select">
                            <option value="user">普通用户</option>
                            <option value="admin">管理员</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 添加</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
