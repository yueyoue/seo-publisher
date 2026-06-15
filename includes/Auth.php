<?php
/**
 * 用户认证类
 */
class Auth {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * 用户登录
     */
    public function login($username, $password) {
        $user = $this->db->fetchOne(
            "SELECT * FROM users WHERE username = ? OR email = ?",
            [$username, $username]
        );

        if (!$user || !password_verify($password, $user['password'])) {
            return ['success' => false, 'message' => '用户名或密码错误'];
        }

        if ($user['status'] != 1) {
            return ['success' => false, 'message' => '账户已被禁用'];
        }

        // 更新最后登录时间
        $this->db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id=?', [$user['id']]);

        // 设置session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        return ['success' => true, 'message' => '登录成功', 'user' => $user];
    }

    /**
     * 用户注册
     */
    public function register($username, $email, $password, $role = 'user') {
        // 检查用户名是否已存在
        if ($this->db->count('users', 'username=?', [$username])) {
            return ['success' => false, 'message' => '用户名已存在'];
        }

        // 检查邮箱是否已存在
        if ($this->db->count('users', 'email=?', [$email])) {
            return ['success' => false, 'message' => '邮箱已被注册'];
        }

        $userId = $this->db->insert('users', [
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['success' => true, 'message' => '注册成功', 'user_id' => $userId];
    }

    /**
     * 退出登录
     */
    public function logout() {
        session_destroy();
        header('Location: /modules/auth/login.php');
        exit;
    }

    /**
     * 检查是否已登录
     */
    public static function check() {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /modules/auth/login.php');
            exit;
        }
    }

    /**
     * 检查是否为管理员
     */
    public static function isAdmin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }

    /**
     * 获取当前用户
     */
    public static function user() {
        if (!isset($_SESSION['user_id'])) return null;
        return Database::getInstance()->fetchOne("SELECT * FROM users WHERE id=?", [$_SESSION['user_id']]);
    }

    /**
     * 修改密码
     */
    public function changePassword($userId, $oldPass, $newPass) {
        $user = $this->db->fetchOne("SELECT password FROM users WHERE id=?", [$userId]);
        if (!$user || !password_verify($oldPass, $user['password'])) {
            return ['success' => false, 'message' => '原密码错误'];
        }

        $this->db->update('users', [
            'password' => password_hash($newPass, PASSWORD_DEFAULT)
        ], 'id=?', [$userId]);

        return ['success' => true, 'message' => '密码修改成功'];
    }

    /**
     * 获取用户套餐信息
     */
    public function getUserPackage($userId) {
        return $this->db->fetchOne(
            "SELECT up.*, p.name as package_name, p.article_limit, p.keyword_limit 
             FROM user_packages up 
             JOIN packages p ON up.package_id = p.id 
             WHERE up.user_id = ? AND up.status = 'active' AND up.expire_time > NOW() 
             ORDER BY up.expire_time DESC LIMIT 1",
            [$userId]
        );
    }

    /**
     * 检查用户配额
     */
    public function checkQuota($userId, $type = 'article') {
        // 管理员不限制
        $user = $this->db->fetchOne("SELECT role FROM users WHERE id=?", [$userId]);
        if ($user && $user['role'] === 'admin') return true;

        $package = $this->getUserPackage($userId);
        if (!$package) return false;

        $field = $type === 'article' ? 'article_limit' : 'keyword_limit';
        $usedField = $type === 'article' ? 'articles_used' : 'keywords_used';

        return $package[$usedField] < $package[$field];
    }
}
