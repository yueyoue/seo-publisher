<?php
/**
 * 管理员API - AI模型管理等
 */
ob_start();

require_once __DIR__ . '/../config/init.php';
require_once ROOT_PATH . '/includes/Functions.php';

// 登录检查（失败会exit，ob_start会自动flush）
Auth::check();

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

// 检查管理员权限
if (!Auth::isAdmin()) {
    ob_end_clean();
    jsonResponse(['success' => false, 'message' => '权限不足'], 403);
}

$action = $_GET['action'] ?? '';

// 确保ai_models表存在
function ensureAiModelsTable($db) {
    try {
        $db->fetchOne("SELECT 1 FROM ai_models LIMIT 1");
        return true;
    } catch (Exception $e) {
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `ai_models` (
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
            return true;
        } catch (Exception $e2) {
            return false;
        }
    }
}

// 获取默认内置模型列表
function getBuiltinModels() {
    return [
        ['model_key' => 'deepseek', 'model_name' => 'DeepSeek', 'api_endpoint' => 'https://api.deepseek.com/v1/chat/completions', 'model_id' => 'deepseek-chat', 'is_builtin' => 1, 'status' => 1, 'sort_order' => 1],
        ['model_key' => 'gpt', 'model_name' => 'GPT (OpenAI)', 'api_endpoint' => 'https://api.openai.com/v1/chat/completions', 'model_id' => 'gpt-4o-mini', 'is_builtin' => 1, 'status' => 1, 'sort_order' => 2],
        ['model_key' => 'mimo', 'model_name' => 'MiMo', 'api_endpoint' => 'https://api.xiaomimimo.com/v1/chat/completions', 'model_id' => 'mimo-v2-flash', 'is_builtin' => 1, 'status' => 1, 'sort_order' => 3],
        ['model_key' => 'qwen', 'model_name' => '通义千问', 'api_endpoint' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions', 'model_id' => 'qwen-turbo', 'is_builtin' => 1, 'status' => 1, 'sort_order' => 4],
        ['model_key' => 'glm', 'model_name' => 'ChatGLM (智谱)', 'api_endpoint' => 'https://open.bigmodel.cn/api/paas/v4/chat/completions', 'model_id' => 'glm-4-flash', 'is_builtin' => 1, 'status' => 1, 'sort_order' => 5],
        ['model_key' => 'kimi', 'model_name' => 'Kimi (月之暗面)', 'api_endpoint' => 'https://api.moonshot.cn/v1/chat/completions', 'model_id' => 'moonshot-v1-8k', 'is_builtin' => 1, 'status' => 1, 'sort_order' => 6],
        ['model_key' => 'doubao', 'model_name' => '豆包 (字节)', 'api_endpoint' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions', 'model_id' => 'doubao-lite-4k', 'is_builtin' => 1, 'status' => 1, 'sort_order' => 7],
        ['model_key' => 'yi', 'model_name' => '零一万物', 'api_endpoint' => 'https://api.lingyiwanwu.com/v1/chat/completions', 'model_id' => 'yi-lightning', 'is_builtin' => 1, 'status' => 1, 'sort_order' => 8],
        ['model_key' => 'baichuan', 'model_name' => '百川智能', 'api_endpoint' => 'https://api.baichuan-ai.com/v1/chat/completions', 'model_id' => 'Baichuan4-Turbo', 'is_builtin' => 1, 'status' => 1, 'sort_order' => 9],
        ['model_key' => 'hunyuan', 'model_name' => '腾讯混元', 'api_endpoint' => 'https://api.hunyuan.cloud.tencent.com/v1/chat/completions', 'model_id' => 'hunyuan-turbo', 'is_builtin' => 1, 'status' => 1, 'sort_order' => 10],
    ];
}

// 清除缓冲区，输出JSON
ob_end_clean();

switch ($action) {
    // ========== AI模型管理 ==========
    case 'list_models':
        ensureAiModelsTable($db);

        // 获取数据库中的模型
        $models = [];
        try {
            $models = $db->fetchAll("SELECT * FROM ai_models ORDER BY is_builtin DESC, sort_order ASC, id ASC");
        } catch (Exception $e) {
            $models = [];
        }

        $builtin = getBuiltinModels();
        $builtinKeys = [];
        foreach ($builtin as $b) {
            $builtinKeys[$b['model_key']] = $b;
        }

        // 如果数据库为空，插入默认内置模型
        if (empty($models)) {
            foreach ($builtin as $m) {
                try {
                    $m['created_at'] = date('Y-m-d H:i:s');
                    $db->insert('ai_models', $m);
                } catch (Exception $e) {}
            }
            try {
                $models = $db->fetchAll("SELECT * FROM ai_models ORDER BY is_builtin DESC, sort_order ASC, id ASC");
            } catch (Exception $e) {
                $models = $builtin;
            }
        } else {
            // 自动同步内置模型的端点和模型ID（仅当用户未自定义时）
            foreach ($models as $m) {
                if ($m['is_builtin'] && isset($builtinKeys[$m['model_key']])) {
                    $b = $builtinKeys[$m['model_key']];
                    // 只同步模型名称，不覆盖用户自定义的端点和模型ID
                    if ($m['model_name'] !== $b['model_name']) {
                        try {
                            $db->update('ai_models', [
                                'model_name' => $b['model_name'],
                            ], 'id=?', [$m['id']]);
                            $m['model_name'] = $b['model_name'];
                        } catch (Exception $e) {}
                    }
                }
            }
            // 重新获取更新后的数据
            try {
                $models = $db->fetchAll("SELECT * FROM ai_models ORDER BY is_builtin DESC, sort_order ASC, id ASC");
            } catch (Exception $e) {}
        }

        jsonResponse(['success' => true, 'models' => $models]);
        break;

    case 'add_model':
        ensureAiModelsTable($db);

        $modelKey = trim($_POST['model_key'] ?? '');
        $modelName = trim($_POST['model_name'] ?? '');
        $apiEndpoint = trim($_POST['api_endpoint'] ?? '');
        $modelId = trim($_POST['model_id'] ?? '');

        if (empty($modelKey) || empty($modelName) || empty($apiEndpoint) || empty($modelId)) {
            jsonResponse(['success' => false, 'message' => '请填写所有必填项']);
        }

        // 自动补全端点路径
        $apiEndpoint = rtrim($apiEndpoint, '/');
        if (!preg_match('#/chat/completions$#', $apiEndpoint)) {
            $apiEndpoint .= '/chat/completions';
        }

        // 检查key是否重复
        $exists = $db->fetchOne("SELECT id FROM ai_models WHERE model_key=?", [$modelKey]);
        if ($exists) {
            jsonResponse(['success' => false, 'message' => '模型标识已存在']);
        }

        $db->insert('ai_models', [
            'model_key' => $modelKey,
            'model_name' => $modelName,
            'api_endpoint' => $apiEndpoint,
            'model_id' => $modelId,
            'is_builtin' => 0,
            'status' => 1,
            'sort_order' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        writeLog('admin', '添加AI模型', $modelName);
        jsonResponse(['success' => true, 'message' => '添加成功']);
        break;

    case 'edit_model':
        ensureAiModelsTable($db);

        $id = intval($_POST['id'] ?? 0);
        $modelName = trim($_POST['model_name'] ?? '');
        $apiEndpoint = trim($_POST['api_endpoint'] ?? '');
        $modelId = trim($_POST['model_id'] ?? '');
        $status = intval($_POST['status'] ?? 1);

        if (empty($modelName) || empty($apiEndpoint) || empty($modelId)) {
            jsonResponse(['success' => false, 'message' => '请填写所有必填项']);
        }

        // 自动补全端点路径
        $apiEndpoint = rtrim($apiEndpoint, '/');
        if (!preg_match('#/chat/completions$#', $apiEndpoint)) {
            $apiEndpoint .= '/chat/completions';
        }

        $db->update('ai_models', [
            'model_name' => $modelName,
            'api_endpoint' => $apiEndpoint,
            'model_id' => $modelId,
            'status' => $status,
        ], 'id=?', [$id]);

        writeLog('admin', '编辑AI模型', $modelName);
        jsonResponse(['success' => true, 'message' => '修改成功']);
        break;

    case 'delete_model':
        ensureAiModelsTable($db);

        $id = intval($_POST['id'] ?? 0);
        $model = $db->fetchOne("SELECT * FROM ai_models WHERE id=?", [$id]);
        if (!$model) {
            jsonResponse(['success' => false, 'message' => '模型不存在']);
        }
        if ($model['is_builtin']) {
            jsonResponse(['success' => false, 'message' => '内置模型不能删除']);
        }
        $db->delete('ai_models', 'id=?', [$id]);
        writeLog('admin', '删除AI模型', $model['model_name']);
        jsonResponse(['success' => true, 'message' => '删除成功']);
        break;

    // ========== 测试AI连接 ==========
    case 'test_model':
        $input = json_decode(file_get_contents('php://input'), true);
        $endpoint = trim($input['endpoint'] ?? '');
        $apiKey = trim($input['api_key'] ?? '');
        $modelId = trim($input['model_id'] ?? '');

        if (empty($endpoint) || empty($apiKey) || empty($modelId)) {
            jsonResponse(['success' => false, 'message' => '请填写API地址、API Key和模型ID']);
        }

        // 自动补全端点路径
        $endpoint = rtrim($endpoint, '/');
        if (!preg_match('#/chat/completions$#', $endpoint)) {
            $endpoint .= '/chat/completions';
        }

        $data = [
            'model' => $modelId,
            'messages' => [
                ['role' => 'user', 'content' => '你好，请回复"连接成功"两个字'],
            ],
            'max_tokens' => 50,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'api-key: ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            jsonResponse(['success' => false, 'message' => '连接失败: ' . $curlError]);
        }

        if ($response === false || $response === '') {
            jsonResponse(['success' => false, 'message' => '连接失败: 服务器无响应']);
        }

        $isHtml = stripos($response, '<html') !== false || stripos($response, '<!DOCTYPE') !== false;

        if ($httpCode !== 200) {
            if ($isHtml) {
                jsonResponse(['success' => false, 'message' => "连接失败 (HTTP {$httpCode}): API地址返回了HTML页面，请检查端点地址。正确格式如: https://api.xiaomimimo.com/v1/chat/completions"]);
            }
            $errJson = json_decode($response, true);
            if ($errJson && isset($errJson['error']['message'])) {
                jsonResponse(['success' => false, 'message' => "API错误 (HTTP {$httpCode}): " . $errJson['error']['message']]);
            }
            jsonResponse(['success' => false, 'message' => "连接失败 (HTTP {$httpCode}): " . mb_substr($response, 0, 300)]);
        }

        if ($isHtml) {
            jsonResponse(['success' => false, 'message' => 'API地址返回了HTML页面，请检查端点地址']);
        }

        $result = json_decode($response, true);
        if (!$result) {
            jsonResponse(['success' => false, 'message' => '响应不是有效JSON: ' . mb_substr($response, 0, 200)]);
        }

        if (isset($result['choices'][0]['message']['content'])) {
            $reply = trim($result['choices'][0]['message']['content']);
            jsonResponse(['success' => true, 'message' => "连接成功！模型: {$modelId}，回复: {$reply}"]);
        }

        jsonResponse(['success' => false, 'message' => '响应格式异常: ' . json_encode($result, JSON_UNESCAPED_UNICODE)]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => '未知操作'], 400);
}
