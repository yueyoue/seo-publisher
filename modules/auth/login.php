<?php
/**
 * 登录页面
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
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = '请填写用户名和密码';
    } else {
        $auth = new Auth();
        $result = $auth->login($username, $password);
        if ($result['success']) {
            header('Location: /');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - SEO Publisher</title>
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
        .login-card {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 420px;
            width: 100%;
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .login-header h1 { margin: 0; font-size: 28px; font-weight: 700; }
        .login-header p { margin: 10px 0 0; opacity: 0.9; }
        .login-body { padding: 40px 30px; }
        .form-floating { margin-bottom: 15px; }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 10px;
            width: 100%;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102,126,234,0.4);
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <h1><i class="bi bi-search"></i> SEO Publisher</h1>
            <p>登录到您的账户</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-circle"></i> <?php echo e($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-floating">
                    <input type="text" name="username" class="form-control" id="username" placeholder="用户名" required autofocus>
                    <label for="username"><i class="bi bi-person"></i> 用户名或邮箱</label>
                </div>
                <div class="form-floating">
                    <input type="password" name="password" class="form-control" id="password" placeholder="密码" required>
                    <label for="password"><i class="bi bi-lock"></i> 密码</label>
                </div>
                <button type="submit" class="btn btn-login btn-primary text-white mt-3">
                    <i class="bi bi-box-arrow-in-right"></i> 登录
                </button>
            </form>

            <div class="text-center mt-3">
                <?php if ($allowRegister): ?>
                    <span class="text-muted">还没有账户？</span>
                    <a href="register.php" class="text-decoration-none">立即注册</a>
                <?php else: ?>
                    <span class="text-muted">注册已关闭，请联系管理员</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
