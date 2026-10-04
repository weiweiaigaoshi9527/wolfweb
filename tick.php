<?php
/**
 * 对局存储 + 懒调度器（virtual host 没有 WebSocket/常驻进程，
 * 由任意一次 API 轮询触发 tick，把到点的 AI 动作/超时动作推进一至多步）。
 */

function ww_load_game(int $gid): ?Game
{
    $row = q1('SELECT * FROM games WHERE id=?', [$gid]);
    if (!$row) return null;
    $g = new Game(json_decode($row['state'], true) ?: []);
    $g->id = (int)$row['id'];
    return $g;
}

function ww_save_game(Game $g): void
{
    $st = $g->st;
    $status = $st['phase'] === 'GAME_OVER' ? 'over' : 'playing';
    q(
        'UPDATE games SET state=?, day=?, phase=?, action_kind=?, current=?, status=? WHERE id=?',
        [json_encode($st, JSON_UNESCAPED_UNICODE), $st['day'], $st['phase'], $st['kind'], $st['cur'], $status, $g->id]
    );
    foreach ($g->drainEvents() as $e) {
        q(
            'INSERT INTO events(game_id,seq,phase,day,type,actor,target,detail,public,to_seat,created) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            [$g->id, $e['seq'], $e['phase'], $e['day'], $e['type'], $e['actor'], $e['target'], $e['detail'], $e['public'], $e['to'] ?? 0, time()]
        );
    }
    if ($status === 'over') ww_finish_game($g);
}

/** 为当前步设置时钟：AI 座位给拟人延迟（可按 ai_delay_scale 调速），真人座位给阶段超时。 */
function ww_schedule_step(Game $g): void
{
    if ($g->isGameOver()) {
        q('UPDATE games SET next_ai_at=0, deadline_at=0 WHERE id=?', [$g->id]);
        return;
    }
    $kind = $g->currentActionKind();
    $seat = $g->currentActor();
    $timeoutMs = (Game::TIMEOUTS[$kind] ?? 30) * 1000;
    $p = $g->bySeat($seat);
    $isAi = $p && $p['ai'];
    $now = ww_now_ms();
    $scale = (float)setting('ai_delay_scale', 1);
    if ($scale <= 0) $scale = 1;
    $nextAi = $isAi ? $now + max(120, (int)round(ww_ai_delay_ms() * $scale)) : 0;
    q('UPDATE games SET next_ai_at=?, deadline_at=? WHERE id=?', [$nextAi, $now + $timeoutMs, $g->id]);
}

function ww_recent_speeches(int $gid): array
{
    $rows = q('SELECT detail FROM events WHERE game_id=? AND type IN (?,?) ORDER BY id DESC LIMIT 30', [$gid, 'SPEECH', 'LAST_WORDS'])->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];
}

/** 读取持久化事件（按 seq 升序，最近 $limit 条），用于视图过滤。 */
function ww_game_events(int $gid, int $limit = 120): array
{
    $rows = q('SELECT seq,phase,day,type,actor,target,detail,public,to_seat FROM events WHERE game_id=? ORDER BY seq DESC LIMIT ' . (int)$limit, [$gid])->fetchAll();
    $rows = array_reverse($rows);
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['seq' => (int)$r['seq'], 'phase' => $r['phase'], 'day' => (int)$r['day'], 'type' => $r['type'],
            'actor' => (int)$r['actor'], 'target' => (int)$r['target'], 'detail' => $r['detail'],
            'public' => (int)$r['public'], 'to' => (int)$r['to_seat']];
    }
    return $out;
}

/** 把托管决策落到引擎。 */
function ww_apply_auto(Game $g, int $seat, array $act): void
{
    $kind = $act['kind'];
    switch ($kind) {
        case 'GUARD': $g->submitGuard($seat, (int)$act['target']); break;
        case 'WOLF_KILL': $g->submitWolfKill($seat, (int)$act['target']); break;
        case 'SEER': $g->submitSeer($seat, (int)$act['target']); break;
        case 'WITCH': $g->submitWitch($seat, [(int)$act['save'], (int)$act['poison']]); break;
        case 'CROW': $g->submitCrow($seat, (int)$act['target']); break;
        case 'SILENCER': $g->submitSilencer($seat, (int)$act['target']); break;
        case 'SHERIFF_SIGNUP': $g->submitSheriffSignup($seat, !empty($act['yes'])); break;
        case 'SHERIFF_VOTE': $g->submitSheriffVote($seat, (int)$act['target']); break;
        case 'SPEECH': $g->submitSpeech($seat, (string)$act['line']); break;
        case 'PK_SPEECH': $g->submitTiebreakSpeech($seat, (string)$act['line']); break;
        case 'VOTE': $g->submitVote($seat, (int)$act['target']); break;
        case 'PK_VOTE': $g->submitTiebreakVote($seat, (int)$act['target']); break;
        case 'LAST_WORDS': $g->submitLastWords($seat, (string)$act['line']); break;
        case 'SHOOT': $g->submitShoot($seat, (int)$act['target']); break;
        case 'BLOWUP': $g->submitBlowUp($seat, (int)$act['target']); break;
        default: throw new RuntimeException('未知托管动作 ' . $kind);
    }
}

function ww_step_due(array $row): bool
{
    $now = ww_now_ms();
    $ai = (int)$row['next_ai_at'];
    $dl = (int)$row['deadline_at'];
    return ($ai > 0 && $ai <= $now) || ($dl > 0 && $dl <= $now);
}

/**
 * 推进全部到期对局；$gid 传入时只推进该局（玩家轮询自己的局时优先保证它走一步）。
 */
function ww_tick(int $gid = 0): void
{
    $now = ww_now_ms();
    if ($gid > 0) {
        $rows = q('SELECT id,next_ai_at,deadline_at FROM games WHERE id=? AND status=?', [$gid, 'playing'])->fetchAll();
        if ($rows && !ww_step_due($rows[0])) return; // 还没到点：不推进
    } else {
        $rows = q('SELECT id,next_ai_at,deadline_at FROM games WHERE status=? AND ((next_ai_at>0 AND next_ai_at<=?) OR (deadline_at>0 AND deadline_at<=?)) LIMIT 12', ['playing', $now, $now])->fetchAll();
    }
    foreach ($rows as $row) {
        ww_tick_one((int)$row['id']);
    }
}

function ww_tick_one(int $gid): void
{
    $lock = ww_lock('game-' . $gid);
    try {
        for ($i = 0; $i < 12; $i++) {
            $row = q1('SELECT * FROM games WHERE id=?', [$gid]);
            if (!$row || $row['status'] !== 'playing') break;
            if ($i > 0 && !ww_step_due($row)) break; // 第一步之后：到点才继续
            $g = ww_load_game($gid);
            $seat = $g->currentActor();
            $p = $g->bySeat($seat);
            if (!$p) break;
            $now = ww_now_ms();
            $dl = (int)$row['deadline_at'];
            $ai = (int)$row['next_ai_at'];
            $isAi = $p['ai'];
            $timeoutHit = $dl > 0 && $dl <= $now;
            $aiHit = $isAi && $ai > 0 && $ai <= $now;
            if (!$timeoutHit && !$aiHit) break;

            // 白狼王 AI 小概率自爆
            if ($isAi && !$timeoutHit && $g->canBlowUp($seat) && ww_rnd(1, 100) <= 8) {
                $goods = array_values(array_filter($g->aliveSeats(), function ($s) use ($g) {
                    return !$g->isWolfRole($g->bySeat($s)['role']);
                }));
                if (!empty($goods)) {
                    ww_apply_auto($g, $seat, ['kind' => 'BLOWUP', 'target' => ww_pick($goods)]);
                    ww_save_game($g);
                    ww_schedule_step($g);
                    continue;
                }
            }
            $act = ww_apply_plugins('auto_action', ['act' => null, 'g' => $g, 'seat' => $seat, 'recent' => ww_recent_speeches($gid)]);
            $act = $act['act'] ?? null;
            if (!$act) $act = ww_auto_action($g, $seat, ww_recent_speeches($gid));
            ww_apply_auto($g, $seat, $act);
            ww_save_game($g);
            ww_schedule_step($g);
        }
    } catch (Throwable $e) {
        // 推进失败不阻塞页面：记录到设置表便于排查
        set_setting('last_tick_error', date('H:i:s ') . $e->getMessage());
    } finally {
        ww_unlock($lock);
    }
}

/** 结算：更新战绩、房间状态，触发插件钩子。 */
function ww_finish_game(Game $g): void
{
    $st = $g->st;
    $winner = (string)$st['winner'];
    $v = $g->viewFor(0);
    foreach ($v['seats'] as $s) {
        if ($s['ai'] || empty($s['name'])) continue;
        $u = q1('SELECT id FROM users WHERE name=?', [$s['name']]);
        if (!$u) continue;
        $seatData = $g->bySeat((int)$s['seat']);
        $good = !$g->isWolfRole($seatData['role']);
        $win = ($winner === 'good') === $good;
        q('UPDATE users SET games=games+1, wins=wins+? WHERE id=?', [$win ? 1 : 0, (int)$u['id']]);
    }
    q("UPDATE rooms SET status='ended' WHERE id=(SELECT room_id FROM games WHERE id=?)", [$g->id]);
    ww_apply_plugins('on_game_over', ['g' => $g, 'winner' => $winner]);
}
