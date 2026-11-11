/**
 * KOPHI'S MUSIC STUDIO — Smart Payment Center (front)
 * Nécessite : window.KophiPayment (localisé par includes/payment-center.php)
 *           : QRCode (assets/js/vendor/qrcode.min.js)
 */
(function() {
    'use strict';

    function $id(id) { return document.getElementById(id); }

    function post(path, data) {
        return fetch(KophiPayment.rest + 'paiement/' + KophiPayment.order_ref + path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': KophiPayment.nonce },
            body: JSON.stringify(data || {})
        }).then(function(r) { return r.json(); });
    }

    function get(path) {
        return fetch(KophiPayment.rest + 'paiement/' + KophiPayment.order_ref + path, {
            headers: { 'X-WP-Nonce': KophiPayment.nonce }
        }).then(function(r) { return r.json(); });
    }

    var state = { methods: {}, current: null, order: null };

    function renderQR(el, text) {
        el.innerHTML = '';
        new QRCode(el, {
            text: text,
            width: 190, height: 190,
            colorDark: '#000000', colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    function fmtFCFA(n) {
        return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' FCFA';
    }

    function markInitiated(gateway) {
        post('/initiated', { gateway: gateway }).catch(function() {});
    }

    function goToMerchant(key) {
        var method = state.methods[key];
        if (!method) return;
        markInitiated(key);
        var target = KophiPayment.is_mobile ? '_self' : '_blank';
        window.open(method.merchant_link, target);
    }

    function goToCinetpay() {
        var $btn = $id('kspc-btn-pay');
        $btn.disabled = true;
        $btn.textContent = 'Connexion à CinetPay…';
        post('/cinetpay-init', {}).then(function(res) {
            if (res && res.payment_url) {
                markInitiated('cinetpay');
                window.location.href = res.payment_url;
            } else {
                alert((res && res.message) || "Impossible de contacter CinetPay pour le moment. Essaie un autre moyen ci-dessus.");
                $btn.disabled = false;
                $btn.textContent = 'Payer maintenant';
            }
        }).catch(function() {
            alert('Erreur réseau. Réessaie.');
            $btn.disabled = false;
            $btn.textContent = 'Payer maintenant';
        });
    }

    function selectMethod(key) {
        state.current = key;
        document.querySelectorAll('.kspc-method-tab').forEach(function(t) {
            t.classList.toggle('is-active', t.dataset.method === key);
        });

        var $btn = $id('kspc-btn-pay');
        var $qrWrap = $id('kspc-qr-wrap');

        if (key === 'cinetpay') {
            $qrWrap.style.display = 'none';
            $btn.textContent = 'Payer maintenant';
            $btn.onclick = goToCinetpay;
        } else {
            var method = state.methods[key];
            $qrWrap.style.display = '';
            renderQR($id('kspc-qr-box'), method.qr_url);
            $btn.textContent = '💳 Payer avec ' + method.label;
            $btn.onclick = function() { goToMerchant(key); };
        }
    }

    function renderTabs() {
        var $tabs = $id('kspc-tabs');
        $tabs.innerHTML = '';
        var keys = Object.keys(state.methods);

        keys.forEach(function(key) {
            var m = state.methods[key];
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'kspc-method-tab';
            btn.dataset.method = key;
            btn.innerHTML = (m.icon || '') + ' ' + m.label;
            btn.addEventListener('click', function() { selectMethod(key); });
            $tabs.appendChild(btn);
        });

        if (keys.length) selectMethod(keys[0]);
    }

    function initDeclareForm() {
        $id('kspc-declare-toggle').addEventListener('click', function() {
            var $form = $id('kspc-declare-form');
            var open = $form.style.display !== 'none';
            $form.style.display = open ? 'none' : 'flex';
        });

        $id('kspc-btn-declare').addEventListener('click', function() {
            var reference = $id('kspc-declare-ref').value.trim();
            var note = $id('kspc-declare-note').value.trim();
            var $msg = $id('kspc-declare-msg');

            if (!reference) {
                $msg.textContent = 'Merci d\'indiquer la référence de ta transaction.';
                $msg.className = 'kspc-declare-msg is-error';
                $msg.style.display = 'block';
                return;
            }

            var $btn = $id('kspc-btn-declare');
            $btn.disabled = true;
            $btn.textContent = 'Envoi…';

            post('/declare', { reference: reference, note: note, gateway: state.current }).then(function(res) {
                $btn.disabled = false;
                $btn.textContent = 'Envoyer ma confirmation';
                if (res && res.ok) {
                    $msg.textContent = "Merci ! Notre équipe vérifie et confirme ton paiement très vite.";
                    $msg.className = 'kspc-declare-msg is-success';
                    $msg.style.display = 'block';
                    $id('kspc-declare-ref').disabled = true;
                    $id('kspc-declare-note').disabled = true;
                    $btn.disabled = true;
                    startPolling();
                } else {
                    $msg.textContent = (res && res.message) || 'Une erreur est survenue.';
                    $msg.className = 'kspc-declare-msg is-error';
                    $msg.style.display = 'block';
                }
            }).catch(function() {
                $btn.disabled = false;
                $btn.textContent = 'Envoyer ma confirmation';
                $msg.textContent = 'Erreur réseau. Réessaie.';
                $msg.className = 'kspc-declare-msg is-error';
                $msg.style.display = 'block';
            });
        });
    }

    var pollTimer = null;
    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(function() {
            get('/status').then(function(res) {
                if (!res) return;
                if (res.statut === 'confirmed') {
                    clearInterval(pollTimer);
                    showStatus('✅', 'Paiement confirmé — merci !', res.licence_url);
                } else if (res.payment_status === 'declared') {
                    showStatus('⏳', 'Paiement déclaré, vérification en cours par notre équipe…');
                }
            }).catch(function() {});
        }, 8000);
    }

    function showStatus(icon, text, licenceUrl) {
        $id('kspc-status-card').style.display = 'block';
        $id('kspc-status-icon').textContent = icon;
        $id('kspc-status-text').textContent = text;
        if (licenceUrl) {
            var $lic = $id('kspc-licence-link');
            $lic.href = licenceUrl;
            $lic.style.display = 'block';
        }
    }

    function boot() {
        get('').then(function(order) {
            if (!order || order.code) return; // erreur REST (WP_Error a un champ "code")

            state.order = order;
            state.methods = {};

            Object.keys(order.manual_methods || {}).forEach(function(key) {
                state.methods[key] = order.manual_methods[key];
            });
            if (order.cinetpay) {
                state.methods.cinetpay = { label: 'Mobile Money / Carte (auto)', icon: '⚡', merchant_link: null };
            }

            $id('kspc-service').textContent = order.service_label || 'Paiement KOPHI STUDIO';
            $id('kspc-montant').textContent = fmtFCFA(order.montant);
            $id('kspc-wa-link').href = KophiPayment.wa_link + '?text=' + encodeURIComponent(
                "Bonjour, je souhaite régler : " + order.service_label + " (réf. " + order.order_ref + ")"
            );

            if (order.statut === 'confirmed') {
                showStatus('✅', 'Paiement déjà confirmé — merci !', order.payment_status === 'paid' ? null : null);
                get('/status').then(function(s) { if (s.licence_url) showStatus('✅', 'Paiement confirmé — merci !', s.licence_url); });
                return;
            }

            if (Object.keys(state.methods).length === 0) {
                $id('kspc-methods-card').innerHTML = '<p style="color:var(--kf-text3)">Aucun moyen de paiement en ligne configuré pour le moment. Contacte-nous sur WhatsApp ci-dessous pour régler.</p>';
                $id('kspc-methods-card').style.display = 'block';
                return;
            }

            $id('kspc-methods-card').style.display = 'block';
            renderTabs();
            initDeclareForm();

            $id('kspc-btn-copy').addEventListener('click', function() {
                var url = window.location.href;
                navigator.clipboard.writeText(url).then(function() {
                    var $btn = $id('kspc-btn-copy');
                    $btn.classList.add('is-copied');
                    $btn.textContent = '✅ Lien copié';
                    setTimeout(function() {
                        $btn.classList.remove('is-copied');
                        $btn.textContent = '📋 Copier le lien';
                    }, 2000);
                });
            });

            if (order.payment_status === 'declared') startPolling();
        });
    }

    document.addEventListener('DOMContentLoaded', boot);
})();
