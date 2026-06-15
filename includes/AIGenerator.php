<?php
/**
 * AI文章生成器 - 支持多种模型
 */
class AIGenerator {
    private $db;
    
    // 支持的模型配置
    private $models = [
        'deepseek' => [
            'name' => 'DeepSeek',
            'endpoint' => 'https://api.deepseek.com/v1/chat/completions',
            'model' => 'deepseek-chat',
        ],
        'gpt' => [
            'name' => 'GPT (OpenAI)',
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
            'model' => 'gpt-4o-mini',
        ],
        'mimo' => [
            'name' => 'MiMo',
            'endpoint' => 'https://api.xiaomimimo.com/v1/chat/completions',
            'model' => 'mimo-v2-flash',
        ],
        'qwen' => [
            'name' => '通义千问',
            'endpoint' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
            'model' => 'qwen-turbo',
        ],
        'glm' => [
            'name' => 'ChatGLM (智谱)',
            'endpoint' => 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
            'model' => 'glm-4-flash',
        ],
        'kimi' => [
            'name' => 'Kimi (月之暗面)',
            'endpoint' => 'https://api.moonshot.cn/v1/chat/completions',
            'model' => 'moonshot-v1-8k',
        ],
        'doubao' => [
            'name' => '豆包 (字节)',
            'endpoint' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions',
            'model' => 'doubao-lite-4k',
        ],
        'yi' => [
            'name' => '零一万物',
            'endpoint' => 'https://api.lingyiwanwu.com/v1/chat/completions',
            'model' => 'yi-lightning',
        ],
        'baichuan' => [
            'name' => '百川智能',
            'endpoint' => 'https://api.baichuan-ai.com/v1/chat/completions',
            'model' => 'Baichuan4-Turbo',
        ],
        'hunyuan' => [
            'name' => '腾讯混元',
            'endpoint' => 'https://api.hunyuan.cloud.tencent.com/v1/chat/completions',
            'model' => 'hunyuan-turbo',
        ],
    ];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * 生成文章
     */
    public function generateArticle($keyword, $config) {
        $modelKey = $config['model'] ?? 'deepseek';
        $modelConfig = $this->models[$modelKey] ?? $this->models['deepseek'];
        
        // 支持自定义端点
        if (!empty($config['api_endpoint'])) {
            $endpoint = rtrim($config['api_endpoint'], '/');
            if (!preg_match('#/chat/completions$#', $endpoint)) {
                $endpoint .= '/chat/completions';
            }
            $modelConfig['endpoint'] = $endpoint;
        }
        
        // 构建提示词
        $prompt = $this->buildPrompt($keyword, $config);
        
        // 调用AI API
        $result = $this->callAPI($modelConfig, $prompt, $config['api_key'] ?? '');
        
        if (!$result['success']) {
            return $result;
        }

        // 处理生成的内容
        $content = $result['content'];
        
        // 去除AI返回的代码块标记
        $content = preg_replace('/^\s*```(?:html)?\s*/i', '', $content);
        $content = preg_replace('/\s*```\s*$/', '', $content);
        $content = trim($content);
        
        // 如果AI返回了完整HTML文档，只提取<body>内的内容
        if (stripos($content, '<body') !== false) {
            if (preg_match('/<body[^>]*>(.*)<\/body>/is', $content, $bodyMatch)) {
                $content = trim($bodyMatch[1]);
            }
        } elseif (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<html') !== false) {
            // 有DOCTYPE或html标签但没有body，去掉这些文档结构标签
            $content = preg_replace('/<!DOCTYPE[^>]*>/i', '', $content);
            $content = preg_replace('/<\/?html[^>]*>/i', '', $content);
            $content = preg_replace('/<head[^>]*>.*?<\/head>/is', '', $content);
            $content = trim($content);
        }
        
        // 去除AI在HTML前添加的说明文字（如"这是一个为您定制的SEO文章..."）
        $firstTagPos = preg_match('/<(h[1-6]|p|div|article|section|main|ul|ol|table|br|hr|img)\b/i', $content, $matches, PREG_OFFSET_CAPTURE);
        if ($firstTagPos && $matches[0][1] > 0) {
            $beforeTag = substr($content, 0, $matches[0][1]);
            if (!preg_match('/<[a-zA-Z]/', $beforeTag)) {
                $content = substr($content, $matches[0][1]);
            }
        }
        
        // 去除内容中残留的<title>和<meta>标签
        $content = preg_replace('/<title[^>]*>.*?<\/title>/is', '', $content);
        $content = preg_replace('/<meta[^>]*>/i', '', $content);
        $content = trim($content);
        
        $title = $keyword;
        
        // 生成标题
        if (($config['title_type'] ?? 'original') === 'generate') {
            $titleResult = $this->generateTitle($keyword, $modelConfig, $config['api_key'] ?? '');
            if ($titleResult['success']) {
                $title = $titleResult['title'];
            }
        } elseif (($config['title_type'] ?? 'original') === 'double') {
            $titleResult = $this->generateTitle($keyword, $modelConfig, $config['api_key'] ?? '');
            if ($titleResult['success']) {
                $title = $title . '|' . $titleResult['title'];
            }
        }

        // 敏感词过滤
        if (!empty($config['sensitive_words'])) {
            $content = $this->filterSensitive($content, $config['sensitive_words']);
        }

        // 插入广告
        if (!empty($config['ad_paragraph']) || !empty($config['ad_ending'])) {
            $content = $this->insertAds($content, $config);
        }

        // 插入图片
        if (!empty($config['image_config'])) {
            $content = $this->insertImages($content, $config['image_config']);
        }

        return [
            'success' => true,
            'title' => $title,
            'content' => $content,
            'keyword' => $keyword,
        ];
    }

    /**
     * 批量生成文章
     */
    public function batchGenerate($keywords, $config) {
        $results = [];
        foreach ($keywords as $keyword) {
            $results[] = $this->generateArticle(trim($keyword), $config);
            usleep(500000); // 0.5秒间隔，避免API限流
        }
        return $results;
    }

    /**
     * 构建提示词
     */
    private function buildPrompt($keyword, $config) {
        switch ($config['article_type'] ?? 'short') {
            case 'long':
                $wordCount = '2000字左右';
                break;
            case 'custom':
                $wordCount = $config['custom_template'] ?? '1000字左右';
                break;
            default:
                $wordCount = '1000字左右';
        }

        $lang = ($config['language'] ?? 'zh') === 'en' ? '英文' : '中文';

        $prompt = "你是一个专业的SEO文章写手。请围绕关键词「{$keyword}」写一篇{$lang}文章。

要求：
1. 文章字数：{$wordCount}
2. 标题要吸引人，包含关键词
3. 内容要原创、有深度、对读者有价值
4. 合理使用H2、H3等小标题，结构清晰
5. 关键词自然分布在文章中，不要堆砌
6. 段落分明，每段不要太长
7. 适合搜索引擎收录
8. 输出HTML格式，包含h1/h2/h3/p/ul/li等标签

重要：只输出文章正文的HTML内容，不要输出<!DOCTYPE>、<html>、<head>、<body>、<meta>、<title>等完整的HTML文档结构标签。以<h1>标题标签开头即可。";

        if (!empty($config['custom_prompt'])) {
            $prompt .= "\n\n额外要求：\n" . $config['custom_prompt'];
        }

        return $prompt;
    }

    /**
     * 调用AI API
     */
    private function callAPI($modelConfig, $prompt, $apiKey) {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];

        $data = [
            'model' => $modelConfig['model'],
            'messages' => [
                ['role' => 'system', 'content' => '你是一个专业的SEO文章写手，擅长撰写高质量的原创文章。'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.8,
            'max_tokens' => 4000,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $modelConfig['endpoint']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => 'API请求失败: ' . $error];
        }

        if ($httpCode !== 200) {
            return ['success' => false, 'message' => 'API返回错误: HTTP ' . $httpCode . ' - ' . $response];
        }

        $result = json_decode($response, true);
        if (!$result || !isset($result['choices'][0]['message']['content'])) {
            return ['success' => false, 'message' => 'API响应解析失败'];
        }

        return ['success' => true, 'content' => $result['choices'][0]['message']['content']];
    }

    /**
     * 生成标题
     */
    private function generateTitle($keyword, $modelConfig, $apiKey) {
        $prompt = "请围绕关键词「{$keyword}」生成一个吸引人的SEO文章标题，只输出标题本身，不要其他内容。";

        $result = $this->callAPI($modelConfig, $prompt, $apiKey);
        if ($result['success']) {
            $title = trim($result['content'], "\n\r\t\"' ");
            return ['success' => true, 'title' => $title];
        }
        return $result;
    }

    /**
     * 敏感词过滤
     */
    private function filterSensitive($content, $words) {
        $wordList = array_filter(array_map('trim', explode("\n", $words)));
        foreach ($wordList as $word) {
            if ($word) {
                $content = str_replace($word, str_repeat('*', mb_strlen($word)), $content);
            }
        }
        return $content;
    }

    /**
     * 插入广告
     */
    private function insertAds($content, $config) {
        $paragraphs = explode("</p>", $content);
        $paragraphs = array_filter($paragraphs, fn($p) => trim($p));

        // 段落间广告
        if (!empty($config['ad_paragraph'])) {
            $ads = array_filter(array_map('trim', explode("\n", $config['ad_paragraph'])));
            if ($ads) {
                $position = $config['ad_paragraph_pos'] ?? 'after_first';
                $ad = $ads[array_rand($ads)];

                if ($position === 'before_first' && count($paragraphs) > 0) {
                    $paragraphs[0] = $ad . '</p>' . $paragraphs[0];
                } elseif ($position === 'after_first' && count($paragraphs) > 1) {
                    $paragraphs[0] .= '</p>' . $ad;
                }
            }
        }

        // 末段广告
        if (!empty($config['ad_ending'])) {
            $ads = array_filter(array_map('trim', explode("\n", $config['ad_ending'])));
            if ($ads) {
                $position = $config['ad_ending_pos'] ?? 'after_last';
                $ad = $ads[array_rand($ads)];
                $lastIdx = count($paragraphs) - 1;

                if ($position === 'before_last') {
                    $paragraphs[$lastIdx] = $ad . '</p>' . $paragraphs[$lastIdx];
                } else {
                    $paragraphs[$lastIdx] .= '</p>' . $ad;
                }
            }
        }

        return implode('</p>', $paragraphs);
    }

    /**
     * 插入图片
     */
    private function insertImages($content, $imageConfig) {
        $images = [];
        $source = $imageConfig['source'] ?? 'none';

        if ($source === 'custom' && !empty($imageConfig['urls'])) {
            $images = array_filter(array_map('trim', explode("\n", $imageConfig['urls'])));
        }

        if (empty($images)) return $content;

        $paragraphs = explode("</p>", $content);
        $paragraphs = array_filter($paragraphs, fn($p) => trim($p));
        $maxImages = min(count($images), $imageConfig['max_count'] ?? 2);
        $position = $imageConfig['position'] ?? 'after_1';

        // 根据position确定在哪些段落后插入
        $insertAfter = [];
        switch ($position) {
            case 'before_first': $insertAfter[] = 0; break;
            case 'after_first': $insertAfter[] = 0; break;
            case 'after_1': $insertAfter[] = 0; break;
            case 'after_2': $insertAfter[] = 1; break;
            case 'after_3': $insertAfter[] = 2; break;
            default: $insertAfter[] = 0;
        }

        $inserted = 0;
        $newParagraphs = [];
        foreach ($paragraphs as $idx => $p) {
            $newParagraphs[] = $p;
            if ($inserted < $maxImages && in_array($idx, $insertAfter)) {
                $img = $images[$inserted % count($images)];
                $newParagraphs[] = '<p><img src="' . htmlspecialchars($img) . '" alt="" style="max-width:100%;height:auto;" /></p>';
                $inserted++;
            }
        }

        return implode('</p>', $newParagraphs);
    }

    /**
     * 测试API连接
     */
    public function testConnection($modelKey, $apiKey, $customEndpoint = '') {
        // 支持从数据库获取模型配置
        $modelConfig = null;
        try {
            $dbModel = $this->db->fetchOne("SELECT * FROM ai_models WHERE model_key=? AND status=1", [$modelKey]);
            if ($dbModel) {
                $modelConfig = [
                    'name' => $dbModel['model_name'],
                    'endpoint' => $dbModel['api_endpoint'],
                    'model' => $dbModel['model_id'],
                ];
            }
        } catch (Exception $e) {}
        
        if (!$modelConfig) {
            $modelConfig = $this->models[$modelKey] ?? $this->models['deepseek'];
        }

        // 支持自定义端点
        $endpoint = $customEndpoint ?: $modelConfig['endpoint'];

        $data = [
            'model' => $modelConfig['model'],
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
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'message' => '连接失败: ' . $error];
        }

        // curl_exec 返回 false 或空
        if ($response === false || $response === '') {
            return ['success' => false, 'message' => '连接失败: 服务器无响应，请检查API地址是否正确'];
        }

        // 检测是否为HTML响应（无论HTTP状态码）
        $isHtml = stripos($contentType, 'text/html') !== false 
                   || stripos($response, '<html') !== false 
                   || stripos($response, '<!DOCTYPE') !== false
                   || stripos($response, '<head') !== false;

        if ($httpCode !== 200) {
            if ($isHtml) {
                return ['success' => false, 'message' => "连接失败 (HTTP {$httpCode}): API地址返回了HTML页面，请检查API端点地址是否正确"];
            }
            $errJson = json_decode($response, true);
            if ($errJson && isset($errJson['error']['message'])) {
                return ['success' => false, 'message' => "API错误 (HTTP {$httpCode}): " . $errJson['error']['message']];
            }
            $errDetail = mb_substr($response, 0, 300);
            return ['success' => false, 'message' => "连接失败 (HTTP {$httpCode}): {$errDetail}"];
        }

        // HTTP 200 但返回的是HTML
        if ($isHtml) {
            return ['success' => false, 'message' => 'API地址返回了HTML页面而非JSON数据，请检查端点地址是否正确。返回内容: ' . mb_substr(strip_tags($response), 0, 150)];
        }

        $result = json_decode($response, true);
        if (!$result) {
            // JSON解析失败，返回原始内容摘要
            return ['success' => false, 'message' => '响应不是有效JSON。返回内容: ' . mb_substr($response, 0, 200)];
        }

        if (isset($result['choices'][0]['message']['content'])) {
            $reply = trim($result['choices'][0]['message']['content']);
            return ['success' => true, 'message' => "连接成功！模型: {$modelConfig['name']}，回复: {$reply}"];
        }

        // 兼容不同的响应格式
        if (isset($result['message'])) {
            return ['success' => true, 'message' => "连接成功！" . $result['message']];
        }

        return ['success' => false, 'message' => '响应格式异常: ' . json_encode($result, JSON_UNESCAPED_UNICODE)];
    }

    /**
     * 获取支持的模型列表（内置+数据库自定义）
     */
    public function getModels() {
        try {
            $dbModels = $this->db->fetchAll("SELECT * FROM ai_models WHERE status=1 ORDER BY is_builtin DESC, sort_order ASC, id ASC");
            if (!empty($dbModels)) {
                // 只同步模型名称，不覆盖用户自定义的端点和模型ID
                foreach ($dbModels as &$m) {
                    if ($m['is_builtin'] && isset($this->models[$m['model_key']])) {
                        $builtin = $this->models[$m['model_key']];
                        if ($m['model_name'] !== $builtin['name']) {
                            try {
                                $this->db->update('ai_models', [
                                    'model_name' => $builtin['name'],
                                ], 'id=?', [$m['id']]);
                                $m['model_name'] = $builtin['name'];
                            } catch (Exception $e) {}
                        }
                    }
                }
                unset($m);
                
                $models = [];
                foreach ($dbModels as $m) {
                    $models[$m['model_key']] = [
                        'name' => $m['model_name'],
                        'endpoint' => $m['api_endpoint'],
                        'model' => $m['model_id'],
                    ];
                }
                return $models;
            }
        } catch (Exception $e) {
            // 表不存在时回退到内置模型
        }
        return $this->models;
    }
}
