<?php if (!defined('ABSPATH')) exit;
// Rediriger directement vers le dashboard admin KOPHI si connecté
if (is_user_logged_in() && (current_user_can('kophi_manage_sessions') || current_user_can('administrator'))) {
    wp_redirect(admin_url('admin.php?page=kophi-dashboard'));
    exit;
}
// Sinon afficher formulaire de connexion
$login_url = wp_login_url(get_permalink());
?>
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:'Inter',sans-serif;background:linear-gradient(to bottom,#0D0D0D 0%,#0D0D0D 60%,#120a24 85%,#1e0938 100%)">
  <div style="background:rgba(255,255,255,.04);border:1px solid rgba(232,184,75,.25);border-radius:20px;padding:40px;text-align:center;max-width:380px;width:90%">
    <div style="font-size:52px;margin-bottom:16px">🎙</div>
    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:32px;color:#fff;letter-spacing:3px;margin:0 0 8px">KOPHI'S MUSIC<br><span style="color:#E8B84B">STUDIO</span></h2>
    <p style="font-size:12px;color:#9E98B8;margin-bottom:28px">Accès réservé à l'équipe studio</p>
    <a href="<?= esc_url($login_url) ?>" style="display:block;background:linear-gradient(135deg,#8B3FD9,#E8B84B);color:#fff;text-decoration:none;padding:14px;border-radius:12px;font-family:'Bebas Neue',sans-serif;font-size:18px;letter-spacing:2px">
      🔐 SE CONNECTER
    </a>
    <div style="margin-top:20px;font-size:10px;color:#f2a007">
      <a href="<?= esc_url(home_url('/reserver')) ?>" style="color:#E8B84B;text-decoration:none">🎙 Réserver une session</a>
      &nbsp;·&nbsp;
      <a href="<?= esc_url(home_url('/beats')) ?>" style="color:#C084FC;text-decoration:none">🎹 Boutique Beats</a>
    </div>
  </div>
</div>
