<?php
/**
 * Intégration WPCode : enregistrement de snippets de code depuis le plugin
 * Ces snippets apparaissent dans WPCode > Snippets > Bibliothèque Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KM_Salle_Ecoute_WPCode_Snippets {

    public static function init(): void {
        // WPCode 2.x hook pour enregistrer des snippets depuis un plugin
        add_filter( 'wpcode_get_snippets_for_plugin', [ __CLASS__, 'register_snippets' ] );
    }

    public static function register_snippets( $snippets ) {
        if ( ! is_array( $snippets ) ) return $snippets;
        $snippets[] = [
            'title'   => 'KM SALLE D\'ÉCOUTE – Désactiver le clic droit sur les pages audio',
            'code'    => self::get_disable_right_click_code(),
            'type'    => 'js',
            'tags'    => [ 'km-salle-ecoute', 'securite', 'audio' ],
            'note'    => 'Empêche le clic droit sur les pages de session d\'écoute pour protéger les fichiers.',
        ];

        // Le journal des accès est désormais intégré au plugin (Réglages > Journal des
        // accès) : l'ancien snippet, basé sur $_SESSION, n'est plus proposé.

        $snippets[] = [
            'title'   => 'KM SALLE D\'ÉCOUTE – Notification email au partenaire',
            'code'    => self::get_email_notification_code(),
            'type'    => 'php',
            'tags'    => [ 'km-salle-ecoute', 'email', 'partenaire' ],
            'note'    => 'Envoie un email au partenaire dès la publication d\'une session qui lui est dédiée.',
        ];

        return $snippets;
    }

    private static function get_disable_right_click_code(): string {
        return <<<'JS'
// KM SALLE D'ÉCOUTE - Désactiver le clic droit sur les pages de session
(function() {
    if (document.body.classList.contains('single-kse_session')) {
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });
        // Bloquer aussi les raccourcis clavier de téléchargement
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                e.preventDefault();
            }
        });
    }
})();
JS;
    }

    private static function get_email_notification_code(): string {
        return <<<'PHP'
<?php
// KM SALLE D'ÉCOUTE - Notification email au partenaire à la publication
// acf/save_post (priorité 20) : les champs ACF sont déjà enregistrés. Avec
// publish_kse_session ils ne l'étaient pas encore lors de la première publication.
add_action('acf/save_post', function($post_id) {
    if (!is_numeric($post_id) || get_post_type($post_id) !== 'kse_session') return;
    if (get_post_status($post_id) !== 'publish') return;
    $email_partenaire = get_field('km_email_partenaire', $post_id);
    if (!$email_partenaire || !is_email($email_partenaire)) return;
    
    // Évite les doublons d'envoi
    if (get_post_meta($post_id, '_km_notif_sent', true)) return;
    
    $nom_partenaire = get_field('km_nom_partenaire', $post_id) ?: 'Partenaire';
    $titre_session  = get_the_title($post_id);
    $lien_session   = get_permalink($post_id);
    $mdp            = get_field('km_mot_de_passe', $post_id);
    $message_custom = get_field('km_message_partenaire', $post_id);
    $expiration     = get_field('km_date_expiration', $post_id);
    
    $label   = class_exists('KM_Salle_Ecoute_ACF_Helper') ? KM_Salle_Ecoute_ACF_Helper::label_nom() : 'KM Label';
    $sujet   = sprintf('%s — Nouvelle session d’écoute : %s', $label, $titre_session);
    $contenu = sprintf(
        "Bonjour %s,\n\n%s\n\nAccéder à la session d’écoute :\n%s\n\n%s%sBonne écoute !\n\n— L’équipe %s",
        $nom_partenaire,
        $message_custom ?: 'Nous avons le plaisir de vous partager une nouvelle session d\'écoute.',
        $lien_session,
        ($mdp && get_field('km_acces_type', $post_id) === 'password') ? "Mot de passe : {$mdp}\n\n" : '',
        $expiration ? 'Ce lien expire le : ' . wp_date('j F Y', strtotime($expiration)) . "\n\n" : '',
        $label
    );
    
    $headers = ['Content-Type: text/plain; charset=UTF-8'];
    
    if (wp_mail($email_partenaire, $sujet, $contenu, $headers)) {
        update_post_meta($post_id, '_km_notif_sent', 1);
    }
}, 20);
PHP;
    }
}
