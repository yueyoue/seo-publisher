<?php
/**
 * 迁移脚本 - 添加AI模型管理表 + 默认模型数据
 * 运行方式: 浏览器访问此文件，或命令行 php migrate_ai_models.php
 */
session_start();
require_once __DIR__ . '/../config/init.php';

if (!defined('INSTALLED') || !INSTALLED) {
    die('系统尚未安装');
}

$db = Database::getInstance();
$pdo = $db->getConnection();

try {
    // 检查表是否已存在
    $tableExists = $pdo->query("SHOW TABLES LIKE 'ai_models'")->fetch();

    if (!$tableExists) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `ai_models` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `model_key` varchar(50) NOT NULL,
            `model_name` varchar(100) NOT NULL,
            `api_endpoint` varchar(500) NOT NULL,
            `model_id` varchar(100) NOT NULL,
            `is_builtin` tinyint(1) NOT NULL DEFAULT 0,
            `status` tinyint(1) NOT NULL DEFAULT 1,
            `sort_order` int(11) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_model_key` (`model_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        echo "✅ ai_models 表创建成功\n";
    } else {
        echo "ℹ️ ai_models 表已存在\n";
    }

    // 插入默认模型
    $existing = $pdo->query("SELECT COUNT(*) FROM ai_models")->fetchColumn();
    if ($existing == 0) {
        $pdo->exec("INSERT INTO ai_models (model_key, model_name, api_endpoint, model_id, is_builtin, status, sort_order, created_at) VALUES
            ('deepseek', 'DeepSeek', 'https://api.deepseek.com/v1/chat/completions', 'deepseek-chat', 1, 1, 1, NOW()),
            ('gpt', 'GPT (OpenAI)', 'https://api.openai.com/v1/chat/completions', 'gpt-4o-mini', 1, 1, 2, NOW()),
            ('mimo', 'MiMo', 'https://api.xiaomimimo.com/v1/chat/completions', 'mimo-v2-flash', 1, 1, 3, NOW()),
            ('qwen', '通义千问', 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', 'qwen-turbo', 1, 1, 4, NOW()),
            ('glm', 'ChatGLM (智谱)', 'https://open.bigmodel.cn/api/paas/v4/chat/completions', 'glm-4-flash', 1, 1, 5, NOW()),
            ('kimi', 'Kimi (月之暗面)', 'https://api.moonshot.cn/v1/chat/completions', 'moonshot-v1-8k', 1, 1, 6, NOW()),
            ('doubao', '豆包 (字节)', 'https://ark.cn-beijing.volces.com/api/v3/chat/completions', 'doubao-lite-4k', 1, 1, 7, NOW()),
            ('yi', '零一万物', 'https://api.lingyiwanwu.com/v1/chat/completions', 'yi-lightning', 1, 1, 8, NOW()),
            ('baichuan', '百川智能', 'https://api.baichuan-ai.com/v1/chat/completions', 'Baichuan4-Turbo', 1, 1, 9, NOW()),
            ('hunyuan', '腾讯混元', 'https://api.hunyuan.cloud.tencent.com/v1/chat/completions', 'hunyuan-turbo', 1, 1, 10, NOW())");
        echo "✅ 10个默认AI模型已插入\n";
    } else {
        echo "ℹ️ 已有 {$existing} 个模型，跳过\n";
    }

    echo "\n🎉 迁移完成！\n";
    echo "管理入口: /modules/admin/ai_models.php\n";
    echo "全局配置中也可选择这些模型\n";

} catch (Exception $e) {
    echo "❌ 迁移失败: " . $e->getMessage() . "\n";
}
