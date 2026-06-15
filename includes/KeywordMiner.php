<?php
/**
 * 百度关键词挖掘器
 * 修复：gzip编码、JSONP解析、相关词正则、User-Agent轮换
 */
class KeywordMiner {
    private $db;
    private $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ];

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * 获取随机User-Agent
     */
    private function getRandomUA() {
        return $this->userAgents[array_rand($this->userAgents)];
    }

    /**
     * 挖掘百度下拉词 (Suggestion API)
     * 修复：正确解析JSONP格式，添加gzip支持
     */
    public function getBaiduSuggest($keyword) {
        $results = [];

        // 方法1: 百度搜索建议API (JSONP格式)
        $url = 'https://suggestion.baidu.com/su?wd=' . urlencode($keyword) . '&json=1&p=3';
        $response = $this->httpGetBaidu($url, false);

        if ($response) {
            $json = $this->parseJsonp($response);
            if ($json && isset($json['s']) && is_array($json['s'])) {
                foreach ($json['s'] as $word) {
                    $word = trim($word);
                    if (!empty($word)) {
                        $results[] = $word;
                    }
                }
            }
        }

        // 方法2: 百度sugrec API (直接JSON)
        if (empty($results)) {
            $url2 = 'https://www.baidu.com/sugrec?pre=1&p=3&ie=utf-8&json=1&prod=pc&from=pc_web&wd=' . urlencode($keyword);
            $response2 = $this->httpGetBaidu($url2, true);
            if ($response2) {
                $data = json_decode($response2, true);
                if (isset($data['g']) && is_array($data['g'])) {
                    foreach ($data['g'] as $item) {
                        if (isset($item['q'])) {
                            $word = trim($item['q']);
                            if (!empty($word) && !in_array($word, $results)) {
                                $results[] = $word;
                            }
                        }
                    }
                }
            }
        }

        // 方法3: 百度输入法建议API
        if (empty($results)) {
            $url3 = 'https://shurufa.baidu.com/api/sug?word=' . urlencode($keyword) . '&inputtype=py&num=50';
            $response3 = $this->httpGetBaidu($url3, false);
            if ($response3) {
                $data = json_decode($response3, true);
                if (isset($data['data']) && is_array($data['data'])) {
                    foreach ($data['data'] as $item) {
                        if (isset($item['word'])) {
                            $word = trim($item['word']);
                            if (!empty($word) && !in_array($word, $results)) {
                                $results[] = $word;
                            }
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * 挖掘百度相关搜索词
     * 修复：使用更宽松的正则匹配，适配百度页面结构变化
     */
    public function getBaiduRelated($keyword) {
        $url = 'https://www.baidu.com/s?wd=' . urlencode($keyword) . '&rn=50';
        $html = $this->httpGetBaidu($url, true);

        if (!$html || !is_string($html)) return [];

        $keywords = [];

        // 方式1: 从相关搜索区域提取 - 匹配 id="rs" 区块中的链接
        if (preg_match_all('/id="rs".*?<\/table>/si', $html, $rsBlocks)) {
            foreach ($rsBlocks[0] as $block) {
                if (!is_string($block)) continue;
                if (preg_match_all('/<a[^>]*>(.*?)<\/a>/si', $block, $matches)) {
                    foreach ($matches[1] as $match) {
                        $kw = strip_tags(trim($match));
                        if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) < 50) {
                            $keywords[] = $kw;
                        }
                    }
                }
            }
        }

        // 方式2: 匹配所有带 data-show-delight 属性的链接（百度相关搜索新格式）
        if (empty($keywords)) {
            if (preg_match_all('/data-show-delight[^>]*>(.*?)<\/a>/si', $html, $matches)) {
                foreach ($matches[1] as $match) {
                    $kw = strip_tags(trim($match));
                    if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) < 50) {
                        $keywords[] = $kw;
                    }
                }
            }
        }

        // 方式3: 匹配底部相关搜索区域的链接文本
        if (empty($keywords)) {
            if (preg_match_all('/<div[^>]*class="[^"]*rs[^"]*"[^>]*>.*?<a[^>]*href="\/s\?wd=[^"]*"[^>]*>(.*?)<\/a>/si', $html, $matches)) {
                foreach ($matches[1] as $match) {
                    $kw = strip_tags(trim($match));
                    if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) < 50) {
                        $keywords[] = $kw;
                    }
                }
            }
        }

        // 方式4: 通用匹配 - 从页面中提取所有 /s?wd= 链接的文本
        if (empty($keywords)) {
            if (preg_match_all('/<a[^>]*href="\/s\?wd=[^"]*"[^>]*>(.*?)<\/a>/si', $html, $matches)) {
                foreach ($matches[1] as $match) {
                    $kw = strip_tags(trim($match));
                    if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) < 50 && $kw !== $keyword) {
                        $keywords[] = $kw;
                    }
                }
            }
        }

        return array_unique(array_filter($keywords));
    }

    /**
     * 挖掘百度"大家还在搜"
     */
    public function getBaiduAlsoSearch($keyword) {
        $url = 'https://www.baidu.com/s?wd=' . urlencode($keyword);
        $html = $this->httpGetBaidu($url, true);

        if (!$html || !is_string($html)) return [];

        $keywords = [];

        // 方式1: 匹配 rs-direct 区域的 span 文本
        if (preg_match_all('/class="[^"]*rs-direct[^"]*"[^>]*>.*?<span[^>]*>(.*?)<\/span>/si', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $kw = strip_tags(trim($match));
                if ($kw) $keywords[] = $kw;
            }
        }

        // 方式2: 匹配 relatedQuery 链接
        if (empty($keywords)) {
            if (preg_match_all('/<a[^>]*class="[^"]*relatedQuery[^"]*"[^>]*>(.*?)<\/a>/si', $html, $matches)) {
                foreach ($matches[1] as $match) {
                    $kw = strip_tags(trim($match));
                    if ($kw) $keywords[] = $kw;
                }
            }
        }

        // 方式3: 匹配 "大家还在搜" 文本附近的链接
        if (empty($keywords)) {
            if (preg_match('/大家还在搜.*?<\/div>/si', $html, $block)) {
                if (preg_match_all('/>([^<]{2,30})<\/a>/', $block[0], $matches)) {
                    foreach ($matches[1] as $match) {
                        $kw = strip_tags(trim($match));
                        if ($kw) $keywords[] = $kw;
                    }
                }
            }
        }

        return array_unique(array_filter($keywords));
    }

    /**
     * 执行挖词任务
     */
    public function executeTask($taskId) {
        $task = $this->db->fetchOne("SELECT * FROM keyword_tasks WHERE id=?", [$taskId]);
        if (!$task) return false;

        $this->db->update('keyword_tasks', ['status' => 'processing'], 'id=?', [$taskId]);

        $keyword = $task['keyword'];
        $mustContain = $task['must_contain'] ? array_filter(explode(' ', $task['must_contain'])) : [];
        $maxCount = $task['keyword_count'] ?: 50;

        $sourceTypes = $task['source_types'] ?? 'suggest,related,also_search';
        $allKeywords = [];
        $keywordSources = []; // 跟踪每个关键词的来源

        try {
            // 挖掘下拉词
            if (strpos($sourceTypes, 'suggest') !== false) {
                $suggests = $this->getBaiduSuggest($keyword);
                if (is_array($suggests)) {
                    foreach ($suggests as $kw) {
                        $allKeywords[] = $kw;
                        $keywordSources[$kw] = 'suggest';
                    }
                }
            }

            // 挖掘相关词
            if (strpos($sourceTypes, 'related') !== false) {
                $related = $this->getBaiduRelated($keyword);
                if (is_array($related)) {
                    foreach ($related as $kw) {
                        $allKeywords[] = $kw;
                        if (!isset($keywordSources[$kw])) {
                            $keywordSources[$kw] = 'related';
                        }
                    }
                }
            }

            // 挖掘大家还在搜
            if (strpos($sourceTypes, 'also_search') !== false) {
                $alsoSearch = $this->getBaiduAlsoSearch($keyword);
                if (is_array($alsoSearch)) {
                    foreach ($alsoSearch as $kw) {
                        $allKeywords[] = $kw;
                        if (!isset($keywordSources[$kw])) {
                            $keywordSources[$kw] = 'also_search';
                        }
                    }
                }
            }

            // 二级扩展 - 用已有结果继续挖词
            if (count($allKeywords) < $maxCount) {
                $expandWords = array_slice(array_unique($allKeywords), 0, 3);
                foreach ($expandWords as $expandWord) {
                    if (count($allKeywords) >= $maxCount) break;
                    $subSuggests = $this->getBaiduSuggest($expandWord);
                    if (is_array($subSuggests)) {
                        foreach ($subSuggests as $word) {
                            if (count($allKeywords) >= $maxCount) break;
                            if (!in_array($word, $allKeywords)) {
                                $allKeywords[] = $word;
                                $keywordSources[$word] = 'suggest';
                            }
                        }
                    }
                    usleep(300000); // 300ms延迟避免被封
                }
            }
        } catch (\Exception $e) {
            writeLog('keyword', '挖词异常', $e->getMessage(), $task['user_id']);
        }

        // 去重
        $allKeywords = array_unique(array_filter($allKeywords));

        // 过滤必须包含词
        if (!empty($mustContain)) {
            $allKeywords = array_filter($allKeywords, function($kw) use ($mustContain) {
                foreach ($mustContain as $must) {
                    if ($must && mb_strpos($kw, $must) !== false) return true;
                }
                return false;
            });
        }

        // 限制数量
        $allKeywords = array_slice(array_values($allKeywords), 0, $maxCount);

        // 保存结果
        $savedCount = 0;
        foreach ($allKeywords as $kw) {
            if (empty($kw)) continue;

            // 检查是否已存在
            $exists = $this->db->count('keyword_results', 'keyword=? AND task_id=?', [$kw, $taskId]);
            if ($exists) continue;

            $this->db->insert('keyword_results', [
                'task_id' => $taskId,
                'user_id' => $task['user_id'],
                'keyword' => $kw,
                'source' => $keywordSources[$kw] ?? 'baidu',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $savedCount++;
        }

        // 更新任务状态
        $this->db->update('keyword_tasks', [
            'status' => 'completed',
            'found_count' => $savedCount,
            'completed_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$taskId]);

        writeLog('keyword', '挖掘完成', "关键词:{$keyword}, 找到:{$savedCount}个", $task['user_id']);

        return $savedCount;
    }

    /**
     * 获取任务列表
     */
    public function getTasks($userId, $page = 1, $perPage = 20) {
        $offset = ($page - 1) * $perPage;
        $tasks = $this->db->fetchAll(
            "SELECT * FROM keyword_tasks WHERE user_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$userId, $perPage, $offset]
        );
        $total = $this->db->count('keyword_tasks', 'user_id=?', [$userId]);
        return ['list' => $tasks, 'total' => $total];
    }

    /**
     * 获取任务结果
     */
    public function getResults($taskId, $page = 1, $perPage = 50) {
        $offset = ($page - 1) * $perPage;
        $results = $this->db->fetchAll(
            "SELECT * FROM keyword_results WHERE task_id=? ORDER BY id DESC LIMIT ? OFFSET ?",
            [$taskId, $perPage, $offset]
        );
        $total = $this->db->count('keyword_results', 'task_id=?', [$taskId]);
        return ['list' => $results, 'total' => $total];
    }

    /**
     * 带百度Headers的HTTP请求
     * 修复：正确设置gzip编码、添加Referer、使用随机UA
     */
    private function httpGetBaidu($url, $withCookie = false) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');  // 修复：正确设置gzip编码
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->getRandomUA());
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.baidu.com/');

        if ($withCookie) {
            $cookieFile = sys_get_temp_dir() . '/baidu_' . md5($url . microtime(true));
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // 清理临时cookie文件
        if ($withCookie && isset($cookieFile) && file_exists($cookieFile)) {
            @unlink($cookieFile);
        }

        if ($response === false || $httpCode >= 400) {
            return '';
        }
        // 确保返回字符串（防止gzip解码异常返回非字符串）
        return is_string($response) ? $response : '';
    }

    /**
     * 解析JSONP响应
     * 修复：支持多种百度JSONP格式
     */
    private function parseJsonp($response) {
        // 格式1: window.baidu.sug({...})
        if (preg_match('/window\.baidu\.sug\((.*)\)/s', $response, $matches)) {
            return json_decode($matches[1], true);
        }

        // 格式2: baidu.sug({...})
        if (preg_match('/baidu\.sug\((.*)\)/s', $response, $matches)) {
            return json_decode($matches[1], true);
        }

        // 格式3: 直接JSON
        $json = json_decode($response, true);
        if ($json && is_array($json)) {
            return $json;
        }

        // 格式4: 提取第一个JSON对象
        if (preg_match('/\{[^{}]*"s"\s*:\s*\[.*?\][^{}]*\}/s', $response, $matches)) {
            return json_decode($matches[0], true);
        }

        return null;
    }
}
