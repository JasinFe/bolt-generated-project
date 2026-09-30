<?php
/**
 * Template blank KM — page pleine largeur sans header/footer thème
 */
defined( 'ABSPATH' ) || exit;

// ── Retirer les hooks Astra (header + footer + content wrappers) ──
remove_all_actions( 'astra_header' );
remove_all_actions( 'astra_footer' );
remove_all_actions( 'astra_masthead_top' );
remove_all_actions( 'astra_masthead_bottom' );
remove_all_actions( 'astra_content_before' );
remove_all_actions( 'astra_content_top' );
remove_all_actions( 'astra_content_after' );
remove_all_actions( 'astra_content_bottom' );
remove_all_actions( 'astra_entry_before' );
remove_all_actions( 'astra_entry_content_before' );
remove_all_actions( 'astra_entry_after' );
remove_all_actions( 'astra_footer_content' );

// ── Retirer les footer builders courants ──
remove_all_actions( 'generate_footer' );       // GeneratePress
remove_all_actions( 'ocean_footer' );          // OceanWP
remove_all_actions( 'storefront_footer' );     // Storefront
remove_all_actions( 'neve_do_footer' );        // Neve
remove_all_actions( 'hfe_footer_content' );    // Header Footer Elementor
remove_all_actions( 'elementor/page_templates/canvas/after_content' );

// ── Supprimer les sorties HTML éventuelles injectées via wp_footer ──
// (footer builders Elementor, Divi, etc.) sans bloquer les scripts WP
add_action( 'wp_footer', function() {
    global $wp_filter;
    if ( isset( $wp_filter['wp_footer'] ) ) {
        foreach ( $wp_filter['wp_footer']->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $id => $callback ) {
                $fn = $callback['function'];
                // Garder les callbacks natifs WordPress (scripts, styles)
                if ( is_string( $fn ) && in_array( $fn, array(
                    'wp_print_footer_scripts', 'wp_admin_bar_render',
                    '_wp_footer_scripts', 'print_emoji_detection_script',
                    'wp_enqueue_scripts',
                ), true ) ) {
                    continue;
                }
                // Retirer les callbacks qui ne sont pas des fonctions WP core
                if ( is_string( $fn ) && strpos( $fn, 'wp_' ) !== 0 && strpos( $fn, '_wp_' ) !== 0 ) {
                    remove_action( 'wp_footer', $fn, $priority );
                }
                if ( is_array( $fn ) && isset( $fn[0] ) ) {
                    // Retirer les callbacks de classes/objets non-WP (footer builders)
                    $class = is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0];
                    if ( stripos( $class, 'footer' ) !== false
                      || stripos( $class, 'elementor' ) !== false
                      || stripos( $class, 'header' ) !== false ) {
                        remove_action( 'wp_footer', $fn, $priority );
                    }
                }
            }
        }
    }
}, 0 );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?php wp_title( '—', true, 'right' ); bloginfo( 'name' ); ?></title>
<?php wp_head(); ?>
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0 !important; padding: 0 !important; background: #050710 !important; }
/* overflow-x:clip au lieu de hidden : évite le bug iOS où overflow:hidden
   couplé à filter:blur() enfants provoque un rendu entièrement noir */
body { min-height: 100vh; overflow-x: clip; }

/* ── Masquer header thème (toutes variantes) ──
   IMPORTANT : ne JAMAIS masquer .ast-header-break-point,
   c'est une classe ajoutée par Astra sur le <body> en mobile
   (voir astra_body_classes()). La masquer = page noire/vide
   sur mobile, alors que tout fonctionne en mode "Ordinateur". */
.main-header-bar, .ast-above-header-wrap, .ast-below-header-wrap,
.ast-primary-header-bar, .site-header, #masthead, .hfb-header,
.ast-masthead, #ast-fixed-header,
.ast-sticky-active, .hfb-sticky, .ast-page-header,
.entry-header, .post-thumbnail, .ast-breadcrumbs-wrap,
.comments-area, #comments,
#ast-desktop-header, #ast-mobile-header { display: none !important; }

/* Sécurité : si jamais quelque chose tente display:none sur body, on l'annule */
body.km-dashboard-page { display: block !important; visibility: visible !important; opacity: 1 !important; }

/* ── Masquer footer thème (toutes variantes connues) ── */
footer:not([class*="km-"]),
.site-footer, #colophon, #footer, .ast-footer-overlay,
.footer-wrapper, .footer-section, .footer-area, .footer-container,
.footer-widget-area, .footer-bar, .footer-inner,
[data-elementor-type="footer"],
.elementor-location-footer,
.hfe-template-type-footer,
.hfe-footer, .hfe-header,
.wp-block-template-part[class*="footer"],
[class*="footer-builder"],
[id*="footer-builder"] { display: none !important; }

/* ── Reset wrappers ── */
#page, #content, #primary, #main, .site-content, .content-area,
.ast-container, .ast-grid-right-sidebar, .ast-grid-left-sidebar,
.entry-content, .page-content, article, .ast-article-single {
    padding: 0 !important;
    margin: 0 !important;
    max-width: none !important;
    width: 100% !important;
    background: transparent !important;
}

#wpadminbar { display: none !important; }
html { margin-top: 0 !important; }

/* ── Correctif rendu mobile définitif v5.4.5 ── */
/* Sur mobile : supprimer TOUS les pseudo-éléments et fonds décoratifs
   qui créent des couches GPU (position:fixed, radial-gradient lourds).
   Fonds solides = rendu garanti sur tous les navigateurs mobiles. */
@media (max-width: 1024px) {
    .km-db-wrap::before,
    .km-auth-bg,
    .km-auth::before { display: none !important; }
    .km-db-wrap { background: #080d1a !important; }
    .km-auth    { background: #080d1a !important; overflow: visible !important; }
}
</style>
</head>
<body <?php body_class( 'km-dashboard-page' ); ?>>
<?php
if ( have_posts() ) :
    while ( have_posts() ) :
        the_post();
        the_content();
    endwhile;
endif;

wp_footer();
?>
<?php /* Filet de sécurité : supprime via JS tout footer résiduel non capturé par CSS */ ?>
<script>
(function(){
    var sel = [
        'footer:not([class*="km-"])',
        '.site-footer', '#colophon', '#footer',
        '[data-elementor-type="footer"]',
        '.elementor-location-footer',
        '.hfe-template-type-footer'
    ].join(',');
    try {
        document.querySelectorAll(sel).forEach(function(el){ el.style.setProperty('display','none','important'); });
    } catch(e){}
})();
</script>
</body>
</html>
