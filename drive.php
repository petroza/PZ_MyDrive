<?php
declare(strict_types=1);

/*
 * MyDrive — drive.php
 * The app itself. Renders the Google-Drive-style shell (left rail, search,
 * storage meter) and mounts the shared file browser for "My Drive". Recent,
 * Starred, Trash and Search are rendered client-side from the JSON API.
 */

require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/drive.php';
require_once __DIR__ . '/lib/ui.php';
pzc_secure_headers();
pzc_require_admin_page();

$csrf  = pzc_csrf_token();
$user  = (string)pzc_cfg('admin_user', 'admin');
$site  = (string)pzc_cfg('site_name', 'MyDrive');
$st    = md_storage();
$ini   = strtoupper(mb_substr($user, 0, 1));
$ownerAnimal = md_owner_animal();

pzc_head('Můj disk');
?>
<script nonce="<?= pzc_e(pzc_nonce()) ?>">try{if(localStorage.getItem('mydrive-theme')==='dark')document.documentElement.setAttribute('data-theme','dark');}catch(e){}</script>
<div class="dr-app">
  <aside class="dr-side">
    <div class="dr-brand"><span class="lg"><?= pzc_ic('cloud') ?></span> <b>My<span>Drive</span></b></div>
    <div class="dr-new-wrap">
      <button class="dr-new" id="drNew"><span class="pl">+</span> Nový</button>
    </div>
    <nav class="dr-nav">
      <button class="dr-ni act" data-view="drive"><?= pzc_ic('drive') ?> Můj disk</button>
      <button class="dr-ni" data-view="recent"><?= pzc_ic('recent') ?> Nedávné</button>
      <button class="dr-ni" data-view="starred"><?= pzc_ic('star') ?> S hvězdičkou</button>
      <button class="dr-ni" data-view="trash"><?= pzc_ic('trash') ?> Koš</button>
      <button class="dr-ni" data-view="admin"><?= pzc_ic('cog') ?> Správa</button>
    </nav>
    <div class="dr-meter" id="drMeter">
      <?= pzc_ic('cloud') ?> Úložiště
      <div class="track"><div class="fill" id="drFill" style="width:<?= (int)$st['pct'] ?>%"></div></div>
      <span id="drMeterTxt">
        <?php if ($st['quota'] > 0): ?>
          <?= pzc_e(pzc_human_size($st['used'])) ?> z <?= pzc_e(pzc_human_size($st['quota'])) ?>
        <?php else: ?>
          <?= pzc_e(pzc_human_size($st['used'])) ?> využito
        <?php endif; ?>
      </span>
    </div>
  </aside>

  <div class="dr-main">
    <div class="dr-top">
      <div class="dr-srch">
        <?= pzc_ic('search') ?>
        <input id="drSearch" placeholder="Hledat na disku" autocomplete="off">
      </div>
      <div class="dr-acct">
        <button class="btn btn-sm" id="drTheme" type="button" title="Přepnout světlý/tmavý režim">🌙</button>
        <span class="who"><?= pzc_e($user) ?></span>
        <div class="dr-avatar" title="<?= pzc_e($user) ?>"><?= pzc_e($ownerAnimal) ?></div>
        <form method="post" action="<?= pzc_e(pzc_url('logout.php')) ?>" style="margin:0">
          <input type="hidden" name="csrf" value="<?= pzc_e($csrf) ?>">
          <button class="btn btn-sm" type="submit" title="Odhlásit"><?= pzc_ic('logout') ?></button>
        </form>
      </div>
    </div>

    <div class="dr-content">
      <!-- My Drive: the shared file browser mounts here -->
      <div class="dr-surface" id="driveView">
        <div id="fbMount"></div>
      </div>
      <!-- Recent / Starred / Trash / Search render here -->
      <div class="dr-surface" id="listView" style="display:none">
        <div class="dr-h"><span class="t" id="lvTitle"></span><span class="sub" id="lvSub"></span>
          <button class="btn btn-sm btn-d right" id="lvEmpty" style="display:none">Vysypat koš</button>
        </div>
        <div id="viewMount"></div>
      </div>
    </div>
  </div>
</div>

<script src="<?= pzc_e(pzc_url('assets/filebrowser.js') . '?v=' . (int)@filemtime(__DIR__ . '/assets/filebrowser.js')) ?>"></script>
<script nonce="<?= pzc_e(pzc_nonce()) ?>">
(function () {
  var CFG = {
    apiUrl:      <?= json_encode(pzc_url('api.php'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    uploadUrl:   <?= json_encode(pzc_url('upload.php'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    downloadUrl: <?= json_encode(pzc_url('download.php'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    csrf:        <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    hasQuota:    <?= $st['quota'] > 0 ? 'true' : 'false' ?>
  };

  /* ---------- tiny shared helpers (mirror the browser's private ones) ---------- */
  function h(s){return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
  function fmtSize(b){if(!b)return '0 B';var u=['B','KB','MB','GB','TB'],i=0,n=b;while(n>=1024&&i<u.length-1){n/=1024;i++;}return (i===0?b:n.toFixed(1))+' '+u[i];}
  function fmtDate(ts){if(!ts)return '';var d=new Date(ts*1000);function p(x){return (x<10?'0':'')+x;}return p(d.getDate())+'.'+p(d.getMonth()+1)+'.'+d.getFullYear()+' '+p(d.getHours())+':'+p(d.getMinutes());}
  function icon(type,name){if(type==='dir')return '📁';var e=(name.split('.').pop()||'').toLowerCase();
    if(['jpg','jpeg','png','gif','webp','bmp','svg','avif'].indexOf(e)>=0)return '🖼️';
    if(['mp4','mov','mkv','avi','webm','m4v'].indexOf(e)>=0)return '🎬';
    if(['mp3','wav','flac','aac','ogg','m4a'].indexOf(e)>=0)return '🎵';
    if(e==='pdf')return '📕';if(['zip','rar','7z','tar','gz'].indexOf(e)>=0)return '🗜️';
    if(['doc','docx','txt','rtf','md'].indexOf(e)>=0)return '📄';
    if(['xls','xlsx','csv'].indexOf(e)>=0)return '📊';return '📦';}
  function mediaKind(name){var e=(name.split('.').pop()||'').toLowerCase();
    if(['jpg','jpeg','png','gif','webp','bmp','avif'].indexOf(e)>=0)return 'image';
    if(['mp4','webm','ogg','ogv','mov','m4v'].indexOf(e)>=0)return 'video';return null;}

  function api(action, extra) {
    var body = Object.assign({ action: action }, extra || {});
    return fetch(CFG.apiUrl, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': CFG.csrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }
  function dlUrl(rel, mode){ return CFG.downloadUrl + '?share=mydrive&rel=' + encodeURIComponent(rel) + '&mode=' + (mode||'download'); }
  function thumbUrl(rel){ return CFG.downloadUrl + '?share=mydrive&rel=' + encodeURIComponent(rel) + '&mode=inline&thumb=1'; }

  /* ---------------- starred-state cache (so the browser menu shows ★/☆) ------- */
  var starSet = {};
  function refreshStars(cb){ api('starred').then(function(d){ starSet={}; (d.items||[]).forEach(function(it){ starSet[it.rel]=true; }); if(cb)cb(); }); }

  /* ----------------------------- the file browser ----------------------------- */
  var fb = null;
  function initBrowser() {
    if (fb) return;
    fb = initFileBrowser({
      mount: '#fbMount', share: 'mydrive',
      perms: { browse: true, download: true, write: true },
      apiUrl: CFG.apiUrl, uploadUrl: CFG.uploadUrl, downloadUrl: CFG.downloadUrl,
      csrf: CFG.csrf, rootLabel: 'Můj disk',
      onShare: function (rel, name, type) { openShareDialog(rel, name, type); },
      isStarred: function (rel) { return !!starSet[rel]; },
      onStar: function (rel) {
        api('star', { rel: rel }).then(function (d) {
          if (d && d.ok) { if (d.starred) starSet[rel]=true; else delete starSet[rel]; }
          refreshStorage();
        });
      }
    });
  }

  /* ----------------------------- storage meter -------------------------------- */
  var fillEl = document.getElementById('drFill');
  var meterTxt = document.getElementById('drMeterTxt');
  function refreshStorage() {
    api('storage').then(function (d) {
      if (!d || !d.ok) return;
      var s = d.storage;
      fillEl.style.width = s.pct + '%';
      fillEl.className = 'fill' + (s.pct >= 100 ? ' full' : (s.pct >= 80 ? ' warn' : ''));
      meterTxt.textContent = CFG.hasQuota
        ? fmtSize(s.used) + ' z ' + fmtSize(s.quota)
        : fmtSize(s.used) + ' využito';
    });
  }
  setInterval(refreshStorage, 30000);

  /* ----------------------------- custom list views ---------------------------- */
  var driveView = document.getElementById('driveView');
  var listView  = document.getElementById('listView');
  var viewMount = document.getElementById('viewMount');
  var lvTitle   = document.getElementById('lvTitle');
  var lvSub     = document.getElementById('lvSub');
  var lvEmpty   = document.getElementById('lvEmpty');

  function rowHtml(it, ctx) {
    var media = it.type === 'file' && mediaKind(it.name);
    var src = ctx === 'trash' ? (CFG.downloadUrl + '?trash=' + encodeURIComponent(it.id) + '&mode=inline&thumb=1') : thumbUrl(it.rel);
    var thumb;
    if (media === 'image') thumb = '<img class="thumb" src="' + h(src) + '" loading="lazy" alt="">';
    else if (media === 'video') thumb = '<video class="thumb" muted preload="metadata" src="' + h(src) + '"></video>';
    else thumb = '<span class="ic">' + icon(it.type, it.name) + '</span>';
    var meta = it.type === 'dir' ? 'složka' : fmtSize(it.size);
    var when = ctx === 'trash' ? ('smazáno ' + fmtDate(it.deleted))
             : (it.dir ? (h(it.dir) + ' · ') : '') + fmtDate(it.mtime);
    var acts = '';
    if (ctx === 'trash') {
      acts = '<button class="btn btn-sm" data-act="restore" data-id="' + h(it.id) + '">Obnovit</button>' +
             '<button class="btn btn-sm btn-d" data-act="purge" data-id="' + h(it.id) + '">Smazat</button>';
    } else {
      var starred = !!starSet[it.rel];
      if (it.type === 'file')
        acts += '<button class="btn btn-sm" data-act="open" data-rel="' + h(it.rel) + '">Otevřít</button>';
      else
        acts += '<button class="btn btn-sm" data-act="goto" data-rel="' + h(it.rel) + '">Otevřít</button>';
      acts += '<button class="btn btn-sm" data-act="star" data-rel="' + h(it.rel) + '" title="Hvězdička">' + (starred ? '★' : '☆') + '</button>';
    }
    return '<div class="fl-row" style="grid-template-columns:46px 1fr 120px 200px auto">' +
             '<div class="thumb-wrap">' + thumb + '</div>' +
             '<div class="nm">' + h(it.name) + '</div>' +
             '<div class="sz">' + meta + '</div>' +
             '<div class="dt">' + when + '</div>' +
             '<div class="row" style="justify-content:flex-end;gap:.35rem">' + acts + '</div>' +
           '</div>';
  }

  function renderList(title, sub, items, ctx) {
    listView.classList.remove('bare');
    lvTitle.textContent = title;
    lvSub.textContent = sub || '';
    lvEmpty.style.display = (ctx === 'trash' && items.length) ? '' : 'none';
    if (!items.length) {
      viewMount.innerHTML = '<div class="fl-empty"><span class="em">' +
        (ctx === 'trash' ? '🗑️' : ctx === 'starred' ? '⭐' : ctx === 'search' ? '🔎' : '🕓') +
        '</span>' + (ctx === 'trash' ? 'Koš je prázdný.' : ctx === 'starred' ? 'Zatím nic s hvězdičkou.' : ctx === 'search' ? 'Nic nenalezeno.' : 'Zatím žádné soubory.') + '</div>';
      return;
    }
    viewMount.innerHTML = '<div class="fl">' + items.map(function (it) { return rowHtml(it, ctx); }).join('') + '</div>';
    Array.prototype.forEach.call(viewMount.querySelectorAll('[data-act]'), function (b) {
      b.onclick = function () {
        var act = b.getAttribute('data-act'), rel = b.getAttribute('data-rel'), id = b.getAttribute('data-id');
        if (act === 'open')   { window.open(dlUrl(rel, 'inline'), '_blank', 'noopener'); }
        if (act === 'goto')   { showView('drive'); /* folder lives in the drive */ }
        if (act === 'star')   { api('star', { rel: rel }).then(function (d) { if (d.starred) starSet[rel]=true; else delete starSet[rel]; reloadCurrent(); }); }
        if (act === 'restore'){ api('trash_restore', { id: id }).then(function (d) { if (d.ok) { reloadCurrent(); refreshStorage(); } }); }
        if (act === 'purge')  { if (confirm('Trvale smazat tuto položku? Nelze vrátit.')) api('trash_purge', { id: id }).then(function () { reloadCurrent(); refreshStorage(); }); }
      };
    });
  }

  lvEmpty.onclick = function () {
    if (!confirm('Trvale smazat vše v koši? Nelze vrátit.')) return;
    api('trash_empty').then(function () { loadTrash(); refreshStorage(); });
  };

  function loadRecent()  { api('recent').then(function (d) { renderList('Nedávné', 'Naposledy upravené soubory', d.items || [], 'recent'); }); }
  function loadStarred() { refreshStars(function () { api('starred').then(function (d) { renderList('S hvězdičkou', 'Tvé oblíbené položky', d.items || [], 'starred'); }); }); }
  function loadTrash()   { api('trash_list').then(function (d) { renderList('Koš', 'Položky se po obnovení vrátí na původní místo', d.items || [], 'trash'); }); }
  function loadSearch(q) { api('search', { q: q }).then(function (d) { renderList('Výsledky hledání', '„' + q + '"', d.items || [], 'search'); }); }

  /* ------------------------------- admin / správa ----------------------------- */
  function loadAdmin() {
    api('admin_overview').then(function (d) {
      lvTitle.textContent = 'Správa'; lvSub.textContent = 'Sdílené odkazy a nastavení'; lvEmpty.style.display = 'none';
      listView.classList.add('bare');
      var s = d.settings || {}, links = d.links || [];
      var html = '<div class="adm-card"><h3>🔗 Sdílené odkazy (' + links.length + ')</h3>';
      if (!links.length) {
        html += '<div class="fl-empty"><span class="em">🔗</span>Zatím nic nesdílíš. Sdílení vytvoříš u položky přes „⋯ → Sdílet".</div>';
      } else {
        html += '<div class="fl">';
        links.forEach(function (l) {
          var meta = (l.is_dir ? '📁 složka' : '📄 soubor') +
            ' · ' + (l.perm === 'write' ? '✏ čtení+zápis' : '👁 jen čtení') +
            (l.has_pass ? ' · 🔒 heslo' : '') +
            (l.expires ? ' · do ' + fmtDate(l.expires) : '') +
            (l.expired ? ' · ⛔ vypršel' : '') +
            (l.missing ? ' · ⚠ položka smazána' : '');
          html += '<div class="fl-row" style="grid-template-columns:1fr auto">' +
            '<div class="nm"><span class="nmt"><span class="lanim">' + (l.animal || '🔗') + '</span> ' + h(l.rel || l.name) + '</span><div class="small muted">' + meta + '</div></div>' +
            '<div class="row" style="justify-content:flex-end;gap:.35rem">' +
              '<a class="btn btn-sm" href="' + h(l.url) + '" target="_blank" rel="noopener">Otevřít</a>' +
              '<button class="btn btn-sm" data-acp="copy" data-url="' + h(l.url) + '">Kopírovat</button>' +
              '<button class="btn btn-sm" data-acp="edit" data-id="' + h(l.id) + '" data-isdir="' + (l.is_dir ? '1' : '') + '" data-perm="' + h(l.perm || 'read') + '">Upravit</button>' +
              '<button class="btn btn-sm btn-d" data-acp="del" data-id="' + h(l.id) + '">Zrušit</button>' +
            '</div></div>';
        });
        html += '</div>';
      }
      html += '</div>';

      // ---- transfer statistics ----
      var st = d.stats || { today: { down: 0, up: 0 }, month: { down: 0, up: 0 }, days: [], months: [] };
      var act = d.activity || [];
      var maxD = 1; st.days.forEach(function (x) { maxD = Math.max(maxD, x.down, x.up); });
      var maxM = 1; st.months.forEach(function (x) { maxM = Math.max(maxM, x.down, x.up); });
      function stBars(arr, max, lab) {
        return '<div class="stbars">' + arr.map(function (x) {
          var dh = Math.max(2, Math.round(x.down / max * 100)), uh = Math.max(2, Math.round(x.up / max * 100));
          return '<div class="stcol" title="' + lab(x) + ' · ↓ ' + fmtSize(x.down) + ' · ↑ ' + fmtSize(x.up) + '">' +
            '<div class="stbw"><span class="stb dn" style="height:' + (x.down ? dh : 0) + '%"></span><span class="stb up" style="height:' + (x.up ? uh : 0) + '%"></span></div>' +
            '<div class="stlab">' + lab(x) + '</div></div>';
        }).join('') + '</div>';
      }
      html += '<div class="adm-card"><h3>📊 Přenosy (stažení / nahrání)</h3>' +
        '<div class="strow"><div class="stbox"><div class="stk">Dnes</div><div class="stv">↓ ' + fmtSize(st.today.down) + ' &nbsp;·&nbsp; ↑ ' + fmtSize(st.today.up) + '</div></div>' +
        '<div class="stbox"><div class="stk">Tento měsíc</div><div class="stv">↓ ' + fmtSize(st.month.down) + ' &nbsp;·&nbsp; ↑ ' + fmtSize(st.month.up) + '</div></div></div>' +
        '<div class="sthd">Posledních 14 dní</div>' + stBars(st.days, maxD, function (x) { return x.day.slice(8) + '.' + x.day.slice(5, 7) + '.'; }) +
        '<div class="sthd">Měsíce</div>' + stBars(st.months, maxM, function (x) { return x.month.slice(5) + '/' + x.month.slice(0, 4); }) +
        '<div class="stleg"><span class="stdot dn"></span> stažení &nbsp;&nbsp; <span class="stdot up"></span> nahrání</div>';
      if (act.length) {
        html += '<div class="sthd">Poslední aktivita</div><div class="actlist">';
        act.forEach(function (a) {
          html += '<div class="actrow"><span class="acta">' + (a.who || '🔗') + '</span><span class="actd ' + (a.dir === 'up' ? 'up' : 'dn') + '">' + (a.dir === 'up' ? '⬆' : '⬇') + '</span><span class="actn">' + h(a.name || '') + '</span><span class="acts">' + fmtSize(a.bytes) + '</span><span class="actt">' + fmtDate(a.ts) + '</span></div>';
        });
        html += '</div>';
      }
      html += '</div>';
      html += '<div class="adm-card"><h3>⚙️ Nastavení</h3>' +
        '<label>Název webu</label><input id="admSite" value="' + h(s.site_name || '') + '">' +
        '<label>Kvóta úložiště (GB, 0 = neomezeně)</label><input id="admQuota" type="number" min="0" value="' + (s.quota_gb || 0) + '">' +
        '<label>Nové heslo administrátora (nech prázdné = beze změny)</label><input id="admPw" type="password" placeholder="••••••" autocomplete="new-password">' +
        '<div style="margin-top:14px"><button class="btn btn-p" id="admSave">Uložit nastavení</button></div>' +
        '<div class="hint">Přihlašovací jméno: <b>' + h(s.admin_user || '') + '</b></div>' +
        '</div>';
      viewMount.innerHTML = html;
      Array.prototype.forEach.call(viewMount.querySelectorAll('[data-acp]'), function (b) {
        b.onclick = function () {
          var a = b.getAttribute('data-acp');
          if (a === 'copy') { var u = b.getAttribute('data-url'); if (navigator.clipboard) navigator.clipboard.writeText(u); toast('Odkaz zkopírován', 'ok'); }
          if (a === 'edit') { editLinkModal(b.getAttribute('data-id'), b.getAttribute('data-isdir') === '1', b.getAttribute('data-perm') || 'read'); }
          if (a === 'del') { if (!confirm('Zrušit tento odkaz? Přestane fungovat.')) return; api('link_delete', { id: b.getAttribute('data-id') }).then(function () { toast('Odkaz zrušen', 'ok'); loadAdmin(); }); }
        };
      });
      var sv = viewMount.querySelector('#admSave');
      if (sv) sv.onclick = function () {
        var payload = { site_name: viewMount.querySelector('#admSite').value, quota_gb: viewMount.querySelector('#admQuota').value };
        var pw = viewMount.querySelector('#admPw').value; if (pw) payload.password = pw;
        api('admin_save', payload).then(function (d) {
          if (!d.ok) { toast(d.msg || 'Chyba', 'err'); return; }
          toast('Uloženo', 'ok'); viewMount.querySelector('#admPw').value = ''; refreshStorage();
        });
      };
    });
  }

  /* --------------------------------- routing ---------------------------------- */
  var current = 'drive', currentQuery = '';
  function setActiveNav(view) {
    Array.prototype.forEach.call(document.querySelectorAll('.dr-ni'), function (n) {
      n.classList.toggle('act', n.getAttribute('data-view') === view);
    });
  }
  function reloadCurrent() {
    if (current === 'recent')  loadRecent();
    else if (current === 'starred') loadStarred();
    else if (current === 'trash')   loadTrash();
    else if (current === 'search')  loadSearch(currentQuery);
    else if (current === 'admin')   loadAdmin();
  }
  function showView(view) {
    current = view;
    if (view === 'drive') {
      setActiveNav('drive');
      driveView.style.display = '';
      listView.style.display = 'none';
      initBrowser();
      refreshStorage();
      return;
    }
    setActiveNav(view === 'search' ? '' : view);
    driveView.style.display = 'none';
    listView.style.display = '';
    if (view === 'recent') loadRecent();
    if (view === 'starred') loadStarred();
    if (view === 'trash') loadTrash();
    if (view === 'admin') loadAdmin();
  }

  Array.prototype.forEach.call(document.querySelectorAll('.dr-ni'), function (n) {
    n.onclick = function () { var sb = document.getElementById('drSearch'); sb.value = ''; showView(n.getAttribute('data-view')); };
  });

  /* New button reuses the browser's own add menu (folder / upload). */
  document.getElementById('drNew').onclick = function () {
    showView('drive');
    setTimeout(function () { var a = document.querySelector('#fbMount #fbAdd'); if (a) a.click(); }, 60);
  };

  /* Global search (debounced, recursive on the server). */
  var sTimer = null;
  document.getElementById('drSearch').oninput = function () {
    var q = this.value.trim();
    clearTimeout(sTimer);
    if (q === '') { showView('drive'); return; }
    sTimer = setTimeout(function () { current = 'search'; currentQuery = q; setActiveNav(''); driveView.style.display='none'; listView.style.display=''; loadSearch(q); }, 280);
  };

  /* ----------------------------- toast + modal -------------------------------- */
  var toastEl = null;
  function toast(msg, kind) {
    if (!toastEl) { toastEl = document.createElement('div'); toastEl.innerHTML = '<span class="dot"></span><span class="m"></span>'; document.body.appendChild(toastEl); }
    toastEl.className = 'toast ' + (kind || '');
    toastEl.querySelector('.m').textContent = msg;
    toastEl.classList.add('show');
    clearTimeout(toast._t); toast._t = setTimeout(function () { toastEl.classList.remove('show'); }, 2600);
  }
  var modalEl = null;
  function modal(html) {
    if (!modalEl) { modalEl = document.createElement('div'); modalEl.className = 'modal'; modalEl.innerHTML = '<div class="box"></div>'; document.body.appendChild(modalEl); modalEl.addEventListener('click', function (e) { if (e.target === modalEl) closeModal(); }); }
    modalEl.querySelector('.box').innerHTML = html; modalEl.classList.add('open');
    return modalEl.querySelector('.box');
  }
  function closeModal() { if (modalEl) modalEl.classList.remove('open'); }

  /* ----------------------- admin: edit an existing share ---------------------- */
  function editLinkModal(id, isDir, perm) {
    var permSel = isDir
      ? '<label>Oprávnění</label><select id="elPerm"><option value="read"' + (perm !== 'write' ? ' selected' : '') + '>Jen číst (zobrazit a stáhnout)</option><option value="write"' + (perm === 'write' ? ' selected' : '') + '>Číst i zapisovat (může nahrávat)</option></select>'
      : '';
    var box = modal('<h2>✏️ Upravit sdílení</h2>' + permSel +
      '<label>Nové heslo (prázdné = beze změny)</label>' +
      '<div class="row" style="flex-wrap:nowrap;gap:.4rem"><input id="elPw" placeholder="(beze změny)" autocomplete="off"><button class="btn" type="button" id="elGen" title="Vygenerovat">↻</button></div>' +
      '<div class="hint">Vygeneruj a zkopíruj nové heslo, pak ulož.</div>' +
      '<label style="display:flex;align-items:center;gap:.5rem;text-transform:none;letter-spacing:0;font-weight:500"><input type="checkbox" id="elNoPw" style="width:auto"> Zrušit heslo (odkaz bez hesla)</label>' +
      '<label>Platnost (dní) — prázdné = beze změny, 0 = neomezeně</label><input type="number" id="elDays" min="0" placeholder="(beze změny)">' +
      '<button class="btn btn-p btn-block" type="button" id="elSave">Uložit změny</button>' +
      '<div class="row" style="margin-top:14px"><button class="btn right" type="button" id="elClose">Zavřít</button></div>');
    box.querySelector('#elClose').onclick = closeModal;
    var gen = box.querySelector('#elGen'); if (gen) gen.onclick = function () { box.querySelector('#elPw').value = genPwd(); box.querySelector('#elNoPw').checked = false; };
    box.querySelector('#elSave').onclick = function () {
      var payload = { id: id };
      var noPw = box.querySelector('#elNoPw').checked;
      var pwv = box.querySelector('#elPw').value;
      if (noPw) payload.password = '';
      else if (pwv !== '') payload.password = pwv;
      var dv = box.querySelector('#elDays').value;
      if (dv !== '') payload.days = dv;
      if (isDir && box.querySelector('#elPerm')) payload.perm = box.querySelector('#elPerm').value;
      api('link_update', payload).then(function (d) {
        if (!d.ok) { toast(d.msg || 'Chyba', 'err'); return; }
        toast('Uloženo' + ((!noPw && pwv !== '') ? (' · nové heslo: ' + pwv) : ''), 'ok');
        closeModal(); loadAdmin();
      });
    };
  }

  /* ------------------------- public link sharing (gdrive-style) --------------- */
  function openShareDialog(rel, name, type) {
    api('link_get', { rel: rel }).then(function (d) {
      renderShare(rel, name, type, (d.links && d.links[0]) || null);
    });
  }
  function genPwd() {
    var c = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', s = '';
    var a = new Uint32Array(12); (window.crypto && crypto.getRandomValues) ? crypto.getRandomValues(a) : a.fill(0);
    for (var i = 0; i < 12; i++) s += c[a[i] % c.length];
    return s;
  }
  function permLabel(p) { return p === 'write' ? 'Čtení i zápis (může nahrávat)' : 'Jen čtení (zobrazit a stáhnout)'; }
  function shareCopy(box, sel, msg) {
    var i = box.querySelector(sel); if (!i) return;
    if (i.select) i.select();
    if (navigator.clipboard) navigator.clipboard.writeText(i.value); else { try { document.execCommand('copy'); } catch (e) {} }
    toast(msg || 'Zkopírováno', 'ok');
  }
  function afterShareChange() { if (current === 'admin') loadAdmin(); else if (fb && fb.reload) fb.reload(); }

  function renderShare(rel, name, type, link) {
    if (link) return renderManage(rel, name, type, link);
    return renderCreate(rel, name, type);
  }

  function renderCreate(rel, name, type) {
    var isDir = type === 'dir';
    var permSel = isDir
      ? '<label>Oprávnění</label><select id="shPerm"><option value="read">Jen číst (zobrazit a stáhnout)</option><option value="write">Číst i zapisovat (může nahrávat)</option></select>'
      : '';
    var inner =
      '<div class="sub">Kdokoli s odkazem ' + (isDir ? 'otevře tuto složku' : 'otevře tento soubor') + '. Heslo a platnost nastav níže.</div>' +
      permSel +
      '<label>Heslo pro příjemce</label>' +
      '<div class="row" style="flex-wrap:nowrap;gap:.4rem"><input id="shPw" value="' + h(genPwd()) + '" style="flex:1" autocomplete="off"><button class="btn" type="button" id="shGen" title="Vygenerovat nové">↻</button></div>' +
      '<div class="hint">Automaticky vygenerováno — pošli ho příjemci spolu s odkazem. Vymaž pro odkaz bez hesla.</div>' +
      '<label>Platnost hesla (dní) — prázdné = neomezeně</label><input type="number" id="shDays" min="1" placeholder="neomezeně">' +
      '<button class="btn btn-p btn-block" type="button" id="shMake">Vytvořit odkaz</button>';
    var box = modal('<h2>🔗 Sdílet — ' + h(name) + '</h2>' + inner +
      '<div class="row" style="margin-top:14px"><button class="btn right" type="button" id="shClose">Zavřít</button></div>');
    box.querySelector('#shClose').onclick = closeModal;
    box.querySelector('#shGen').onclick = function () { box.querySelector('#shPw').value = genPwd(); };
    box.querySelector('#shMake').onclick = function () {
      var pw = box.querySelector('#shPw').value;
      var days = box.querySelector('#shDays').value;
      var perm = (isDir && box.querySelector('#shPerm')) ? box.querySelector('#shPerm').value : 'read';
      api('link_create', { rel: rel, password: pw, days: days, perm: perm }).then(function (d) {
        if (!d.ok) { toast(d.msg || 'Chyba', 'err'); return; }
        afterShareChange();
        renderCreated(rel, name, type, d.url, d.id, pw, perm, days);
      });
    };
  }

  function renderCreated(rel, name, type, url, id, pw, perm, days) {
    var exp = (days && +days > 0) ? ('platí ' + (+days) + ' dní') : 'bez expirace';
    var inner =
      '<div class="ok">Hotovo! Pošli příjemci odkaz' + (pw ? ' i heslo' : '') + '.</div>' +
      '<label>Odkaz</label><div class="row" style="flex-wrap:nowrap;gap:.4rem"><input id="shUrl" readonly value="' + h(url) + '" style="flex:1"><button class="btn" type="button" id="shCopyU">Kopírovat</button></div>' +
      (pw ? '<label>Heslo</label><div class="row" style="flex-wrap:nowrap;gap:.4rem"><input id="shPw2" readonly value="' + h(pw) + '" class="mono" style="flex:1"><button class="btn" type="button" id="shCopyP">Kopírovat</button></div>' : '') +
      '<div class="hint">' + (type === 'dir' ? permLabel(perm) : 'Jen čtení (zobrazit a stáhnout)') + ' · ' + exp + '</div>' +
      '<button class="btn btn-d btn-block" type="button" id="shDel">Zrušit odkaz</button>';
    var box = modal('<h2>🔗 Sdíleno — ' + h(name) + '</h2>' + inner +
      '<div class="row" style="margin-top:14px"><button class="btn right" type="button" id="shClose">Hotovo</button></div>');
    box.querySelector('#shClose').onclick = closeModal;
    box.querySelector('#shCopyU').onclick = function () { shareCopy(box, '#shUrl', 'Odkaz zkopírován'); };
    var cp = box.querySelector('#shCopyP'); if (cp) cp.onclick = function () { shareCopy(box, '#shPw2', 'Heslo zkopírováno'); };
    box.querySelector('#shDel').onclick = function () {
      if (!confirm('Zrušit veřejný odkaz? Přestane fungovat.')) return;
      api('link_delete', { id: id }).then(function () { toast('Odkaz zrušen', 'ok'); afterShareChange(); closeModal(); });
    };
  }

  function renderManage(rel, name, type, link) {
    var inner =
      '<div class="ok">Veřejný odkaz je aktivní.</div>' +
      '<label>Odkaz</label><div class="row" style="flex-wrap:nowrap;gap:.4rem"><input id="shUrl" readonly value="' + h(link.url) + '" style="flex:1"><button class="btn" type="button" id="shCopyU">Kopírovat</button></div>' +
      '<div class="hint">' + (type === 'dir' ? permLabel(link.perm) : 'Jen čtení') + (link.has_pass ? ' · 🔒 chráněno heslem' : ' · bez hesla') + (link.expires ? ' · platí do ' + fmtDate(link.expires) : ' · bez expirace') + '</div>' +
      '<div class="hint">Heslo se z bezpečnostních důvodů znovu nezobrazuje. Pro změnu hesla/práv zruš odkaz a vytvoř nový.</div>' +
      '<button class="btn btn-d btn-block" type="button" id="shDel">Zrušit odkaz</button>';
    var box = modal('<h2>🔗 Sdílet — ' + h(name) + '</h2>' + inner +
      '<div class="row" style="margin-top:14px"><button class="btn right" type="button" id="shClose">Zavřít</button></div>');
    box.querySelector('#shClose').onclick = closeModal;
    box.querySelector('#shCopyU').onclick = function () { shareCopy(box, '#shUrl', 'Odkaz zkopírován'); };
    box.querySelector('#shDel').onclick = function () {
      if (!confirm('Zrušit veřejný odkaz? Přestane fungovat.')) return;
      api('link_delete', { id: link.id }).then(function () { toast('Odkaz zrušen', 'ok'); afterShareChange(); openShareDialog(rel, name, type); });
    };
  }

  /* ---------------------------- dark / light theme ---------------------------- */
  var themeBtn = document.getElementById('drTheme');
  function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t === 'dark' ? 'dark' : 'light');
    if (themeBtn) themeBtn.textContent = (t === 'dark') ? '☀️' : '🌙';
  }
  (function () { var t = 'light'; try { t = localStorage.getItem('mydrive-theme') || 'light'; } catch (e) {} applyTheme(t); })();
  if (themeBtn) themeBtn.onclick = function () {
    var n = (document.documentElement.getAttribute('data-theme') === 'dark') ? 'light' : 'dark';
    try { localStorage.setItem('mydrive-theme', n); } catch (e) {}
    applyTheme(n);
  };

  /* boot */
  refreshStars(function () { showView('drive'); });
})();
</script>
<?php pzc_foot();
