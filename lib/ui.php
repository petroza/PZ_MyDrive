<?php
declare(strict_types=1);

/*
 * MyDrive — ui.php
 * Page chrome + theme, inlined so it works from any folder with no external
 * stylesheet. Visual language follows Google Drive / myDrive: white surfaces,
 * a calm blue accent, a fixed left rail with a prominent "New" button, rounded
 * cards, soft shadows. Fully responsive. All the .fb-* / .fl-* / .gtile classes
 * the shared filebrowser.js depends on are defined here, restyled to match.
 */

require_once __DIR__ . '/bootstrap.php';

function pzc_head(string $title, bool $compact = false): void {
    $site = pzc_e(pzc_cfg('site_name', 'MyDrive'));
    $t = pzc_e($title);
    echo "<!doctype html><html lang=\"cs\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1,viewport-fit=cover\">";
    echo "<meta name=\"color-scheme\" content=\"light\">";
    echo "<meta name=\"theme-color\" content=\"#ffffff\">";
    echo "<meta name=\"robots\" content=\"noindex,nofollow\">";
    echo "<title>$t · $site</title><style>" . pzc_css() . "</style></head>";
    echo "<body class=\"" . ($compact ? 'compact' : '') . "\">";
}

function pzc_foot(): void {
    echo "</body></html>";
}

/** Inline SVG glyphs for the sidebar (kept tiny, currentColor-tinted). */
function pzc_ic(string $name): string {
    $p = array(
        'drive'   => '<path d="M3 5h6l2 3h10v11H3z"/>',
        'recent'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'star'    => '<path d="M12 3l2.6 5.6 6 .7-4.4 4.1 1.2 6L12 16.8 6.6 19.4l1.2-6L3.4 9.3l6-.7z"/>',
        'trash'   => '<path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'cloud'   => '<path d="M7 18a4 4 0 010-8 5 5 0 019.6-1.3A3.5 3.5 0 0117 18z"/>',
        'logout'  => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
        'cog'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.6 1.6 0 00-1.8-.3 1.6 1.6 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.6 1.6 0 00-1-1.5 1.6 1.6 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.6 1.6 0 00.3-1.8 1.6 1.6 0 00-1.5-1H3a2 2 0 110-4h.1a1.6 1.6 0 001.5-1 1.6 1.6 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.6 1.6 0 001.8.3H9a1.6 1.6 0 001-1.5V3a2 2 0 114 0v.1a1.6 1.6 0 001 1.5 1.6 1.6 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.6 1.6 0 00-.3 1.8V9a1.6 1.6 0 001.5 1H21a2 2 0 110 4h-.1a1.6 1.6 0 00-1.5 1z"/>',
    );
    $d = $p[$name] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
         . 'stroke-linecap="round" stroke-linejoin="round" width="20" height="20">' . $d . '</svg>';
}

function pzc_css(): string {
    return <<<CSS
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f6f8fc;--sf:#ffffff;--sf2:#f1f5fb;--sf3:#e8eef7;
  --bd:#e3e8f0;--bd2:#d3dae6;
  --tx:#1f2733;--tx2:#5b6573;--tx3:#717c8c;
  --ac:#1a73e8;--ac2:#1862c6;--acw:#e8f0fe;
  --grn:#137333;--red:#d23b2f;--amb:#e8910c;--vio:#7c3aed;
  --f:'Inter','DM Sans',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  --sh:0 1px 2px rgba(16,24,40,.05),0 4px 14px rgba(16,24,40,.05);
  --sh-lg:0 12px 42px rgba(16,24,40,.14);
}
html{scrollbar-gutter:stable}
html,body{background:var(--bg);color:var(--tx);font-family:var(--f);font-size:15px;min-height:100vh;-webkit-text-size-adjust:100%}
body{min-height:100dvh}
body.compact{display:flex;align-items:center;justify-content:center;padding:24px;
  background:radial-gradient(1100px 520px at 50% -10%,#e9f1ff 0%,var(--bg) 60%)}
a{color:var(--ac);text-decoration:none}a:hover{text-decoration:underline}
.lnk{color:var(--ac);cursor:pointer;font-weight:600}.lnk:hover{text-decoration:underline}
.muted{color:var(--tx2)}.small{font-size:.8rem}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
img{max-width:100%}

/* ---------------- login / setup cards ---------------- */
.wrap{width:min(440px,100%)}
.card{background:var(--sf);border:1px solid var(--bd);border-radius:18px;padding:30px;box-shadow:var(--sh-lg)}
.brandline{display:flex;align-items:center;gap:.6rem;margin-bottom:18px;color:var(--ac);font-weight:800;letter-spacing:-.02em;font-size:1.15rem}
.brandline svg{color:var(--ac)}
h1{font-size:22px;margin-bottom:6px;letter-spacing:-.01em}h2{font-size:16px;margin-bottom:10px;letter-spacing:-.01em}
.sub{color:var(--tx2);font-size:13px;margin-bottom:20px;line-height:1.55}
label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--tx2);margin:14px 0 6px;font-weight:700}
input,select,textarea{width:100%;border:1px solid var(--bd2);background:#fff;color:var(--tx);border-radius:10px;padding:12px 13px;font-size:15px;outline:none;font-family:var(--f);transition:border-color .15s,box-shadow .15s}
input::placeholder{color:var(--tx3)}
input:focus,select:focus,textarea:focus{border-color:var(--ac);box-shadow:0 0 0 3px rgba(26,115,232,.16)}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:.45rem;border:1px solid var(--bd2);background:#fff;color:var(--tx);border-radius:9px;padding:9px 15px;font-size:.84rem;font-weight:600;cursor:pointer;font-family:var(--f);transition:.15s;text-decoration:none;line-height:1.2}
.btn:hover{border-color:var(--tx3);background:var(--sf2);text-decoration:none}
.btn:active{transform:translateY(1px)}
.btn:focus-visible,.dr-ni:focus-visible,.dr-new:focus-visible,.kebab:focus-visible,.hcol:focus-visible,.ctxitem:focus-visible{outline:2px solid var(--ac);outline-offset:2px}
.btn-p{background:var(--ac);color:#fff;border-color:var(--ac)}.btn-p:hover{background:var(--ac2);border-color:var(--ac2)}
.btn-g{background:var(--grn);color:#fff;border-color:var(--grn)}.btn-g:hover{filter:brightness(.95)}
.btn-d{color:var(--red);background:#fff;border-color:var(--bd2)}.btn-d:hover{border-color:var(--red);background:#fef3f2}
.btn-sm{padding:7px 12px;font-size:.76rem;border-radius:8px}
.btn-block{width:100%;margin-top:20px;padding:12px}
.btn[disabled]{cursor:not-allowed;opacity:.6}
.err{background:#fef3f2;border:1px solid #f6cfca;color:#b3261e;border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:14px}
.ok{background:#eaf6ee;border:1px solid #bfe3c8;color:var(--grn);border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:14px}
.info{background:var(--acw);border:1px solid #c5dbfb;color:var(--ac2);border-radius:10px;padding:10px 12px;font-size:13px;margin-bottom:14px}
.hint{font-size:13px;color:var(--tx3);margin-top:6px;line-height:1.55}
.row{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}.right{margin-left:auto}

/* ======================= Drive shell ======================= */
.dr-app{display:flex;min-height:100vh;min-height:100dvh}
.dr-side{width:256px;min-width:256px;background:var(--sf);border-right:1px solid var(--bd);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;height:100dvh}
.dr-brand{display:flex;align-items:center;gap:.6rem;padding:1.05rem 1.25rem;font-weight:800;font-size:1.2rem;letter-spacing:-.02em;color:var(--tx)}
.dr-brand .lg{color:var(--ac);display:flex}
.dr-brand b{font-weight:800}.dr-brand b span{color:var(--ac)}
.dr-new-wrap{padding:.35rem 1rem .9rem}
.dr-new{display:inline-flex;align-items:center;gap:.55rem;background:#fff;color:var(--tx);border:1px solid var(--bd2);border-radius:16px;padding:.7rem 1.25rem .7rem .95rem;font-weight:600;font-size:.92rem;cursor:pointer;box-shadow:var(--sh);font-family:var(--f);transition:.15s}
.dr-new:hover{box-shadow:0 1px 3px rgba(16,24,40,.12),0 8px 20px rgba(26,115,232,.16);background:#fff}
.dr-new .pl{font-size:1.3rem;line-height:1;color:var(--ac);font-weight:400;margin-top:-2px}
.dr-nav{flex:1;padding:.25rem .55rem;overflow:auto}
.dr-ni{display:flex;align-items:center;gap:.85rem;padding:.62rem .95rem;color:var(--tx2);cursor:pointer;border-radius:0 22px 22px 0;font-size:.9rem;font-weight:600;margin-bottom:2px;width:100%;border:none;background:none;font-family:var(--f);text-align:left;transition:background-color .12s ease,color .12s ease}
.dr-ni svg{color:var(--tx3);flex:none}
.dr-ni:hover{background:var(--sf2);color:var(--tx)}
.dr-ni:hover svg{color:var(--tx2)}
.dr-ni.act{background:var(--acw);color:var(--ac2)}
.dr-ni.act svg{color:var(--ac2)}
.dr-meter{padding:1rem 1.25rem;border-top:1px solid var(--bd);color:var(--tx2);font-size:.78rem}
.dr-meter .track{height:6px;border-radius:6px;background:var(--sf3);overflow:hidden;margin:.55rem 0 .45rem}
.dr-meter .fill{height:100%;background:var(--ac);border-radius:6px;transition:width .4s ease}
.dr-meter .fill.warn{background:linear-gradient(90deg,var(--amb),#f7b955)}
.dr-meter .fill.full{background:linear-gradient(90deg,var(--red),#f0746a)}

.dr-main{flex:1;min-width:0;display:flex;flex-direction:column}
.dr-top{position:sticky;top:0;z-index:30;background:var(--bg);padding:.85rem 1.4rem;display:flex;align-items:center;gap:1rem}
.dr-srch{flex:1;max-width:680px;position:relative;display:flex;align-items:center}
.dr-srch svg{position:absolute;left:14px;color:var(--tx2);pointer-events:none}
.dr-srch input{background:var(--sf3);border:1px solid transparent;border-radius:24px;padding:.7rem 1rem .7rem 2.7rem;font-size:.95rem;width:100%;transition:background-color .15s ease,box-shadow .15s ease,border-color .15s ease}
.dr-srch input:focus{background:#fff;border-color:transparent;box-shadow:0 1px 3px rgba(16,24,40,.12),0 4px 14px rgba(16,24,40,.08)}
.dr-acct{margin-left:auto;display:flex;align-items:center;gap:.7rem}
.dr-avatar{width:38px;height:38px;border-radius:50%;background:var(--sf3);color:var(--tx);border:1.5px solid var(--bd2);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex:none}
.dr-acct .who{font-size:.82rem;color:var(--tx2);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.dr-content{padding:.4rem 1.4rem 2rem;max-width:1280px;width:100%}
.dr-surface{background:var(--sf);border:1px solid var(--bd);border-radius:16px;padding:1.15rem 1.25rem;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.dr-h{display:flex;align-items:center;gap:.7rem;margin:.2rem 0 1rem}
.dr-h .t{font-size:1.32rem;font-weight:700;letter-spacing:-.02em}
.dr-h .sub{margin:0;color:var(--tx3);font-size:.85rem}
.dr-surface.bare{background:none;border:none;box-shadow:none;padding:0}
.adm-card{background:var(--sf);border:1px solid var(--bd);border-radius:14px;padding:1.1rem 1.25rem;box-shadow:0 1px 2px rgba(16,24,40,.04);margin-bottom:1rem}
.adm-card h3{font-size:1.02rem;font-weight:700;letter-spacing:-.01em;margin-bottom:.8rem}
.adm-card input{max-width:420px}

/* ======================= file browser ======================= */
.fb-bar{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.85rem;min-height:42px}
.crumb{font-size:1rem;color:var(--tx2);font-weight:600}.crumb a{color:var(--tx);font-weight:600}.crumb b{color:var(--tx)}
.crumb a:hover{color:var(--ac)}
.fl{border:1px solid transparent;border-radius:12px;overflow:hidden;background:var(--sf)}
.fl-row{display:grid;grid-template-columns:28px 46px 1fr 110px 170px 44px;align-items:center;gap:.7rem;padding:.6rem .85rem;border-bottom:1px solid var(--bd);transition:background-color .12s ease}
.fl-row .ck,.fl-head .ck{width:17px;height:17px;cursor:pointer;accent-color:var(--ac)}
.fl-head{display:grid;grid-template-columns:28px 46px 1fr 110px 170px 44px;align-items:center;gap:.7rem;padding:.45rem .85rem;border-bottom:1px solid var(--bd2);background:var(--sf)}
.fl-head .hcol{font-size:.72rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:var(--tx2);cursor:pointer;user-select:none;white-space:nowrap;transition:color .12s ease}
.fl-head .hcol:hover{color:var(--ac)}.fl-head .hcol.on{color:var(--ac2)}
.rn-input{width:100%;font:inherit;font-size:.9rem;padding:3px 7px;border:1px solid var(--ac);border-radius:7px;background:#fff;color:var(--tx)}
.fb-toolswrap{position:relative;margin-bottom:.85rem;min-height:44px}
.fb-toolswrap .fb-bar{margin-bottom:0}
.fb-sel{position:absolute;inset:0;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;background:var(--acw);border:1px solid #c5dbfb;border-radius:12px;padding:.3rem .75rem;margin:0;z-index:8}
.fb-sel b{color:var(--ac2);font-size:.92rem}
.fb-sel .selx{flex:none;width:30px;height:30px;border-radius:50%;border:none;background:transparent;color:var(--ac2);font-size:1rem;cursor:pointer;line-height:1}
.fb-sel .selx:hover{background:rgba(26,115,232,.16)}
.fb-sel .selacts{margin-left:auto;display:flex;gap:.4rem;flex-wrap:wrap}
.fl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.85rem}
.gtile{position:relative;border:1px solid var(--bd);border-radius:12px;background:var(--sf);padding:.55rem;cursor:pointer;transition:box-shadow .15s ease,border-color .15s ease,transform .15s ease}
.gtile:hover{border-color:var(--bd2);box-shadow:var(--sh);transform:translateY(-1px)}
.gtile.sel{border-color:var(--ac);background:var(--acw);box-shadow:0 0 0 1px var(--ac)}
.gck{position:absolute;top:9px;left:9px;z-index:3;width:18px;height:18px;accent-color:var(--ac);cursor:pointer;opacity:0;transition:opacity .1s}
.gtile:hover .gck,.gtile.sel .gck{opacity:1}
.gkebab{position:absolute;top:6px;right:6px;z-index:3;opacity:0;background:rgba(255,255,255,.92);border-radius:8px}
.gtile:hover .gkebab{opacity:1}
@media (hover:none){.gck,.gkebab{opacity:1}}
.gthumb{width:100%;aspect-ratio:1/1;border-radius:10px;background:var(--sf2);display:flex;align-items:center;justify-content:center;font-size:2.5rem;overflow:hidden}
.gthumb img,.gthumb video{width:100%;height:100%;object-fit:cover}
.gname{font-size:.8rem;margin-top:.5rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--tx);text-align:center;font-weight:500}
.kebab{border:1px solid transparent;background:none;color:var(--tx2);font-size:1.15rem;line-height:1;cursor:pointer;border-radius:8px;padding:3px 9px}
.kebab:hover{background:var(--sf2);color:var(--tx)}
.ctxmenu{position:fixed;z-index:600;background:var(--sf);border:1px solid var(--bd2);border-radius:13px;box-shadow:var(--sh-lg);padding:7px;min-width:218px;max-height:80vh;overflow:auto}
.ctxitem{display:flex;align-items:center;gap:11px;width:100%;text-align:left;background:none;border:none;padding:9px 12px;font-size:.88rem;color:var(--tx);border-radius:9px;cursor:pointer;font-family:var(--f);white-space:nowrap}
.ctxitem .ci{flex:none;width:1.25em;text-align:center;font-size:1em;line-height:1}
.ctxitem .cl{flex:1;overflow:hidden;text-overflow:ellipsis}
.ctxitem:hover{background:var(--sf2)}
.ctxitem.danger{color:var(--red)}.ctxitem.danger:hover{background:#fef3f2}
.ctxsep{height:1px;background:var(--bd);margin:5px 8px}
.fl-row.drop-into,.gtile.drop-into{outline:2px solid var(--ac);outline-offset:-2px;background:rgba(26,115,232,.07)}
.fl-row:last-child{border-bottom:none}
.fl-row:hover{background:var(--sf2)}
.fl-row.sel{background:#eef4fe}.fl-row.sel:hover{background:#e3edfd}
.fl-row:focus-visible,.gtile:focus-visible{outline:2px solid var(--ac);outline-offset:-2px;border-radius:8px}
.fl-row{user-select:none}
.fl-row .nm{font-size:.92rem;font-weight:500;display:flex;align-items:center;gap:9px;min-width:0}
.fl-row .nm .nmt{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fl-row .nm.dir,.fl-row .nm.media{cursor:pointer;color:var(--tx)}
.fl-row .nm.dir:hover,.fl-row .nm.media:hover{color:var(--ac)}
.fl-row .sz,.fl-row .dt{font-size:.8rem;color:var(--tx2);font-variant-numeric:tabular-nums}
.fl-row .ic{font-size:1.3rem;text-align:center}
.shdot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#5cd07a;box-shadow:0 0 0 2px rgba(92,208,122,.22);vertical-align:middle;flex:none}
.shdot.off{background:transparent;box-shadow:none}
.fl-empty{padding:3.25rem 1rem;text-align:center;color:var(--tx2);font-size:.9rem}
.fl-empty .em{font-size:2.4rem;display:block;margin-bottom:.65rem;opacity:.55}
.thumb-wrap{position:relative;width:42px;height:42px}
.thumb{width:42px;height:42px;object-fit:cover;border-radius:8px;display:block;background:var(--sf3);cursor:pointer;border:1px solid var(--bd)}
.thumb-play{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:20px;height:20px;border-radius:50%;background:rgba(0,0,0,.55);color:#fff;font-size:9px;display:flex;align-items:center;justify-content:center;pointer-events:none}
.fb-area{position:relative}
.fb-drop-ov{position:absolute;inset:0;display:none;align-items:center;justify-content:center;text-align:center;background:rgba(26,115,232,.08);border:2px dashed var(--ac);border-radius:14px;z-index:6;color:var(--ac2);font-weight:700;pointer-events:none;backdrop-filter:blur(1px)}
.fb-area.over .fb-drop-ov{display:flex}
.prog{height:10px;background:var(--sf3);border-radius:7px;overflow:hidden;margin:.2rem 0 .6rem;display:none;position:relative}
.prog .bar{height:100%;width:0;background:linear-gradient(90deg,#34c759,#1e9e4a);transition:width .2s;box-shadow:0 0 8px rgba(52,199,89,.4)}

/* modal + toast */
.modal{position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(3px);display:none;align-items:center;justify-content:center;padding:1rem;z-index:200}
.modal.open{display:flex}
.modal .box{background:var(--sf);border:1px solid var(--bd);border-radius:16px;width:min(460px,100%);max-height:90vh;overflow:auto;padding:1.4rem;box-shadow:var(--sh-lg)}
.toast{position:fixed;bottom:1.4rem;right:1.4rem;background:#202733;color:#fff;border-radius:11px;padding:.75rem 1.15rem;font-size:.85rem;z-index:999;opacity:0;transform:translateY(40px);transition:.25s;display:flex;align-items:center;gap:.55rem;box-shadow:var(--sh-lg);max-width:calc(100vw - 2rem)}
.toast.show{opacity:1;transform:none}.toast .dot{width:8px;height:8px;border-radius:50%;background:#5b9bff;flex-shrink:0}
.toast.ok .dot{background:#34d27b}.toast.err .dot{background:#ff7a6e}

/* fullscreen media preview */
.pv{display:none;position:fixed;inset:0;background:rgba(10,12,18,.95);z-index:500;align-items:center;justify-content:center}
.pv.open{display:flex}
.pv-bar{position:fixed;top:0;left:0;right:0;display:flex;align-items:center;gap:.6rem;padding:.7rem .9rem;padding-top:calc(.7rem + env(safe-area-inset-top));background:linear-gradient(rgba(0,0,0,.55),transparent);z-index:2}
.pv-bar .btn{background:rgba(255,255,255,.16);color:#fff;border-color:transparent}
.pv-bar .btn:hover{background:rgba(255,255,255,.28)}
.pv-name{color:#fff;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:46vw}
.pv-sp{flex:1}
.pv-stage{display:flex;align-items:center;justify-content:center;max-width:94vw;max-height:84vh}
.pv-stage img,.pv-stage video{max-width:94vw;max-height:84vh;border-radius:8px;background:#000}
.pv-nav{position:fixed;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.16);color:#fff;border:none;font-size:1.8rem;line-height:1;width:46px;height:62px;border-radius:12px;cursor:pointer}
.pv-nav:hover{background:rgba(255,255,255,.28)}
.pv-prev{left:14px}.pv-next{right:14px}

/* tree view */
.fl-tree{border:1px solid var(--bd);border-radius:12px;background:var(--sf);padding:.4rem .3rem;min-height:60px}
.tkids{margin-left:16px;border-left:1px solid var(--bd);padding-left:2px}
.troot{margin-left:0;border-left:none;padding-left:0}
.trow{display:flex;align-items:center;gap:.45rem;padding:.34rem .5rem;border-radius:8px;cursor:pointer;white-space:nowrap;transition:background-color .12s ease}
.trow:hover{background:var(--sf2)}
.trow.shared{background:#eefaf0}
.ttog{width:16px;text-align:center;color:var(--tx2);font-size:.65rem;flex:none;border-radius:4px;user-select:none}
.ttog:hover{background:var(--sf3)}
.tic{font-size:1.05rem;flex:none}
.tname{overflow:hidden;text-overflow:ellipsis;font-size:.9rem;font-weight:500}
.tload,.tempty{padding:.3rem .6rem;font-size:.78rem}
[data-theme=dark] .trow.shared{background:#15271b}

/* back button + shared-row tint + animal avatar */
#fbBack{font-size:1.1rem;line-height:1;padding:6px 12px;font-weight:600}
.fl-row.shared{background:#eefaf0}.fl-row.shared:hover{background:#e3f6e8}
.fl-row.shared.sel{background:#eef4fe}
.gtile.shared{border-color:#bfe3c8;background:#f4fbf6}
.dr-avatar{font-size:1.25rem}
.lanim{font-size:1.05rem}

/* admin: transfer statistics */
.strow{display:flex;gap:.8rem;flex-wrap:wrap;margin-bottom:1rem}
.stbox{flex:1;min-width:170px;background:var(--sf2);border:1px solid var(--bd);border-radius:12px;padding:.7rem .95rem}
.stk{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--tx2);font-weight:700}
.stv{font-size:1.02rem;font-weight:700;margin-top:.25rem}
.sthd{font-size:.74rem;text-transform:uppercase;letter-spacing:.04em;color:var(--tx2);font-weight:700;margin:1.1rem 0 .55rem}
.stbars{display:flex;align-items:flex-end;gap:6px;height:118px}
.stcol{flex:1;display:flex;flex-direction:column;align-items:center;min-width:0;height:100%}
.stbw{flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:3px}
.stb{width:7px;border-radius:3px 3px 0 0;min-height:2px;transition:height .3s}
.stb.dn{background:var(--ac)}.stb.up{background:#34c759}
.stlab{font-size:.6rem;color:var(--tx3);margin-top:5px;white-space:nowrap}
.stleg{font-size:.76rem;color:var(--tx2);margin-top:.6rem}
.stdot{display:inline-block;width:10px;height:10px;border-radius:3px;vertical-align:middle}
.stdot.dn{background:var(--ac)}.stdot.up{background:#34c759}
.actlist{display:flex;flex-direction:column;margin-top:.2rem}
.actrow{display:grid;grid-template-columns:26px 18px 1fr auto auto;gap:.55rem;align-items:center;padding:.4rem .2rem;border-bottom:1px solid var(--bd);font-size:.83rem}
.actrow:last-child{border-bottom:none}
.acta{font-size:1.05rem;text-align:center}.actd.up{color:#1e9e4a}.actd.dn{color:var(--ac)}
.actn{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.acts{color:var(--tx2);font-size:.76rem;font-variant-numeric:tabular-nums}
.actt{color:var(--tx3);font-size:.72rem;white-space:nowrap}

/* ================= dark theme ================= */
[data-theme=dark]{
  --bg:#0f1216;--sf:#181c23;--sf2:#212732;--sf3:#2a3038;
  --bd:#2b313b;--bd2:#3a4250;
  --tx:#e7ebf1;--tx2:#aab3c0;--tx3:#828c9a;
  --ac:#5b9bff;--ac2:#7db0ff;--acw:#172740;
  --grn:#3ecf72;--red:#ff6b5e;
  --sh:0 1px 2px rgba(0,0,0,.4),0 4px 14px rgba(0,0,0,.35);
  --sh-lg:0 12px 42px rgba(0,0,0,.55);
}
[data-theme=dark] input,[data-theme=dark] select,[data-theme=dark] textarea{background:var(--sf3);color:var(--tx);border-color:var(--bd2)}
[data-theme=dark] .dr-new{background:var(--sf2);color:var(--tx);border-color:var(--bd2)}
[data-theme=dark] .dr-new:hover{background:var(--sf3)}
[data-theme=dark] .btn{background:var(--sf2);color:var(--tx);border-color:var(--bd2)}
[data-theme=dark] .btn:hover{background:var(--sf3);border-color:var(--tx3)}
[data-theme=dark] .btn-p{background:var(--ac);color:#0b1220;border-color:var(--ac)}
[data-theme=dark] .btn-p:hover{background:var(--ac2);border-color:var(--ac2)}
[data-theme=dark] .btn-d{background:var(--sf2);color:#ff8a7e}
[data-theme=dark] .dr-srch input:focus{background:var(--sf)}
[data-theme=dark] .rn-input{background:var(--sf3)}
[data-theme=dark] .dr-top{background:var(--bg)}
[data-theme=dark] .fl-row.sel{background:var(--acw)}[data-theme=dark] .fl-row.sel:hover{background:#1f3354}
[data-theme=dark] .fl-row.shared{background:#15271b}[data-theme=dark] .fl-row.shared:hover{background:#1a3020}
[data-theme=dark] .fl-row.shared.sel{background:var(--acw)}
[data-theme=dark] .gtile.shared{background:#15271b;border-color:#2e5238}
[data-theme=dark] .gkebab{background:rgba(24,28,35,.92)}
[data-theme=dark] .err{background:#371d1b;border-color:#5e2b27;color:#ffb4ac}
[data-theme=dark] .ok{background:#123020;border-color:#1f5236;color:#86e0a8}
[data-theme=dark] .info{background:var(--acw);border-color:#27406a;color:#bcd4ff}
[data-theme=dark] .toast{background:#05070a}

/* ---------------- mobile ---------------- */
@media(max-width:860px){
  .dr-app{flex-direction:column}
  .dr-side{width:100%;min-width:0;height:auto;position:sticky;top:0;flex-direction:column;box-shadow:var(--sh);z-index:40}
  .dr-brand{padding:.7rem 1rem}
  .dr-new-wrap{display:none}
  .dr-nav{display:flex;flex-direction:row;gap:.3rem;padding:.4rem .6rem;border-top:1px solid var(--bd);overflow-x:auto;-webkit-overflow-scrolling:touch}
  .dr-ni{margin-bottom:0;white-space:nowrap;width:auto;border-radius:20px;padding:.5rem .85rem;font-size:.82rem}
  .dr-ni svg{display:none}
  .dr-meter{display:none}
  .dr-top{padding:.7rem 1rem;flex-wrap:wrap}
  .dr-content{padding:.4rem 1rem 2rem}
  .dr-surface{padding:.9rem;border-radius:14px}
  input,select,textarea{font-size:16px}
  .btn-sm{padding:9px 12px;font-size:.78rem}
  .fl-row{grid-template-columns:28px 46px 1fr 44px;gap:.55rem;padding:.7rem .6rem}
  .fl-row .sz,.fl-row .dt{display:none}
  .fl-head{grid-template-columns:28px 46px 1fr 44px;gap:.55rem;padding:.4rem .6rem}
  .fl-head .hcol[data-sort="size"],.fl-head .hcol[data-sort="mtime"],.fl-head .hcol[data-sort="ctime"]{display:none}
  .kebab{padding:8px 11px}
  .pv-name{max-width:55vw}.pv-nav{width:40px;height:56px;font-size:1.5rem}
}
@media(max-width:380px){.card{padding:22px}.fl-grid{grid-template-columns:repeat(auto-fill,minmax(120px,1fr))}}
CSS;
}
