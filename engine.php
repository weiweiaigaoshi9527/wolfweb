<?php
/**
 * 对局引擎（事件溯源）—— 与主项目 GameEngine 同构：
 * 不可变事件流 + 状态机推进；夜晚 守卫→狼刀→预言家→女巫→乌鸦，
 * 白天 遗言→发言→投票→放逐/PK→猎人枪/白狼王自爆；警长竞选可选。
 */
class Game
{
    public const ROLE_NAMES = [
        'werewolf' => '狼人', 'whiteWolf' => '白狼王', 'seer' => '预言家', 'witch' => '女巫',
        'guard' => '守卫', 'hunter' => '猎人', 'crow' => '乌鸦', 'silencer' => '禁言长老', 'villager' => '村民',
    ];
    public const TIMEOUTS = [
        'GUARD' => 30, 'WOLF_KILL' => 30, 'SEER' => 25, 'WITCH' => 30, 'CROW' => 20, 'SILENCER' => 20,
        'LAST_WORDS' => 25, 'SHERIFF_SIGNUP' => 20, 'SHERIFF_VOTE' => 20,
        'SPEECH' => 45, 'VOTE' => 25, 'PK_SPEECH' => 30, 'PK_VOTE' => 20, 'SHOOT' => 25, 'BLOWUP' => 20,
    ];

    public int $id = 0;
    public array $st = [];
    private array $evBuf = [];

    /* ---------------- 构造 / 建局 ---------------- */

    public function __construct(array $state = null)
    {
        if ($state !== null) $this->st = $state;
    }

    /** 用板子配置与座位表建新局。 */
    public static function create(array $board, array $players): Game
    {
        $g = new Game();
        $roles = [];
        foreach ($board['roles'] as $role => $n) for ($i = 0; $i < (int)$n; $i++) $roles[] = $role;
        shuffle($roles);
        $players = array_values($players);
        // 保证人数与板子一致（AI 已由房间层补齐）
        $list = [];
        foreach ($players as $i => $p) {
            $list[] = [
                'seat' => $i + 1, 'name' => $p['name'], 'userId' => (int)($p['userId'] ?? 0),
                'ai' => !empty($p['ai']), 'role' => $roles[$i] ?? 'villager', 'alive' => true,
                'isSheriff' => false, 'revealed' => false, 'crow' => 0, 'silencedUntil' => 0,
            ];
        }
        $g->st = [
            'players' => $list, 'board' => $board, 'day' => 0, 'phase' => 'INIT', 'kind' => '', 'cur' => 0,
            'night' => [], 'witchSaveUsed' => false, 'witchPoisonUsed' => false, 'guardLast' => 0,
            'speech' => ['order' => [], 'idx' => 0], 'votes' => [], 'pk' => ['list' => [], 'round' => 0],
            'lastQueue' => [], 'pendingShot' => null, 'sheriff' => 0, 'candidates' => [], 'signupAsked' => [],
            'winner' => '', 'seq' => 0, 'stepQueue' => [], 'spoken' => [], 'lastLine' => [],
        ];
        $g->ev('SETUP', 0, 0, '本局板子：' . ($board['name'] ?? '自定义') . '，共 ' . count($list) . ' 人入座', 1);
        if (!empty($board['sheriff'])) $g->startSheriffElection();
        else $g->nightStart();
        return $g;
    }

    /* ---------------- 事件 ---------------- */

    public function ev(string $type, int $actor, int $target, string $detail, int $public, int $to = 0): void
    {
        $this->st['seq']++;
        $this->evBuf[] = [
            'seq' => $this->st['seq'], 'phase' => $this->st['phase'], 'day' => $this->st['day'],
            'type' => $type, 'actor' => $actor, 'target' => $target, 'detail' => $detail,
            'public' => $public, 'to' => $to,
        ];
    }

    public function drainEvents(): array
    {
        $b = $this->evBuf;
        $this->evBuf = [];
        return $b;
    }

    public function events(): array { return $this->evBuf; }

    /* ---------------- 基础查询 ---------------- */

    public function bySeat(int $seat): ?array
    {
        foreach ($this->st['players'] as $p) if ($p['seat'] === $seat) return $p;
        return null;
    }

    /** 返回座位玩家的引用（PHP 数组是值语义，修改存活/标记必须走引用）。 */
    private function &playerRef(int $seat): array
    {
        foreach ($this->st['players'] as $i => $p) {
            if ((int)$p['seat'] === $seat) return $this->st['players'][$i];
        }
        throw new RuntimeException('座位不存在: ' . $seat);
    }
    public function aliveSeats(): array
    {
        $r = [];
        foreach ($this->st['players'] as $p) if ($p['alive']) $r[] = $p['seat'];
        return $r;
    }
    public function seatsWithRole(string $role): array
    {
        $r = [];
        foreach ($this->st['players'] as $p) if ($p['role'] === $role) $r[] = $p['seat'];
        return $r;
    }
    public function aliveWithRole(string $role): array
    {
        $r = [];
        foreach ($this->st['players'] as $p) if ($p['alive'] && $p['role'] === $role) $r[] = $p['seat'];
        return $r;
    }
    public function isWolfRole(string $role): bool { return $role === 'werewolf' || $role === 'whiteWolf'; }
    public function wolvesAlive(): array
    {
        $r = [];
        foreach ($this->st['players'] as $p) if ($p['alive'] && $this->isWolfRole($p['role'])) $r[] = $p['seat'];
        return $r;
    }
    public function currentActor(): int { return (int)$this->st['cur']; }
    public function currentActionKind(): string { return (string)$this->st['kind']; }
    public function isGameOver(): bool { return $this->st['phase'] === 'GAME_OVER'; }

    /* ---------------- 警长竞选 ---------------- */

    public function startSheriffElection(): void
    {
        $this->st['phase'] = 'SHERIFF';
        $this->st['candidates'] = [];
        $this->nextSignup();
    }

    private function nextSignup(): void
    {
        // 只跳过"已问过"的座位：弃权（不上警）也算已问，否则会反复询问同一人造成死循环
        $asked = $this->st['signupAsked'] ?? [];
        foreach ($this->aliveSeats() as $s) {
            if (!in_array($s, $asked, true)) { $this->setStep('SHERIFF_SIGNUP', $s); return; }
        }
        if (empty($this->st['candidates'])) {
            $this->ev('NARR', 0, 0, '无人上警，本局没有警长。', 1);
            $this->nightStart();
            return;
        }
        $voters = array_diff($this->aliveSeats(), $this->st['candidates']);
        if (empty($voters)) {
            $this->ev('NARR', 0, 0, '全员上警，警徽取消。', 1);
            $this->nightStart();
            return;
        }
        $this->setStep('SHERIFF_VOTE', array_values($voters)[0]);
        $this->st['votes'] = [];
    }

    public function submitSheriffSignup(int $seat, bool $yes): void
    {
        $this->assertStep('SHERIFF_SIGNUP', $seat);
        $this->st['signupAsked'][] = $seat;
        if ($yes) {
            $this->st['candidates'][] = $seat;
            $this->ev('SHERIFF_SIGNUP', $seat, 0, '上警', 1);
        } else {
            $this->ev('SHERIFF_SIGNUP', $seat, 0, '不上警', 1);
        }
        $this->nextSignup();
    }

    public function sheriffCandidates(): array { return $this->st['candidates']; }

    public function submitSheriffVote(int $seat, int $target): void
    {
        $this->assertStep('SHERIFF_VOTE', $seat);
        if (in_array($seat, $this->st['candidates'], true)) $this->fail('候选人不能投票');
        if (!in_array($target, $this->st['candidates'], true)) $this->fail('请投票给上警的人');
        $this->st['votes'][$seat] = $target;
        $this->ev('SHERIFF_BALLOT', $seat, $target, '', 1, -2); // 狼队可见，最终结果公开
        $voters = array_diff($this->aliveSeats(), $this->st['candidates']);
        foreach ($voters as $v) if (!isset($this->st['votes'][$v])) { $this->setStep('SHERIFF_VOTE', $v); return; }
        // 开票
        $tally = [];
        foreach ($this->st['votes'] as $t) $tally[$t] = ($tally[$t] ?? 0) + 1;
        arsort($tally);
        $top = array_key_first($tally);
        $maxv = $tally[$top];
        $tops = array_keys(array_filter($tally, function ($v) use ($maxv) { return $v === $maxv; }));
        $sheriff = count($tops) > 1 ? $tops[array_rand($tops)] : (int)$top;
        $this->st['sheriff'] = $sheriff;
        $p = &$this->playerRef($sheriff);
        $p['isSheriff'] = true;
        $this->ev('SHERIFF', $sheriff, 0, $p['name'] . ' 当选警长', 1);
        $this->nightStart();
    }

    /* ---------------- 夜晚 ---------------- */

    public function nightStart(): void
    {
        $this->st['day']++;
        $this->st['phase'] = 'NIGHT';
        $this->st['night'] = ['guarded' => 0, 'wolfTarget' => 0, 'seerTarget' => 0, 'witchSaved' => false, 'poisonTarget' => 0];
        $q = [];
        if (!empty($this->st['board']['roles']['guard'])) $q[] = 'GUARD';
        if (!empty($this->st['board']['roles']['werewolf']) || !empty($this->st['board']['roles']['whiteWolf'])) $q[] = 'WOLF_KILL';
        if (!empty($this->st['board']['roles']['seer'])) $q[] = 'SEER';
        if (!empty($this->st['board']['roles']['witch'])) $q[] = 'WITCH';
        if (!empty($this->st['board']['roles']['crow'])) $q[] = 'CROW';
        if (!empty($this->st['board']['roles']['silencer'])) $q[] = 'SILENCER';
        $this->st['stepQueue'] = $q;
        $this->ev('NARR', 0, 0, ww_narr('night_fall', ['day' => $this->st['day']]), 1);
        $this->nextNightStep();
    }

    private function nextNightStep(): void
    {
        while (!empty($this->st['stepQueue'])) {
            $kind = array_shift($this->st['stepQueue']);
            $roles = ['GUARD' => 'guard', 'WOLF_KILL' => 'werewolf', 'SEER' => 'seer', 'WITCH' => 'witch', 'CROW' => 'crow', 'SILENCER' => 'silencer'];
            $role = $roles[$kind] ?? '';
            $seats = $kind === 'WOLF_KILL' ? $this->wolvesAlive() : $this->aliveWithRole($role);
            if (empty($seats)) continue; // 该神职全灭/不存在 → 跳过
            $this->setStep($kind, $seats[0]);
            return;
        }
        $this->resolveNight();
    }

    public function submitGuard(int $seat, int $target): void
    {
        $this->assertStep('GUARD', $seat);
        if ($target !== 0 && (!in_array($target, $this->aliveSeats(), true) || $target === (int)$this->st['guardLast'])) $this->fail('守卫不能连守同一人');
        $this->st['night']['guarded'] = $target;
        $this->st['guardLast'] = $target;
        $this->ev('GUARD', $seat, $target, $target ? '守护了 ' . $this->nameOf($target) : '选择空守', 0, $seat);
        $this->nextNightStep();
    }

    public function submitWolfKill(int $seat, int $target): void
    {
        $this->assertStep('WOLF_KILL', $seat);
        if (!in_array($target, $this->aliveSeats(), true) || $this->isWolfRole($this->bySeat($target)['role'])) $this->fail('狼刀只能指向存活的非狼玩家');
        $this->st['night']['wolfTarget'] = $target;
        $this->ev('WOLF_KILL', $seat, $target, '狼队决定袭击 ' . $this->nameOf($target), 0, -2);
        $this->nextNightStep();
    }

    public function submitSeer(int $seat, int $target): void
    {
        $this->assertStep('SEER', $seat);
        if (!in_array($target, $this->aliveSeats(), true) || $target === $seat) $this->fail('请验一名其他存活玩家');
        $t = $this->bySeat($target);
        $good = !$this->isWolfRole($t['role']);
        $this->st['night']['seerTarget'] = $target;
        $this->st['seerClaims'][$seat][] = ['target' => $target, 'text' => $good ? '好人' : '狼人'];
        $this->ev('SEER', $seat, $target, $this->nameOf($target) . ' 的身份是：' . ($good ? '好人' : '狼人'), 0, $seat);
        $this->nextNightStep();
    }

    /** 女巫：[解药目标(0=不用), 毒药目标(0=不用)] */
    public function submitWitch(int $seat, array $wp): void
    {
        $this->assertStep('WITCH', $seat);
        $save = (int)$wp[0];
        $poison = (int)($wp[1] ?? 0);
        $wt = (int)$this->st['night']['wolfTarget'];
        if ($save !== 0) {
            if ($this->st['witchSaveUsed']) $this->fail('解药已用完');
            if ($save !== $wt) $this->fail('解药只能救今晚被袭击的人');
            $this->st['witchSaveUsed'] = true;
            $this->st['night']['witchSaved'] = true;
            $this->ev('WITCH', $seat, $save, '使用解药救了 ' . $this->nameOf($save), 0, $seat);
        }
        if ($poison !== 0) {
            if ($this->st['witchPoisonUsed']) $this->fail('毒药已用完');
            if (!in_array($poison, $this->aliveSeats(), true) || $poison === $seat) $this->fail('毒药目标无效');
            $this->st['witchPoisonUsed'] = true;
            $this->st['night']['poisonTarget'] = $poison;
            $this->ev('WITCH', $seat, $poison, '使用毒药毒了 ' . $this->nameOf($poison), 0, $seat);
        }
        if ($save === 0 && $poison === 0) $this->ev('WITCH', $seat, 0, '今晚空过', 0, $seat);
        $this->nextNightStep();
    }

    public function submitCrow(int $seat, int $target): void
    {
        $this->assertStep('CROW', $seat);
        if (!in_array($target, $this->aliveSeats(), true) || $target === $seat) $this->fail('乌鸦要抹黑一名其他存活玩家');
        $t = &$this->playerRef($target);
        $t['crow']++;
        $this->ev('CROW', $seat, $target, '抹黑了 ' . $this->nameOf($target), 0, $seat);
        $this->nextNightStep();
    }

    private function resolveNight(): void
    {
        $n = $this->st['night'];
        $dead = [];
        $wt = (int)$n['wolfTarget'];
        if ($wt && !$n['witchSaved'] && $wt !== (int)$n['guarded']) $dead[] = $wt;
        $pt = (int)$n['poisonTarget'];
        if ($pt && !in_array($pt, $dead, true)) $dead[] = $pt;
        $this->st['pendingShot'] = null;
        foreach ($dead as $s) {
            $p = &$this->playerRef($s);
            $p['alive'] = false;
            $p['revealed'] = true;
            if ($p['role'] === 'hunter' && $s !== $pt) $this->st['pendingShot'] = ['seat' => $s, 'cause' => 'night'];
        }
        unset($p); // 解除引用绑定，防止后续值赋值写穿进玩家槽位
        $this->st['lastQueue'] = $dead;
        $this->st['lastSource'] = 'night';
        $this->ev('DAWN', 0, 0, ww_narr('dawn', ['day' => $this->st['day'], 'dead' => $dead, 'names' => $this->namesOf($dead)]), 1);
        foreach ($dead as $s) {
            $dp = $this->bySeat($s);
            $this->ev('DIE', $s, $s, ($dp ? $dp['name'] : $s . '号') . ' 出局，翻牌：【' . self::ROLE_NAMES[$dp ? $dp['role'] : 'villager'] . '】', 1);
        }
        if (!$this->checkWin()) $this->dayStart();
    }

    /* ---------------- 白天 ---------------- */

    private function dayStart(): void
    {
        $this->st['phase'] = 'DAY';
        $this->st['votes'] = [];
        $this->st['pk'] = ['list' => [], 'round' => 0];
        if (!empty($this->st['lastQueue'])) {
            $q = array_values($this->st['lastQueue']);
            $this->st['lastQueue'] = $q;
            $this->setStep('LAST_WORDS', $q[0]);
        } else {
            $this->beginSpeeches();
        }
    }

    public function submitLastWords(int $seat, string $line): void
    {
        $this->assertStep('LAST_WORDS', $seat);
        $this->ev('LAST_WORDS', $seat, 0, $line, 1);
        $idx = array_search($seat, $this->st['lastQueue'], true);
        if ($idx !== false) array_splice($this->st['lastQueue'], $idx, 1);
        if (!empty($this->st['lastQueue'])) {
            $this->setStep('LAST_WORDS', $this->st['lastQueue'][0]);
        } elseif ($this->st['pendingShot'] && $this->bySeat($this->st['pendingShot']['seat']) && $this->st['pendingShot']['seat'] === $seat) {
            $this->setStep('SHOOT', $seat);
        } elseif (($this->st['lastSource'] ?? 'night') === 'exile') {
            // 放逐流程结束 → 直接入夜
            $this->checkWin();
            if (!$this->isGameOver()) $this->nightStart();
        } else {
            $this->afterDeaths();
        }
    }

    private function afterDeaths(): void
    {
        if ($this->st['pendingShot']) {
            $s = $this->st['pendingShot']['seat'];
            if ($this->bySeat($s) && !$this->bySeat($s)['alive']) { $this->setStep('SHOOT', $s); return; }
            $this->st['pendingShot'] = null;
        }
        $this->checkWin();
        if (!$this->isGameOver()) $this->beginSpeeches();
    }

    public function submitShoot(int $seat, int $target): void
    {
        $this->assertStep('SHOOT', $seat);
        if (!in_array($target, $this->aliveSeats(), true)) $this->fail('开枪目标无效');
        $p = &$this->playerRef($target);
        $p['alive'] = false;
        $p['revealed'] = true;
        $this->st['pendingShot'] = null;
        $this->ev('SHOOT', $seat, $target, $this->nameOf($seat) . ' 开枪带走了 ' . $this->nameOf($target) . '，翻牌：【' . self::ROLE_NAMES[$p['role']] . '】', 1);
        $this->checkWin();
        if (!$this->isGameOver()) {
            if (($this->st['lastSource'] ?? 'night') === 'exile') $this->nightStart();
            else $this->beginSpeeches();
        }
    }

    private function beginSpeeches(): void
    {
        $alive = $this->aliveSeats();
        if (empty($alive)) { $this->checkWin(); return; }
        $start = 1;
        if ($this->st['sheriff'] && $this->bySeat($this->st['sheriff']) && $this->bySeat($this->st['sheriff'])['alive']) $start = (int)$this->st['sheriff'];
        $order = [];
        foreach ($alive as $s) if ($s >= $start) $order[] = $s;
        foreach ($alive as $s) if ($s < $start) $order[] = $s;
        $this->st['speech'] = ['order' => $order, 'idx' => 0];
        $this->ev('NARR', 0, 0, ww_narr('day_discuss', ['day' => $this->st['day']]), 1);
        $this->setSpeechSeat();
    }

    private function setSpeechSeat(): void
    {
        $sp = $this->st['speech'];
        while ($sp['idx'] < count($sp['order'])) {
            $seat = $sp['order'][$sp['idx']];
            $p = $this->bySeat($seat);
            if ($p && $p['alive']) {
                if ($p['silencedUntil'] >= $this->st['day']) {
                    $this->ev('SILENCED', $seat, 0, $p['name'] . ' 被禁言，跳过发言', 1);
                    $sp['idx']++;
                    continue;
                }
                $this->setStep('SPEECH', $seat);
                $this->st['speech'] = $sp;
                return;
            }
            $sp['idx']++;
        }
        $this->st['speech'] = $sp;
        $this->beginVote();
    }

    public function submitSpeech(int $seat, string $line): void
    {
        $this->assertStep('SPEECH', $seat);
        $line = trim(mb_substr($line, 0, 200));
        if ($line === '') $line = '（过）';
        $this->st['spoken'][$seat] = (int)($this->st['spoken'][$seat] ?? 0) + 1;
        $this->st['lastLine'][$seat] = $line;
        $this->ev('SPEECH', $seat, 0, $line, 1);
        $this->st['speech']['idx']++;
        $this->setSpeechSeat();
    }

    /** 白狼王自爆：白天发言阶段可自爆带走一人，之后直接入夜。 */
    public function canBlowUp(int $seat): bool
    {
        $p = $this->bySeat($seat);
        return $p && $p['alive'] && $p['role'] === 'whiteWolf' && $this->st['phase'] === 'DAY' && $this->st['kind'] === 'SPEECH' && $this->st['cur'] === $seat;
    }

    public function submitBlowUp(int $seat, int $target): void
    {
        if (!$this->canBlowUp($seat)) $this->fail('现在不能自爆');
        if (!in_array($target, $this->aliveSeats(), true) || $target === $seat) $this->fail('自爆目标无效');
        $me = &$this->playerRef($seat);
        $me['alive'] = false;
        $me['revealed'] = true;
        $t = &$this->playerRef($target);
        $t['alive'] = false;
        $t['revealed'] = true;
        $this->ev('BLOWUP', $seat, $target, ww_narr('blowup', ['name' => $me['name'], 'target' => $t['name'], 'role' => self::ROLE_NAMES[$t['role']]]), 1);
        $this->checkWin();
        if (!$this->isGameOver()) $this->nightStart();
    }

    /* ---------------- 投票 / 放逐 ---------------- */

    public function beginVote(): void
    {
        $this->st['votes'] = [];
        $this->setStep('VOTE', $this->aliveSeats()[0]);
        $this->ev('NARR', 0, 0, ww_narr('vote_begin', ['day' => $this->st['day']]), 1);
    }

    public function submitVote(int $seat, int $target): void
    {
        $this->assertStep('VOTE', $seat);
        if (!in_array($target, $this->aliveSeats(), true) || $target === $seat) $this->fail('请投给其他存活玩家');
        $this->st['votes'][$seat] = $target;
        $alive = $this->aliveSeats();
        foreach ($alive as $v) {
            if (!isset($this->st['votes'][$v])) { $this->setStep('VOTE', $v); return; }
        }
        $this->resolveVotes($this->st['votes'], 1);
    }

    private function resolveVotes(array $votes, int $round): void
    {
        $tally = [];
        foreach ($votes as $t) $tally[$t] = ($tally[$t] ?? 0) + 1;
        arsort($tally);
        $maxv = $tally ? reset($tally) : 0;
        $tops = [];
        foreach ($tally as $t => $v) if ($v === $maxv) $tops[] = (int)$t;
        $detail = [];
        foreach ($votes as $s => $t) $detail[] = $this->nameOf((int)$s) . ' → ' . $this->nameOf((int)$t);
        $this->ev('VOTE_RESULT', 0, 0, implode('，', $detail), 1);
        if (count($tops) > 1 && $round === 1 && count($tops) <= 3) {
            // 平票 PK
            $this->st['pk'] = ['list' => $tops, 'round' => 1];
            $this->st['votes'] = [];
            $this->ev('NARR', 0, 0, ww_narr('pk_begin', ['names' => $this->namesOf($tops)]), 1);
            $this->setStep('PK_SPEECH', $tops[0]);
            return;
        }
        if (count($tops) > 1) {
            $this->ev('NARR', 0, 0, '二次平票，本轮无人放逐。', 1);
            $this->nightStart();
            return;
        }
        $this->exile((int)$tops[0]);
    }

    public function submitTiebreakSpeech(int $seat, string $line): void
    {
        $this->assertStep('PK_SPEECH', $seat);
        $this->ev('SPEECH', $seat, 0, '[PK] ' . trim(mb_substr($line, 0, 200)), 1);
        $list = $this->st['pk']['list'];
        $idx = array_search($seat, $list, true);
        if ($idx !== false && $idx + 1 < count($list)) {
            $this->setStep('PK_SPEECH', $list[$idx + 1]);
        } else {
            $this->st['votes'] = [];
            $voters = array_values(array_diff($this->aliveSeats(), $list));
            if (empty($voters)) $voters = $this->aliveSeats();
            $this->setStep('PK_VOTE', $voters[0]);
        }
    }

    public function submitTiebreakVote(int $seat, int $target): void
    {
        $this->assertStep('PK_VOTE', $seat);
        if (!in_array($target, $this->st['pk']['list'], true)) $this->fail('请投给 PK 台上的人');
        $this->st['votes'][$seat] = $target;
        $voters = array_values(array_diff($this->aliveSeats(), $this->st['pk']['list']));
        if (empty($voters)) $voters = $this->aliveSeats();
        foreach ($voters as $v) if (!isset($this->st['votes'][$v])) { $this->setStep('PK_VOTE', $v); return; }
        $round = (int)$this->st['pk']['round'] + 1;
        $this->resolveVotes($this->st['votes'], $round);
    }

    private function exile(int $seat): void
    {
        $p = &$this->playerRef($seat);   // 必须走引用：值拷贝会让"放逐"不生效
        $p['alive'] = false;
        $p['revealed'] = true;
        $this->ev('EXILE', $seat, $seat, ww_narr('exile', ['name' => $p['name'], 'role' => self::ROLE_NAMES[$p['role']], 'day' => $this->st['day']]), 1);
        $this->st['lastSource'] = 'exile';
        if ($p['role'] === 'hunter') {
            $this->st['pendingShot'] = ['seat' => $seat, 'cause' => 'exile'];
            $this->setStep('SHOOT', $seat);
            return;
        }
        $this->st['lastQueue'] = [$seat];
        $this->setStep('LAST_WORDS', $seat);
    }

    /* ---------------- 禁言（禁言长老夜间行动，禁掉当日发言） ---------------- */

    public function submitSilencer(int $seat, int $target): void
    {
        $this->assertStep('SILENCER', $seat);
        if (!in_array($target, $this->aliveSeats(), true) || $target === $seat) $this->fail('禁言目标无效');
        $t = &$this->playerRef($target);
        $t['silencedUntil'] = $this->st['day'];
        $this->ev('SILENCER', $seat, $target, $this->nameOf($seat) . ' 禁言了 ' . $this->nameOf($target) . '，本轮无法发言', 0, $seat);
        $this->nextNightStep();
    }

    /* ---------------- 胜负 ---------------- */

    public function checkWin(): bool
    {
        $wolves = count($this->wolvesAlive());
        $goods = count($this->aliveSeats()) - $wolves;
        if ($wolves === 0) return $this->gameOver('good');
        if ($wolves >= $goods) return $this->gameOver('wolf');
        return false;
    }

    private function gameOver(string $side): bool
    {
        $this->st['phase'] = 'GAME_OVER';
        $this->st['winner'] = $side;
        $this->ev('GAME_OVER', 0, 0, ww_narr('game_over', ['side' => $side]), 1);
        return true;
    }

    /* ---------------- 视图 ---------------- */

    /** @param int $seat 0 = 观战视角  @param array|null $allEvents 持久化事件（DB），缺省用本次新增缓冲 */
    public function viewFor(int $seat, ?array $allEvents = null): array
    {
        $me = $seat ? $this->bySeat($seat) : null;
        $isWolf = $me && $this->isWolfRole($me['role']);
        $seats = [];
        foreach ($this->st['players'] as $p) {
            $row = [
                'seat' => $p['seat'], 'name' => $p['name'], 'ai' => $p['ai'], 'alive' => $p['alive'],
                'isSheriff' => $p['isSheriff'],
            ];
            if ($p['revealed'] || $me === $p || ($isWolf && $this->isWolfRole($p['role']))) $row['role'] = self::ROLE_NAMES[$p['role']];
            else $row['role'] = '';
            if ($this->isGameOver()) $row['role'] = self::ROLE_NAMES[$p['role']];
            $seats[] = $row;
        }
        $src = $allEvents !== null ? $allEvents : $this->evBuf;
        $events = [];
        foreach ($src as $e) {
            $to = isset($e['to']) ? (int)$e['to'] : 0;
            if ((int)$e['public'] === 1 || ($to === $seat && $seat) || ($to === -2 && $isWolf)) $events[] = $e;
        }
        $my = null;
        if ($me) {
            $my = ['role' => self::ROLE_NAMES[$me['role']], 'roleKey' => $me['role']];
            if ($me['role'] === 'witch') $my['potions'] = ['save' => !$this->st['witchSaveUsed'], 'poison' => !$this->st['witchPoisonUsed']];
            if ($me['role'] === 'witch' && $this->st['kind'] === 'WITCH') $my['attacked'] = (int)$this->st['night']['wolfTarget'];
            if ($me['role'] === 'guard') $my['guardLast'] = (int)$this->st['guardLast'];
            if ($me['role'] === 'seer') {
                $seen = [];
                foreach ($src as $e) if ($e['type'] === 'SEER' && (int)$e['actor'] === $seat) $seen[] = ['target' => (int)$e['target'], 'text' => $e['detail']];
                $my['seen'] = $seen;
            }
        }
        return [
            'day' => $this->st['day'], 'phase' => $this->st['phase'],
            'kind' => $this->st['kind'], 'cur' => $this->st['cur'],
            'seats' => $seats, 'events' => $events, 'my' => $my,
            'winner' => $this->st['winner'],
            'voted' => count($this->st['votes']),
            'pkList' => array_map('intval', $this->st['pk']['list']),
            'sheriffCandidates' => $this->st['candidates'],
            'canBlowUp' => $seat ? $this->canBlowUp($seat) : false,
        ];
    }

    /* ---------------- 内部 ---------------- */

    private function setStep(string $kind, int $seat): void
    {
        $this->st['kind'] = $kind;
        $this->st['cur'] = $seat;
    }

    private function assertStep(string $kind, int $seat): void
    {
        if ($this->st['phase'] === 'GAME_OVER') $this->fail('对局已结束');
        if ($this->st['kind'] !== $kind || (int)$this->st['cur'] !== $seat) $this->fail('现在轮不到你行动');
    }

    private function fail(string $msg): void
    {
        throw new RuntimeException($msg);
    }

    public function nameOf(int $seat): string
    {
        $p = $this->bySeat($seat);
        return $p ? $p['name'] : $seat . '号';
    }

    private function namesOf(array $seats): string
    {
        if (empty($seats)) return '无人出局';
        $n = [];
        foreach ($seats as $s) $n[] = $this->nameOf((int)$s);
        return implode('、', $n);
    }

    public function roleNames(): array { return self::ROLE_NAMES; }
}
