/**
 * KOPHI'S MUSIC STUDIO — Lecteur unifié (v4.8.0)
 * ------------------------------------------------------------------
 * Un seul lecteur pour tout le site public (boutique beats, beats de la page
 * d'accueil, page d'écoute client) : une seule balise <audio>, une barre de
 * lecture fixe en bas d'écran, enchaînement des titres, commandes clavier et
 * écran verrouillé (Media Session).
 *
 * Balisage attendu (aucun onclick nécessaire) :
 *   <div data-kp-id="12" data-kp-url="…mp3" data-kp-title="Titre"
 *        data-kp-sub="120 BPM" data-kp-cover="…jpg" [data-kp-fallback="https://wa.me/…"]>
 *     <button data-kp-play>…</button>           ← lecture / pause de ce titre
 *     <span class="kp-cur"></span> <span class="kp-dur"></span>   (facultatif)
 *   </div>
 * États posés sur la carte : .kp-current, .kp-playing, .kp-loading, --kp-progress.
 */
(function () {
  'use strict';
  if (window.KophiPlayer) return;

  var ICON = {
    play: '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>',
    pause: '<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>',
    prev: '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M6 6h2v12H6zm3.5 6 8.5 6V6z"/></svg>',
    next: '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M6 18l8.5-6L6 6v12zM16 6h2v12h-2z"/></svg>',
    vol: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path class="kp-vol-w" d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path class="kp-vol-x" d="m22 9-6 6m0-6 6 6"/></svg>',
    close: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>',
    note: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>'
  };

  var audio = new Audio();
  audio.preload = 'none';
  var cur = null;           // carte (élément) en cours
  var dock = null, el = {};
  var seeking = false;
  var vol = 0.9, muted = false;
  try { var sv = parseFloat(localStorage.getItem('kophi_vol')); if (!isNaN(sv)) vol = Math.min(1, Math.max(0, sv)); } catch (e) {}

  function fmt(s) {
    if (!isFinite(s) || s < 0) return '0:00';
    s = Math.floor(s);
    return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
  }
  function dur() { return isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 0; }
  function cards() {
    return Array.prototype.filter.call(document.querySelectorAll('[data-kp-url]'), function (c) {
      return c.getAttribute('data-kp-url') && c.offsetParent !== null;
    });
  }
  function byId(id) { return document.querySelector('[data-kp-id="' + String(id).replace(/"/g, '') + '"]'); }

  /* ── Barre de lecture ── */
  function buildDock() {
    if (dock) return;
    dock = document.createElement('div');
    dock.className = 'kp-dock';
    dock.setAttribute('role', 'region');
    dock.setAttribute('aria-label', 'Lecteur audio');
    dock.innerHTML =
      '<div class="kp-seek kp-seek-top" data-kp-seek><div class="kp-seek-track"><div class="kp-seek-buf"></div><div class="kp-seek-fill"></div></div></div>' +
      '<div class="kp-dock-in">' +
        '<div class="kp-now"><div class="kp-art"></div><div class="kp-meta"><div class="kp-title">—</div><div class="kp-sub"></div></div></div>' +
        '<div class="kp-mid">' +
          '<div class="kp-ctrl">' +
            '<button type="button" class="kp-btn kp-prev" aria-label="Titre précédent">' + ICON.prev + '</button>' +
            '<button type="button" class="kp-btn kp-main" aria-label="Lecture / pause"><span class="kp-i-play">' + ICON.play + '</span><span class="kp-i-pause">' + ICON.pause + '</span><span class="kp-spin"></span></button>' +
            '<button type="button" class="kp-btn kp-next" aria-label="Titre suivant">' + ICON.next + '</button>' +
          '</div>' +
          '<div class="kp-bar"><span class="kp-t kp-t-cur">0:00</span>' +
            '<div class="kp-seek" data-kp-seek role="slider" tabindex="0" aria-label="Position de lecture" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="kp-seek-track"><div class="kp-seek-buf"></div><div class="kp-seek-fill"></div><div class="kp-seek-thumb"></div></div></div>' +
          '<span class="kp-t kp-t-dur">0:00</span></div>' +
        '</div>' +
        '<div class="kp-side">' +
          '<button type="button" class="kp-btn kp-mute" aria-label="Couper le son">' + ICON.vol + '</button>' +
          '<input type="range" class="kp-vol" min="0" max="100" value="90" aria-label="Volume">' +
          '<button type="button" class="kp-btn kp-close" aria-label="Fermer le lecteur">' + ICON.close + '</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(dock);
    ['art', 'title', 'sub', 'main', 'prev', 'next', 'mute', 'vol', 'close'].forEach(function (k) { el[k] = dock.querySelector('.kp-' + k); });
    el.tcur = dock.querySelector('.kp-t-cur'); el.tdur = dock.querySelector('.kp-t-dur');
    el.seeks = dock.querySelectorAll('[data-kp-seek]');

    el.main.addEventListener('click', function () { toggle(); });
    el.prev.addEventListener('click', prev);
    el.next.addEventListener('click', function () { next(false); });
    el.close.addEventListener('click', close);
    el.mute.addEventListener('click', function () { muted = !muted; if (!muted && vol === 0) vol = 0.9; applyVolume(); });
    el.vol.addEventListener('input', function () { vol = this.value / 100; muted = false; try { localStorage.setItem('kophi_vol', vol); } catch (e) {} applyVolume(); });
    Array.prototype.forEach.call(el.seeks, bindSeek);
    applyVolume();
  }
  function applyVolume() {
    var v = muted ? 0 : vol;
    audio.volume = v;
    if (!dock) return;
    el.vol.value = Math.round(v * 100);
    el.vol.style.setProperty('--kp-pct', Math.round(v * 100) + '%');
    dock.classList.toggle('kp-muted', v === 0);
  }
  function bindSeek(s) {
    function ratio(e) { var r = s.getBoundingClientRect(); return r.width ? Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)) : 0; }
    s.addEventListener('pointerdown', function (e) {
      if (!dur()) return;
      e.preventDefault(); seeking = true; s.classList.add('kp-drag');
      try { s.setPointerCapture(e.pointerId); } catch (x) {}
      paint(ratio(e) * dur());
    });
    s.addEventListener('pointermove', function (e) { if (seeking && s.classList.contains('kp-drag')) paint(ratio(e) * dur()); });
    function end(e) {
      if (!s.classList.contains('kp-drag')) return;
      s.classList.remove('kp-drag'); seeking = false;
      if (e.type === 'pointerup' && dur()) audio.currentTime = ratio(e) * dur();
    }
    s.addEventListener('pointerup', end); s.addEventListener('pointercancel', end);
    s.addEventListener('keydown', function (e) {
      if (!dur()) return;
      var step = { ArrowLeft: -5, ArrowRight: 5, PageDown: -30, PageUp: 30 }[e.key];
      if (step) { e.preventDefault(); e.stopPropagation(); audio.currentTime = Math.min(dur(), Math.max(0, audio.currentTime + step)); }
    });
  }

  /* ── Affichage ── */
  function paint(t) {
    var d = dur(), pct = d ? Math.min(100, t / d * 100) : 0;
    if (dock) {
      Array.prototype.forEach.call(el.seeks, function (s) {
        s.querySelector('.kp-seek-fill').style.width = pct + '%';
        var th = s.querySelector('.kp-seek-thumb'); if (th) th.style.left = pct + '%';
        if (s.hasAttribute('role')) { s.setAttribute('aria-valuenow', Math.round(pct)); s.setAttribute('aria-valuetext', fmt(t) + ' sur ' + fmt(d)); }
      });
      el.tcur.textContent = fmt(t);
    }
    if (cur) {
      cur.style.setProperty('--kp-progress', pct + '%');
      var c = cur.querySelector('.kp-cur'); if (c) c.textContent = fmt(t);
    }
  }
  function setState(playing, loading) {
    if (cur) { cur.classList.toggle('kp-playing', !!playing); cur.classList.toggle('kp-loading', !!loading); }
    if (dock) { dock.classList.toggle('kp-playing', !!playing); dock.classList.toggle('kp-loading', !!loading); }
    document.documentElement.classList.toggle('kp-is-playing', !!playing);
    if ('mediaSession' in navigator) navigator.mediaSession.playbackState = playing ? 'playing' : 'paused';
  }
  function clearCard(c) {
    if (!c) return;
    c.classList.remove('kp-current', 'kp-playing', 'kp-loading');
    c.style.removeProperty('--kp-progress');
    var x = c.querySelector('.kp-cur'); if (x) x.textContent = '0:00';
  }

  /* ── Lecture ── */
  function load(card, autoplay) {
    var url = card.getAttribute('data-kp-url');
    if (!url) {
      var fb = card.getAttribute('data-kp-fallback');
      if (fb) window.open(fb, '_blank', 'noopener');
      return;
    }
    buildDock();
    clearCard(cur);
    cur = card;
    card.classList.add('kp-current');
    var title = card.getAttribute('data-kp-title') || '', sub = card.getAttribute('data-kp-sub') || '', cover = card.getAttribute('data-kp-cover') || '';
    el.title.textContent = title; el.sub.textContent = sub;
    el.art.innerHTML = '';
    if (cover) { var im = new Image(); im.alt = ''; im.src = cover; el.art.appendChild(im); } else { el.art.innerHTML = ICON.note; }
    el.tdur.textContent = fmt(parseFloat(card.getAttribute('data-kp-duration')) || 0);
    dock.classList.add('kp-show'); document.body.classList.add('kp-has-dock');
    audio.src = url; audio.preload = 'auto'; audio.load();
    paint(0); setState(false, true);
    if ('mediaSession' in navigator && typeof MediaMetadata !== 'undefined') {
      try { navigator.mediaSession.metadata = new MediaMetadata({ title: title, artist: sub || "KOPHI'S MUSIC STUDIO", album: "KOPHI'S MUSIC STUDIO", artwork: cover ? [{ src: cover }] : [] }); } catch (e) {}
    }
    if (autoplay !== false) play();
  }
  function play() {
    var p = audio.play();
    if (p && p.catch) p.catch(function (err) { if (!err || err.name !== 'AbortError') setState(false, false); });
  }
  /** Lecture / pause : sans argument, agit sur le titre en cours. */
  function toggle(target) {
    var card = target == null ? cur : (target.nodeType ? target : byId(target));
    if (!card) { var all = cards(); if (all.length) load(all[0]); return; }
    if (card !== cur) { load(card); return; }
    if (card.classList.contains('kp-error')) { card.classList.remove('kp-error'); load(card); return; }
    if (audio.paused) play(); else audio.pause();
  }
  function next(auto) {
    var all = cards(), i = all.indexOf(cur);
    if (i > -1 && i < all.length - 1) load(all[i + 1]);
    else if (!auto && all.length) load(all[0]);
    else { audio.pause(); try { audio.currentTime = 0; } catch (e) {} paint(0); setState(false, false); }
  }
  function prev() {
    var all = cards(), i = all.indexOf(cur);
    if (audio.currentTime > 3 || i <= 0) { try { audio.currentTime = 0; } catch (e) {} return; }
    load(all[i - 1]);
  }
  function close() {
    audio.pause(); audio.removeAttribute('src'); try { audio.load(); } catch (e) {}
    clearCard(cur); cur = null; setState(false, false);
    if (dock) dock.classList.remove('kp-show');
    document.body.classList.remove('kp-has-dock');
  }

  audio.addEventListener('play', function () { setState(true, false); });
  audio.addEventListener('playing', function () { setState(true, false); });
  audio.addEventListener('pause', function () { setState(false, false); });
  audio.addEventListener('waiting', function () { if (!audio.paused) setState(true, true); });
  audio.addEventListener('timeupdate', function () { if (!seeking) paint(audio.currentTime); });
  audio.addEventListener('loadedmetadata', function () {
    var d = dur(); if (!d) return;
    if (dock) el.tdur.textContent = fmt(d);
    if (cur) { var x = cur.querySelector('.kp-dur'); if (x) x.textContent = fmt(d); cur.setAttribute('data-kp-duration', d); }
  });
  audio.addEventListener('progress', function () {
    if (!dock || !dur()) return;
    var b = audio.buffered, end = 0;
    for (var i = 0; i < b.length; i++) { if (b.start(i) <= audio.currentTime + 0.5 && b.end(i) >= audio.currentTime) end = b.end(i); }
    Array.prototype.forEach.call(el.seeks, function (s) { s.querySelector('.kp-seek-buf').style.width = Math.min(100, end / dur() * 100) + '%'; });
  });
  audio.addEventListener('ended', function () { next(true); });
  audio.addEventListener('error', function () {
    if (!audio.getAttribute('src')) return;
    setState(false, false);
    if (cur) cur.classList.add('kp-error');
    if (dock) el.sub.textContent = 'Lecture impossible — vérifiez votre connexion.';
  });

  /* ── Délégation : clic sur [data-kp-play] ── */
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-kp-play]') : null;
    if (!b) return;
    var card = b.closest('[data-kp-id]');
    if (!card) return;
    e.preventDefault();
    toggle(card);
  });
  document.addEventListener('keydown', function (e) {
    if (!cur || e.ctrlKey || e.metaKey || e.altKey || e.defaultPrevented) return;
    var t = e.target;
    if (/^(INPUT|TEXTAREA|SELECT|BUTTON|A)$/.test(t.tagName) || t.isContentEditable) return;
    if (e.key === ' ' || e.key === 'k') { e.preventDefault(); toggle(); }
    else if (e.key === 'ArrowRight' && dur()) { audio.currentTime = Math.min(dur(), audio.currentTime + 10); }
    else if (e.key === 'ArrowLeft' && dur()) { audio.currentTime = Math.max(0, audio.currentTime - 10); }
  });
  if ('mediaSession' in navigator) {
    var set = function (a, fn) { try { navigator.mediaSession.setActionHandler(a, fn); } catch (e) {} };
    set('play', function () { toggle(); }); set('pause', function () { audio.pause(); });
    set('previoustrack', prev); set('nexttrack', function () { next(false); });
    set('seekto', function (d) { if (d && typeof d.seekTime === 'number') audio.currentTime = d.seekTime; });
  }

  /* ── Durées : détectées en arrière-plan pour les cartes qui affichent .kp-dur ── */
  function probe() {
    if ((navigator.connection || {}).saveData) return;
    var queue = Array.prototype.filter.call(document.querySelectorAll('[data-kp-url][data-kp-probe]'), function (c) {
      return c.getAttribute('data-kp-url') && !parseFloat(c.getAttribute('data-kp-duration'));
    });
    (function step() {
      var c = queue.shift(); if (!c) { document.dispatchEvent(new CustomEvent('kophi-player:durations')); return; }
      var a = new Audio(); a.preload = 'metadata';
      var done = function () { a.removeAttribute('src'); try { a.load(); } catch (e) {} setTimeout(step, 120); };
      a.addEventListener('loadedmetadata', function () {
        if (isFinite(a.duration) && a.duration > 0) {
          c.setAttribute('data-kp-duration', a.duration);
          var x = c.querySelector('.kp-dur'); if (x) x.textContent = fmt(a.duration);
        }
        done();
      }, { once: true });
      a.addEventListener('error', done, { once: true });
      a.src = c.getAttribute('data-kp-url');
    })();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', probe); else probe();

  window.KophiPlayer = { toggle: toggle, next: function () { next(false); }, prev: prev, close: close, fmt: fmt,
    current: function () { return cur; }, isPlaying: function () { return !audio.paused; } };
})();
