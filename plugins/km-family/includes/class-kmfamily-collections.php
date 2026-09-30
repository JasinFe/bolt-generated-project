<?php
/**
 * KM Family — Collections & Agenda (LOT 3)
 *
 * COLLECTIONS : « Studio Sessions — 12 contenus », « Backstage — 24 contenus ».
 * Une liste plate de contenus ne raconte rien ; des collections donnent au
 * catalogue l'allure d'une œuvre plutôt que d'un dossier de fichiers.
 *
 * Implémenté en taxonomie plutôt qu'en type de contenu : on hérite gratuitement
 * des pages d'archive, du filtrage dans l'administration et des requêtes
 * WordPress. Chaque collection porte l'identifiant de son artiste en méta de
 * terme, ce qui évite qu'un membre voie les collections d'un artiste qu'il ne
 * suit pas.
 *
 * AGENDA : la programmation existe déjà en base grâce au moteur d'exclusivité.
 * L'afficher ne coûte donc presque rien et donne à la plateforme un rythme
 * visible — « il se passe quelque chose la semaine prochaine ».
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Collections {

    const TAX          = 'km_collection';
    const TERM_ARTISTE = 'km_collection_artiste';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_taxonomy' ), 6 );

        add_action( self::TAX . '_add_form_fields',  array( __CLASS__, 'field_add' ) );
        add_action( self::TAX . '_edit_form_fields', array( __CLASS__, 'field_edit' ) );
        add_action( 'created_' . self::TAX, array( __CLASS__, 'save_term' ) );
        add_action( 'edited_' . self::TAX,  array( __CLASS__, 'save_term' ) );

        add_shortcode( 'kmfamily_collections', array( __CLASS__, 'shortcode_collections' ) );
        add_shortcode( 'kmfamily_agenda',      array( __CLASS__, 'shortcode_agenda' ) );
    }

    public static function register_taxonomy() {
        register_taxonomy( self::TAX, array( 'contenu_exclusif' ), array(
            'labels' => array(
                'name'          => __( 'Collections', 'km-family' ),
                'singular_name' => __( 'Collection', 'km-family' ),
                'add_new_item'  => __( 'Ajouter une collection', 'km-family' ),
                'edit_item'     => __( 'Modifier la collection', 'km-family' ),
                'search_items'  => __( 'Rechercher une collection', 'km-family' ),
            ),
            'hierarchical'      => false,
            'public'            => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => array( 'slug' => 'km-collection' ),
        ) );
    }

    // ==========================================================
    // RATTACHEMENT À UN ARTISTE
    // ==========================================================

    private static function artiste_selector( $selected = 0 ) {
        $artistes = get_posts( array(
            'post_type'      => 'nos-artistes',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $html = '<select name="' . esc_attr( self::TERM_ARTISTE ) . '" id="' . esc_attr( self::TERM_ARTISTE ) . '">';
        $html .= '<option value="0">' . esc_html__( '— Tous les artistes —', 'km-family' ) . '</option>';
        foreach ( $artistes as $a ) {
            $html .= '<option value="' . esc_attr( $a->ID ) . '" ' . selected( $selected, $a->ID, false ) . '>'
                   . esc_html( $a->post_title ) . '</option>';
        }
        return $html . '</select>';
    }

    public static function field_add() {
        ?>
        <div class="form-field">
            <label for="<?php echo esc_attr( self::TERM_ARTISTE ); ?>"><?php esc_html_e( 'Artiste', 'km-family' ); ?></label>
            <?php echo self::artiste_selector( 0 ); ?>
            <p><?php esc_html_e( 'Rattache la collection à un artiste, pour qu\'elle n\'apparaisse que sur son espace.', 'km-family' ); ?></p>
        </div>
        <?php
    }

    public static function field_edit( $term ) {
        $val = (int) get_term_meta( $term->term_id, self::TERM_ARTISTE, true );
        ?>
        <tr class="form-field">
            <th scope="row"><label for="<?php echo esc_attr( self::TERM_ARTISTE ); ?>"><?php esc_html_e( 'Artiste', 'km-family' ); ?></label></th>
            <td>
                <?php echo self::artiste_selector( $val ); ?>
                <p class="description"><?php esc_html_e( 'Rattache la collection à un artiste.', 'km-family' ); ?></p>
            </td>
        </tr>
        <?php
    }

    public static function save_term( $term_id ) {
        if ( ! current_user_can( 'manage_categories' ) ) return;
        if ( ! isset( $_POST[ self::TERM_ARTISTE ] ) ) return;
        update_term_meta( $term_id, self::TERM_ARTISTE, absint( $_POST[ self::TERM_ARTISTE ] ) );
    }

    public static function get_for_artiste( $artiste_id ) {
        $termes = get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => true ) );
        if ( is_wp_error( $termes ) ) return array();

        $artiste_id = absint( $artiste_id );
        return array_values( array_filter( $termes, function ( $t ) use ( $artiste_id ) {
            $a = (int) get_term_meta( $t->term_id, self::TERM_ARTISTE, true );
            return ! $a || $a === $artiste_id;
        } ) );
    }

    // ==========================================================
    // SHORTCODES
    // ==========================================================

    /** [kmfamily_collections artiste_id="123"] */
    public static function shortcode_collections( $atts ) {
        $atts = shortcode_atts( array( 'artiste_id' => 0 ), $atts, 'kmfamily_collections' );

        $artiste_id = absint( $atts['artiste_id'] ) ?: get_queried_object_id();
        if ( ! $artiste_id || get_post_type( $artiste_id ) !== 'nos-artistes' ) return '';

        $collections = self::get_for_artiste( $artiste_id );
        if ( ! $collections ) return '';

        wp_enqueue_style( 'kmfamily-hub' );

        ob_start(); ?>
        <div class="kmh-collections">
            <h3 class="kmh-title"><?php esc_html_e( 'Collections', 'km-family' ); ?></h3>
            <div class="kmh-grid">
            <?php foreach ( $collections as $c ) : ?>
                <a class="kmh-collection" href="<?php echo esc_url( get_term_link( $c ) ); ?>">
                    <span class="kmh-collection-nom"><?php echo esc_html( $c->name ); ?></span>
                    <span class="kmh-collection-nb">
                        <?php printf( esc_html( _n( '%d contenu', '%d contenus', $c->count, 'km-family' ) ), $c->count ); ?>
                    </span>
                    <?php if ( $c->description ) : ?>
                        <span class="kmh-collection-desc"><?php echo esc_html( wp_trim_words( $c->description, 14 ) ); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [kmfamily_agenda jours="30" artiste_id="0"]
     *
     * Uniquement ce qui est à venir : premières pas encore sorties et drops
     * pas encore ouverts. Un agenda rempli d'événements passés perd tout son
     * intérêt en deux semaines.
     */
    public static function shortcode_agenda( $atts ) {
        $atts = shortcode_atts( array( 'jours' => 30, 'artiste_id' => 0, 'limit' => 12 ), $atts, 'kmfamily_agenda' );

        $now    = current_time( 'timestamp' );
        $limite = $now + ( max( 1, (int) $atts['jours'] ) * DAY_IN_SECONDS );

        $args = array(
            'post_type'      => 'contenu_exclusif',
            'posts_per_page' => 100,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'     => KMFamily_Availability::META_MODE,
                    'value'   => array( 'premiere', 'drop' ),
                    'compare' => 'IN',
                ),
            ),
        );
        if ( absint( $atts['artiste_id'] ) ) {
            $args['meta_query'][] = array( 'key' => 'artiste_lie', 'value' => absint( $atts['artiste_id'] ) );
        }

        $evenements = array();
        foreach ( get_posts( $args ) as $p ) {
            $d = KMFamily_Availability::resolve( $p->ID, $now );

            $quand = null;
            $type  = '';

            if ( $d['mode'] === 'premiere' && ! empty( $d['public_at'] ) ) {
                // On annonce la première étape à venir si elle existe, sinon la
                // sortie publique — c'est l'échéance qui intéresse le membre.
                $quand = ( ! empty( $d['next_at'] ) && $d['next_at'] > $now ) ? $d['next_at'] : $d['public_at'];
                $type  = ( $quand < $d['public_at'] ) ? __( 'Avant-première', 'km-family' ) : __( 'Sortie', 'km-family' );
            } elseif ( $d['mode'] === 'drop' && $d['state'] === 'a_venir' ) {
                $quand = $d['next_at'];
                $type  = __( 'Drop', 'km-family' );
            }

            if ( ! $quand || $quand <= $now || $quand > $limite ) continue;

            $evenements[] = array(
                'post'   => $p,
                'quand'  => $quand,
                'type'   => $type,
                'palier' => $d['next_palier'] ?: $d['palier'],
            );
        }

        if ( ! $evenements ) return '';

        usort( $evenements, function ( $a, $b ) { return $a['quand'] <=> $b['quand']; } );
        $evenements = array_slice( $evenements, 0, max( 1, (int) $atts['limit'] ) );

        wp_enqueue_style( 'kmfamily-hub' );

        ob_start(); ?>
        <div class="kmh-agenda">
            <h3 class="kmh-title"><?php esc_html_e( 'Agenda KM Family', 'km-family' ); ?></h3>
            <ul class="kmh-agenda-liste">
            <?php foreach ( $evenements as $e ) :
                $artiste = get_field( 'artiste_lie', $e['post']->ID );
                $nom_artiste = $artiste ? get_the_title( is_object( $artiste ) ? $artiste->ID : $artiste ) : '';
                $palier_nom = ( $e['palier'] && class_exists( 'KMFamily_Paliers' ) && KMFamily_Paliers::get( $e['palier'] ) )
                    ? KMFamily_Paliers::get( $e['palier'] )['nom'] : strtoupper( (string) $e['palier'] );
            ?>
                <li class="kmh-agenda-item">
                    <span class="kmh-agenda-date">
                        <strong><?php echo esc_html( date_i18n( 'd', $e['quand'] ) ); ?></strong>
                        <small><?php echo esc_html( date_i18n( 'M', $e['quand'] ) ); ?></small>
                    </span>
                    <span class="kmh-agenda-main">
                        <span class="kmh-agenda-titre"><?php echo esc_html( get_the_title( $e['post'] ) ); ?></span>
                        <span class="kmh-agenda-sub">
                            <?php echo esc_html( $e['type'] ); ?>
                            <?php if ( $nom_artiste ) : ?> · <?php echo esc_html( $nom_artiste ); ?><?php endif; ?>
                            <?php if ( $palier_nom && $e['palier'] !== 'public' ) : ?> · <?php echo esc_html( $palier_nom ); ?><?php endif; ?>
                        </span>
                    </span>
                    <span class="kmh-agenda-compte"><?php echo esc_html( KMFamily_Availability::countdown( $e['quand'] - $now ) ); ?></span>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }
}
