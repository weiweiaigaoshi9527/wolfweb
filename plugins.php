<?php
/**
 * 插件系统：plugins/<name>/plugin.json + plugin.php
 * plugin.json: {"name":..., "version":..., "desc":..., "hooks":["auto_action","on_game_over",...]}
 * plugin.php 提供函数 wwpl_<name>_<hook>(array $args) 返回值按钩子约定合并。
 */

function ww_plugin_manifests(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $dir = WW_ROOT . '/plugins';
    foreach (glob($dir . '/*/plugin.json') ?: [] as $f) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j) && !empty($j['name'])) {
            $j['_dir'] = dirname($f);
            $cache[$j['name']] = $j;
        }
    }
    ksort($cache);
    return $cache;
}

function ww_plugin_enabled_list(): array
{
    $list = setting('plugins', []);
    return is_array($list) ? $list : [];
}

function ww_plugin_enabled(string $name): bool
{
    $list = ww_plugin_enabled_list();
    if (array_key_exists($name, $list)) return !empty($list[$name]);
    // 未登记的内置插件默认关闭，需管理员启用
    return false;
}

function ww_set_plugin_enabled(string $name, bool $on): void
{
    $list = ww_plugin_enabled_list();
    $list[$name] = $on;
    set_setting('plugins', $list);
}

/**
 * 触发钩子。约定：
 *  - auto_action: 插件可返回 ['act' => [...]] 接管托管决策（如 LLM 发言）
 *  - on_game_over / narr / state_view / admin_page: 返回值忽略或由插件自行写库
 * @return mixed 第一个非空返回值（仅 auto_action 有意义）
 */
function ww_apply_plugins(string $hook, array $args = [])
{
    $ret = null;
    foreach (ww_plugin_manifests() as $name => $m) {
        if (!ww_plugin_enabled($name)) continue;
        if (!empty($m['hooks']) && !in_array($hook, $m['hooks'], true)) continue;
        $file = $m['_dir'] . '/plugin.php';
        if (!is_file($file)) continue;
        static $loaded = [];
        if (empty($loaded[$name])) {
            require_once $file;
            $loaded[$name] = true;
        }
        $fn = 'wwpl_' . preg_replace('/[^a-z0-9_]/', '', strtolower($name)) . '_' . preg_replace('/[^a-z0-9_]/', '', $hook);
        if (function_exists($fn)) {
            $r = $fn($args);
            if ($r !== null && $ret === null) $ret = $r;
        }
    }
    return $ret;
}
