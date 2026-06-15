<?php
session_start();

// 加载配置
require_once __DIR__ . '/database.php';

// 自动加载
spl_autoload_register(function ($class) {
    $file = ROOT_PATH . '/includes/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
