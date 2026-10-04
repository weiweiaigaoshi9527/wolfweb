<?php
/**
 * 狼人杀 Online · 虚拟主机版 —— 引导文件
 * 兼容 PHP 7.4+，PDO (MySQL / SQLite)，无 exec 依赖，flock 并发锁。
 */
define('WW_VERSION', '2.0.0');
define('WW_ROOT', __DIR__);
define('WW_STORAGE', WW_ROOT . '/storage');

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Shanghai');

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

/* ---------- 已安装检测 ---------- */
function ww_installed(): bool {
    return is_file(WW_STORAGE . '/config.php') && is_file(WW_STORAGE . '/installed.lock');
}

function ww_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = is_file(WW_STORAGE . '/config.php') ? require WW_STORAGE . '/config.php' : [];
    }
    return $cfg;
}

/* ---------- 数据库 ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $cfg = ww_config();
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (($cfg['driver'] ?? 'sqlite') === 'mysql') {
        $dsn = "mysql:host={$cfg['host']};port=" . ($cfg['port'] ?? 3306) . ";dbname={$cfg['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $opts);
    } else {
        $dir = WW_STORAGE . '/data';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $pdo = new PDO('sqlite:' . $dir . '/wolf.db', null, null, $opts);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
    }
    return $pdo;
}

function q(string $sql, array $args = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st;
}
function q1(string $sql, array $args = []) { $r = q($sql, $args)->fetch(); return $r === false ? null : $r; }
function qv(string $sql, array $args = [], $def = null) {
    $r = q($sql, $args)->fetchColumn();
    return $r === false || $r === null ? $def : $r;
}

/* ---------- 会话 ---------- */
function ww_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('WWSESS');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}
function current_user(): ?array {
    ww_session_start();
    $uid = $_SESSION['ww_uid'] ?? 0;
    if (!$uid) return null;
    $u = q1('SELECT * FROM users WHERE id=? AND banned=0', [$uid]);
    return $u ?: null;
}
function require_login(): array {
    $u = current_user();
    if (!$u) ww_json(['ok' => 0, 'err' => '请先登录'], 401);
    return $u;
}
function require_admin(): array {
    $u = require_login();
    if ((int)$u['is_admin'] !== 1) ww_json(['ok' => 0, 'err' => '需要管理员权限'], 403);
    return $u;
}

/* ---------- CSRF ---------- */
function ww_csrf_token(): string {
    ww_session_start();
    if (empty($_SESSION['ww_csrf'])) $_SESSION['ww_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['ww_csrf'];
}
function ww_csrf_check(): void {
    ww_session_start();
    $t = $_SERVER['HTTP_X_WW_TOKEN'] ?? ($_POST['_token'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $t && hash_equals($_SESSION['ww_csrf'] ?? '', $t)) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // 安装向导与登录接口允许无会话令牌的首次握手；其余 POST 必须携带令牌
        $a = $_GET['a'] ?? ($_GET['step'] ?? '');
        static $open = ['login', 'register', 'me', 'cfg'];
        if (!in_array($a, $open, true)) ww_json(['ok' => 0, 'err' => '会话过期，请刷新页面'], 419);
    }
}

/* ---------- 输出 ---------- */
function ww_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function ww_fail(string $msg, int $code = 400): void { ww_json(['ok' => 0, 'err' => $msg], $code); }

function ww_body(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw ?: '[]', true);
        return is_array($j) ? $j : [];
    }
    return $_POST ?: [];
}

/* ---------- 设置 / 功能开关 ---------- */
function setting(string $k, $def = null) {
    $v = qv('SELECT v FROM settings WHERE k=?', [$k]);
    return $v === null ? $def : json_decode($v, true);
}
function set_setting(string $k, $val): void {
    $j = json_encode($val, JSON_UNESCAPED_UNICODE);
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        q('INSERT INTO settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', [$k, $j]);
    } else {
        q('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v', [$k, $j]);
    }
}
function feature_enabled(string $k): bool {
    static $map = null;
    if ($map === null) { $map = setting('features', []); if (!is_array($map)) $map = []; }
    return !empty($map[$k]);
}

/* ---------- 文件锁 ---------- */
function ww_lock(string $name) {
    $dir = WW_STORAGE . '/locks';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $fp = fopen($dir . '/' . preg_replace('/[^\w-]/', '', $name) . '.lock', 'c');
    flock($fp, LOCK_EX);
    return $fp;
}
function ww_unlock($fp): void { if ($fp) { flock($fp, LOCK_UN); fclose($fp); } }

/* ---------- 工具 ---------- */
function ww_rnd(int $min, int $max): int { return random_int($min, $max); }
function ww_pick(array $arr) { return $arr[array_rand($arr)]; }
function ww_now_ms(): int { return (int)round(microtime(true) * 1000); }
