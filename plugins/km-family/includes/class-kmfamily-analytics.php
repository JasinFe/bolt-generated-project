<?php
/**
 * KM Family — Analytique (LOT 7)
 *
 * Ce module N'ENREGISTRE RIEN. Il ne crée aucune table, ne pose aucun mouchard,
 * n'ajoute aucune écriture sur le chemin critique. Il se contente de lire ce que
 * les lots précédents produisent déjà :
 *
 *   adhésions, churn, ARPU  → KMFamily_Memberships          (LOT 0)
 *   revenus et partage      → KMFamily_Revenue              (pont v2.5.0)
 *   écoutes et complétion   → KMFamily_Playback             (LOT 2)
 *   points et niveaux       → KMFamily_Loyalty_Plus         (LOT 6)
 *
 * C'est délibéré, et c'est ce qui rend ce lot peu risqué : une erreur ici
 * produit un chiffre faux sur un écran, jamais une donnée corrompue ni un
 * paiement perdu.
 *
 * TROIS RÈGLES DE LECTURE :
 *  - un indicateur qu'on ne sait pas calculer honnêtement n'est pas affiché,
 *    plutôt qu'affiché à zéro — un zéro se confond avec une vraie absence ;
 *  - aucune moyenne n'est présentée sans son effectif, parce qu'un taux de
 *    complétion de 100 % sur deux auditeurs ne veut rien dire ;
 *  - l'artiste ne voit jamais que ses propres chiffres.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Analytics {

    /** En dessous de cet effectif, une moyenne est signalée comme non significative. */
    const SEUIL_SIGNIFICATIF = 10;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 18 );
        add_action( 'admin_post_kmfamily_export_csv', array( __CLASS__, 'handle_export' ) );
        add_shortcode( 'kmfamily_analytics_artiste', array( __CLASS__, 'shortcode_artiste' ) );
    }

    // ==========================================================
    // DISPONIBILITÉ DES SOURCES
    // ==========================================================

    public static function socle_pret() {
        return class_exists( 'KMFamily_Memberships' ) && KMFamily_Memberships::is_ready();
    }

    /**
     * La présence de la classe ne suffit pas : si l'extension a été mise à jour
     * par FTP sans réactivation, le code est là mais la table peut manquer, et
     * l'écran d'analytique afficherait alors des erreurs SQL au lieu de dire
     * simplement qu'il n'y a rien à montrer. On vérifie donc la table, une
     * seule fois par requête.
     */
    public static function playback_pret() {
        static $pret = null;
        if ( $pret !== null ) return $pret;

        if ( ! class_exists( 'KMFamily_Playback' ) ) return ( $pret = false );

        global $wpdb;
        $t = KMFamily_Playback::table_name();
        $pret = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t );

        return $pret;
    }

    // ==========================================================
    // VUE LABEL
    // ==========================================================

    /**
     * @param int $jours Fenêtre d'observation.
     */
    public static function label( $jours = 30 ) {
        $out = array(
            'jours'       => (int) $jours,
            'adhesions'   => null,
            'revenus'     => null,
            'contenus'    => null,
            'fidelite'    => null,
        );

        if ( self::socle_pret() ) {
            $out['adhesions'] = KMFamily_Memberships::get_stats( array( 'days' => $jours ) );
        }

        if ( class_exists( 'KMFamily_Revenue' ) ) {
            $out['revenus'] = KMFamily_Revenue::get_label_summary();
        }

        if ( self::playback_pret() ) {
            $out['contenus'] = self::contenus_label( $jours );
        }

        if ( class_exists( 'KMFamily_Loyalty_Plus' ) ) {
            $out['fidelite'] = KMFamily_Loyalty_Plus::engagement_financier();
        }

        return $out;
    }

    private static function contenus_label( $jours ) {
        global $wpdb;
        $t = KMFamily_Playback::table_name();

        $depuis = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( (int) $jours * DAY_IN_SECONDS ) );

        // La jointure sur les posts publiés est INDISPENSABLE ici : sans elle,
        // la synthèse compte les contenus dépubliés alors que le tableau
        // détaillé juste en dessous, lui, les exclut. Deux chiffres qui se
        // contredisent sur le même écran valent moins que pas de chiffre.
        $r = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.user_id) AS auditeurs,
                    COALESCE(SUM(p.plays),0)  AS lectures,
                    COALESCE(SUM(p.favorite),0) AS favoris,
                    COUNT(DISTINCT p.content_id) AS contenus
             FROM {$t} p
             INNER JOIN {$wpdb->posts} w ON w.ID = p.content_id AND w.post_status = 'publish'
             WHERE p.last_played >= %s", $depuis
        ), ARRAY_A );

        return array(
            'auditeurs' => (int) ( $r['auditeurs'] ?? 0 ),
            'lectures'  => (int) ( $r['lectures'] ?? 0 ),
            'favoris'   => (int) ( $r['favoris'] ?? 0 ),
            'contenus'  => (int) ( $r['contenus'] ?? 0 ),
        );
    }

    /**
     * Contenus les plus écoutés. `artiste_id` est renseigné dans la table de
     * lecture au moment de l'écriture, ce qui évite une jointure sur les métas
     * de post — et rend le filtrage par artiste indexé.
     */
    public static function top_contenus( $artiste_id = 0, $limit = 10, $jours = 0 ) {
        if ( ! self::playback_pret() ) return array();

        global $wpdb;
        $t = KMFamily_Playback::table_name();

        $where = array( '1=1' );
        if ( $artiste_id ) $where[] = $wpdb->prepare( 'p.artiste_id = %d', absint( $artiste_id ) );
        if ( $jours ) {
            $where[] = $wpdb->prepare( 'p.last_played >= %s',
                gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( (int) $jours * DAY_IN_SECONDS ) ) );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.content_id,
                    COUNT(DISTINCT p.user_id) AS auditeurs,
                    COALESCE(SUM(p.plays),0)  AS lectures,
                    COALESCE(SUM(p.favorite),0) AS favoris,
                    COALESCE(AVG(p.max_progress),0) AS progression,
                    COALESCE(SUM(p.completed),0) AS termines
             FROM {$t} p
             INNER JOIN {$wpdb->posts} w ON w.ID = p.content_id AND w.post_status = 'publish'
             WHERE " . implode( ' AND ', $where ) . "
             GROUP BY p.content_id
             ORDER BY lectures DESC, auditeurs DESC
             LIMIT %d", max( 1, min( 100, (int) $limit ) )
        ), ARRAY_A );

        $out = array();
        foreach ( (array) $rows as $r ) {
            $auditeurs = (int) $r['auditeurs'];
            $out[] = array(
                'content_id'   => (int) $r['content_id'],
                'titre'        => get_the_title( $r['content_id'] ),
                'auditeurs'    => $auditeurs,
                'lectures'     => (int) $r['lectures'],
                'favoris'      => (int) $r['favoris'],
                'progression'  => (int) round( (float) $r['progression'] ),
                'completion'   => $auditeurs > 0 ? (int) round( (int) $r['termines'] / $auditeurs * 100 ) : 0,
                'significatif' => $auditeurs >= self::SEUIL_SIGNIFICATIF,
            );
        }

        return $out;
    }

    // ==========================================================
    // VUE ARTISTE
    // ==========================================================

    public static function artiste( $artiste_id, $jours = 30 ) {
        $artiste_id = absint( $artiste_id );

        $out = array(
            'artiste_id'   => $artiste_id,
            'nom'          => get_the_title( $artiste_id ),
            'jours'        => (int) $jours,
            'adhesions'    => null,
            'revenus'      => null,
            'top_contenus' => array(),
            'palier_dominant' => null,
        );

        if ( ! $artiste_id ) return $out;

        if ( self::socle_pret() ) {
            $stats = KMFamily_Memberships::get_stats( array( 'artiste_id' => $artiste_id, 'days' => $jours ) );
            $out['adhesions'] = $stats;

            if ( ! empty( $stats['par_palier'] ) ) {
                $dominant = $stats['par_palier'][0];
                $out['palier_dominant'] = array(
                    'palier' => $dominant['palier'],
                    'nb'     => (int) $dominant['nb'],
                    'part'   => $stats['actifs'] > 0 ? (int) round( (int) $dominant['nb'] / $stats['actifs'] * 100 ) : 0,
                );
            }
        }

        if ( class_exists( 'KMFamily_Revenue' ) ) {
            $out['revenus'] = KMFamily_Revenue::get_artist_summary( $artiste_id );
        }

        $out['top_contenus'] = self::top_contenus( $artiste_id, 10 );

        return $out;
    }

    /** Shortcode artiste : ses contenus, et rien d'autre. */
    public static function shortcode_artiste( $atts ) {
        if ( ! is_user_logged_in() ) return '';

        $atts = shortcode_atts( array( 'artiste_id' => 0, 'limit' => 10 ), $atts, 'kmfamily_analytics_artiste' );

        $artiste_id = absint( $atts['artiste_id'] );
        if ( ! $artiste_id && class_exists( 'KMFamily_Revenue' ) ) {
            $artiste_id = KMFamily_Revenue::get_artiste_id_by_user( get_current_user_id() );
        }
        if ( ! $artiste_id ) return '';

        // Un artiste ne consulte que sa propre fiche ; l'administrateur voit tout.
        if ( ! current_user_can( 'manage_options' ) && class_exists( 'KMFamily_Revenue' ) ) {
            if ( KMFamily_Revenue::get_artiste_id_by_user( get_current_user_id() ) !== $artiste_id ) return '';
        }

        $top = self::top_contenus( $artiste_id, absint( $atts['limit'] ) );
        if ( ! $top ) return '';

        wp_enqueue_style( 'kmfamily-hub' );

        ob_start(); ?>
<div class="kma-bloc">
    <div class="kmh-title"><?php esc_html_e( 'Mes contenus les plus écoutés', 'km-family' ); ?></div>
    <div class="kmh-table-wrap">
        <table class="kma-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Contenu', 'km-family' ); ?></th>
                    <th class="kma-num"><?php esc_html_e( 'Auditeurs', 'km-family' ); ?></th>
                    <th class="kma-num"><?php esc_html_e( 'Écoutes', 'km-family' ); ?></th>
                    <th class="kma-num">❤</th>
                    <th class="kma-num"><?php esc_html_e( 'Écouté en moyenne', 'km-family' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $top as $c ) : ?>
                <tr>
                    <td><?php echo esc_html( $c['titre'] ); ?></td>
                    <td class="kma-num"><?php echo esc_html( $c['auditeurs'] ); ?></td>
                    <td class="kma-num"><?php echo esc_html( $c['lectures'] ); ?></td>
                    <td class="kma-num"><?php echo esc_html( $c['favoris'] ); ?></td>
                    <td class="kma-num">
                        <?php if ( $c['significatif'] ) : ?>
                            <?php echo esc_html( $c['progression'] ); ?> %
                        <?php else : ?>
                            <span class="kma-faible" title="<?php esc_attr_e( 'Trop peu d\'auditeurs pour que cette moyenne veuille dire quelque chose', 'km-family' ); ?>">
                                <?php echo esc_html( $c['progression'] ); ?> %*
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="kmh-hint">
        <?php printf(
            esc_html__( '* Moins de %d auditeurs : la moyenne n\'est pas encore représentative.', 'km-family' ),
            self::SEUIL_SIGNIFICATIF
        ); ?>
    </p>
</div>
        <?php
        return ob_get_clean();
    }

    // ==========================================================
    // EXPORT
    // ==========================================================

    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'kmfamily_export_csv', 'kmfamily_export_nonce' ) ) {
            wp_die( esc_html__( 'Accès refusé', 'km-family' ) );
        }

        $quoi  = sanitize_key( $_POST['quoi'] ?? 'contenus' );
        $jours = max( 1, min( 730, absint( $_POST['jours'] ?? 30 ) ) );

        $nom = 'km-family-' . $quoi . '-' . gmdate( 'Y-m-d' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $nom . '"' );

        $sortie = fopen( 'php://output', 'w' );

        // BOM UTF-8 : sans lui, Excel affiche « Écoutes » en « Ãcoutes » sur
        // une machine configurée en français. Détail, mais c'est la première
        // chose que l'on voit en ouvrant le fichier.
        fwrite( $sortie, "\xEF\xBB\xBF" );

        if ( $quoi === 'artistes' ) {
            fputcsv( $sortie, array( 'Artiste', 'Membres actifs', 'Collecté brut', 'Part artiste', 'Part label', 'Taux' ), ';' );

            if ( class_exists( 'KMFamily_Revenue' ) ) {
                foreach ( KMFamily_Revenue::get_label_summary()['artistes'] as $a ) {
                    fputcsv( $sortie, array(
                        $a['nom'], $a['actifs'], $a['brut'], $a['artiste'], $a['label'], $a['split_pct'],
                    ), ';' );
                }
            }
        } else {
            fputcsv( $sortie, array( 'Contenu', 'Auditeurs', 'Écoutes', 'Favoris', 'Progression moyenne %', 'Complétion %', 'Significatif' ), ';' );

            foreach ( self::top_contenus( 0, 100, $jours ) as $c ) {
                fputcsv( $sortie, array(
                    $c['titre'], $c['auditeurs'], $c['lectures'], $c['favoris'],
                    $c['progression'], $c['completion'], $c['significatif'] ? 'oui' : 'non',
                ), ';' );
            }
        }

        fclose( $sortie );
        exit;
    }

    // ==========================================================
    // ADMIN
    // ==========================================================

    public static function register_menu() {
        add_submenu_page(
            'km-family',
            __( 'Analytique', 'km-family' ),
            __( 'Analytique', 'km-family' ),
            'manage_options',
            'kmfamily-analytique',
            array( __CLASS__, 'render_page' )
        );
    }

    private static function fcfa( $n ) {
        return number_format( (int) $n, 0, ',', ' ' ) . ' FCFA';
    }

    public static function render_page() {
        $jours = isset( $_GET['jours'] ) ? max( 1, min( 730, absint( $_GET['jours'] ) ) ) : 30;
        $d     = self::label( $jours );
        ?>
        <div class="wrap kmfamily-admin-wrap">
            <h1><?php esc_html_e( 'KM Family — Analytique', 'km-family' ); ?></h1>

            <p>
                <?php esc_html_e( 'Période :', 'km-family' ); ?>
                <?php foreach ( array( 7 => '7 j', 30 => '30 j', 90 => '90 j', 365 => '1 an' ) as $j => $lib ) : ?>
                    <a class="button <?php echo $jours === $j ? 'button-primary' : ''; ?>"
                       href="<?php echo esc_url( admin_url( 'admin.php?page=kmfamily-analytique&jours=' . $j ) ); ?>"><?php echo esc_html( $lib ); ?></a>
                <?php endforeach; ?>
            </p>

            <?php if ( ! self::socle_pret() ) : ?>
                <div class="notice notice-warning inline" style="max-width:820px"><p>
                    <?php esc_html_e( 'Le socle des adhésions n\'a pas encore été repris : les indicateurs d\'adhésion, de churn et de contribution moyenne ne peuvent pas être calculés. Lancez la reprise depuis « Socle & Migration ».', 'km-family' ); ?>
                </p></div>
            <?php endif; ?>

            <?php if ( $d['adhesions'] ) : $a = $d['adhesions']; ?>
            <h2><?php esc_html_e( 'Adhésions', 'km-family' ); ?></h2>
            <table class="widefat striped" style="max-width:720px"><tbody>
                <tr><td><?php esc_html_e( 'Membres actifs', 'km-family' ); ?></td><td><strong><?php echo esc_html( $a['actifs'] ); ?></strong></td></tr>
                <tr><td><?php esc_html_e( 'Nouveaux sur la période', 'km-family' ); ?></td><td><?php echo esc_html( $a['nouveaux'] ); ?></td></tr>
                <tr><td><?php esc_html_e( 'Renouvellements', 'km-family' ); ?></td><td><?php echo esc_html( $a['renouvelles'] ); ?></td></tr>
                <tr><td><?php esc_html_e( 'Perdus', 'km-family' ); ?></td><td><?php echo esc_html( $a['perdus'] ); ?></td></tr>
                <tr><td><?php esc_html_e( 'Taux d\'attrition', 'km-family' ); ?></td><td><strong><?php echo esc_html( $a['churn_pct'] ); ?> %</strong></td></tr>
                <tr><td><?php esc_html_e( 'Contribution moyenne par membre', 'km-family' ); ?></td><td><?php echo esc_html( self::fcfa( $a['arpu'] ) ); ?></td></tr>
                <tr style="background:#fff8e5"><td><strong><?php esc_html_e( 'Échéances dans les 7 jours', 'km-family' ); ?></strong></td><td><strong><?php echo esc_html( $a['expirent_7j'] ); ?></strong></td></tr>
            </tbody></table>
            <p class="description" style="max-width:820px">
                <?php esc_html_e( 'La dernière ligne est la plus actionnable de cet écran : ce sont les membres que vous pouvez encore retenir cette semaine. Les relances automatiques s\'en chargent, mais un message personnel du label sur un membre de longue date vaut mieux qu\'un email de plus.', 'km-family' ); ?>
            </p>
            <?php endif; ?>

            <?php if ( $d['revenus'] && ! empty( $d['revenus']['artistes'] ) ) : $r = $d['revenus']; ?>
            <h2><?php esc_html_e( 'Revenus KM Family', 'km-family' ); ?></h2>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Artiste', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Membres actifs', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Collecté brut', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Part artiste', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Part label', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Taux', 'km-family' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $r['artistes'] as $a ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $a['nom'] ); ?></strong></td>
                        <td><?php echo esc_html( $a['actifs'] ); ?></td>
                        <td><?php echo esc_html( self::fcfa( $a['brut'] ) ); ?></td>
                        <td><?php echo esc_html( self::fcfa( $a['artiste'] ) ); ?></td>
                        <td><?php echo esc_html( self::fcfa( $a['label'] ) ); ?></td>
                        <td><?php echo esc_html( $a['split_pct'] ); ?> %</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <td><strong><?php esc_html_e( 'TOTAL', 'km-family' ); ?></strong></td>
                    <td>—</td>
                    <td><strong><?php echo esc_html( self::fcfa( $r['brut'] ) ); ?></strong></td>
                    <td><strong><?php echo esc_html( self::fcfa( $r['artiste'] ) ); ?></strong></td>
                    <td><strong><?php echo esc_html( self::fcfa( $r['label'] ) ); ?></strong></td>
                    <td>—</td>
                </tr></tfoot>
            </table>
            <?php endif; ?>

            <?php if ( $d['contenus'] ) : $c = $d['contenus']; ?>
            <h2><?php esc_html_e( 'Usage des contenus', 'km-family' ); ?></h2>
            <p>
                <?php printf(
                    esc_html__( '%1$d membre(s) ont ouvert %2$d contenu(s) sur la période, pour %3$d écoute(s) comptabilisée(s) et %4$d favori(s).', 'km-family' ),
                    $c['auditeurs'], $c['contenus'], $c['lectures'], $c['favoris']
                ); ?>
            </p>
            <p class="description" style="max-width:820px">
                <?php esc_html_e( 'Une écoute n\'est comptabilisée qu\'au-delà de 60 % du contenu, et au plus une fois par membre et par 24 heures. Ces chiffres sont donc plus bas — et plus fiables — qu\'un simple compteur de clics sur « lecture ».', 'km-family' ); ?>
            </p>

            <?php $top = self::top_contenus( 0, 15, $jours ); if ( $top ) : ?>
            <h3><?php esc_html_e( 'Contenus les plus écoutés', 'km-family' ); ?></h3>
            <table class="widefat striped">
                <thead><tr>
                    <th><?php esc_html_e( 'Contenu', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Auditeurs', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Écoutes', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Favoris', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Écouté en moyenne', 'km-family' ); ?></th>
                    <th><?php esc_html_e( 'Terminé par', 'km-family' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $top as $t ) : ?>
                    <tr>
                        <td><?php echo esc_html( $t['titre'] ); ?></td>
                        <td><?php echo esc_html( $t['auditeurs'] ); ?></td>
                        <td><?php echo esc_html( $t['lectures'] ); ?></td>
                        <td><?php echo esc_html( $t['favoris'] ); ?></td>
                        <td><?php echo esc_html( $t['progression'] ); ?> %<?php echo $t['significatif'] ? '' : ' <span style="opacity:.5">*</span>'; ?></td>
                        <td><?php echo esc_html( $t['completion'] ); ?> %<?php echo $t['significatif'] ? '' : ' <span style="opacity:.5">*</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">
                <?php printf( esc_html__( '* Moins de %d auditeurs : moyenne non représentative.', 'km-family' ), self::SEUIL_SIGNIFICATIF ); ?>
            </p>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ( $d['fidelite'] && $d['fidelite']['circulation'] > 0 ) : $f = $d['fidelite']; ?>
            <h2><?php esc_html_e( 'Fidélité', 'km-family' ); ?></h2>
            <p>
                <?php printf(
                    esc_html__( '%1$s points en circulation chez %2$d membre(s).', 'km-family' ),
                    '<strong>' . esc_html( number_format( $f['circulation'], 0, ',', ' ' ) ) . '</strong>',
                    $f['membres']
                ); ?>
                <?php if ( $f['convertible'] ) : ?>
                    <?php printf( esc_html__( 'Obligation estimée : %s.', 'km-family' ), '<strong>' . esc_html( self::fcfa( $f['provision'] ) ) . '</strong>' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Aucune récompense active : ces points ne sont convertibles en rien.', 'km-family' ); ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>

            <hr />

            <h2><?php esc_html_e( 'Export', 'km-family' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="kmfamily_export_csv" />
                <input type="hidden" name="jours" value="<?php echo esc_attr( $jours ); ?>" />
                <?php wp_nonce_field( 'kmfamily_export_csv', 'kmfamily_export_nonce' ); ?>
                <select name="quoi">
                    <option value="contenus"><?php esc_html_e( 'Usage des contenus', 'km-family' ); ?></option>
                    <option value="artistes"><?php esc_html_e( 'Revenus par artiste', 'km-family' ); ?></option>
                </select>
                <button class="button button-primary"><?php esc_html_e( 'Télécharger le CSV', 'km-family' ); ?></button>
                <p class="description"><?php esc_html_e( 'Séparateur point-virgule et encodage UTF-8 avec indicateur d\'ordre : le fichier s\'ouvre directement dans Excel en français, sans caractères abîmés.', 'km-family' ); ?></p>
            </form>
        </div>
        <?php
    }
}
