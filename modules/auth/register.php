<?php
/**
 * 注册页面
 */
require_once __DIR__ . '/../../config/init.php';
require_once ROOT_PATH . '/includes/Functions.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

// 检查是否允许注册
$db = Database::getInstance();
$allowRegister = true;
try {
    $setting = $db->fetchOne("SELECT setting_value FROM site_settings WHERE setting_key='allow_register'");
    if ($setting && $setting['setting_value'] === '0') {
        $allowRegister = false;
    }
} catch (Exception $e) {}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$allowRegister) {
        $error = '注册已关闭，请联系管理员';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            $error = '请填写所有必填项';
        } elseif ($password !== $confirm) {
            $error = '两次密码不一致';
        } elseif (strlen($password) < 6) {
            $error = '密码至少6位';
        } else {
            $auth = new Auth();
            $result = $auth->register($username, $email, $password);
            if ($result['success']) {
                $loginResult = $auth->login($username, $password);
                if ($loginResult['success']) {
                    header('Location: /');
                    exit;
                } else {
                    $error = '注册成功但自动登录失败，请手动登录';
                }
            } else {
                $error = $result['message'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注册 - SEO Publisher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .register-card {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 420px;
            width: 100%;
            overflow: hidden;
        }
        .register-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .register-header h1 { margin: 0; font-size: 28px; font-weight: 700; }
        .register-header p { margin: 10px 0 0; opacity: 0.9; }
        .register-body { padding: 40px 30px; }
        .btn-register {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 10px;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="register-card">
        <div class="register-header">
            <h1><i class="bi bi-search"></i> SEO Publisher</h1>
            <p>创建新账户</p>
        </div>
        <div class="register-body">
            <?php if (!$allowRegister): ?>
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> 注册已关闭，请联系管理员开通账户</div>
                <div class="text-center mt-3">
                    <a href="login.php" class="btn btn-outline-primary">返回登录</a>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo e($error); ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">用户名</label>
                        <input type="text" name="username" class="form-control" required value="<?php echo e($_POST['username'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">邮箱</label>
                        <input type="email" name="email" class="form-control" required value="<?php echo e($_POST['email'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">密码</label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">确认密码</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-register text-white mt-2">
                        <i class="bi bi-person-plus"></i> 注册
                    </button>
                </form>

                <div class="text-center mt-3">
                    <span class="text-muted">已有账户？</span>
                    <a href="login.php" class="text-decoration-none">立即登录</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
