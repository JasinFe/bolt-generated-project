/**
 * KM SALLE D'ÉCOUTE v1.8.0 — Lecteur HTML5 professionnel
 * - Un seul élément <audio> (enchaînement fiable, y compris iOS)
 * - Barres de progression glissables (souris + tactile) avec infobulle de temps et tampon
 * - Répétition (off / tout / une piste), lecture aléatoire, vitesse, volume mémorisés
 * - Reprise automatique là où l'écoute s'était arrêtée (par piste)
 * - Durées réelles détectées automatiquement si elles ne sont pas saisies
 * - Media Session API (commandes écran verrouillé / casque / clavier multimédia)
 * - Raccourcis clavier, accessibilité (sliders ARIA), catalogue avec recherche
 */
(function ($) {
  'use strict';

  var cfg = window.kmEcoute || {};
  var T = $.extend({
    listen: 'Écouter', pause: 'Pause', resume: 'Reprendre', checking: 'Vérification…', granted: 'Accès autorisé',
    emptyPwd: 'Veuillez saisir le mot de passe.', badPwd: 'Mot de passe incorrect.', netError: 'Erreur réseau. Réessayez.',
    showPwd: 'Afficher le mot de passe', hidePwd: 'Masquer le mot de passe',
    playError: 'Lecture impossible — vérifiez votre connexion.', retry: 'Réessayer',
    resumedAt: 'Reprise à %s', fromStart: 'Depuis le début', of: 'sur',
    mute: 'Couper le son', unmute: 'Rétablir le son', speed: 'Vitesse de lecture : %s',
    repeatOff: 'Répétition : désactivée', repeatAll: 'Répétition : toutes les pistes', repeatOne: 'Répétition : piste en boucle',
    shuffleOn: 'Lecture aléatoire : activée', shuffleOff: 'Lecture aléatoire : désactivée',
    session: '%d session', sessions: '%d sessions', volume: 'Volume : %s %%',
    noLyrics: 'Pas de paroles pour ce titre.', lyricsHint: 'Lancez un titre pour afficher ses paroles.'
  }, cfg.i18n || {});
  function tr_(s, v) { return String(s).replace('%s', v).replace('%d', v).replace('%%', '%'); }

  /* ── Préférences mémorisées ── */
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem('kse_' + k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem('kse_' + k, JSON.stringify(v)); } catch (e) {} },
    del: function (k) { try { localStorage.removeItem('kse_' + k); } catch (e) {} }
  };

  var RATES = [1, 1.25, 1.5, 2, 0.75];
  var S = {
    idx: -1, tracks: [], order: [], seeking: false,
    vol: clamp(store.get('vol', 0.8), 0, 1),
    muted: false,
    rate: RATES.indexOf(store.get('rate', 1)) > -1 ? store.get('rate', 1) : 1,
    repeat: ['off', 'all', 'one'].indexOf(store.get('repeat', 'off')) > -1 ? store.get('repeat', 'off') : 'off',
    shuffle: !!store.get('shuffle', false),
    lastSave: 0, pendingResume: 0
  };

  var audio = null;
  var baseTitle = document.title;
  var $page, $np, $btnMain, $mainLabel, toastTimer;

  function safe(name, fn) {
    try { fn(); } catch (e) { if (window.console) console.error('KM Salle d\'Écoute — erreur dans ' + name + ' :', e); }
  }

  $(function () {
    safe('enforceFullwidth', enforceFullwidth);
    safe('extractCoverColor', extractCoverColor);
    safe('initPasswordForm', initPasswordForm);
    safe('initCatalogue', initCatalogue);
    safe('initTopBar', initTopBar);
    safe('initLightbox', initLightbox);

    if (!$('.km-track').length) return;
    audio = document.getElementById('km-audio');
    if (!audio) {                                   // filet de sécurité : crée l'élément audio s'il manque
      audio = document.createElement('audio');
      audio.id = 'km-audio'; audio.preload = 'none'; audio.className = 'km-audio';
      audio.setAttribute('playsinline', '');
      document.body.appendChild(audio);
    }
    $page = $('.km-page');
    $np = $('#km-np');
    $btnMain = $('#km-btn-main');
    $mainLabel = $('#km-main-label');

    safe('buildTracks', buildTracks);
    // Chaque module est isolé : une erreur dans l'un n'empêche pas la lecture
    safe('initAudioEvents', initAudioEvents);
    safe('initRows', initRows);
    safe('initSeekBars', initSeekBars);
    safe('initDock', initDock);
    safe('initHelp', initHelp);
    safe('initKeyboard', initKeyboard);
    safe('initMediaSession', initMediaSession);
    safe('applyVolume', applyVolume);
    safe('applyRate', applyRate);
    safe('applyRepeat', applyRepeat);
    safe('applyShuffle', applyShuffle);
    safe('initSessionUI', initSessionUI);
    safe('updateTotal', updateTotal);
    safe('probeDurations', probeDurations);
    $(window).on('pagehide', function () { savePosition(true); });
  });

  function clamp(n, a, b) { n = +n; if (isNaN(n)) return a; return Math.min(b, Math.max(a, n)); }
  function fmt(s) {
    if (!s || isNaN(s) || !isFinite(s) || s < 0) return '0:00';
    s = Math.floor(s);
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
  }
  function cur() { return S.idx > -1 ? S.tracks[S.idx] : null; }
  function dur() { return audio && isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 0; }
  function posKey(tr) {             // clé courte et stable par fichier
    var h = 0, s = tr.url.split('?')[0];
    for (var i = 0; i < s.length; i++) { h = ((h << 5) - h + s.charCodeAt(i)) | 0; }
    return 'pos_' + (h >>> 0).toString(36);
  }

  /* ================================================================
     PLEINE LARGEUR (filet de sécurité, indépendant du thème)
     N'agit que sur les pages « application » (session, catalogue, archives) :
     plus jamais sur une page ordinaire contenant un shortcode.
  ================================================================ */
  function enforceFullwidth() {
    const page = document.querySelector('.km-page');
    if (!page || !document.body.classList.contains('km-fullwidth-page')) return;

    // 1) Neutralise les contraintes de largeur/centrage des ancêtres de .km-page
    const ancestors = [];
    let node = page.parentElement;
    while (node && node !== document.body && node !== document.documentElement) {
      ancestors.push(node);
      const cs = window.getComputedStyle(node);
      if (cs.maxWidth && cs.maxWidth !== 'none' && cs.maxWidth !== '100%') node.style.setProperty('max-width', 'none', 'important');
      if (cs.width && /px$/.test(cs.width)) node.style.setProperty('width', '100%', 'important');
      node.style.setProperty('padding-left', '0', 'important');
      node.style.setProperty('padding-right', '0', 'important');
      node.style.setProperty('margin-left', '0', 'important');
      node.style.setProperty('margin-right', '0', 'important');
      node.style.setProperty('float', 'none', 'important');
      if ((cs.display === 'flex' || cs.display === 'inline-flex') && cs.flexDirection.indexOf('row') === 0) {
        node.style.setProperty('display', 'block', 'important');
      }
      node = node.parentElement;
    }

    if (!cfg.hideChrome) return;

    // 2) Masque en-têtes / pieds de page du thème — uniquement parmi les « frères »
    // de la chaîne d'ancêtres (là où le thème les place), jamais à l'intérieur de
    // la page ni la barre d'administration WordPress.
    const isChrome = function (el) {
      if (el.id === 'wpadminbar' || el.closest('#wpadminbar')) return false;
      if (el.matches('header,footer,#header,#masthead,.site-header,.main-header,#colophon,.site-footer,nav,[role="banner"],[role="contentinfo"],.elementor-location-header,.elementor-location-footer')) return true;
      // Navigation détectée par son contenu (menu du site principal)
      const text = (el.textContent || '').toUpperCase();
      if (text.length > 1200) return false;
      const kws = ['ACTUALIT', 'DISCOGRAPH', 'NOS ARTISTES', 'ESPACE ARTISTE', 'CONTACT', 'ACCUEIL'];
      return kws.filter(function (k) { return text.indexOf(k) !== -1; }).length >= 2 && !!el.querySelector('a');
    };
    [page].concat(ancestors).forEach(function (n) {
      const parent = n.parentElement;
      if (!parent) return;
      Array.prototype.forEach.call(parent.children, function (sib) {
        if (sib === n || sib.contains(page) || /^(SCRIPT|STYLE|LINK|NOSCRIPT|TEMPLATE)$/.test(sib.tagName)) return;
        if (isChrome(sib)) sib.style.setProperty('display', 'none', 'important');
      });
    });
  }

  /* ================================================================
     COULEUR DYNAMIQUE du hero (extraite de la cover)
  ================================================================ */
  function extractCoverColor() {
    var img = document.getElementById('km-cover');
    var page = document.querySelector('.km-page');
    if (!img || !page) return;

    // Teinte dominante « vive » : on pondère chaque pixel par sa saturation, puis
    // on ramène la couleur à une luminosité fixe pour garder le texte lisible.
    function apply(r, g, b) {
      var max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 510;
      var target = 0.3, k = l > 0 ? target / l : 1;
      r = clamp(r * k, 0, 255); g = clamp(g * k, 0, 255); b = clamp(b * k, 0, 255);
      if (max - min < 18) { r = g = b = 62; }           // pochette grise : gris neutre
      page.style.setProperty('--km-cover', 'rgb(' + Math.round(r) + ',' + Math.round(g) + ',' + Math.round(b) + ')');
    }
    function sample(el) {
      try {
        var c = document.createElement('canvas'); c.width = 48; c.height = 48;
        var ctx = c.getContext('2d'); ctx.drawImage(el, 0, 0, 48, 48);
        var d = ctx.getImageData(0, 0, 48, 48).data, r = 0, g = 0, b = 0, n = 0;
        for (var i = 0; i < d.length; i += 4) {
          var mx = Math.max(d[i], d[i + 1], d[i + 2]), mn = Math.min(d[i], d[i + 1], d[i + 2]);
          var w = (mx ? (mx - mn) / mx : 0) + 0.05;
          r += d[i] * w; g += d[i + 1] * w; b += d[i + 2] * w; n += w;
        }
        if (n) apply(r / n, g / n, b / n);
      } catch (e) { /* canvas « tainted » : on garde la couleur par défaut */ }
    }
    // L'image affichée n'a plus d'attribut crossorigin (il empêchait tout simplement
    // l'affichage de la pochette quand le CDN n'envoie pas d'en-têtes CORS) : la
    // couleur est lue sur une copie miniature chargée à part.
    var src = img.getAttribute('data-sample') || img.currentSrc || img.src;
    var probe = new Image();
    try { if (new URL(src, location.href).origin !== location.origin) probe.crossOrigin = 'anonymous'; } catch (e) {}
    probe.onload = function () { sample(probe); };
    probe.src = src;
  }

  /* ================================================================
     BARRE SUPÉRIEURE, POCHETTE EN GRAND
  ================================================================ */
  function initTopBar() {
    var top = document.getElementById('km-top'), hero = document.querySelector('.km-sh');
    if (!top || !hero) return;
    var bar = document.getElementById('wpadminbar');
    function offset() {
      var h = bar && getComputedStyle(bar).position === 'fixed' ? bar.offsetHeight : 0;
      top.parentNode.style.setProperty('--km-adminbar', h + 'px');
      return h;
    }
    var ticking = false;
    function update() {
      ticking = false;
      var stuck = hero.getBoundingClientRect().bottom < offset() + 70;
      top.classList.toggle('is-stuck', stuck);
      var tp = document.getElementById('km-top-play'); if (tp) tp.tabIndex = stuck ? 0 : -1;
    }
    window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
    window.addEventListener('resize', update);
    update();
  }

  function initLightbox() {
    var btn = document.getElementById('km-cover-btn'), box = document.getElementById('km-lightbox');
    if (!btn || !box) return;
    var im = document.getElementById('km-lightbox-img');
    function close() { box.hidden = true; btn.focus(); }
    btn.addEventListener('click', function () {
      if (!im.getAttribute('src')) im.src = btn.getAttribute('data-full');
      box.hidden = false; document.getElementById('km-lightbox-close').focus();
    });
    box.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !box.hidden) { e.stopPropagation(); close(); } }, true);
  }

  /* ================================================================
     PISTES
  ================================================================ */
  function buildTracks() {
    $('.km-track').each(function () {
      var $t = $(this), url = $t.data('url');
      if (!url) return;
      S.tracks.push({
        tid: $t.data('tid'), url: String(url), title: String($t.data('title') || ''),
        known: +$t.data('duration') || 0,
        lyrics: $.trim($t.find('.km-tr-lyrics').text()),
        $t: $t, $row: $t.find('.km-tr'),
        $dur: $t.find('.km-tr-dur'), $curTxt: $t.find('.km-time-cur'), $totTxt: $t.find('.km-time-tot'),
        $seekPanel: $t.find('[data-seek="panel"]'), $seekMini: $t.find('[data-seek="mini"]')
      });
    });
    buildOrder();
  }

  /** Ordre de lecture : naturel, ou mélangé (la piste en cours reste en tête). */
  function buildOrder(noPin) {
    var n = S.tracks.length, o = [];
    for (var i = 0; i < n; i++) o.push(i);
    if (S.shuffle) {
      for (var j = n - 1; j > 0; j--) { var k = Math.floor(Math.random() * (j + 1)); var x = o[j]; o[j] = o[k]; o[k] = x; }
      if (S.idx > -1 && !noPin) { o.splice(o.indexOf(S.idx), 1); o.unshift(S.idx); }
      else if (S.idx > -1 && n > 1 && o[0] === S.idx) { o.push(o.shift()); }   // évite de rejouer la même piste
    }
    S.order = o;
  }

  function initRows() {
    S.tracks.forEach(function (tr, i) {
      tr.$row.on('click', function (e) {
        if ($(e.target).closest('a,button,.km-seek').length) return;
        selectTrack(i);
      });
      tr.$row.on('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        if ($(e.target).closest('a,button,.km-seek').length) return;
        e.preventDefault(); e.stopPropagation();
        selectTrack(i);
      });
      tr.$t.find('.km-btn-panel-play').on('click', function (e) { e.stopPropagation(); togglePlay(); });
      tr.$t.on('click', '.km-retry', function (e) { e.preventDefault(); e.stopPropagation(); loadTrack(i, true); });
      tr.$t.find('.km-tr-lyr').on('click', function (e) { e.stopPropagation(); showLyrics(i, true); });
    });
    $btnMain.on('click', function () { if (S.idx < 0) loadTrack(S.order[0] || 0, true); else togglePlay(); });
  }

  function selectTrack(i) {
    var t = S.tracks[i];
    if (i === S.idx && !t.$t.hasClass('has-error')) togglePlay(); else loadTrack(i, true);
  }

  function loadTrack(i, autoplay) {
    if (i < 0 || i >= S.tracks.length) return;
    savePosition(true);
    var prev = cur();
    if (prev) clearTrackState(prev, true);

    S.idx = i;
    var tr = S.tracks[i];
    clearError(tr);
    tr.$t.addClass('is-active is-loading');

    // Reprise : position mémorisée lors d'une écoute précédente
    var saved = +store.get(posKey(tr), 0) || 0;
    S.pendingResume = saved > 10 ? saved : 0;

    audio.src = tr.url;
    audio.preload = 'auto';
    audio.load();
    audio.playbackRate = S.rate;
    audio.loop = (S.repeat === 'one');

    $('#np-track').text(tr.title);
    $('#np-dur').text(tr.known ? fmt(tr.known) : (tr.$dur.text() || '—'));
    $np.addClass('show'); $('body').addClass('km-has-dock');
    updateProgressUI(0, tr.known);
    updateMediaMetadata(tr);
    showLyrics(i, false);
    // Garde la piste active (et son panneau) visible au-dessus de la barre de lecture
    setTimeout(function () {
      var el = tr.$t.get(0);
      if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest', behavior: reducedMotion() ? 'auto' : 'smooth' });
    }, 320);

    if (autoplay) tryPlay();
  }
  function reducedMotion() { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; }

  function clearTrackState(tr, resetUI) {
    tr.$t.removeClass('is-active is-playing is-loading');
    tr.$t.find('.km-pp').removeClass('is-playing');
    if (resetUI) { tr.$t.css('--p', '0%'); setSeekUI(tr.$seekPanel, 0, 0); setSeekUI(tr.$seekMini, 0, 0); tr.$curTxt.text('0:00'); tr.$t.find('.km-seek-buf').css('width', 0); }
  }

  function tryPlay() {
    var p = audio.play();
    if (p && p.catch) p.catch(function (err) {
      if (err && err.name === 'AbortError') return;      // changement de piste : normal
      var tr = cur(); if (tr) tr.$t.removeClass('is-loading');
      setPlayingUI(false);
      if (err && err.name === 'NotSupportedError') onError();
    });
  }
  function togglePlay() {
    if (S.idx < 0) { if (S.tracks.length) loadTrack(S.order[0] || 0, true); return; }
    if (audio.paused) tryPlay(); else audio.pause();
  }

  function next(auto) {
    if (S.idx < 0) return;
    var pos = S.order.indexOf(S.idx);
    if (pos < S.order.length - 1) loadTrack(S.order[pos + 1], true);
    else if (S.repeat === 'all' || !auto) { if (S.shuffle) buildOrder(true); loadTrack(S.order[0], true); }
    else stopAtEnd();
  }
  function prev() {
    if (S.idx < 0) return;
    var pos = S.order.indexOf(S.idx);
    if (audio.currentTime > 3 || pos <= 0) { seekTo(0); return; }
    loadTrack(S.order[pos - 1], true);
  }
  function stopAtEnd() {
    audio.pause();
    seekTo(0);
    updateProgressUI(0, dur());
    setPlayingUI(false);
  }
  function seekTo(t) {
    try { audio.currentTime = clamp(t, 0, dur() || t); } catch (e) {}
  }
  function skip(sec) {
    if (S.idx < 0 || !dur()) return;
    seekTo(audio.currentTime + sec);
  }

  /* ── Mémorisation de la position d'écoute ── */
  function savePosition(force) {
    var t = cur(); if (!t || !audio || !dur()) return;
    var now = Date.now();
    if (!force && now - S.lastSave < 4000) return;
    S.lastSave = now;
    var p = audio.currentTime;
    if (p > 10 && p < dur() - 10) store.set(posKey(t), Math.floor(p));
    else store.del(posKey(t));
  }

  function toast(html, ms) {
    var $t = $('#km-toast'); if (!$t.length) return;
    clearTimeout(toastTimer);
    $t.html(html).prop('hidden', false).addClass('show');
    toastTimer = setTimeout(function () { $t.removeClass('show'); setTimeout(function () { $t.prop('hidden', true); }, 300); }, ms || 4000);
  }

  /* ================================================================
     ÉVÉNEMENTS AUDIO → INTERFACE
  ================================================================ */
  function initAudioEvents() {
    audio.addEventListener('play', function () { setPlayingUI(true); });
    audio.addEventListener('pause', function () { setPlayingUI(false); savePosition(true); });
    audio.addEventListener('waiting', function () { var t = cur(); if (t) t.$t.addClass('is-loading'); });
    audio.addEventListener('playing', function () { var t = cur(); if (t) t.$t.removeClass('is-loading'); });
    audio.addEventListener('canplay', function () { var t = cur(); if (t && !audio.paused) t.$t.removeClass('is-loading'); });
    audio.addEventListener('loadedmetadata', function () {
      var t = cur(); if (!t) return;
      var d = dur();
      if (d) { t.known = d; var txt = fmt(d); t.$dur.text(txt); t.$totTxt.text(txt); $('#np-dur').text(txt); updateTotal(); }
      if (S.pendingResume && d && S.pendingResume < d - 10) {
        var at = S.pendingResume;
        seekTo(at);
        toast($('<span>').text(tr_(T.resumedAt, fmt(at))).prop('outerHTML') +
          ' <button type="button" class="km-b km-toast-btn" id="km-restart">' + $('<span>').text(T.fromStart).html() + '</button>', 6000);
      }
      S.pendingResume = 0;
    });
    audio.addEventListener('timeupdate', function () {
      if (S.seeking) return;
      updateProgressUI(audio.currentTime, dur());
      updatePositionState();
      savePosition(false);
    });
    audio.addEventListener('progress', updateBuffered);
    audio.addEventListener('ended', function () { var t = cur(); if (t) store.del(posKey(t)); next(true); });
    audio.addEventListener('error', function () { if (audio.getAttribute('src')) onError(); });
    audio.addEventListener('ratechange', function () { if (audio.playbackRate !== S.rate) audio.playbackRate = S.rate; });

    $(document).on('click', '#km-restart', function () { seekTo(0); $('#km-toast').removeClass('show').prop('hidden', true); });
  }

  function setPlayingUI(state) {
    var t = cur();
    if (t) {
      t.$t.toggleClass('is-playing', state);
      t.$t.find('.km-pp').toggleClass('is-playing', state);
      if (state) t.$t.removeClass('is-loading');
    }
    $('#np-play,#km-top-play').toggleClass('is-playing', state);
    $btnMain.toggleClass('is-playing', state);
    var lbl = state ? T.pause : (S.idx > -1 ? T.resume : T.listen);
    $mainLabel.text(lbl); $btnMain.attr('aria-label', lbl);
    document.title = (state && t ? '▶ ' + t.title + ' · ' : '') + baseTitle;
    if ('mediaSession' in navigator) navigator.mediaSession.playbackState = state ? 'playing' : 'paused';
  }

  function onError() {
    var t = cur(); if (!t) return;
    t.$t.removeClass('is-loading is-playing').addClass('has-error');
    setPlayingUI(false);
    if (!t.$t.find('.km-tr-error-msg').length) {
      t.$t.find('.km-tr-info').append($('<div class="km-tr-error-msg" role="alert">').text(T.playError));
    }
    var $in = t.$t.find('.km-panel-inner');
    if (!$in.find('.km-error-banner').length) {
      $in.prepend($('<p class="km-error-banner">').text(T.playError + ' ').append(
        $('<button type="button" class="km-b km-retry">').text(T.retry)
      ));
    }
  }
  function clearError(t) {
    t.$t.removeClass('has-error');
    t.$t.find('.km-tr-error-msg,.km-error-banner').remove();
  }

  /* ── Durées : détectées en arrière-plan si non saisies dans l'admin ── */
  function probeDurations() {
    var conn = navigator.connection || {};
    if (conn.saveData) return;
    var queue = S.tracks.filter(function (t) { return !t.known; });
    (function nextProbe() {
      var t = queue.shift(); if (!t) return;
      if (t.known) return nextProbe();
      var a = new Audio(); a.preload = 'metadata';
      var done = function () { a.removeAttribute('src'); try { a.load(); } catch (e) {} setTimeout(nextProbe, 150); };
      a.addEventListener('loadedmetadata', function () {
        if (isFinite(a.duration) && a.duration > 0 && !t.known) {
          t.known = a.duration; var txt = fmt(a.duration);
          t.$dur.text(txt); t.$totTxt.text(txt); updateTotal();
        }
        done();
      }, { once: true });
      a.addEventListener('error', done, { once: true });
      a.src = t.url;
    })();
  }

  /* ================================================================
     PROGRESSION
  ================================================================ */
  function updateProgressUI(time, d) {
    var t = cur(); if (!t) return;
    var pct = d ? clamp(time / d * 100, 0, 100) : 0;
    setSeekUI(t.$seekPanel, pct, time); setSeekUI(t.$seekMini, pct, time);
    setSeekUI($('#np-seek'), pct, time);
    t.$t.css('--p', pct + '%');
    var txt = fmt(time);
    t.$curTxt.text(txt); $('#np-cur').text(txt);
    updateBuffered();
  }
  function setSeekUI($s, pct, time) {
    if (!$s.length) return;
    $s.find('.km-seek-fill').css('width', pct + '%');
    $s.find('.km-seek-thumb').css('left', pct + '%');
    $s.attr({ 'aria-valuenow': Math.round(pct), 'aria-valuetext': fmt(time) + (dur() ? ' ' + T.of + ' ' + fmt(dur()) : '') });
  }
  function updateBuffered() {
    var t = cur(); if (!t || !dur()) return;
    var b = audio.buffered, end = 0;
    for (var i = 0; i < b.length; i++) { if (b.start(i) <= audio.currentTime + 0.5 && b.end(i) >= audio.currentTime) end = b.end(i); }
    var pct = clamp(end / dur() * 100, 0, 100) + '%';
    t.$seekPanel.add(t.$seekMini).add('#np-seek').find('.km-seek-buf').css('width', pct);
  }

  /* ── Barres de progression : glisser (souris/tactile) + clavier ── */
  function initSeekBars() {
    $('.km-seek').each(function () { bindSeek($(this)); });
  }
  function bindSeek($s) {
    var el = $s.get(0), $tip = $s.find('.km-seek-tip');

    function ratio(e) {
      var r = el.getBoundingClientRect();
      return r.width ? clamp((e.clientX - r.left) / r.width, 0, 1) : 0;
    }
    function owner() {  // la barre agit sur la piste active uniquement
      var $tr = $s.closest('.km-track');
      return $tr.length ? ($tr.is('.is-active') ? cur() : null) : cur();
    }
    function showTip(e) {
      if (!owner() || !dur()) { $tip.css('opacity', 0); return; }
      var x = ratio(e);
      $tip.css('opacity', '').text(fmt(x * dur())).css('left', (x * 100) + '%');
    }

    $s.on('pointerdown', function (e) {
      if (e.button > 0 || !owner() || !dur()) return;
      e.preventDefault(); e.stopPropagation();
      S.seeking = true; $s.addClass('is-drag');
      try { el.setPointerCapture(e.pointerId); } catch (err) {}
      updateProgressUI(ratio(e) * dur(), dur()); showTip(e);
    });
    $s.on('pointermove', function (e) {
      showTip(e);
      if (!S.seeking || !$s.hasClass('is-drag')) return;
      updateProgressUI(ratio(e) * dur(), dur());
    });
    $s.on('pointerup pointercancel', function (e) {
      if (!$s.hasClass('is-drag')) return;
      $s.removeClass('is-drag');
      S.seeking = false;
      if (e.type === 'pointerup' && dur()) seekTo(ratio(e) * dur());
      else updateProgressUI(audio.currentTime, dur());
      try { el.releasePointerCapture(e.pointerId); } catch (err) {}
    });
    $s.on('click', function (e) { e.stopPropagation(); });
    $s.on('keydown', function (e) {
      if (!owner() || !dur()) return;
      var step = { ArrowLeft: -5, ArrowDown: -5, ArrowRight: 5, ArrowUp: 5, PageDown: -30, PageUp: 30 }[e.key];
      if (step) { e.preventDefault(); e.stopPropagation(); skip(step); }
      else if (e.key === 'Home') { e.preventDefault(); e.stopPropagation(); seekTo(0); }
      else if (e.key === 'End') { e.preventDefault(); e.stopPropagation(); seekTo(Math.max(0, dur() - 1)); }
    });
  }

  /* ================================================================
     BARRE DE LECTURE : prev/next, aléatoire, répétition, vitesse, volume
  ================================================================ */
  function initDock() {
    $('#np-play').on('click', togglePlay);
    $('#np-prev').on('click', prev);
    $('#np-next').on('click', function () { next(false); });
    $('#np-repeat').on('click', cycleRepeat);
    $('#np-shuffle,#km-act-shuffle').on('click', toggleShuffle);
    $('#km-top-play').on('click', togglePlay);
    $('#np-speed').on('click', cycleRate);
    $('#np-mute').on('click', toggleMute);
    $('#np-vol').on('input', function () {
      S.vol = clamp(this.value / 100, 0, 1); S.muted = false;
      store.set('vol', S.vol); applyVolume();
    });
    // Molette sur le volume
    $('#np-vol-wrap').on('wheel', function (e) {
      var dy = e.originalEvent && e.originalEvent.deltaY; if (!dy) return;
      e.preventDefault(); bumpVolume(dy < 0 ? 0.05 : -0.05);
    });
  }

  function applyVolume() {
    var v = S.muted ? 0 : S.vol;
    audio.volume = v;
    var pct = Math.round(v * 100);
    $('#np-vol').val(pct).css('--pct', pct + '%').attr('aria-valuetext', tr_(T.volume, pct));
    $('#np-vol-wrap').attr('data-level', v === 0 ? 0 : (v < 0.4 ? 1 : 2));
    $('#np-mute').attr('aria-label', v === 0 ? T.unmute : T.mute);
  }
  function toggleMute() {
    S.muted = !S.muted;
    if (!S.muted && S.vol === 0) S.vol = 0.8;
    applyVolume();
  }
  function bumpVolume(d) { S.muted = false; S.vol = clamp(Math.round((S.vol + d) * 100) / 100, 0, 1); store.set('vol', S.vol); applyVolume(); }

  function cycleRate() {
    S.rate = RATES[(RATES.indexOf(S.rate) + 1) % RATES.length];
    store.set('rate', S.rate); applyRate();
  }
  function applyRate() {
    audio.playbackRate = S.rate;
    try { audio.preservesPitch = true; } catch (e) {}
    var lbl = String(S.rate).replace('.', ',') + '×';
    $('#np-speed').text(lbl).toggleClass('is-on', S.rate !== 1).attr('aria-label', tr_(T.speed, lbl));
  }

  function cycleRepeat() {
    S.repeat = { off: 'all', all: 'one', one: 'off' }[S.repeat];
    store.set('repeat', S.repeat); applyRepeat();
  }
  function applyRepeat() {
    audio.loop = (S.repeat === 'one');
    var labels = { off: T.repeatOff, all: T.repeatAll, one: T.repeatOne };
    $('#np-repeat').attr({ 'data-repeat': S.repeat, 'aria-pressed': S.repeat !== 'off', 'aria-label': labels[S.repeat] });
  }

  function toggleShuffle() { S.shuffle = !S.shuffle; store.set('shuffle', S.shuffle); buildOrder(); applyShuffle(); }
  function applyShuffle() {
    $('#km-act-shuffle').attr('aria-pressed', S.shuffle);
    $('#np-shuffle').attr({ 'aria-pressed': S.shuffle, 'aria-label': S.shuffle ? T.shuffleOn : T.shuffleOff });
  }

  /* ================================================================
     PAROLES, DURÉE TOTALE, LECTEUR PLEIN ÉCRAN (mobile)
  ================================================================ */
  function showLyrics(i, focus) {
    var $body = $('#km-lyrics-body'); if (!$body.length) return;
    var tr = S.tracks[i]; if (!tr) return;
    $('#km-lyrics-track').text(tr.title);
    $body.empty().scrollTop(0);
    if (tr.lyrics) {
      tr.lyrics.replace(/\r/g, '').split(/\n{2,}/).forEach(function (block) {
        var $p = $('<p>');
        block.split('\n').forEach(function (line, n) { if (n) $p.append('<br>'); $p.append(document.createTextNode(line)); });
        $body.append($p);
      });
    } else {
      $body.append($('<p class="km-lyrics-empty">').text(T.noLyrics));
    }
    $('.km-track').removeClass('is-lyrics'); tr.$t.addClass('is-lyrics');
    if (focus) focusLyrics();
  }
  function focusLyrics() {
    var el = document.getElementById('km-lyrics'); if (!el) return;
    closeFullPlayer();
    if (S.idx < 0 && !$('.km-track.is-lyrics').length) {
      for (var i = 0; i < S.tracks.length; i++) { if (S.tracks[i].lyrics) { showLyrics(i, false); break; } }
    }
    var r = el.getBoundingClientRect();
    if (r.top < 80 || r.top > window.innerHeight - 220) {
      window.scrollTo({ top: window.pageYOffset + r.top - 96, behavior: reducedMotion() ? 'auto' : 'smooth' });
    }
    el.classList.remove('is-flash'); void el.offsetWidth; el.classList.add('is-flash');
  }

  /** « 5 pistes, 12 min 30 s » — mis à jour à mesure que les durées sont connues. */
  function updateTotal() {
    var $el = $('#km-total-dur'); if (!$el.length || !S.tracks.length) return;
    var tot = 0, all = true;
    S.tracks.forEach(function (t) { if (t.known) tot += t.known; else all = false; });
    if (!all || !tot) { $el.text(''); return; }
    tot = Math.round(tot);
    var h = Math.floor(tot / 3600), m = Math.floor((tot % 3600) / 60), sec = tot % 60;
    $el.text(h ? h + ' h ' + m + ' min' : (m ? m + ' min ' + (sec < 10 ? '0' : '') + sec + ' s' : sec + ' s'));
  }

  function isCompact() { return window.matchMedia && window.matchMedia('(max-width:900px)').matches; }
  function closeFullPlayer() { if ($np && $np.hasClass('is-open')) { $np.removeClass('is-open'); $('body').removeClass('km-np-open'); } }
  function initSessionUI() {
    $('#np-lyrics,#km-act-lyrics').on('click', focusLyrics);
    $('#np-open').on('click', function () {
      if (!isCompact() || $np.hasClass('is-open')) return;
      $np.addClass('is-open'); $('body').addClass('km-np-open');
    });
    $('#np-collapse').on('click', function (e) { e.stopPropagation(); closeFullPlayer(); });
    $(window).on('resize', function () { if (!isCompact()) closeFullPlayer(); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') closeFullPlayer(); });
  }

  /* ================================================================
     RACCOURCIS CLAVIER
  ================================================================ */
  function initHelp() {
    var $b = $('#km-help-btn'), $h = $('#km-help');
    if (!$b.length) return;
    function toggle(open) { $h.prop('hidden', !open); $b.attr('aria-expanded', open); }
    $b.on('click', function (e) { e.stopPropagation(); toggle($h.prop('hidden')); });
    $(document).on('click', function (e) { if (!$(e.target).closest('#km-help,#km-help-btn').length) toggle(false); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape' && !$h.prop('hidden')) { toggle(false); $b.trigger('focus'); } });
  }

  function initKeyboard() {
    $(document).on('keydown', function (e) {
      if (e.ctrlKey || e.metaKey || e.altKey || e.isDefaultPrevented()) return;
      var $t = $(e.target);
      if ($t.is('input,textarea,select') || e.target.isContentEditable) return;
      var onControl = $t.closest('button,a,[role="button"],[role="slider"]').length;
      var onSlider = $t.is('[role="slider"]');
      var active = S.idx > -1;

      if (/^[0-9]$/.test(e.key)) {                         // 0–9 : aller à 0 %…90 %
        if (!active || !dur()) return;
        e.preventDefault(); seekTo(dur() * (+e.key) / 10); return;
      }
      switch (e.key) {
        case ' ': case 'k': case 'K':
          if (onControl && e.key === ' ') return;           // laisse le bouton/ligne réagir seul
          e.preventDefault(); togglePlay(); break;
        case 'ArrowRight': if (!active || onSlider) return; e.preventDefault(); skip(10); break;
        case 'ArrowLeft':  if (!active || onSlider) return; e.preventDefault(); skip(-10); break;
        case 'ArrowUp':    if (!active || onSlider) return; e.preventDefault(); bumpVolume(0.1); break;
        case 'ArrowDown':  if (!active || onSlider) return; e.preventDefault(); bumpVolume(-0.1); break;
        case 'n': case 'N': if (active) next(false); break;
        case 'p': case 'P': if (active) prev(); break;
        case 'm': case 'M': if (active) toggleMute(); break;
        case 'r': case 'R': cycleRepeat(); break;
        case 'a': case 'A': toggleShuffle(); break;
        case 's': case 'S': if (active) cycleRate(); break;
        case 'l': case 'L': if ($('#km-lyrics').length) focusLyrics(); break;
      }
    });
  }

  /* ================================================================
     MEDIA SESSION (écran verrouillé, casque, touches multimédia)
  ================================================================ */
  function updateMediaMetadata(tr) {
    if (!('mediaSession' in navigator) || typeof MediaMetadata === 'undefined' || !$page) return;
    var cover = $page.data('sessionCover');
    try {
      navigator.mediaSession.metadata = new MediaMetadata({
        title: tr.title,
        artist: $page.data('sessionArtist') || '',
        album: $page.data('sessionTitle') || '',
        artwork: cover ? [{ src: String(cover) }] : []
      });
    } catch (e) {}
  }
  function updatePositionState() {
    if (!('mediaSession' in navigator) || !navigator.mediaSession.setPositionState || !dur()) return;
    try { navigator.mediaSession.setPositionState({ duration: dur(), playbackRate: audio.playbackRate || 1, position: Math.min(audio.currentTime, dur()) }); } catch (e) {}
  }
  function initMediaSession() {
    if (!('mediaSession' in navigator)) return;
    var set = function (a, fn) { try { navigator.mediaSession.setActionHandler(a, fn); } catch (e) {} };
    set('play', function () { togglePlay(); });
    set('pause', function () { audio.pause(); });
    set('stop', function () { audio.pause(); });
    set('previoustrack', prev);
    set('nexttrack', function () { next(false); });
    set('seekbackward', function (d) { skip(-((d && d.seekOffset) || 10)); });
    set('seekforward', function (d) { skip((d && d.seekOffset) || 10); });
    set('seekto', function (d) { if (d && typeof d.seekTime === 'number') seekTo(d.seekTime); });
  }

  /* ================================================================
     CATALOGUE : filtres + recherche
  ================================================================ */
  function initCatalogue() {
    var $grid = $('#km-grid');
    if (!$grid.length) return;
    var $cards = $grid.find('.km-cat-card'), $count = $('#km-count'), $empty = $('#km-empty-filter');
    var filter = 'all', q = '';
    var norm = function (s) {
      s = String(s || '').toLowerCase();
      return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
    };

    function apply() {
      var vis = 0;
      $cards.each(function () {
        var $c = $(this);
        var okF = filter === 'all' || String($c.data('filter') || '').split(' ').indexOf(filter) > -1;
        var okQ = !q || norm($c.data('search')).indexOf(q) > -1;
        var show = okF && okQ;
        $c.prop('hidden', !show);
        if (show) $c.css('--i', ++vis);
      });
      $count.text(vis ? tr_(vis > 1 ? T.sessions : T.session, vis) : '');
      $empty.toggleClass('show', $cards.length > 0 && vis === 0);
    }

    $('#km-filters').on('click', '.km-filter', function () {
      $('#km-filters .km-filter').removeClass('on').attr('aria-pressed', 'false');
      $(this).addClass('on').attr('aria-pressed', 'true');
      filter = String($(this).data('filter')); apply();
    });
    var timer;
    $('#km-search').on('input', function () {
      var v = this.value; clearTimeout(timer);
      timer = setTimeout(function () { q = norm(v.trim()); apply(); }, 120);
    }).on('keydown', function (e) { if (e.key === 'Escape') { this.value = ''; q = ''; apply(); } });
    // « / » place le curseur dans la recherche
    $(document).on('keydown', function (e) {
      if (e.key === '/' && !$(e.target).is('input,textarea,select') && !e.target.isContentEditable) { e.preventDefault(); $('#km-search').trigger('focus'); }
    });
    apply();
  }

  /* ================================================================
     MOT DE PASSE (AJAX, avec repli formulaire classique)
  ================================================================ */
  function initPasswordForm() {
    var $form = $('#km-gate-form'), $input = $('#km-pwd'), $btn = $('#km-pwd-btn'), $err = $('#km-err'), $etxt = $('#km-err-txt');
    if (!$input.length) return;
    var $label = $btn.find('.km-btn-label'), idle = $label.text();

    $('#km-pwd-eye').on('click', function () {
      var show = $input.attr('type') === 'password';
      $input.attr('type', show ? 'text' : 'password');
      $(this).toggleClass('is-on', show).attr('aria-label', show ? T.hidePwd : T.showPwd);
      $input.trigger('focus');
    });

    function showErr(msg) { $('.km-gate-err.show').not($err).remove(); $etxt.text(msg); $err.addClass('show'); }
    function reset() { $btn.prop('disabled', false); $label.text(idle); }

    $form.on('submit', function (e) {
      if (!cfg.ajaxUrl) return;                         // pas de config JS : envoi classique
      e.preventDefault();
      var pwd = $.trim($input.val());
      if (!pwd) { showErr(T.emptyPwd); $input.trigger('focus'); return; }
      $btn.prop('disabled', true); $label.text(T.checking); $err.removeClass('show');

      $.post(cfg.ajaxUrl, {
        action: 'km_verify_password', nonce: cfg.nonce || '', post_id: cfg.postId || 0, password: pwd
      }, null, 'json')
      .done(function (res) {
        if (res && res.success) {
          $label.text(T.granted); $btn.addClass('is-ok');
          setTimeout(function () { location.reload(); }, 500);
          return;
        }
        var data = (res && res.data) || {};
        showErr(data.message || T.badPwd);
        if (data.reload) { setTimeout(function () { location.reload(); }, 1800); return; }
        reset();
        $input.val('').addClass('km-shake').trigger('focus');
        setTimeout(function () { $input.removeClass('km-shake'); }, 450);
      })
      .fail(function () { showErr(T.netError); reset(); });
    });
  }

})(jQuery);
