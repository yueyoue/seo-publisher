<?php
/**
 * 个人设置 - 修改密码
 */
$pageTitle = '个人设置';
$page = 'user';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldPass = $_POST['old_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (empty($oldPass) || empty($newPass)) {
        $error = '请填写所有字段';
    } elseif ($newPass !== $confirmPass) {
        $error = '两次密码不一致';
    } elseif (strlen($newPass) < 6) {
        $error = '密码至少6位';
    } else {
        $auth = new Auth();
        $result = $auth->changePassword($userId, $oldPass, $newPass);
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}

$user = Auth::user();
$package = (new Auth())->getUserPackage($userId);
?>

<div class="container-fluid">
    <h4 class="mb-4"><i class="bi bi-gear"></i> 个人设置</h4>

    <?php if ($message): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle"></i> <?php echo e($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo e($error); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-person"></i> 账户信息</div>
                <div class="card-body">
                    <table class="table table-borderless">
                        <tr><th width="100">用户名</th><td><?php echo e($user['username']); ?></td></tr>
                        <tr><th>邮箱</th><td><?php echo e($user['email']); ?></td></tr>
                        <tr><th>角色</th><td><?php echo $user['role'] === 'admin' ? '<span class="badge bg-danger">管理员</span>' : '<span class="badge bg-info">普通用户</span>'; ?></td></tr>
                        <tr><th>注册时间</th><td><?php echo $user['created_at']; ?></td></tr>
                        <tr><th>最后登录</th><td><?php echo $user['last_login'] ?? '-'; ?></td></tr>
                    </table>
                </div>
            </div>

            <?php if ($package): ?>
            <div class="card mt-3">
                <div class="card-header"><i class="bi bi-box"></i> 我的套餐</div>
                <div class="card-body">
                    <h5><?php echo e($package['package_name']); ?></h5>
                    <p class="text-muted">到期时间: <?php echo $package['expire_time']; ?></p>
                    <div class="row">
                        <div class="col-6">
                            <div class="text-center">
                                <div class="fs-4 fw-bold text-primary"><?php echo $package['articles_used']; ?> / <?php echo $package['article_limit']; ?></div>
                                <small class="text-muted">已用文章/总文章</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="text-center">
                                <div class="fs-4 fw-bold text-success"><?php echo $package['keywords_used']; ?> / <?php echo $package['keyword_limit']; ?></div>
                                <small class="text-muted">已用关键词/总关键词</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-6 mb-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-key"></i> 修改密码</div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">原密码</label>
                            <input type="password" name="old_password" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">新密码</label>
                            <input type="password" name="new_password" class="form-control" required minlength="6">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">确认新密码</label>
                            <input type="password" name="confirm_password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 修改密码</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
