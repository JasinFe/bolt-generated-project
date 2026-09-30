/**
 * KM FAMILY — Fan Hub : gestion des playlists.
 * Sans dépendance. Les actions sont optimistes le moins possible : on ne
 * modifie l'affichage qu'après confirmation du serveur, pour ne jamais montrer
 * au membre une playlist qui n'existe pas.
 */
(function () {
    'use strict';

    var CFG = window.KMFamilyHub || {};
    if (!CFG.rest) return;

    function api(route, method, body) {
        return fetch(CFG.rest + route, {
            method: method || 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().then(function (d) {
                if (!r.ok) throw new Error(d.message || CFG.i18n.erreur);
                return d;
            });
        });
    }

    function flash(el, texte, erreur) {
        var n = document.createElement('span');
        n.className = 'kmh-flash' + (erreur ? ' kmh-flash--err' : '');
        n.textContent = texte;
        el.appendChild(n);
        setTimeout(function () { n.remove(); }, 2600);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var hub = document.querySelector('[data-kmh-hub]');
        if (!hub) return;

        /* ── Créer ─────────────────────────────────────────── */
        var creer = hub.querySelector('[data-kmh-creer]');
        if (creer) {
            creer.addEventListener('click', function () {
                var nom = window.prompt(CFG.i18n.nouvelle, '');
                if (nom === null) return;             // annulation
                if (!nom.trim()) return;              // nom vide : on ne crée rien
                api('playlists', 'POST', { nom: nom.trim() })
                    .then(function () { window.location.reload(); })
                    .catch(function (e) { flash(creer.parentNode, e.message, true); });
            });
        }

        /* ── Renommer / supprimer ──────────────────────────── */
        Array.prototype.forEach.call(hub.querySelectorAll('[data-kmh-playlist]'), function (carte) {
            var id = carte.getAttribute('data-kmh-playlist');

            var btnR = carte.querySelector('[data-kmh-renommer]');
            if (btnR) btnR.addEventListener('click', function () {
                var actuel = carte.querySelector('.kmh-collection-nom').textContent.trim();
                var nom = window.prompt(CFG.i18n.renommer, actuel);
                if (nom === null || !nom.trim() || nom.trim() === actuel) return;
                api('playlists/' + id, 'POST', { nom: nom.trim() })
                    .then(function () { carte.querySelector('.kmh-collection-nom').textContent = nom.trim(); })
                    .catch(function (e) { flash(carte, e.message, true); });
            });

            var btnS = carte.querySelector('[data-kmh-supprimer]');
            if (btnS) btnS.addEventListener('click', function () {
                if (!window.confirm(CFG.i18n.confirmer)) return;
                api('playlists/' + id, 'DELETE')
                    .then(function () { carte.remove(); })
                    .catch(function (e) { flash(carte, e.message, true); });
            });
        });

        /* ── Ajouter un titre à une playlist ───────────────── */
        Array.prototype.forEach.call(hub.querySelectorAll('[data-kmh-ajout]'), function (bloc) {
            var contentId = parseInt(bloc.getAttribute('data-kmh-ajout'), 10);
            var btn = bloc.querySelector('.kmh-ajout-btn');
            var sel = bloc.querySelector('.kmh-ajout-select');

            btn.addEventListener('click', function () {
                bloc.classList.toggle('is-ouvert');
                if (bloc.classList.contains('is-ouvert')) sel.focus();
            });

            sel.addEventListener('change', function () {
                var pid = sel.value;
                if (!pid) return;
                api('playlists/' + pid + '/items', 'POST', { content_id: contentId })
                    .then(function () {
                        bloc.classList.remove('is-ouvert');
                        sel.value = '';
                        btn.textContent = '✓';
                        setTimeout(function () { btn.textContent = '+'; }, 1800);
                    })
                    .catch(function (e) {
                        sel.value = '';
                        flash(bloc, e.message, true);
                    });
            });
        });
    });
})();
