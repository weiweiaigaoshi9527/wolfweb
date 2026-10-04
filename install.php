<?php
/**
 * 安装向导（五步）：环境体检 → 数据库 → 账户管理员 → 网站设定 → 完成
 * 安装完成后自动上锁（storage/installed.lock），防止重装攻击。
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/schema.php';

ww_session_start();

if (ww_installed()) {
    $lock = json_decode((string)file_get_contents(WW_STORAGE . '/installed.lock'), true) ?: [];
    $key = $_GET['key'] ?? '';
    if (($key ?? '') === '' || $key !== ($lock['key'] ?? '')) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>已安装</title><body style="font-family:system-ui;background:#12142a;color:#dfe3ff;display:grid;place-items:center;height:100vh">';
        echo '<div style="text-align:center"><div style="font-size:44px">🔒</div><h2>本站已完成安装</h2><p>如需重装，请删除 storage/installed.lock 与 storage/config.php，或带原始安装密钥访问。</p><p><a style="color:#f5c542" href="index.php">返回首页</a></p></div>';
        exit;
    }
}

$step = (int)($_GET['step'] ?? 0);
if ($step > 0) {
    header('Content-Type: application/json; charset=utf-8');
    $in = ww_body();
    try {
        switch ($step) {
            case 1: { // 环境体检
                $exts = ['pdo' => extension_loaded('pdo'), 'pdo_sqlite' => extension_loaded('pdo_sqlite'),
                    'pdo_mysql' => extension_loaded('pdo_mysql'), 'json' => extension_loaded('json'),
                    'mbstring' => extension_loaded('mbstring'), 'openssl' => extension_loaded('openssl'),
                    'fileinfo' => extension_loaded('fileinfo'), 'curl' => extension_loaded('curl')];
                if (!function_exists('ww_ext_probe')) { }
                $root = WW_ROOT;
                $writable = is_writable($root);
                if ($writable && !is_dir(WW_STORAGE)) @mkdir(WW_STORAGE, 0775, true);
                $storageWritable = is_dir(WW_STORAGE) ? is_writable(WW_STORAGE) : false;
                $checks = [
                    ['php', 'PHP 版本 ≥ 7.4（当前 ' . PHP_VERSION . '）', version_compare(PHP_VERSION, '7.4.0', '>=')],
                    ['sapi', '运行方式：' . php_sapi_name() . ' · ' . PHP_OS_FAMILY, true],
                    ['json', 'JSON 扩展', $exts['json']],
                    ['mbstring', 'mbstring 扩展（中文处理）', $exts['mbstring']],
                    ['pdo', 'PDO 扩展', $exts['pdo']],
                    ['sqlite', 'SQLite 驱动（免配置数据库）', $exts['pdo_sqlite']],
                    ['mysql', 'MySQL 驱动（虚拟主机数据库）', $exts['pdo_mysql']],
                    ['openssl', 'OpenSSL（自签证书/密码学）', $exts['openssl']],
                    ['dir', '目录可写（storage/ 自动创建）', $storageWritable],
                    ['disk', '磁盘可用空间 ≥ 20MB（' . round(disk_free_space('.') / 1048576) . 'MB）', disk_free_space('.') > 20 * 1048576],
                ];
                $ok = true;
                foreach ($checks as $c) if (!$c[2] && !in_array($c[0], ['mysql', 'curl', 'openssl'], true)) $ok = false;
                $dbOk = $exts['pdo_sqlite'] || $exts['pdo_mysql'];
                if (!$dbOk) $ok = false;
                ww_json(['ok' => 1, 'checks' => $checks, 'pass' => $ok, 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY . ' ' . php_uname('r'), 'sapi' => php_sapi_name()]);
            }
            case 2: { // 数据库
                $driver = ($in['driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
                if ($driver === 'mysql') {
                    $dsn = "mysql:host={$in['host']};port=" . ((int)($in['port'] ?? 3306)) . ";dbname={$in['dbname']};charset=utf8mb4";
                    $pdo = new PDO($dsn, (string)$in['user'], (string)$in['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                } else {
                    if (!extension_loaded('pdo_sqlite')) ww_fail('PHP 未启用 pdo_sqlite');
                    $dir = WW_STORAGE . '/data';
                    if (!is_dir($dir)) mkdir($dir, 0775, true);
                    $pdo = new PDO('sqlite:' . $dir . '/wolf.db');
                }
                $pdo->exec('SELECT 1');
                // 试建表 + 种子，成功才写配置
                $cfg = ['driver' => $driver, 'host' => (string)($in['host'] ?? ''), 'port' => (int)($in['port'] ?? 3306),
                    'dbname' => (string)($in['dbname'] ?? ''), 'user' => (string)($in['user'] ?? ''), 'pass' => (string)($in['pass'] ?? '')];
                if (!is_dir(WW_STORAGE)) mkdir(WW_STORAGE, 0775, true);
                file_put_contents(WW_STORAGE . '/config.php', "<?php\nreturn " . var_export($cfg, true) . ";\n");
                ww_create_tables();
                ww_apply_patches();
                ww_json(['ok' => 1, 'driver' => $driver]);
            }
            case 3: { // 管理员
                if (!is_file(WW_STORAGE . '/config.php')) ww_fail('请先完成数据库配置');
                $name = trim((string)($in['name'] ?? ''));
                $pass = (string)($in['pass'] ?? '');
                if (!preg_match('/^[\w\x{4e00}-\x{9fa5}]{2,16}$/u', $name)) ww_fail('昵称需 2-16 位');
                if (mb_strlen($pass) < 6) ww_fail('密码至少 6 位');
                if (qv('SELECT COUNT(*) FROM users WHERE name=?', [$name]) > 0) ww_fail('昵称已被占用');
                q('INSERT INTO users(name,pass,avatar,is_admin,created) VALUES(?,?,?,?,?)', [$name, password_hash($pass, PASSWORD_DEFAULT), '👑', 1, time()]);
                ww_session_start();
                $_SESSION['ww_uid'] = (int)db()->lastInsertId();
                ww_json(['ok' => 1]);
            }
            case 4: { // 网站设定
                if (!is_file(WW_STORAGE . '/config.php')) ww_fail('请先完成数据库配置');
                $site = ['name' => trim(mb_substr((string)($in['name'] ?? '月夜村庄'), 0, 24)) ?: '月夜村庄',
                    'theme' => (string)($in['theme'] ?? 'moonlit'),
                    'max_page_height' => max(50, min(100, (int)($in['maxPageHeight'] ?? 100))),
                    'welcome' => '月夜降临，村庄的谎言即将开始。'];
                ww_seed($site);
                $features = ['duo' => !empty($in['f_duo']), 'spectate' => !empty($in['f_spectate']), 'shop' => !empty($in['f_shop']),
                    'forum' => !empty($in['f_forum']), 'rank' => true, 'guide' => true, 'register' => !empty($in['f_register']),
                    'voiceMode' => false, 'downloads' => true];
                set_setting('features', $features);
                // 默认插件登记（默认关闭，后台可开）
                set_setting('plugins', ['llm_speaker' => false, 'voice_bridge' => false, 'battle_report' => true]);
                ww_json(['ok' => 1]);
            }
            case 5: { // 完成
                if (!is_file(WW_STORAGE . '/config.php')) ww_fail('请先完成数据库配置');
                ww_seed([]);
                $key = bin2hex(random_bytes(12));
                file_put_contents(WW_STORAGE . '/installed.lock', json_encode(['at' => date('c'), 'key' => $key], JSON_UNESCAPED_UNICODE));
                ww_json(['ok' => 1, 'key' => $key]);
            }
            default:
                ww_fail('未知步骤');
        }
    } catch (PDOException $e) {
        ww_fail('数据库连接失败：' . $e->getMessage());
    } catch (Throwable $e) {
        ww_fail($e->getMessage());
    }
}
?>
<!doctype html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>安装向导 · 狼人杀 Online 虚拟主机版</title>
<style>
:root{--bg:#12142a;--card:#1b1e3f;--line:#2c3060;--fg:#dfe3ff;--dim:#9aa0d0;--gold:#f5c542;--red:#ff6b6b;--green:#5dd39e}
*{box-sizing:border-box;margin:0}
body{font-family:system-ui,"Segoe UI","Microsoft YaHei";background:radial-gradient(1200px 800px at 70% -10%,#2a2f63,var(--bg));color:var(--fg);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:28px;width:min(720px,100%)}
h1{font-size:22px;margin-bottom:4px}
.sub{color:var(--dim);font-size:13px;margin-bottom:20px}
.steps{display:flex;gap:8px;margin-bottom:22px;flex-wrap:wrap}
.steps span{padding:6px 12px;border-radius:999px;border:1px solid var(--line);font-size:12px;color:var(--dim)}
.steps span.on{border-color:var(--gold);color:var(--gold)}
.row{display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap}
.field{flex:1;min-width:180px}
label{display:block;font-size:12px;color:var(--dim);margin-bottom:6px}
input,select{width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:#141636;color:var(--fg);font-size:14px}
.check{display:flex;align-items:center;gap:8px;margin:6px 0;font-size:13px}
.check input{width:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
td{padding:8px 6px;border-bottom:1px solid var(--line)}
.y{color:var(--green)}.n{color:var(--red)}
.btn{margin-top:18px;padding:12px 22px;border-radius:12px;border:0;background:var(--gold);color:#232333;font-weight:700;font-size:15px;cursor:pointer}
.btn.ghost{background:transparent;color:var(--dim);border:1px solid var(--line)}
.msg{margin-top:14px;font-size:13px;min-height:18px}
.msg.err{color:var(--red)}.msg.ok{color:var(--green)}
.note{font-size:12px;color:var(--dim);line-height:1.7;margin-top:10px}
a{color:var(--gold)}
</style>
</head>
<body>
<div class="card">
  <h1>🐺 狼人杀 Online · 虚拟主机版</h1>
  <div class="sub">五步完成部署：环境体检 → 数据库 → 账户与管理员 → 网站设定 → 完成</div>
  <div class="steps" id="steps">
    <span>① 环境体检</span><span>② 数据库</span><span>③ 账户管理员</span><span>④ 网站设定</span><span>⑤ 完成</span>
  </div>
  <div id="pane"></div>
  <div class="msg" id="msg"></div>
</div>
<script>
let token = '<?= $_SESSION['ww_csrf'] ?? '' ?>';
const $ = s => document.querySelector(s);
const pane = $('#pane'), msg = $('#msg');
function setStep(n){ document.querySelectorAll('#steps span').forEach((s,i)=>s.classList.toggle('on', i < n)); }
function showErr(t){ msg.className='msg err'; msg.textContent = t; }
function showOk(t){ msg.className='msg ok'; msg.textContent = t; }
async function api(step, data){
  const r = await fetch('install.php?step='+step, {method:'POST', headers:{'Content-Type':'application/json','X-WW-Token':token}, body: JSON.stringify(data||{})});
  const j = await r.json().catch(()=>({ok:0,err:'响应异常'}));
  if(j.ok) token = token; // 会话令牌不变
  return j;
}

function pane1(){
  setStep(1);
  pane.innerHTML = '<div class="note">正在检查虚拟主机环境（系统、PHP、扩展、目录权限、磁盘）……</div>';
  api(1).then(j=>{
    if(!j.ok) return showErr(j.err||'检查失败');
    pane.innerHTML = '<table>' + j.checks.map(c=>`<tr><td style="width:28px" class="${c[2]?'y':'n'}">${c[2]?'✓':'✗'}</td><td>${c[1]}</td></tr>`).join('') + '</table>'
      + '<div class="note">系统 ' + j.os + ' · SAPI ' + j.sapi + ' · PHP ' + j.php
      + (j.checks[5][2] ? '' : '') + '</div>'
      + (j.pass ? '<button class="btn" id="next">下一步：数据库</button>' : '<div class="note n">有必需项未通过：请联系虚拟主机服务商开启对应扩展，或改用支持的环境。</div>');
    if(j.pass) $('#next').onclick = pane2;
  });
}

function pane2(){
  setStep(2);
  pane.innerHTML = `
    <div class="row"><div class="field"><label>数据库类型</label>
      <select id="driver"><option value="sqlite">SQLite（零配置，推荐无数据库的虚拟主机）</option><option value="mysql">MySQL（虚拟主机数据库）</option></select></div></div>
    <div id="mysqlbox" style="display:none">
      <div class="row">
        <div class="field"><label>主机</label><input id="host" placeholder="localhost"></div>
        <div class="field"><label>端口</label><input id="port" value="3306"></div>
      </div>
      <div class="row">
        <div class="field"><label>数据库名</label><input id="dbname" placeholder="wolfweb"></div>
        <div class="field"><label>用户名</label><input id="user"></div>
      </div>
      <div class="row"><div class="field"><label>密码</label><input id="pass" type="password"></div></div>
    </div>
    <div class="note">选择 SQLite 时数据文件保存在 storage/data/wolf.db；选择 MySQL 时将自动建表与种子数据。</div>
    <button class="btn ghost" id="test">测试连接</button>
    <button class="btn" id="next">安装依赖与建表</button>`;
  $('#driver').onchange = e => { $('#mysqlbox').style.display = e.target.value==='mysql' ? '' : 'none'; };
  const cfg = () => ({driver: $('#driver').value, host: $('#host').value||'localhost', port: $('#port').value||3306, dbname: $('#dbname').value, user: $('#user').value, pass: $('#pass').value});
  $('#test').onclick = async () => { const j = await api(2, cfg()); j.ok ? showOk('连接成功 ✓') : showErr(j.err); };
  $('#next').onclick = async () => {
    const j = await api(2, cfg());
    if(!j.ok) return showErr(j.err);
    showOk('表结构、补丁与种子数据已安装 ✓');
    setTimeout(pane3, 600);
  };
}

function pane3(){
  setStep(3);
  pane.innerHTML = `
    <div class="row"><div class="field"><label>站长昵称（管理员）</label><input id="name" placeholder="村长"></div></div>
    <div class="row"><div class="field"><label>密码（≥6 位）</label><input id="pass" type="password"></div></div>
    <div class="row"><div class="field"><label>确认密码</label><input id="pass2" type="password"></div></div>
    <div class="note">该账号自动拥有管理员权限，可进入"管理后台"管理功能开关、用户、备份、更新与插件。</div>
    <button class="btn" id="next">下一步：网站设定</button>`;
  $('#next').onclick = async () => {
    if($('#pass').value !== $('#pass2').value) return showErr('两次密码不一致');
    const j = await api(3, {name: $('#name').value, pass: $('#pass').value});
    if(!j.ok) return showErr(j.err);
    showOk('管理员创建成功 ✓');
    setTimeout(pane4, 500);
  };
}

function pane4(){
  setStep(4);
  pane.innerHTML = `
    <div class="row"><div class="field"><label>站点名称</label><input id="sname" value="月夜村庄"></div>
    <div class="field"><label>默认主题</label><select id="theme">
      <option value="">月夜蓝（默认）</option><option value="sakura">樱粉浅色</option><option value="parchment">羊皮纸</option>
      <option value="light">晨光浅色</option><option value="day">白昼</option><option value="deepsea">深海</option>
      <option value="moss">幽林绿</option><option value="ember">余烬</option><option value="neon">霓虹</option>
      <option value="mono">水墨单色</option><option value="slate">岩板</option><option value="aurora">极光</option><option value="bloodmoon">血月</option>
    </select></div></div>
    <div class="row"><div class="field"><label>页面最大高度（50-100%，0/100 = 不限）</label><input id="mph" type="number" value="100" min="50" max="100"></div></div>
    <label style="margin-top:6px">功能开关（可随时在管理后台调整）</label>
    <div class="check"><input type="checkbox" id="f_duo" checked><span>双人组队</span></div>
    <div class="check"><input type="checkbox" id="f_spectate" checked><span>观战</span></div>
    <div class="check"><input type="checkbox" id="f_shop" checked><span>商城 VIP</span></div>
    <div class="check"><input type="checkbox" id="f_forum" checked><span>论坛</span></div>
    <div class="check"><input type="checkbox" id="f_register" checked><span>开放注册</span></div>
    <div class="note">HTTPS 提示：虚拟主机若已提供 SSL 证书请直接在主机面板绑定；若需自签证书且主机支持 OpenSSL，可在管理后台-更新里查看生成指引。语音同传在虚拟主机版默认关闭（可在插件系统中接入外部语音桥）。</div>
    <button class="btn" id="next">完成安装</button>`;
  $('#next').onclick = async () => {
    const j = await api(4, {name: $('#sname').value, theme: $('#theme').value, maxPageHeight: +$('#mph').value,
      f_duo: $('#f_duo').checked, f_spectate: $('#f_spectate').checked, f_shop: $('#f_shop').checked,
      f_forum: $('#f_forum').checked, f_register: $('#f_register').checked});
    if(!j.ok) return showErr(j.err);
    const j2 = await api(5, {});
    if(!j2.ok) return showErr(j2.err);
    pane5(j2.key);
  };
}

function pane5(key){
  setStep(5);
  pane.innerHTML = `
    <div style="font-size:40px;text-align:center">🌙</div>
    <h1 style="text-align:center;margin:12px 0">安装完成！</h1>
    <div class="note" style="text-align:center">站点已上锁（防重装攻击）。请妥善保管安装密钥：<br><code>${key}</code></div>
    <div style="text-align:center"><button class="btn" onclick="location.href='index.php'">进入村庄 →</button></div>`;
}

pane1();
</script>
</body>
</html>
