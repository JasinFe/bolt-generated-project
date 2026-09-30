<?php
/**
 * KOPHI'S MUSIC — Connexion + Reinitialisation MDP v5.2
 * Design Premium · Safe hooks · No infinite loops
 */
defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════
// REDIRECTION ANTICIPÉE — avant tout output HTML
// Gere le cas "utilisateur deja connecte" sur template_redirect
// (wp_redirect fonctionne car les headers ne sont pas encore envoyes)
// ══════════════════════════════════════════════════════════════
add_action( 'template_redirect', 'km_redirect_if_logged_in_on_login_page' );
function km_redirect_if_logged_in_on_login_page() {
    $pid = km_get_page_id( 'connexion-artiste' );
    if ( ! $pid || ! is_page( $pid ) ) return;
    if ( ! is_user_logged_in() ) return;
    $user = wp_get_current_user();
    $url  = in_array( 'artiste_label', (array) $user->roles )
        ? km_dashboard_url( 'mon-tableau-de-bord' )
        : km_dashboard_url( 'dashboard-label' );
    wp_safe_redirect( $url, 302 );
    exit;
}

// ══════════════════════════════════════════════════════════════
// TRAITEMENT POST LOGIN — sur init (avant tout output HTML)
// wp_signon + wp_safe_redirect fonctionnent car headers pas encore envoyes
// ══════════════════════════════════════════════════════════════
add_action( 'init', 'km_process_login_post' );
function km_process_login_post() {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
    if ( ! isset( $_POST['km_login_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['km_login_nonce'] ) ), 'km_login' ) ) return;

    $creds = array(
        'user_login'    => sanitize_text_field( wp_unslash( isset( $_POST['username'] ) ? $_POST['username'] : '' ) ),
        'user_password' => isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '',
        'remember'      => isset( $_POST['remember'] ),
    );
    $user = wp_signon( $creds, is_ssl() );
    $base = km_dashboard_url( 'connexion-artiste' );

    if ( is_wp_error( $user ) ) {
        // Erreur : rediriger vers la page de connexion avec indicateur d'erreur
        wp_safe_redirect( add_query_arg( 'km_err', '1', $base ) );
        exit;
    }

    // Succes : rediriger vers le bon tableau de bord
    $url = in_array( 'artiste_label', (array) $user->roles )
        ? km_dashboard_url( 'mon-tableau-de-bord' )
        : km_dashboard_url( 'dashboard-label' );
    wp_safe_redirect( $url, 302 );
    exit;
}

// ══════════════════════════════════════════════════════════════
// SHORTCODE PRINCIPAL — rendu uniquement (plus de redirections ici)
// ══════════════════════════════════════════════════════════════
add_shortcode( 'km_login_form', 'km_render_login_shortcode' );
function km_render_login_shortcode() {

    // Determiner le mode selon les parametres GET
    $action   = isset( $_GET['km_action'] ) ? sanitize_key( $_GET['km_action'] ) : '';
    $km_reset = isset( $_GET['km_reset'] )  ? sanitize_key( $_GET['km_reset'] )  : '';

    // Mode : succes reinitialisation
    if ( $km_reset === 'success' ) {
        return km_render_reset_success();
    }

    // Mode : formulaire "mot de passe oublie"
    if ( $action === 'lost_password' ) {
        return km_render_lost_password_form();
    }

    // Mode : formulaire nouveau mot de passe
    if ( $action === 'reset_password' ) {
        return km_render_reset_password_form();
    }

    // Mode par defaut : connexion
    return km_render_login_form();
}

// ══════════════════════════════════════════════════════════════
// FORMULAIRE DE CONNEXION
// ══════════════════════════════════════════════════════════════
function km_render_login_form() {
    // Securite : si l'utilisateur est connecte et que template_redirect
    // n'a pas pu rediriger (ex: shortcode hors page connexion),
    // on utilise un redirect JS qui fonctionne meme apres les headers
    if ( is_user_logged_in() ) {
        $user = wp_get_current_user();
        $url  = in_array( 'artiste_label', (array) $user->roles )
            ? km_dashboard_url( 'mon-tableau-de-bord' )
            : km_dashboard_url( 'dashboard-label' );
        return '<script>window.location.replace(' . wp_json_encode( $url ) . ');</script>';
    }

    // Erreur de connexion transmise via GET (traitee par km_process_login_post sur init)
    $error = isset( $_GET['km_err'] ) ? 'Identifiants incorrects. Veuillez reessayer.' : '';

    $logo_url  = defined( 'KM_LOGO_URL' ) ? KM_LOGO_URL : '';
    $reset_url = km_dashboard_url( 'connexion-artiste' ) . '?km_action=lost_password';

    ob_start();
    ?>
    <div class="km-auth">
      <?php echo km_auth_background(); ?>
      <div class="km-auth-container">
        <?php echo km_auth_brand_panel( $logo_url ); ?>
        <div class="km-auth-panel">
          <div class="km-auth-card">
            <div class="km-auth-badge"><span class="km-auth-dot"></span> ESPACE ARTISTE</div>
            <h1 class="km-auth-title">Connexion</h1>
            <p class="km-auth-sub">Accedez a votre tableau de bord personnel.</p>

            <?php if ( $error ) : ?>
              <div class="km-auth-alert km-auth-alert-error">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?php echo esc_html( $error ); ?></span>
              </div>
            <?php endif; ?>

            <form method="post" class="km-auth-form" novalidate>
              <?php wp_nonce_field( 'km_login', 'km_login_nonce' ); ?>
              <div class="km-auth-field">
                <label for="km-username">Identifiant ou e-mail</label>
                <div class="km-auth-input-wrap">
                  <svg class="km-auth-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                  <input type="text" id="km-username" name="username" placeholder="votre_nom" autocomplete="username" required />
                </div>
              </div>
              <div class="km-auth-field">
                <label for="km-password">Mot de passe</label>
                <div class="km-auth-input-wrap">
                  <svg class="km-auth-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  <input type="password" id="km-password" name="password" placeholder="Votre mot de passe" autocomplete="current-password" required />
                  <button type="button" class="km-auth-toggle" onclick="kmTogglePwd(this,'km-password')" aria-label="Afficher/masquer">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
              </div>
              <div class="km-auth-row">
                <label class="km-auth-check">
                  <input type="checkbox" name="remember" value="1" />
                  <span class="km-auth-check-box"></span>
                  <span>Se souvenir</span>
                </label>
                <a href="<?php echo esc_url( $reset_url ); ?>" class="km-auth-link">Oublie ?</a>
              </div>
              <button type="submit" class="km-auth-btn">
                <span>Acceder a mon espace</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
              </button>
            </form>
            <div class="km-auth-footer"><span>Protege par</span><strong>KOPHI'S MUSIC</strong></div>
          </div>
        </div>
      </div>
    </div>
    <script>
    function kmTogglePwd(btn,id){var el=document.getElementById(id);if(!el)return;el.type=el.type==='password'?'text':'password';btn.classList.toggle('km-auth-toggle-on');}
    </script>
    <?php
    return ob_get_clean();
}

// ══════════════════════════════════════════════════════════════
// FORMULAIRE "MOT DE PASSE OUBLIE"
// ══════════════════════════════════════════════════════════════
function km_render_lost_password_form() {
    $km_sent  = isset( $_GET['km_sent'] );
    $km_error = isset( $_GET['km_error'] ) ? sanitize_key( $_GET['km_error'] ) : '';
    $logo_url = defined( 'KM_LOGO_URL' ) ? KM_LOGO_URL : '';
    $base     = km_dashboard_url( 'connexion-artiste' );

    $msg = '';
    if ( $km_sent ) {
        $msg = '<div class="km-auth-alert km-auth-alert-success">'
             . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>'
             . '<span>Si un compte correspond, un e-mail de reinitialisation a ete envoye. Verifiez votre boite de reception (et les spams).</span>'
             . '</div>';
    }
    if ( $km_error === 'invalid_key' ) {
        $msg = '<div class="km-auth-alert km-auth-alert-error">'
             . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg>'
             . '<span>Lien invalide ou expire. Faites une nouvelle demande.</span></div>';
    }

    ob_start();
    ?>
    <div class="km-auth">
      <?php echo km_auth_background(); ?>
      <div class="km-auth-container">
        <?php echo km_auth_brand_panel( $logo_url ); ?>
        <div class="km-auth-panel">
          <div class="km-auth-card">
            <div class="km-auth-badge"><span class="km-auth-dot"></span> RECUPERATION</div>
            <h1 class="km-auth-title">Mot de passe oublie</h1>
            <p class="km-auth-sub">Saisissez votre e-mail ou identifiant pour recevoir un lien de reinitialisation.</p>
            <?php echo $msg; ?>
            <form method="post" action="<?php echo esc_url( $base ); ?>" class="km-auth-form" novalidate>
              <?php wp_nonce_field( 'km_lost_password', 'km_lost_nonce' ); ?>
              <input type="hidden" name="km_do_lost_password" value="1" />
              <div class="km-auth-field">
                <label for="km-user-login">Adresse e-mail ou identifiant</label>
                <div class="km-auth-input-wrap">
                  <svg class="km-auth-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                  <input type="text" id="km-user-login" name="user_login" placeholder="votre@email.com" required autocomplete="email" autofocus />
                </div>
              </div>
              <button type="submit" class="km-auth-btn">
                <span>Envoyer le lien</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              </button>
            </form>
            <div class="km-auth-footer-links">
              <a href="<?php echo esc_url( $base ); ?>" class="km-auth-link-back">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                Retour a la connexion
              </a>
            </div>
            <div class="km-auth-footer"><span>Protege par</span><strong>KOPHI'S MUSIC</strong></div>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

// ══════════════════════════════════════════════════════════════
// FORMULAIRE NOUVEAU MOT DE PASSE
// ══════════════════════════════════════════════════════════════
function km_render_reset_password_form() {
    $login = isset( $_GET['login'] ) ? sanitize_text_field( rawurldecode( $_GET['login'] ) ) : '';
    $key   = isset( $_GET['key'] )   ? trim( rawurldecode( $_GET['key'] ) )                  : '';

    $user_check = check_password_reset_key( $key, $login );
    $logo_url   = defined( 'KM_LOGO_URL' ) ? KM_LOGO_URL : '';
    $base       = km_dashboard_url( 'connexion-artiste' );

    // Lien invalide
    if ( is_wp_error( $user_check ) ) {
        $new_url = $base . ( strpos( $base, '?' ) !== false ? '&' : '?' ) . 'km_action=lost_password';
        ob_start();
        ?>
        <div class="km-auth">
          <?php echo km_auth_background(); ?>
          <div class="km-auth-container">
            <?php echo km_auth_brand_panel( $logo_url ); ?>
            <div class="km-auth-panel">
              <div class="km-auth-card">
                <div class="km-auth-badge"><span class="km-auth-dot"></span> ERREUR</div>
                <div class="km-auth-error-icon">
                  <svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                </div>
                <h1 class="km-auth-title">Lien invalide</h1>
                <p class="km-auth-sub">Ce lien de reinitialisation est invalide ou a expire (valable 24 heures maximum).</p>
                <a href="<?php echo esc_url( $new_url ); ?>" class="km-auth-btn km-auth-btn-block" style="text-decoration:none;">
                  <span>Nouvelle demande</span>
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
                <div class="km-auth-footer"><span>Protege par</span><strong>KOPHI'S MUSIC</strong></div>
              </div>
            </div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }

    $km_error = isset( $_GET['km_error'] ) ? sanitize_key( $_GET['km_error'] ) : '';
    $msg = '';
    if ( $km_error === 'mismatch' ) {
        $msg = '<div class="km-auth-alert km-auth-alert-error"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg><span>Les mots de passe ne correspondent pas.</span></div>';
    } elseif ( $km_error === 'tooshort' ) {
        $msg = '<div class="km-auth-alert km-auth-alert-error"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg><span>Le mot de passe doit contenir au moins 8 caracteres.</span></div>';
    }

    ob_start();
    ?>
    <div class="km-auth">
      <?php echo km_auth_background(); ?>
      <div class="km-auth-container">
        <?php echo km_auth_brand_panel( $logo_url ); ?>
        <div class="km-auth-panel">
          <div class="km-auth-card">
            <div class="km-auth-badge"><span class="km-auth-dot"></span> REINITIALISATION</div>
            <h1 class="km-auth-title">Nouveau mot de passe</h1>
            <p class="km-auth-sub">Bonjour <strong><?php echo esc_html( $user_check->display_name ); ?></strong>, choisissez votre nouveau mot de passe.</p>
            <?php echo $msg; ?>
            <form method="post" action="<?php echo esc_url( $base ); ?>" class="km-auth-form" novalidate>
              <?php wp_nonce_field( 'km_reset_password', 'km_reset_nonce' ); ?>
              <input type="hidden" name="km_do_reset_password" value="1" />
              <input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>" />
              <input type="hidden" name="key"   value="<?php echo esc_attr( $key ); ?>" />

              <div class="km-auth-field">
                <label for="km-pass1">Nouveau mot de passe</label>
                <div class="km-auth-input-wrap">
                  <svg class="km-auth-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  <input type="password" id="km-pass1" name="pass1" placeholder="Minimum 8 caracteres" minlength="8" required autocomplete="new-password" autofocus />
                  <button type="button" class="km-auth-toggle" onclick="kmTogglePwd(this,'km-pass1')">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                  </button>
                </div>
                <div class="km-auth-strength"><div class="km-auth-strength-bar"><span id="km-sb"></span></div><div id="km-sl" class="km-auth-strength-label"></div></div>
              </div>

              <div class="km-auth-field">
                <label for="km-pass2">Confirmer le mot de passe</label>
                <div class="km-auth-input-wrap">
                  <svg class="km-auth-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  <input type="password" id="km-pass2" name="pass2" placeholder="Confirmez" minlength="8" required autocomplete="new-password" />
                </div>
                <div id="km-match" class="km-auth-match"></div>
              </div>

              <button type="submit" class="km-auth-btn">
                <span>Enregistrer le nouveau mot de passe</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
              </button>
            </form>
            <div class="km-auth-footer"><span>Protege par</span><strong>KOPHI'S MUSIC</strong></div>
          </div>
        </div>
      </div>
    </div>
    <script>
    function kmTogglePwd(btn,id){var el=document.getElementById(id);if(!el)return;el.type=el.type==='password'?'text':'password';btn.classList.toggle('km-auth-toggle-on');}
    (function(){
      var p1=document.getElementById('km-pass1'),p2=document.getElementById('km-pass2');
      var sb=document.getElementById('km-sb'),sl=document.getElementById('km-sl'),mt=document.getElementById('km-match');
      if(!p1)return;
      function check(){
        var v=p1.value,sc=0;
        if(v.length>=8)sc++;if(v.length>=12)sc++;
        if(/[A-Z]/.test(v))sc++;if(/[0-9]/.test(v))sc++;if(/[^A-Za-z0-9]/.test(v))sc++;
        var labels=['Tres faible','Faible','Moyen','Fort','Excellent'];
        var colors=['#FF4E6A','#FF9500','#F5A623','#00D67F','#00E596'];
        var pcts=[20,40,60,80,100];
        if(!v){sb.style.width='0%';sl.textContent='';return;}
        var idx=Math.min(sc,4);
        sb.style.width=pcts[idx]+'%';sb.style.background=colors[idx];
        sl.textContent=labels[idx];sl.style.color=colors[idx];
        if(p2.value){
          if(p2.value===v){mt.innerHTML='<span style="color:#00D67F;">&#10003; Les mots de passe correspondent</span>';}
          else{mt.innerHTML='<span style="color:#FF4E6A;">&#10007; Les mots de passe ne correspondent pas</span>';}
        }else{mt.innerHTML='';}
      }
      p1.addEventListener('input',check);if(p2)p2.addEventListener('input',check);
    })();
    </script>
    <?php
    return ob_get_clean();
}

// ══════════════════════════════════════════════════════════════
// PAGE DE SUCCES
// ══════════════════════════════════════════════════════════════
function km_render_reset_success() {
    $logo_url = defined( 'KM_LOGO_URL' ) ? KM_LOGO_URL : '';
    $base     = km_dashboard_url( 'connexion-artiste' );
    ob_start();
    ?>
    <div class="km-auth">
      <?php echo km_auth_background(); ?>
      <div class="km-auth-container">
        <?php echo km_auth_brand_panel( $logo_url ); ?>
        <div class="km-auth-panel">
          <div class="km-auth-card km-auth-card-success">
            <div class="km-auth-badge km-auth-badge-success"><span class="km-auth-dot"></span> SUCCES</div>
            <div class="km-auth-success-icon">
              <svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <h1 class="km-auth-title">Mot de passe mis a jour</h1>
            <p class="km-auth-sub">Votre mot de passe a ete modifie avec succes. Connectez-vous avec vos nouveaux identifiants.</p>
            <a href="<?php echo esc_url( $base ); ?>" class="km-auth-btn km-auth-btn-block" style="text-decoration:none;">
              <span>Se connecter</span>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
            </a>
            <div class="km-auth-footer"><span>Protege par</span><strong>KOPHI'S MUSIC</strong></div>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

// ══════════════════════════════════════════════════════════════
// TRAITEMENT POST : mot de passe oublie
// Hook uniquement sur init (tot, avant tout rendu)
// ══════════════════════════════════════════════════════════════
add_action( 'init', 'km_process_password_forms' );
function km_process_password_forms() {
    // Uniquement si un formulaire est soumis
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;

    // ── ETAPE 1 : demande de reinitialisation ──
    if ( isset( $_POST['km_do_lost_password'] ) ) {
        if ( ! isset( $_POST['km_lost_nonce'] ) || ! wp_verify_nonce( $_POST['km_lost_nonce'], 'km_lost_password' ) ) return;

        $login = sanitize_text_field( wp_unslash( $_POST['user_login'] ) );
        $user  = false;
        if ( is_email( $login ) ) $user = get_user_by( 'email', $login );
        if ( ! $user )            $user = get_user_by( 'login', $login );
        if ( ! $user )            $user = get_user_by( 'slug', $login );

        if ( $user ) {
            $key = get_password_reset_key( $user );
            if ( ! is_wp_error( $key ) ) {
                $base = km_dashboard_url( 'connexion-artiste' );
                $sep  = strpos( $base, '?' ) !== false ? '&' : '?';
                $reset_url = $base . $sep
                    . 'km_action=reset_password'
                    . '&login=' . rawurlencode( $user->user_login )
                    . '&key='   . $key;

                $site_name = get_bloginfo( 'name' );
                $subject   = '[' . $site_name . '] Reinitialisation de votre mot de passe';
                $message   = 'Bonjour ' . $user->display_name . ',' . "\r\n\r\n";
                $message  .= 'Vous avez demande la reinitialisation de votre mot de passe pour votre espace artiste.' . "\r\n\r\n";
                $message  .= 'Cliquez sur le lien ci-dessous (valable 24 heures) :' . "\r\n\r\n";
                $message  .= $reset_url . "\r\n\r\n";
                $message  .= 'Si vous n etes pas a l origine de cette demande, ignorez cet e-mail.' . "\r\n\r\n";
                $message  .= '---' . "\r\n";
                $message  .= 'L equipe ' . $site_name;

                wp_mail( $user->user_email, $subject, $message );
            }
        }

        $base = km_dashboard_url( 'connexion-artiste' );
        $sep  = strpos( $base, '?' ) !== false ? '&' : '?';
        wp_safe_redirect( $base . $sep . 'km_action=lost_password&km_sent=1' );
        exit;
    }

    // ── ETAPE 2 : enregistrement du nouveau mot de passe ──
    if ( isset( $_POST['km_do_reset_password'] ) ) {
        if ( ! isset( $_POST['km_reset_nonce'] ) || ! wp_verify_nonce( $_POST['km_reset_nonce'], 'km_reset_password' ) ) return;

        $login = isset( $_POST['login'] ) ? sanitize_text_field( wp_unslash( $_POST['login'] ) ) : '';
        $key   = isset( $_POST['key'] )   ? trim( wp_unslash( $_POST['key'] ) )                 : '';
        $pass1 = isset( $_POST['pass1'] ) ? wp_unslash( $_POST['pass1'] ) : '';
        $pass2 = isset( $_POST['pass2'] ) ? wp_unslash( $_POST['pass2'] ) : '';

        $user = check_password_reset_key( $key, $login );
        $base = km_dashboard_url( 'connexion-artiste' );
        $sep  = strpos( $base, '?' ) !== false ? '&' : '?';

        if ( is_wp_error( $user ) ) {
            wp_safe_redirect( $base . $sep . 'km_action=lost_password&km_error=invalid_key' );
            exit;
        }
        if ( empty( $pass1 ) || $pass1 !== $pass2 ) {
            wp_safe_redirect( $base . $sep . 'km_action=reset_password&login=' . rawurlencode( $login ) . '&key=' . rawurlencode( $key ) . '&km_error=mismatch' );
            exit;
        }
        if ( strlen( $pass1 ) < 8 ) {
            wp_safe_redirect( $base . $sep . 'km_action=reset_password&login=' . rawurlencode( $login ) . '&key=' . rawurlencode( $key ) . '&km_error=tooshort' );
            exit;
        }

        // CHANGEMENT EFFECTIF — utilise wp_set_password (plus simple et sur)
        wp_set_password( $pass1, $user->ID );

        // Detruire toutes les sessions
        if ( class_exists( 'WP_Session_Tokens' ) ) {
            $sessions = WP_Session_Tokens::get_instance( $user->ID );
            $sessions->destroy_all();
        }

        wp_safe_redirect( $base . $sep . 'km_reset=success' );
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
// COMPOSANTS UI PARTAGES
// ══════════════════════════════════════════════════════════════

function km_auth_background() {
    return '<div class="km-auth-bg">'
         . '<div class="km-auth-orb km-orb-1"></div>'
         . '<div class="km-auth-orb km-orb-2"></div>'
         . '<div class="km-auth-orb km-orb-3"></div>'
         . '<div class="km-auth-grid-pattern"></div>'
         . '</div>';
}

function km_auth_brand_panel( $logo_url ) {
    $year = date( 'Y' );
    ob_start();
    ?>
    <div class="km-auth-brand">
      <div class="km-brand-float km-brand-float-1">&#9834;</div>
      <div class="km-brand-float km-brand-float-2">&#9835;</div>
      <div class="km-brand-float km-brand-float-3">&#9836;</div>

      <div class="km-brand-top">
        <div class="km-brand-logo-row">
          <?php if ( $logo_url ) : ?>
            <img src="<?php echo esc_url( $logo_url ); ?>" alt="KOPHI'S MUSIC" class="km-brand-logo-img" />
          <?php else : ?>
            <div class="km-brand-logo-fallback">KM</div>
          <?php endif; ?>
          <div>
            <strong class="km-brand-name">KOPHI'S MUSIC</strong>
            <span class="km-brand-tag">Label Urban Gospel</span>
          </div>
        </div>
      </div>

      <div class="km-brand-middle">
        <div class="km-brand-vinyl">
          <div class="km-brand-vinyl-inner"></div>
          <div class="km-brand-vinyl-center"></div>
        </div>
        <h2 class="km-brand-hero">La voix de la<br><em>nouvelle generation</em><br>Urban Gospel</h2>
        <div class="km-brand-chips">
          <span class="km-brand-chip">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            Urban Gospel
          </span>
          <span class="km-brand-chip">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
            Cote d'Ivoire
          </span>
          <span class="km-brand-chip">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            Artistes &amp; Label
          </span>
        </div>
      </div>

      <div class="km-brand-bottom">
        <div class="km-brand-stats">
          <div class="km-brand-stat"><strong>100%</strong><span>Securise</span></div>
          <div class="km-brand-stat"><strong>24/7</strong><span>Acces</span></div>
          <div class="km-brand-stat"><strong>&#10003;</strong><span>Verifie</span></div>
        </div>
        <div class="km-brand-copyright">&copy; KOPHI'S MUSIC 2025&ndash;<?php echo esc_html( $year ); ?> &middot; KOPHI'S GROUP SAS</div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

// ══════════════════════════════════════════════════════════════
// HELPERS METIER — Commission / Part Artiste
// ══════════════════════════════════════════════════════════════

if ( ! function_exists( 'km_get_artist_commission' ) ) {
    function km_get_artist_commission( $user_id ) {
        if ( $user_id ) {
            $posts = get_posts( array(
                'post_type'      => 'nos-artistes',
                'posts_per_page' => 1,
                'post_status'    => 'publish',
                'meta_key'       => 'artiste_user_id',
                'meta_value'     => $user_id,
            ) );
            if ( $posts ) {
                $v = get_field( 'commission_artiste', $posts[0]->ID );
                if ( $v !== false && $v !== '' && $v !== null ) return floatval( $v ) / 100;
            }
        }
        return floatval( get_option( 'km_label_commission', 30 ) ) / 100;
    }
}

/**
 * Commission specifique par titre (son).
 * Retourne le pourcentage label (0-1) pour un titre donne.
 * Priorite :
 *   1. Override par ISRC (cle unique du titre)
 *   2. Override par titre + artiste (normalise)
 *   3. Commission artiste (fallback)
 *
 * @param int    $user_id     ID utilisateur WordPress (artiste)
 * @param string $track_title Titre du son
 * @param string $isrc        ISRC (optionnel, prioritaire)
 * @return float Pourcentage label entre 0 et 1
 */
/**
 * Recherche une répartition personnalisée multi-artistes pour un titre
 * donné (définie depuis KM Dashboard → Commissions par titre → mode
 * "Multi-artistes"). Priorité ISRC > Titre, comme pour les overrides
 * simples de km_get_track_commission().
 *
 * @param string $isrc  ISRC du titre (optionnel, prioritaire).
 * @param string $title Titre du son (fallback si pas d'ISRC).
 * @return array|null   array('label_pct'=>float, 'artists'=>array(user_id=>pct,...)) ou null.
 */
if ( ! function_exists( 'km_get_track_multi_split' ) ) {
    function km_get_track_multi_split( $isrc = '', $title = '' ) {
        $splits = get_option( 'km_track_multi_splits', array() );
        if ( ! is_array( $splits ) ) return null;

        if ( $isrc ) {
            $k = 'isrc:' . sanitize_key( $isrc );
            if ( isset( $splits[ $k ] ) && ! empty( $splits[ $k ]['artists'] ) ) return $splits[ $k ];
        }
        if ( $title ) {
            $k = 'title:' . sanitize_title( $title );
            if ( isset( $splits[ $k ] ) && ! empty( $splits[ $k ]['artists'] ) ) return $splits[ $k ];
        }
        return null;
    }
}

if ( ! function_exists( 'km_get_track_commission' ) ) {
    function km_get_track_commission( $user_id, $track_title = '', $isrc = '' ) {
        $overrides = get_option( 'km_track_commissions', array() );

        // 1. Par ISRC (plus precis)
        if ( $isrc ) {
            $isrc_key = 'isrc:' . sanitize_key( $isrc );
            if ( isset( $overrides[ $isrc_key ] ) && $overrides[ $isrc_key ] !== '' ) {
                return floatval( $overrides[ $isrc_key ] ) / 100;
            }
        }

        // 2. Par titre + artiste normalise
        if ( $track_title && $user_id ) {
            $title_key = 'track:' . $user_id . ':' . sanitize_title( $track_title );
            if ( isset( $overrides[ $title_key ] ) && $overrides[ $title_key ] !== '' ) {
                return floatval( $overrides[ $title_key ] ) / 100;
            }
        }

        // 3. Fallback : commission artiste
        return km_get_artist_commission( $user_id );
    }
}

/**
 * Calcule la part artiste pour un titre specifique.
 * Utilise la commission par titre si definie, sinon celle de l'artiste.
 */
if ( ! function_exists( 'km_calculate_part_track' ) ) {
    function km_calculate_part_track( $total_gains, $user_id, $track_title = '', $isrc = '' ) {
        $commission = km_get_track_commission( $user_id, $track_title, $isrc );
        return round( $total_gains * ( 1 - $commission ), 6 );
    }
}

if ( ! function_exists( 'km_calculate_part_artiste' ) ) {
    function km_calculate_part_artiste( $total_gains, $user_id ) {
        return round( $total_gains * ( 1 - km_get_artist_commission( $user_id ) ), 6 );
    }
}

if ( ! function_exists( 'km_is_label_tunecore_name' ) ) {
    /**
     * Indique si un nom TuneCore correspond au LABEL lui-même (ex: "KOPHI'S
     * MUSIC" crédité comme co-artiste sur un featuring) et non à un artiste
     * réel. Marqué depuis KM Dashboard → Mapping Artistes en choisissant
     * "🏷️ C'est le Label" (stocké comme -1 dans km_tunecore_mapping, pour
     * le distinguer de 0 = simplement pas encore mappé).
     *
     * Ces noms sont exclus du partage streams/gains entre artistes lors de
     * l'import : leur part reste implicitement dans la part du label,
     * plutôt que de créer une fiche "artiste" fantôme jamais payée.
     */
    function km_is_label_tunecore_name( $tc_name ) {
        $mapping = get_option( 'km_tunecore_mapping', array() );
        return isset( $mapping[ $tc_name ] ) && (int) $mapping[ $tc_name ] === -1;
    }
}

if ( ! function_exists( 'km_get_artist_user_id' ) ) {
    function km_get_artist_user_id( $tc_name ) {
        $mapping = get_option( 'km_tunecore_mapping', array() );
        if ( isset( $mapping[ $tc_name ] ) ) {
            $v = intval( $mapping[ $tc_name ] );
            return $v > 0 ? $v : 0; // -1 (label) ou 0 (non mappé) => jamais un user_id valide
        }
        $posts = get_posts( array(
            'post_type'      => 'nos-artistes',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'meta_query'     => array( array( 'key' => 'tunecore_name', 'value' => $tc_name, 'compare' => 'LIKE' ) ),
        ) );
        if ( $posts ) {
            $uid = (int) get_field( 'artiste_user_id', $posts[0]->ID );
            if ( $uid ) return $uid;
        }
        return 0;
    }
}
