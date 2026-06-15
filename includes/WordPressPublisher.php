<?php
/**
 * WordPress 发布器 - 支持 REST API + XML-RPC 双通道
 */
class WordPressPublisher {
    private $siteUrl;
    private $username;
    private $password;
    private $xmlrpcUrl;
    private $restUrl;
    private $debug = [];
    private $useRest = true; // 优先使用REST API

    public function __construct($siteUrl, $username, $password) {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->xmlrpcUrl = $this->siteUrl . '/xmlrpc.php';
        $this->restUrl = $this->siteUrl . '/wp-json/wp/v2';
    }

    public function getDebug() {
        return $this->debug;
    }

    /**
     * 测试连接 - REST API优先，回退XML-RPC
     */
    public function testConnection() {
        // 尝试 REST API (Application Password)
        $result = $this->restGet('/users/me');
        if ($result && isset($result['id'])) {
            $this->debug[] = 'REST API连接成功';
            return true;
        }

        // 尝试 REST API (不带认证，检查是否公开)
        $result2 = $this->restGetPublic('/posts?per_page=1');
        if ($result2 !== false) {
            $this->debug[] = 'REST API可用（公开），但认证失败';
        }

        // 回退 XML-RPC
        $xml = $this->buildXml('system.listMethods', []);
        $response = $this->sendRequest($xml);
        if ($response !== false && strpos($response, 'faultCode') === false) {
            $this->debug[] = 'XML-RPC连接成功';
            return true;
        }

        $this->debug[] = 'REST API: ' . $this->restUrl;
        $this->debug[] = 'XML-RPC: ' . $this->xmlrpcUrl;
        $this->debug[] = '两种方式均连接失败，请检查：1)域名是否正确 2)WordPress REST API是否启用 3)是否需要Application Password';
        return false;
    }

    /**
     * 获取网站分类/栏目 - REST API优先，回退XML-RPC
     */
    public function getCategories() {
        $this->debug = [];

        // 方法1: REST API
        $categories = $this->getCategoriesRest();
        if ($categories !== false && !empty($categories)) {
            $this->debug[] = 'REST API获取到 ' . count($categories) . ' 个栏目';
            return $categories;
        }

        // 方法2: XML-RPC
        $categories = $this->getCategoriesXmlRpc();
        if ($categories !== false && !empty($categories)) {
            $this->debug[] = 'XML-RPC获取到 ' . count($categories) . ' 个栏目';
            return $categories;
        }

        // 方法3: 不带认证的REST API（公开分类）
        $categories = $this->getCategoriesPublic();
        if ($categories !== false && !empty($categories)) {
            $this->debug[] = '公开API获取到 ' . count($categories) . ' 个栏目';
            return $categories;
        }

        $this->debug[] = '所有方式均获取栏目失败';
        return false;
    }

    /**
     * REST API 获取分类
     */
    private function getCategoriesRest() {
        $allCategories = [];
        $page = 1;

        while ($page <= 10) {
            $result = $this->restGet("/categories?per_page=100&page={$page}");
            if (!$result || !is_array($result) || empty($result)) break;

            foreach ($result as $cat) {
                $allCategories[] = [
                    'id' => $cat['id'],
                    'name' => $cat['name'],
                    'description' => $cat['description'] ?? '',
                    'parent' => $cat['parent'] ?? 0,
                    'count' => $cat['count'] ?? 0,
                ];
            }

            if (count($result) < 100) break;
            $page++;
        }

        return empty($allCategories) ? false : $allCategories;
    }

    /**
     * 不带认证的公开REST API获取分类
     */
    private function getCategoriesPublic() {
        $url = $this->restUrl . '/categories?per_page=100';
        $this->debug[] = '尝试公开REST API: ' . $url;

        $response = $this->httpGet($url);
        if (!$response) return false;

        $data = json_decode($response, true);
        if (!is_array($data)) return false;

        $categories = [];
        foreach ($data as $cat) {
            $categories[] = [
                'id' => $cat['id'],
                'name' => $cat['name'],
                'description' => $cat['description'] ?? '',
                'parent' => $cat['parent'] ?? 0,
                'count' => $cat['count'] ?? 0,
            ];
        }

        return empty($categories) ? false : $categories;
    }

    /**
     * XML-RPC 获取分类
     */
    private function getCategoriesXmlRpc() {
        $xml = $this->buildXml('wp.getCategories', [1, $this->username, $this->password]);
        $response = $this->sendRequest($xml);

        if ($response === false) {
            $this->debug[] = 'XML-RPC请求失败';
            return false;
        }

        if (strpos($response, 'faultCode') !== false) {
            // 提取错误信息
            if (preg_match('/<string>(.*?)<\/string>/', $response, $m)) {
                $this->debug[] = 'XML-RPC错误: ' . $m[1];
            }
            return false;
        }

        return $this->parseCategories($response);
    }

    /**
     * 发布文章 - REST API优先，回退XML-RPC
     */
    public function publishPost($title, $content, $categoryId = 0, $status = 'publish') {
        $this->debug = [];

        // 方法1: REST API
        $result = $this->publishPostRest($title, $content, $categoryId, $status);
        if ($result['success']) {
            $this->debug[] = 'REST API发布成功';
            return $result;
        }

        // 方法2: XML-RPC
        $result = $this->publishPostXmlRpc($title, $content, $categoryId, $status);
        if ($result['success']) {
            $this->debug[] = 'XML-RPC发布成功';
            return $result;
        }

        $this->debug[] = '所有发布方式均失败';
        $debugInfo = implode(' | ', array_slice($this->debug, -5));

        // 给出针对性建议
        $hint = '';
        if (strpos($debugInfo, 'HTTP 401') !== false) {
            $hint = '。提示：WordPress需要使用「应用程序密码」而非登录密码。请到WordPress后台 → 用户 → 应用程序密码，生成一个新密码填入本系统';
        } elseif (strpos($debugInfo, 'HTTP 405') !== false) {
            $hint = '。提示：XML-RPC已被禁用，请使用REST API方式（需要WordPress 4.7+并启用REST API）';
        }

        return ['success' => false, 'message' => '发布失败: REST API和XML-RPC均不可用' . $hint . '。调试: ' . $debugInfo];
    }

    /**
     * REST API 发布文章
     */
    private function publishPostRest($title, $content, $categoryId, $status) {
        $postData = [
            'title' => $title,
            'content' => $content,
            'status' => $status,
        ];
        if ($categoryId > 0) {
            $postData['categories'] = [(int)$categoryId];
        }

        $result = $this->restPost('/posts', $postData);
        if ($result && isset($result['id'])) {
            return [
                'success' => true,
                'post_id' => $result['id'],
                'message' => '发布成功',
                'url' => $result['link'] ?? '',
            ];
        }

        return ['success' => false, 'message' => 'REST API发布失败'];
    }

    /**
     * XML-RPC 发布文章
     */
    private function publishPostXmlRpc($title, $content, $categoryId, $status) {
        $struct = [
            'title' => ['value' => 'string', $title],
            'description' => ['value' => 'string', $content],
            'post_status' => ['value' => 'string', $status],
            'mt_allow_comments' => ['value' => 'int', 1],
            'mt_allow_pings' => ['value' => 'int', 1],
        ];

        if ($categoryId) {
            $struct['categories'] = [
                'value' => 'array',
                [['value' => 'string', strval($categoryId)]]
            ];
        }

        $xml = $this->buildXml('metaWeblog.newPost', [1, $this->username, $this->password, $struct, true]);
        $response = $this->sendRequest($xml);

        if ($response === false) {
            return ['success' => false, 'message' => 'XML-RPC请求失败'];
        }

        if (strpos($response, 'faultCode') !== false) {
            $errMsg = '未知错误';
            if (preg_match('/<string>(.*?)<\/string>/', $response, $m)) {
                $errMsg = $m[1];
            }
            return ['success' => false, 'message' => 'XML-RPC错误: ' . $errMsg];
        }

        $postId = $this->parseResponse($response);
        if ($postId && is_numeric($postId)) {
            return ['success' => true, 'post_id' => $postId, 'message' => '发布成功'];
        }

        return ['success' => false, 'message' => '发布失败: ' . ($postId ?: '未知错误')];
    }

    /**
     * 上传媒体文件
     */
    public function uploadMedia($filename, $mimeType, $data) {
        $bits = base64_encode($data);
        $struct = [
            'name' => ['value' => 'string', $filename],
            'type' => ['value' => 'string', $mimeType],
            'bits' => ['value' => 'base64', $bits],
        ];

        $xml = $this->buildXml('wp.uploadFile', [1, $this->username, $this->password, $struct]);
        $response = $this->sendRequest($xml);

        if ($response === false) return false;
        return $this->parseStruct($response);
    }

    /**
     * 获取站点信息
     */
    public function getBlogInfo() {
        $xml = $this->buildXml('blogger.getUsersBlogs', ['', $this->username, $this->password]);
        $response = $this->sendRequest($xml);
        if ($response === false) return false;
        return $this->parseStruct($response);
    }

    // === REST API 辅助方法 ===

    private function restGet($endpoint) {
        $url = $this->restUrl . $endpoint;
        $this->debug[] = 'REST GET: ' . $url;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SEO-Publisher/1.0');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $this->debug[] = 'REST HTTP: ' . $httpCode;
        if ($error) $this->debug[] = 'REST cURL错误: ' . $error;

        if ($httpCode === 200) {
            return json_decode($response, true);
        }

        // 401 = 认证失败
        if ($httpCode === 401) {
            $this->debug[] = 'REST认证失败(HTTP 401)：WordPress REST API需要「应用程序密码」，请在WordPress后台→用户→应用程序密码中生成';
        }

        // 404 = REST API未启用
        if ($httpCode === 404) {
            $this->debug[] = 'REST API不可用(HTTP 404)，请确认WordPress安装了REST API插件或版本>=4.7';
        }

        return false;
    }

    /**
     * 不带认证的REST API请求（用于检测API是否可用）
     */
    private function restGetPublic($endpoint) {
        $url = $this->restUrl . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SEO-Publisher/1.0');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->debug[] = 'REST公开测试 HTTP: ' . $httpCode;

        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        return false;
    }

    private function restPost($endpoint, $data) {
        $url = $this->restUrl . $endpoint;
        $this->debug[] = 'REST POST: ' . $url;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_USERAGENT, 'SEO-Publisher/1.0');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $this->debug[] = 'REST POST HTTP: ' . $httpCode;
        if ($error) $this->debug[] = 'REST POST cURL错误: ' . $error;

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        return false;
    }

    // === XML-RPC 辅助方法 ===

    private function httpGet($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) return false;
        return $response;
    }

    private function buildXml($method, $params) {
        $xml = '<?xml version="1.0"?>';
        $xml .= '<methodCall>';
        $xml .= '<methodName>' . $method . '</methodName>';
        $xml .= '<params>';

        foreach ($params as $param) {
            $xml .= '<param><value>' . $this->xmlValue($param) . '</value></param>';
        }

        $xml .= '</params></methodCall>';
        return $xml;
    }

    private function xmlValue($value) {
        if (is_array($value) && isset($value['value'])) {
            $type = $value['value'];
            $val = $value[1] ?? $value['value'];
            return $this->xmlValueTyped($type, $val);
        }

        if (is_int($value)) return '<int>' . $value . '</int>';
        if (is_float($value)) return '<double>' . $value . '</double>';
        if (is_array($value)) {
            $xml = '<array><data>';
            foreach ($value as $v) {
                $xml .= '<value>' . $this->xmlValue($v) . '</value>';
            }
            $xml .= '</data></array>';
            return $xml;
        }
        return '<string>' . htmlspecialchars($value, ENT_XML1) . '</string>';
    }

    private function xmlValueTyped($type, $value) {
        switch ($type) {
            case 'string':
                return '<string>' . htmlspecialchars($value, ENT_XML1) . '</string>';
            case 'int':
                return '<int>' . intval($value) . '</int>';
            case 'double':
                return '<double>' . floatval($value) . '</double>';
            case 'base64':
                return '<base64>' . $value . '</base64>';
            case 'array':
                $xml = '<array><data>';
                foreach ($value as $v) {
                    $xml .= '<value>' . $this->xmlValue($v) . '</value>';
                }
                $xml .= '</data></array>';
                return $xml;
            default:
                return '<string>' . htmlspecialchars($value, ENT_XML1) . '</string>';
        }
    }

    private function sendRequest($xml) {
        $this->debug[] = 'XML-RPC: ' . $this->xmlrpcUrl;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->xmlrpcUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/xml']);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $this->debug[] = 'XML-RPC HTTP: ' . $httpCode;
        if ($error) $this->debug[] = 'XML-RPC cURL错误: ' . $error;

        if ($response === false || $httpCode >= 400) {
            return false;
        }
        return $response;
    }

    private function parseResponse($xml) {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) return false;

        $values = $doc->getElementsByTagName('value');
        if ($values->length === 0) return false;

        return $this->extractValue($values->item(0));
    }

    private function parseCategories($xml) {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) return [];

        $categories = [];
        $structs = $doc->getElementsByTagName('struct');

        foreach ($structs as $struct) {
            $cat = [];
            $members = $struct->getElementsByTagName('member');
            foreach ($members as $member) {
                $name = $member->getElementsByTagName('name')->item(0)->nodeValue;
                $value = $this->extractValue($member->getElementsByTagName('value')->item(0));
                $cat[$name] = $value;
            }
            if (isset($cat['categoryId'])) {
                $categories[] = [
                    'id' => $cat['categoryId'],
                    'name' => $cat['categoryName'] ?? $cat['name'] ?? '',
                    'description' => $cat['categoryDescription'] ?? '',
                ];
            }
        }

        return $categories;
    }

    private function parseStruct($xml) {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) return false;

        $struct = $doc->getElementsByTagName('struct')->item(0);
        if (!$struct) return false;

        $result = [];
        $members = $struct->getElementsByTagName('member');
        foreach ($members as $member) {
            $name = $member->getElementsByTagName('name')->item(0)->nodeValue;
            $value = $this->extractValue($member->getElementsByTagName('value')->item(0));
            $result[$name] = $value;
        }
        return $result;
    }

    private function extractValue($node) {
        if (!$node) return '';

        foreach ($node->childNodes as $child) {
            switch ($child->nodeName) {
                case 'string': return $child->nodeValue;
                case 'int':
                case 'i4': return (int)$child->nodeValue;
                case 'double': return (float)$child->nodeValue;
                case 'boolean': return (bool)$child->nodeValue;
                case 'array':
                    $data = $child->getElementsByTagName('data')->item(0);
                    $arr = [];
                    if ($data) {
                        foreach ($data->getElementsByTagName('value') as $v) {
                            $arr[] = $this->extractValue($v);
                        }
                    }
                    return $arr;
                case 'struct':
                    $result = [];
                    foreach ($child->getElementsByTagName('member') as $member) {
                        $name = $member->getElementsByTagName('name')->item(0)->nodeValue;
                        $value = $this->extractValue($member->getElementsByTagName('value')->item(0));
                        $result[$name] = $value;
                    }
                    return $result;
            }
        }
        return $node->nodeValue;
    }
}
