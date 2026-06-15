<?php
/**
 * SEO Publisher - 安装程序
 */
session_start();
$step = $_GET['step'] ?? 1;
$error = '';
$success = '';

// 检查是否已安装
if (file_exists(__DIR__ . '/../config/installed.lock') && $step != 'done') {
    // 允许重新安装
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'install') {
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbName = trim($_POST['db_name'] ?? 'seo_publisher');
        $dbUser = trim($_POST['db_user'] ?? 'root');
        $dbPass = $_POST['db_pass'] ?? '';
        $adminUser = trim($_POST['admin_user'] ?? 'admin');
        $adminEmail = trim($_POST['admin_email'] ?? '');
        $adminPass = $_POST['admin_pass'] ?? '';
        $siteName = trim($_POST['site_name'] ?? 'SEO Publisher');

        // 验证
        if (empty($adminUser) || empty($adminEmail) || empty($adminPass)) {
            $error = '请填写完整的管理员信息';
        } else {
            try {
                // 先连接MySQL（不指定数据库）
                $pdo = new PDO("mysql:host={$dbHost};charset=utf8mb4", $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                // 创建数据库
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbName}`");

                // 创建表
                $sql = file_get_contents(__DIR__ . '/schema.sql');
                $pdo->exec($sql);

                // 创建管理员
                $adminPassHash = password_hash($adminPass, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (username, email, password, role, status, created_at) VALUES (?, ?, ?, 'admin', 1, NOW())")
                    ->execute([$adminUser, $adminEmail, $adminPassHash]);

                // 创建默认套餐
                $pdo->prepare("INSERT INTO packages (name, price, article_limit, keyword_limit, status, created_at) VALUES 
                    ('免费版', 0, 10, 50, 1, NOW()),
                    ('基础版', 99, 100, 500, 1, NOW()),
                    ('专业版', 299, 500, 2000, 1, NOW()),
                    ('企业版', 699, 2000, 10000, 1, NOW())")
                    ->execute();

                // 写入配置文件
                $configContent = "<?php\n";
                $configContent .= "define('DB_HOST', '{$dbHost}');\n";
                $configContent .= "define('DB_NAME', '{$dbName}');\n";
                $configContent .= "define('DB_USER', '{$dbUser}');\n";
                $configContent .= "define('DB_PASS', " . var_export($dbPass, true) . ");\n";
                $configContent .= "define('DB_CHARSET', 'utf8mb4');\n";
                $configContent .= "date_default_timezone_set('Asia/Shanghai');\n";
                $configContent .= "define('ROOT_PATH', dirname(__DIR__));\n";
                $configContent .= "define('BASE_URL', '');\n";
                $configContent .= "define('UPLOAD_PATH', ROOT_PATH . '/uploads/');\n";
                $configContent .= "define('SECRET_KEY', '" . bin2hex(random_bytes(32)) . "');\n";
                $configContent .= "define('INSTALLED', true);\n";

                file_put_contents(__DIR__ . '/../config/database.php', $configContent);
                file_put_contents(__DIR__ . '/../config/installed.lock', date('Y-m-d H:i:s'));

                // 写入站点设置
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('site_name', ?)")
                    ->execute([$siteName]);

                header('Location: ?step=done');
                exit;

            } catch (PDOException $e) {
                $error = '安装失败: ' . $e->getMessage();
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
    <title>SEO Publisher - 安装向导</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f0c29, #302b63, #24243e);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        .install-card {
            background: rgba(255,255,255,0.95);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
            overflow: hidden;
        }
        .install-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .install-header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
        }
        .install-header p {
            margin: 10px 0 0;
            opacity: 0.9;
        }
        .install-body {
            padding: 40px;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        .step-item {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin: 0 10px;
            position: relative;
        }
        .step-item.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        .step-item.done {
            background: #28a745;
            color: white;
        }
        .step-item.pending {
            background: #e9ecef;
            color: #6c757d;
        }
        .form-label {
            font-weight: 600;
            color: #333;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102,126,234,0.25);
        }
        .btn-install {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px 40px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 10px;
        }
        .btn-install:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102,126,234,0.4);
        }
        .check-item {
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .check-item i.bi-check-circle-fill { color: #28a745; }
        .check-item i.bi-x-circle-fill { color: #dc3545; }
    </style>
</head>
<body>
    <div class="install-card">
        <div class="install-header">
            <h1><i class="bi bi-search"></i> SEO Publisher</h1>
            <p>搜索引擎关键词挖掘 & 自动文章发布系统</p>
        </div>
        <div class="install-body">
            <?php if ($step == 1): ?>
                <!-- 步骤1: 环境检测 -->
                <div class="step-indicator">
                    <div class="step-item active">1</div>
                    <div class="step-item pending">2</div>
                    <div class="step-item pending">3</div>
                </div>

                <h4 class="text-center mb-4">环境检测</h4>

                <?php
                $checks = [
                    ['PHP版本 >= 7.4', version_compare(PHP_VERSION, '7.4.0', '>=')],
                    ['PDO扩展', extension_loaded('pdo')],
                    ['PDO MySQL', extension_loaded('pdo_mysql')],
                    ['CURL扩展', extension_loaded('curl')],
                    ['JSON扩展', extension_loaded('json')],
                    ['XML扩展', extension_loaded('xml')],
                    ['config目录可写', is_writable(__DIR__ . '/../config/')],
                    ['uploads目录可写', is_dir(__DIR__ . '/../uploads/') && is_writable(__DIR__ . '/../uploads/') || @mkdir(__DIR__ . '/../uploads/', 0755, true) && is_writable(__DIR__ . '/../uploads/')],
                ];

                $allPassed = true;
                foreach ($checks as $check) {
                    $passed = $check[1];
                    if (!$passed) $allPassed = false;
                    echo '<div class="check-item">';
                    echo '<i class="bi ' . ($passed ? 'bi-check-circle-fill' : 'bi-x-circle-fill') . '"></i> ';
                    echo $check[0];
                    echo '</div>';
                }
                ?>

                <div class="text-center mt-4">
                    <?php if ($allPassed): ?>
                        <a href="?step=2" class="btn btn-install btn-primary text-white">
                            <i class="bi bi-arrow-right"></i> 下一步
                        </a>
                    <?php else: ?>
                        <div class="alert alert-warning">请先解决以上环境问题再继续安装</div>
                    <?php endif; ?>
                </div>

            <?php elseif ($step == 2): ?>
                <!-- 步骤2: 数据库配置 -->
                <div class="step-indicator">
                    <div class="step-item done"><i class="bi bi-check"></i></div>
                    <div class="step-item active">2</div>
                    <div class="step-item pending">3</div>
                </div>

                <h4 class="text-center mb-4">数据库配置 & 管理员设置</h4>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="action" value="install">

                    <h6 class="text-muted mt-3 mb-2"><i class="bi bi-database"></i> 数据库信息</h6>
                    
                    <div class="mb-3">
                        <label class="form-label">数据库主机</label>
                        <input type="text" name="db_host" class="form-control" value="localhost" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">数据库名称</label>
                        <input type="text" name="db_name" class="form-control" value="seo_publisher" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">数据库用户名</label>
                            <input type="text" name="db_user" class="form-control" value="root" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">数据库密码</label>
                            <input type="password" name="db_pass" class="form-control">
                        </div>
                    </div>

                    <hr class="my-4">
                    <h6 class="text-muted mb-2"><i class="bi bi-gear"></i> 站点设置</h6>

                    <div class="mb-3">
                        <label class="form-label">站点名称</label>
                        <input type="text" name="site_name" class="form-control" value="SEO Publisher" required>
                    </div>

                    <hr class="my-4">
                    <h6 class="text-muted mb-2"><i class="bi bi-person-shield"></i> 管理员账户</h6>

                    <div class="mb-3">
                        <label class="form-label">管理员用户名</label>
                        <input type="text" name="admin_user" class="form-control" value="admin" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">管理员邮箱</label>
                        <input type="email" name="admin_email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">管理员密码</label>
                        <input type="password" name="admin_pass" class="form-control" required minlength="6">
                    </div>

                    <div class="text-center mt-4">
                        <a href="?step=1" class="btn btn-outline-secondary me-3">上一步</a>
                        <button type="submit" class="btn btn-install btn-primary text-white">
                            <i class="bi bi-rocket-takeoff"></i> 开始安装
                        </button>
                    </div>
                </form>

            <?php elseif ($step == 'done'): ?>
                <!-- 步骤3: 安装完成 -->
                <div class="step-indicator">
                    <div class="step-item done"><i class="bi bi-check"></i></div>
                    <div class="step-item done"><i class="bi bi-check"></i></div>
                    <div class="step-item done"><i class="bi bi-check"></i></div>
                </div>

                <div class="text-center">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 64px;"></i>
                    <h4 class="mt-3 mb-2">安装成功！</h4>
                    <p class="text-muted">SEO Publisher 已成功安装，现在可以开始使用了。</p>

                    <div class="mt-4">
                        <a href="/" class="btn btn-install btn-primary text-white me-3">
                            <i class="bi bi-house"></i> 访问首页
                        </a>
                        <a href="/modules/auth/login.php" class="btn btn-outline-primary">
                            <i class="bi bi-box-arrow-in-right"></i> 登录后台
                        </a>
                    </div>

                    <div class="alert alert-warning mt-4 text-start">
                        <i class="bi bi-exclamation-triangle"></i> <strong>安全提示：</strong><br>
                        为安全起见，建议安装完成后删除或重命名 <code>install.php</code> 文件。
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
