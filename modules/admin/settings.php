<?php
/**
 * 系统设置（管理员）
 */
$pageTitle = '系统设置';
$page = 'settings';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

if (!Auth::isAdmin()) {
    header('Location: /');
    exit;
}

$db = Database::getInstance();
$message = '';

// 确保site_settings表存在
try {
    $db->fetchOne("SELECT 1 FROM site_settings LIMIT 1");
} catch (Exception $e) {
    $db->query("CREATE TABLE IF NOT EXISTS `site_settings` (
        `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `setting_key` varchar(100) NOT NULL,
        `setting_value` text NOT NULL,
        `description` varchar(255) DEFAULT '',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_setting_key` (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 插入默认值
    $defaults = [
        ['allow_register', '1', '是否允许用户注册'],
        ['site_name', 'SEO Publisher', '站点名称'],
    ];
    foreach ($defaults as $d) {
        try {
            $db->insert('site_settings', ['setting_key' => $d[0], 'setting_value' => $d[1], 'description' => $d[2]]);
        } catch (Exception $e2) {}
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'save_settings') {
        $fields = ['allow_register', 'site_name'];
        foreach ($fields as $field) {
            $value = trim($_POST[$field] ?? '');
            $exists = $db->fetchOne("SELECT id FROM site_settings WHERE setting_key=?", [$field]);
            if ($exists) {
                $db->update('site_settings', ['setting_value' => $value], 'setting_key=?', [$field]);
            } else {
                $db->insert('site_settings', ['setting_key' => $field, 'setting_value' => $value]);
            }
        }
        $message = '设置保存成功';
    }
}

// 读取当前设置
function getSetting($db, $key, $default = '') {
    try {
        $row = $db->fetchOne("SELECT setting_value FROM site_settings WHERE setting_key=?", [$key]);
        return $row ? $row['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

$allowRegister = getSetting($db, 'allow_register', '1');
$siteName = getSetting($db, 'site_name', 'SEO Publisher');

// 支付记录
$paymentRecords = [];
try {
    $paymentRecords = $db->fetchAll(
        "SELECT o.*, u.username, p.name as package_name FROM orders o 
         LEFT JOIN users u ON o.user_id = u.id 
         LEFT JOIN packages p ON o.package_id = p.id 
         ORDER BY o.created_at DESC LIMIT 100"
    );
} catch (Exception $e) {}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-sliders"></i> 系统设置</h4>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success alert-auto-dismiss"><i class="bi bi-check-circle"></i> <?php echo e($message); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><i class="bi bi-gear"></i> 基本设置</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_settings">

                        <div class="mb-3">
                            <label class="form-label">站点名称</label>
                            <input type="text" name="site_name" class="form-control" value="<?php echo e($siteName); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">允许用户注册</label>
                            <select name="allow_register" class="form-select">
                                <option value="1" <?php echo $allowRegister === '1' ? 'selected' : ''; ?>>开启</option>
                                <option value="0" <?php echo $allowRegister === '0' ? 'selected' : ''; ?>>关闭</option>
                            </select>
                            <small class="form-text text-muted">关闭后，注册页面将提示"注册已关闭"，新用户无法自行注册</small>
                        </div>

                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 保存设置</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- 支付记录 -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><i class="bi bi-credit-card"></i> 支付记录</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>订单号</th>
                                    <th>用户</th>
                                    <th>套餐</th>
                                    <th>金额</th>
                                    <th>支付方式</th>
                                    <th>状态</th>
                                    <th>支付时间</th>
                                    <th>创建时间</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($paymentRecords)): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-4">暂无支付记录</td></tr>
                                <?php else: ?>
                                    <?php foreach ($paymentRecords as $record): ?>
                                    <tr>
                                        <td><small><?php echo e($record['order_no']); ?></small></td>
                                        <td><?php echo e($record['username'] ?? '-'); ?></td>
                                        <td><?php echo e($record['package_name'] ?? '-'); ?></td>
                                        <td>¥<?php echo number_format($record['amount'], 2); ?></td>
                                        <td><?php echo e($record['payment_method'] ?? '-'); ?></td>
                                        <td><?php
                                            $statusMap = [
                                                'pending' => '<span class="badge bg-warning">待支付</span>',
                                                'paid' => '<span class="badge bg-success">已支付</span>',
                                                'cancelled' => '<span class="badge bg-secondary">已取消</span>',
                                                'refunded' => '<span class="badge bg-danger">已退款</span>',
                                            ];
                                            echo $statusMap[$record['status']] ?? '<span class="badge bg-secondary">' . e($record['status']) . '</span>';
                                        ?></td>
                                        <td><small class="text-muted"><?php echo $record['paid_at'] ?: '-'; ?></small></td>
                                        <td><small class="text-muted"><?php echo $record['created_at']; ?></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
