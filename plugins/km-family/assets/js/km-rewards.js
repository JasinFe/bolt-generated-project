/**
 * KM FAMILY — Échange de points contre une récompense.
 *
 * Le bouton est désactivé pendant l'appel : un double-clic sur « Échanger »
 * ne doit pas pouvoir débiter deux fois. Le serveur s'en protège aussi, mais
 * un membre ne devrait jamais avoir à compter sur cette seconde barrière.
 */
(function () {
    'use strict';

    var CFG = window.KMFamilyPlayer || window.KMFamilyHub || {};
    if (!CFG.rest) return;

    document.addEventListener('DOMContentLoaded', function () {
        var zone = document.querySelector('[data-kmf-recompenses]');
        if (!zone) return;

        Array.prototype.forEach.call(zone.querySelectorAll('[data-kmf-echanger]'), function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-kmf-echanger'), 10);
                var carte = btn.closest('.kmf-recompense');

                if (!window.confirm('Échanger vos points contre cette récompense ?')) return;

                btn.disabled = true;
                var libelle = btn.textContent;
                btn.textContent = '…';

                fetch(CFG.rest + 'rewards/redeem', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
                    body: JSON.stringify({ reward_id: id })
                }).then(function (r) {
                    return r.json().then(function (d) {
                        if (!r.ok) throw new Error(d.message || 'Échange impossible.');
                        return d;
                    });
                }).then(function (d) {
                    var ok = document.createElement('span');
                    ok.className = 'kmf-rec-etat kmf-rec-etat--ok';
                    ok.textContent = 'Échangé · code ' + d.code;
                    btn.replaceWith(ok);
                    // Le solde et l'historique changent : on recharge pour que
                    // l'affichage reflète exactement l'état serveur.
                    setTimeout(function () { window.location.reload(); }, 2500);
                }).catch(function (e) {
                    btn.disabled = false;
                    btn.textContent = libelle;
                    var err = document.createElement('span');
                    err.className = 'kmh-flash kmh-flash--err';
                    err.textContent = e.message;
                    carte.appendChild(err);
                    setTimeout(function () { err.remove(); }, 4000);
                });
            });
        });
    });
})();
