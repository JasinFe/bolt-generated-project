/**
 * KM FAMILY — Lecteur audio
 *
 * Sans dépendance : ni jQuery, ni bibliothèque de lecture. Le lecteur ne sait
 * pas d'où vient le flux — il demande une session au serveur et joue ce qu'on
 * lui renvoie. C'est ce qui permet de changer de mode de diffusion sans
 * réécrire une ligne d'interface.
 *
 * Il n'affiche un contrôle que si le pilote déclare la fonction correspondante
 * (capabilities). Une abstraction qui promettrait un sélecteur de qualité au
 * pilote local mentirait.
 */
(function () {
    'use strict';

    var CFG = window.KMFamilyPlayer || {};
    if (!CFG.rest) return;

    var HEARTBEAT_MS = 30000;  // maintien de session
    var PROGRESS_MS  = 15000;  // sauvegarde de la position

    function api(route, body) {
        return fetch(CFG.rest + route, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
            body: JSON.stringify(body || {})
        }).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok) throw Object.assign(new Error(data.message || 'Erreur'), { code: data.code, status: r.status });
                return data;
            });
        });
    }

    function fmt(sec) {
        sec = Math.max(0, Math.floor(sec || 0));
        var m = Math.floor(sec / 60), s = sec % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    function KMPlayer(root) {
        this.root   = root;
        this.audio  = new Audio();
        this.audio.preload = 'none';
        this.tracks = [];
        this.index  = -1;
        this.session = null;
        this.repeat = false;
        this.shuffle = false;
        this.order  = [];
        this.timers = {};

        this.collect();
        this.build();
        this.bind();
    }

    /* ── Pistes déclarées dans le DOM ─────────────────────────── */
    KMPlayer.prototype.collect = function () {
        var self = this;
        this.items = Array.prototype.slice.call(this.root.querySelectorAll('[data-kmp-track]'));
        this.items.forEach(function (el, i) {
            self.tracks.push({
                id:       parseInt(el.getAttribute('data-kmp-track'), 10),
                title:    el.getAttribute('data-kmp-title') || '',
                artist:   el.getAttribute('data-kmp-artist') || '',
                cover:    el.getAttribute('data-kmp-cover') || '',
                exclusive: el.getAttribute('data-kmp-exclusive') === '1',
                favorite: el.getAttribute('data-kmp-favorite') === '1',
                el: el
            });
            el.addEventListener('click', function (e) {
                if (e.target.closest('[data-kmp-fav]')) return;
                e.preventDefault();
                self.play(i);
            });
        });
        this.resetOrder();
    };

    KMPlayer.prototype.resetOrder = function () {
        this.order = this.tracks.map(function (_, i) { return i; });
        if (this.shuffle) {
            for (var i = this.order.length - 1; i > 0; i--) {
                var j = Math.floor(Math.random() * (i + 1));
                var t = this.order[i]; this.order[i] = this.order[j]; this.order[j] = t;
            }
        }
    };

    /* ── Barre de lecture ─────────────────────────────────────── */
    KMPlayer.prototype.build = function () {
        var bar = document.createElement('div');
        bar.className = 'kmp-bar kmp-bar--hidden';
        bar.innerHTML =
            '<div class="kmp-bar-inner">' +
              '<div class="kmp-now">' +
                '<div class="kmp-cover"></div>' +
                '<div class="kmp-meta">' +
                  '<span class="kmp-title"></span>' +
                  '<span class="kmp-artist"></span>' +
                '</div>' +
                '<button class="kmp-btn kmp-fav" type="button" aria-label="Ajouter aux favoris">♡</button>' +
              '</div>' +
              '<div class="kmp-controls">' +
                '<div class="kmp-buttons">' +
                  '<button class="kmp-btn kmp-shuffle" type="button" aria-label="Lecture aléatoire">⤮</button>' +
                  '<button class="kmp-btn kmp-prev" type="button" aria-label="Piste précédente">⏮</button>' +
                  '<button class="kmp-btn kmp-play kmp-btn--main" type="button" aria-label="Lecture">▶</button>' +
                  '<button class="kmp-btn kmp-next" type="button" aria-label="Piste suivante">⏭</button>' +
                  '<button class="kmp-btn kmp-repeat" type="button" aria-label="Répéter">↻</button>' +
                '</div>' +
                '<div class="kmp-seek">' +
                  '<span class="kmp-time kmp-elapsed">0:00</span>' +
                  '<input class="kmp-range" type="range" min="0" max="1000" value="0" step="1" aria-label="Position" />' +
                  '<span class="kmp-time kmp-remaining">-0:00</span>' +
                '</div>' +
              '</div>' +
              '<div class="kmp-extras">' +
                '<span class="kmp-badge kmp-badge--hidden">EXCLUSIF KM FAMILY</span>' +
                '<select class="kmp-speed" aria-label="Vitesse de lecture">' +
                  '<option value="0.75">0,75×</option>' +
                  '<option value="1" selected>1×</option>' +
                  '<option value="1.25">1,25×</option>' +
                  '<option value="1.5">1,5×</option>' +
                  '<option value="2">2×</option>' +
                '</select>' +
                '<input class="kmp-volume" type="range" min="0" max="100" value="100" aria-label="Volume" />' +
              '</div>' +
              '<div class="kmp-status" role="status" aria-live="polite"></div>' +
            '</div>';

        document.body.appendChild(bar);
        this.bar = bar;

        this.el = {
            cover:  bar.querySelector('.kmp-cover'),
            title:  bar.querySelector('.kmp-title'),
            artist: bar.querySelector('.kmp-artist'),
            play:   bar.querySelector('.kmp-play'),
            prev:   bar.querySelector('.kmp-prev'),
            next:   bar.querySelector('.kmp-next'),
            fav:    bar.querySelector('.kmp-fav'),
            shuffle: bar.querySelector('.kmp-shuffle'),
            repeat: bar.querySelector('.kmp-repeat'),
            range:  bar.querySelector('.kmp-range'),
            elapsed: bar.querySelector('.kmp-elapsed'),
            remaining: bar.querySelector('.kmp-remaining'),
            speed:  bar.querySelector('.kmp-speed'),
            volume: bar.querySelector('.kmp-volume'),
            badge:  bar.querySelector('.kmp-badge'),
            status: bar.querySelector('.kmp-status')
        };
    };

    KMPlayer.prototype.status = function (msg, kind) {
        this.el.status.textContent = msg || '';
        this.el.status.className = 'kmp-status' + (msg ? ' kmp-status--' + (kind || 'info') : '');
    };

    /* ── Événements ───────────────────────────────────────────── */
    KMPlayer.prototype.bind = function () {
        var self = this, a = this.audio;

        this.el.play.addEventListener('click', function () { self.toggle(); });
        this.el.prev.addEventListener('click', function () { self.step(-1); });
        this.el.next.addEventListener('click', function () { self.step(1); });

        this.el.shuffle.addEventListener('click', function () {
            self.shuffle = !self.shuffle;
            this.classList.toggle('is-on', self.shuffle);
            self.resetOrder();
        });
        this.el.repeat.addEventListener('click', function () {
            self.repeat = !self.repeat;
            this.classList.toggle('is-on', self.repeat);
        });

        this.el.fav.addEventListener('click', function () { self.toggleFavorite(); });

        this.el.speed.addEventListener('change', function () { a.playbackRate = parseFloat(this.value); });
        this.el.volume.addEventListener('input', function () { a.volume = this.value / 100; });

        // Le seek n'est appliqué qu'au relâchement : pendant le glissement,
        // on gèle la mise à jour automatique pour que le curseur ne saute pas
        // sous le doigt.
        this.seeking = false;
        this.el.range.addEventListener('input', function () {
            self.seeking = true;
            if (a.duration) self.el.elapsed.textContent = fmt(this.value / 1000 * a.duration);
        });
        this.el.range.addEventListener('change', function () {
            if (a.duration) a.currentTime = this.value / 1000 * a.duration;
            self.seeking = false;
        });

        a.addEventListener('timeupdate', function () { self.tick(); });
        a.addEventListener('ended', function () { self.onEnded(); });
        a.addEventListener('play',  function () { self.el.play.textContent = '❚❚'; self.el.play.setAttribute('aria-label', 'Pause'); });
        a.addEventListener('pause', function () { self.el.play.textContent = '▶'; self.el.play.setAttribute('aria-label', 'Lecture'); });
        a.addEventListener('error', function () {
            self.status('Lecture interrompue. Rechargez la page pour reprendre.', 'err');
        });

        // Les favoris de la liste restent cliquables sans lancer la lecture.
        Array.prototype.forEach.call(this.root.querySelectorAll('[data-kmp-fav]'), function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                var id = parseInt(btn.getAttribute('data-kmp-fav'), 10);
                var on = btn.getAttribute('aria-pressed') === 'true';
                api('playback/favorite', { content_id: id, state: !on }).then(function (r) {
                    btn.setAttribute('aria-pressed', r.favorite ? 'true' : 'false');
                    btn.textContent = r.favorite ? '❤' : '♡';
                    var t = self.tracks.filter(function (x) { return x.id === id; })[0];
                    if (t) t.favorite = r.favorite;
                    if (self.current() && self.current().id === id) self.paintFavorite();
                }).catch(function () {});
            });
        });

        // Intégration au système du téléphone : écran verrouillé, casque,
        // commandes Bluetooth. C'est ce qui donne l'impression d'une vraie
        // application plutôt que d'une page web qui joue du son.
        if ('mediaSession' in navigator) {
            navigator.mediaSession.setActionHandler('play',  function () { self.audio.play(); });
            navigator.mediaSession.setActionHandler('pause', function () { self.audio.pause(); });
            navigator.mediaSession.setActionHandler('previoustrack', function () { self.step(-1); });
            navigator.mediaSession.setActionHandler('nexttrack',     function () { self.step(1); });
        }

        // Quitter la page sans perdre la position en cours.
        window.addEventListener('pagehide', function () { self.saveProgress(true); });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') self.saveProgress(true);
        });
    };

    KMPlayer.prototype.current = function () {
        return this.index >= 0 ? this.tracks[this.index] : null;
    };

    /* ── Lecture ──────────────────────────────────────────────── */
    KMPlayer.prototype.play = function (i) {
        var self = this;
        if (i === this.index && this.session) { this.toggle(); return; }

        var track = this.tracks[i];
        if (!track) return;

        this.saveProgress(true);
        this.index = i;
        this.paint(track);
        this.bar.classList.remove('kmp-bar--hidden');
        this.status('Préparation…');

        api('playback/session', { content_id: track.id }).then(function (data) {
            self.session = data;

            // On n'affiche un contrôle que si le pilote l'annonce.
            self.bar.classList.toggle('kmp-has-quality', !!(data.capabilities && data.capabilities.quality_menu));

            self.audio.src = data.playback.src;
            self.audio.load();

            if (data.resume_at > 0) {
                var once = function () {
                    self.audio.currentTime = data.resume_at;
                    self.audio.removeEventListener('loadedmetadata', once);
                };
                self.audio.addEventListener('loadedmetadata', once);
                self.status('Reprise à ' + fmt(data.resume_at));
            } else {
                self.status('');
            }

            self.markActive();
            self.startTimers();
            return self.audio.play();
        }).catch(function (err) {
            if (err.status === 429) {
                self.status(err.message, 'err');
            } else if (err.status === 403) {
                self.status(err.message || 'Ce contenu n\'est pas accessible avec votre palier.', 'err');
            } else {
                self.status('Lecture indisponible pour le moment.', 'err');
            }
            self.index = -1;
        });
    };

    KMPlayer.prototype.toggle = function () {
        if (this.audio.paused) { this.audio.play(); } else { this.audio.pause(); }
    };

    KMPlayer.prototype.step = function (dir) {
        if (!this.tracks.length) return;
        var pos = this.order.indexOf(this.index);
        var next = this.order[(pos + dir + this.order.length) % this.order.length];
        this.play(next);
    };

    KMPlayer.prototype.onEnded = function () {
        this.saveProgress(true);
        if (this.repeat) { this.audio.currentTime = 0; this.audio.play(); return; }
        if (this.tracks.length > 1) this.step(1);
    };

    /* ── Affichage ────────────────────────────────────────────── */
    KMPlayer.prototype.paint = function (t) {
        this.el.title.textContent  = t.title;
        this.el.artist.textContent = t.artist;
        this.el.cover.style.backgroundImage = t.cover ? 'url("' + t.cover + '")' : '';
        this.el.badge.classList.toggle('kmp-badge--hidden', !t.exclusive);
        this.paintFavorite();

        if ('mediaSession' in navigator && window.MediaMetadata) {
            navigator.mediaSession.metadata = new MediaMetadata({
                title: t.title,
                artist: t.artist,
                album: 'KM Family',
                artwork: t.cover ? [{ src: t.cover, sizes: '512x512' }] : []
            });
        }
    };

    KMPlayer.prototype.paintFavorite = function () {
        var t = this.current();
        if (!t) return;
        this.el.fav.textContent = t.favorite ? '❤' : '♡';
        this.el.fav.classList.toggle('is-on', !!t.favorite);
    };

    KMPlayer.prototype.markActive = function () {
        var self = this;
        this.items.forEach(function (el, i) { el.classList.toggle('is-playing', i === self.index); });
    };

    KMPlayer.prototype.tick = function () {
        var a = this.audio;
        if (!a.duration || isNaN(a.duration)) return;
        if (!this.seeking) this.el.range.value = Math.round(a.currentTime / a.duration * 1000);
        this.el.elapsed.textContent   = fmt(a.currentTime);
        this.el.remaining.textContent = '-' + fmt(a.duration - a.currentTime);
    };

    KMPlayer.prototype.toggleFavorite = function () {
        var self = this, t = this.current();
        if (!t) return;
        api('playback/favorite', { content_id: t.id, state: !t.favorite }).then(function (r) {
            t.favorite = r.favorite;
            self.paintFavorite();
            var btn = self.root.querySelector('[data-kmp-fav="' + t.id + '"]');
            if (btn) { btn.setAttribute('aria-pressed', r.favorite ? 'true' : 'false'); btn.textContent = r.favorite ? '❤' : '♡'; }
        }).catch(function () {});
    };

    /* ── Session et position ──────────────────────────────────── */
    KMPlayer.prototype.startTimers = function () {
        var self = this;
        this.stopTimers();

        this.timers.beat = setInterval(function () {
            if (!self.session || self.audio.paused) return; // une lecture en pause n'a pas à tenir la session en vie
            api('playback/heartbeat', { session_key: self.session.session_key }).catch(function (err) {
                if (err.status === 403) {
                    self.audio.pause();
                    self.status(err.message || 'Lecture interrompue.', 'err');
                    self.stopTimers();
                }
            });
        }, HEARTBEAT_MS);

        this.timers.prog = setInterval(function () { self.saveProgress(false); }, PROGRESS_MS);
    };

    KMPlayer.prototype.stopTimers = function () {
        if (this.timers.beat) clearInterval(this.timers.beat);
        if (this.timers.prog) clearInterval(this.timers.prog);
        this.timers = {};
    };

    KMPlayer.prototype.saveProgress = function (immediate) {
        var t = this.current(), a = this.audio;
        if (!t || !a.duration || isNaN(a.duration) || a.currentTime < 1) return;

        var body = JSON.stringify({
            content_id: t.id,
            position: Math.floor(a.currentTime),
            duration: Math.floor(a.duration)
        });

        // À la fermeture de l'onglet, fetch() est souvent annulé avant d'aboutir.
        // sendBeacon survit à la navigation — sans lui, la position de reprise
        // serait perdue exactement au moment où elle est la plus utile.
        if (immediate && navigator.sendBeacon) {
            var url = CFG.rest + 'playback/progress?_wpnonce=' + encodeURIComponent(CFG.nonce);
            navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }));
            return;
        }

        api('playback/progress', JSON.parse(body)).catch(function () {});
    };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-kmp-playlist]'), function (root) {
            new KMPlayer(root);
        });
    });
})();
