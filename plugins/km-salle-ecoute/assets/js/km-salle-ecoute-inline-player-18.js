/**
 * KM SALLE D'ÉCOUTE v1.7.2 — Lecteur inline (shortcode [km_salle_ecoute_player])
 * Forme d'onde WaveSurfer.js v7 (hébergé localement), chargée au premier clic.
 * - Un seul morceau à la fois, même entre plusieurs lecteurs de la page
 * - Enchaînement automatique sur la piste suivante du même lecteur
 * - Couleurs résolues depuis les variables CSS (le canvas ne lit pas var())
 * - Clavier : Espace sur le bouton, flèches ←/→ (±5 s) sur la forme d'onde
 * - Repli automatique sur le lecteur natif <audio> si la forme d'onde est impossible
 *   (fichier hébergé sur un autre domaine sans CORS, bibliothèque bloquée…)
 */
(function ($) {
  'use strict';

  var T = $.extend({ playError: 'Lecture impossible — vérifiez votre connexion.', of: 'sur' },
    (window.kmEcouteInline && window.kmEcouteInline.i18n) || {});
  var instances = {}; // trackId -> { ws | native, $card, totalTxt }

  $(function () {
    $('.km-track-card[data-audio-url]').each(function () {
      var $card = $(this), tid = String($card.data('track-id')), url = $card.data('audio-url');
      var $btn = $card.find('.km-play-btn');
      if (!tid || !url || !$btn.length) return;
      $btn.on('click', function () { handlePlayClick(tid, String(url), $card); });
      $card.find('.km-waveform').on('keydown', function (e) {
        var inst = instances[tid]; if (!inst || !inst.ws) return;
        var d = inst.ws.getDuration(); if (!d) return;
        var step = { ArrowLeft: -5, ArrowRight: 5 }[e.key];
        if (step) { e.preventDefault(); inst.ws.setTime(Math.max(0, Math.min(d, inst.ws.getCurrentTime() + step))); }
      });
    });
  });

  function cssVar($el, name, fallback) {
    var v = window.getComputedStyle($el.get(0)).getPropertyValue(name);
    return (v && v.trim()) || fallback;
  }

  function play(p) { if (p && p.catch) p.catch(function () {}); }   // autoplay bloqué : pas d'erreur non gérée

  function handlePlayClick(tid, url, $card) {
    var inst = instances[tid];
    if (!inst) { createInstance(tid, url, $card, true); return; }
    if (inst.native) {                       // repli <audio>
      var a = inst.native;
      if (a.paused) { stopAllExcept(tid); play(a.play()); } else { a.pause(); }
      return;
    }
    if (!inst.ready) { inst.autoplay = !inst.autoplay; return; }
    if (inst.ws.isPlaying()) inst.ws.pause();
    else { stopAllExcept(tid); play(inst.ws.play()); }
  }

  function setPlaying($card, on) {
    $card.toggleClass('playing', on);
    $card.find('.km-play-btn').attr('aria-pressed', on ? 'true' : 'false');
  }

  /** Piste suivante dans le même lecteur (enchaînement). */
  function playNextAfter($card) {
    var $next = $card.nextAll('.km-track-card[data-audio-url]').first();
    if ($next.length) $next.find('.km-play-btn').trigger('click');
  }

  function createInstance(tid, url, $card, autoplay) {
    stopAllExcept(tid);
    if (typeof WaveSurfer === 'undefined') { fallbackNative(tid, url, $card); return; }

    var $wave = $card.find('.km-waveform');
    var totalTxt = String($card.find('.km-time-display').data('total') || '').trim();
    var accent = cssVar($card, '--km-accent', '#e8415c');

    $card.addClass('km-loading');

    var ws;
    try {
      ws = WaveSurfer.create({
        container: $wave.get(0),
        waveColor: 'rgba(255,255,255,.28)',
        progressColor: accent,
        cursorColor: 'rgba(255,255,255,.7)',
        cursorWidth: 1, barWidth: 2, barGap: 2, barRadius: 2,
        height: 44, normalize: true, dragToSeek: true, url: url
      });
    } catch (e) { $card.removeClass('km-loading'); fallbackNative(tid, url, $card); return; }

    var inst = instances[tid] = { ws: ws, $card: $card, totalTxt: totalTxt, ready: false, autoplay: autoplay };
    $wave.attr('tabindex', '0');

    ws.on('ready', function () {
      $card.removeClass('km-loading');
      inst.ready = true;
      if (!inst.totalTxt) inst.totalTxt = fmt(ws.getDuration());
      updateTime(tid, 0);
      if (inst.autoplay) { stopAllExcept(tid); play(ws.play()); }
    });
    ws.on('play',   function () { stopAllExcept(tid); setPlaying($card, true); });
    ws.on('pause',  function () { setPlaying($card, false); });
    ws.on('finish', function () { setPlaying($card, false); ws.setTime(0); updateTime(tid, 0); playNextAfter($card); });
    ws.on('timeupdate', function (t) { updateTime(tid, t); });
    ws.on('error', function (err) {
      // Forme d'onde impossible (CORS, format…) : on bascule sur le lecteur natif
      try { ws.destroy(); } catch (e) {}
      delete instances[tid];
      $wave.empty().attr('tabindex', '-1');
      $card.removeClass('km-loading playing');
      fallbackNative(tid, url, $card);
      if (window.console) console.warn('KM Salle d\'Écoute — forme d\'onde indisponible, lecteur natif utilisé.', err);
    });
  }

  function fallbackNative(tid, url, $card) {
    var $wave = $card.find('.km-waveform').empty();
    var a = new Audio();
    a.controls = true; a.preload = 'metadata'; a.src = url;
    a.setAttribute('playsinline', '');
    a.setAttribute('controlsList', 'nodownload');
    $wave.removeAttr('role').append(a);
    $card.find('.km-time-display').hide();
    instances[tid] = { native: a, ws: null, $card: $card };
    a.addEventListener('play',  function () { stopAllExcept(tid); setPlaying($card, true); });
    a.addEventListener('pause', function () { setPlaying($card, false); });
    a.addEventListener('ended', function () { setPlaying($card, false); playNextAfter($card); });
    a.addEventListener('error', function () {
      setPlaying($card, false);
      $wave.empty().append($('<span class="km-wave-error" role="alert">').text(T.playError));
    });
    play(a.play());
  }

  function updateTime(tid, t) {
    var inst = instances[tid]; if (!inst || !inst.$card) return;
    inst.$card.find('.km-time-display').text(fmt(t) + ' / ' + (inst.totalTxt || '—'));
    if (inst.ws) {
      var d = inst.ws.getDuration() || 0;
      inst.$card.find('.km-waveform').attr({
        'aria-valuenow': d ? Math.round(t / d * 100) : 0,
        'aria-valuetext': fmt(t) + (d ? ' ' + T.of + ' ' + fmt(d) : '')
      });
    }
  }

  function stopAllExcept(tid) {
    Object.keys(instances).forEach(function (key) {
      if (key === tid) return;
      var i = instances[key];
      if (i.native) { if (!i.native.paused) i.native.pause(); }
      else if (i.ws) { i.autoplay = false; if (i.ws.isPlaying()) i.ws.pause(); }
    });
    // Le lecteur principal de session (s'il est sur la même page) est aussi mis en pause
    var main = document.getElementById('km-audio');
    if (main && !main.paused) main.pause();
  }

  function fmt(s) {
    if (!s || isNaN(s) || !isFinite(s) || s < 0) return '0:00';
    var m = Math.floor(s / 60), sec = Math.floor(s % 60);
    return m + ':' + (sec < 10 ? '0' : '') + sec;
  }

  $(window).on('pagehide', function (e) {
    if (e.originalEvent && e.originalEvent.persisted) return;   // page conservée en cache (retour arrière)
    Object.keys(instances).forEach(function (k) { try { if (instances[k].ws) instances[k].ws.destroy(); } catch (e) {} });
  });
})(jQuery);
