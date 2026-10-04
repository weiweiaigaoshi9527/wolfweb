<?php
/**
 * 全部 JSON 接口。约定：读接口 GET，写接口 POST；令牌放 X-WW-Token。
 * 调用：api.php?a=<action>
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/tick.php';
require_once __DIR__ . '/plugins.php';

if (!ww_installed()) ww_fail('站点尚未安装，请先打开 install.php 完成安装向导', 503);

$action = $_GET['a'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
ww_csrf_check();
$body = ww_body();

define('WW_AI_NAMES', ['月月', '阿灰', '夜夜', '小村', '板板', '团子', '豆豆', '眠眠', '蜡蜡', '多多', '月亮', '雾雾']);

try {
    switch ($action) {

        /* ================= 公共 ================= */
        case 'cfg': {
            ww_session_start();
            $u = current_user();
            ww_json(['ok' => 1, 'site' => setting('site', []), 'features' => setting('features', []),
                'token' => ww_csrf_token(), 'version' => WW_VERSION,
                'me' => $u ? ['id' => (int)$u['id'], 'name' => $u['name'], 'avatar' => $u['avatar'], 'isAdmin' => (int)$u['is_admin'] === 1, 'vipUntil' => (int)$u['vip_until']] : null]);
        }

        /* ================= 账户 ================= */
        case 'register': {
            if (!feature_enabled('register')) ww_fail('本站已关闭注册');
            $name = trim((string)($body['name'] ?? ''));
            $pass = (string)($body['pass'] ?? '');
            if (!preg_match('/^[\w\x{4e00}-\x{9fa5}]{2,16}$/u', $name)) ww_fail('昵称需 2-16 位（中文/字母/数字）');
            if (mb_strlen($pass) < 6) ww_fail('密码至少 6 位');
            if (qv('SELECT COUNT(*) FROM users WHERE name=?', [$name]) > 0) ww_fail('昵称已被占用');
            $first = qv('SELECT COUNT(*) FROM users') == 0;
            q('INSERT INTO users(name,pass,is_admin,created) VALUES(?,?,?,?)', [$name, password_hash($pass, PASSWORD_DEFAULT), $first ? 1 : 0, time()]);
            ww_session_start();
            $_SESSION['ww_uid'] = (int)db()->lastInsertId();
            ww_json(['ok' => 1]);
        }
        case 'login': {
            $name = trim((string)($body['name'] ?? ''));
            $pass = (string)($body['pass'] ?? '');
            $u = q1('SELECT * FROM users WHERE name=?', [$name]);
            if (!$u || !password_verify($pass, $u['pass'])) ww_fail('昵称或密码不对');
            if ((int)$u['banned'] === 1) ww_fail('账号已被封禁，请联系管理员');
            ww_session_start();
            $_SESSION['ww_uid'] = (int)$u['id'];
            ww_json(['ok' => 1]);
        }
        case 'logout':
            ww_session_start();
            unset($_SESSION['ww_uid']);
            ww_json(['ok' => 1]);

        case 'me': {
            $u = require_login();
            $vip = (int)$u['vip_until'] > time();
            ww_json(['ok' => 1, 'me' => ['id' => (int)$u['id'], 'name' => $u['name'], 'avatar' => $u['avatar'], 'isAdmin' => (int)$u['is_admin'] === 1,
                'vip' => $vip, 'vipUntil' => (int)$u['vip_until'], 'games' => (int)$u['games'], 'wins' => (int)$u['wins']]]);
        }

        case 'profile_save': {
            $u = require_login();
            $avatar = trim((string)($body['avatar'] ?? ''));
            if ($avatar === '' || mb_strlen($avatar) > 4) ww_fail('头像 emoji 不合法');
            q('UPDATE users SET avatar=? WHERE id=?', [$avatar, (int)$u['id']]);
            ww_json(['ok' => 1]);
        }

        /* ================= 大厅 / 房间 ================= */
        case 'rooms': {
            require_login();
            ww_tick();
            $rows = q('SELECT r.*, (SELECT COUNT(*) FROM room_players rp WHERE rp.room_id=r.id AND rp.spectate=0) AS cnt FROM rooms r WHERE r.status IN (?,?) ORDER BY r.id DESC LIMIT 50', ['waiting', 'playing'])->fetchAll();
            $list = [];
            foreach ($rows as $r) {
                $list[] = ['id' => (int)$r['id'], 'no' => $r['no'], 'name' => $r['name'], 'status' => $r['status'], 'cnt' => (int)$r['cnt'], 'max' => (int)$r['max_players']];
            }
            ww_json(['ok' => 1, 'rooms' => $list]);
        }
        case 'create': {
            $u = require_login();
            $name = trim((string)($body['name'] ?? '')) ?: $u['name'] . ' 的房间';
            $boardIdx = (int)($body['board'] ?? 0);
            $boards = setting('boards', []);
            if (!isset($boards[$boardIdx])) ww_fail('板子配置不存在');
            $no = (string)ww_rnd(100000, 999999);
            while (qv('SELECT COUNT(*) FROM rooms WHERE no=?', [$no]) > 0) $no = (string)ww_rnd(100000, 999999);
            q('INSERT INTO rooms(no,name,owner_id,max_players,board,status,created) VALUES(?,?,?,?,?,?,?)',
                [$no, $name, (int)$u['id'], (int)$boards[$boardIdx]['players'], json_encode(['idx' => $boardIdx], JSON_UNESCAPED_UNICODE), 'waiting', time()]);
            $rid = (int)db()->lastInsertId();
            ww_join_room($rid, (int)$u['id']);
            ww_json(['ok' => 1, 'no' => $no]);
        }
        case 'join': {
            $u = require_login();
            $no = trim((string)($body['no'] ?? ''));
            $r = q1('SELECT * FROM rooms WHERE no=?', [$no]);
            if (!$r) ww_fail('房间号不存在');
            if ($r['status'] === 'ended') ww_fail('该局已结束');
            ww_join_room((int)$r['id'], (int)$u['id']);
            ww_json(['ok' => 1, 'no' => $r['no']]);
        }
        case 'quick': {
            $u = require_login();
            $r = q1("SELECT * FROM rooms WHERE status='waiting' AND (SELECT COUNT(*) FROM room_players rp WHERE rp.room_id=rooms.id AND rp.spectate=0) < max_players ORDER BY id LIMIT 1");
            if (!$r) {
                $no = (string)ww_rnd(100000, 999999);
                q('INSERT INTO rooms(no,name,owner_id,max_players,board,status,created) VALUES(?,?,?,?,?,?,?)',
                    [$no, '快速开局', (int)$u['id'], 9, json_encode(['idx' => 0], JSON_UNESCAPED_UNICODE), 'waiting', time()]);
                $r = q1('SELECT * FROM rooms WHERE no=?', [$no]);
            }
            ww_join_room((int)$r['id'], (int)$u['id']);
            ww_json(['ok' => 1, 'no' => $r['no']]);
        }
        case 'leave': {
            $u = require_login();
            $r = ww_my_room((int)$u['id']);
            if ($r) ww_leave_room((int)$r['id'], (int)$u['id']);
            ww_json(['ok' => 1]);
        }
        case 'room_state': {
            $u = require_login();
            $no = trim((string)($_GET['no'] ?? ''));
            $r = q1('SELECT * FROM rooms WHERE no=?', [$no]);
            if (!$r) ww_fail('房间不存在', 404);
            $mps = q('SELECT rp.*, us.name AS uname, us.avatar AS avatar FROM room_players rp LEFT JOIN users us ON us.id=rp.user_id WHERE rp.room_id=? ORDER BY rp.seat', [(int)$r['id']])->fetchAll();
            $players = [];
            $meSeat = 0;
            $amSpectator = false;
            foreach ($mps as $m) {
                $pname = $m['uname'] !== null ? $m['uname'] : 'AI·' . $m['seat'];
                $players[] = ['seat' => (int)$m['seat'], 'name' => $pname, 'avatar' => $m['avatar'] ?: '🤖', 'ready' => (int)$m['ready'], 'spectate' => (int)$m['spectate'], 'isOwner' => (int)$m['user_id'] === (int)$r['owner_id'], 'ai' => $m['uname'] === null];
                if ((int)$m['user_id'] === (int)$u['id']) {
                    $meSeat = (int)$m['seat'];
                    $amSpectator = (int)$m['spectate'] === 1;
                }
            }
            $board = json_decode($r['board'], true) ?: [];
            $boards = setting('boards', []);
            $b = isset($board['idx']) ? $boards[$board['idx']] : reset($boards);
            ww_json(['ok' => 1, 'room' => ['id' => (int)$r['id'], 'no' => $r['no'], 'name' => $r['name'], 'status' => $r['status'],
                'gameId' => (int)$r['game_id'], 'max' => (int)$r['max_players'], 'players' => $players,
                'board' => $b ?: [], 'isOwner' => (int)$r['owner_id'] === (int)$u['id']],
                'you' => ['seat' => $meSeat, 'spectate' => $amSpectator]]);
        }
        case 'ready': {
            $u = require_login();
            $r = ww_my_room((int)$u['id']);
            if (!$r) ww_fail('你不在任何房间里');
            q('UPDATE room_players SET ready=1-ready WHERE room_id=? AND user_id=?', [(int)$r['id'], (int)$u['id']]);
            ww_json(['ok' => 1]);
        }
        case 'addai': {
            $u = require_login();
            $r = ww_my_room((int)$u['id']);
            if (!$r || (int)$r['owner_id'] !== (int)$u['id']) ww_fail('只有房主可以添加 AI');
            $cnt = (int)qv('SELECT COUNT(*) FROM room_players WHERE room_id=? AND spectate=0', [(int)$r['id']]);
            if ($cnt >= (int)$r['max_players']) ww_fail('座位已满');
            $used = array_column(q('SELECT seat FROM room_players WHERE room_id=?', [(int)$r['id']])->fetchAll(), 'seat');
            $seat = 1;
            for ($i = 1; $i <= (int)$r['max_players']; $i++) if (!in_array($i, array_map('intval', $used), true)) { $seat = $i; break; }
            q('INSERT INTO room_players(room_id,user_id,seat,ready,last_seen) VALUES(?,?,?,?,?)', [(int)$r['id'], 0, $seat, 1, time()]) ;
            ww_json(['ok' => 1]);
        }
        case 'kick': {
            $u = require_login();
            $r = ww_my_room((int)$u['id']);
            if (!$r || (int)$r['owner_id'] !== (int)$u['id']) ww_fail('只有房主可以移出玩家');
            $seat = (int)($body['seat'] ?? 0);
            $m = q1('SELECT * FROM room_players WHERE room_id=? AND seat=?', [(int)$r['id'], $seat]);
            if (!$m || (int)$m['user_id'] === 0) ww_fail('该座位没有真人玩家');
            q('DELETE FROM room_players WHERE room_id=? AND user_id=?', [(int)$r['id'], (int)$m['user_id']]);
            ww_json(['ok' => 1]);
        }
        case 'spectate': {
            $u = require_login();
            $r = ww_my_room((int)$u['id']);
            if (!$r) ww_fail('先进入房间');
            if (!feature_enabled('spectate')) ww_fail('观战功能已关闭');
            q('UPDATE room_players SET spectate=1, ready=0 WHERE room_id=? AND user_id=?', [(int)$r['id'], (int)$u['id']]);
            ww_json(['ok' => 1]);
        }
        case 'play_seat': {
            $u = require_login();
            $r = ww_my_room((int)$u['id']);
            if (!$r) ww_fail('先进入房间');
            $cnt = (int)qv('SELECT COUNT(*) FROM room_players WHERE room_id=? AND spectate=0', [(int)$r['id']]);
            if ($cnt >= (int)$r['max_players']) ww_fail('没有空位了，只能继续观战');
            $used = array_map('intval', array_column(q('SELECT seat FROM room_players WHERE room_id=?', [(int)$r['id']])->fetchAll(), 'seat'));
            $seat = 1;
            for ($i = 1; $i <= (int)$r['max_players']; $i++) if (!in_array($i, $used, true)) { $seat = $i; break; }
            q('UPDATE room_players SET spectate=0, seat=? WHERE room_id=? AND user_id=?', [$seat, (int)$r['id'], (int)$u['id']]);
            ww_json(['ok' => 1]);
        }
        case 'start': {
            $u = require_login();
            $r = ww_my_room((int)$u['id']);
            if (!$r || (int)$r['owner_id'] !== (int)$u['id']) ww_fail('只有房主可以开局');
            if ($r['status'] === 'playing') ww_fail('本局已在进行中');
            $boards = setting('boards', []);
            $board = json_decode($r['board'], true) ?: [];
            $b = isset($board['idx']) ? $boards[$board['idx']] : reset($boards);
            $need = (int)$b['players'];
            $mps = q('SELECT rp.*, us.name AS uname FROM room_players rp JOIN users us ON us.id=rp.user_id WHERE rp.room_id=? AND rp.spectate=0 ORDER BY rp.seat', [(int)$r['id']])->fetchAll();
            $real = [];
            foreach ($mps as $m) if ((int)$m['user_id'] !== 0) $real[] = $m;
            if (count($real) < 1) ww_fail('至少需要一名真人玩家');
            // 补 AI 座位
            $players = [];
            $aiNames = WW_AI_NAMES;
            shuffle($aiNames);
            foreach ($real as $i => $m) $players[] = ['name' => $m['uname'], 'userId' => (int)$m['user_id'], 'ai' => false];
            while (count($players) < $need) $players[] = ['name' => array_pop($aiNames) ?: ('AI' . count($players)), 'userId' => 0, 'ai' => true];
            shuffle($players); // 座位随机化
            $g = Game::create($b, $players);
            q('INSERT INTO games(room_id,status,day,phase,action_kind,current,state,created) VALUES(?,?,?,?,?,?,?,?)',
                [(int)$r['id'], 'playing', $g->st['day'], $g->st['phase'], $g->st['kind'], $g->st['cur'], json_encode($g->st, JSON_UNESCAPED_UNICODE), time()]);
            $gid = (int)db()->lastInsertId();
            $g->id = $gid;
            ww_save_game($g);           // 落事件
            ww_schedule_step($g);       // 起表
            q('UPDATE rooms SET status=?, game_id=? WHERE id=?', ['playing', $gid, (int)$r['id']]);
            ww_json(['ok' => 1]);
        }

        /* ================= 对局 ================= */
        case 'gstate': {
            $u = require_login();
            $no = trim((string)($_GET['no'] ?? ''));
            $r = q1('SELECT * FROM rooms WHERE no=?', [$no]);
            if (!$r) ww_fail('房间不存在', 404);
            $gid = (int)$r['game_id'];
            if (!$gid) ww_json(['ok' => 1, 'game' => null]);
            ww_tick($gid); // 懒调度：轮询即推进
            $row = q1('SELECT * FROM games WHERE id=?', [$gid]);
            if (!$row) ww_json(['ok' => 1, 'game' => null]);
            $g = ww_load_game($gid);
            $mp = q1('SELECT * FROM room_players WHERE room_id=? AND user_id=?', [(int)$r['id'], (int)$u['id']]);
            $mySeat = 0;
            $spectate = true;
            if ($mp && (int)$mp['spectate'] === 0) {
                // 在游戏座位表里找自己名字对应的座位（座位随机化后按 userId 查）
                foreach ($g->st['players'] as $p) {
                    if (!$p['ai'] && $p['name'] === $u['name']) { $mySeat = (int)$p['seat']; $spectate = false; break; }
                }
            }
            $view = $g->viewFor($mySeat, ww_game_events($gid));
            $view['deadlineAt'] = (int)$row['deadline_at'];
            $view['nextAiAt'] = (int)$row['next_ai_at'];
            $view['nowMs'] = ww_now_ms();
            ww_json(['ok' => 1, 'game' => $view, 'you' => ['seat' => $mySeat, 'spectate' => $spectate], 'room' => ['no' => $r['no'], 'status' => $r['status']]]);
        }
        case 'act': {
            $u = require_login();
            $no = trim((string)($body['no'] ?? ''));
            $r = q1('SELECT * FROM rooms WHERE no=?', [$no]);
            if (!$r) ww_fail('房间不存在', 404);
            $gid = (int)$r['game_id'];
            $g = ww_load_game($gid);
            if (!$g) ww_fail('对局不存在');
            $mp = q1('SELECT * FROM room_players WHERE room_id=? AND user_id=?', [(int)$r['id'], (int)$u['id']]);
            $mySeat = 0;
            if ($mp && (int)$mp['spectate'] === 0) {
                foreach ($g->st['players'] as $p) if (!$p['ai'] && $p['name'] === $u['name']) { $mySeat = (int)$p['seat']; break; }
            }
            if (!$mySeat) ww_fail('观战或不在局内，不能行动', 403);
            $kind = (string)($body['kind'] ?? '');
            $lock = ww_lock('game-' . $gid);
            try {
                $act = ww_human_act($g, $mySeat, $body);
                if ($act['kind'] !== '__none__') ww_apply_auto($g, $mySeat, $act);
            } finally {
                ww_unlock($lock);
            }
            ww_save_game($g);
            ww_schedule_step($g);
            $row = q1('SELECT deadline_at,next_ai_at FROM games WHERE id=?', [$gid]);
            $view = $g->viewFor($mySeat, ww_game_events($gid));
            $view['deadlineAt'] = (int)$row['deadline_at'];
            $view['nextAiAt'] = (int)$row['next_ai_at'];
            $view['nowMs'] = ww_now_ms();
            ww_json(['ok' => 1, 'game' => $view]);
        }

        /* ================= 双人组队 ================= */
        case 'duo_invite': {
            $u = require_login();
            if (!feature_enabled('duo')) ww_fail('双人组队功能已关闭');
            $fid = (int)($body['to'] ?? 0);
            $f = q1('SELECT * FROM users WHERE id=? AND banned=0', [$fid]);
            if (!$f) ww_fail('对方不存在');
            $r = ww_my_room((int)$u['id']);
            $no = $r ? $r['no'] : '';
            q('INSERT INTO duo_invites(from_u,to_u,room_no,status,created) VALUES(?,?,?,?,?)', [(int)$u['id'], $fid, $no, 'pending', time()]);
            ww_json(['ok' => 1]);
        }
        case 'duo_pending': {
            $u = require_login();
            $rows = q('SELECT di.*, us.name AS fname FROM duo_invites di JOIN users us ON us.id=di.from_u WHERE di.to_u=? AND di.status=? ORDER BY di.id DESC LIMIT 10', [(int)$u['id'], 'pending'])->fetchAll();
            $out = [];
            foreach ($rows as $d) $out[] = ['id' => (int)$d['id'], 'from' => $d['fname'], 'roomNo' => $d['room_no']];
            ww_json(['ok' => 1, 'invites' => $out]);
        }
        case 'duo_accept': {
            $u = require_login();
            $id = (int)($body['id'] ?? 0);
            $d = q1('SELECT * FROM duo_invites WHERE id=? AND to_u=? AND status=?', [$id, (int)$u['id'], 'pending']);
            if (!$d) ww_fail('邀请不存在或已处理');
            q("UPDATE duo_invites SET status='accepted' WHERE id=?", [$id]);
            if ($d['room_no']) {
                $r = q1('SELECT * FROM rooms WHERE no=? AND status IN (?,?)', [$d['room_no'], 'waiting', 'playing']);
                if ($r) ww_join_room((int)$r['id'], (int)$u['id']);
                ww_json(['ok' => 1, 'no' => $d['room_no']]);
            }
            ww_json(['ok' => 1]);
        }
        case 'duo_decline': {
            $u = require_login();
            q("UPDATE duo_invites SET status='declined' WHERE id=? AND to_u=?", [(int)($body['id'] ?? 0), (int)$u['id']]);
            ww_json(['ok' => 1]);
        }

        /* ================= 社区 ================= */
        case 'friends': {
            $u = require_login();
            $rows = q('SELECT us.id, us.name, us.avatar, us.vip_until,
                (SELECT COUNT(*) FROM friend_msgs fm WHERE fm.from_u=us.id AND fm.to_u=? AND fm.is_read=0) AS unread
                FROM friends f JOIN users us ON us.id=f.friend_id WHERE f.user_id=? ORDER BY us.name', [(int)$u['id'], (int)$u['id']])->fetchAll();
            $out = [];
            foreach ($rows as $r) $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'avatar' => $r['avatar'], 'vip' => (int)$r['vip_until'] > time(), 'unread' => (int)$r['unread']];
            ww_json(['ok' => 1, 'friends' => $out]);
        }
        case 'friend_add': {
            $u = require_login();
            $name = trim((string)($body['name'] ?? ''));
            $f = q1('SELECT * FROM users WHERE name=? AND banned=0', [$name]);
            if (!$f) ww_fail('用户不存在');
            if ((int)$f['id'] === (int)$u['id']) ww_fail('不能添加自己');
            if (qv('SELECT COUNT(*) FROM friends WHERE user_id=? AND friend_id=?', [(int)$u['id'], (int)$f['id']]) > 0) ww_fail('已经是好友了');
            q('INSERT INTO friends(user_id,friend_id,created) VALUES(?,?,?)', [(int)$u['id'], (int)$f['id'], time()]);
            q('INSERT INTO friends(user_id,friend_id,created) VALUES(?,?,?)', [(int)$f['id'], (int)$u['id'], time()]);
            ww_json(['ok' => 1]);
        }
        case 'friend_del': {
            $u = require_login();
            $fid = (int)($body['id'] ?? 0);
            q('DELETE FROM friends WHERE (user_id=? AND friend_id=?) OR (user_id=? AND friend_id=?)', [(int)$u['id'], $fid, $fid, (int)$u['id']]);
            ww_json(['ok' => 1]);
        }
        case 'fmsg': {
            $u = require_login();
            $fid = (int)($_GET['with'] ?? 0);
            $rows = q('SELECT fm.*, us.name AS fname FROM friend_msgs fm JOIN users us ON us.id=fm.from_u WHERE (fm.from_u=? AND fm.to_u=?) OR (fm.from_u=? AND fm.to_u=?) ORDER BY fm.id ASC LIMIT 200', [(int)$u['id'], $fid, $fid, (int)$u['id']])->fetchAll();
            q('UPDATE friend_msgs SET is_read=1 WHERE from_u=? AND to_u=?', [$fid, (int)$u['id']]);
            $out = [];
            foreach ($rows as $m) $out[] = ['from' => (int)$m['from_u'], 'fromName' => $m['fname'], 'text' => $m['text'], 'ts' => (int)$m['created']];
            ww_json(['ok' => 1, 'msgs' => $out]);
        }
        case 'fmsg_send': {
            $u = require_login();
            $fid = (int)($body['to'] ?? 0);
            $text = trim(mb_substr((string)($body['text'] ?? ''), 0, 500));
            if ($text === '') ww_fail('消息不能为空');
            if (qv('SELECT COUNT(*) FROM friends WHERE user_id=? AND friend_id=?', [(int)$u['id'], $fid]) === 0) ww_fail('只能给好友发消息');
            q('INSERT INTO friend_msgs(from_u,to_u,text,is_read,created) VALUES(?,?,?,0,?)', [(int)$u['id'], $fid, $text, time()]);
            ww_json(['ok' => 1]);
        }
        case 'unread_total': {
            $u = require_login();
            $n = (int)qv('SELECT COUNT(*) FROM friend_msgs WHERE to_u=? AND is_read=0', [(int)$u['id']]);
            $d = (int)qv("SELECT COUNT(*) FROM duo_invites WHERE to_u=? AND status='pending'", [(int)$u['id']]);
            ww_json(['ok' => 1, 'unread' => $n, 'duo' => $d]);
        }
        case 'rank': {
            $rows = q('SELECT name,avatar,games,wins,vip_until FROM users WHERE banned=0 AND games>0 ORDER BY wins DESC, games DESC LIMIT 50')->fetchAll();
            $out = [];
            foreach ($rows as $i => $r) $out[] = ['rank' => $i + 1, 'name' => $r['name'], 'avatar' => $r['avatar'], 'games' => (int)$r['games'], 'wins' => (int)$r['wins'], 'vip' => (int)$r['vip_until'] > time()];
            ww_json(['ok' => 1, 'rank' => $out]);
        }
        case 'forum_list': {
            $rows = q('SELECT fp.*, us.name AS uname, us.avatar AS uava FROM forum_posts fp LEFT JOIN users us ON us.id=fp.user_id ORDER BY fp.id DESC LIMIT 50')->fetchAll();
            $out = [];
            foreach ($rows as $r) $out[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'text' => $r['text'], 'by' => $r['uname'] !== null ? $r['uname'] : '村务站', 'avatar' => $r['uava'] !== null ? $r['uava'] : '📢', 'ts' => (int)$r['created']];
            ww_json(['ok' => 1, 'posts' => $out]);
        }
        case 'forum_post': {
            $u = require_login();
            if (!feature_enabled('forum')) ww_fail('论坛功能已关闭');
            $title = trim(mb_substr((string)($body['title'] ?? ''), 0, 60));
            $text = trim(mb_substr((string)($body['text'] ?? ''), 0, 2000));
            if ($title === '' || $text === '') ww_fail('标题和正文不能为空');
            q('INSERT INTO forum_posts(user_id,title,text,created) VALUES(?,?,?,?)', [(int)$u['id'], $title, $text, time()]);
            ww_json(['ok' => 1]);
        }
        case 'changelog': {
            $rows = q('SELECT * FROM changelog ORDER BY id DESC LIMIT 50')->fetchAll();
            ww_json(['ok' => 1, 'logs' => $rows]);
        }

        /* ================= 商城 VIP ================= */
        case 'vip_buy': {
            $u = require_login();
            if (!feature_enabled('shop')) ww_fail('商城功能已关闭');
            $plan = (string)($body['plan'] ?? 'month');
            $days = ['month' => 30, 'season' => 90, 'year' => 365][$plan] ?? 0;
            if (!$days) ww_fail('套餐不存在');
            $base = max((int)$u['vip_until'], time());
            q('UPDATE users SET vip_until=? WHERE id=?', [$base + $days * 86400, (int)$u['id']]);
            ww_json(['ok' => 1, 'until' => $base + $days * 86400]);
        }

        /* ================= 管理后台 ================= */
        case 'a_overview': {
            require_admin();
            ww_json(['ok' => 1, 'stats' => [
                'users' => (int)qv('SELECT COUNT(*) FROM users'),
                'rooms' => (int)qv('SELECT COUNT(*) FROM rooms'),
                'playing' => (int)qv("SELECT COUNT(*) FROM games WHERE status='playing'"),
                'posts' => (int)qv('SELECT COUNT(*) FROM forum_posts'),
            ], 'version' => WW_VERSION]);
        }
        case 'a_users': {
            require_admin();
            $rows = q('SELECT id,name,avatar,is_admin,banned,vip_until,games,wins,created FROM users ORDER BY id DESC LIMIT 200')->fetchAll();
            ww_json(['ok' => 1, 'users' => $rows]);
        }
        case 'a_user_save': {
            require_admin();
            $id = (int)($body['id'] ?? 0);
            $u = q1('SELECT * FROM users WHERE id=?', [$id]);
            if (!$u) ww_fail('用户不存在');
            if (isset($body['banned'])) q('UPDATE users SET banned=? WHERE id=?', [(int)!empty($body['banned']), $id]);
            if (isset($body['admin'])) q('UPDATE users SET is_admin=? WHERE id=?', [(int)!empty($body['admin']), $id]);
            if (!empty($body['vipDays'])) {
                $base = max((int)$u['vip_until'], time());
                q('UPDATE users SET vip_until=? WHERE id=?', [$base + (int)$body['vipDays'] * 86400, $id]);
            }
            if (!empty($body['newPass'])) q('UPDATE users SET pass=? WHERE id=?', [password_hash((string)$body['newPass'], PASSWORD_DEFAULT), $id]);
            ww_json(['ok' => 1]);
        }
        case 'a_features': {
            if ($method === 'POST') {
                require_admin();
                $f = $body['features'] ?? [];
                if (is_array($f)) set_setting('features', $f);
            } else require_admin();
            ww_json(['ok' => 1, 'features' => setting('features', [])]);
        }
        case 'a_display': {
            if ($method === 'POST') {
                require_admin();
                $site = setting('site', []);
                $h = max(0, min(100, (int)($body['maxPageHeight'] ?? 100)));
                $site['max_page_height'] = $h;
                if (!empty($body['name'])) $site['name'] = trim(mb_substr((string)$body['name'], 0, 24));
                if (!empty($body['theme'])) $site['theme'] = (string)$body['theme'];
                set_setting('site', $site);
            } else require_admin();
            ww_json(['ok' => 1, 'site' => setting('site', [])]);
        }
        case 'a_rooms': {
            require_admin();
            $rows = q('SELECT r.*, (SELECT COUNT(*) FROM room_players rp WHERE rp.room_id=r.id) AS cnt FROM rooms r ORDER BY r.id DESC LIMIT 50')->fetchAll();
            ww_json(['ok' => 1, 'rooms' => $rows]);
        }
        case 'a_close_room': {
            require_admin();
            $id = (int)($body['id'] ?? 0);
            $r = q1('SELECT * FROM rooms WHERE id=?', [$id]);
            if (!$r) ww_fail('房间不存在');
            if ($r['game_id']) { $g = ww_load_game((int)$r['game_id']); if ($g && !$g->isGameOver()) { $g->st['phase'] = 'GAME_OVER'; $g->st['winner'] = ''; $g->ev('GAME_OVER', 0, 0, '房間已被管理员关闭', 1); ww_save_game($g); } }
            q("UPDATE rooms SET status='ended' WHERE id=?", [$id]);
            ww_json(['ok' => 1]);
        }
        case 'a_backup': {
            require_admin();
            $dir = WW_STORAGE . '/backups';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $file = 'backup-' . date('Ymd-His') . '.sql';
            $sql = ww_dump_sql();
            file_put_contents($dir . '/' . $file, $sql);
            q('INSERT INTO backups(file,size,created) VALUES(?,?,?)', [$file, strlen($sql), time()]);
            ww_json(['ok' => 1, 'file' => $file]);
        }
        case 'a_backup_list': {
            require_admin();
            ww_json(['ok' => 1, 'backups' => q('SELECT * FROM backups ORDER BY id DESC LIMIT 50')->fetchAll()]);
        }
        case 'a_backup_dl': {
            require_admin();
            $id = (int)($_GET['id'] ?? 0);
            $b = q1('SELECT * FROM backups WHERE id=?', [$id]);
            if (!$b) ww_fail('备份不存在', 404);
            $path = WW_STORAGE . '/backups/' . basename((string)$b['file']);
            if (!is_file($path)) ww_fail('备份文件丢失', 404);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($path) . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
        case 'a_update': {
            require_admin();
            if ($method === 'POST') {
                $applied = ww_apply_patches();
                if ($applied) q('INSERT INTO changelog(version,date,text) VALUES(?,?,?)', [WW_VERSION, date('Y-m-d'), '应用补丁：' . implode('；', $applied)]);
                ww_json(['ok' => 1, 'applied' => $applied, 'version' => WW_VERSION]);
            }
            $done = array_column(q('SELECT v FROM migrations')->fetchAll(), 'v');
            $pending = [];
            foreach (ww_patches() as $v => $p) if (!in_array($v, $done, true)) $pending[] = ['v' => $v, 'text' => $p['text']];
            ww_json(['ok' => 1, 'version' => WW_VERSION, 'pending' => $pending, 'appliedCount' => count($done)]);
        }
        case 'a_plugins': {
            if ($method === 'POST') {
                require_admin();
                $name = (string)($body['name'] ?? '');
                $on = !empty($body['on']);
                $m = ww_plugin_manifests();
                if (!isset($m[$name])) ww_fail('插件不存在');
                ww_set_plugin_enabled($name, $on);
            } else require_admin();
            $list = [];
            foreach (ww_plugin_manifests() as $name => $m) {
                $list[] = ['name' => $name, 'version' => $m['version'] ?? '1.0', 'desc' => $m['desc'] ?? '', 'hooks' => $m['hooks'] ?? [], 'on' => ww_plugin_enabled($name)];
            }
            ww_json(['ok' => 1, 'plugins' => $list]);
        }
        case 'a_setting': {
            require_admin();
            if ($method === 'POST') {
                $k = (string)($body['k'] ?? '');
                if (!in_array($k, ['ai_delay_scale'], true)) ww_fail('该设置项不支持在线修改');
                $v = (float)($body['v'] ?? 1);
                if ($k === 'ai_delay_scale') $v = max(0.1, min(3, $v));
                set_setting($k, $v);
            }
            ww_json(['ok' => 1, 'ai_delay_scale' => (float)setting('ai_delay_scale', 1)]);
        }
        case 'a_changelog_add': {
            require_admin();
            $text = trim(mb_substr((string)($body['text'] ?? ''), 0, 500));
            if ($text === '') ww_fail('内容不能为空');
            q('INSERT INTO changelog(version,date,text) VALUES(?,?,?)', [WW_VERSION, date('Y-m-d'), $text]);
            ww_json(['ok' => 1]);
        }

        default:
            ww_fail('未知接口: ' . $action, 404);
    }
} catch (RuntimeException $e) {
    ww_fail($e->getMessage());
} catch (Throwable $e) {
    set_setting('last_api_error', date('H:i:s ') . $e->getMessage());
    ww_fail('服务内部错误，请稍后再试', 500);
}

/* ================= 辅助函数 ================= */

/** 真人动作 → 引擎调用（与托管共用 ww_apply_auto 的 kind 词汇表）。 */
function ww_human_act(Game $g, int $seat, array $b): array
{
    $kind = (string)($b['kind'] ?? '');
    switch ($kind) {
        case 'GUARD': return ['kind' => 'GUARD', 'target' => (int)($b['target'] ?? 0)];
        case 'WOLF_KILL': return ['kind' => 'WOLF_KILL', 'target' => (int)($b['target'] ?? 0)];
        case 'SEER': return ['kind' => 'SEER', 'target' => (int)($b['target'] ?? 0)];
        case 'WITCH': return ['kind' => 'WITCH', 'save' => (int)($b['save'] ?? 0), 'poison' => (int)($b['poison'] ?? 0)];
        case 'CROW': return ['kind' => 'CROW', 'target' => (int)($b['target'] ?? 0)];
        case 'SILENCER': return ['kind' => 'SILENCER', 'target' => (int)($b['target'] ?? 0)];
        case 'SHERIFF_SIGNUP': return ['kind' => 'SHERIFF_SIGNUP', 'yes' => !empty($b['yes'])];
        case 'SHERIFF_VOTE': return ['kind' => 'SHERIFF_VOTE', 'target' => (int)($b['target'] ?? 0)];
        case 'SPEECH': return ['kind' => 'SPEECH', 'line' => (string)($b['line'] ?? '')];
        case 'PK_SPEECH': return ['kind' => 'PK_SPEECH', 'line' => (string)($b['line'] ?? '')];
        case 'VOTE': return ['kind' => 'VOTE', 'target' => (int)($b['target'] ?? 0)];
        case 'PK_VOTE': return ['kind' => 'PK_VOTE', 'target' => (int)($b['target'] ?? 0)];
        case 'LAST_WORDS': return ['kind' => 'LAST_WORDS', 'line' => (string)($b['line'] ?? '')];
        case 'SHOOT': return ['kind' => 'SHOOT', 'target' => (int)($b['target'] ?? 0)];
        case 'BLOWUP': return ['kind' => 'BLOWUP', 'target' => (int)($b['target'] ?? 0)];
        default: ww_fail('未知动作');
    }
}

function ww_join_room(int $rid, int $uid): void
{
    if (qv('SELECT COUNT(*) FROM room_players WHERE room_id=? AND user_id=?', [$rid, $uid]) > 0) return;
    $max = (int)qv('SELECT max_players FROM rooms WHERE id=?', [$rid], 9);
    $cnt = (int)qv('SELECT COUNT(*) FROM room_players WHERE room_id=? AND spectate=0', [$rid]);
    if ($cnt >= $max) ww_fail('房间已满，可选观战进入');
    // 先退出其他房间（一次只在一个房间）
    $old = ww_my_room($uid);
    if ($old && (int)$old['id'] !== $rid) ww_leave_room((int)$old['id'], $uid);
    $used = array_map('intval', array_column(q('SELECT seat FROM room_players WHERE room_id=?', [$rid])->fetchAll(), 'seat'));
    $seat = 1;
    for ($i = 1; $i <= $max; $i++) if (!in_array($i, $used, true)) { $seat = $i; break; }
    q('INSERT INTO room_players(room_id,user_id,seat,ready,last_seen) VALUES(?,?,?,?,?)', [$rid, $uid, $seat, 0, time()]);
}

function ww_leave_room(int $rid, int $uid): void
{
    // 对局进行中：座位转 AI 托管，对局不中断
    $r = q1('SELECT * FROM rooms WHERE id=?', [$rid]);
    if ($r && $r['status'] === 'playing' && $r['game_id']) {
        $u = q1('SELECT name FROM users WHERE id=?', [$uid]);
        if ($u) {
            $g = ww_load_game((int)$r['game_id']);
            if ($g && !$g->isGameOver()) {
                foreach ($g->st['players'] as &$p) {
                    if (!$p['ai'] && $p['name'] === $u['name']) { $p['ai'] = true; $p['userId'] = 0; }
                }
                unset($p);
                ww_save_game($g);
            }
        }
    }
    q('DELETE FROM room_players WHERE room_id=? AND user_id=?', [$rid, $uid]);
    // 房主离开且无真人：房直接结束
    if ($r) {
        $ownerHere = qv('SELECT COUNT(*) FROM room_players WHERE room_id=? AND user_id=?', [$rid, (int)$r['owner_id']]);
        $anyHere = qv('SELECT COUNT(*) FROM room_players WHERE room_id=?', [$rid]);
        if (!$ownerHere && (!$anyHere || (int)$r['owner_id'] === $uid)) {
            q("UPDATE rooms SET status='ended' WHERE id=? AND status='waiting'", [$rid]);
        }
    }
}

function ww_my_room(int $uid): ?array
{
    $m = q1('SELECT rp.room_id FROM room_players rp JOIN rooms r ON r.id=rp.room_id WHERE rp.user_id=? AND r.status IN (?,?) ORDER BY rp.room_id DESC LIMIT 1', [$uid, 'waiting', 'playing']);
    if (!$m) return null;
    return q1('SELECT * FROM rooms WHERE id=?', [(int)$m['room_id']]);
}

/** 全量 SQL 导出（MySQL/SQLite 通用文本）。 */
function ww_dump_sql(): string
{
    $out = "-- Werewolf Online backup @ " . date('c') . "\n";
    foreach (['users', 'rooms', 'room_players', 'games', 'events', 'friends', 'friend_msgs', 'forum_posts', 'changelog', 'settings', 'backups', 'duo_invites', 'vote_log', 'migrations'] as $t) {
        try {
            $rows = q("SELECT * FROM `$t`")->fetchAll();
        } catch (Throwable $e) { continue; }
        $out .= "\n-- TABLE $t (" . count($rows) . " rows)\n";
        foreach ($rows as $r) {
            $cols = array_keys($r);
            $vals = array_map(function ($v) {
                return $v === null ? 'NULL' : (is_numeric($v) ? $v : "'" . str_replace("'", "''", (string)$v) . "'");
            }, array_values($r));
            $out .= "INSERT INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
        }
    }
    return $out;
}
