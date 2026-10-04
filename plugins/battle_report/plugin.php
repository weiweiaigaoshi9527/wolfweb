<?php
/**
 * 插件：battle_report —— 对局结束后自动发论坛战报。
 */
function wwpl_battle_report_on_game_over(array $args)
{
    if (!($args['g'] instanceof Game)) return null;
    $g = $args['g'];

    $side = $args['winner'] === 'good' ? '🌅 好人阵营' : '🐺 狼人阵营';
    $lines = [];
    foreach ($g->st['players'] as $p) {
        $lines[] = ($p['alive'] ? '🙂' : '💀') . ' ' . $p['seat'] . '号 ' . $p['name'] . '（' . Game::ROLE_NAMES[$p['role']] . '）';
    }
    $title = '【战报】第 ' . $g->st['day'] . ' 天 · ' . $side . '胜利';
    $text = "最终身份底牌：\n" . implode("\n", $lines) . "\n\n——本战报由 battle_report 插件自动生成";
    q('INSERT INTO forum_posts(user_id,title,text,created) VALUES(0,?,?,?)', [$title, $text, time()]);
    return null;
}
