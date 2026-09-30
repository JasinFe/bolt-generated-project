<?php
/**
 * ============================================================
 * KOPHI'S MUSIC — Pont « KM FAMILY » ↔ Tableau de bord artiste
 * ============================================================
 *
 * Affiche, dans l'espace de chaque artiste, les revenus issus des soutiens
 * KM Family, à côté des revenus de streaming :
 *   - combien de personnes le soutiennent,
 *   - combien cela représente (brut collecté),
 *   - ce qui lui revient selon le taux de partage KM Family,
 *   - ce qui revient au label.
 *
 * Le calcul n'est JAMAIS refait ici : ce fichier ne fait que lire l'API
 * KMFamily_Revenue (extension KM Family), qui est la source de vérité et qui
 * fige le taux appliqué au moment de chaque encaissement. Une seule source,
 * donc aucun risque de voir deux chiffres différents des deux côtés.
 *
 * DÉPENDANCE OPTIONNELLE : si KM Family est désactivé, absent, ou dans une
 * version antérieure au pont, toutes les fonctions ci-dessous retournent une
 * chaîne vide et le tableau de bord s'affiche exactement comme avant.
 */
defined( 'ABSPATH' ) || exit;

// ══════════════════════════════════════════════════════════════
// DISPONIBILITÉ
// ══════════════════════════════════════════════════════════════
if ( ! function_exists( 'kmfb_available' ) ) {
    function kmfb_available() {
        return class_exists( 'KMFamily_Revenue' ) && method_exists( 'KMFamily_Revenue', 'get_artist_summary' );
    }
}

if ( ! function_exists( 'kmfb_fcfa' ) ) {
    function kmfb_fcfa( $n ) {
        return number_format( (int) $n, 0, ',', ' ' ) . ' F CFA';
    }
}

/** Équivalent EUR indicatif, pour rester cohérent avec l'affichage streaming. */
if ( ! function_exists( 'kmfb_eur' ) ) {
    function kmfb_eur( $xof ) {
        $taux = defined( 'KM_EUR_TO_XOF' ) ? KM_EUR_TO_XOF : 655.957;
        return number_format( $xof / max( 1, $taux ), 2, ',', ' ' ) . ' €';
    }
}

if ( ! function_exists( 'kmfb_palier_label' ) ) {
    function kmfb_palier_label( $slug ) {
        if ( class_exists( 'KMFamily_Paliers' ) ) {
            $p = KMFamily_Paliers::get( $slug );
            if ( $p ) return $p['icon'] . ' ' . $p['nom'];
        }
        return ucfirst( (string) $slug );
    }
}

if ( ! function_exists( 'kmfb_palier_color' ) ) {
    function kmfb_palier_color( $slug ) {
        if ( class_exists( 'KMFamily_Paliers' ) ) return KMFamily_Paliers::get_color( $slug );
        return '#F5A623';
    }
}

if ( ! function_exists( 'kmfb_periodicite_label' ) ) {
    function kmfb_periodicite_label( $slug ) {
        $map = array(
            'monthly'   => 'Mensuel',
            'quarterly' => 'Trimestriel',
            'biannual'  => 'Semestriel',
            'annual'    => 'Annuel',
            'once'      => 'Ponctuel',
        );
        return $map[ $slug ] ?? ucfirst( (string) $slug );
    }
}

/** « 2026-03 » → « mars 2026 ». */
if ( ! function_exists( 'kmfb_mois_label' ) ) {
    function kmfb_mois_label( $ym ) {
        $mois = array( 1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',
                       7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre' );
        $parts = explode( '-', (string) $ym );
        if ( count( $parts ) < 2 ) return (string) $ym;
        $m = intval( $parts[1] );
        return ( $mois[ $m ] ?? $parts[1] ) . ' ' . $parts[0];
    }
}

// ══════════════════════════════════════════════════════════════
// ASSETS
// ══════════════════════════════════════════════════════════════
add_action( 'wp_enqueue_scripts', 'kmfb_enqueue_assets', 20 );
function kmfb_enqueue_assets() {
    if ( ! function_exists( 'km_is_dashboard_page' ) || ! km_is_dashboard_page() ) return;
    if ( ! kmfb_available() ) return;

    wp_enqueue_style(
        'km-family-bridge',
        KM_PLUGIN_URL . 'assets/km-family-bridge.css',
        array( 'km-dashboard' ),
        KM_VERSION
    );
}

// ══════════════════════════════════════════════════════════════
// CARTE KPI — insérée dans la grille du tableau de bord artiste
// ══════════════════════════════════════════════════════════════
add_action( 'km_artist_dashboard_kpi_cards', 'kmfb_render_kpi_card', 10, 1 );
function kmfb_render_kpi_card( $user_id ) {
    echo kmfb_get_kpi_card( $user_id );
}

if ( ! function_exists( 'kmfb_get_kpi_card' ) ) {
    function kmfb_get_kpi_card( $user_id ) {
        if ( ! kmfb_available() ) return '';

        $artiste_id = KMFamily_Revenue::get_artiste_id_by_user( $user_id );
        if ( ! $artiste_id ) return '';

        $s = KMFamily_Revenue::get_artist_summary( $artiste_id, array( 'limit_recent' => 1 ) );

        ob_start(); ?>
        <div class="km-kpi-card kmfb-kpi">
            <div class="km-kpi-icon-wrap kmfb-icon">
                <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
            </div>
            <div class="km-kpi-body">
                <span class="km-kpi-label">KM Family — Ma part</span>
                <span class="km-kpi-value"><?php echo esc_html( number_format( $s['artiste'], 0, ',', ' ' ) ); ?><small> F CFA</small></span>
                <span class="km-kpi-xof"><?php echo esc_html( kmfb_eur( $s['artiste'] ) ); ?></span>
                <span class="km-kpi-sub">
                    <?php echo esc_html( $s['supporters_actifs'] ); ?> soutien(s) actif(s) · part <?php echo esc_html( $s['split_pct'] ); ?>%
                </span>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// ══════════════════════════════════════════════════════════════
// SECTION COMPLÈTE — espace artiste
// ══════════════════════════════════════════════════════════════
add_action( 'km_artist_dashboard_after_kpi', 'kmfb_render_artist_section', 10, 1 );
function kmfb_render_artist_section( $user_id ) {
    echo kmfb_get_artist_section( $user_id );
}

/** Shortcode autonome, si la section doit être placée ailleurs. */
add_shortcode( 'km_family_revenus', 'kmfb_shortcode' );
function kmfb_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) return '';
    $atts = shortcode_atts( array( 'user_id' => 0 ), $atts, 'km_family_revenus' );
    $uid  = get_current_user_id();
    // SÉCURITÉ : l'attribut user_id permettait à tout auteur pouvant insérer un
    // shortcode (ou à une page mal configurée) d'afficher les revenus d'un AUTRE
    // artiste. Seul le personnel du label peut consulter un autre compte.
    $asked = absint( $atts['user_id'] );
    if ( $asked && $asked !== $uid && function_exists( 'km_user_is_label_staff' ) && km_user_is_label_staff() ) {
        $uid = $asked;
    }
    return kmfb_get_artist_section( $uid );
}

if ( ! function_exists( 'kmfb_get_artist_section' ) ) {
    function kmfb_get_artist_section( $user_id ) {
        if ( ! kmfb_available() ) return '';

        $artiste_id = KMFamily_Revenue::get_artiste_id_by_user( $user_id );
        if ( ! $artiste_id ) return '';

        $s = KMFamily_Revenue::get_artist_summary( $artiste_id, array( 'limit_recent' => 12 ) );

        $pct_artiste = (float) $s['split_pct'];
        $pct_label   = round( 100 - $pct_artiste, 2 );

        // Échelle des barres mensuelles : proportionnelle au meilleur mois, pour
        // que la lecture reste utile même sur de petits montants.
        $max_mois = 0;
        foreach ( $s['by_month'] as $m ) $max_mois = max( $max_mois, $m['brut'] );

        ob_start(); ?>
<div class="km-section kmfb-section" id="kmfb-section">

    <div class="km-section-header">
        <div>
            <h3 class="km-section-title">
                <span class="kmfb-heart">♥</span> KM FAMILY — Mes soutiens
            </h3>
            <span class="km-section-sub">
                Revenus communautaires, en plus des revenus de streaming
            </span>
        </div>
        <div class="kmfb-split-badge" title="Taux de partage propre à KM Family, distinct de votre contrat de distribution">
            <span class="kmfb-split-me"><?php echo esc_html( $pct_artiste ); ?>%</span>
            <span class="kmfb-split-sep">/</span>
            <span class="kmfb-split-label"><?php echo esc_html( $pct_label ); ?>%</span>
            <small>moi / label</small>
        </div>
    </div>

    <?php if ( ! $s['has_data'] && ! $s['supporters_actifs'] ) : ?>
        <div class="kmfb-empty">
            <span class="kmfb-empty-icon">♥</span>
            <p>Aucun soutien enregistré pour le moment.</p>
            <small>Dès qu'un membre KM Family vous soutient, le montant et sa répartition apparaissent ici automatiquement.</small>
        </div>
    <?php else : ?>

    <!-- ══ CHIFFRES CLÉS ══ -->
    <div class="kmfb-grid">

        <div class="kmfb-card kmfb-card--people">
            <span class="kmfb-card-label">Personnes qui me soutiennent</span>
            <span class="kmfb-card-value"><?php echo esc_html( $s['supporters_actifs'] ); ?></span>
            <span class="kmfb-card-sub">
                <?php echo esc_html( $s['supporters_uniques'] ); ?> membre(s) au total depuis le début
            </span>
        </div>

        <div class="kmfb-card kmfb-card--gross">
            <span class="kmfb-card-label">Collecté en mon nom (brut)</span>
            <span class="kmfb-card-value"><?php echo esc_html( number_format( $s['brut'], 0, ',', ' ' ) ); ?><small>F CFA</small></span>
            <span class="kmfb-card-sub"><?php echo esc_html( $s['transactions'] ); ?> soutien(s) encaissé(s)</span>
        </div>

        <div class="kmfb-card kmfb-card--mine">
            <span class="kmfb-card-label">Ma part KM Family</span>
            <span class="kmfb-card-value"><?php echo esc_html( number_format( $s['artiste'], 0, ',', ' ' ) ); ?><small>F CFA</small></span>
            <span class="kmfb-card-sub"><?php echo esc_html( kmfb_eur( $s['artiste'] ) ); ?> · taux <?php echo esc_html( $pct_artiste ); ?>%</span>
        </div>

        <div class="kmfb-card kmfb-card--label">
            <span class="kmfb-card-label">Part du label</span>
            <span class="kmfb-card-value"><?php echo esc_html( number_format( $s['label'], 0, ',', ' ' ) ); ?><small>F CFA</small></span>
            <span class="kmfb-card-sub">Taux <?php echo esc_html( $pct_label ); ?>% · production &amp; promotion</span>
        </div>

    </div>

    <!-- ══ BARRE DE RÉPARTITION ══ -->
    <div class="kmfb-splitbar-wrap">
        <div class="kmfb-splitbar">
            <div class="kmfb-splitbar-me" style="width:<?php echo esc_attr( max( 2, min( 98, $pct_artiste ) ) ); ?>%">
                <span>Moi · <?php echo esc_html( kmfb_fcfa( $s['artiste'] ) ); ?></span>
            </div>
            <div class="kmfb-splitbar-label" style="width:<?php echo esc_attr( max( 2, min( 98, $pct_label ) ) ); ?>%">
                <span>Label · <?php echo esc_html( kmfb_fcfa( $s['label'] ) ); ?></span>
            </div>
        </div>
        <p class="kmfb-note">
            Le taux appliqué est figé au moment de chaque encaissement : un changement
            de taux ne modifie jamais les montants déjà affichés ci-dessus.
        </p>
    </div>

    <!-- ══ ÉVOLUTION MENSUELLE ══ -->
    <?php if ( ! empty( $s['by_month'] ) ) : ?>
    <div class="kmfb-block">
        <div class="kmfb-block-title">Évolution mensuelle</div>
        <div class="kmfb-months">
            <?php foreach ( $s['by_month'] as $ym => $m ) :
                $h = $max_mois > 0 ? max( 4, round( $m['brut'] / $max_mois * 100 ) ) : 4; ?>
                <div class="kmfb-month" title="<?php echo esc_attr( kmfb_mois_label( $ym ) . ' — ' . kmfb_fcfa( $m['brut'] ) . ' brut, dont ' . kmfb_fcfa( $m['artiste'] ) . ' pour moi' ); ?>">
                    <div class="kmfb-month-barwrap">
                        <div class="kmfb-month-bar" style="height:<?php echo esc_attr( $h ); ?>%"></div>
                    </div>
                    <span class="kmfb-month-val"><?php echo esc_html( number_format( $m['artiste'], 0, ',', ' ' ) ); ?></span>
                    <span class="kmfb-month-name"><?php echo esc_html( ucfirst( substr( kmfb_mois_label( $ym ), 0, 3 ) ) ); ?></span>
                    <span class="kmfb-month-nb"><?php echo esc_html( $m['nb_users'] ); ?> pers.</span>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="kmfb-note">Les barres représentent le brut collecté ; le chiffre affiché est <strong>ma part</strong> (F CFA).</p>
    </div>
    <?php endif; ?>

    <!-- ══ RÉPARTITION PAR PALIER ══ -->
    <?php if ( ! empty( $s['by_palier'] ) ) : ?>
    <div class="kmfb-block">
        <div class="kmfb-block-title">Par palier de soutien</div>
        <div class="kmfb-table-wrap">
            <table class="kmfb-table">
                <thead>
                    <tr>
                        <th>Palier</th>
                        <th class="kmfb-num">Membres</th>
                        <th class="kmfb-num">Soutiens</th>
                        <th class="kmfb-num">Brut</th>
                        <th class="kmfb-num">Ma part</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $s['by_palier'] as $slug => $p ) : ?>
                    <tr>
                        <td>
                            <span class="kmfb-pastille" style="background:<?php echo esc_attr( kmfb_palier_color( $slug ) ); ?>"></span>
                            <?php echo esc_html( kmfb_palier_label( $slug ) ); ?>
                        </td>
                        <td class="kmfb-num"><?php echo esc_html( $p['nb_users'] ); ?></td>
                        <td class="kmfb-num"><?php echo esc_html( $p['nb'] ); ?></td>
                        <td class="kmfb-num"><?php echo esc_html( number_format( $p['brut'], 0, ',', ' ' ) ); ?></td>
                        <td class="kmfb-num kmfb-strong"><?php echo esc_html( number_format( $p['artiste'], 0, ',', ' ' ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ DERNIERS SOUTIENS ══ -->
    <?php if ( ! empty( $s['recents'] ) ) : ?>
    <div class="kmfb-block">
        <div class="kmfb-block-title">Derniers soutiens reçus</div>
        <div class="kmfb-table-wrap">
            <table class="kmfb-table">
                <thead>
                    <tr>
                        <th>Membre</th>
                        <th>Palier</th>
                        <th>Formule</th>
                        <th class="kmfb-num">Montant</th>
                        <th class="kmfb-num">Ma part</th>
                        <th class="kmfb-num">Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $s['recents'] as $r ) : ?>
                    <tr>
                        <td><?php echo esc_html( $r['nom'] ); ?></td>
                        <td>
                            <span class="kmfb-pastille" style="background:<?php echo esc_attr( kmfb_palier_color( $r['palier'] ) ); ?>"></span>
                            <?php echo esc_html( kmfb_palier_label( $r['palier'] ) ); ?>
                        </td>
                        <td><?php echo esc_html( kmfb_periodicite_label( $r['periodicity'] ) ); ?></td>
                        <td class="kmfb-num"><?php echo esc_html( number_format( $r['brut'], 0, ',', ' ' ) ); ?></td>
                        <td class="kmfb-num kmfb-strong">
                            <?php echo esc_html( number_format( $r['artiste'], 0, ',', ' ' ) ); ?>
                            <small>(<?php echo esc_html( $r['split_pct'] ); ?>%)</small>
                        </td>
                        <td class="kmfb-num"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $r['date'] ) ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
        <?php
        return ob_get_clean();
    }
}

// ══════════════════════════════════════════════════════════════
// VUE LABEL — récapitulatif tous artistes
// ══════════════════════════════════════════════════════════════
add_action( 'km_label_dashboard_after_kpi', 'kmfb_render_label_section' );
function kmfb_render_label_section() {
    if ( function_exists( 'km_user_is_label_staff' ) && ! km_user_is_label_staff() ) return;
    echo kmfb_get_label_section();
}

if ( ! function_exists( 'kmfb_get_label_section' ) ) {
    function kmfb_get_label_section() {
        if ( ! kmfb_available() ) return '';
        if ( ! method_exists( 'KMFamily_Revenue', 'get_label_summary' ) ) return '';

        $data = KMFamily_Revenue::get_label_summary();
        if ( empty( $data['artistes'] ) ) return '';

        ob_start(); ?>
<div class="km-section kmfb-section kmfb-section--label">
    <div class="km-section-header">
        <div>
            <h3 class="km-section-title"><span class="kmfb-heart">♥</span> KM FAMILY — Soutiens communautaires</h3>
            <span class="km-section-sub"><?php echo esc_html( $data['transactions'] ); ?> soutien(s) · répartition artistes / label</span>
        </div>
        <div class="kmfb-split-badge">
            <span class="kmfb-split-me"><?php echo esc_html( kmfb_fcfa( $data['artiste'] ) ); ?></span>
            <span class="kmfb-split-sep">/</span>
            <span class="kmfb-split-label"><?php echo esc_html( kmfb_fcfa( $data['label'] ) ); ?></span>
            <small>artistes / label</small>
        </div>
    </div>

    <div class="kmfb-table-wrap">
        <table class="kmfb-table">
            <thead>
                <tr>
                    <th>Artiste</th>
                    <th class="kmfb-num">Soutiens actifs</th>
                    <th class="kmfb-num">Membres</th>
                    <th class="kmfb-num">Brut</th>
                    <th class="kmfb-num">Part artiste</th>
                    <th class="kmfb-num">Part label</th>
                    <th class="kmfb-num">Taux</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $data['artistes'] as $a ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $a['nom'] ); ?></strong></td>
                    <td class="kmfb-num"><?php echo esc_html( $a['actifs'] ); ?></td>
                    <td class="kmfb-num"><?php echo esc_html( $a['nb_users'] ); ?></td>
                    <td class="kmfb-num"><?php echo esc_html( number_format( $a['brut'], 0, ',', ' ' ) ); ?></td>
                    <td class="kmfb-num kmfb-strong"><?php echo esc_html( number_format( $a['artiste'], 0, ',', ' ' ) ); ?></td>
                    <td class="kmfb-num"><?php echo esc_html( number_format( $a['label'], 0, ',', ' ' ) ); ?></td>
                    <td class="kmfb-num"><?php echo esc_html( $a['split_pct'] ); ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><strong>TOTAL</strong></td>
                    <td class="kmfb-num">—</td>
                    <td class="kmfb-num">—</td>
                    <td class="kmfb-num"><strong><?php echo esc_html( number_format( $data['brut'], 0, ',', ' ' ) ); ?></strong></td>
                    <td class="kmfb-num kmfb-strong"><strong><?php echo esc_html( number_format( $data['artiste'], 0, ',', ' ' ) ); ?></strong></td>
                    <td class="kmfb-num"><strong><?php echo esc_html( number_format( $data['label'], 0, ',', ' ' ) ); ?></strong></td>
                    <td class="kmfb-num">—</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="kmfb-note">
        Montants en F CFA. Le taux affiché est le taux courant de l'artiste ; les
        montants, eux, reflètent le taux figé lors de chaque encaissement.
        Réglage : <em>KM Family → Partage &amp; Revenus</em>.
    </p>
</div>
        <?php
        return ob_get_clean();
    }
}
