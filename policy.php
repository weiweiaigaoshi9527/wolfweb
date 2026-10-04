<?php
/**
 * AI 托管策略 + 旁白文案 + 发言反雷同（与主项目 AutoPolicy/Humanizer/PhraseDedup 同构）。
 */

/* ---------------- 旁白 ---------------- */

function ww_narr(string $key, array $v = []): string
{
    // 按需构造：只取本次用到的键，避免未传键触发 PHP 8 未定义索引警告
    $day = (int)($v['day'] ?? 0);
    $name = (string)($v['name'] ?? '');
    $target = (string)($v['target'] ?? '');
    $role = (string)($v['role'] ?? '');
    $names = (string)($v['names'] ?? '');
    $side = (string)($v['side'] ?? 'good');
    $hasDead = !empty($v['dead']);

    switch ($key) {
        case 'night_fall':
            $pool = [
                "夜幕落下，村庄陷入沉睡（第 {$day} 夜）。",
                "第 {$day} 夜降临，狼的眼睛在黑暗里睁开了。",
                "月亮躲进云层，第 {$day} 夜开始了。",
            ];
            break;
        case 'dawn':
            $pool = $hasDead ? [
                "天亮了。昨夜，" . $names . " 永远地留在了黑夜里。",
                "晨光刺破夜幕，村庄发现 " . $names . " 已经凉了。",
            ] : [
                "天亮了，昨夜是平安夜，无人出局。",
                "晨雾散去——昨夜是平安夜，所有人都活着。",
            ];
            break;
        case 'day_discuss':
            $pool = [
                "第 {$day} 天，请大家依次发言，找出藏在人群里的狼。",
                "讨论开始。第 {$day} 天，谁的话里有破绽？",
            ];
            break;
        case 'vote_begin':
            $pool = ["发言结束，请投票放逐一名嫌疑者。", "投票开始，票出你心中的狼人。"];
            break;
        case 'pk_begin':
            $pool = [
                "平票！" . $names . " 进入 PK，请依次辩解。",
                "僵持不下，" . $names . " 上台为自己一辩。",
            ];
            break;
        case 'exile':
            $pool = [
                "全村的手指指向了 " . $name . "，TA 被放逐出局，翻牌：【" . $role . "】。",
                $name . " 在众目睽睽下出局——【" . $role . "】。",
            ];
            break;
        case 'blowup':
            $pool = [
                "轰！" . $name . " 自爆了，带走 " . $target . "，翻牌：【" . $role . "】。",
            ];
            break;
        case 'game_over':
            $pool = [$side === 'good' ? "最后一头狼倒下了——好人阵营胜利！" : "狼群撕下了伪装——狼人阵营胜利！"];
            break;
        default:
            $pool = [$key];
    }
    return ww_pick($pool);
}

/* ---------------- 发言池（语癖人设 8 套） ---------------- */

function ww_personas(): array
{
    return [
        ['tag' => '稳', 'lead' => ['嗯……', '我想了想，', '从票型上看，']],
        ['tag' => '冲', 'lead' => ['我说直接点，', '别绕了，', '听我的，']],
        ['tag' => '怂', 'lead' => ['那个……', '我不太确定，', '先保一下自己，']],
        ['tag' => '逻辑', 'lead' => ['梳理一下：', '按时间线看，', '这里有个矛盾，']],
        ['tag' => '气氛', 'lead' => ['家人们，', '这把有点意思，', '我说个细节，']],
        ['tag' => '老练', 'lead' => ['打了这么多把，', '凭经验讲，', '这个局面，']],
        ['tag' => '耿直', 'lead' => ['我就一句话，', '直说了啊，', '我票很硬，']],
        ['tag' => '神叨', 'lead' => ['直觉告诉我，', '有一种可能，', '细思极恐，']],
    ];
}

function ww_speech_pools(): array
{
    return [
        'open' => [
            '我先听一听大家的发言。', '这轮我没太多要说的，先观察一下。', '票型我先记着，稍后再表态。',
            '我暂时保留意见，先听后置位怎么说。', '前面几位说的我都记下了。', '我先跟一票大势，回头再细说。',
            '这轮先过，重点听下一位。', '我还在理思路，大家继续。', '我先不站边，听完再定。',
            '这把信息太少，我谨慎一点。', '我想看看谁不敢接话。', '先把票记住，别回头又改口。',
        ],
        'suspect' => [
            '{t} 的发言有点飘，我先标一下。', '我不太信 {t}，逻辑没闭环。', '{t} 昨晚的票我不理解，给个说法？',
            '重点看 {t}，TA 一直在带节奏。', '{t} 的身份感很奇怪，小心点。', '{t} 从头到尾都在划水，很像狼。',
            '我建议先出 {t}，TA 的视角太干了。', '{t} 刚才那句其实是废话，拖时间。', '我怀疑 {t} 在替谁挡票。',
            '{t} 的站位太滑，谁都不像。',
        ],
        'claim' => [
            '我身份比较普通，好人视角。', '我是平民，别在我身上浪费票。', '我的信息不多，但站边清晰。',
            '信我一次，我这票很有用。', '我是场上最干净的那张牌。', '我没什么花活，就是找狼。',
            '我的票很贵，但我不藏。', '要出我可以，先想清楚有没有第二匹狼。', '我是好人，发言可以被我负责。',
        ],
        'seer_claim' => [
            '我预言家，昨晚验了 {t}，{r}。', '开口验人：{t} 是 {r}，别跑票。',
        ],
        'last' => [
            '就到这里吧，大家加油。', '祝你们玩得开心，我先行一步。', '我的信息都用完了，就看你们的了。',
            '记住我的话，别浪费这张翻牌。',
        ],
        'night' => ['先按老规矩来。', '稳一手，别浪。', '今天先这样，收。', '按计划走。'],
    ];
}

/* ---------------- 反雷同（n-gram 相似度） ---------------- */

function ww_norm(string $s): string
{
    $s = preg_replace('/[\x{3000}-\x{303F}\x{FF00}-\x{FFEF}\p{P}]/u', '', $s); // 先剥标点
    $s = str_replace(['吧', '呢', '啊', '了', '的', '是', '我', '你', '他'], '', $s); // 虚词折叠
    return $s;
}

function ww_ngrams(string $s, int $n = 2): array
{
    $s = ww_norm($s);
    $len = mb_strlen($s);
    if ($len < $n) return $len ? [$s] : [];
    $g = [];
    for ($i = 0; $i <= $len - $n; $i++) $g[] = mb_substr($s, $i, $n);
    return array_unique($g);
}

function ww_similarity(string $a, string $b): float
{
    $ga = ww_ngrams($a);
    $gb = ww_ngrams($b);
    if (!$ga || !$gb) return 0.0;
    $inter = count(array_intersect($ga, $gb));
    return $inter / min(count($ga), count($gb)); // containment，主项目同款
}

function ww_tooSimilar(string $line, array $recent, string $selfName = ''): bool
{
    foreach ($recent as $r) {
        if (ww_similarity($line, (string)$r) >= 0.6) return true;
    }
    return false;
}

/** 从池子里选一句与近期发言不雷同的台词，最多尝试 6 次。 */
function ww_pick_fresh(array $pool, array $recent, array $vars = []): string
{
    for ($i = 0; $i < 6; $i++) {
        $line = ww_pick($pool);
        foreach ($vars as $k => $v) $line = str_replace('{' . $k . '}', (string)$v, $line);
        if (!ww_tooSimilar($line, $recent)) return $line;
    }
    // 兜底：按池内顺序轮转一句，保证不与上一句相同
    $idx = ww_rnd(0, count($pool) - 1);
    for ($k = 0; $k < count($pool); $k++) {
        $line = $pool[($idx + $k) % count($pool)];
        foreach ($vars as $kk => $v) $line = str_replace('{' . $kk . '}', (string)$v, $line);
        if (!in_array($line, $recent, true)) return $line;
    }
    $line = $pool[$idx];
    foreach ($vars as $kk => $v) $line = str_replace('{' . $kk . '}', (string)$v, $line);
    return $line;
}

/** 主项目同款座位轮转兜底：同一座位不会连续两轮说同一句。 */
function ww_rotated(array $pool, int $seat, int $day, int $spoken): string
{
    $b = count($pool);
    if ($b === 0) return '';
    return $pool[(int)((($seat * 7 + $day * 13 + $spoken) % $b + $b) % $b)];
}

/* ---------------- 托管动作决策 ---------------- */

/**
 * 返回 ['kind' => ..., args...]，由 tick 转成具体 submit 调用。
 * @param array $recent 近期公开发言文本（去重用）
 */
function ww_auto_action(Game $g, int $seat, array $recent): array
{
    $me = $g->bySeat($seat);
    $kind = $g->currentActionKind();
    $alive = $g->aliveSeats();
    $others = array_values(array_diff($alive, [$seat]));
    $persons = ww_personas();
    $persona = $persons[$seat % count($persons)];

    switch ($kind) {
        case 'GUARD': {
            $cand = array_values(array_diff($alive, [$seat, (int)$g->st['guardLast']]));
            $t = empty($cand) ? 0 : ww_pick($cand);
            return ['kind' => 'GUARD', 'target' => $t];
        }
        case 'WOLF_KILL': {
            $goods = array_values(array_filter($alive, function ($s) use ($g) {
                return !$g->isWolfRole($g->bySeat($s)['role']);
            }));
            if (empty($goods)) return ['kind' => 'WOLF_KILL', 'target' => $others ? $others[0] : $seat];
            // 优先刀跳身份的（简化：随机偏好非村民）
            $weights = [];
            foreach ($goods as $s) {
                $role = $g->bySeat($s)['role'];
                $weights[$s] = $role === 'villager' ? 1 : 3;
            }
            $t = ww_weighted($weights);
            return ['kind' => 'WOLF_KILL', 'target' => $t];
        }
        case 'SEER': {
            $cand = $others;
            return ['kind' => 'SEER', 'target' => $cand ? ww_pick($cand) : $seat];
        }
        case 'WITCH': {
            $wt = (int)$g->st['night']['wolfTarget'];
            $save = 0;
            $poison = 0;
            if ($wt && !$g->st['witchSaveUsed']) {
                $save = ($wt === $seat || ww_rnd(1, 100) <= 45) ? $wt : 0;
            }
            if (!$save && !$g->st['witchPoisonUsed'] && ww_rnd(1, 100) <= 15 && $others) {
                $poison = ww_pick($others);
            }
            return ['kind' => 'WITCH', 'save' => $save, 'poison' => $poison];
        }
        case 'CROW': {
            return ['kind' => 'CROW', 'target' => $others ? ww_pick($others) : $seat];
        }
        case 'SILENCER': {
            return ['kind' => 'SILENCER', 'target' => $others ? ww_pick($others) : $seat];
        }
        case 'SHERIFF_SIGNUP':
            return ['kind' => 'SHERIFF_SIGNUP', 'yes' => ww_rnd(1, 100) <= 45];
        case 'SHERIFF_VOTE': {
            $cand = array_values(array_diff($g->sheriffCandidates(), [$seat]));
            return ['kind' => 'SHERIFF_VOTE', 'target' => $cand ? ww_pick($cand) : 0];
        }
        case 'SPEECH': {
            $line = ww_auto_speech($g, $seat, $recent, $persona);
            return ['kind' => 'SPEECH', 'line' => $line];
        }
        case 'PK_SPEECH':
            return ['kind' => 'PK_SPEECH', 'line' => ww_pick_fresh(['我是好人，票我不的好人后悔。', '冷静一点，我是平民。', '投我出去你们必输。'], $recent)];
        case 'VOTE': {
            // 1) 有人跳预言家并报出"狼人"结果 → 优先跟验票（好人阵营最基本的信息利用）
            $claimSeat = ww_claimedWolfTarget($g, $recent);
            if ($claimSeat && $claimSeat !== $seat && in_array($claimSeat, $others, true) && ww_rnd(1, 100) <= 75) {
                return ['kind' => 'VOTE', 'target' => (int)$claimSeat];
            }
            // 2) 跟票收敛：60% 跟当前最高票（大势）
            $tally = [];
            foreach ($g->st['votes'] as $t) {
                $t = (int)$t;
                if ($t !== $seat) $tally[$t] = ($tally[$t] ?? 0) + 1;
            }
            arsort($tally);
            $top = $tally ? (int)array_key_first($tally) : 0;
            if ($top && ww_rnd(1, 100) <= 60 && in_array($top, $others, true)) {
                return ['kind' => 'VOTE', 'target' => $top];
            }
            // 3) 乌鸦标记加权随机
            $weights = [];
            foreach ($others as $s) {
                $w = 1 + ($g->bySeat($s)['crow'] ?? 0) * 2;
                if ($claimSeat && $s === $claimSeat) $w += 3;
                $weights[$s] = $w;
            }
            return ['kind' => 'VOTE', 'target' => ww_weighted($weights)];
        }
        case 'PK_VOTE': {
            $list = array_values(array_diff($g->st['pk']['list'], [$seat]));
            return ['kind' => 'PK_VOTE', 'target' => $list ? ww_pick($list) : 0];
        }
        case 'LAST_WORDS':
            return ['kind' => 'LAST_WORDS', 'line' => ww_pick_fresh(ww_speech_pools()['last'], $recent)];
        case 'SHOOT': {
            $goods = array_values(array_filter($others, function ($s) use ($g) {
                return !$g->isWolfRole($g->bySeat($s)['role']);
            }));
            return ['kind' => 'SHOOT', 'target' => $goods ? ww_pick($goods) : ($others ? $others[0] : 0)];
        }
        default:
            return ['kind' => 'SPEECH', 'line' => '（过）'];
    }
}

/**
 * 从公开发言里解析"跳预言家并报出狼人"的目标座位（好人阵营最基本的信息利用）。
 * 匹配话术池：'我预言家，昨晚验了 X，狼人。' / '开口验人：X 是 狼人，别跑票。'
 */
function ww_claimedWolfTarget(Game $g, array $recent)
{
    foreach (array_reverse($recent) as $line) {
        $line = (string)$line;
        if (mb_strpos($line, '验') === false) continue;
        $hitWolf = mb_strpos($line, '是 狼人') !== false || mb_strpos($line, '是狼人') !== false || mb_strpos($line, '，狼人') !== false;
        if (!$hitWolf) continue;
        foreach ($g->st['players'] as $p) {
            if (!empty($p['alive']) && $p['name'] !== '' && mb_strpos($line, (string)$p['name']) !== false) return (int)$p['seat'];
        }
    }
    return 0;
}

function ww_weighted(array $weights){
    $sum = array_sum($weights);
    if ($sum <= 0) return array_key_first($weights);
    $r = ww_rnd(1, (int)$sum);
    foreach ($weights as $k => $w) {
        $r -= $w;
        if ($r <= 0) return $k;
    }
    return array_key_first($weights);
}

function ww_auto_speech(Game $g, int $seat, array $recent, array $persona): string
{
    $pools = ww_speech_pools();
    $alive = $g->aliveSeats();
    $others = array_values(array_diff($alive, [$seat]));
    $t = $others ? $g->nameOf(ww_pick($others)) : '前面那位';
    $vars = ['t' => $t];

    // 去重集合：全局近期发言 + 自己上一句（避免同座位连说同一句）
    $own = isset($g->st['lastLine'][$seat]) ? [(string)$g->st['lastLine'][$seat]] : [];
    $recentAll = array_merge($recent, $own);
    $lastOwn = $own[0] ?? '';
    $spoken = (int)($g->st['spoken'][$seat] ?? 0);

    /** 组装最终句（含语癖前缀）后再与上一句比对，最多重试 4 次 */
    $compose = function (array $pool, array $vars) use ($recentAll, $lastOwn, $persona) {
        $final = '';
        for ($k = 0; $k < 4; $k++) {
            $base = ww_pick_fresh($pool, $recentAll, $vars);
            $final = ww_rnd(1, 100) <= 30 ? ww_pick($persona['lead']) . $base : $base;
            // 同时拒绝：完全相同 / 仅差一个语癖前缀 / 与近期任何一句完全相同
            $dup = ($final === $lastOwn) || ($lastOwn !== '' && $base !== '' && mb_substr($lastOwn, -mb_strlen($base)) === $base);
            if (!$dup && !in_array($final, $recentAll, true)) return $final;
        }
        return $final;
    };

    // 预言家报验人（30% 概率）
    $me = $g->bySeat($seat);
    if ($me['role'] === 'seer' && !empty($g->st['seerClaims'][$seat]) && ww_rnd(1, 100) <= 30) {
        $last = end($g->st['seerClaims'][$seat]);
        $vars = ['t' => $g->nameOf((int)$last['target']), 'r' => $last['text']];
        return $compose($pools['seer_claim'], $vars);
    }
    if (ww_rnd(1, 100) <= 35) {
        $pool = $pools['suspect'];
    } elseif (ww_rnd(1, 100) <= 50) {
        $pool = $pools['open'];
    } else {
        $pool = $pools['claim'];
    }
    $line = $compose($pool, $vars);
    // 仍与上一句相同 → 改用座位轮转句兜底
    if ($line === $lastOwn) $line = ww_rotated($pool, $seat, (int)$g->st['day'], $spoken);
    return $line;
}

/** 拟人思考停顿（毫秒）：1.2~3.4s 基础 + 旁白余量。 */
function ww_ai_delay_ms(int $narrLen = 0): int
{
    $base = ww_rnd(1200, 3400);
    $narr = (int)round($narrLen * 0.267 * 1000) + 900; // 与主项目同款估算
    return min($base + $narr, 25000);
}
