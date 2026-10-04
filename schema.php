<?php
/**
 * 建表与补丁迁移（MySQL / SQLite 双方言），供安装向导与更新系统共用。
 */
function ww_tables(): array {
    // name => ['sqlite' => SQL, 'mysql' => SQL]
    return [
        'users' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, pass TEXT NOT NULL,
                avatar TEXT DEFAULT '🐺', email TEXT DEFAULT '', is_admin INTEGER DEFAULT 0,
                banned INTEGER DEFAULT 0, vip_until INTEGER DEFAULT 0, coins INTEGER DEFAULT 0,
                games INTEGER DEFAULT 0, wins INTEGER DEFAULT 0, created INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(32) UNIQUE NOT NULL, pass VARCHAR(255) NOT NULL,
                avatar VARCHAR(16) DEFAULT '🐺', email VARCHAR(128) DEFAULT '', is_admin TINYINT DEFAULT 0,
                banned TINYINT DEFAULT 0, vip_until BIGINT DEFAULT 0, coins INT DEFAULT 0,
                games INT DEFAULT 0, wins INT DEFAULT 0, created BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'rooms' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS rooms (
                id INTEGER PRIMARY KEY AUTOINCREMENT, no TEXT UNIQUE, name TEXT, owner_id INTEGER,
                max_players INTEGER DEFAULT 9, board TEXT DEFAULT '{}', status TEXT DEFAULT 'waiting',
                game_id INTEGER DEFAULT 0, created INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS rooms (
                id INT AUTO_INCREMENT PRIMARY KEY, no VARCHAR(12) UNIQUE, name VARCHAR(64), owner_id INT,
                max_players INT DEFAULT 9, board TEXT, status VARCHAR(16) DEFAULT 'waiting',
                game_id INT DEFAULT 0, created BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'room_players' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS room_players (
                id INTEGER PRIMARY KEY AUTOINCREMENT, room_id INTEGER, user_id INTEGER, seat INTEGER, ready INTEGER DEFAULT 0,
                spectate INTEGER DEFAULT 0, last_seen INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS room_players (
                id INT AUTO_INCREMENT PRIMARY KEY, room_id INT, user_id INT, seat INT, ready INT DEFAULT 0,
                spectate INT DEFAULT 0, last_seen BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'games' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS games (
                id INTEGER PRIMARY KEY AUTOINCREMENT, room_id INTEGER, status TEXT DEFAULT 'playing',
                day INTEGER DEFAULT 0, phase TEXT DEFAULT '', action_kind TEXT DEFAULT '', current INTEGER DEFAULT 0,
                state TEXT DEFAULT '{}', next_ai_at INTEGER DEFAULT 0, deadline_at INTEGER DEFAULT 0, created INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS games (
                id INT AUTO_INCREMENT PRIMARY KEY, room_id INT, status VARCHAR(16) DEFAULT 'playing',
                day INT DEFAULT 0, phase VARCHAR(24) DEFAULT '', action_kind VARCHAR(24) DEFAULT '', current INT DEFAULT 0,
                state MEDIUMTEXT, next_ai_at BIGINT DEFAULT 0, deadline_at BIGINT DEFAULT 0, created BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'events' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS events (
                id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, seq INTEGER, phase TEXT, day INTEGER,
                type TEXT, actor INTEGER, target INTEGER, detail TEXT DEFAULT '', public INTEGER DEFAULT 1, to_seat INTEGER DEFAULT 0, created INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS events (
                id BIGINT AUTO_INCREMENT PRIMARY KEY, game_id INT, seq INT, phase VARCHAR(24), day INT,
                type VARCHAR(32), actor INT, target INT, detail TEXT, public TINYINT DEFAULT 1, to_seat INT DEFAULT 0, created BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'friends' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS friends (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, friend_id INTEGER, created INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS friends (
                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, friend_id INT, created BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'friend_msgs' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS friend_msgs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, from_u INTEGER, to_u INTEGER, text TEXT, is_read INTEGER DEFAULT 0, created INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS friend_msgs (
                id INT AUTO_INCREMENT PRIMARY KEY, from_u INT, to_u INT, text VARCHAR(1000), is_read TINYINT DEFAULT 0, created BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'forum_posts' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS forum_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, text TEXT, created INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS forum_posts (
                id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, title VARCHAR(128), text TEXT, created BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'changelog' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS changelog (
                id INTEGER PRIMARY KEY AUTOINCREMENT, version TEXT, date TEXT, text TEXT)",
            'mysql' => "CREATE TABLE IF NOT EXISTS changelog (
                id INT AUTO_INCREMENT PRIMARY KEY, version VARCHAR(24), date VARCHAR(16), text TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'settings' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)",
            'mysql' => "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'migrations' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS migrations (v TEXT PRIMARY KEY, applied INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS migrations (v VARCHAR(32) PRIMARY KEY, applied BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'backups' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS backups (id INTEGER PRIMARY KEY AUTOINCREMENT, file TEXT, size INTEGER, created INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS backups (id INT AUTO_INCREMENT PRIMARY KEY, file VARCHAR(128), size INT, created BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'duo_invites' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS duo_invites (
                id INTEGER PRIMARY KEY AUTOINCREMENT, from_u INTEGER, to_u INTEGER, room_no TEXT, status TEXT DEFAULT 'pending', created INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS duo_invites (
                id INT AUTO_INCREMENT PRIMARY KEY, from_u INT, to_u INT, room_no VARCHAR(12), status VARCHAR(16) DEFAULT 'pending', created BIGINT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
        'vote_log' => [
            'sqlite' => "CREATE TABLE IF NOT EXISTS vote_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, game_id INTEGER, day INTEGER, seat INTEGER, target INTEGER)",
            'mysql' => "CREATE TABLE IF NOT EXISTS vote_log (
                id INT AUTO_INCREMENT PRIMARY KEY, game_id INT, day INT, seat INT, target INT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ],
    ];
}

/** 已发布的补丁（更新系统逐个应用并登记 migrations 表）。 */
function ww_patches(): array {
    return [
        '2.0.1' => ['text' => '补丁 2.0.1：新增投票明细表 vote_log，支持结算回放', 'sql' => function () {
            $t = ww_tables()['vote_log'];
            db()->exec(db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? $t['mysql'] : $t['sqlite']);
        }],
        '2.0.2' => ['text' => '补丁 2.0.2：设置表新增 badges 默认项', 'sql' => function () {
            if (qv('SELECT COUNT(*) FROM settings WHERE k=?', ['badges']) == 0) set_setting('badges', ['月夜守护者' => '连胜 3 场']);
        }],
    ];
}

/** 建全部基础表。 */
function ww_create_tables(): void {
    $mysql = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    foreach (ww_tables() as $t) db()->exec($mysql ? $t['mysql'] : $t['sqlite']);
}

/** 应用全部未打的补丁，返回 ['applied' => [...]]。 */
function ww_apply_patches(): array {
    ww_create_tables();
    $applied = [];
    foreach (ww_patches() as $v => $p) {
        if (qv('SELECT COUNT(*) FROM migrations WHERE v=?', [$v]) > 0) continue;
        ($p['sql'])();
        q('INSERT INTO migrations(v,applied) VALUES(?,?)', [$v, time()]);
        $applied[] = $v . ' ' . $p['text'];
    }
    return $applied;
}

/** 种子数据：设置 / 功能开关 / 更新日志 / 管理员标记。 */
function ww_seed(array $site): void {
    if (qv('SELECT COUNT(*) FROM settings WHERE k=?', ['site']) == 0) {
        set_setting('site', [
            'name' => $site['name'] ?? '月夜村庄',
            'theme' => $site['theme'] ?? 'moonlit',
            'max_page_height' => (int)($site['max_page_height'] ?? 100),
            'welcome' => '月夜降临，村庄的谎言即将开始。',
        ]);
    }
    if (qv('SELECT COUNT(*) FROM settings WHERE k=?', ['features']) == 0) {
        set_setting('features', [
            'duo' => true, 'spectate' => true, 'shop' => true, 'forum' => true,
            'rank' => true, 'guide' => true, 'register' => true, 'voiceMode' => false, 'downloads' => true,
        ]);
    }
    if (qv('SELECT COUNT(*) FROM settings WHERE k=?', ['boards']) == 0) {
        set_setting('boards', [
            ['name' => '标准 9 人', 'players' => 9, 'roles' => ['werewolf' => 3, 'seer' => 1, 'witch' => 1, 'hunter' => 1, 'villager' => 3], 'sheriff' => false],
            ['name' => '进阶 10 人', 'players' => 10, 'roles' => ['werewolf' => 3, 'whiteWolf' => 1, 'seer' => 1, 'witch' => 1, 'guard' => 1, 'hunter' => 1, 'villager' => 2], 'sheriff' => false],
            ['name' => '暗夜 12 人', 'players' => 12, 'roles' => ['werewolf' => 4, 'whiteWolf' => 0, 'seer' => 1, 'witch' => 1, 'guard' => 1, 'hunter' => 1, 'crow' => 1, 'silencer' => 1, 'villager' => 2], 'sheriff' => true],
        ]);
    }
    if (qv('SELECT COUNT(*) FROM changelog') == 0) {
        $rows = [
            [WW_VERSION, '2026-10-03', '虚拟主机版发布：五步安装向导（环境体检/依赖补丁/数据库/账户管理员/网站设定）、更新系统、备份系统、插件系统。'],
            [WW_VERSION, '2026-10-03', '对局引擎与主项目一致：12 神职自由配板、警长竞选、PK 谈判、猎人开枪、白狼王自爆、出局翻牌。'],
            [WW_VERSION, '2026-10-03', 'AI 托管三层反雷同：n-gram 相似度去重 + 8 套语癖人设 + 拟人思考停顿。'],
        ];
        foreach ($rows as $r) q('INSERT INTO changelog(version,date,text) VALUES(?,?,?)', $r);
    }
}
