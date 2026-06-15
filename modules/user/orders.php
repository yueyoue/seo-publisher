<?php
/**
 * 我的订单
 */
$pageTitle = '我的订单';
$page = 'user';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

$pageNum = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($pageNum - 1) * $perPage;

$orders = $db->fetchAll(
    "SELECT o.*, p.name as package_name FROM orders o LEFT JOIN packages p ON o.package_id=p.id WHERE o.user_id=? ORDER BY o.created_at DESC LIMIT ? OFFSET ?",
    [$userId, $perPage, $offset]
);
$total = $db->count('orders', 'user_id=?', [$userId]);
?>

<div class="container-fluid">
    <h4 class="mb-4"><i class="bi bi-receipt"></i> 我的订单</h4>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>订单号</th>
                            <th>套餐</th>
                            <th>金额</th>
                            <th>状态</th>
                            <th>支付时间</th>
                            <th>创建时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">暂无订单</td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><code><?php echo e($order['order_no']); ?></code></td>
                                <td><?php echo e($order['package_name'] ?? '-'); ?></td>
                                <td>¥<?php echo number_format($order['amount'], 2); ?></td>
                                <td>
                                    <?php
                                    $statusMap = [
                                        'pending' => '<span class="badge bg-warning">待支付</span>',
                                        'paid' => '<span class="badge bg-success">已支付</span>',
                                        'cancelled' => '<span class="badge bg-secondary">已取消</span>',
                                        'refunded' => '<span class="badge bg-info">已退款</span>',
                                    ];
                                    echo $statusMap[$order['status']] ?? $order['status'];
                                    ?>
                                </td>
                                <td><small class="text-muted"><?php echo $order['paid_at'] ?? '-'; ?></small></td>
                                <td><small class="text-muted"><?php echo $order['created_at']; ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php echo pagination($total, $pageNum, $perPage); ?>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
