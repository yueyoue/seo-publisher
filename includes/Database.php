<?php
/**
 * 数据库操作类
 */
class Database {
    private static $instance = null;
    private $pdo;
    private $dsn;
    private $user;
    private $pass;
    private $options;

    private function __construct() {
        $this->dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $this->user = DB_USER;
        $this->pass = DB_PASS;
        $this->options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $this->connect();
    }

    private function connect() {
        try {
            $this->pdo = new PDO($this->dsn, $this->user, $this->pass, $this->options);
        } catch (PDOException $e) {
            die('数据库连接失败: ' . $e->getMessage());
        }
    }

    private function reconnect() {
        $this->connect();
    }

    private function isGoneAway($e) {
        $msg = $e->getMessage();
        return strpos($msg, 'server has gone away') !== false
            || strpos($msg, 'Lost connection') !== false
            || strpos($msg, 'Connection was killed') !== false;
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    private $lastStmt = null;

    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $this->lastStmt = $stmt;
            return $stmt;
        } catch (PDOException $e) {
            if ($this->isGoneAway($e)) {
                // 连接断开，自动重连并重试
                $this->reconnect();
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $this->lastStmt = $stmt;
                return $stmt;
            }
            throw $e;
        }
    }

    public function affected() {
        return $this->lastStmt ? $this->lastStmt->rowCount() : 0;
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchColumn($sql, $params = []) {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function insert($table, $data) {
        $fields = implode(',', array_keys($data));
        $placeholders = implode(',', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$fields}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = []) {
        $set = implode(',', array_map(fn($k) => "{$k}=?", array_keys($data)));
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        $params = array_merge(array_values($data), $whereParams);
        return $this->query($sql, $params)->rowCount();
    }

    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        return $this->query($sql, $params)->rowCount();
    }

    public function count($table, $where = '1=1', $params = []) {
        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        return (int)$this->fetchColumn($sql, $params);
    }
}
