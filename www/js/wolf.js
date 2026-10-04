/* 狼人杀 Online · 虚拟主机版 前端逻辑（原生 JS，零框架） */
'use strict';
const $ = s => document.querySelector(s);
const $$ = s => Array.from(document.querySelectorAll(s));
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

const WW = {
  t: '', me: null, site: {}, features: {}, roomNo: '', gameNo: '',
  timers: {}, selTarget: 0,

  /* ---------- 基础 ---------- */
  async api(a, data, get) {
    const opt = get
      ? {method: 'GET'}
      : {method: 'POST', headers: {'Content-Type': 'application/json', 'X-WW-Token': this.t}, body: JSON.stringify(data || {})};
    const r = await fetch('api.php?a=' + encodeURIComponent(a) + (get ? '&' + new URLSearchParams(data || {}).toString() : ''), opt);
    const j = await r.json().catch(() => ({ok: 0, err: '网络异常'}));
    if (r.status === 401) { this.me = null; this.go('#login', true); }
    return j;
  },
  toast(msg) {
    const t = $('#toast'); t.textContent = msg; t.style.display = 'block';
    clearTimeout(this._tt); this._tt = setTimeout(() => t.style.display = 'none', 2600);
  },
  modal(html, onOpen) {
    const root = $('#modal-root');
    root.innerHTML = '<div class="modal-mask"><div class="modal">' + html + '</div></div>';
    root.querySelector('.modal-mask').addEventListener('click', e => { if (e.target === e.currentTarget) this.closeModal(); });
    if (onOpen) onOpen(root);
  },
  closeModal() { $('#modal-root').innerHTML = ''; },
  confirm(title, text, onYes) {
    this.modal('<h3>' + esc(title) + '</h3><div class="muted">' + esc(text) + '</div>' +
      '<div class="actions"><button class="btn" id="m-no">取消</button><button class="btn gold" id="m-yes">确定</button></div>');
    $('#m-no').onclick = () => this.closeModal();
    $('#m-yes').onclick = () => { this.closeModal(); onYes(); };
  },

  /* ---------- 主题 ---------- */
  THEMES: [
    ['', '月夜蓝'], ['sakura', '樱粉浅色'], ['parchment', '羊皮纸'], ['light', '晨光浅色'], ['day', '白昼'],
    ['deepsea', '深海'], ['moss', '幽林绿'], ['ember', '余烬'], ['neon', '霓虹'], ['mono', '水墨单色'],
    ['slate', '岩板'], ['aurora', '极光'], ['bloodmoon', '血月'],
  ],
  applyTheme(id) {
    document.body.className = id ? ('theme-' + id) : '';
    try { localStorage.setItem('ww_theme', id); } catch (e) {}
  },
  themeModal() {
    const cur = document.body.className.replace('theme-', '');
    this.modal('<h3>🎨 外观</h3><div class="theme-grid">' + this.THEMES.map(t =>
      '<div class="theme-cell' + (t[0] === cur ? ' on' : '') + '" data-t="' + t[0] + '">' + esc(t[1]) + '</div>').join('') +
      '<div class="actions"><button class="btn" onclick="WW.closeModal()">完成</button></div>');
    $$('#modal-root .theme-cell').forEach(c => c.onclick = () => { this.applyTheme(c.dataset.t); this.themeModal(); });
  },

  /* ---------- 路由 ---------- */
  go(hash, force) {
    if (!force && hash !== '#login' && !this.me) hash = '#login';
    if ((hash === '#room' || hash === '#game') && !this.roomNo) {
      this.toast('请先加入一个房间'); hash = '#lobby';
    }
    if (location.hash === hash && !force) return;
    location.hash = hash;
  },
  route() {
    let name = (location.hash || '#lobby').slice(1);
    if (['lobby', 'room', 'game', 'friends', 'rank', 'forum', 'shop', 'profile', 'changelog', 'admin'].indexOf(name) < 0) name = 'lobby';
    if (!this.me && name !== 'login') name = 'login';
    if ((name === 'room' || name === 'game') && !this.roomNo) { name = 'lobby'; location.hash = '#lobby'; }
    $$('.view').forEach(v => v.classList.toggle('on', v.id === 'view-' + name));
    $$('#nav .nav-item').forEach(b => b.classList.toggle('on', b.dataset.route === name));
    $('#nav-admin').classList.toggle('hidden', !(this.me && this.me.isAdmin));
    const loaders = {lobby: 'loadLobby', room: 'loadRoom', game: 'loadGame', friends: 'loadFriends', rank: 'loadRank',
      forum: 'loadForum', shop: 'loadShop', profile: 'loadProfile', changelog: 'loadChangelog', admin: 'loadAdmin'};
    this.stopPolls();
    if (this[loaders[name]]) this[loaders[name]]();
    if (name !== 'login') this.pollSocial();
  },
  stopPolls() { Object.keys(this.timers).forEach(k => { clearInterval(this.timers[k]); delete this.timers[k]; }); },
  pollEvery(key, ms, fn) { fn(); this.timers[key] = setInterval(fn, ms); },

  /* ---------- 启动 ---------- */
  async boot() {
    const j = await this.api('cfg', null, true);
    if (!j.ok) return this.toast('配置加载失败');
    this.t = j.token;
    this.version = j.version || '2.0.0';
    this.site = j.site || {};
    this.features = j.features || {};
    document.title = (this.site.name || '狼人杀') + ' · 狼人杀 Online';
    if ($('#site-welcome')) $('#site-welcome').textContent = this.site.welcome || $('#site-welcome').textContent;
    // 主题：本机偏好 > 站点默认
    let theme = null;
    try { theme = localStorage.getItem('ww_theme'); } catch (e) {}
    this.applyTheme(theme === null ? (this.site.theme || '') : theme);
    // 页面最大高度（管理员配置）
    const mph = parseInt(this.site.max_page_height, 10) || 100;
    if (mph > 0 && mph < 100) {
      const sh = $('#app-shell'); sh.classList.add('capped'); sh.style.setProperty('--mph', mph);
    }
    // 功能开关隐藏导航（白名单）
    $$('#nav [data-feat]').forEach(el => {
      const key = el.dataset.feat.split('-')[0];
      if (['rank', 'forum', 'shop'].indexOf(key) >= 0 && this.features[key] === false) el.classList.add('hidden');
    });
    this.me = j.me;
    this.renderIdentity();
    if (!location.hash) location.hash = '#lobby';
    this.route();
    window.addEventListener('hashchange', () => this.route());
    $('#btn-theme').onclick = () => this.themeModal();
    $('#btn-logout').onclick = async () => { await this.api('logout'); this.me = null; this.roomNo = ''; this.renderIdentity(); this.go('#login', true); };
  },
  renderIdentity() {
    const on = !!this.me;
    $('#userchip').classList.toggle('hidden', !on);
    $('#btn-logout').classList.toggle('hidden', !on);
    if (on) {
      $('#chip-ava').textContent = this.me.avatar || '🐺';
      $('#chip-name').textContent = this.me.name;
      $('#chip-vip').classList.toggle('hidden', !this.me.vipUntil || this.me.vipUntil * 1000 < Date.now());
    }
  },

  /* ---------- 登录 ---------- */
  authMode: 'login',
  async doAuth() {
    const name = $('#lg-name').value.trim(), pass = $('#lg-pass').value;
    if (!name || !pass) return this.toast('请输入昵称和密码');
    const j = await this.api(this.authMode, {name, pass});
    if (!j.ok) return this.toast(j.err || '失败');
    const c = await this.api('cfg', null, true);
    this.t = c.token; this.me = c.me; this.features = c.features || {};
    this.renderIdentity();
    this.toast('欢迎回来，' + this.me.name);
    this.go('#lobby', true);
  },

  /* ---------- 大厅 ---------- */
  async loadLobby() {
    this.pollEvery('lobby', 4000, async () => {
      const j = await this.api('rooms', null, true);
      if (!j.ok) return;
      const box = $('#room-list');
      if (!j.rooms.length) { box.innerHTML = '<div class="muted">暂无房间，创建一个吧</div>'; return; }
      box.innerHTML = j.rooms.map(r =>
        '<div class="room-card"><span class="no">' + esc(r.no) + '</span>' +
        '<div class="grow"><div>' + esc(r.name) + '</div><div class="muted">' + r.cnt + '/' + r.max + ' 人 · ' +
        (r.status === 'waiting' ? '等待中' : '对局中') + '</div></div>' +
        '<button class="btn sm gold" data-join="' + esc(r.no) + '">进入</button></div>').join('');
      box.querySelectorAll('[data-join]').forEach(b => b.onclick = () => this.joinRoom(b.dataset.join));
    });
  },
  async createRoom() {
    const boards = await this.api('cfg', null, true); // 占位：板子来自 settings，简化为序号选择
    this.modal('<h3>创建房间</h3><div class="field"><label>房间名</label><input type="text" id="cr-name" placeholder="今晚吃谁"></div>' +
      '<div class="field"><label>板子</label><select id="cr-board"><option value="0">标准 9 人</option><option value="1">进阶 10 人</option><option value="2">暗夜 12 人</option></select></div>' +
      '<div class="actions"><button class="btn" onclick="WW.closeModal()">取消</button><button class="btn gold" id="cr-ok">创建</button></div>');
    $('#cr-ok').onclick = async () => {
      const j = await this.api('create', {name: $('#cr-name').value, board: +$('#cr-board').value});
      if (!j.ok) return this.toast(j.err);
      this.closeModal(); this.joinRoomGo(j.no);
    };
  },
  async joinRoom(no) {
    const j = await this.api('join', {no});
    if (!j.ok) return this.toast(j.err || '加入失败');
    this.joinRoomGo(j.no);
  },
  joinRoomGo(no) { this.roomNo = no; this.gameNo = no; this.go('#room', true); },
  async quickStart() {
    const j = await this.api('quick');
    if (!j.ok) return this.toast(j.err);
    this.joinRoomGo(j.no);
  },

  /* ---------- 房间 ---------- */
  async loadRoom() {
    this.pollEvery('room', 2500, async () => {
      const j = await this.api('room_state', {no: this.roomNo}, true);
      if (!j.ok) { this.toast(j.err || '房间不存在'); this.roomNo = ''; return this.go('#lobby', true); }
      const r = j.room;
      $('#room-title').textContent = '房间 ' + r.no + ' · ' + r.name;
      const b = r.board || {};
      const roleTxt = Object.entries(b.roles || {}).map(([k, n]) => (GameRoleNames[k] || k) + '×' + n).join('，');
      $('#room-sub').textContent = (b.name || '') + ' · ' + roleTxt + (b.sheriff ? ' · 有警长' : '');
      $('#board-info').textContent = '真人先入座，开局时系统自动补 AI 并随机分座。房间号 ' + r.no + ' 就是邀请码，把它发给朋友即可加入。';
      $('#btn-start').classList.toggle('hidden', !r.isOwner);
      $('#btn-addai').classList.toggle('hidden', !r.isOwner);
      $('#seats').innerHTML = r.players.map(p =>
        '<div class="seat' + (p.isOwner ? ' owner' : '') + (p.name === (this.me && this.me.name) ? ' me' : '') + '">' +
        '<span class="no">' + p.seat + '号</span><div class="face">' + esc(p.avatar || (p.ai ? '🤖' : '🐺')) + '</div>' +
        '<div class="nm">' + esc(p.name) + '</div><div class="st">' +
        (p.spectate ? '<span class="tag">观战</span>' : (p.ready ? '<span class="tag green">已准备</span>' : '<span class="tag">未准备</span>')) +
        (r.isOwner && !p.ai && !p.isOwner ? ' <button class="btn sm danger" data-kick="' + p.seat + '">移出</button>' : '') +
        '</div></div>').join('');
      $('#seats').querySelectorAll('[data-kick]').forEach(b => b.onclick = () => this.api('kick', {seat: +b.dataset.kick}));
      if (r.status === 'playing') { this.gameNo = r.no; return this.go('#game', true); }
    });
  },
  async leaveRoom() {
    this.confirm('离开房间', '对局中离开将转为 AI 托管，确定离开？', async () => {
      await this.api('leave');
      this.roomNo = ''; this.go('#lobby', true);
    });
  },

  /* ---------- 对局 ---------- */
  async loadGame() {
    this.pollEvery('game', 2000, async () => {
      const j = await this.api('gstate', {no: this.gameNo}, true);
      if (!j.ok) return this.toast(j.err || '');
      if (!j.game) { $('#g-phase').textContent = '尚未开局'; return; }
      this.renderGame(j.game, j.you);
    });
  },
  renderGame(g, you) {
    const phaseTxt = {NIGHT: '黑夜', DAY: '白天', SHERIFF: '警长竞选', GAME_OVER: '结算', INIT: '准备'}[g.phase] || g.phase;
    $('#g-phase').textContent = phaseTxt;
    $('#g-day').textContent = g.day ? ('第 ' + g.day + ' 天') : '';
    // 计时
    const tm = $('#g-timer');
    if (g.phase !== 'GAME_OVER' && g.deadlineAt) {
      const left = Math.max(0, Math.round((g.deadlineAt - g.nowMs) / 1000));
      tm.textContent = '⏳ ' + Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
      tm.classList.toggle('hot', left <= 10);
    } else { tm.textContent = ''; }
    // 结算
    const wl = $('#g-winline');
    if (g.phase === 'GAME_OVER' && g.winner) {
      wl.innerHTML = '<div class="winline ' + (g.winner === 'good' ? 'good' : 'wolf') + '">' +
        (g.winner === 'good' ? '🌅 好人阵营胜利' : '🐺 狼人阵营胜利') + '</div>';
    } else wl.innerHTML = '';
    // 事件流
    const feed = $('#g-feed');
    const oldTop = feed.scrollHeight - feed.scrollTop <= feed.clientHeight + 40;
    feed.innerHTML = g.events.slice(-80).map(e => {
      const cls = e.type === 'NARR' ? 'narr' : (['SPEECH', 'LAST_WORDS', 'PK_SPEECH'].indexOf(e.type) >= 0 ? '' : 'sys');
      const seatNo = e.actor ? '<span class="seatno">' + e.actor + '号</span>' : '';
      return '<div class="fitem ' + cls + '">' + seatNo + esc(e.detail) + '</div>';
    }).join('');
    if (oldTop) feed.scrollTop = feed.scrollHeight;
    // 座位
    $('#g-seats').innerHTML = g.seats.map(s =>
      '<div class="seat gseat' + (s.alive ? '' : ' dead') + (WW.selTarget === s.seat ? ' sel' : '') + '" data-seat="' + s.seat + '">' +
      '<span class="no">' + s.seat + '号</span><div class="face">' + (s.alive ? '🙂' : '💀') + '</div>' +
      '<div class="nm">' + esc(s.name) + (s.isSheriff ? ' 👮' : '') + '</div>' +
      '<div class="st">' + (s.role ? '<span class="tag ' + (s.role.indexOf('狼') >= 0 ? 'red' : 'green') + '">' + esc(s.role) + '</span>' : '<span class="tag">？</span>') + '</div></div>').join('');
    $$('#g-seats .gseat').forEach(el => el.onclick = () => { WW.selTarget = +el.dataset.seat; WW.renderTargets(g); });
    // 我的身份
    const mr = $('#g-myrole');
    if (you.spectate || !g.my) mr.innerHTML = '<span class="muted">👁 观战视角 · 不泄露任何底牌</span>';
    else {
      let extra = '';
      if (g.my.potions) extra = '<span class="tag' + (g.my.potions.save ? ' green' : '') + '">解药' + (g.my.potions.save ? '可用' : '已用') + '</span> <span class="tag' + (g.my.potions.poison ? ' green' : '') + '">毒药' + (g.my.potions.poison ? '可用' : '已用') + '</span>';
      if (g.my.seen && g.my.seen.length) extra += g.my.seen.map(s => '<span class="tag">' + s.target + '号=' + esc(s.text) + '</span>').join(' ');
      mr.innerHTML = '<div><div class="muted">你的身份</div><div class="role-name">' + esc(g.my.role) + '</div></div><div class="grow"></div><div>' + extra + '</div>';
    }
    this.renderTargets(g);
  },
  renderTargets(g) {
    // 重绘选中态
    $$('#g-seats .gseat').forEach(el => el.classList.toggle('sel', +el.dataset.seat === WW.selTarget));
    const box = $('#g-act');
    const isMe = g.cur && g.my && !youSpectate(g) && g.kind && g.cur === youSeat(g);
    const alive = g.seats.filter(s => s.alive && s.seat !== youSeat(g));
    let html = '';
    if (g.phase === 'GAME_OVER') {
      html = '<button class="btn" onclick="WW.go(\'#lobby\',true)">返回大厅</button>';
    } else if (isMe) {
      const btn = (label, data, cls) => '<button class="btn sm ' + (cls || '') + '" data-act=\'' + JSON.stringify(data).replace(/'/g, '&#39;') + '\'>' + label + '</button>';
      const seatBtns = (extra) => alive.map(s => '<button class="btn sm' + (WW.selTarget === s.seat ? ' gold' : '') + '" data-t="' + s.seat + '">' + s.seat + '号 ' + esc(s.name) + '</button>').join(' ') + (extra || '');
      switch (g.kind) {
        case 'SHERIFF_SIGNUP': html = '警长竞选：' + btn('上警', {kind: 'SHERIFF_SIGNUP', yes: 1}, 'gold') + btn('不上警', {kind: 'SHERIFF_SIGNUP', yes: 0}); break;
        case 'SHERIFF_VOTE': html = '投票选警长：' + seatBtns(); break;
        case 'SPEECH':
        case 'PK_SPEECH':
          html = '<textarea id="act-line" placeholder="说出你的推理…（留空=过）"></textarea><div class="row" style="margin-top:8px">' +
            btn('发言', {kind: g.kind, line: '__LINE__'}, 'gold') +
            (g.canBlowUp ? btn('💥 自爆', {kind: '__BLOWUP__'}, 'danger') : '') + '</div>'; break;
        case 'VOTE': case 'PK_VOTE': html = (g.kind === 'VOTE' ? '投票放逐：' : 'PK 投票：') + seatBtns() + '<span class="muted" style="margin-left:8px">已投 ' + g.voted + ' 票</span>'; break;
        case 'GUARD': html = '守护一人（可空守，不能连守）：' + seatBtns() + btn('空守', {kind: 'GUARD', target: 0}); break;
        case 'WOLF_KILL': html = '选择今晚的猎物：' + seatBtns(); break;
        case 'SEER': html = '查验一名玩家：' + seatBtns(); break;
        case 'CROW': html = '抹黑一名玩家（投票加权）：' + seatBtns(); break;
        case 'SILENCER': html = '🤐 禁言一名玩家（本轮无法发言）：' + seatBtns(); break;
        case 'WITCH': html = '女巫行动：' + btn('用解药救 ' + ((g.my && g.my.attacked) ? g.my.attacked + '号' : ''), {kind: 'WITCH', save: '__WT__', poison: 0}, 'gold') + ' ' +
          '<span class="muted">或点座位选毒药目标：</span>' + seatBtns() + btn('空过', {kind: 'WITCH', save: 0, poison: 0}); break;
        case 'LAST_WORDS': html = '遗言：<textarea id="act-line"></textarea><div class="row" style="margin-top:8px">' + btn('留下遗言', {kind: 'LAST_WORDS', line: '__LINE__'}, 'gold') + '</div>'; break;
        case 'SHOOT': html = '开枪带走一人：' + seatBtns(); break;
        default: html = '<span class="muted">等待行动…</span>';
      }
    } else {
      html = '<span class="muted">' + (g.cur ? g.cur + '号正在行动（' + (ActionNames[g.kind] || g.kind) + '）…' : '系统推进中…') + '</span>';
    }
    box.innerHTML = html;
    // 绑定
    box.querySelectorAll('[data-act]').forEach(b => b.onclick = () => {
      const d = JSON.parse(b.dataset.act.replace(/&#39;/g, "'"));
      if (d.line === '__LINE__') d.line = ($('#act-line') || {}).value || '';
      if (d.save === '__WT__') d.save = (g.my && g.my.attacked) || 0;
      if (d.kind === '__BLOWUP__') { this.blowupFlow(); return; }
      this.sendAct(d);
    });
    box.querySelectorAll('[data-t]').forEach(b => b.onclick = () => {
      const t = +b.dataset.t;
      const kindBtn = box.querySelector('[data-act]');
      const d = kindBtn ? JSON.parse(kindBtn.dataset.act.replace(/&#39;/g, "'")) : null;
      if (d && d.save === '__WT__') { this.sendAct({kind: 'WITCH', save: 0, poison: t}); return; } // 选了毒药目标
      const kind = g.kind;
      this.sendAct({kind, target: t});
    });
  },
  blowupFlow() {
    this.modal('<h3>💥 白狼王自爆</h3><div class="muted">选择要带走的目标，自爆后直接入夜。</div><div class="targets" style="margin-top:12px" id="bl-targets"></div>' +
      '<div class="actions"><button class="btn" onclick="WW.closeModal()">取消</button></div>');
    this.api('gstate', {no: this.gameNo}, true).then(j => {
      if (!j.ok || !j.game) return;
      $('#bl-targets').innerHTML = j.game.seats.filter(s => s.alive && s.seat !== youSeat(j.game)).map(s =>
        '<button class="btn sm" data-t="' + s.seat + '">' + s.seat + '号 ' + esc(s.name) + '</button>').join(' ');
      $$('#bl-targets [data-t]').forEach(b => b.onclick = async () => {
        this.closeModal();
        await this.sendAct({kind: 'BLOWUP', target: +b.dataset.t});
      });
    });
  },
  async sendAct(d) {
    const j = await this.api('act', Object.assign({no: this.gameNo}, d));
    if (!j.ok) { this.toast(j.err || '动作失败'); return; }
    this.selTarget = 0;
    this.renderGame(j.game, {seat: youSeat(j.game), spectate: false});
  },

  /* ---------- 社交 ---------- */
  chatWith: 0,
  pollSocial() {
    this.pollEvery('social', 8000, async () => {
      const j = await this.api('unread_total', null, true);
      if (!j.ok) return;
      const dot = $('#friends-dot');
      const n = (j.unread || 0) + (j.duo || 0);
      dot.textContent = n > 99 ? '99+' : n;
      dot.classList.toggle('hidden', n === 0);
      if (j.duo > 0 && !$('#modal-root').firstChild) this.duoPopup();
    });
  },
  async duoPopup() {
    const j = await this.api('duo_pending', null, true);
    if (!j.ok || !j.invites.length) return;
    const inv = j.invites[0];
    this.modal('<h3>👫 组队邀请</h3><div>' + esc(inv.from) + ' 邀请你加入房间 <b>' + esc(inv.roomNo || '(未建房)') + '</b></div>' +
      '<div class="actions"><button class="btn" id="duo-no">拒绝</button><button class="btn gold" id="duo-yes">接受</button></div>');
    $('#duo-no').onclick = async () => { await this.api('duo_decline', {id: inv.id}); this.closeModal(); };
    $('#duo-yes').onclick = async () => {
      const r = await this.api('duo_accept', {id: inv.id});
      this.closeModal();
      if (r.ok && r.no) this.joinRoomGo(r.no);
    };
  },
  async loadFriends() {
    const j = await this.api('friends', null, true);
    if (!j.ok) return;
    $('#friend-list').innerHTML = j.friends.length ? j.friends.map(f =>
      '<div class="friend-row" data-id="' + f.id + '"><span style="font-size:22px">' + esc(f.avatar) + '</span>' +
      '<div class="grow"><div>' + esc(f.name) + (f.vip ? ' <span class="vip-tag">VIP</span>' : '') + '</div></div>' +
      (f.unread ? '<span class="dot">' + f.unread + '</span>' : '') +
      '<button class="btn sm danger" data-del="' + f.id + '">删除</button></div>').join('')
      : '<div class="muted">还没有好友，用上方输入框添加</div>';
    $$('#friend-list .friend-row').forEach(el => el.onclick = e => {
      if (e.target.dataset.del) return;
      this.chatWith = +el.dataset.id;
      const name = el.querySelector('div > div').textContent.trim();
      $('#chat-with').textContent = '与 ' + name + ' 私聊';
      this.pollChat();
    });
    $$('#friend-list [data-del]').forEach(b => b.onclick = () => this.api('friend_del', {id: +b.dataset.del}).then(() => this.loadFriends()));
    if (!this.timers.chat) this.pollChat(true);
  },
  pollChat(quiet) {
    const load = async () => {
      if (!this.chatWith) return;
      const j = await this.api('fmsg', {with: this.chatWith}, true);
      if (!j.ok) return;
      $('#chat-box').innerHTML = j.msgs.map(m =>
        '<div class="bub' + (m.from === this.me.id ? ' me' : '') + '"><b>' + esc(m.fromName) + '：</b>' + esc(m.text) + '</div>').join('') || '<div class="muted">暂无消息</div>';
      const cb = $('#chat-box'); cb.scrollTop = cb.scrollHeight;
    };
    load();
    if (!quiet) { clearInterval(this.timers.chat); this.timers.chat = setInterval(load, 4000); }
  },

  /* ---------- 其他页面 ---------- */
  async loadRank() {
    const j = await this.api('rank', null, true);
    if (!j.ok) return;
    $('#rank-box').innerHTML = '<table class="tbl"><tr><th>名次</th><th>玩家</th><th>场次</th><th>胜场</th></tr>' +
      j.rank.map(r => '<tr><td>' + r.rank + '</td><td>' + esc(r.avatar) + ' ' + esc(r.name) + (r.vip ? ' <span class="vip-tag">VIP</span>' : '') + '</td><td>' + r.games + '</td><td>' + r.wins + '</td></tr>').join('') + '</table>';
  },
  async loadForum() {
    const j = await this.api('forum_list', null, true);
    if (!j.ok) return;
    $('#forum-list').innerHTML = j.posts.map(p =>
      '<div class="card" style="margin-bottom:10px"><div class="row"><b>' + esc(p.title) + '</b><div class="grow"></div><span class="muted">' + esc(p.avatar) + ' ' + esc(p.by) + ' · ' + new Date(p.ts * 1000).toLocaleString() + '</span></div>' +
      '<div style="margin-top:8px;white-space:pre-wrap">' + esc(p.text) + '</div></div>').join('') || '<div class="muted">还没有帖子</div>';
  },
  async loadShop() {
    const j = await this.api('me', null, true);
    if (!j.ok) return;
    const vip = j.me.vip;
    $('#shop-sub').innerHTML = vip ? '会员有效期至 <b>' + new Date(j.me.vipUntil * 1000).toLocaleDateString() + '</b>，续费自动顺延' : '开通 VIP 解除身份光环';
    const plans = [['month', '月卡', 30], ['season', '季卡', 90], ['year', '年卡', 365]];
    $('#shop-grid').innerHTML = plans.map(p =>
      '<div class="card" style="text-align:center"><div style="font-size:34px">💎</div><h3>' + p[1] + '</h3>' +
      '<div class="muted">' + p[2] + ' 天 · 全主题 · 专属标牌</div>' +
      '<button class="btn gold" style="margin-top:10px" data-buy="' + p[0] + '">' + (vip ? '续费' : '开通') + '</button></div>').join('');
    $$('#shop-grid [data-buy]').forEach(b => b.onclick = async () => {
      const r = await this.api('vip_buy', {plan: b.dataset.buy});
      if (!r.ok) return this.toast(r.err);
      this.toast('VIP 已生效 ✓'); this.loadShop(); this.renderIdentity();
    });
  },
  async loadProfile() {
    const j = await this.api('me', null, true);
    if (!j.ok) return;
    const m = j.me;
    $('#pf-ava').textContent = m.avatar;
    $('#pf-name').textContent = m.name + (m.vip ? '  💎VIP' : '');
    $('#pf-stats').textContent = '场次 ' + m.games + ' · 胜场 ' + m.wins + (m.vip ? ' · VIP 至 ' + new Date(m.vipUntil * 1000).toLocaleDateString() : '');
    const emojis = ['🐺', '🌕', '🌙', '🦉', '🧙', '🛡', '🏹', '👑', '🦌', '🐑', '🎃', '👻', '🔥', '💎', '🎩', '🦊'];
    $('#ava-row').innerHTML = emojis.map(e =>
      '<button class="btn sm' + (e === m.avatar ? ' gold' : '') + '" data-ava="' + e + '">' + e + '</button>').join(' ');
    $$('#ava-row [data-ava]').forEach(b => b.onclick = () => {
      $('#pf-ava').textContent = b.dataset.ava;
      $$('#ava-row [data-ava]').forEach(x => x.classList.toggle('gold', x === b));
    });
    $('#pf-save').onclick = async () => {
      const r = await this.api('profile_save', {avatar: $('#pf-ava').textContent});
      if (r.ok) { this.me.avatar = $('#pf-ava').textContent; this.renderIdentity(); this.toast('头像已更新'); }
    };
  },
  async loadChangelog() {
    const j = await this.api('changelog', null, true);
    if (!j.ok) return;
    $('#ver-sub').textContent = '当前版本 v' + (this.version || '2.0.0');
    $('#log-list').innerHTML = j.logs.map(l =>
      '<div style="padding:10px 0;border-bottom:1px solid var(--border-soft)"><b class="tag gold">v' + esc(l.version) + '</b> <span class="muted">' + esc(l.date) + '</span>' +
      '<div style="margin-top:6px;white-space:pre-wrap">' + esc(l.text) + '</div></div>').join('');
  },

  /* ---------- 管理后台 ---------- */
  admTab: 'ov',
  async loadAdmin() {
    if (!(this.me && this.me.isAdmin)) { $('#adm-pane').innerHTML = '<div class="muted">需要管理员权限</div>'; return; }
    $$('#adm-tabs button').forEach(b => {
      b.classList.toggle('on', b.dataset.t === this.admTab);
      b.onclick = () => { this.admTab = b.dataset.t; this.loadAdmin(); };
    });
    const p = $('#adm-pane');
    const t = this.admTab;
    if (t === 'ov') {
      const j = await this.api('a_overview', null, true);
      if (!j.ok) { p.innerHTML = '<div class="muted">' + esc(j.err) + '</div>'; return; }
      p.innerHTML = '<div class="grid c3">' +
        [['用户', j.stats.users], ['房间', j.stats.rooms], ['进行中对局', j.stats.playing], ['帖子', j.stats.posts], ['版本', 'v' + j.version]]
          .map(s => '<div class="card" style="text-align:center"><div class="muted">' + s[0] + '</div><div style="font-size:26px;font-weight:800;color:var(--accent)">' + esc(s[1]) + '</div></div>').join('') + '</div>';
    } else if (t === 'users') {
      const j = await this.api('a_users', null, true);
      if (!j.ok) return;
      p.innerHTML = '<table class="tbl"><tr><th>ID</th><th>昵称</th><th>身份</th><th>场次</th><th>操作</th></tr>' +
        j.users.map(u => '<tr><td>' + u.id + '</td><td>' + esc(u.avatar) + ' ' + esc(u.name) + '</td><td>' +
          (u.is_admin ? '<span class="tag gold">管理员</span>' : '') + (u.banned ? '<span class="tag red">封禁</span>' : '') + (u.vip_until * 1000 > Date.now() ? '<span class="tag green">VIP</span>' : '') +
          '</td><td>' + u.games + '/' + u.wins + '</td><td class="row">' +
          '<button class="btn sm" data-a="ban" data-id="' + u.id + '">' + (u.banned ? '解封' : '封禁') + '</button>' +
          '<button class="btn sm" data-a="vip" data-id="' + u.id + '">赠VIP30天</button>' +
          '<button class="btn sm" data-a="pass" data-id="' + u.id + '">重置密码</button></td></tr>').join('') + '</table>';
      p.querySelectorAll('[data-a]').forEach(b => b.onclick = async () => {
        const id = +b.dataset.id;
        let body = {id};
        if (b.dataset.a === 'ban') body.banned = b.textContent.trim() === '封禁';
        if (b.dataset.a === 'vip') body.vipDays = 30;
        if (b.dataset.a === 'pass') {
          const np = prompt('新密码（≥6 位）');
          if (!np) return;
          body.newPass = np;
        }
        const r = await this.api('a_user_save', body);
        this.toast(r.ok ? '已更新' : r.err);
        if (r.ok) this.loadAdmin();
      });
    } else if (t === 'feat') {
      const j = await this.api('a_features', null, true);
      if (!j.ok) return;
      const names = {duo: '双人组队', spectate: '观战', shop: '商城 VIP', forum: '论坛', rank: '排行榜', guide: '新手引导', register: '开放注册', voiceMode: '语音同传', downloads: '下载页'};
      p.innerHTML = Object.keys(j.features).map(k =>
        '<div class="row" style="padding:8px 0"><span class="grow">' + (names[k] || k) + '</span>' +
        '<button class="switch' + (j.features[k] ? ' on' : '') + '" data-k="' + k + '"></button></div>').join('') +
        '<div class="muted" style="margin-top:10px">关闭后：客户端立即隐藏入口，服务端同时拒绝相关接口（双重保险）。</div>';
      p.querySelectorAll('.switch').forEach(s => s.onclick = async () => {
        j.features[s.dataset.k] = !j.features[s.dataset.k];
        const r = await this.api('a_features', {features: j.features});
        if (r.ok) { this.features = r.features; this.toast('已保存'); this.loadAdmin(); }
      });
    } else if (t === 'disp') {
      const j = await this.api('a_display', null, true);
      if (!j.ok) return;
      const s = j.site;
      p.innerHTML = '<div class="field"><label>站点名称</label><input type="text" id="d-name" value="' + esc(s.name || '') + '"></div>' +
        '<div class="field"><label>页面最大高度（50-100，100=不限）</label><input type="number" id="d-mph" min="50" max="100" value="' + (s.max_page_height || 100) + '"></div>' +
        '<button class="btn gold" id="d-save">保存</button>';
      $('#d-save').onclick = async () => {
        const r = await this.api('a_display', {name: $('#d-name').value, maxPageHeight: +$('#d-mph').value});
        if (r.ok) {
          this.toast('已保存，刷新生效');
          const sh = $('#app-shell');
          const h = +$('#d-mph').value;
          if (h > 0 && h < 100) { sh.classList.add('capped'); sh.style.setProperty('--mph', h); } else sh.classList.remove('capped');
          document.title = $('#d-name').value + ' · 狼人杀 Online';
        } else this.toast(r.err);
      };
    } else if (t === 'rooms') {
      const j = await this.api('a_rooms', null, true);
      if (!j.ok) return;
      p.innerHTML = '<table class="tbl"><tr><th>房号</th><th>名称</th><th>状态</th><th>操作</th></tr>' +
        j.rooms.map(r => '<tr><td>' + esc(r.no) + '</td><td>' + esc(r.name) + '</td><td>' + esc(r.status) + '</td>' +
          '<td><button class="btn sm danger" data-c="' + r.id + '">关闭房间</button></td></tr>').join('') + '</table>';
      p.querySelectorAll('[data-c]').forEach(b => b.onclick = () => this.api('a_close_room', {id: +b.dataset.c}).then(() => this.loadAdmin()));
    } else if (t === 'bak') {
      const j = await this.api('a_backup_list', null, true);
      p.innerHTML = '<button class="btn gold" id="bk-now">立即备份</button> <span class="muted">备份为 SQL 全量导出（storage/backups）</span><div style="margin-top:12px" id="bk-list"></div>';
      const render = list => {
        $('#bk-list').innerHTML = list.length ? '<table class="tbl"><tr><th>文件</th><th>大小</th><th>时间</th><th></th></tr>' +
          list.map(b => '<tr><td>' + esc(b.file) + '</td><td>' + Math.round(b.size / 1024) + 'KB</td><td>' + new Date(b.created * 1000).toLocaleString() + '</td>' +
            '<td><a class="btn sm" href="api.php?a=a_backup_dl&id=' + b.id + '">下载</a></td></tr>').join('') + '</table>' : '<div class="muted">暂无备份</div>';
      };
      render(j.backups || []);
      $('#bk-now').onclick = async () => {
        const r = await this.api('a_backup');
        if (r.ok) { this.toast('备份完成 ✓'); const j2 = await this.api('a_backup_list', null, true); render(j2.backups); }
      };
    } else if (t === 'upd') {
      const j = await this.api('a_update', null, true);
      if (!j.ok) return;
      p.innerHTML = '<div class="row"><span>当前版本 <b>v' + esc(j.version) + '</b></span><div class="grow"></div>' +
        '<button class="btn gold" id="up-now"' + (j.pending.length ? '' : ' disabled') + '>一键更新（' + j.pending.length + ' 个待打补丁）</button></div>' +
        (j.pending.length ? '<div class="muted" style="margin-top:10px">' + j.pending.map(x => '• ' + esc(x.v) + '：' + esc(x.text)).join('<br>') + '</div>' : '<div class="muted" style="margin-top:10px">已是最新 ✓</div>');
      if (j.pending.length) $('#up-now').onclick = async () => {
        const r = await this.api('a_update');
        if (r.ok) { this.toast('更新完成 ✓'); this.loadAdmin(); }
      };
    } else if (t === 'plug') {
      const j = await this.api('a_plugins', null, true);
      if (!j.ok) return;
      p.innerHTML = j.plugins.map(x =>
        '<div class="row" style="padding:10px 0;border-bottom:1px solid var(--border-soft)">' +
        '<div class="grow"><b>' + esc(x.name) + '</b> <span class="tag">v' + esc(x.version) + '</span>' +
        '<div class="muted">' + esc(x.desc) + '</div><div class="muted">钩子：' + esc((x.hooks || []).join(', ')) + '</div></div>' +
        '<button class="switch' + (x.on ? ' on' : '') + '" data-n="' + esc(x.name) + '"></button></div>').join('') +
        '<div class="muted" style="margin-top:10px">插件放在 plugins/&lt;name&gt;/（plugin.json + plugin.php），声明钩子即可介入对局。</div>';
      p.querySelectorAll('.switch').forEach(s => s.onclick = async () => {
        const r = await this.api('a_plugins', {name: s.dataset.n, on: !s.classList.contains('on')});
        if (r.ok) { this.toast('已保存'); this.loadAdmin(); }
      });
    } else if (t === 'log') {
      const j = await this.api('changelog', null, true);
      p.innerHTML = '<div class="field"><label>新增日志</label><textarea id="cl-text" placeholder="本次更新内容…"></textarea></div>' +
        '<button class="btn gold" id="cl-add">发布</button><div style="margin-top:14px">' +
        j.logs.map(l => '<div style="padding:8px 0;border-bottom:1px solid var(--border-soft)"><b>v' + esc(l.version) + '</b> <span class="muted">' + esc(l.date) + '</span><div>' + esc(l.text) + '</div></div>').join('') + '</div>';
      $('#cl-add').onclick = async () => {
        const r = await this.api('a_changelog_add', {text: $('#cl-text').value});
        if (r.ok) { this.toast('已发布'); this.loadAdmin(); }
      };
    }
  },
};

const GameRoleNames = {werewolf: '狼人', whiteWolf: '白狼王', seer: '预言家', witch: '女巫', guard: '守卫', hunter: '猎人', crow: '乌鸦', silencer: '禁言长老', villager: '村民'};
const ActionNames = {GUARD: '守卫守护', WOLF_KILL: '狼人袭击', SEER: '预言家查验', WITCH: '女巫用药', CROW: '乌鸦抹黑', SILENCER: '禁言',
  LAST_WORDS: '遗言', SHERIFF_SIGNUP: '警长竞选', SHERIFF_VOTE: '警长投票', SPEECH: '发言', VOTE: '投票',
  PK_SPEECH: 'PK发言', PK_VOTE: 'PK投票', SHOOT: '开枪', BLOWUP: '自爆'};
function youSeat(g) { return (g.mySeat || window.__mySeat || 0); }
function youSpectate(g) { return g.youSpectate || false; }

/* 记录自己的座位（gstate 返回时写入） */
const _origRender = WW.renderGame.bind(WW);
WW.renderGame = function (g, you) {
  window.__mySeat = you && you.seat ? you.seat : 0;
  g.mySeat = window.__mySeat;
  g.youSpectate = you ? !!you.spectate : false;
  _origRender(g, you);
};

/* ---------- 绑定静态事件 ---------- */
document.addEventListener('DOMContentLoaded', () => {
  WW.boot();
  $('#tab-login').onclick = () => { WW.authMode = 'login'; $('#tab-login').classList.add('on'); $('#tab-reg').classList.remove('on'); $('#btn-auth').textContent = '进入村庄'; };
  $('#tab-reg').onclick = () => { WW.authMode = 'register'; $('#tab-reg').classList.add('on'); $('#tab-login').classList.remove('on'); $('#btn-auth').textContent = '注册并进入'; };
  $('#btn-auth').onclick = () => WW.doAuth();
  $('#btn-create').onclick = () => WW.createRoom();
  $('#btn-quick').onclick = () => WW.quickStart();
  $('#btn-join').onclick = () => { const v = $('#join-no').value.trim(); if (v) WW.joinRoom(v); };
  $('#btn-start').onclick = async () => { const r = await WW.api('start'); if (!r.ok) WW.toast(r.err); };
  $('#btn-ready').onclick = () => WW.api('ready');
  $('#btn-addai').onclick = async () => { const r = await WW.api('addai'); if (!r.ok) WW.toast(r.err); };
  $('#btn-spectate').onclick = async () => { const r = await WW.api('spectate'); if (!r.ok) WW.toast(r.err); else WW.toast('已切换为观战'); };
  $('#btn-playseat').onclick = async () => { const r = await WW.api('play_seat'); if (!r.ok) WW.toast(r.err); else WW.toast('已加入对局座位'); };
  $('#btn-leave').onclick = () => WW.leaveRoom();
  $('#f-add').onclick = async () => {
    const r = await WW.api('friend_add', {name: $('#f-add-name').value.trim()});
    WW.toast(r.ok ? '已添加' : r.err); if (r.ok) { $('#f-add-name').value = ''; WW.loadFriends(); }
  };
  $('#chat-send').onclick = async () => {
    const v = $('#chat-input').value.trim();
    if (!v || !WW.chatWith) return;
    const r = await WW.api('fmsg_send', {to: WW.chatWith, text: v});
    if (r.ok) { $('#chat-input').value = ''; WW.pollChat(true); }
  };
  $('#fp-post').onclick = async () => {
    const r = await WW.api('forum_post', {title: $('#fp-title').value, text: $('#fp-text').value});
    if (r.ok) { $('#fp-title').value = ''; $('#fp-text').value = ''; WW.loadForum(); } else WW.toast(r.err);
  };
  document.addEventListener('keydown', e => { if (e.key === 'Escape') WW.closeModal(); });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) WW.stopPolls(); else WW.route();
  });
});
