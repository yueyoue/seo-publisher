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
     * 长尾词挖掘 - 从百度相关搜索和底部推荐提取长尾关键词
     */
    public function getBaiduLongTail($keyword) {
        $results = [];

        // 方式1: 从百度搜索结果页提取所有相关搜索长尾词
        $url = 'https://www.baidu.com/s?wd=' . urlencode($keyword) . '&rn=50';
        $html = $this->httpGetBaidu($url, true);

        if ($html && is_string($html)) {
            // 提取相关搜索区域的链接
            if (preg_match_all('/id="rs".*?<\/table>/si', $html, $rsBlocks)) {
                foreach ($rsBlocks[0] as $block) {
                    if (preg_match_all('/<a[^>]*>(.*?)<\/a>/si', $block, $matches)) {
                        foreach ($matches[1] as $match) {
                            $kw = strip_tags(trim($match));
                            if ($kw && mb_strlen($kw) >= 4) {
                                $results[] = $kw;
                            }
                        }
                    }
                }
            }

            // 提取所有 /s?wd= 链接（通常是长尾词）
            if (preg_match_all('/<a[^>]*href="\/s\?wd=[^"]*"[^>]*>(.*?)<\/a>/si', $html, $matches)) {
                foreach ($matches[1] as $match) {
                    $kw = strip_tags(trim($match));
                    if ($kw && mb_strlen($kw) >= 4 && mb_strlen($kw) < 50 && $kw !== $keyword) {
                        $results[] = $kw;
                    }
                }
            }
        }

        // 方式2: 用百度下拉词API获取长尾变体
        $suggests = $this->getBaiduSuggest($keyword);
        foreach ($suggests as $kw) {
            if (mb_strlen($kw) >= 4) {
                $results[] = $kw;
            }
        }

        // 方式3: 二级扩展 - 对已有长尾词再次挖掘
        $expandWords = array_slice(array_unique($results), 0, 5);
        foreach ($expandWords as $expandWord) {
            if (count($results) >= 100) break;
            $subSuggests = $this->getBaiduSuggest($expandWord);
            foreach ($subSuggests as $word) {
                if (mb_strlen($word) >= 4) {
                    $results[] = $word;
                }
            }
            usleep(200000);
        }

        return array_unique(array_filter($results));
    }

    /**
     * 竞价词挖掘 - 从百度搜索结果中提取广告/竞价推广关键词
     */
    public function getBaiduBidWords($keyword) {
        $results = [];

        $url = 'https://www.baidu.com/s?wd=' . urlencode($keyword) . '&rn=50';
        $html = $this->httpGetBaidu($url, true);

        if (!$html || !is_string($html)) return [];

        // 方式1: 匹配 ec_tuiguang/ec_ad 相关的广告区域标题
        if (preg_match_all('/class="[^"]*ec_tuiguang[^"]*"[^>]*>.*?<a[^>]*>(.*?)<\/a>/si', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $kw = strip_tags(trim($match));
                if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) < 50) {
                    $results[] = $kw;
                }
            }
        }

        // 方式2: 匹配 data-tuiguang 属性的链接
        if (preg_match_all('/data-tuiguang[^>]*>.*?<a[^>]*>(.*?)<\/a>/si', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $kw = strip_tags(trim($match));
                if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) < 50) {
                    $results[] = $kw;
                }
            }
        }

        // 方式3: 匹配百度竞价广告区域（c-container 内的推广标记）
        if (preg_match_all('/<span[^>]*class="[^"]*c-text-danger[^"]*"[^>]*>.*?推广.*?<\/span>.*?<a[^>]*href="[^"]*"[^>]*>(.*?)<\/a>/si', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $kw = strip_tags(trim($match));
                if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) < 50) {
                    $results[] = $kw;
                }
            }
        }

        // 方式4: 匹配推广链接区域 - 提取广告标题
        if (preg_match_all('/id="content_left".*?推广.*?<h3[^>]*>.*?<a[^>]*>(.*?)<\/a>/si', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $kw = strip_tags(trim($match));
                if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) < 80) {
                    $results[] = $kw;
                }
            }
        }

        // 方式5: 匹配所有带有"广告"或"推广"标记区域附近的标题链接
        if (empty($results)) {
            if (preg_match_all('/(推广|广告).*?<a[^>]*href="[^"]*"[^>]*target="_blank"[^>]*>(.*?)<\/a>/si', $html, $matches)) {
                foreach ($matches[2] as $match) {
                    $kw = strip_tags(trim($match));
                    if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) < 80) {
                        $results[] = $kw;
                    }
                }
            }
        }

        return array_unique(array_filter($results));
    }

    /**
     * 指数词挖掘 - 从百度获取搜索指数相关词汇
     */
    public function getBaiduIndexWords($keyword) {
        $results = [];

        // 方式1: 尝试百度需求图谱API
        $url1 = 'https://index.baidu.com/api/SugApi/sug?word=' . urlencode($keyword);
        $response = $this->httpGetBaidu($url1, false);
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $item) {
                    $kw = is_string($item) ? trim($item) : (isset($item['word']) ? trim($item['word']) : '');
                    if ($kw) $results[] = $kw;
                }
            }
        }

        // 方式2: 尝试百度指数联想词API
        if (empty($results)) {
            $url2 = 'https://index.baidu.com/api/Interface/ptbk?word=' . urlencode($keyword);
            $response2 = $this->httpGetBaidu($url2, false);
            if ($response2) {
                $data2 = json_decode($response2, true);
                if (isset($data2['data']['wordlist']) && is_array($data2['data']['wordlist'])) {
                    foreach ($data2['data']['wordlist'] as $item) {
                        $kw = isset($item['word']) ? trim($item['word']) : '';
                        if ($kw) $results[] = $kw;
                    }
                }
            }
        }

        // 方式3: 从百度搜索页面提取"相关搜索"中的热门指数词
        if (empty($results)) {
            $html = $this->httpGetBaidu('https://www.baidu.com/s?wd=' . urlencode($keyword), true);
            if ($html && is_string($html)) {
                // 提取底部相关搜索
                if (preg_match_all('/id="rs".*?<a[^>]*>(.*?)<\/a>/si', $html, $matches)) {
                    foreach ($matches[1] as $match) {
                        $kw = strip_tags(trim($match));
                        if ($kw) $results[] = $kw;
                    }
                }
                // 提取"其他人还在搜"区域
                if (preg_match_all('/class="[^"]*rs-direct[^"]*"[^>]*>.*?<span[^>]*>(.*?)<\/span>/si', $html, $matches)) {
                    foreach ($matches[1] as $match) {
                        $kw = strip_tags(trim($match));
                        if ($kw) $results[] = $kw;
                    }
                }
            }
        }

        // 方式4: 用下拉词API作为补充
        if (empty($results)) {
            $suggests = $this->getBaiduSuggest($keyword);
            $results = array_merge($results, $suggests);
        }

        return array_unique(array_filter($results));
    }

    /**
     * 竞争对手网站关键词挖掘
     * 分析竞争站的meta标签、标题、H标签、高频词等提取关键词
     */
    public function mineCompetitorSite($url) {
        $results = [];

        // 确保URL有协议头
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        $html = $this->httpGetCompetitor($url);
        if (!$html || !is_string($html)) return [];

        // 1. 提取 meta keywords
        if (preg_match('/<meta[^>]*name=["\']keywords["\'][^>]*content=["\'](.*?)["\']/si', $html, $match)) {
            $keywords = preg_split('/[,，;；]/', $match[1]);
            foreach ($keywords as $kw) {
                $kw = trim(strip_tags($kw));
                if ($kw && mb_strlen($kw) >= 2) $results[] = $kw;
            }
        }

        // 2. 提取 meta description 中的关键词短语
        if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/si', $html, $match)) {
            $desc = trim(strip_tags($match[1]));
            // 按标点分句，取短句作为关键词
            $sentences = preg_split('/[。！？，、；\.\!\?\,]/u', $desc);
            foreach ($sentences as $s) {
                $s = trim($s);
                if (mb_strlen($s) >= 2 && mb_strlen($s) <= 30) {
                    $results[] = $s;
                }
            }
        }

        // 3. 提取 title
        if (preg_match('/<title>(.*?)<\/title>/si', $html, $match)) {
            $title = trim(strip_tags($match[1]));
            if ($title) {
                $results[] = $title;
                // 按分隔符拆分标题
                $parts = preg_split('/[-_|—–·,，]/u', $title);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (mb_strlen($part) >= 2 && mb_strlen($part) <= 30) {
                        $results[] = $part;
                    }
                }
            }
        }

        // 4. 提取 H1-H3 标签内容
        if (preg_match_all('/<h[1-3][^>]*>(.*?)<\/h[1-3]>/si', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $kw = trim(strip_tags($match));
                if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) <= 50) {
                    $results[] = $kw;
                }
            }
        }

        // 5. 提取导航链接文本（nav/menu/sidebar区域）
        if (preg_match_all('/<(nav|div)[^>]*class="[^"]*(?:nav|menu|sidebar|category)[^"]*"[^>]*>.*?<a[^>]*>(.*?)<\/a>/si', $html, $matches)) {
            foreach ($matches[2] as $match) {
                $kw = trim(strip_tags($match));
                if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) <= 20) {
                    $results[] = $kw;
                }
            }
        }

        // 6. 提取高频词组（从页面正文中提取）
        $bodyText = strip_tags($html);
        $bodyText = preg_replace('/\s+/u', ' ', $bodyText);

        // 提取2-6个字的中文词组，统计频率
        if (preg_match_all('/[\x{4e00}-\x{9fa5}]{2,6}/u', $bodyText, $wordMatches)) {
            $wordFreq = [];
            foreach ($wordMatches[0] as $word) {
                if (!isset($wordFreq[$word])) {
                    $wordFreq[$word] = 0;
                }
                $wordFreq[$word]++;
            }
            // 过滤出现3次以上的词，按频率排序
            arsort($wordFreq);
            $topWords = array_slice(array_keys($wordFreq), 0, 50);
            foreach ($topWords as $word) {
                if ($wordFreq[$word] >= 3) {
                    $results[] = $word;
                }
            }
        }

        // 7. 提取所有链接的锚文本
        if (preg_match_all('/<a[^>]*>(.*?)<\/a>/si', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $kw = trim(strip_tags($match));
                if ($kw && mb_strlen($kw) >= 2 && mb_strlen($kw) <= 30 && !preg_match('/^(首页|home|返回|更多|登录|注册|关于|联系|下载|查看详情|了解更多)$/iu', $kw)) {
                    $results[] = $kw;
                }
            }
        }

        return array_unique(array_filter($results));
    }

    /**
     * 竞争对手网站HTTP请求
     */
    private function httpGetCompetitor($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->getRandomUA());
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) return '';
        return is_string($response) ? $response : '';
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

        $miningType = $task['mining_type'] ?? 'search_engine';
        $allKeywords = [];
        $keywordSources = [];

        try {
            // 竞争对手挖词模式
            if ($miningType === 'competitor') {
                $competitorUrl = $task['competitor_url'] ?? '';
                if (empty($competitorUrl)) {
                    $this->db->update('keyword_tasks', [
                        'status' => 'failed',
                        'completed_at' => date('Y-m-d H:i:s'),
                    ], 'id=?', [$taskId]);
                    return 0;
                }

                $competitorKeywords = $this->mineCompetitorSite($competitorUrl);
                foreach ($competitorKeywords as $kw) {
                    $allKeywords[] = $kw;
                    $keywordSources[$kw] = 'competitor';
                }
            } else {
                // 搜索引擎挖词模式（原有逻辑）
                $sourceTypes = $task['source_types'] ?? 'suggest,related,also_search';

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

                // 长尾词挖掘
                if (strpos($sourceTypes, 'longtail') !== false) {
                    $longtail = $this->getBaiduLongTail($keyword);
                    if (is_array($longtail)) {
                        foreach ($longtail as $kw) {
                            $allKeywords[] = $kw;
                            if (!isset($keywordSources[$kw])) {
                                $keywordSources[$kw] = 'longtail';
                            }
                        }
                    }
                }

                // 竞价词挖掘
                if (strpos($sourceTypes, 'bidwords') !== false) {
                    $bidWords = $this->getBaiduBidWords($keyword);
                    if (is_array($bidWords)) {
                        foreach ($bidWords as $kw) {
                            $allKeywords[] = $kw;
                            if (!isset($keywordSources[$kw])) {
                                $keywordSources[$kw] = 'bidwords';
                            }
                        }
                    }
                }

                // 指数词挖掘
                if (strpos($sourceTypes, 'indexwords') !== false) {
                    $indexWords = $this->getBaiduIndexWords($keyword);
                    if (is_array($indexWords)) {
                        foreach ($indexWords as $kw) {
                            $allKeywords[] = $kw;
                            if (!isset($keywordSources[$kw])) {
                                $keywordSources[$kw] = 'indexwords';
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
