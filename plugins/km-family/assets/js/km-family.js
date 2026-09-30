/**
 * KM Family v2 — Frontend JavaScript
 */
(function($) {
    'use strict';

    /**
     * Helper global : tente admin-ajax puis bascule en REST si 403/406/503
     * Utilisation : KMFamilyRequest('kmfamily_login', 'auth/login', payload, onSuccess, onError)
     * nonceKey (optionnel) : quelle clé de KMFamily utiliser comme nonce ('auth_nonce' par défaut,
     * utiliser 'nonce' pour les endpoints non liés à l'auth comme create-order — le serveur
     * vérifie un nonce différent selon l'action, il faut donc envoyer le bon).
     */
    window.KMFamilyRequest = function(action, restEndpoint, payload, onSuccess, onError, nonceKey) {
        payload = payload || {};
        payload.action = action;
        payload.nonce  = KMFamily[nonceKey || 'auth_nonce'];

        /**
         * AMELIORATION v3.3.5 — UN MESSAGE D'ERREUR EXPLOITABLE.
         * Quand admin-ajax ET le repli REST echouaient tous les deux, l'appelant
         * recevait un objet vide et affichait « Une erreur est survenue. » — un
         * message qui ne dit rien, ni a la personne qui se connecte, ni a
         * l'administrateur a qui elle le signale. Or les deux causes reelles de ce
         * cas sont identifiables cote client : un pare-feu qui bloque les deux points
         * d'entree (codes 403/406/503), et une reponse qui n'est pas du JSON valide
         * (une alerte PHP imprimee avant le JSON suffit a casser l'analyse).
         * On fabrique donc un objet d'erreur au format attendu par les appelants,
         * porteur d'un message qui nomme la cause probable.
         */
        function diagnostic(xhrAjax, xhrRest) {
            var sAjax = (xhrAjax && xhrAjax.status) || 0;
            var sRest = (xhrRest && xhrRest.status) || 0;
            var message;

            if (sAjax === 0 && sRest === 0) {
                message = 'Connexion au serveur impossible. Verifie ta connexion internet et reessaie.';
            } else if (sAjax === 403 || sRest === 403 || sAjax === 406 || sRest === 406) {
                message = 'Le serveur a refuse la requete (securite/pare-feu). Contacte-nous en precisant ce message.';
            } else if (sAjax >= 500 || sRest >= 500) {
                message = 'Le serveur a rencontre une erreur. Reessaie dans un instant.';
            } else {
                message = 'Reponse inattendue du serveur. Contacte-nous en precisant ce message.';
            }

            // Repere technique discret, utile quand la personne envoie une capture.
            message += ' [' + sAjax + '/' + sRest + ']';

            return { status: sRest || sAjax, responseJSON: { success: false, data: { message: message } } };
        }

        function tryRest(xhrAjax) {
            if (!KMFamily.rest_url) {
                if (typeof onError === 'function') onError(diagnostic(xhrAjax, null));
                return;
            }
            $.ajax({
                url: KMFamily.rest_url + restEndpoint,
                type: 'POST',
                data: payload,
                headers: { 'X-WP-Nonce': KMFamily.rest_nonce }
            })
            .done(function(response) {
                if (response && typeof response === 'object') {
                    if (typeof onSuccess === 'function') onSuccess(response);
                } else {
                    // Reponse 200 mais illisible : meme impasse qu'un echec reseau.
                    if (typeof onError === 'function') onError(diagnostic(xhrAjax, null));
                }
            })
            .fail(function(xhr2) {
                // Une erreur bien formee cote REST reste la meilleure information
                // disponible : on la laisse remonter telle quelle.
                if (xhr2 && xhr2.responseJSON && xhr2.responseJSON.data && xhr2.responseJSON.data.message) {
                    if (typeof onError === 'function') onError(xhr2);
                    return;
                }
                if (typeof onError === 'function') onError(diagnostic(xhrAjax, xhr2));
            });
        }

        $.post(KMFamily.ajax_url, payload)
            .done(function(response) {
                // Réponse HTTP 200 mais invalide (ex. "-1"/"0" — nonce périmé, souvent à
                // cause d'une page mise en cache) : ni un succès ni une erreur exploitable,
                // on retente via REST avant d'abandonner.
                if (response && typeof response === 'object') {
                    if (typeof onSuccess === 'function') onSuccess(response);
                } else {
                    // « 0 » ou « -1 » : action AJAX inconnue, ou nonce perime sur une
                    // page servie depuis un cache. Ni un succes, ni une erreur lisible.
                    tryRest(null);
                }
            })
            .fail(function(xhr) {
                // BUGFIX : une réponse d'erreur BIEN FORMÉE (ex. limite anti-abus atteinte,
                // validation refusée) est un vrai message du serveur, pas une panne réseau —
                // il faut l'afficher tel quel, pas la masquer derrière une nouvelle tentative
                // (qui de toute façon échouerait pour la même raison, et double la consommation
                // du quota anti-abus). On ne retente via REST que si la réponse n'est PAS du
                // JSON exploitable, signe d'un vrai blocage réseau/WAF/proxy en amont de WordPress.
                if (xhr && xhr.responseJSON && typeof xhr.responseJSON === 'object') {
                    if (typeof onSuccess === 'function') onSuccess(xhr.responseJSON);
                } else {
                    tryRest(xhr);
                }
            });
    };

    $(document).ready(function() {

        // =====================================================
        // 1. Boutons "Rejoindre la famille" → initier paiement
        // =====================================================
        $(document).on('click', '.kmfamily-btn--subscribe', function(e) {
            e.preventDefault();

            var $btn = $(this);
            if ($btn.hasClass('is-loading')) return;

            var artiste_id  = $btn.data('artiste-id');
            var palier      = $btn.data('palier');
            var periodicity = $btn.data('periodicity') || 'monthly';
            var montant     = $btn.data('montant');

            // Palier "Libre" (don libre) : le montant n'est pas dans un attribut statique,
            // il vient d'un champ que l'utilisateur remplit. On le lit ici directement plutôt
            // que de dépendre d'un second handler distinct qui se déclencherait après celui-ci.
            var targetInputId = $btn.data('target-input');
            if (targetInputId) {
                var minAmount = parseInt($btn.data('montant-min'), 10) || 500;
                var $input = $('#' + targetInputId);
                var typed = parseInt($input.val(), 10);

                if (!typed || isNaN(typed) || typed < minAmount) {
                    alert('Veuillez saisir un montant d\'au moins ' + minAmount.toLocaleString('fr-FR') + ' FCFA.');
                    $input.focus();
                    return;
                }
                montant = typed;
            }

            if (!artiste_id || !palier || !montant) {
                alert(KMFamily.i18n.error);
                return;
            }

            $btn.addClass('is-loading');

            function handleSubscribeSuccess(response) {
                if (response.success && response.data && response.data.redirect_url) {
                    $btn.find('.kmfamily-btn__label').text(KMFamily.i18n.redirecting);
                    window.location.href = response.data.redirect_url;
                } else {
                    var msg = (response.data && response.data.message) ? response.data.message : KMFamily.i18n.error;
                    if (response.data && response.data.register_url) {
                        // BUGFIX : on ne redirigeait vers l'inscription qu'après un confirm()
                        // natif du navigateur (jarring, hors charte), et sans jamais revenir
                        // compléter l'abonnement visé une fois le compte créé — la personne
                        // devait tout rechoisir depuis la liste générale des artistes.
                        // On mémorise le palier + la périodicité (même mécanisme que la
                        // présélection depuis la page "tous les artistes") et on demande à la
                        // page de connexion de nous ramener ici une fois connecté(e).
                        if (window.sessionStorage) {
                            try {
                                window.sessionStorage.setItem('kmfamily_preselect_palier', palier);
                                window.sessionStorage.setItem('kmfamily_preselect_period', periodicity);
                            } catch (err) {}
                        }
                        var authUrl = response.data.register_url +
                            (response.data.register_url.indexOf('?') > -1 ? '&' : '?') +
                            'tab=register&next=' + encodeURIComponent(window.location.href.split('#')[0]);
                        $btn.find('.kmfamily-btn__label').text('Direction la création de compte…');
                        window.location.href = authUrl;
                    } else {
                        alert(msg);
                        $btn.removeClass('is-loading');
                    }
                }
            }

            function handleSubscribeError(xhr) {
                var msg = KMFamily.i18n.error;
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                } else if (xhr && xhr.status === 403) {
                    msg = 'Erreur 403 : requête bloquée par le serveur.';
                }
                alert(msg);
                $btn.removeClass('is-loading');
            }

            KMFamilyRequest('kmfamily_create_order', 'create-order', {
                artiste_id:  artiste_id,
                palier:      palier,
                montant:     montant,
                periodicity: periodicity
            }, handleSubscribeSuccess, handleSubscribeError, 'nonce');
        });

        // =====================================================
        // 2. Filtres archive (côté client)
        // =====================================================
        var $filters = $('.kmfamily-filter');
        var $cards   = $('.kmfamily-exclusive-grid .kmfamily-card');

        $filters.on('change', function() {
            var filtreArtiste = $('#kmfamily-filter-artiste').val();
            var filtreType    = $('#kmfamily-filter-type').val();
            var filtrePalier  = $('#kmfamily-filter-palier').val();

            $cards.each(function() {
                var $card   = $(this);
                var artiste = String($card.data('artiste') || '');
                var type    = String($card.data('type') || '');
                var palier  = String($card.data('palier') || '');

                var matchArtiste = !filtreArtiste || artiste === String(filtreArtiste);
                var matchType    = !filtreType    || type === filtreType;
                var matchPalier  = !filtrePalier  || palier === filtrePalier;

                $card.toggle(matchArtiste && matchType && matchPalier);
            });
        });

        // =====================================================
        // 3. Auto-reload page "paiement en cours"
        // =====================================================
        if ($('.kmfamily-payment-status--pending').length) {
            setTimeout(function() { window.location.reload(); }, 8000);
        }

        // =====================================================
        // 4. AUTH PAGE — Tabs
        // =====================================================
        $('.kmfamily-auth-tab').on('click', function() {
            var tab = $(this).data('tab');

            $('.kmfamily-auth-tab').removeClass('is-active');
            $(this).addClass('is-active');

            $('.kmfamily-auth-form').removeClass('is-active');
            $('.kmfamily-auth-form[data-form="' + tab + '"]').addClass('is-active');
        });

        $('.kmfamily-auth-link[data-switch]').on('click', function() {
            var target = $(this).data('switch');

            $('.kmfamily-auth-form').removeClass('is-active');
            $('.kmfamily-auth-form[data-form="' + target + '"]').addClass('is-active');

            $('.kmfamily-auth-tab').removeClass('is-active');
            // Seul login/register sont dans les tabs, pas forgot
            if (target === 'login' || target === 'register') {
                $('.kmfamily-auth-tab[data-tab="' + target + '"]').addClass('is-active');
            } else {
                // Si on va vers forgot, marquer login comme actif dans les tabs
                $('.kmfamily-auth-tab[data-tab="login"]').addClass('is-active');
            }
        });

        // =====================================================
        // 5. Toggle visibilité mot de passe
        // =====================================================
        $(document).on('click', '.kmfamily-form-field__toggle', function() {
            var $btn = $(this);
            var $input = $btn.siblings('input');
            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $btn.text('🙈');
            } else {
                $input.attr('type', 'password');
                $btn.text('👁');
            }
        });

        // =====================================================
        // 6. Password strength meter
        // =====================================================
        $(document).on('input', '#reg-password, #new-password', function() {
            var val = $(this).val();
            var $meter = $(this).closest('.kmfamily-form-field').find('.kmfamily-password-strength');
            var $label = $meter.find('.kmfamily-password-strength__label');

            if (!val) {
                $meter.removeAttr('data-strength');
                $label.text('Au moins 8 caractères');
                return;
            }

            var score = 0;
            if (val.length >= 8) score++;
            if (val.length >= 12) score++;
            if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
            if (/\d/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            var strengths = ['weak', 'weak', 'fair', 'good', 'strong', 'strong'];
            var labels = {
                weak:   KMFamily.i18n.password_weak   || 'Faible',
                fair:   KMFamily.i18n.password_fair   || 'Moyen',
                good:   KMFamily.i18n.password_good   || 'Bon',
                strong: KMFamily.i18n.password_strong || 'Excellent'
            };
            var strength = strengths[score];

            $meter.attr('data-strength', strength);
            $label.text(labels[strength]);
        });

        // =====================================================
        // 7. LOGIN AJAX
        // =====================================================
        $('#kmfamily-login-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var $msg = $('#login-message');

            $btn.addClass('is-loading');
            $msg.removeClass('is-error is-success').hide();

            KMFamilyRequest('kmfamily_login', 'auth/login', {
                login:    $form.find('[name=login]').val(),
                password: $form.find('[name=password]').val(),
                remember: $form.find('[name=remember]').is(':checked') ? 1 : 0,
                next:     $form.find('[name=next]').val()
            },
            function(response) {
                if (response.success) {
                    $msg.addClass('is-success').text(response.data.message).show();
                    setTimeout(function() { window.location.href = response.data.redirect; }, 600);
                } else {
                    $msg.addClass('is-error').text((response.data && response.data.message) || KMFamily.i18n.error).show();
                    $btn.removeClass('is-loading');
                }
            },
            function(xhr) {
                var err = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || KMFamily.i18n.error;
                $msg.addClass('is-error').text(err).show();
                $btn.removeClass('is-loading');
            });
        });

        // =====================================================
        // 8. REGISTER AJAX
        // =====================================================
        $('#kmfamily-register-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var $msg = $('#register-message');

            $btn.addClass('is-loading');
            $msg.removeClass('is-error is-success').hide();

            KMFamilyRequest('kmfamily_register', 'auth/register', {
                full_name: $form.find('[name=full_name]').val(),
                email:     $form.find('[name=email]').val(),
                password:  $form.find('[name=password]').val(),
                next:      $form.find('[name=next]').val()
            },
            function(response) {
                if (response.success) {
                    $msg.addClass('is-success').text(response.data.message + ' Redirection...').show();
                    setTimeout(function() { window.location.href = response.data.redirect; }, 800);
                } else {
                    $msg.addClass('is-error').text((response.data && response.data.message) || KMFamily.i18n.error).show();
                    $btn.removeClass('is-loading');
                }
            },
            function(xhr) {
                var err = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || KMFamily.i18n.error;
                $msg.addClass('is-error').text(err).show();
                $btn.removeClass('is-loading');
            });
        });

        // =====================================================
        // 9. FORGOT PASSWORD AJAX
        // =====================================================
        $('#kmfamily-forgot-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var $msg = $('#forgot-message');

            $btn.addClass('is-loading');
            $msg.removeClass('is-error is-success').hide();

            KMFamilyRequest('kmfamily_forgot', 'auth/forgot', {
                email: $form.find('[name=email]').val()
            },
            function(response) {
                if (response.success) {
                    $msg.addClass('is-success').text(response.data.message).show();
                    $form.find('input[type=email]').val('');
                } else {
                    $msg.addClass('is-error').text((response.data && response.data.message) || KMFamily.i18n.error).show();
                }
                $btn.removeClass('is-loading');
            },
            function(xhr) {
                var err = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || KMFamily.i18n.error;
                $msg.addClass('is-error').text(err).show();
                $btn.removeClass('is-loading');
            });
        });

        // =====================================================
        // 9b. RESET PASSWORD AJAX (lien reçu par email)
        // =====================================================
        $('#kmfamily-reset-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var $msg = $('#reset-message');

            var pass1 = $form.find('[name=pass1]').val();
            var pass2 = $form.find('[name=pass2]').val();

            if (pass1 !== pass2) {
                $msg.addClass('is-error').removeClass('is-success').text('Les deux mots de passe ne correspondent pas.').show();
                return;
            }
            if (pass1.length < 8) {
                $msg.addClass('is-error').removeClass('is-success').text('Le mot de passe doit contenir au moins 8 caractères.').show();
                return;
            }

            $btn.addClass('is-loading');
            $msg.removeClass('is-error is-success').hide();

            KMFamilyRequest('kmfamily_reset_password', 'auth/reset-password', {
                key:   $form.find('[name=key]').val(),
                login: $form.find('[name=login]').val(),
                pass1: pass1,
                pass2: pass2
            },
            function(response) {
                if (response.success) {
                    $msg.addClass('is-success').text(response.data.message).show();
                    setTimeout(function() { window.location.href = response.data.redirect; }, 800);
                } else {
                    $msg.addClass('is-error').text((response.data && response.data.message) || KMFamily.i18n.error).show();
                    $btn.removeClass('is-loading');
                }
            },
            function(xhr) {
                var err = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || KMFamily.i18n.error;
                $msg.addClass('is-error').text(err).show();
                $btn.removeClass('is-loading');
            });
        });

        // =====================================================
        // 10. CHANGE PASSWORD (dashboard)
        // =====================================================
        $('#kmfamily-password-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn = $form.find('button[type="submit"]');
            var $msg = $('#password-message');

            $btn.addClass('is-loading');
            $msg.removeClass('is-error is-success').hide();

            KMFamilyRequest('kmfamily_change_password', 'auth/change-password', {
                current_password: $form.find('[name=current_password]').val(),
                new_password:     $form.find('[name=new_password]').val()
            },
            function(response) {
                if (response.success) {
                    $msg.addClass('is-success').text(response.data.message).show();
                    $form[0].reset();
                    $form.find('.kmfamily-password-strength').removeAttr('data-strength');
                    $form.find('.kmfamily-password-strength__label').text('Au moins 8 caractères');
                } else {
                    $msg.addClass('is-error').text((response.data && response.data.message) || KMFamily.i18n.error).show();
                }
                $btn.removeClass('is-loading');
            },
            function(xhr) {
                var err = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || KMFamily.i18n.error;
                $msg.addClass('is-error').text(err).show();
                $btn.removeClass('is-loading');
            });
        });

        // =====================================================
        // 11. DASHBOARD TABS
        // =====================================================
        $('.kmfamily-dashboard__tab').on('click', function() {
            var tab = $(this).data('tab');

            $('.kmfamily-dashboard__tab').removeClass('is-active');
            $(this).addClass('is-active');

            $('.kmfamily-dashboard__tab-content').removeClass('is-active');
            $('.kmfamily-dashboard__tab-content[data-content="' + tab + '"]').addClass('is-active');

            // Scroll en haut sur mobile
            if (window.innerWidth < 768) {
                window.scrollTo({ top: $(this).offset().top - 80, behavior: 'smooth' });
            }
        });

    });

})(jQuery);


/* ============================================================
   v2.1 — Profile page : avatar upload, profile form, palier switcher
============================================================ */
(function($) {
    'use strict';

    $(document).ready(function() {

        // =====================================================
        // 12. Avatar upload (avec fallback REST si 403/WAF)
        // =====================================================
        function uploadAvatarVia(url, useRest, formData, $preview) {
            var settings = {
                url: url,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false
            };
            if (useRest) {
                settings.headers = { 'X-WP-Nonce': KMFamily.rest_nonce };
            }
            return $.ajax(settings);
        }

        $('#kmfamily-avatar-input').on('change', function() {
            var file = this.files[0];
            if (!file) return;

            // Validation côté client
            var maxSize = 2 * 1024 * 1024;
            if (file.size > maxSize) {
                showMessage('#avatar-message', 'Image trop lourde (max 2 Mo).', 'error');
                return;
            }

            var allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (allowed.indexOf(file.type) === -1) {
                showMessage('#avatar-message', 'Format non supporté (JPG, PNG, WebP ou GIF).', 'error');
                return;
            }

            var $preview = $('.kmfamily-avatar-uploader__preview');
            $preview.addClass('is-uploading');

            // Tentative 1 : admin-ajax
            var formData1 = new FormData();
            formData1.append('action', 'kmfamily_upload_avatar');
            formData1.append('nonce', KMFamily.auth_nonce);
            formData1.append('avatar', file);

            uploadAvatarVia(KMFamily.ajax_url, false, formData1, $preview)
                .done(handleAvatarSuccess)
                .fail(function(xhr) {
                    // Si 403 → fallback REST (contourne ModSecurity/WAF)
                    if (xhr && (xhr.status === 403 || xhr.status === 406 || xhr.status === 503) && KMFamily.rest_url) {
                        console.log('[KM Family] admin-ajax bloqué (' + xhr.status + '), tentative via REST API...');
                        var formData2 = new FormData();
                        formData2.append('avatar', file);

                        uploadAvatarVia(KMFamily.rest_url + 'profile/avatar', true, formData2, $preview)
                            .done(handleAvatarSuccess)
                            .fail(function(xhr2) {
                                handleAvatarError(xhr2, true);
                            });
                    } else {
                        handleAvatarError(xhr, false);
                    }
                });

            function handleAvatarSuccess(response) {
                $preview.removeClass('is-uploading');
                if (response.success) {
                    var $img = $('#kmfamily-avatar-preview');
                    $img.attr('src', response.data.url + '?t=' + Date.now()).show();
                    $('#kmfamily-avatar-placeholder').hide();
                    showMessage('#avatar-message', response.data.message, 'success');

                    if (!$('#kmfamily-avatar-delete').length) {
                        $('#kmfamily-avatar-input').parent().append(
                            '<button type="button" class="kmfamily-btn kmfamily-btn-ghost" id="kmfamily-avatar-delete">🗑 Supprimer</button>'
                        );
                    }
                } else {
                    showMessage('#avatar-message', (response.data && response.data.message) || KMFamily.i18n.error, 'error');
                }
            }

            function handleAvatarError(xhr, isRestFallback) {
                $preview.removeClass('is-uploading');
                var msg = KMFamily.i18n.error;
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                } else if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                } else if (xhr && xhr.status === 403) {
                    msg = 'Erreur 403 : le serveur bloque l\'upload (pare-feu). Contactez l\'administrateur.';
                } else if (xhr && xhr.status) {
                    msg = 'Erreur ' + xhr.status + ' : ' + (xhr.statusText || 'upload échoué');
                }
                if (isRestFallback) msg += ' (REST + AJAX)';
                showMessage('#avatar-message', msg, 'error');
            }
        });

        // Supprimer avatar
        $(document).on('click', '#kmfamily-avatar-delete', function() {
            if (!confirm('Voulez-vous vraiment supprimer votre photo de profil ?')) return;

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(KMFamily.ajax_url, {
                action: 'kmfamily_delete_avatar',
                nonce: KMFamily.auth_nonce
            })
            .done(function(response) {
                if (response.success) {
                    $('#kmfamily-avatar-preview').attr('src', '').hide();
                    if (!$('#kmfamily-avatar-placeholder').length) {
                        $('.kmfamily-avatar-uploader__preview').prepend(
                            '<div class="kmfamily-avatar-uploader__placeholder" id="kmfamily-avatar-placeholder">' +
                            '<span class="kmfamily-avatar-uploader__placeholder-icon">👤</span>' +
                            '<span class="kmfamily-avatar-uploader__placeholder-text">Aucune photo</span>' +
                            '</div>'
                        );
                    } else {
                        $('#kmfamily-avatar-placeholder').show();
                    }
                    $btn.remove();
                    showMessage('#avatar-message', response.data.message, 'success');
                } else {
                    $btn.prop('disabled', false);
                    showMessage('#avatar-message', (response.data && response.data.message) || KMFamily.i18n.error, 'error');
                }
            })
            .fail(function() {
                $btn.prop('disabled', false);
                showMessage('#avatar-message', KMFamily.i18n.error, 'error');
            });
        });

        // =====================================================
        // 13. Profile form (update infos) — avec fallback REST si 403
        // =====================================================
        // Changement d'e-mail : le mot de passe actuel est exigé côté serveur.
        (function() {
            var $email = $('#kmfamily-profile-form [name=email]');
            if (!$email.length) return;
            var initial = $email.val();
            $email.on('input', function() {
                $('.kmfamily-form-field--email-confirm').prop('hidden', $.trim($email.val()).toLowerCase() === $.trim(initial).toLowerCase());
            });
        })();

        $('#kmfamily-profile-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $btn  = $form.find('button[type="submit"]');
            $btn.addClass('is-loading');

            var payload = {
                action:       'kmfamily_update_profile',
                nonce:        KMFamily.auth_nonce,
                display_name: $form.find('[name=display_name]').val(),
                email:        $form.find('[name=email]').val(),
                first_name:   $form.find('[name=first_name]').val(),
                last_name:    $form.find('[name=last_name]').val(),
                phone:        $form.find('[name=phone]').val(),
                city:         $form.find('[name=city]').val(),
                description:  $form.find('[name=description]').val(),
                current_password: $form.find('[name=email_current_password]').val() || ''
            };

            function handleSuccess(response) {
                $btn.removeClass('is-loading');
                if (response.success) {
                    showMessage('#profile-message', response.data.message, 'success');
                } else {
                    showMessage('#profile-message', (response.data && response.data.message) || KMFamily.i18n.error, 'error');
                }
            }

            function handleError(xhr) {
                $btn.removeClass('is-loading');
                var msg = KMFamily.i18n.error;
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                } else if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                } else if (xhr && xhr.status === 403) {
                    msg = 'Erreur 403 : le serveur bloque la requête.';
                } else if (xhr && xhr.status) {
                    msg = 'Erreur ' + xhr.status + ': ' + (xhr.statusText || 'échec');
                }
                showMessage('#profile-message', msg, 'error');
            }

            // Tentative 1 : admin-ajax
            $.post(KMFamily.ajax_url, payload)
                .done(handleSuccess)
                .fail(function(xhr) {
                    // Fallback REST si 403
                    if (xhr && (xhr.status === 403 || xhr.status === 406 || xhr.status === 503) && KMFamily.rest_url) {
                        console.log('[KM Family] admin-ajax bloqué (' + xhr.status + '), tentative via REST API...');
                        $.ajax({
                            url: KMFamily.rest_url + 'profile/update',
                            type: 'POST',
                            data: payload,
                            headers: { 'X-WP-Nonce': KMFamily.rest_nonce }
                        })
                        .done(handleSuccess)
                        .fail(handleError);
                    } else {
                        handleError(xhr);
                    }
                });
        });

        // =====================================================
        // 14. Palier switcher (changer de palier depuis dashboard)
        // =====================================================
        $(document).on('click', '.kmfamily-palier-switcher__btn:not(:disabled)', function() {
            var $btn = $(this);
            if ($btn.hasClass('is-current')) return;

            var artisteId  = $btn.data('artiste-id');
            var palier     = $btn.data('palier');
            var artistName = $btn.data('artist-name');
            var palierName = $btn.data('palier-name');

            var msg = 'Changer de palier pour ' + artistName + ' vers ' + palierName + ' ?\n\n' +
                      'Si c\'est un upgrade, vous serez redirigé pour finaliser le paiement.';

            if (!confirm(msg)) return;

            var $allBtns = $btn.closest('.kmfamily-palier-switcher').find('button');
            $allBtns.prop('disabled', true);

            var payload = {
                action:     'kmfamily_change_palier',
                nonce:      KMFamily.auth_nonce,
                artiste_id: artisteId,
                palier:     palier
            };

            function handleSuccess(response) {
                if (response.success) {
                    alert(response.data.message);
                    if (response.data.redirect) {
                        window.location.href = response.data.redirect;
                    } else if (response.data.reload) {
                        window.location.reload();
                    }
                } else {
                    alert((response.data && response.data.message) || KMFamily.i18n.error);
                    $allBtns.prop('disabled', false);
                    $btn.closest('.kmfamily-palier-switcher').find('button.is-current').prop('disabled', true);
                }
            }

            function handleError(xhr) {
                var msg = KMFamily.i18n.error;
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                } else if (xhr && xhr.status === 403) {
                    msg = 'Erreur 403 : requête bloquée par le serveur.';
                } else if (xhr && xhr.status) {
                    msg = 'Erreur ' + xhr.status + ' : ' + (xhr.statusText || 'échec');
                }
                alert(msg);
                $allBtns.prop('disabled', false);
                $btn.closest('.kmfamily-palier-switcher').find('button.is-current').prop('disabled', true);
            }

            // Repli REST automatique en cas d'echec admin-ajax (WAF, reseau, nonce perime...),
            // harmonise avec le meme helper deja utilise pour le paiement.
            if (typeof window.KMFamilyRequest === 'function') {
                delete payload.action;
                delete payload.nonce;
                window.KMFamilyRequest('kmfamily_change_palier', 'auth/change-palier', payload, handleSuccess, handleError, 'auth_nonce');
            } else {
                $.post(KMFamily.ajax_url, payload).done(handleSuccess).fail(handleError);
            }
        });

        // Helper
        function showMessage(selector, message, type) {
            var $el = $(selector);
            $el.removeClass('is-error is-success')
               .addClass('is-' + type)
               .text(message)
               .show();

            if (type === 'success') {
                setTimeout(function() { $el.fadeOut(); }, 4000);
            }
        }

    });
})(jQuery);


/* ============================================================
   v2.1.1 — Palier LIBRE : suggestions + validation montant
============================================================ */
(function($) {
    'use strict';

    $(document).ready(function() {

        // Suggestions : clic → remplit l'input du même form
        $(document).on('click', '.kmfamily-libre-suggest', function() {
            var $btn = $(this);
            var amount = $btn.data('amount');
            var $form = $btn.closest('.kmfamily-libre-form');
            var $input = $form.find('.kmfamily-libre-amount');

            $input.val(amount);

            // Highlight visuel
            $form.find('.kmfamily-libre-suggest').removeClass('is-active');
            $btn.addClass('is-active');
        });

        // Note : la validation/lecture du montant pour le palier "Libre" est gérée
        // directement dans le handler .kmfamily-btn--subscribe ci-dessus (voir section 1).

    });
})(jQuery);


/* ============================================================
   v2.1.5 — SÉLECTEUR DE PÉRIODICITÉ
============================================================ */
(function($) {
    'use strict';

    $(document).ready(function() {

        function formatFCFA(n) {
            return new Intl.NumberFormat('fr-FR').format(n);
        }

        // Changement de période → mise à jour des prix affichés
        $(document).on('click', '.kmfamily-period-tab', function() {
            var $tab = $(this);
            var period = $tab.data('period');

            // Activer l'onglet
            $tab.closest('.kmfamily-period-selector__tabs').find('.kmfamily-period-tab')
                .removeClass('is-active').attr('aria-selected', 'false');
            $tab.addClass('is-active').attr('aria-selected', 'true');

            // Mettre à jour chaque carte palier
            $('.kmfamily-palier-card').each(function() {
                var $card = $(this);
                var prices = $card.data('prices');
                if (!prices || !prices[period]) return;

                var data = prices[period];

                // Animation visuelle sur le prix
                var $amount = $card.find('.kmfamily-palier-card__amount').not('.kmfamily-palier-card__amount--libre');
                $amount.addClass('is-updating');
                setTimeout(function() { $amount.removeClass('is-updating'); }, 250);

                // Mettre à jour le prix affiché (équivalent mensuel)
                $amount.text(formatFCFA(data.monthly_equivalent));

                // Bloc info périodicité
                var $periodInfo = $card.find('.kmfamily-palier-card__period-info');
                if (period === 'monthly') {
                    $periodInfo.hide();
                } else {
                    $periodInfo.show();
                    $card.find('.kmfamily-palier-card__total-amount').text(formatFCFA(data.total));

                    var $savings = $card.find('.kmfamily-palier-card__savings');
                    if (data.discount_amount > 0) {
                        $savings.show();
                        $card.find('.kmfamily-palier-card__savings-amount').text(formatFCFA(data.discount_amount));
                    } else {
                        $savings.hide();
                    }
                }

                // Mettre à jour data-montant et data-periodicity sur le bouton (si présent)
                var $subBtn = $card.find('.kmfamily-btn--subscribe');
                if ($subBtn.length) {
                    $subBtn.attr('data-montant', data.total).data('montant', data.total);
                    $subBtn.attr('data-periodicity', period).data('periodicity', period);
                }
            });
        });

    });
})(jQuery);


/* ============================================================
   v2.1.9 — Showcase "Rejoindre KM Family" : CTA paliers → artistes
============================================================ */
(function($) {
    'use strict';

    $(document).ready(function() {

        // Clic sur un CTA de palier : scroll doux vers la section artistes
        // Le palier choisi est mémorisé en sessionStorage pour la page artiste
        $(document).on('click', '.kmfamily-palier-card__cta', function(e) {
            var $cta = $(this);
            var palier = $cta.data('palier');

            // Mémoriser le palier choisi (pré-sélection sur la page artiste)
            if (palier && window.sessionStorage) {
                try {
                    window.sessionStorage.setItem('kmfamily_preselect_palier', palier);
                } catch(err) {}
            }

            // Mémoriser aussi la période active
            var activePeriod = $('.kmfamily-period-tab.is-active').data('period') || 'monthly';
            if (window.sessionStorage) {
                try {
                    window.sessionStorage.setItem('kmfamily_preselect_period', activePeriod);
                } catch(err) {}
            }

            // Highlight visuel + scroll doux vers la section artistes, pour guider la personne
            // jusqu'au bout du parcours inverse (palier choisi avant l'artiste).
            var $artistes = $('.kmfamily-paliers-all__artistes');
            $artistes.addClass('is-pulsing');
            setTimeout(function() { $artistes.removeClass('is-pulsing'); }, 1500);

            if ($artistes.length) {
                $('html, body').animate({
                    scrollTop: $artistes.offset().top - 90
                }, 500);
            }
        });

        // Sur une page artiste : pré-sélectionner la période et le palier si la personne
        // vient du showcase, d'un lien direct (?palier=or&period=yearly) ou revient de la
        // page de connexion après avoir cliqué sur "Rejoindre" (palier dans l'URL de retour).
        //
        // BUGFIX : la clé sessionStorage du palier était supprimée dès qu'elle existait, même
        // sur une page SANS carte de palier (typiquement la page de connexion, traversée entre
        // le clic sur "Rejoindre" et le retour sur la page artiste) — la présélection était
        // donc perdue avant d'arriver. On ne la consomme plus que sur une page qui affiche
        // réellement les paliers.
        (function applyPreselection() {
            // Pas de présélection sur la page "tous les artistes" (showcase) : c'est elle qui
            // MÉMORISE le choix, pour la page artiste suivante.
            if (!$('.kmfamily-palier-card').length || $('.kmfamily-paliers-all__artistes').length) return;

            var urlParams = null;
            try { urlParams = new URLSearchParams(window.location.search); } catch (err) {}

            var store = null;
            try { store = window.sessionStorage; } catch (err) {}

            var preselectPeriod = (urlParams && urlParams.get('period')) || (store && store.getItem('kmfamily_preselect_period'));
            var preselectPalier = (urlParams && urlParams.get('palier')) || (store && store.getItem('kmfamily_preselect_palier'));

            if (preselectPeriod && /^[a-z0-9_-]+$/i.test(preselectPeriod)) {
                var $tab = $('.kmfamily-period-tab[data-period="' + preselectPeriod + '"]');
                if ($tab.length) {
                    setTimeout(function() { $tab.first().trigger('click'); }, 300);
                }
            }

            if (preselectPalier && /^[a-z0-9_-]+$/i.test(preselectPalier)) {
                // Highlight doux de la carte palier ciblée
                var $card = $('.kmfamily-palier-card--' + preselectPalier);
                if ($card.length) {
                    $card.addClass('is-preselected');
                    setTimeout(function() {
                        $('html, body').animate({
                            scrollTop: $card.offset().top - 100
                        }, 600);
                    }, 600);
                    setTimeout(function() { $card.removeClass('is-preselected'); }, 3000);
                }
            }

            if (store) {
                try {
                    store.removeItem('kmfamily_preselect_period');
                    store.removeItem('kmfamily_preselect_palier');
                } catch (err) {}
            }
        })();

        // Visiteur non connecté qui clique sur "Rejoindre" : on mémorise la période
        // d'engagement choisie pour la retrouver au retour de la page de connexion.
        $(document).on('click', '.kmfamily-btn--join', function() {
            var period = $('.kmfamily-period-tab.is-active').first().data('period') || 'monthly';
            try {
                window.sessionStorage.setItem('kmfamily_preselect_period', period);
                var palier = $(this).data('palier');
                if (palier) window.sessionStorage.setItem('kmfamily_preselect_palier', palier);
            } catch (err) {}
        });

        /**
         * BUGFIX AFFICHAGE — bande blanche entre le menu et le contenu KM Family.
         * Les tentatives précédentes ciblaient des noms de classes devinés pour Astra/Elementor
         * (.page-header, .entry-header, .elementor-page-title...) mais ce thème personnalisé
         * (Astra + Elementor 4.2.1, sur-mesure pour ce site) n'utilise visiblement pas ces noms
         * exacts pour l'élément en cause — la bande persistait.
         * Plutôt que de deviner un énième sélecteur, on détecte le problème directement dans le
         * DOM au chargement : on remonte depuis notre propre section jusqu'à <body>, on remet à
         * zéro le padding/margin du haut de chaque ancêtre, et on masque tout élément "frère"
         * précédent qui ne contient ni texte visible ni média (image/svg/vidéo/bouton/lien) —
         * donc typiquement une zone de titre de page vide ou un espaceur du thème — sans jamais
         * toucher à un élément qui contient un vrai contenu.
         */
        (function fixThemeGapAboveKMFamily() {
            var root = document.querySelector(
                '.kmfamily-paliers-all, .kmfamily-paliers, .kmfamily-dashboard, .kmfamily-profile-page, .kmfamily-auth-page, .kmfamily-spc'
            );
            if (!root) return;

            function isVisuallyEmpty(el) {
                var text = (el.innerText || el.textContent || '').trim();
                if (text) return false;
                if (el.querySelector('img, svg, iframe, video, canvas, button, input, a[href]')) return false;
                return true;
            }

            var el = root;
            var hops = 0;
            while (el && el.parentElement && el.parentElement.tagName !== 'BODY' && hops < 8) {
                el.parentElement.style.paddingTop = '0px';
                el.parentElement.style.marginTop = '0px';

                var prev = el.previousElementSibling;
                while (prev) {
                    var toHide = prev;
                    prev = prev.previousElementSibling;
                    if (isVisuallyEmpty(toHide)) toHide.style.display = 'none';
                }
                el = el.parentElement;
                hops++;
            }
        })();
    });
})(jQuery);
