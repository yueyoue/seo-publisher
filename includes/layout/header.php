<?php
/**
 * 页面布局 - Header
 */
require_once __DIR__ . '/../../config/init.php';
require_once ROOT_PATH . '/includes/Functions.php';

if (!defined('INSTALLED') || !INSTALLED) {
    // 检查是否在安装目录
    if (strpos($_SERVER['SCRIPT_NAME'], '/install/') === false) {
        header('Location: /install/');
        exit;
    }
}

$currentUser = null;
if (isset($_SESSION['user_id'])) {
    $currentUser = Auth::user();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'SEO Publisher'; ?> - SEO Publisher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php if ($currentUser): ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-gradient">
        <div class="container-fluid">
            <a class="navbar-brand" href="/">
                <i class="bi bi-search"></i> SEO Publisher
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($page ?? '') === 'dashboard' ? 'active' : ''; ?>" href="/">
                            <i class="bi bi-speedometer2"></i> 控制台
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($page ?? '') === 'site' ? 'active' : ''; ?>" href="/modules/site/index.php">
                            <i class="bi bi-globe"></i> 站点管理
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($page ?? '') === 'keyword' ? 'active' : ''; ?>" href="/modules/keyword/index.php">
                            <i class="bi bi-key"></i> 关键词挖掘
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($page ?? '') === 'article' ? 'active' : ''; ?>" href="/modules/article/index.php">
                            <i class="bi bi-file-earmark-text"></i> 生成文章
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($page ?? '') === 'queue' ? 'active' : ''; ?>" href="/modules/queue/index.php">
                            <i class="bi bi-clock-history"></i> 发布队列
                        </a>
                    </li>
                    <?php if (Auth::isAdmin()): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($page ?? '', ['admin', 'packages', 'user']) ? 'active' : ''; ?>" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-sliders"></i> 设置
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="/modules/admin/settings.php"><i class="bi bi-gear"></i> 系统设置</a></li>
                            <li><a class="dropdown-item" href="/modules/admin/ai_models.php"><i class="bi bi-cpu"></i> AI模型管理</a></li>
                            <li><a class="dropdown-item" href="/modules/admin/packages.php"><i class="bi bi-box"></i> 套餐管理</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/modules/user/index.php"><i class="bi bi-people"></i> 用户管理</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo e($currentUser['username']); ?>
                            <?php if ($currentUser['role'] === 'admin'): ?>
                                <span class="badge bg-warning text-dark">管理员</span>
                            <?php endif; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="/modules/user/profile.php"><i class="bi bi-gear"></i> 个人设置</a></li>
                            <li><a class="dropdown-item" href="/modules/user/packages.php"><i class="bi bi-cart"></i> 套餐购买</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/modules/auth/logout.php"><i class="bi bi-box-arrow-right"></i> 退出登录</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <main class="main-content">
