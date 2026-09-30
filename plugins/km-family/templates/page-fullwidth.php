<?php
/**
 * Template Name: KM Family — Pleine largeur
 *
 * Template de page sans sidebar ni contrainte de largeur,
 * conçu pour les pages utilisant les shortcodes KM Family.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();
?>

<main id="kmfamily-main" class="kmfamily-fullwidth-main" style="padding:0;margin:0;width:100%;max-width:100%;">
    <?php
    while ( have_posts() ) :
        the_post();
        the_content();
    endwhile;
    ?>
</main>

<?php get_footer(); ?>
