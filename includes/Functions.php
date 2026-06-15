<?php
/**
 * 公共函数库
 */

/**
 * JSON响应
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 安全输出
 */
function e($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * 生成CSRF Token
 */
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 验证CSRF Token
 */
function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * 生成分页HTML
 */
function pagination($total, $page, $perPage = 20) {
    $totalPages = ceil($total / $perPage);
    if ($totalPages <= 1) return '';

    $html = '<nav><ul class="pagination justify-content-center">';
    
    // 上一页
    if ($page > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="?page=' . ($page - 1) . '">上一页</a></li>';
    }

    // 页码
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    if ($start > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
        if ($start > 2) $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = $i == $page ? ' active' : '';
        $html .= "<li class=\"page-item{$active}\"><a class=\"page-link\" href=\"?page={$i}\">{$i}</a></li>";
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        $html .= '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . '">' . $totalPages . '</a></li>';
    }

    // 下一页
    if ($page < $totalPages) {
        $html .= '<li class="page-item"><a class="page-link" href="?page=' . ($page + 1) . '">下一页</a></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

/**
 * 格式化时间
 */
function timeAgo($datetime) {
    $now = time();
    $time = strtotime($datetime);
    $diff = $now - $time;

    if ($diff < 60) return '刚刚';
    if ($diff < 3600) return floor($diff / 60) . '分钟前';
    if ($diff < 86400) return floor($diff / 3600) . '小时前';
    if ($diff < 2592000) return floor($diff / 86400) . '天前';
    return date('Y-m-d', $time);
}

/**
 * 获取状态文本
 */
function statusText($status) {
    $map = [
        'pending' => '<span class="badge bg-warning">待生成</span>',
        'generating' => '<span class="badge bg-info">生成中</span>',
        'generated' => '<span class="badge bg-primary">已生成</span>',
        'scheduled' => '<span class="badge bg-secondary">待发布</span>',
        'publishing' => '<span class="badge bg-info">发布中</span>',
        'processing' => '<span class="badge bg-info">处理中</span>',
        'completed' => '<span class="badge bg-success">已完成</span>',
        'success' => '<span class="badge bg-success">成功</span>',
        'failed' => '<span class="badge bg-danger">失败</span>',
        'published' => '<span class="badge bg-success">已发布</span>',
        'active' => '<span class="badge bg-success">正常</span>',
        'inactive' => '<span class="badge bg-secondary">已禁用</span>',
    ];
    return $map[$status] ?? '<span class="badge bg-secondary">' . $status . '</span>';
}

/**
 * 记录日志
 */
function writeLog($module, $action, $content = '', $userId = null) {
    if (!$userId && isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
    }
    Database::getInstance()->insert('logs', [
        'user_id' => $userId,
        'module' => $module,
        'action' => $action,
        'content' => $content,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

/**
 * CURL请求
 */
function httpGet($url, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

function httpPost($url, $data, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($data) ? $data : http_build_query($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
