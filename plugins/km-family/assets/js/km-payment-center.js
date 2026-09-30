/**
 * KM Family — Smart Payment Center (frontend logic)
 *
 * Dépend de :
 *  - window.KMFamily      (global fourni par km-family.js : ajax_url, nonce)
 *  - window.KMFamilyPayment (localisé par la classe PHP Payment Center : i18n, is_mobile)
 *  - window.QRCode         (librairie vendorisée assets/js/vendor/qrcode.min.js)
 *  - #kmfamily-order-data  (JSON injecté par templates/payment-center.php)
 */
(function ($) {
    'use strict';

    var STEP_LABELS = ['En attente', 'Paiement initié', 'Paiement confirmé', 'Abonnement actif'];

    var state = {
        order: null,
        methods: {},
        activeMethod: null,
        pollTimer: null,
        polling: false,
        pollStart: 0,
        pollPaused: false,
    };

    /**
     * CORRECTIF v3.3.5 — SCRUTATION SANS FIN DU STATUT DE COMMANDE.
     * L'ancienne boucle interrogeait le serveur TOUTES LES 4 SECONDES, indefiniment :
     * elle ne s'arretait que sur les statuts 'active', 'expired' et 'cancelled'. Une
     * commande laissee en attente (onglet oublie ouvert, paiement jamais finalise, ou
     * commande REJETEE par l'administrateur — statut qui n'etait pas traite du tout)
     * generait donc une requete toutes les 4 s pour toujours. Sur un forfait mobile,
     * c'est environ 900 requetes par heure a la charge de la personne qui paie, et
     * autant pour le serveur, multipliees par le nombre d'onglets ouverts.
     *
     * Trois garde-fous : un espacement progressif, une mise en pause quand l'onglet
     * n'est pas visible (l'utilisateur est parti payer dans son application), et un
     * arret definitif au bout d'une duree qui depasse largement un paiement reel.
     */
    var POLL_RAPIDE_MS   = 4000;      // les 2 premieres minutes : retour quasi immediat
    var POLL_NORMAL_MS   = 10000;     // ensuite
    var POLL_LENT_MS     = 30000;     // au-dela de 10 minutes
    var POLL_MAX_MS      = 45 * 60 * 1000;  // arret complet apres 45 minutes
    var STATUTS_FINAUX   = ['active', 'expired', 'cancelled', 'rejected', 'failed'];

    function pollIntervalle(ecoule) {
        if (ecoule < 2 * 60 * 1000) return POLL_RAPIDE_MS;
        if (ecoule < 10 * 60 * 1000) return POLL_NORMAL_MS;
        return POLL_LENT_MS;
    }

    function $id(id) { return document.getElementById(id); }

    function readOrderData() {
        var el = $id('kmfamily-order-data');
        if (!el) return null;
        try { return JSON.parse(el.textContent); } catch (e) { return null; }
    }

    function urlParam(name) {
        var m = new RegExp('[?&]' + name + '=([^&]*)').exec(window.location.search);
        return m ? decodeURIComponent(m[1]) : null;
    }

    // BUGFIX : wp_is_mobile() (PHP) ne détecte PAS les iPad comme mobile — Safari sur
    // iPadOS se présente en desktop depuis iPadOS 13, ce qui cassait le comportement
    // "mobile" (ouverture du lien marchand dans le même onglet, QR replié par défaut)
    // sur tablette. On complète le signal serveur par une détection côté client basée
    // sur la taille d'écran et le support tactile, et on retient le résultat le plus
    // permissif des deux (mobile dès que l'un des deux signaux le dit).
    function isMobileOrTablet() {
        var serverSaysMobile = !!(window.KMFamilyPayment && window.KMFamilyPayment.is_mobile);
        var narrowViewport = window.matchMedia && window.matchMedia('(max-width: 1024px)').matches;
        var touchCapable = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
        return serverSaysMobile || (narrowViewport && touchCapable);
    }

    // -----------------------------------------------------------------
    // Timeline
    // -----------------------------------------------------------------
    function renderTimeline(stage) {
        var $wrap = $('.kmfamily-spc__timeline');
        if (!$wrap.length) return;
        $wrap.empty();
        for (var i = 1; i <= 4; i++) {
            var cls = 'kmfamily-spc__step';
            if (i < stage) cls += ' is-done';
            else if (i === stage) cls += ' is-current';
            var $step = $('<div class="' + cls + '"><div class="kmfamily-spc__step-dot">' +
                (i < stage ? '✓' : i) + '</div><div class="kmfamily-spc__step-label">' + STEP_LABELS[i - 1] + '</div></div>');
            $wrap.append($step);
        }
    }

    function renderBanner(status) {
        var $b = $('.kmfamily-spc__banner-slot');
        if (!$b.length) return;
        $b.empty();
        if (status === 'active') {
            $b.append('<div class="kmfamily-spc__banner kmfamily-spc__banner--success">✅ ' + KMFamilyPayment.i18n.confirmed + '</div>');
        } else if (status === 'awaiting_confirmation') {
            $b.append('<div class="kmfamily-spc__banner kmfamily-spc__banner--pending">⏳ Ton paiement est en cours de vérification par notre équipe — quelques minutes en général.</div>');
        } else if (status === 'expired' || status === 'cancelled') {
            $b.append('<div class="kmfamily-spc__banner kmfamily-spc__banner--error">Cette commande n\'est plus valide. Merci de recommencer depuis la page des paliers.</div>');
        }
    }

    // -----------------------------------------------------------------
    // AJAX helpers
    // -----------------------------------------------------------------
    function post(action, data, done, fail) {
        data = data || {};
        data.action = action;
        data.nonce = window.KMFamily ? window.KMFamily.nonce : '';
        // BUGFIX : aucune gestion d'échec (.fail()) n'existait — si la requête échouait
        // (erreur réseau/serveur, ou une action AJAX non enregistrée côté serveur
        // renvoyant "0" au lieu de JSON, comme cela a été le cas pour la déclaration de
        // paiement), rien ne se passait : pas de message, bouton bloqué indéfiniment
        // sur son état "en cours" — exactement le symptôme observé.
        $.post(window.KMFamily.ajax_url, data).done(done).fail(function (jqXHR) {
            // Défense en profondeur : certaines réponses d'erreur (ex. limitation de
            // débit) contiennent un vrai message JSON malgré un code HTTP non-2xx —
            // jQuery les traite comme un échec réseau, mais le message reste lisible
            // dans responseJSON. On le remonte plutôt que d'afficher un message
            // générique qui laisse croire que tout le système est cassé.
            var msg = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message;
            if (fail) fail(msg);
        });
    }

    function markInitiated(gateway, onResult) {
        var payload = { order_ref: state.order.order_ref, gateway: gateway };
        var handle = function (res) {
            if (typeof onResult === 'function') onResult(!!(res && res.success));
        };
        var handleFail = function () {
            if (typeof onResult === 'function') onResult(false);
        };
        // BUGFIX : aucun repli REST n'existait ici (contrairement à create-order et
        // init-payment), et l'échec était totalement silencieux — le serveur n'enregistrait
        // jamais "paiement initié" mais l'interface l'affichait quand même (voir goToMerchant),
        // ce qui expliquait l'absence de notification admin et le blocage de la commande.
        if (typeof window.KMFamilyRequest === 'function') {
            window.KMFamilyRequest('kmfamily_mark_initiated', 'mark-initiated', payload, handle, handleFail, 'nonce');
        } else {
            post('kmfamily_mark_initiated', payload, handle, handleFail);
        }
    }

    // MESURE ANTI-FRAUDE : équivalent signé de markInitiated ci-dessus. Envoie le jeton
    // HMAC (voir KMFamily_Security_Tokens / issue_redirect_token) émis pour CETTE commande
    // et CE moyen de paiement précis — le serveur refuse tout jeton absent, expiré, déjà
    // utilisé, ou ne correspondant pas à la commande/moyen indiqués. En cas d'échec de
    // vérification (page en cache servant un jeton périmé, par ex.), on retombe sur
    // l'ancien markInitiated non signé plutôt que de bloquer le paiement — le serveur
    // journalise alors ce cas comme signal de risque plus faible (voir moteur de risque).
    function confirmRedirect(methodKey, onResult, source) {
        var method = state.methods[methodKey];
        var token = method && method.redirect_token;
        source = source === 'qr_reveal' ? 'qr_reveal' : 'button';

        var handle = function (res) {
            if (typeof onResult === 'function') onResult(!!(res && res.success));
        };
        var handleFail = function () {
            // Filet de sécurité : ne jamais laisser un jeton expiré/absent empêcher le suivi
            // basique de la commande — on retombe sur le mécanisme legacy non signé.
            markInitiated(methodKey, onResult);
        };

        if (!token) { markInitiated(methodKey, onResult); return; }

        var payload = { order_ref: state.order.order_ref, gateway: methodKey, token: token, source: source };
        if (typeof window.KMFamilyRequest === 'function') {
            window.KMFamilyRequest('kmfamily_confirm_redirect', 'confirm-redirect', payload, handle, handleFail, 'nonce');
        } else {
            post('kmfamily_confirm_redirect', payload, handle, handleFail);
        }
    }

    // ÉVÉNEMENT DEMANDÉ : "retour" — dès que l'utilisateur revient sur cet onglet après
    // être parti payer dans une app externe (Wave/Orange/MTN/Moov), on le journalise.
    // sendBeacon est utilisé quand disponible car il continue d'envoyer la requête même
    // si l'onglet se ferme/se met en arrière-plan juste après le retour de focus.
    function trackReturn(methodKey) {
        if (!state.order || !methodKey) return;
        var nonce = window.KMFamily ? window.KMFamily.nonce : '';
        var ajaxUrl = window.KMFamily ? window.KMFamily.ajax_url : '/wp-admin/admin-ajax.php';

        if (navigator.sendBeacon) {
            var body = new URLSearchParams();
            body.set('action', 'kmfamily_mark_returned');
            body.set('order_ref', state.order.order_ref);
            body.set('gateway', methodKey);
            body.set('nonce', nonce);
            var blob = new Blob([body.toString()], { type: 'application/x-www-form-urlencoded' });
            navigator.sendBeacon(ajaxUrl, blob);
        } else {
            post('kmfamily_mark_returned', { order_ref: state.order.order_ref, gateway: methodKey }, function () {}, function () {});
        }
    }

    (function initReturnTracking() {
        var hasLeft = false;
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                // L'utilisateur quitte probablement l'onglet pour aller payer dans l'app.
                if (state.activeMethod) hasLeft = true;
            } else if (document.visibilityState === 'visible' && hasLeft) {
                hasLeft = false;
                trackReturn(state.activeMethod);
            }
        });
    })();

    function pollArreter() {
        if (state.pollTimer) clearTimeout(state.pollTimer);
        state.pollTimer = null;
        state.polling = false;
    }

    function pollTick() {
        var ecoule = Date.now() - state.pollStart;

        if (ecoule > POLL_MAX_MS) { pollArreter(); return; }

        // Onglet en arriere-plan : la personne est partie payer dans son application.
        // On ne consomme ni sa connexion ni le serveur pendant ce temps ; le retour sur
        // l'onglet relance immediatement une verification (voir plus bas).
        if (state.pollPaused) {
            state.pollTimer = setTimeout(pollTick, pollIntervalle(ecoule));
            return;
        }

        post('kmfamily_order_status', { order_ref: state.order.order_ref }, function (res) {
            if (res && res.success && res.data) {
                renderTimeline(res.data.stage);
                renderBanner(res.data.status);

                if (res.data.status === 'active') {
                    pollArreter();
                    setTimeout(function () {
                        // BUGFIX : la redirection etait conditionnee a la presence de
                        // window.KMFamily.login_url, sans aucun rapport avec elle. Si cet
                        // objet manquait, le paiement etait bien confirme mais la personne
                        // restait bloquee sur la page de paiement, sans rien pour continuer.
                        var dash = (window.KMFamilyPayment && window.KMFamilyPayment.dashboard_url) || '/';
                        window.location.href = dash;
                    }, 2500);
                    return;
                }

                if (STATUTS_FINAUX.indexOf(res.data.status) !== -1) {
                    pollArreter();
                    return;
                }
            }

            // Erreur reseau ou statut non final : on reprogramme, jamais d'intervalle fixe.
            state.pollTimer = setTimeout(pollTick, pollIntervalle(Date.now() - state.pollStart));
        }, function () {
            // Echec de la requete : on continue, mais sans accelerer.
            state.pollTimer = setTimeout(pollTick, pollIntervalle(Date.now() - state.pollStart));
        });
    }

    function pollStatus() {
        if (state.polling) return;
        state.polling = true;
        state.pollStart = Date.now();
        state.pollTimer = setTimeout(pollTick, POLL_RAPIDE_MS);

        document.addEventListener('visibilitychange', function () {
            state.pollPaused = (document.visibilityState === 'hidden');
            // Retour sur l'onglet : on verifie tout de suite, c'est le moment ou le
            // paiement vient typiquement d'etre valide dans l'application.
            if (!state.pollPaused && state.polling) {
                if (state.pollTimer) clearTimeout(state.pollTimer);
                state.pollTimer = setTimeout(pollTick, 800);
            }
        });

        window.addEventListener('pagehide', pollArreter);
    }

    // -----------------------------------------------------------------
    // Payment actions
    // -----------------------------------------------------------------
    function goToMerchant(methodKey) {
        var method = state.methods[methodKey];
        if (!method) return;

        // BUGFIX : renderTimeline(2) était appelé immédiatement, avant même de savoir si
        // l'enregistrement serveur avait réussi — l'étape "Paiement initié" s'affichait donc
        // même quand rien n'était réellement enregistré. On n'avance visuellement qu'une fois
        // la confirmation reçue (ou on prévient discrètement en cas d'échec, sans bloquer le
        // paiement lui-même : l'utilisateur doit pouvoir payer même si ce simple suivi rate).
        confirmRedirect(methodKey, function (ok) {
            if (ok) {
                renderTimeline(2);
            } else {
                console.warn('[KM Family] Le suivi "paiement initié" a échoué côté serveur — nouvelle tentative au prochain contrôle de statut.');
            }
        }, 'button');

        if (method.dynamic) {
            // Passerelle automatique (CinetPay / Paystack) : on récupère l'URL de paiement en direct.
            var $btn = $('.kmfamily-spc__btn-pay[data-method="' + methodKey + '"]');
            $btn.prop('disabled', true).text('Connexion à ' + method.label + '…');

            // BUGFIX : un simple $.post() sans repli laissait l'appel echouer silencieusement
            // (message d'erreur générique) quand admin-ajax.php est bloqué par le WAF/
            // ModSecurity de l'hébergeur — ce qui touche surtout les visiteurs mobiles
            // (comportement réseau/User-Agent different déclenchant plus facilement ces
            // règles). On réutilise le helper global KMFamilyRequest (déjà éprouvé pour
            // create-order, l'upload d'avatar, etc.) qui retente automatiquement via
            // l'endpoint REST /init-payment si admin-ajax renvoie 403/406/503.
            var onInitiateDone = function (res) {
                if (res && res.success && res.data && res.data.payment_url) {
                    window.location.href = res.data.payment_url;
                } else {
                    $btn.prop('disabled', false).text('Payer avec ' + method.label);
                    alert((res && res.data && res.data.message) || 'Une erreur est survenue, réessaie.');
                }
            };
            var onInitiateFail = function () {
                $btn.prop('disabled', false).text('Payer avec ' + method.label);
                alert('Connexion impossible pour le moment. Vérifie ta connexion internet et réessaie.');
            };

            if (typeof window.KMFamilyRequest === 'function') {
                window.KMFamilyRequest('kmfamily_initiate_payment', 'init-payment', {
                    order_ref: state.order.order_ref,
                    artiste_id: state.order.artiste_id,
                    palier: state.order.palier,
                    montant: state.order.montant,
                    periodicity: state.order.periodicity,
                }, onInitiateDone, onInitiateFail, 'nonce');
            } else {
                // Filet de sécurité si km-family.js n'était pas chargé (ne devrait pas arriver,
                // c'est une dépendance déclarée) : comportement d'origine sans repli REST.
                post('kmfamily_initiate_payment', {
                    order_ref: state.order.order_ref,
                    artiste_id: state.order.artiste_id,
                    palier: state.order.palier,
                    montant: state.order.montant,
                    periodicity: state.order.periodicity,
                }, onInitiateDone);
            }
            return;
        }

        // Moyen manuel : on ouvre le lien marchand tout de suite, de façon synchrone (dans le
        // même tick que le clic) pour ne pas se faire bloquer par un bloqueur de popups — sans
        // attendre la réponse de markInitiated ci-dessus, qui est un simple suivi en tâche de fond.
        var target = isMobileOrTablet() ? '_self' : '_blank';
        window.open(method.merchant_link, target);
    }

    function copyLink(methodKey) {
        var method = state.methods[methodKey];
        if (!method || !method.merchant_link) return;

        var done = function (ok) {
            var $btn = $('.kmfamily-spc__btn-copy[data-method="' + methodKey + '"]');
            $btn.addClass('is-copied').text(ok ? ('✓ ' + KMFamilyPayment.i18n.copied) : KMFamilyPayment.i18n.copy_failed);
            setTimeout(function () { $btn.removeClass('is-copied').text('📋 Copier le lien'); }, 2500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(method.merchant_link).then(function () { done(true); }, function () { done(false); });
        } else {
            var $tmp = $('<input>').val(method.merchant_link).appendTo('body').select();
            try { document.execCommand('copy'); done(true); } catch (e) { done(false); }
            $tmp.remove();
        }
    }

    // -----------------------------------------------------------------
    // QR rendering
    // -----------------------------------------------------------------
    function renderQR(el, text) {
        el.innerHTML = '';
        // eslint-disable-next-line no-new
        new QRCode(el, {
            text: text,
            width: 220,
            height: 220,
            colorDark: '#0d0d0f',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M,
        });
    }

    // -----------------------------------------------------------------
    // Panel / tabs rendering
    // -----------------------------------------------------------------
    function selectMethod(key) {
        state.activeMethod = key;
        $('.kmfamily-spc__method-tab').removeClass('is-active').filter('[data-method="' + key + '"]').addClass('is-active');
        renderPanel(key);
    }

    function renderPanel(key) {
        var method = state.methods[key];
        var $panel = $('.kmfamily-spc__panel');
        if (!method || !$panel.length) return;

        var hasQR = method.capabilities.indexOf('qr') !== -1;
        var hasCopy = method.capabilities.indexOf('copy') !== -1;

        var html = '<p class="kmfamily-spc__panel-instructions">' + method.instructions + '</p>';

        if (hasQR) {
            var qrCollapsedByDefault = isMobileOrTablet();
            html += '<div class="kmfamily-spc__qr-wrap"' + (qrCollapsedByDefault ? ' style="display:none"' : '') + ' id="kmfamily-qr-wrap">' +
                '<div class="kmfamily-spc__qr-box" id="kmfamily-qr-box"></div>' +
                '<span class="kmfamily-spc__qr-hint">Scanne avec l\'app ' + method.label + ' ou l\'appareil photo</span>' +
                '</div>';
            if (qrCollapsedByDefault) {
                html += '<button type="button" class="kmfamily-spc__qr-toggle" id="kmfamily-qr-toggle">📷 Afficher le QR (pour payer depuis un autre téléphone)</button>';
            }
        }

        html += '<div class="kmfamily-spc__actions">';
        html += '<button type="button" class="kmfamily-spc__btn-pay" data-method="' + key + '">' +
            method.icon + '&nbsp; Payer avec ' + method.label + '</button>';
        if (hasCopy) {
            html += '<button type="button" class="kmfamily-spc__btn-copy" data-method="' + key + '">📋 Copier le lien</button>';
        }
        html += '</div>';

        if (!method.dynamic) {
            html += '<details class="kmfamily-spc__declare">' +
                '<summary>J\'ai déjà payé — confirmer ma transaction</summary>' +
                '<div class="kmfamily-spc__declare-form">' +
                '<input type="text" id="kmfamily-declare-ref" placeholder="Référence / code de la transaction ' + method.label + '">' +
                '<textarea id="kmfamily-declare-note" rows="2" placeholder="Note (optionnel) : numéro utilisé, heure du paiement…"></textarea>' +
                '<button type="button" class="kmfamily-spc__declare-submit" id="kmfamily-declare-submit">Envoyer pour vérification</button>' +
                '</div></details>';
        }

        $panel.html(html);

        // Le QR est toujours généré immédiatement (même caché sur mobile derrière le toggle) :
        // c'est instantané côté client, pas besoin d'attendre un clic pour le dessiner.
        if (hasQR) {
            renderQR($id('kmfamily-qr-box'), method.qr_url);
        }
    }

    function bindPanelEvents() {
        $(document).on('click', '.kmfamily-spc__btn-pay', function () {
            goToMerchant($(this).data('method'));
        });
        $(document).on('click', '.kmfamily-spc__btn-copy', function () {
            copyLink($(this).data('method'));
        });
        $(document).on('click', '#kmfamily-qr-toggle', function () {
            $('#kmfamily-qr-wrap').show();
            $(this).hide();
            // On ne peut pas détecter un scan externe du QR par l'app Wave/Orange/MTN/Moov
            // (l'appareil qui scanne n'a pas notre JS) — mais l'affichage explicite du QR
            // est le geste le plus proche d'une intention réelle de payer par ce moyen.
            // On journalise cette confirmation avec le même jeton signé que le bouton.
            confirmRedirect(state.activeMethod, function () { renderTimeline(2); }, 'qr_reveal');
        });
        $(document).on('click', '#kmfamily-declare-submit', function () {
            var $btn = $(this);
            var reference = $('#kmfamily-declare-ref').val();
            var note = $('#kmfamily-declare-note').val();
            if (!reference) { alert(KMFamilyPayment.i18n.declare_error); return; }

            $btn.prop('disabled', true).text('Envoi…');

            var payload = { order_ref: state.order.order_ref, reference: reference, note: note };
            var onDone = function (res) {
                $btn.prop('disabled', false).text('Envoyer pour vérification');
                if (res && res.success) {
                    alert(KMFamilyPayment.i18n.declare_success);
                    renderBanner('awaiting_confirmation');
                    renderTimeline(2);
                } else {
                    alert((res && res.data && res.data.message) || 'Erreur, réessaie.');
                }
            };
            var onFail = function (xhr) {
                // BUGFIX : ce message générique s'affichait même quand le serveur avait
                // renvoyé un message clair et exploitable (ex. limitation de débit :
                // "trop de tentatives, réessaie dans quelques minutes") — la personne
                // pensait alors que tout le système était cassé. On récupère le vrai
                // message quel que soit le chemin qui a échoué (admin-ajax ou repli REST,
                // dont la forme de réponse diffère légèrement).
                $btn.prop('disabled', false).text('Envoyer pour vérification');
                var msg = (typeof xhr === 'string') ? xhr
                    : (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
                    || (xhr && xhr.responseJSON && xhr.responseJSON.message);
                alert(msg || 'La demande n\'a pas pu être envoyée (problème réseau ou serveur). Merci de réessayer, ou de nous contacter directement si le problème persiste.');
            };

            // BUGFIX : aucun repli REST n'existait ici — si admin-ajax.php était bloqué par le
            // WAF de l'hébergeur (ou toute autre panne réseau ponctuelle), la déclaration de
            // paiement échouait sans seconde chance, empêchant la commande de jamais atteindre
            // le statut "en attente de confirmation" (donc invisible pour l'admin).
            if (typeof window.KMFamilyRequest === 'function') {
                window.KMFamilyRequest('kmfamily_declare_payment', 'declare-payment', payload, onDone, onFail, 'nonce');
            } else {
                post('kmfamily_declare_payment', payload, onDone, onFail);
            }
        });
    }

    // -----------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------
    $(function () {
        var data = readOrderData();
        if (!data) return;

        state.order = data.order;
        state.methods = data.methods;

        renderTimeline(data.order.stage);
        renderBanner(data.order.status);
        bindPanelEvents();

        var methodKeys = Object.keys(state.methods);
        if (!methodKeys.length) return;

        // Tabs
        var $tabs = $('.kmfamily-spc__methods-tabs');
        methodKeys.forEach(function (key) {
            var m = state.methods[key];
            var badge = m.dynamic ? ' <span class="kmfamily-spc__badge-auto">Instantané</span>' : '';
            $tabs.append('<button type="button" class="kmfamily-spc__method-tab" data-method="' + key + '">' +
                m.icon + ' ' + m.label + badge + '</button>');
        });
        $(document).on('click', '.kmfamily-spc__method-tab', function () { selectMethod($(this).data('method')); });

        // Préselection : paramètre ?gw= (ex. lien partagé pointant directement sur un moyen précis),
        // sinon le premier moyen dispo (automatiques en tête).
        var preselect = urlParam('gw');
        var initial = (preselect && state.methods[preselect]) ? preselect : methodKeys[0];
        selectMethod(initial);

        if (data.order.status !== 'active' && data.order.status !== 'expired' && data.order.status !== 'cancelled') {
            pollStatus();
        }
    });

})(jQuery);
