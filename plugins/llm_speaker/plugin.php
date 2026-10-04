<?php
/**
 * 插件：llm_speaker —— 让 AI 玩家通过大模型发言。
 * 配置：settings 表 k=llm_speaker v={"endpoint":"https://api.xxx/v1/chat/completions","key":"sk-..","model":"..."}
 * 钩子 auto_action：仅在 SPEECH/PK_SPEECH 且行动者为 AI 时接管；任何异常自动回退本地台词池。
 */
function wwpl_llm_speaker_auto_action(array $args)
{
    $act = $args['act'];
    $g = $args['g'];
    $seat = $args['seat'];
    if ($act || !($g instanceof Game)) return null;
    $kind = $g->currentActionKind();
    if ($kind !== 'SPEECH' && $kind !== 'PK_SPEECH' && $kind !== 'LAST_WORDS') return null;
    $cfg = setting('llm_speaker', []);
    if (empty($cfg['endpoint']) || empty($cfg['key'])) return null;

    $recent = array_slice($args['recent'] ?? [], 0, 8);
    $alive = $g->aliveSeats();
    $me = $g->bySeat($seat);
    $prompt = "你正在玩狼人杀。你是 {$seat} 号「{$me['name']}」，身份是 " . Game::ROLE_NAMES[$me['role']] .
        "。当前第 {$g->st['day']} 天，阶段 {$g->st['phase']}。存活座位：" . implode('、', $alive) .
        "。最近发言：\n" . implode("\n", $recent) . "\n请用 1-2 句中文口语发言，不要复述别人说过的话，不要暴露身份信息（除非你是预言家要报验人）。只输出发言内容。";

    $body = json_encode([
        'model' => $cfg['model'] ?? 'gpt-4o-mini',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => 120, 'temperature' => 0.9,
    ], JSON_UNESCAPED_UNICODE);

    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 12,
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$cfg['key']}\r\n",
        'content' => $body,
    ]]);
    $raw = @file_get_contents($cfg['endpoint'], false, $ctx);
    if (!$raw) return null;
    $j = json_decode($raw, true);
    $line = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    if ($line === '' || mb_strlen($line) > 200) return null;

    // 反雷同：与近期发言 containment ≥ 0.6 则回退本地池
    foreach ($recent as $r) {
        if (ww_similarity($line, (string)$r) >= 0.6) return null;
    }
    return ['act' => ['kind' => $kind, 'line' => $line]];
}
