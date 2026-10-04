<?php
/**
 * 插件：voice_bridge —— 语音桥（虚拟主机不能常驻 Python 服务，改为外链）。
 * 配置：settings 表 k=voice_bridge v={"tts":"http://你的语音服务器:5002/tts","asr":"http://你的语音服务器:5001/transcribe"}
 * 说明：虚拟主机版默认关闭语音同传（feature.voiceMode=false）。
 * 本插件演示钩子用法：对局事件推送到外部语音服务器由其自行合成播报；
 * 浏览器端可用 <audio> 拉取 /tts?q=文本 播放，无需 WebSocket。
 */
function wwpl_voice_bridge_state_view(array $args)
{
    // 演示钩子：可在视图层附加语音配置（真实接入时在此拉取 TTS URL 下发给前端）
    return null;
}

function wwpl_voice_bridge_on_game_over(array $args)
{
    // 演示钩子：结算时向语音服务器播报（若配置了 webhook）
    $cfg = setting('voice_bridge', []);
    if (empty($cfg['tts']) || !($args['g'] instanceof Game)) return null;
    $side = $args['winner'] === 'good' ? '好人阵营' : '狼人阵营';
    $text = '对局结束，' . $side . '胜利。';
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 6, 'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE)]]);
    @file_get_contents($cfg['tts'], false, $ctx);
    return null;
}
