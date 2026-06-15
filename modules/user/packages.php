<?php
/**
 * 套餐购买
 */
$pageTitle = '套餐购买';
$page = 'user';
require_once __DIR__ . '/../../includes/layout/header.php';
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy_package') {
    $pkgId = intval($_POST['package_id'] ?? 0);
    $pkg = $db->fetchOne("SELECT * FROM packages WHERE id=? AND status=1", [$pkgId]);
    
    if ($pkg) {
        $orderNo = 'ORD' . date('YmdHis') . rand(1000, 9999);
        $db->insert('orders', [
            'order_no' => $orderNo,
            'user_id' => $userId,
            'package_id' => $pkgId,
            'amount' => $pkg['price'],
            'status' => 'paid', // 简化处理，直接标记为已支付
            'payment_method' => 'manual',
            'paid_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 激活套餐
        $db->insert('user_packages', [
            'user_id' => $userId,
            'package_id' => $pkgId,
            'articles_used' => 0,
            'keywords_used' => 0,
            'status' => 'active',
            'expire_time' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $message = '套餐购买成功！订单号: ' . $orderNo;
        writeLog('order', '购买套餐', $pkg['name'] . ' - ' . $orderNo);
    }
}

$packages = $db->fetchAll("SELECT * FROM packages WHERE status=1 ORDER BY price ASC");
$currentPackage = (new Auth())->getUserPackage($userId);
?>

<div class="container-fluid">
    <h4 class="mb-4"><i class="bi bi-box"></i> 套餐购买</h4>

    <?php if ($message): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle"></i> <?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if ($currentPackage): ?>
    <div class="alert alert-info mb-4">
        <i class="bi bi-info-circle"></i> 
        当前套餐: <strong><?php echo e($currentPackage['package_name']); ?></strong>，到期时间: <?php echo $currentPackage['expire_time']; ?>
    </div>
    <?php endif; ?>

    <div class="row">
        <?php foreach ($packages as $pkg): ?>
        <div class="col-md-3 mb-4">
            <div class="card text-center h-100 <?php echo ($currentPackage && $currentPackage['package_id'] == $pkg['id']) ? 'border-primary' : ''; ?>">
                <div class="card-header <?php echo $pkg['price'] == 0 ? 'bg-success' : 'bg-primary'; ?> text-white">
                    <h5 class="mb-0"><?php echo e($pkg['name']); ?></h5>
                </div>
                <div class="card-body">
                    <div class="display-4 fw-bold mb-3">
                        ¥<?php echo number_format($pkg['price'], 0); ?>
                        <small class="fs-6 text-muted">/月</small>
                    </div>
                    <ul class="list-unstyled">
                        <li class="mb-2"><i class="bi bi-check-circle text-success"></i> <?php echo $pkg['article_limit']; ?> 篇文章/月</li>
                        <li class="mb-2"><i class="bi bi-check-circle text-success"></i> <?php echo $pkg['keyword_limit']; ?> 个关键词/月</li>
                        <?php if ($pkg['description']): ?>
                        <li class="mb-2 text-muted"><?php echo e($pkg['description']); ?></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="card-footer">
                    <?php if ($currentPackage && $currentPackage['package_id'] == $pkg['id']): ?>
                        <button class="btn btn-secondary w-100" disabled>当前套餐</button>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="buy_package">
                            <input type="hidden" name="package_id" value="<?php echo $pkg['id']; ?>">
                            <button type="submit" class="btn btn-primary w-100" onclick="return confirm('确认购买此套餐？')">
                                <?php echo $pkg['price'] == 0 ? '免费使用' : '立即购买'; ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/layout/footer.php'; ?>
