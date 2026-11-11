<?php
/**
 * KOPHI'S MUSIC STUDIO — Page de connexion personnalisée
 * ================================================================
 * Habille wp-login.php aux couleurs du studio (or / violet, fond
 * sombre dégradé), corrige les champs « blocs gris » sans libellé,
 * et soigne les boutons. N'altère AUCUNE logique de connexion :
 * compatible avec la connexion Google déjà en place.
 */

if (!defined('ABSPATH')) exit;

// Garde-fou : empêche tout double-chargement
if (defined('KSM_LOGIN_LOADED')) return;
define('KSM_LOGIN_LOADED', true);

// ── Logo cliquable vers l'accueil du studio (au lieu de wordpress.org)
add_filter('login_headerurl', function () { return home_url('/'); });
add_filter('login_headertext', function () { return "KOPHI'S MUSIC STUDIO"; });

// ── Styles de la page de connexion ──────────────────────────────
add_action('login_enqueue_scripts', 'ksm_login_styles');
function ksm_login_styles() {
    // Récupère le logo du site si défini (sinon emoji micro)
    $logo = '';
    if (function_exists('get_custom_logo')) {
        $logo_id = get_theme_mod('custom_logo');
        if ($logo_id) {
            $src = wp_get_attachment_image_src($logo_id, 'full');
            if ($src) $logo = $src[0];
        }
    }
    ?>
    <style>
      :root{
        --kg:#E8B84B; --kg2:#F5CC6A; --kv:#8B3FD9; --kv2:#A855F7;
        --kd:#0D0D0D; --ktext:#F0ECF8; --ktext3:#9E98B8;
      }
      body.login{
        background:linear-gradient(160deg,#0D0D0D 0%,#0D0D0D 50%,#160a2b 80%,#1e0938 100%) !important;
        background-attachment:fixed;
        font-family:'Inter',-apple-system,Segoe UI,sans-serif;
        min-height:100vh;
      }
      body.login::before{
        content:''; position:fixed; inset:0; pointer-events:none;
        background:
          radial-gradient(ellipse 60% 50% at 75% 15%,rgba(139,63,217,.22),transparent 60%),
          radial-gradient(ellipse 50% 40% at 20% 85%,rgba(232,184,75,.12),transparent 55%);
      }
      #login{ width:380px; max-width:92vw; padding-top:6vh; position:relative; z-index:2; }

      /* En-tête / logo */
      .login h1{ margin-bottom:14px; }
      .login h1 a{
        width:100%; height:96px; background-position:center; background-size:contain;
        <?php if ($logo): ?>
        background-image:url('<?= esc_url($logo) ?>') !important;
        <?php else: ?>
        background-image:none !important; text-indent:0; overflow:visible;
        font-family:'Bebas Neue',sans-serif; color:#fff; font-size:0;
        <?php endif; ?>
      }
      <?php if (!$logo): ?>
      .login h1 a::before{
        content:'🎙'; display:block; font-size:54px; line-height:1; text-align:center; margin-bottom:6px;
      }
      .login h1 a::after{
        content:"KOPHI'S MUSIC STUDIO"; display:block; font-family:'Bebas Neue',sans-serif;
        font-size:22px; letter-spacing:3px; color:#fff; text-align:center;
      }
      <?php endif; ?>

      /* Carte du formulaire */
      .login form{
        background:rgba(255,255,255,.04) !important;
        border:1px solid rgba(232,184,75,.22) !important;
        border-radius:18px !important;
        box-shadow:0 20px 60px rgba(0,0,0,.5), 0 0 40px rgba(139,63,217,.12) !important;
        backdrop-filter:blur(18px);
        padding:30px 28px !important;
        margin-top:14px;
      }
      .login form .input,
      .login input[type=text],
      .login input[type=password]{
        background:rgba(255,255,255,.06) !important;
        border:1px solid rgba(255,255,255,.14) !important;
        border-radius:11px !important;
        color:#fff !important;
        font-size:15px !important;
        padding:13px 14px !important;
        box-shadow:none !important;
        transition:border-color .2s, box-shadow .2s;
      }
      .login input[type=text]:focus,
      .login input[type=password]:focus{
        border-color:var(--kg) !important;
        box-shadow:0 0 0 3px rgba(232,184,75,.18) !important;
        outline:none !important;
      }
      .login label{
        color:var(--ktext3) !important; font-size:12px !important;
        font-weight:600; letter-spacing:.3px;
      }
      .login .button.wp-hide-pw{ color:var(--ktext3) !important; }
      .login .forgetmenot label{ color:var(--ktext3) !important; }

      /* Bouton principal */
      .login .button-primary,
      .wp-core-ui .button-primary{
        background:linear-gradient(135deg,var(--kv),var(--kg)) !important;
        border:none !important;
        border-radius:12px !important;
        width:100% !important;
        text-align:center !important;
        float:none !important;
        font-family:'Bebas Neue',sans-serif !important;
        font-size:18px !important; letter-spacing:2px !important;
        text-transform:uppercase;
        padding:14px 0 !important; height:auto !important; line-height:1 !important;
        text-shadow:none !important; color:#fff !important;
        box-shadow:0 8px 24px rgba(139,63,217,.4) !important;
        transition:transform .15s, box-shadow .2s !important;
      }
      .login .button-primary:hover{
        transform:translateY(-2px);
        box-shadow:0 12px 32px rgba(232,184,75,.4) !important;
      }
      .login .submit{ text-align:center; }

      /* Liens bas de page */
      .login #nav, .login #backtoblog{ text-align:center; padding:0 0 0; }
      .login #nav a, .login #backtoblog a{
        color:var(--ktext3) !important; font-size:13px;
      }
      .login #nav a:hover, .login #backtoblog a:hover{ color:var(--kg) !important; }
      .login #backtoblog a::before{ content:'← '; }

      /* Message / erreurs */
      .login .message, .login #login_error{
        background:rgba(255,255,255,.05) !important;
        border-left:4px solid var(--kg) !important;
        color:var(--ktext) !important;
        border-radius:8px;
      }
      .login #login_error{ border-left-color:#FF4757 !important; }

      /* Sélecteur de langue */
      .language-switcher{ filter:saturate(1.1); }
      .language-switcher .button{ border-radius:10px; }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php
}

// ── Placeholders clairs dans les champs (corrige les « blocs gris ») ─
add_action('login_form', 'ksm_login_placeholders');
function ksm_login_placeholders() {
    ?>
    <script>
    (function(){
      var u = document.getElementById('user_login');
      var p = document.getElementById('user_pass');
      if (u){ u.setAttribute('placeholder','Identifiant ou e-mail'); u.setAttribute('autocomplete','username'); }
      if (p){ p.setAttribute('placeholder','Mot de passe'); }
    })();
    </script>
    <?php
}
