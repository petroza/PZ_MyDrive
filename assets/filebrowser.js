/* PZ Cloud — shared file browser (vanilla JS, no dependencies).
 * Used by both the admin panel and the user app. Driven by an options object:
 *   initFileBrowser({ mount, apiUrl, uploadUrl, downloadUrl, csrf, share, perms })
 *   perms = { browse:bool, download:bool, write:bool }
 */
(function (global) {
  'use strict';
  var CHUNK = 5 * 1024 * 1024; // 5 MB — safely under Forpsi's post_max_size

  // Attribute-safe escaper (the textContent->innerHTML trick does NOT escape
  // quotes, which broke data-name/data-rel attributes built from filenames).
  function h(s) {
    return (s == null ? '' : String(s))
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function fmtSize(b) {
    if (!b) return '0 B';
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0, n = b;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 ? b : n.toFixed(1)) + ' ' + u[i];
  }
  function fmtDate(ts) {
    if (!ts) return '';
    var d = new Date(ts * 1000);
    function p(x) { return (x < 10 ? '0' : '') + x; }
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear() + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  function icon(type, name) {
    if (type === 'dir') return '📁';
    var e = (name.split('.').pop() || '').toLowerCase();
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif'].indexOf(e) >= 0) return '🖼️';
    if (['mp4', 'mov', 'mkv', 'avi', 'webm', 'm4v'].indexOf(e) >= 0) return '🎬';
    if (['mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a'].indexOf(e) >= 0) return '🎵';
    if (['pdf'].indexOf(e) >= 0) return '📕';
    if (['zip', 'rar', '7z', 'tar', 'gz'].indexOf(e) >= 0) return '🗜️';
    if (['doc', 'docx', 'txt', 'rtf', 'md'].indexOf(e) >= 0) return '📄';
    if (['xls', 'xlsx', 'csv'].indexOf(e) >= 0) return '📊';
    return '📦';
  }

  function mediaKind(name) {
    var e = (name.split('.').pop() || '').toLowerCase();
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif'].indexOf(e) >= 0) return 'image';
    if (['mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v'].indexOf(e) >= 0) return 'video';
    return null;
  }

  /* ---- shared fullscreen preview (lightbox), with prev/next + keyboard ---- */
  var ov = null, ovItems = [], ovIdx = 0;
  function ovBuild() {
    if (ov) return;
    ov = document.createElement('div');
    ov.className = 'pv';
    ov.innerHTML =
      '<div class="pv-bar"><span class="pv-name"></span><span class="pv-sp"></span>' +
      '<a class="pv-dl btn btn-sm" target="_blank" rel="noopener">⬇ Stáhnout</a>' +
      '<button class="pv-x btn btn-sm">Zavřít ✕</button></div>' +
      '<button class="pv-nav pv-prev" aria-label="Předchozí">‹</button>' +
      '<div class="pv-stage"></div>' +
      '<button class="pv-nav pv-next" aria-label="Další">›</button>';
    document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov) ovHide(); });
    ov.querySelector('.pv-x').onclick = ovHide;
    ov.querySelector('.pv-prev').onclick = function () { ovStep(-1); };
    ov.querySelector('.pv-next').onclick = function () { ovStep(1); };
    document.addEventListener('keydown', function (e) {
      if (!ov || !ov.classList.contains('open')) return;
      if (e.key === 'Escape') ovHide();
      else if (e.key === 'ArrowLeft') ovStep(-1);
      else if (e.key === 'ArrowRight') ovStep(1);
    });
  }
  function ovRender() {
    var it = ovItems[ovIdx]; if (!it) return;
    var stage = ov.querySelector('.pv-stage');
    stage.innerHTML = '';
    var el;
    if (it.kind === 'video') { el = document.createElement('video'); el.controls = true; el.autoplay = true; el.playsInline = true; }
    else { el = document.createElement('img'); el.alt = ''; }
    el.setAttribute('src', it.view); // setAttribute avoids any URL-encoding pitfalls
    stage.appendChild(el);
    ov.querySelector('.pv-name').textContent = it.name;
    var dl = ov.querySelector('.pv-dl');
    if (it.canDl && it.dl) { dl.style.display = ''; dl.setAttribute('href', it.dl); } else dl.style.display = 'none';
    var multi = ovItems.length > 1;
    ov.querySelector('.pv-prev').style.display = multi ? '' : 'none';
    ov.querySelector('.pv-next').style.display = multi ? '' : 'none';
  }
  function ovStep(d) { if (!ovItems.length) return; ovIdx = (ovIdx + d + ovItems.length) % ovItems.length; ovRender(); }
  function ovHide() { if (ov) { ov.classList.remove('open'); ov.querySelector('.pv-stage').innerHTML = ''; } } // clear stage to stop video playback
  function openGallery(items, start) { ovBuild(); ovItems = items || []; ovIdx = start || 0; ov.classList.add('open'); ovRender(); }

  /* ---- right-click / kebab context menu (Explorer / Finder style) ---- */
  var ctx = null, ctxX = 120, ctxY = 120, ctxOpen = false;
  function ctxBuild() {
    if (ctx) return;
    ctx = document.createElement('div'); ctx.className = 'ctxmenu'; ctx.style.display = 'none';
    document.body.appendChild(ctx);
    document.addEventListener('click', ctxHide);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') ctxHide(); });
    document.addEventListener('scroll', ctxHide, true);
    window.addEventListener('resize', ctxHide);
  }
  // only hide — do NOT clear innerHTML here: destroying the just-clicked menu button
  // before its handler finishes makes Chrome treat input.click() (file dialog) as a
  // non-user gesture and silently blocks it. ctxShow rebuilds innerHTML on each open.
  function ctxHide() { if (ctx) ctx.style.display = 'none'; ctxOpen = false; }
  function ctxShow(items, x, y) {
    ctxBuild(); ctx.innerHTML = '';
    items.forEach(function (it) {
      if (!it) { var s = document.createElement('div'); s.className = 'ctxsep'; ctx.appendChild(s); return; }
      var b = document.createElement('button'); b.className = 'ctxitem' + (it.danger ? ' danger' : '');
      var ic = document.createElement('span'); ic.className = 'ci'; ic.textContent = it.icon || ''; b.appendChild(ic);
      var lb = document.createElement('span'); lb.className = 'cl'; lb.textContent = it.label; b.appendChild(lb);
      b.onclick = function (e) { e.stopPropagation(); ctxHide(); it.fn(); };
      ctx.appendChild(b);
    });
    ctx.style.display = 'block'; ctxX = x; ctxY = y; ctxOpen = true;
    var w = ctx.offsetWidth, ht = ctx.offsetHeight;
    ctx.style.left = Math.max(6, Math.min(x, window.innerWidth - w - 8)) + 'px';
    ctx.style.top = Math.max(6, Math.min(y, window.innerHeight - ht - 8)) + 'px';
  }

  function initFileBrowser(opts) {
    var mount = typeof opts.mount === 'string' ? document.querySelector(opts.mount) : opts.mount;
    if (!mount) return;
    var perms = opts.perms || { browse: true, download: false, write: false };
    var cur = '';            // current relative folder
    var curMedia = [];       // image/video items in the current folder (for the gallery)
    var viewMode = 'list';   // 'list' | 'grid'
    var selected = {};        // rel -> true (bulk selection)
    var shown = [];           // currently rendered items, in display order (for shift-range)
    var lastIdx = null;       // anchor index for shift-range selection
    var sortKey = 'name';     // name | size | mtime | ctime | type
    var sortDir = 1;          // 1 = ascending, -1 = descending
    var coarse = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches); // touch device
    var dragRel = null;       // item currently being dragged (for move)
    var toastEl = null;

    function toast(msg, kind) {
      if (!toastEl) {
        toastEl = document.createElement('div');
        toastEl.className = 'toast';
        document.body.appendChild(toastEl);
      }
      toastEl.className = 'toast ' + (kind || '');
      toastEl.innerHTML = '<span class="dot"></span><span></span>';
      toastEl.lastChild.textContent = msg;
      toastEl.classList.add('show');
      clearTimeout(toast._t);
      toast._t = setTimeout(function () { toastEl.classList.remove('show'); }, 2800);
    }

    function api(action, extra) {
      var body = Object.assign({ action: action, share: opts.share, rel: cur }, extra || {});
      return fetch(opts.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': opts.csrf },
        credentials: 'same-origin',
        body: JSON.stringify(body)
      }).then(function (r) { return r.json(); });
    }

    function dlUrl(rel, mode) {
      return opts.downloadUrl + '?share=' + encodeURIComponent(opts.share) +
        '&rel=' + encodeURIComponent(rel) + '&mode=' + mode;
    }
    // thumbnail URL — &thumb=1 tells download.php NOT to log it as a real "view"
    function thumbUrl(rel) {
      return (dlUrl(rel, 'inline') + '&thumb=1').replace(/&/g, '&amp;');
    }
    function openGalleryAt(midx) {
      if (midx < 0 || midx >= curMedia.length) return;
      var items = curMedia.map(function (m) {
        return { name: m.name, kind: m.kind, view: dlUrl(m.rel, 'inline'), dl: dlUrl(m.rel, 'download'), canDl: !!perms.download };
      });
      openGallery(items, midx);
    }

    function render() {
      mount.innerHTML =
        '<div class="fb-bar">' +
          '<button class="btn btn-sm" id="fbBack" title="Zpět (o úroveň výš)">←</button>' +
          '<div class="crumb" id="fbCrumb"></div>' +
          '<span class="right"></span>' +
          (perms.download ? '<button class="btn btn-sm" id="fbZip">⬇ Stáhnout vše (ZIP)</button>' : '') +
          (perms.write ? '<button class="btn btn-sm btn-p" id="fbAdd">+ Přidat ▾</button>' +
            '<input type="file" id="fbUp" multiple style="display:none">' +
            '<input type="file" id="fbUpDir" webkitdirectory directory multiple style="display:none">' : '') +
        '</div>' +
        '<div class="fb-toolswrap">' +
          '<div class="fb-bar" id="fbTools">' +
            '<input id="fbSearch" placeholder="🔎 Hledat v této složce…" style="flex:1;min-width:120px">' +
            '<button class="btn btn-sm" id="fbSortBtn" title="Seřadit">⇅ Seřadit ▾</button>' +
            '<button class="btn btn-sm" id="fbView">▦ Mřížka</button>' +
          '</div>' +
          '<div class="fb-sel" id="fbSel" style="display:none"></div>' +
        '</div>' +
        (perms.write ? '<div class="prog" id="fbProg"><div class="bar" id="fbBar"></div></div><div class="small muted" id="fbProgTxt" style="margin-bottom:.4rem"></div>' : '') +
        '<div class="fb-area" id="fbArea">' +
          '<div class="fl-head" id="fbHead"></div>' +
          '<div class="fl" id="fbList"></div>' +
          (perms.write ? '<div class="fb-drop-ov" id="fbDropOv"><div>📥 <b>Pusť pro nahrání</b><div class="small">soubory i celé složky</div></div></div>' : '') +
        '</div>';

      if (perms.download) mount.querySelector('#fbZip').onclick = function () {
        window.location = opts.downloadUrl + '?share=' + encodeURIComponent(opts.share) + '&rel=' + encodeURIComponent(cur) + '&zip=1';
      };
      mount.querySelector('#fbBack').onclick = function () {
        if (cur !== '') { cur = cur.split('/').slice(0, -1).join('/'); load(); }
        else if (opts.onHome) opts.onHome();
      };
      mount.querySelector('#fbSearch').oninput = renderList;
      mount.querySelector('#fbSortBtn').onclick = function (e) { e.stopPropagation(); if (ctxOpen) { ctxHide(); return; } var r = this.getBoundingClientRect(); sortMenu(r.left, r.bottom + 2); };
      mount.querySelector('#fbList').oncontextmenu = function (e) {
        if (e.target.closest && e.target.closest('.fl-row, .gtile')) return; // item menu handles those
        e.preventDefault(); bgMenu(e.clientX, e.clientY);
      };
      mount.querySelector('#fbView').onclick = function () {
        viewMode = (viewMode === 'list') ? 'grid' : (viewMode === 'grid') ? 'tree' : 'list';
        this.innerHTML = viewLabel();
        selected = {};
        renderList();
      };
      if (perms.write) {
        mount.querySelector('#fbAdd').onclick = function (e) {
          e.stopPropagation();
          if (ctxOpen) { ctxHide(); return; }
          var r = this.getBoundingClientRect();
          ctxShow([
            { icon: '📁', label: 'Nová složka', fn: newFolder },
            { icon: '⬆️', label: 'Nahrát soubory', fn: function () { var u = mount.querySelector('#fbUp'); if (u) u.click(); } },
            { icon: '📂', label: 'Nahrát složku', fn: function () { var u = mount.querySelector('#fbUpDir'); if (u) u.click(); } }
          ], r.left, r.bottom + 2);
        };
        mount.querySelector('#fbUp').onchange = function (e) { handleInputFiles(e.target.files, false); e.target.value = ''; };
        var dirInput = mount.querySelector('#fbUpDir');
        if (dirInput) dirInput.onchange = function (e) { handleInputFiles(e.target.files, true); e.target.value = ''; };
        setupDrop();
      }
      load();
    }

    function crumb() {
      var el = mount.querySelector('#fbCrumb');
      var parts = cur === '' ? [] : cur.split('/');
      var html = '';
      if (opts.onHome) html += '<a href="#" data-home="1">' + h(opts.homeLabel || 'Domů') + '</a> / ';
      html += (parts.length === 0)
        ? '<b>' + h(opts.rootLabel || 'Kořen') + '</b>'
        : '<a href="#" data-rel="">' + h(opts.rootLabel || 'Kořen') + '</a>';
      var acc = '';
      parts.forEach(function (p, i) {
        acc = acc ? acc + '/' + p : p;
        html += ' / ' + (i === parts.length - 1
          ? '<b>' + h(p) + '</b>'
          : '<a href="#" data-rel="' + h(acc) + '">' + h(p) + '</a>');
      });
      el.innerHTML = html;
      var bk = mount.querySelector('#fbBack'); if (bk) bk.style.display = (cur === '' && !opts.onHome) ? 'none' : '';
      var hb = el.querySelector('[data-home]'); if (hb) hb.onclick = function (e) { e.preventDefault(); opts.onHome(); };
      Array.prototype.forEach.call(el.querySelectorAll('a[data-rel]'), function (a) {
        a.onclick = function (e) { e.preventDefault(); cur = a.getAttribute('data-rel'); load(); };
      });
    }

    var curItems = [];
    function load() {
      crumb();
      var se = mount.querySelector('#fbSearch'); if (se) se.value = '';
      selected = {};
      var listEl = mount.querySelector('#fbList');
      listEl.innerHTML = '<div class="fl-empty">Načítám…</div>';
      api('list').then(function (d) {
        if (!d.ok) { curItems = []; listEl.innerHTML = '<div class="fl-empty">' + h(d.msg || 'Chyba načítání') + '</div>'; updateSelBar(); return; }
        curItems = d.items || [];
        renderList();
      }).catch(function () { curItems = []; listEl.innerHTML = '<div class="fl-empty">Chyba spojení.</div>'; });
    }

    function viewLabel() { return viewMode === 'list' ? '▦ Mřížka' : viewMode === 'grid' ? '🌳 Strom' : '☰ Seznam'; }

    function renderList() {
      var listEl = mount.querySelector('#fbList'); if (!listEl) return;
      if (viewMode === 'tree') { renderHead(); renderTree(listEl); updateSelBar(); return; }
      lastIdx = null; // re-filter/sort changes order → drop the shift-range anchor
      var se = mount.querySelector('#fbSearch'); var q = se ? se.value.trim().toLowerCase() : '';
      var items = curItems.filter(function (it) { return !q || it.name.toLowerCase().indexOf(q) >= 0; });
      items = items.slice().sort(function (a, b) {
        if (a.type !== b.type) return a.type === 'dir' ? -1 : 1; // folders first
        var r = 0;
        if (sortKey === 'name') r = a.name.localeCompare(b.name, 'cs', { sensitivity: 'base' });
        else if (sortKey === 'size') r = (a.size || 0) - (b.size || 0);
        else if (sortKey === 'mtime') r = (a.mtime || 0) - (b.mtime || 0);
        else if (sortKey === 'ctime') r = (a.ctime || 0) - (b.ctime || 0);
        else if (sortKey === 'type') r = fileExt(a.name).localeCompare(fileExt(b.name));
        if (r === 0) r = a.name.localeCompare(b.name, 'cs', { sensitivity: 'base' }); // stable tiebreak by name
        return sortDir < 0 ? -r : r;
      });
      shown = items;
      renderHead();
      curMedia = [];
      items.forEach(function (it) { if (it.type === 'file') { var k = mediaKind(it.name); if (k) { it._midx = curMedia.length; curMedia.push({ rel: it.rel, name: it.name, kind: k }); } } });
      if (!items.length) {
        listEl.className = 'fl';
        var emptyMsg = q ? 'Nic nenalezeno.'
          : ('Složka je prázdná.' + (perms.write ? '<div class="small muted" style="margin-top:.35rem">Přetáhni sem soubory nebo použij <b>+ Přidat</b>.</div>' : ''));
        listEl.innerHTML = '<div class="fl-empty">' + emptyMsg + '</div>';
        updateSelBar(); return;
      }
      if (viewMode === 'grid') { listEl.className = 'fl-grid'; listEl.innerHTML = items.map(tileHtml).join(''); wireTiles(listEl); refreshSel(); }
      else { listEl.className = 'fl'; listEl.innerHTML = items.map(function (it, i) { return rowHtml(it, i); }).join(''); wireRows(listEl); refreshSel(); }
    }

    /* ------------------------------- tree view -------------------------------- */
    function renderTree(listEl) {
      listEl.className = 'fl-tree';
      listEl.innerHTML = '<div class="tkids troot" id="treeRoot"></div>';
      loadTreeChildren(listEl.querySelector('#treeRoot'), '');
    }
    function loadTreeChildren(container, rel) {
      container.innerHTML = '<div class="tload small muted">Načítám…</div>';
      api('list', { rel: rel }).then(function (d) {
        var items = (d.items || []).slice().sort(function (a, b) {
          if (a.type !== b.type) return a.type === 'dir' ? -1 : 1;
          return a.name.localeCompare(b.name, 'cs', { sensitivity: 'base' });
        });
        if (!items.length) { container.innerHTML = '<div class="tempty small muted">prázdné</div>'; return; }
        container.innerHTML = items.map(treeNodeHtml).join('');
        wireTreeNodes(container);
      }).catch(function () { container.innerHTML = '<div class="tempty small muted">chyba</div>'; });
    }
    function treeNodeHtml(it) {
      var isDir = it.type === 'dir';
      var ic = isDir ? '📁' : icon('file', it.name);
      return '<div class="tnode" data-rel="' + h(it.rel) + '" data-type="' + it.type + '" data-name="' + h(it.name) + '">' +
        '<div class="trow' + (it.shared ? ' shared' : '') + '">' +
          '<span class="ttog"' + (isDir ? '' : ' style="visibility:hidden"') + '>▸</span>' +
          '<span class="tic">' + ic + '</span>' +
          '<span class="tname">' + h(it.name) + '</span>' +
        '</div>' +
        (isDir ? '<div class="tkids" style="display:none"></div>' : '') +
      '</div>';
    }
    function wireTreeNodes(container) {
      Array.prototype.forEach.call(container.children, function (node) {
        if (!node.classList || !node.classList.contains('tnode')) return;
        var rel = node.getAttribute('data-rel'), type = node.getAttribute('data-type'), name = node.getAttribute('data-name');
        var row = node.querySelector('.trow'), tog = node.querySelector('.ttog'), kids = node.querySelector('.tkids');
        if (type === 'dir' && tog) tog.onclick = function (e) {
          e.stopPropagation();
          var open = node.classList.toggle('open');
          tog.textContent = open ? '▾' : '▸';
          if (open) { if (!node.getAttribute('data-loaded')) { node.setAttribute('data-loaded', '1'); loadTreeChildren(kids, rel); } kids.style.display = ''; }
          else kids.style.display = 'none';
        };
        row.onclick = function (e) {
          if (e.target.closest('.ttog')) return;
          if (type === 'dir') { cur = rel; viewMode = 'list'; var vb = mount.querySelector('#fbView'); if (vb) vb.innerHTML = viewLabel(); load(); }
          else { window.open(dlUrl(rel, 'inline'), '_blank', 'noopener'); }
        };
        row.oncontextmenu = function (e) { e.preventDefault(); e.stopPropagation(); itemMenu(rel, name, type, null, e.clientX, e.clientY); };
      });
    }

    function fileExt(name) { var i = name.lastIndexOf('.'); return i > 0 ? name.slice(i + 1).toLowerCase() : ''; }
    function findRow(rel) { var rows = mount.querySelectorAll('#fbList .fl-row'); for (var i = 0; i < rows.length; i++) { if (rows[i].getAttribute('data-rel') === rel) return rows[i]; } return null; }

    function setSort(key) {
      if (sortKey === key) sortDir = -sortDir;
      else { sortKey = key; sortDir = (key === 'mtime' || key === 'ctime') ? -1 : 1; } // dates default newest-first
      renderList();
    }

    // clickable column headers (Drive-style), with sort-direction arrow + select-all
    function renderHead() {
      var hd = mount.querySelector('#fbHead'); if (!hd) return;
      if (viewMode !== 'list' || !shown.length) { hd.style.display = 'none'; hd.innerHTML = ''; return; }
      hd.style.display = '';
      function arr(k) { return sortKey === k ? (sortDir < 0 ? ' ▼' : ' ▲') : ''; }
      var dateKey = sortKey === 'ctime' ? 'ctime' : 'mtime';
      var dateLbl = sortKey === 'ctime' ? 'Vytvořeno' : 'Změněno';
      var allOn = shown.every(function (it) { return selected[it.rel]; });
      hd.innerHTML =
        '<div><input type="checkbox" class="ck" id="fbHeadAll"' + (allOn ? ' checked' : '') + ' title="Označit vše"></div>' +
        '<div></div>' +
        '<div class="hcol' + (sortKey === 'name' ? ' on' : '') + '" data-sort="name">Název' + arr('name') + '</div>' +
        '<div class="hcol' + (sortKey === 'size' ? ' on' : '') + '" data-sort="size">Velikost' + arr('size') + '</div>' +
        '<div class="hcol' + (dateKey === sortKey ? ' on' : '') + '" data-sort="' + dateKey + '">' + dateLbl + arr(dateKey) + '</div>' +
        '<div></div>';
      Array.prototype.forEach.call(hd.querySelectorAll('.hcol'), function (c) {
        c.onclick = function () { setSort(c.getAttribute('data-sort')); };
      });
      var all = hd.querySelector('#fbHeadAll');
      if (all) all.onclick = function (e) {
        e.stopPropagation();
        if (all.checked) shown.forEach(function (it) { selected[it.rel] = true; });
        else selected = {};
        refreshSel();
      };
    }

    function sortMenu(x, y) {
      function opt(key, label) { return { icon: (sortKey === key ? '✓' : ''), label: label, fn: function () { setSort(key); } }; }
      ctxShow([
        opt('name', 'Název'),
        opt('size', 'Velikost'),
        opt('mtime', 'Naposledy změněno'),
        opt('ctime', 'Datum vytvoření'),
        opt('type', 'Typ souboru'),
        null,
        { icon: (sortDir < 0 ? '↑' : '↓'), label: (sortDir < 0 ? 'Vzestupně (A→Z)' : 'Sestupně (Z→A)'), fn: function () { sortDir = -sortDir; renderList(); } }
      ], x, y);
    }

    function refreshSel() {
      var listEl = mount.querySelector('#fbList'); if (!listEl) return;
      Array.prototype.forEach.call(listEl.querySelectorAll('.fl-row, .gtile'), function (row) {
        var on = !!selected[row.getAttribute('data-rel')];
        row.classList.toggle('sel', on);
        var ck = row.querySelector('.ck'); if (ck) ck.checked = on;
      });
      var all = mount.querySelector('#fbHeadAll');
      if (all) all.checked = shown.length > 0 && shown.every(function (it) { return selected[it.rel]; });
      updateSelBar();
    }

    // Drive-style click selection: plain=one, Ctrl/⌘=toggle, Shift=range.
    function selectAt(idx, e) {
      var it = shown[idx]; if (!it) return;
      if (e.shiftKey && lastIdx !== null) {
        if (!(e.ctrlKey || e.metaKey)) selected = {};
        var a = Math.min(lastIdx, idx), b = Math.max(lastIdx, idx);
        for (var i = a; i <= b; i++) if (shown[i]) selected[shown[i].rel] = true;
      } else if (e.ctrlKey || e.metaKey) {
        if (selected[it.rel]) delete selected[it.rel]; else selected[it.rel] = true;
        lastIdx = idx;
      } else {
        selected = {}; selected[it.rel] = true; lastIdx = idx;
      }
      refreshSel();
    }

    function openItemRow(type, rel, midx) {
      if (type === 'dir') { cur = rel; load(); }
      else if (midx !== null) { openGalleryAt(midx); }
      else { window.open(dlUrl(rel, 'inline'), '_blank', 'noopener'); }
    }

    function tileHtml(it, idx) {
      var k = it.type === 'file' ? mediaKind(it.name) : null;
      var thumb;
      if (it.type === 'dir') thumb = '<div class="gthumb">📁</div>';
      else if (k === 'image') thumb = '<div class="gthumb"><img loading="lazy" src="' + thumbUrl(it.rel) + '" alt=""></div>';
      else if (k === 'video') thumb = '<div class="gthumb"><video muted preload="metadata" src="' + thumbUrl(it.rel) + '"></video></div>';
      else thumb = '<div class="gthumb">' + icon('file', it.name) + '</div>';
      return '<div class="gtile' + (it.shared ? ' shared' : '') + '" data-idx="' + idx + '" data-name="' + h(it.name) + '" data-rel="' + h(it.rel) + '" data-type="' + it.type + '"' + (k ? ' data-midx="' + it._midx + '"' : '') + '>' +
        '<input type="checkbox" class="ck gck" data-sel="' + h(it.rel) + '"' + (selected[it.rel] ? ' checked' : '') + '>' +
        '<button class="kebab gkebab" title="Akce" aria-label="Akce">⋯</button>' +
        thumb + '<div class="gname">' + h(it.name) + '</div></div>';
    }

    function wireTiles(listEl) {
      Array.prototype.forEach.call(listEl.querySelectorAll('.gtile'), function (t) {
        var type = t.getAttribute('data-type'), rel = t.getAttribute('data-rel'), name = t.getAttribute('data-name'), midxAttr = t.getAttribute('data-midx');
        var midx = midxAttr === null ? null : +midxAttr;
        var idx = +t.getAttribute('data-idx');
        var ck = t.querySelector('.gck');
        if (ck) ck.onclick = function (e) {
          e.stopPropagation();
          if (ck.checked) selected[ck.getAttribute('data-sel')] = true; else delete selected[ck.getAttribute('data-sel')];
          lastIdx = idx; refreshSel();
        };
        // Windows-style: plain click OPENS (folder = enter, file = preview).
        // Multi-select is via the checkbox or Ctrl/⌘ (toggle) / Shift (range).
        t.addEventListener('click', function (e) {
          if (e.target.closest('.ck, .kebab')) return;
          if (e.ctrlKey || e.metaKey || e.shiftKey) { e.preventDefault(); selectAt(idx, e); return; }
          openItemRow(type, rel, midx);
        });
        var kb = t.querySelector('.gkebab');
        if (kb) kb.onclick = function (e) { e.preventDefault(); e.stopPropagation(); var r = kb.getBoundingClientRect(); itemMenu(rel, name, type, midx, r.right - 4, r.bottom + 2); };
        t.oncontextmenu = function (e) { e.preventDefault(); e.stopPropagation(); itemMenu(rel, name, type, midx, e.clientX, e.clientY); };
        if (perms.write) {
          t.setAttribute('draggable', 'true');
          t.addEventListener('dragstart', function (e) { dragRel = rel; if (e.dataTransfer) { e.dataTransfer.setData('text/plain', rel); e.dataTransfer.effectAllowed = 'move'; } });
          t.addEventListener('dragend', function () { dragRel = null; t.classList.remove('drop-into'); });
          if (type === 'dir') {
            t.addEventListener('dragover', function (e) { if (dragRel && dragRel !== rel) { e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'; t.classList.add('drop-into'); } });
            t.addEventListener('dragleave', function () { t.classList.remove('drop-into'); });
            t.addEventListener('drop', function (e) { if (dragRel && dragRel !== rel) { e.preventDefault(); e.stopPropagation(); t.classList.remove('drop-into'); var dr = dragRel; dragRel = null; doMove(dr, rel); } });
          }
        }
      });
    }

    function updateSelBar() {
      var bar = mount.querySelector('#fbSel'); if (!bar) return;
      var here = {}; curItems.forEach(function (it) { here[it.rel] = 1; });
      Object.keys(selected).forEach(function (r) { if (!here[r]) delete selected[r]; });
      var n = Object.keys(selected).length;
      if (!n) { bar.style.display = 'none'; bar.innerHTML = ''; return; }
      bar.style.display = 'flex'; // overlays the toolbar (absolute) — nothing below moves
      bar.innerHTML = '<button class="selx" id="selClear" title="Zrušit výběr" aria-label="Zrušit výběr">✕</button>' +
        '<b>' + n + ' vybráno</b>' +
        '<span class="selacts">' +
        (perms.download ? '<button class="btn btn-sm" id="selZip">⬇ Stáhnout</button>' : '') +
        (perms.write ? '<button class="btn btn-sm" id="selMove">↪ Přesunout</button>' : '') +
        (perms.write ? '<button class="btn btn-sm btn-d" id="selDel">🗑 Do koše</button>' : '') +
        '</span>';
      var z = bar.querySelector('#selZip'); if (z) z.onclick = bulkZip;
      var d = bar.querySelector('#selDel'); if (d) d.onclick = bulkDelete;
      var mv = bar.querySelector('#selMove'); if (mv) mv.onclick = function () { var r = mv.getBoundingClientRect(); bulkMove(r.left, r.bottom + 2); };
      bar.querySelector('#selClear').onclick = function () { selected = {}; refreshSel(); };
    }

    function bulkMove(x, y) {
      var rels = Object.keys(selected); if (!rels.length) return;
      var dests = [];
      if (cur !== '') dests.push({ icon: '⬆️', label: 'O úroveň výš', to: cur.split('/').slice(0, -1).join('/') });
      curItems.forEach(function (it) { if (it.type === 'dir' && !selected[it.rel]) dests.push({ icon: '📁', label: it.name, to: it.rel }); });
      if (!dests.length) { toast('Není kam přesunout (žádná cílová podsložka)', 'err'); return; }
      ctxShow(dests.map(function (dd) { return { icon: dd.icon, label: dd.label, fn: function () { bulkMoveTo(rels, dd.to); } }; }), x, y);
    }
    function bulkMoveTo(rels, to) {
      var i = 0, fail = 0;
      (function next() {
        if (i >= rels.length) { selected = {}; toast(fail ? (fail + ' položek nešlo přesunout') : 'Přesunuto', fail ? 'err' : 'ok'); load(); return; }
        api('move', { rel: rels[i], to: to }).then(function (d) { if (!d.ok) fail++; i++; next(); }, function () { fail++; i++; next(); });
      })();
    }

    function bulkZip() {
      var rels = Object.keys(selected); if (!rels.length) return;
      var url = opts.downloadUrl + '?share=' + encodeURIComponent(opts.share) + '&zip=1';
      rels.forEach(function (r) { url += '&rels[]=' + encodeURIComponent(r); });
      if (url.length > 7000) { toast('Příliš mnoho položek — použij „Stáhnout vše (ZIP)"', 'err'); return; }
      window.location = url;
    }

    function bulkDelete() {
      var rels = Object.keys(selected); if (!rels.length) return;
      if (!confirm('Přesunout ' + rels.length + ' vybraných položek do koše?')) return;
      var i = 0;
      (function next() {
        if (i >= rels.length) { selected = {}; toast('Smazáno', 'ok'); load(); return; }
        api('delete', { rel: rels[i] }).then(function () { i++; next(); }, function () { i++; next(); });
      })();
    }

    function rowHtml(it, idx) {
      var k = it.type === 'file' ? mediaKind(it.name) : null;
      var first;
      if (it.type === 'dir') first = '<div class="ic">📁</div>';
      else if (k === 'image') first = '<div class="thumb-wrap"><img class="thumb" loading="lazy" data-midx="' + it._midx + '" src="' + thumbUrl(it.rel) + '" alt=""></div>';
      else if (k === 'video') first = '<div class="thumb-wrap"><video class="thumb" muted preload="metadata" data-midx="' + it._midx + '" src="' + thumbUrl(it.rel) + '"></video><span class="thumb-play" data-midx="' + it._midx + '">▶</span></div>';
      else first = '<div class="ic">' + icon('file', it.name) + '</div>';

      return '<div class="fl-row' + (it.shared ? ' shared' : '') + '" data-idx="' + idx + '" data-name="' + h(it.name) + '" data-rel="' + h(it.rel) + '" data-type="' + it.type + '"' + (k ? ' data-midx="' + it._midx + '"' : '') + '>' +
        '<div><input type="checkbox" class="ck" data-sel="' + h(it.rel) + '"' + (selected[it.rel] ? ' checked' : '') + '></div>' +
        first +
        '<div class="nm ' + (it.type === 'dir' ? 'dir' : (k ? 'media' : '')) + '"' + (it.shared ? ' title="Sdíleno veřejným odkazem"' : '') + '>' +
          '<span class="nmt">' + h(it.name) + '</span></div>' +
        '<div class="sz">' + (it.type === 'dir' ? '' : fmtSize(it.size)) + '</div>' +
        '<div class="dt">' + fmtDate(sortKey === 'ctime' ? it.ctime : it.mtime) + '</div>' +
        '<div class="row" style="justify-content:flex-end"><button class="kebab" title="Akce" aria-label="Akce">⋯</button></div>' +
      '</div>';
    }

    function wireRows(listEl) {
      Array.prototype.forEach.call(listEl.querySelectorAll('.fl-row'), function (row) {
        var name = row.getAttribute('data-name');
        var rel = row.getAttribute('data-rel');
        var type = row.getAttribute('data-type');
        var midxAttr = row.getAttribute('data-midx');
        var midx = midxAttr === null ? null : +midxAttr;
        var idx = +row.getAttribute('data-idx');
        var ck = row.querySelector('.ck');
        if (ck) ck.onclick = function (e) {
          e.stopPropagation();
          if (ck.checked) selected[ck.getAttribute('data-sel')] = true; else delete selected[ck.getAttribute('data-sel')];
          lastIdx = idx; refreshSel();
        };
        row.style.cursor = 'pointer';
        // Windows-style: plain click OPENS (folder = enter, file = preview).
        // Multi-select is via the checkbox or Ctrl/⌘ (toggle) / Shift (range).
        row.addEventListener('click', function (e) {
          if (e.target.closest('.ck, .kebab')) return;
          if (e.ctrlKey || e.metaKey || e.shiftKey) { e.preventDefault(); selectAt(idx, e); return; }
          openItemRow(type, rel, midx);
        });
        var kb = row.querySelector('.kebab');
        if (kb) kb.onclick = function (e) { e.preventDefault(); e.stopPropagation(); var r = kb.getBoundingClientRect(); itemMenu(rel, name, type, midx, r.right - 4, r.bottom + 2); };
        row.oncontextmenu = function (e) { e.preventDefault(); e.stopPropagation(); itemMenu(rel, name, type, midx, e.clientX, e.clientY); };
        if (perms.write) {
          row.setAttribute('draggable', 'true');
          row.addEventListener('dragstart', function (e) { dragRel = rel; if (e.dataTransfer) { e.dataTransfer.setData('text/plain', rel); e.dataTransfer.effectAllowed = 'move'; } });
          row.addEventListener('dragend', function () { dragRel = null; row.classList.remove('drop-into'); });
          if (type === 'dir') {
            row.addEventListener('dragover', function (e) { if (dragRel && dragRel !== rel) { e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'; row.classList.add('drop-into'); } });
            row.addEventListener('dragleave', function () { row.classList.remove('drop-into'); });
            row.addEventListener('drop', function (e) { if (dragRel && dragRel !== rel) { e.preventDefault(); e.stopPropagation(); row.classList.remove('drop-into'); var dr = dragRel; dragRel = null; doMove(dr, rel); } });
          }
        }
      });
    }

    function newFolder() {
      var name = prompt('Název nové složky:');
      if (!name) return;
      api('mkdir', { name: name }).then(function (d) {
        if (d.ok) { toast('Složka vytvořena', 'ok'); load(); } else toast(d.msg || 'Chyba', 'err');
      });
    }
    function doDelete(rel, name) {
      if (!confirm('Opravdu smazat "' + name + '"?\nU složky se smaže i celý obsah.')) return;
      api('delete', { rel: rel }).then(function (d) {
        if (d.ok) { toast('Smazáno', 'ok'); load(); } else toast(d.msg || 'Chyba', 'err');
      });
    }
    function commitRename(rel, name, nn) {
      nn = (nn || '').trim();
      if (!nn || nn === name) { renderList(); return; }
      api('rename', { rel: rel, name: nn }).then(function (d) {
        if (d.ok) { toast('Přejmenováno', 'ok'); load(); } else { toast(d.msg || 'Chyba', 'err'); renderList(); }
      });
    }
    // inline rename in the list (Drive/Explorer-style); falls back to prompt() in grid view
    function doRename(rel, name, type) {
      var row = findRow(rel);
      var nmEl = row && row.querySelector('.nm');
      if (!nmEl) {
        var nn = prompt('Nový název:', name);
        if (nn !== null) commitRename(rel, name, nn);
        return;
      }
      var input = document.createElement('input');
      input.className = 'rn-input'; input.value = name;
      nmEl.innerHTML = ''; nmEl.appendChild(input);
      input.focus();
      var dot = name.lastIndexOf('.');
      if (type === 'file' && dot > 0) { try { input.setSelectionRange(0, dot); } catch (e) { input.select(); } }
      else input.select();
      var done = false;
      input.onclick = function (e) { e.stopPropagation(); };
      input.onkeydown = function (e) {
        if (e.key === 'Enter') { e.preventDefault(); done = true; commitRename(rel, name, input.value); }
        else if (e.key === 'Escape') { e.preventDefault(); done = true; renderList(); }
        e.stopPropagation();
      };
      input.onblur = function () { if (!done) { done = true; commitRename(rel, name, input.value); } };
    }
    function doMove(rel, to) {
      api('move', { rel: rel, to: to }).then(function (d) {
        if (d.ok) { toast('Přesunuto', 'ok'); selected = {}; load(); } else toast(d.msg || 'Přesun selhal', 'err');
      });
    }
    // context menu for one item (file or folder)
    function itemMenu(rel, name, type, midx, x, y) {
      var items = [];
      if (type === 'dir') {
        items.push({ icon: '📂', label: 'Otevřít', fn: function () { cur = rel; load(); } });
        if (perms.download) items.push({ icon: '⬇️', label: 'Stáhnout (ZIP)', fn: function () { window.location = opts.downloadUrl + '?share=' + encodeURIComponent(opts.share) + '&rel=' + encodeURIComponent(rel) + '&zip=1'; } });
      } else {
        items.push({ icon: '↗️', label: 'Otevřít', fn: function () { if (midx !== null) openGalleryAt(midx); else window.open(dlUrl(rel, 'inline'), '_blank', 'noopener'); } });
        if (perms.download) items.push({ icon: '⬇️', label: 'Stáhnout', fn: function () { window.location = dlUrl(rel, 'download'); } });
      }
      if (opts.onStar) { items.push(null); items.push({ icon: opts.isStarred && opts.isStarred(rel) ? '★' : '☆', label: opts.isStarred && opts.isStarred(rel) ? 'Odebrat hvězdičku' : 'Přidat hvězdičku', fn: function () { opts.onStar(rel); } }); }
      if (opts.onShare) { items.push({ icon: '🔗', label: 'Sdílet…', fn: function () { opts.onShare(rel, name, type); } }); }
      if (perms.write) {
        items.push(null);
        items.push({ icon: '✏️', label: 'Přejmenovat', fn: function () { doRename(rel, name, type); } });
        items.push({ icon: '↪️', label: 'Přesunout do…', fn: function () { movePicker(rel); } });
        items.push(null);
        items.push({ icon: '🗑️', label: 'Přesunout do koše', danger: true, fn: function () { doDelete(rel, name); } });
      }
      ctxShow(items, x, y);
    }
    // context menu on empty area
    function bgMenu(x, y) {
      if (!perms.write) return;
      ctxShow([
        { icon: '📁', label: 'Nová složka', fn: newFolder },
        { icon: '⬆️', label: 'Nahrát soubory', fn: function () { var u = mount.querySelector('#fbUp'); if (u) u.click(); } }
      ], x, y);
    }
    // choose a destination folder for "Přesunout do…"
    function movePicker(rel) {
      var dests = [];
      if (cur !== '') dests.push({ icon: '⬆️', label: 'O úroveň výš', to: cur.split('/').slice(0, -1).join('/') });
      curItems.forEach(function (it) { if (it.type === 'dir' && it.rel !== rel) dests.push({ icon: '📁', label: it.name, to: it.rel }); });
      if (!dests.length) { toast('Není kam přesunout (žádná podsložka)', 'err'); return; }
      ctxShow(dests.map(function (d) { return { icon: d.icon, label: d.label, fn: function () { doMove(rel, d.to); } }; }), ctxX, ctxY);
    }

    /* ---- drag & drop + chunked upload (files AND whole folders) ---- */
    function joinRel(a, b) { return a ? (b ? a + '/' + b : a) : b; }

    function setupDrop() {
      if (!perms.write) return;
      // Drive-style: drop files ANYWHERE on the page (not just the list).
      var ov = document.getElementById('fbDropFull');
      if (!ov) {
        ov = document.createElement('div');
        ov.id = 'fbDropFull';
        ov.innerHTML = '<div class="fbdf-box"><div class="fbdf-ic">📥</div><b>Pusť soubory pro nahrání</b><div class="small">i celé složky</div></div>';
        document.body.appendChild(ov);
      }
      var depth = 0;
      function hasFiles(e) { var dt = e.dataTransfer; return dt && dt.types && Array.prototype.indexOf.call(dt.types, 'Files') >= 0; }
      function active() { return document.contains(mount) && !dragRel; } // this instance, not an internal move
      document.addEventListener('dragenter', function (e) { if (!active() || !hasFiles(e)) return; e.preventDefault(); depth++; ov.classList.add('on'); });
      document.addEventListener('dragover', function (e) { if (!active() || !hasFiles(e)) return; e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'; });
      document.addEventListener('dragleave', function (e) { if (!active()) return; depth = Math.max(0, depth - 1); if (depth === 0) ov.classList.remove('on'); });
      document.addEventListener('drop', function (e) {
        if (!active()) return;
        depth = 0; ov.classList.remove('on');
        if (!hasFiles(e)) return;
        e.preventDefault();
        var dt = e.dataTransfer; if (!dt) return;
        collectFromDrop(dt).then(function (res) { doUpload(res.dirs, res.items); });
      });
    }

    // Read a dropped tree (files + nested folders) via the Entries API.
    function collectFromDrop(dt) {
      var entries = [];
      if (dt.items && dt.items.length && dt.items[0].webkitGetAsEntry) {
        for (var i = 0; i < dt.items.length; i++) { var en = dt.items[i].webkitGetAsEntry(); if (en) entries.push(en); }
      }
      if (!entries.length) { // browser without Entries API: flat files only
        var flat = [];
        if (dt.files) for (var j = 0; j < dt.files.length; j++) flat.push({ file: dt.files[j], rel: '' });
        return Promise.resolve({ dirs: [], items: flat });
      }
      var dirs = [], out = [], p = Promise.resolve();
      entries.forEach(function (en) { p = p.then(function () { return walkEntry(en, '', dirs, out); }); });
      return p.then(function () { return { dirs: dirs, items: out }; });
    }

    function walkEntry(entry, basePath, dirs, out) {
      return new Promise(function (resolve) {
        if (entry.isFile) {
          entry.file(function (f) { out.push({ file: f, rel: basePath }); resolve(); }, function () { resolve(); });
        } else if (entry.isDirectory) {
          var dirRel = basePath ? basePath + '/' + entry.name : entry.name;
          dirs.push(dirRel);
          var reader = entry.createReader(), acc = [];
          (function readBatch() {
            reader.readEntries(function (batch) {
              if (!batch || !batch.length) {
                var q = Promise.resolve();
                acc.forEach(function (ch) { q = q.then(function () { return walkEntry(ch, dirRel, dirs, out); }); });
                q.then(resolve);
              } else { acc = acc.concat(Array.prototype.slice.call(batch)); readBatch(); }
            }, function () { resolve(); });
          })();
        } else resolve();
      });
    }

    // From an <input>: plain files, or a folder picked via webkitdirectory.
    function handleInputFiles(fileList, useWebkitPath) {
      var arr = Array.prototype.slice.call(fileList || []);
      var dirs = [], items = [], seen = {};
      arr.forEach(function (f) {
        var rel = '';
        if (useWebkitPath && f.webkitRelativePath) {
          var parts = f.webkitRelativePath.split('/'); parts.pop(); // drop the filename
          rel = parts.join('/');
          var acc = '';
          parts.forEach(function (seg) { acc = acc ? acc + '/' + seg : seg; if (!seen[acc]) { seen[acc] = 1; dirs.push(acc); } });
        }
        items.push({ file: f, rel: rel });
      });
      doUpload(dirs, items);
    }

    function doUpload(dirs, items) {
      dirs = (dirs || []).slice().sort(function (a, b) { return a.split('/').length - b.split('/').length; });
      items = items || [];
      if (!dirs.length && !items.length) { toast('Nic k nahrání', 'err'); return; }
      var prog = mount.querySelector('#fbProg'), bar = mount.querySelector('#fbBar'), txt = mount.querySelector('#fbProgTxt');
      if (prog) prog.style.display = 'block';
      if (bar) bar.style.width = '0';

      var di = 0, fi = 0, fails = 0;
      function mkNext() {
        if (di >= dirs.length) { uploadNext(); return; }
        var full = joinRel(cur, dirs[di]); di++;
        if (txt) txt.textContent = 'Vytvářím složky… (' + di + '/' + dirs.length + ')';
        api('mkdirp', { rel: full }).then(mkNext, mkNext); // idempotent; ignore per-dir errors
      }
      function uploadNext() {
        if (fi >= items.length) {
          if (prog) prog.style.display = 'none';
          if (txt) txt.textContent = '';
          load(); // refresh listing so partial results are visible
          if (fails) toast('Hotovo, ale ' + fails + ' soubor(ů) selhalo', 'err');
          else toast('Nahrávání dokončeno', 'ok');
          return;
        }
        var it = items[fi];
        uploadOne(it.file, joinRel(cur, it.rel),
          function (ok, msg) {
            if (!ok) fails++; // keep going with the rest of the queue
            fi++;
            if (bar) bar.style.width = Math.round(fi / items.length * 100) + '%';
            uploadNext();
          },
          function (pct, name) {
            if (txt) txt.textContent = 'Nahrávám ' + name + ' — ' + pct + '% (' + (fi + 1) + '/' + items.length + ')';
            if (bar && items.length) bar.style.width = Math.round((fi + pct / 100) / items.length * 100) + '%';
          });
      }
      mkNext();
    }

    function uploadOne(file, targetRel, done, onprog) {
      var total = Math.max(1, Math.ceil(file.size / CHUNK)), c = 0;
      (function nextChunk() {
        if (c >= total) { done(true); return; }
        var blob = file.slice(c * CHUNK, Math.min((c + 1) * CHUNK, file.size));
        var url = opts.uploadUrl + '?share=' + encodeURIComponent(opts.share) +
          '&rel=' + encodeURIComponent(targetRel) + '&name=' + encodeURIComponent(file.name) +
          '&chunk=' + c + '&total=' + total;
        fetch(url, { method: 'POST', headers: { 'X-CSRF': opts.csrf }, credentials: 'same-origin', body: blob })
          .then(function (r) { return r.json(); })
          .then(function (d) { if (!d.ok) throw new Error(d.msg || 'chyba'); c++; if (onprog) onprog(Math.round(c / total * 100), file.name); nextChunk(); })
          .catch(function (e) { done(false, e.message); });
      })();
    }

    // keyboard: Ctrl/⌘+A select all visible · Esc clear · Delete remove selected
    document.addEventListener('keydown', function (e) {
      if (!document.contains(mount)) return; // ignore stale instances (folder/share switched away)
      var ae = document.activeElement;
      // don't hijack typing — but a focused checkbox/radio must NOT block Delete/F2 etc.
      if (ae && (ae.tagName === 'TEXTAREA' || ae.tagName === 'SELECT' || ae.isContentEditable ||
                 (ae.tagName === 'INPUT' && ae.type !== 'checkbox' && ae.type !== 'radio'))) return;
      if ((e.ctrlKey || e.metaKey) && (e.key === 'a' || e.key === 'A')) {
        if (!shown.length) return;
        e.preventDefault(); selected = {}; shown.forEach(function (it) { selected[it.rel] = true; }); refreshSel();
      } else if (e.key === 'Escape') {
        if (Object.keys(selected).length) { selected = {}; refreshSel(); }
      } else if (e.key === 'Delete' && perms.write) {
        if (Object.keys(selected).length) { e.preventDefault(); bulkDelete(); }
      } else if (e.key === 'F2' && perms.write) {
        var sel = Object.keys(selected);
        if (sel.length === 1) { var it = shown.filter(function (x) { return x.rel === sel[0]; })[0]; if (it) { e.preventDefault(); doRename(it.rel, it.name, it.type); } }
      } else if (e.key === 'Enter') {
        var s2 = Object.keys(selected);
        if (s2.length === 1) { var it2 = shown.filter(function (x) { return x.rel === s2[0]; })[0]; if (it2) { e.preventDefault(); openItemRow(it2.type, it2.rel, it2._midx != null ? it2._midx : null); } }
      }
    });

    render();
    return { reload: load };
  }

  global.initFileBrowser = initFileBrowser;
  global.pzcMenu = ctxShow;       // reusable dropdown menu (also used by the admin shares list)
  global.pzcMenuHide = ctxHide;
})(window);
