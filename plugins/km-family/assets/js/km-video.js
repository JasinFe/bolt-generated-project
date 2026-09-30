/**
 * KM FAMILY — Lecteur vidéo (LOT 5)
 *
 * Comme le lecteur audio, il ne sait pas d'où vient le flux : il demande une
 * session au serveur et joue ce qu'on lui renvoie.
 *
 * HLS : Safari et iOS lisent nativement les manifestes HLS. Partout ailleurs,
 * on charge hls.js — servi depuis le serveur du site, jamais depuis un CDN
 * tiers, pour ne pas exposer les membres à une requête vers un domaine qu'ils
 * n'ont pas choisi.
 *
 * Le menu de qualité n'apparaît que si le pilote déclare `quality_menu`. Sur un
 * fichier servi localement, il n'y a qu'un seul débit : afficher un sélecteur
 * serait un bouton qui ne peut rien faire.
 */
(function () {
    'use strict';

    var CFG = window.KMFamilyPlayer || {};
    if (!CFG.rest) return;

    var HEARTBEAT_MS = 30000;
    var PROGRESS_MS  = 15000;

    function api(route, body) {
        return fetch(CFG.rest + route, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
            body: JSON.stringify(body || {})
        }).then(function (r) {
            return r.json().then(function (d) {
                if (!r.ok) throw Object.assign(new Error(d.message || 'Erreur'), { status: r.status });
                return d;
            });
        });
    }

    function chargerHls() {
        if (window.Hls) return Promise.resolve(window.Hls);
        if (!CFG.hlsUrl) return Promise.reject(new Error('hls.js indisponible'));

        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = CFG.hlsUrl;
            s.onload = function () { window.Hls ? resolve(window.Hls) : reject(new Error('hls.js illisible')); };
            s.onerror = function () { reject(new Error('hls.js introuvable')); };
            document.head.appendChild(s);
        });
    }

    function KMVideo(root) {
        this.root = root;
        this.contentId = parseInt(root.getAttribute('data-kmv-content'), 10);
        this.video = root.querySelector('video');
        this.session = null;
        this.hls = null;
        this.timers = {};
        this.bind();
        this.demarrer();
    }

    KMVideo.prototype.statut = function (msg, erreur) {
        var el = this.root.querySelector('[data-kmv-statut]');
        if (!el) return;
        el.textContent = msg || '';
        el.className = 'kmv-statut' + (erreur ? ' kmv-statut--err' : '');
    };

    KMVideo.prototype.demarrer = function () {
        var self = this;
        this.statut('Préparation…');

        api('playback/session', { content_id: this.contentId }).then(function (data) {
            self.session = data;
            self.statut('');
            self.poser(data);
            self.filigrane();
            self.timersDemarrer();
        }).catch(function (err) {
            self.statut(err.message || 'Lecture indisponible pour le moment.', true);
        });
    };

    /** Branche la source sur l'élément vidéo, selon le type renvoyé. */
    KMVideo.prototype.poser = function (data) {
        var self = this, v = this.video, src = data.playback.src;

        if (data.playback.poster) v.setAttribute('poster', data.playback.poster);

        if (data.playback.type !== 'hls') {
            v.src = src;
            this.reprendre(data.resume_at);
            return;
        }

        // Safari et iOS savent lire le HLS sans bibliothèque. Y forcer hls.js
        // dégraderait la lecture et empêcherait l'AirPlay.
        if (v.canPlayType('application/vnd.apple.mpegurl')) {
            v.src = src;
            this.reprendre(data.resume_at);
            return;
        }

        chargerHls().then(function (Hls) {
            if (!Hls.isSupported()) { self.statut('Votre navigateur ne peut pas lire cette vidéo.', true); return; }

            self.hls = new Hls({ capLevelToPlayerSize: true, startLevel: -1 });
            self.hls.loadSource(src);
            self.hls.attachMedia(v);

            self.hls.on(Hls.Events.MANIFEST_PARSED, function () {
                if (data.capabilities && data.capabilities.quality_menu) self.menuQualite(Hls);
                self.reprendre(data.resume_at);
            });

            self.hls.on(Hls.Events.ERROR, function (e, d) {
                if (!d.fatal) return;
                // Une erreur réseau sur une connexion mobile ivoirienne est la
                // norme, pas l'exception : on retente avant d'abandonner.
                if (d.type === Hls.ErrorTypes.NETWORK_ERROR) {
                    self.statut('Connexion instable, reprise…');
                    self.hls.startLoad();
                } else if (d.type === Hls.ErrorTypes.MEDIA_ERROR) {
                    self.hls.recoverMediaError();
                } else {
                    self.statut('Lecture interrompue. Rechargez la page.', true);
                    self.hls.destroy();
                }
            });
        }).catch(function () {
            self.statut('Lecture indisponible sur ce navigateur.', true);
        });
    };

    KMVideo.prototype.reprendre = function (secondes) {
        if (!secondes) return;
        var v = this.video, self = this;
        var once = function () {
            v.currentTime = secondes;
            v.removeEventListener('loadedmetadata', once);
            self.statut('Reprise à ' + Math.floor(secondes / 60) + ' min');
            setTimeout(function () { self.statut(''); }, 3000);
        };
        v.addEventListener('loadedmetadata', once);
    };

    KMVideo.prototype.menuQualite = function (Hls) {
        var self = this;
        var zone = this.root.querySelector('[data-kmv-qualite]');
        if (!zone || !this.hls) return;

        var sel = document.createElement('select');
        sel.className = 'kmv-qualite';
        sel.setAttribute('aria-label', 'Qualité vidéo');
        sel.innerHTML = '<option value="-1">Auto</option>';

        this.hls.levels.forEach(function (niveau, i) {
            var o = document.createElement('option');
            o.value = i;
            o.textContent = niveau.height ? niveau.height + 'p' : Math.round(niveau.bitrate / 1000) + ' kbps';
            sel.appendChild(o);
        });

        sel.addEventListener('change', function () { self.hls.currentLevel = parseInt(sel.value, 10); });
        zone.innerHTML = '';
        zone.appendChild(sel);
    };

    /**
     * Incrustation « KM FAMILY · Membre #… ».
     * Contournable par quelqu'un de technique — ce n'est pas le but. Le but est
     * qu'une capture qui circule porte le nom de celui qui l'a faite : rendre
     * la fuite traçable et dissuasive, pas impossible.
     */
    KMVideo.prototype.filigrane = function () {
        var texte = this.root.getAttribute('data-kmv-filigrane');
        if (!texte) return;

        var el = document.createElement('div');
        el.className = 'kmv-filigrane';
        el.textContent = texte;
        this.root.appendChild(el);

        // Déplacement lent : un filigrane fixe se recadre en deux clics.
        var positions = [
            { top: '8%',  left: '6%'  }, { top: '8%',  right: '6%' },
            { bottom: '14%', right: '6%' }, { bottom: '14%', left: '6%' }
        ];
        var i = 0;
        // CORRECTIF v3.3.5 : cet intervalle n'etait jamais conserve ni arrete. Il
        // continuait de tourner apres la fin de la lecture, et un second appel a
        // filigrane() en empilait un de plus sur le meme element.
        if (this.timers.mark) clearInterval(this.timers.mark);
        this.timers.mark = setInterval(function () {
            el.removeAttribute('style');
            var p = positions[i++ % positions.length];
            Object.keys(p).forEach(function (k) { el.style[k] = p[k]; });
        }, 22000);
    };

    KMVideo.prototype.bind = function () {
        var self = this, v = this.video;

        v.addEventListener('error', function () { self.statut('Lecture interrompue.', true); });
        window.addEventListener('pagehide', function () {
            self.progression(true);
            // Sans cela, les intervalles survivent dans le cache page-arriere des
            // navigateurs mobiles (bfcache) et continuent d'appeler l'API.
            self.timersArreter();
        });
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') self.progression(true);
        });
    };

    /**
     * CORRECTIF v3.3.5 — LECTEUR VIDEO : INTERVALLES EN DOUBLON ET JAMAIS ARRETES.
     * Le lecteur audio (km-player.js) possede un stopTimers() appele en tete de
     * startTimers() ; la version video n'avait aucun equivalent. Consequences reelles :
     *  - un second appel a timersDemarrer() (relance de la lecture, changement de
     *    source) ajoutait une PAIRE d'intervalles supplementaire, sans supprimer la
     *    precedente : battements de coeur et enregistrements de progression se
     *    multipliaient a chaque relance, chacun frappant l'API ;
     *  - lorsqu'une session etait revoquee (403), seul le battement etait arrete ;
     *    l'intervalle de progression continuait d'envoyer des requetes vers une
     *    session morte, indefiniment.
     */
    KMVideo.prototype.timersArreter = function () {
        if (this.timers.beat) clearInterval(this.timers.beat);
        if (this.timers.prog) clearInterval(this.timers.prog);
        this.timers.beat = null;
        this.timers.prog = null;
    };

    KMVideo.prototype.timersDemarrer = function () {
        var self = this;
        this.timersArreter();

        this.timers.beat = setInterval(function () {
            if (!self.session || self.video.paused) return;
            api('playback/heartbeat', { session_key: self.session.session_key }).catch(function (err) {
                if (err.status === 403) {
                    self.video.pause();
                    self.statut(err.message || 'Lecture interrompue.', true);
                    // Les DEUX intervalles s'arretent : la session n'existe plus.
                    self.timersArreter();
                }
            });
        }, HEARTBEAT_MS);

        this.timers.prog = setInterval(function () { self.progression(false); }, PROGRESS_MS);
    };

    KMVideo.prototype.progression = function (immediat) {
        var v = this.video;
        if (!v.duration || isNaN(v.duration) || v.currentTime < 1) return;

        var corps = {
            content_id: this.contentId,
            position: Math.floor(v.currentTime),
            duration: Math.floor(v.duration)
        };

        if (immediat && navigator.sendBeacon) {
            navigator.sendBeacon(
                CFG.rest + 'playback/progress?_wpnonce=' + encodeURIComponent(CFG.nonce),
                new Blob([JSON.stringify(corps)], { type: 'application/json' })
            );
            return;
        }
        api('playback/progress', corps).catch(function () {});
    };

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-kmv-content]'), function (el) {
            new KMVideo(el);
        });
    });
})();
