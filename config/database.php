<?php
/**
 * SEO Publisher - 数据库配置
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'seo_publisher');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// 时区
date_default_timezone_set('Asia/Shanghai');

// 项目根路径
define('ROOT_PATH', dirname(__DIR__));
define('BASE_URL', '/seo-publisher');

// 上传目录
define('UPLOAD_PATH', ROOT_PATH . '/uploads/');

// 安全密钥
define('SECRET_KEY', 'your-secret-key-change-this-in-install');

// 是否已安装
define('INSTALLED', file_exists(ROOT_PATH . '/config/installed.lock'));
