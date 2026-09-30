<?php
// ============================================================
// KOPHI'S MUSIC — Tableau de Bord Artiste v3.1
// Design Premium — Données réelles CSV, filtres dynamiques
// ============================================================
defined( 'ABSPATH' ) || exit;

add_shortcode( 'km_artist_dashboard', 'km_render_artist_dashboard' );

function km_render_artist_dashboard() {
    if ( ! is_user_logged_in() ) {
        return '<script>window.location.href=' . wp_json_encode( km_dashboard_url( 'connexion-artiste' ) ) . ';</script>';
    }

    $user    = wp_get_current_user();
    $user_id = get_current_user_id();
    $filter_year    = isset($_GET['year'])    ? intval($_GET['year'])                 : '';
    $filter_periode = isset($_GET['periode']) ? sanitize_text_field($_GET['periode']) : '';
    $filter_album   = isset($_GET['album'])   ? sanitize_text_field($_GET['album'])   : '';
    $filter_track   = isset($_GET['track'])   ? sanitize_text_field($_GET['track'])   : '';

    // Taux de conversion EUR → XOF (F CFA) — taux fixe UEMOA
    if ( ! defined('KM_EUR_TO_XOF') ) define('KM_EUR_TO_XOF', 655.957);

    // ═══ Récupérer TOUS les rapports de cet artiste ═══════════════
    $rapports_all = get_posts(array(
        'post_type'      => 'rapport_mensuel',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'meta_value',
        'meta_key'       => 'periode',
        'order'          => 'DESC',
        'meta_query'     => array(
            'relation' => 'OR',
            array('key'=>'artiste_user_id','value'=>$user_id,'compare'=>'='),
            array('key'=>'artiste_user_id','value'=>strval($user_id),'compare'=>'='),
        ),
    ));

    // Fallback : chercher aussi par tunecore_artist_name si aucun résultat
    //
    // CORRECTIF SÉCURITÉ (fuite de données entre artistes) : l'ancien repli utilisait
    // le nom d'affichage du compte — modifiable par l'artiste lui-même depuis son profil
    // KM Family — avec une comparaison LIKE (« contient »). Un artiste qui se renommait
    // « a » voyait les relevés, gains et streams de TOUS les artistes dont le nom
    // contient un « a ». On n'utilise désormais que le nom TuneCore fixé par le label sur
    // la fiche artiste, en égalité stricte, et jamais un rapport déjà attribué à un
    // autre compte.
    if (empty($rapports_all)) {
        $profile = km_get_artist_profile_post($user_id);
        $tc_name = $profile ? trim((string) get_field('tunecore_name', $profile)) : '';
        $rapports_all = $tc_name === '' ? array() : get_posts(array(
            'post_type'      => 'rapport_mensuel',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array('key'=>'tunecore_artist_name','value'=>$tc_name,'compare'=>'='),
            ),
        ));
        $rapports_all = array_values(array_filter($rapports_all, function($r) use ($user_id) {
            $owner = (int) get_post_meta($r->ID, 'artiste_user_id', true);
            return $owner === 0 || $owner === (int) $user_id;
        }));
        // Aussi essayer post_meta direct si ACF n'est pas dispo
        if (empty($rapports_all)) {
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key IN ('artiste_user_id','_artiste_user_id')
                 AND meta_value = %s",
                strval($user_id)
            ));
            if ($ids) {
                $rapports_all = get_posts(array(
                    'post_type'  => 'rapport_mensuel',
                    'post_status'=> 'publish',
                    'post__in'   => $ids,
                    'posts_per_page' => -1,
                    'orderby'    => 'date',
                    'order'      => 'DESC',
                ));
            }
        }
    }

    // ═══ Index des années disponibles ═════════════════════════════
    $years_available = array();
    foreach ($rapports_all as $r) {
        $p = km_get_rapport_periode($r->ID);
        $y = km_extract_year($p);
        if ($y) $years_available[$y] = true;
    }
    krsort($years_available);

    // ═══ Agrégation avec filtres ══════════════════════════════════
    $total_streams    = 0;
    $total_gains      = 0.0;
    $total_gains_brut = 0.0;
    $platform_streams = array('spotify'=>0,'apple'=>0,'youtube'=>0,'deezer'=>0,'tidal'=>0);
    $platform_gains   = array('spotify'=>0,'apple'=>0,'youtube'=>0,'deezer'=>0,'tidal'=>0);
    $timeline         = array();
    $countries_agg    = array();
    $catalog_agg      = array();

    $rapports_filtered = array();

    foreach ($rapports_all as $r) {
        $periode = km_get_rapport_periode($r->ID);
        $year    = km_extract_year($periode);
        if ($filter_year    && $year    !== strval($filter_year))    continue;
        if ($filter_periode && $periode !== $filter_periode)         continue;
        $rapports_filtered[] = $r;

        $ts = km_get_streams($r->ID);
        $g  = km_get_part_artiste($r->ID);
        $gb = km_get_gains_brut($r->ID);

        $total_streams    += $ts;
        $total_gains      += $g;
        $total_gains_brut += $gb;

        $timeline[$periode] = array('streams'=>$ts,'gains'=>$g,'brut'=>$gb);

        foreach (array_keys($platform_streams) as $p) {
            $platform_streams[$p] += km_get_field_int($p.'_streams', $r->ID);
            $platform_gains[$p]   += km_get_field_float($p.'_gains', $r->ID);
        }

        // Pays
        $cd = km_get_json_meta($r->ID, '_km_countries');
        if (is_array($cd)) {
            foreach ($cd as $cc => $cdata) {
                if (!isset($countries_agg[$cc])) $countries_agg[$cc] = array('streams'=>0,'gains'=>0.0,'name'=>km_country_name($cc));
                $countries_agg[$cc]['streams'] += intval($cdata['streams'] ?? 0);
                $countries_agg[$cc]['gains']   += floatval($cdata['gains'] ?? 0);
            }
        }

        // Catalogue
        $cat = km_get_json_meta($r->ID, '_km_catalog');
        if (is_array($cat)) {
            foreach ($cat as $release => $rdata) {
                if (!isset($catalog_agg[$release])) {
                    $catalog_agg[$release] = array('type'=>$rdata['type']??'Son','upc'=>$rdata['upc']??'','tracks'=>array());
                }
                foreach ((array)($rdata['tracks']??array()) as $track) {
                    if (!in_array($track, $catalog_agg[$release]['tracks'], true))
                        $catalog_agg[$release]['tracks'][] = $track;
                }
            }
        }
    }

    // Listes albums/pistes disponibles pour filtres
    $albums_available = array_keys($catalog_agg);
    $tracks_available = array();
    foreach ($catalog_agg as $rel_title => $rel) {
        foreach ($rel['tracks'] as $t) {
            if (!in_array($t, $tracks_available, true)) $tracks_available[] = $t;
        }
    }
    sort($tracks_available);

    // Tri pays
    uasort($countries_agg, function($a,$b){ return $b['streams'] - $a['streams']; });
    $top_countries = array_slice($countries_agg, 0, 20, true);

    // Timeline triée chronologiquement (tri réel sur mois français, pas
    // alphabétique). Utilise km_periode_label_to_ts(), défini dans
    // label-dashboard.php et partagé avec le dashboard label, pour que
    // les deux tableaux de bord trient les périodes de façon identique.
    if ( function_exists( 'km_periode_label_to_ts' ) ) {
        uksort($timeline, function($a, $b) {
            return km_periode_label_to_ts($a) <=> km_periode_label_to_ts($b);
        });
    } else {
        // Repli si label-dashboard.php n'est pas chargé pour une raison
        // quelconque : ancienne logique locale, pour ne jamais régresser
        // vers un tri purement alphabétique silencieux.
        $mois_fr = array('janvier'=>1,'février'=>2,'mars'=>3,'avril'=>4,'mai'=>5,'juin'=>6,
                         'juillet'=>7,'août'=>8,'septembre'=>9,'octobre'=>10,'novembre'=>11,'décembre'=>12);
        uksort($timeline, function($a, $b) use ($mois_fr) {
            $parse = function($str) use ($mois_fr) {
                if (preg_match('/(.+)\s+(\d{4})$/u', trim(mb_strtolower($str)), $m)) {
                    $month = isset($mois_fr[$m[1]]) ? $mois_fr[$m[1]] : 0;
                    return intval($m[2]) * 100 + $month;
                }
                return 0;
            };
            return $parse($a) - $parse($b);
        });
    }
    $chart_labels  = array_keys($timeline);
    $chart_streams = array_column(array_values($timeline), 'streams');
    $chart_gains   = array_column(array_values($timeline), 'gains');

    // Top pays pour graphique
    $top8cnt   = array_slice($countries_agg, 0, 8, true);
    $js_cnt_l  = json_encode(array_values(array_map(function($v){ return $v['name']; }, $top8cnt)));
    $js_cnt_st = json_encode(array_values(array_map(function($v){ return $v['streams']; }, $top8cnt)));

    // Dernier rapport
    $last_r       = $rapports_all ? $rapports_all[0] : null;
    $last_periode = $last_r ? km_get_rapport_periode($last_r->ID) : '—';
    $last_streams = $last_r ? km_get_streams($last_r->ID) : 0;
    $last_gains   = $last_r ? km_get_part_artiste($last_r->ID) : 0.0;

    // Photo artiste
    $profile_post_id = km_get_artist_profile_post($user_id);
    $artist_photo    = $profile_post_id ? get_the_post_thumbnail_url($profile_post_id, 'medium') : '';
    $avatar_url      = $artist_photo ? '' : get_avatar_url($user_id, array('size'=>120,'default'=>'identicon'));

    // Genres
    $artist_genres = array();
    if ($profile_post_id) {
        $g_raw = get_field('genre_musical', $profile_post_id);
        if (is_array($g_raw)) $artist_genres = array_slice($g_raw, 0, 2);
    }

    // Paiements
    $paiements = get_posts(array('post_type'=>'paiement','posts_per_page'=>8,'post_status'=>'publish','meta_key'=>'artiste_user_id','meta_value'=>$user_id,'orderby'=>'date','order'=>'DESC'));
    $total_paye = 0.0;
    foreach (get_posts(array('post_type'=>'paiement','posts_per_page'=>-1,'post_status'=>'publish','meta_key'=>'artiste_user_id','meta_value'=>$user_id,'fields'=>'ids')) as $pid) {
        if (get_field('statut_paiement',$pid) === 'Payé') $total_paye += floatval(get_field('montant_paiement',$pid));
    }
    $total_paye_xof = round($total_paye * KM_EUR_TO_XOF);

    $total_gains_xof      = round($total_gains * KM_EUR_TO_XOF);
    $total_gains_brut_xof = round($total_gains_brut * KM_EUR_TO_XOF);
    $total_paye_xof       = 0;

    $commission_label = km_get_artist_commission($user_id);
    $pct_artiste      = round((1 - $commission_label) * 100, 1);
    $pct_label        = round($commission_label * 100, 1);

    $logo_url       = defined('KM_LOGO_URL') ? KM_LOGO_URL : '';
    $releve_css_url = defined('KM_PLUGIN_URL') ? KM_PLUGIN_URL . 'assets/releve.css' : '';
    $artist_slug    = sanitize_title($user->display_name);

    // JSON pour graphiques
    $js_labels  = json_encode($chart_labels);
    $js_streams = json_encode(array_map('intval', $chart_streams));
    $js_gains   = json_encode(array_map('floatval', $chart_gains));
    $js_plat_st = json_encode(array_values($platform_streams));
    $js_plat_ga = json_encode(array_values($platform_gains));
    $nonce      = wp_create_nonce('km_releve_nonce');
    $ajax_url   = esc_url(admin_url('admin-ajax.php'));

    $nb_rapports = count($rapports_all);
    $nb_pays     = count($countries_agg);
    $nb_releases = count($catalog_agg);

    ob_start(); ?>
<div id="km-artist-db" class="km-db-wrap">

<!-- ═══════════ HEADER ═══════════════════════════════════════════ -->
<header class="km-db-header">
    <div class="km-db-header-left">
        <div class="km-db-avatar">
            <?php if ($artist_photo): ?>
                <img src="<?php echo esc_url($artist_photo); ?>" alt="<?php echo esc_attr($user->display_name); ?>" />
            <?php elseif ($avatar_url): ?>
                <img src="<?php echo esc_url($avatar_url); ?>" alt="<?php echo esc_attr($user->display_name); ?>" />
            <?php else: ?>
                <span><?php echo strtoupper(substr($user->display_name,0,1)); ?></span>
            <?php endif; ?>
            <span class="km-avatar-online"></span>
        </div>
        <div class="km-db-user-info">
            <span class="km-db-label-tag">KOPHI'S MUSIC</span>
            <span class="km-db-artist-name"><?php echo esc_html($user->display_name); ?></span>
            <div class="km-db-artist-meta">
                <span class="km-db-role-tag">Artiste Urban Gospel</span>
                <?php foreach ($artist_genres as $genre): ?>
                    <span class="km-db-genre-tag"><?php echo esc_html($genre); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="km-db-header-right">
        <?php if ($logo_url): ?>
        <div class="km-header-logo-wrap"><img src="<?php echo esc_url($logo_url); ?>" alt="KOPHI'S MUSIC" /></div>
        <?php endif; ?>
        <a href="https://kophismusic.com/" class="km-btn-icon" title="Retour au site">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </a>
        <button class="km-btn-releve" onclick="kmOpenReleve()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Relevé PDF
        </button>
        <button class="km-btn-pdf" onclick="kmExportPDF()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
            Dashboard PDF
        </button>
        <a href="<?php echo esc_url(wp_logout_url(home_url('/connexion-artiste/'))); ?>" class="km-btn-logout">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            Déconnexion
        </a>
    </div>
</header>

<!-- ═══════════ FILTRES ═══════════════════════════════════════════ -->
<div class="km-filters-bar">
    <form method="get" id="km-filters-form">
        <div class="km-filters-row">
            <div class="km-filter-group">
                <label>📅 Année</label>
                <select name="year" onchange="this.form.submit()">
                    <option value="">Toutes les années</option>
                    <?php foreach (array_keys($years_available) as $y): ?>
                    <option value="<?php echo intval($y); ?>" <?php selected($filter_year, $y); ?>><?php echo intval($y); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="km-filter-group">
                <label>📆 Période</label>
                <select name="periode" onchange="this.form.submit()">
                    <option value="">Toutes les périodes</option>
                    <?php foreach ($rapports_all as $r):
                        $rp = km_get_rapport_periode($r->ID);
                        $ry = km_extract_year($rp);
                        if ($filter_year && $ry !== strval($filter_year)) continue;
                    ?>
                    <option value="<?php echo esc_attr($rp); ?>" <?php selected($filter_periode, $rp); ?>><?php echo esc_html($rp); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="km-filter-group">
                <label>🎵 Album / Sortie</label>
                <select name="album" onchange="this.form.submit()">
                    <option value="">Tous les albums</option>
                    <?php foreach ($albums_available as $alb): ?>
                    <option value="<?php echo esc_attr($alb); ?>" <?php selected($filter_album, $alb); ?>><?php echo esc_html($alb); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="km-filter-group">
                <label>🎤 Morceau</label>
                <select name="track" onchange="this.form.submit()">
                    <option value="">Tous les morceaux</option>
                    <?php foreach ($tracks_available as $trk): ?>
                    <option value="<?php echo esc_attr($trk); ?>" <?php selected($filter_track, $trk); ?>><?php echo esc_html($trk); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filter_year || $filter_periode || $filter_album || $filter_track): ?>
            <div class="km-filter-group km-filter-reset">
                <label>&nbsp;</label>
                <a href="<?php echo esc_url(get_permalink()); ?>" class="km-btn-reset">✕ Tout afficher</a>
            </div>
            <?php endif; ?>
            <div class="km-filter-summary">
                <span class="km-summary-label">Dernier :</span>
                <span class="km-summary-val"><?php echo esc_html($last_periode); ?></span>
                <span class="km-summary-sep">·</span>
                <span class="km-summary-streams"><?php echo number_format($last_streams,0,',',' '); ?> streams</span>
                <span class="km-summary-sep">·</span>
                <span class="km-summary-gains"><?php echo number_format($last_gains,4,',',''); ?> €</span>
            </div>
        </div>
    </form>
</div>

<!-- ═══════════ KPI CARDS ═════════════════════════════════════════ -->
<div class="km-kpi-grid">

    <div class="km-kpi-card km-kpi-gold">
        <div class="km-kpi-icon-wrap">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/></svg>
        </div>
        <div class="km-kpi-body">
            <span class="km-kpi-label">Total Streams</span>
            <span class="km-kpi-value km-counter" data-target="<?php echo intval($total_streams); ?>">0</span>
            <span class="km-kpi-sub"><?php echo $nb_rapports; ?> rapport(s) · <?php echo $nb_pays; ?> pays</span>
        </div>
        <div class="km-kpi-trend">
            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>
        </div>
    </div>

    <div class="km-kpi-card km-kpi-green">
        <div class="km-kpi-icon-wrap">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="km-kpi-body">
            <span class="km-kpi-label">Mes Gains Nets</span>
            <span class="km-kpi-value km-counter-float" data-target="<?php echo esc_attr($total_gains); ?>">0,0000 €</span>
            <span class="km-kpi-xof"><?php echo number_format($total_gains_xof, 0, ',', ' '); ?> F CFA</span>
            <span class="km-kpi-sub">Part artiste <?php echo $pct_artiste; ?>%</span>
        </div>
    </div>

    <div class="km-kpi-card">
        <div class="km-kpi-icon-wrap">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
        </div>
        <div class="km-kpi-body">
            <span class="km-kpi-label">Total Reçu</span>
            <span class="km-kpi-value"><?php echo number_format($total_paye, 2, ',', ''); ?> €</span>
            <span class="km-kpi-xof"><?php echo number_format($total_paye_xof, 0, ',', ' '); ?> F CFA</span>
            <span class="km-kpi-sub"><?php echo count($paiements); ?> paiement(s) enregistré(s)</span>
        </div>
    </div>

    <div class="km-kpi-card">
        <div class="km-kpi-icon-wrap">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
        </div>
        <div class="km-kpi-body">
            <span class="km-kpi-label">Ma Part Contrat</span>
            <span class="km-kpi-value"><?php echo $pct_artiste; ?>%</span>
            <span class="km-kpi-sub"><?php echo $pct_label; ?>% reversé au label</span>
        </div>
        <div class="km-kpi-donut-mini">
            <svg viewBox="0 0 36 36" width="52" height="52">
                <circle cx="18" cy="18" r="14" fill="none" stroke="rgba(255,255,255,.07)" stroke-width="4"/>
                <circle cx="18" cy="18" r="14" fill="none" stroke="#00D67F" stroke-width="4"
                    stroke-dasharray="<?php echo round($pct_artiste*0.88,1); ?> 88"
                    stroke-linecap="round" transform="rotate(-90 18 18)"/>
            </svg>
        </div>
    </div>

    <?php
    /**
     * PONT KM FAMILY — carte « Ma part KM Family » insérée dans la grille KPI.
     * Ne produit rien si l'extension KM Family est absente/désactivée.
     */
    do_action( 'km_artist_dashboard_kpi_cards', $user_id );
    ?>

</div><!-- .km-kpi-grid -->

<?php
/**
 * PONT KM FAMILY — section complète « Mes soutiens » (nombre de personnes,
 * montants, répartition artiste/label). Rendue par includes/km-family-bridge.php.
 */
do_action( 'km_artist_dashboard_after_kpi', $user_id );
?>

<!-- ═══════════ PLATEFORMES STRIP ════════════════════════════════ -->
<div class="km-platform-strip">
    <?php foreach ($platform_streams as $pk => $pst): ?>
    <div class="km-plat-pill km-plat-pill--<?php echo $pk; ?>" data-platform="<?php echo esc_attr($pk); ?>">
        <div class="km-plat-icon km-plat-icon--<?php echo $pk; ?>"><?php echo km_platform_icon($pk, 20); ?></div>
        <div class="km-plat-info">
            <span class="km-plat-name"><?php echo km_platform_name($pk); ?></span>
            <span class="km-plat-value"><?php echo number_format($pst, 0, ',', ' '); ?></span>
            <?php if ($total_streams > 0): ?>
            <div class="km-plat-bar"><div class="km-plat-bar-fill" style="width:<?php echo round($pst/max(1,$total_streams)*100); ?>%"></div></div>
            <?php endif; ?>
        </div>
        <div class="km-plat-gains"><?php echo number_format($platform_gains[$pk], 4, ',', ''); ?> €</div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══════════ GRAPHIQUES — Rangée principale ═══════════════════ -->
<div class="km-charts-row-main">

    <!-- Grande courbe évolution streams & gains -->
    <div class="km-chart-card km-chart-main">
        <div class="km-chart-header">
            <div>
                <h4 class="km-chart-title">Évolution Streams &amp; Gains</h4>
                <p class="km-chart-sub">Tous les rapports importés — <?php echo $nb_rapports; ?> période(s)</p>
            </div>
            <div class="km-chart-legend-row">
                <span class="km-legend-item"><span class="km-legend-dot" style="background:#F5A623;"></span>Streams</span>
                <span class="km-legend-item"><span class="km-legend-dot" style="background:#00D67F;"></span>Gains (€)</span>
            </div>
        </div>
        <?php if (count($chart_labels) > 0): ?>
        <div class="km-chart-canvas-wrap"><canvas id="km-chart-timeline"></canvas></div>
        <?php else: ?>
        <div class="km-chart-empty">
            <svg width="52" height="52" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1" opacity=".25"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            <p>Importez un rapport CSV TuneCore pour afficher les données</p>
            <small>Menu : Rapports Streaming → Import TuneCore → Uploader le CSV</small>
        </div>
        <?php endif; ?>
    </div>

    <!-- Colonne droite -->
    <div class="km-chart-col-right">

        <!-- Carte résumé mensuel -->
        <div class="km-monthly-card">
            <div class="km-monthly-label">Rapport Mensuel</div>
            <div class="km-monthly-periode">Dernier rapport importé : <strong><?php echo esc_html($last_periode); ?></strong></div>
            <div class="km-monthly-gains"><?php echo number_format($total_gains, 4, ',', ''); ?><span> €</span></div>
            <div class="km-kpi-xof" style="margin-bottom:5px;"><?php echo number_format($total_gains_xof, 0, ',', ' '); ?> F CFA</div>
            <div class="km-monthly-streams"><?php echo number_format($total_streams, 0, ',', ' '); ?> streams cumulés</div>
            <?php if ($total_gains_brut > 0): ?>
            <div class="km-monthly-brut">
                Bruts : <?php echo number_format($total_gains_brut, 4, ',', ''); ?> € (<?php echo number_format($total_gains_brut_xof, 0, ',', ' '); ?> F CFA)
                &nbsp;·&nbsp; Label : <?php echo number_format($total_gains_brut - $total_gains, 4, ',', ''); ?> €
            </div>
            <?php endif; ?>
            <?php if ($nb_releases > 0): ?>
            <div class="km-monthly-tags">
                <span class="km-mtag"><?php echo $nb_releases; ?> sortie(s)</span>
                <span class="km-mtag"><?php echo $nb_pays; ?> pays</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Donut plateformes -->
        <div class="km-chart-card km-chart-donut-card">
            <div class="km-chart-header">
                <h4 class="km-chart-title">Répartition Plateformes</h4>
            </div>
            <?php if (array_sum($platform_streams) > 0): ?>
            <div class="km-chart-canvas-wrap km-donut-wrap"><canvas id="km-chart-platforms"></canvas></div>
            <?php else: ?>
            <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée plateforme</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════ GRAPHIQUES — Rangée secondaire ══════════════════ -->
<div class="km-charts-row-secondary">
    <div class="km-chart-card">
        <div class="km-chart-header"><h4 class="km-chart-title">Gains par Plateforme (€)</h4></div>
        <?php if (array_sum(array_filter($platform_gains, function($v){return $v>0;})) > 0): ?>
        <div class="km-chart-canvas-wrap"><canvas id="km-chart-gains"></canvas></div>
        <?php else: ?>
        <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée gains</p></div>
        <?php endif; ?>
    </div>
    <div class="km-chart-card">
        <div class="km-chart-header"><h4 class="km-chart-title">🌍 Top Pays d'écoute</h4></div>
        <?php if ($top_countries): ?>
        <div class="km-chart-canvas-wrap"><canvas id="km-chart-countries"></canvas></div>
        <?php else: ?>
        <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée pays</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════ GRAPHIQUES — 3ème rangée (Secteur + Courbe cumul) ══ -->
<?php
$js_gains_brut_distrib = json_encode(array($total_gains, max(0, $total_gains_brut - $total_gains)));
$js_gains_xof_vals     = json_encode(array_map(function($v){ return round($v * KM_EUR_TO_XOF); }, $chart_gains));
?>
<div class="km-charts-row-3">

    <!-- Graphique secteur — Répartition gains bruts -->
    <div class="km-chart-card">
        <div class="km-chart-header">
            <div>
                <h4 class="km-chart-title">Répartition des Revenus</h4>
                <p class="km-chart-sub">Part artiste vs Label</p>
            </div>
        </div>
        <?php if ($total_gains_brut > 0): ?>
        <div class="km-chart-canvas-wrap" style="height:200px;"><canvas id="km-chart-gains-pie"></canvas></div>
        <?php else: ?>
        <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée</p></div>
        <?php endif; ?>
    </div>

    <!-- Courbe cumulative gains en F CFA -->
    <div class="km-chart-card">
        <div class="km-chart-header">
            <div>
                <h4 class="km-chart-title">Évolution Gains (F CFA)</h4>
                <p class="km-chart-sub">Équivalent en Francs CFA</p>
            </div>
            <div class="km-chart-legend-row">
                <span class="km-legend-item"><span class="km-legend-dot" style="background:#F5A623;"></span>F CFA</span>
            </div>
        </div>
        <?php if (count($chart_labels) > 0): ?>
        <div class="km-chart-canvas-wrap"><canvas id="km-chart-gains-xof"></canvas></div>
        <?php else: ?>
        <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée</p></div>
        <?php endif; ?>
    </div>

    <!-- Stats par plateforme — Radar -->
    <div class="km-chart-card">
        <div class="km-chart-header">
            <div>
                <h4 class="km-chart-title">Performance Plateformes</h4>
                <p class="km-chart-sub">Vue radar</p>
            </div>
        </div>
        <?php if (array_sum($platform_streams) > 0): ?>
        <div class="km-chart-canvas-wrap" style="height:200px;"><canvas id="km-chart-radar"></canvas></div>
        <?php else: ?>
        <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée</p></div>
        <?php endif; ?>
    </div>

</div>

<!-- ═══════════ AUDIENCE MONDIALE ════════════════════════════════ -->
<?php if ($top_countries): ?>
<div class="km-section km-section-countries">
    <div class="km-section-header">
        <div>
            <h3>🌍 Audience Mondiale</h3>
            <span class="km-section-sub"><?php echo count($countries_agg); ?> pays atteints · <?php echo number_format($total_streams,0,',',' '); ?> streams total</span>
        </div>
    </div>
    <div class="km-countries-grid">
        <?php $rank=0; foreach ($top_countries as $cc => $cdata): $rank++;
            $pct = $top_countries ? round($cdata['streams']/max(1,reset($top_countries)['streams'])*100) : 0;
        ?>
        <div class="km-country-card">
            <div class="km-country-rank <?php echo $rank<=3?'top':''; ?>">#<?php echo $rank; ?></div>
            <div class="km-country-flag-code"><?php echo esc_html($cc); ?></div>
            <div class="km-country-info">
                <span class="km-country-name"><?php echo esc_html($cdata['name']); ?></span>
                <div class="km-country-bar-wrap"><div class="km-country-bar" style="width:<?php echo $pct; ?>%"></div></div>
            </div>
            <div class="km-country-streams-val"><?php echo number_format($cdata['streams'],0,',',' '); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════ CATALOGUE ══════════════════════════════════════════ -->
<?php if ($catalog_agg): ?>
<div class="km-section km-section-catalog">
    <div class="km-section-header">
        <div>
            <h3>🎵 Mon Catalogue</h3>
            <span class="km-section-sub"><?php echo count($catalog_agg); ?> sortie(s)</span>
        </div>
    </div>
    <div class="km-catalog-grid">
        <?php foreach ($catalog_agg as $release_title => $rel):
            $cover_url = km_get_release_cover($release_title, $rel['upc'] ?? '');
            $card_id   = 'km-cat-'.sanitize_title($release_title).'-'.substr(md5($release_title),0,6);
        ?>
        <div class="km-catalog-card" id="<?php echo esc_attr($card_id); ?>" onclick="kmToggleCatalogCard('<?php echo esc_js($card_id); ?>')" role="button" tabindex="0">
            <!-- Cover carrée -->
            <div class="km-catalog-artwork">
                <?php if ($cover_url): ?>
                <img src="<?php echo esc_url($cover_url); ?>" alt="<?php echo esc_attr($release_title); ?>" loading="lazy" />
                <?php else: ?>
                <div class="km-catalog-artwork-placeholder">
                    <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="rgba(245,166,35,.45)" stroke-width="1">
                        <circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3.5" fill="rgba(245,166,35,.3)" stroke="none"/>
                        <circle cx="12" cy="12" r="1" fill="rgba(245,166,35,.7)" stroke="none"/>
                    </svg>
                </div>
                <?php endif; ?>
                <span class="km-cover-type-badge"><?php echo esc_html($rel['type']); ?></span>
                <div class="km-cover-expand-icon">
                    <svg width="22" height="22" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24" opacity=".9"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>
            <!-- Titre + meta -->
            <div class="km-catalog-info">
                <span class="km-catalog-title"><?php echo esc_html($release_title); ?></span>
                <div class="km-catalog-meta-row">
                    <?php if ($rel['upc']): ?><span class="km-catalog-upc">UPC <?php echo esc_html($rel['upc']); ?></span><?php endif; ?>
                    <span class="km-catalog-count"><?php echo count($rel['tracks']); ?> piste(s)</span>
                </div>
            </div>
            <!-- Pistes dépliables -->
            <?php if ($rel['tracks']): ?>
            <div class="km-catalog-tracks">
                <?php foreach ($rel['tracks'] as $ti => $track): ?>
                <div class="km-track-row">
                    <span class="km-track-num"><?php echo str_pad($ti+1,2,'0',STR_PAD_LEFT); ?></span>
                    <span class="km-track-title"><?php echo esc_html($track); ?></span>
                    <svg class="km-track-play" width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════ RAPPORTS MENSUELS TABLE ══════════════════════════ -->
<?php
// ── Agrégation multi-rapports : catalogue avec streams, pistes avec streams ──
$agg_releases = array(); // release → {type, streams, gains_brut, part_artiste, tracks_list}
$agg_tracks   = array(); // track   → {album, type, streams, gains_brut, part_artiste, platforms}
$agg_countries = array(); // cc     → {name, streams}

foreach ($rapports_filtered as $r) {
    $r_commission = km_get_artist_commission($user_id);
    $r_brut_total = km_get_gains_brut($r->ID);

    // Catalogue (releases)
    $rcat = km_get_json_meta($r->ID, '_km_catalog');
    if (is_array($rcat)) {
        foreach ($rcat as $rel => $rd) {
            if (!isset($agg_releases[$rel])) {
                $agg_releases[$rel] = array('type'=>$rd['type']??'Son','streams'=>0,'gains_brut'=>0.0,'part_artiste'=>0.0,'tracks'=>array());
            }
            $rel_streams = intval($rd['streams'] ?? 0);
            $rel_gains   = floatval($rd['gains']  ?? 0);
            // Utilise la part artiste pré-calculée à l'import (correcte pour
            // les overrides par titre ET les répartitions multi-artistes) si
            // disponible ; sinon (rapports importés avant cette mise à jour)
            // repli sur l'ancien calcul par commission générique.
            $rel_part    = isset($rd['part_artiste']) ? (float) $rd['part_artiste'] : ($rel_gains * (1 - $r_commission));
            $agg_releases[$rel]['streams']      += $rel_streams;
            $agg_releases[$rel]['gains_brut']   += $rel_gains;
            $agg_releases[$rel]['part_artiste'] += $rel_part;
            foreach ((array)($rd['tracks']??array()) as $t) {
                if (!in_array($t, $agg_releases[$rel]['tracks'], true)) $agg_releases[$rel]['tracks'][] = $t;
            }
        }
    }

    // Pistes (tracks avec streams réels)
    $rtracks = km_get_json_meta($r->ID, '_km_tracks');
    if (is_array($rtracks)) {
        foreach ($rtracks as $tname => $td) {
            if (!isset($agg_tracks[$tname])) {
                $agg_tracks[$tname] = array('album'=>$td['album']??'','type'=>$td['type']??'Son','streams'=>0,'gains_brut'=>0.0,'part_artiste'=>0.0,'platforms'=>array());
            }
            $t_gains = floatval($td['gains'] ?? 0);
            $agg_tracks[$tname]['streams']      += intval($td['streams'] ?? 0);
            $agg_tracks[$tname]['gains_brut']   += $t_gains;
            // Idem : utilise la part pré-calculée à l'import si disponible.
            $agg_tracks[$tname]['part_artiste'] += isset($td['part_artiste']) ? (float) $td['part_artiste'] : ($t_gains * (1 - $r_commission));
            // Plateformes
            if (!empty($td['platform']) && is_array($td['platform'])) {
                foreach ($td['platform'] as $pk => $pdata) {
                    if (!isset($agg_tracks[$tname]['platforms'][$pk])) $agg_tracks[$tname]['platforms'][$pk] = array('streams'=>0,'gains'=>0.0);
                    $agg_tracks[$tname]['platforms'][$pk]['streams'] += intval($pdata['streams']??0);
                    $agg_tracks[$tname]['platforms'][$pk]['gains']   += floatval($pdata['gains']??0);
                }
            }
        }
    }

    // Pays
    $rcnt = km_get_json_meta($r->ID, '_km_countries');
    if (is_array($rcnt)) {
        foreach ($rcnt as $cc => $cd) {
            if (!isset($agg_countries[$cc])) $agg_countries[$cc] = array('name'=>km_country_name($cc),'streams'=>0);
            $agg_countries[$cc]['streams'] += intval($cd['streams']??0);
        }
    }
}
uasort($agg_countries, function($a,$b){ return $b['streams'] - $a['streams']; });
arsort($agg_releases);
uasort($agg_tracks, function($a,$b){ return $b['streams'] - $a['streams']; });
$tab_platforms = array_keys(array_filter($platform_streams, function($v){ return $v > 0; }));
if (!$tab_platforms) $tab_platforms = array('apple','youtube','deezer','tidal');
?>

<div class="km-section km-section-tabs-wrapper">
    <div class="km-section-header">
        <div>
            <h3>📊 Historique des Rapports</h3>
            <span class="km-section-sub"><?php echo $nb_rapports; ?> rapport(s) importé(s)</span>
        </div>
        <?php if (count($rapports_filtered) !== $nb_rapports): ?>
        <span class="km-link-badge"><?php echo count($rapports_filtered); ?> affiché(s)</span>
        <?php endif; ?>
    </div>

    <?php if ($rapports_filtered): ?>
    <!-- ── Barre de Tabs ── -->
    <div class="km-tab-bar">
        <button class="km-tab-btn km-tab-active" onclick="kmSwitchTab(this,'km-hist-sorties')">🎵 SORTIE(S)</button>
        <button class="km-tab-btn" onclick="kmSwitchTab(this,'km-hist-titres')">🎶 TITRES (PISTE)</button>
        <button class="km-tab-btn" onclick="kmSwitchTab(this,'km-hist-pays')">🌍 PAYS</button>
    </div>

    <!-- ══ BLOC SORTIE(S) ══ -->
    <div id="km-hist-sorties" class="km-tab-panel km-tab-block">
        <div class="km-tab-block-title">SORTIE(S)</div>
        <div class="km-tab-block-body">
        <div class="km-reports-table-wrap">
        <table class="km-reports-table">
            <thead><tr>
                <th>SORTIE</th>
                <th>TYPE</th>
                <th>STREAMS</th>
                <?php foreach ($tab_platforms as $pk): ?>
                <th><span class="km-th-plat" style="border-left:2px solid <?php echo km_platform_color($pk); ?>;"><?php echo km_platform_name($pk); ?></span></th>
                <?php endforeach; ?>
                <th>GAINS BRUTS</th>
                <th>MA PART</th>
                <th>F CFA</th>
            </tr></thead>
            <tbody>
            <?php foreach ($agg_releases as $rel_name => $rel):
                $rel_xof = round($rel['part_artiste'] * KM_EUR_TO_XOF);
            ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span class="km-release-dot"></span>
                        <strong style="color:#fff;"><?php echo esc_html($rel_name); ?></strong>
                    </div>
                    <?php if ($rel['tracks']): ?>
                    <div class="km-tracks-list" style="margin-top:4px;">
                    <?php foreach ($rel['tracks'] as $ti => $t): ?>
                        <div class="km-cell-track"><span class="km-track-num-sm"><?php echo str_pad($ti+1,2,'0',STR_PAD_LEFT); ?></span><?php echo esc_html($t); ?></div>
                    <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td><span class="km-badge-type"><?php echo esc_html($rel['type']); ?></span></td>
                <td class="km-num"><strong><?php echo number_format($rel['streams'],0,',',' '); ?></strong></td>
                <?php foreach ($tab_platforms as $pk):
                    // Calcul streams plateforme pour cette sortie via tracks
                    $plat_s = 0;
                    foreach ($agg_tracks as $tn => $td) {
                        if ($td['album'] === $rel_name && isset($td['platforms'][$pk]))
                            $plat_s += $td['platforms'][$pk]['streams'];
                    }
                ?>
                <td class="km-num"><?php echo $plat_s ? number_format($plat_s,0,',',' ') : '<span class="km-num-zero">—</span>'; ?></td>
                <?php endforeach; ?>
                <td class="km-num"><?php echo number_format($rel['gains_brut'],4,',',''); ?> €</td>
                <td class="km-gains-cell"><?php echo number_format($rel['part_artiste'],4,',',''); ?> €</td>
                <td class="km-num" style="color:var(--km-primary);font-size:.7rem;"><?php echo number_format($rel_xof,0,',',' '); ?> F</td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$agg_releases): ?><tr><td colspan="9" style="text-align:center;color:var(--km-text-dim);padding:20px;">Aucune sortie</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>

    <!-- ══ BLOC TITRES (PISTE) ══ -->
    <div id="km-hist-titres" class="km-tab-panel km-tab-block" style="display:none;">
        <div class="km-tab-block-title">TITRES (PISTE)</div>
        <div class="km-tab-block-body">
        <div class="km-reports-table-wrap">
        <table class="km-reports-table">
            <thead><tr>
                <th>#</th>
                <th>TITRE</th>
                <th>ALBUM / SORTIE</th>
                <th>TYPE</th>
                <th>STREAMS</th>
                <?php foreach ($tab_platforms as $pk): ?>
                <th><span class="km-th-plat" style="border-left:2px solid <?php echo km_platform_color($pk); ?>;"><?php echo km_platform_name($pk); ?></span></th>
                <?php endforeach; ?>
                <th>GAINS BRUTS</th>
                <th>MA PART</th>
                <th>F CFA</th>
            </tr></thead>
            <tbody>
            <?php $ti=1; foreach ($agg_tracks as $tname => $td):
                $t_xof = round($td['part_artiste'] * KM_EUR_TO_XOF);
            ?>
            <tr>
                <td style="color:var(--km-text-dim);font-family:'JetBrains Mono',monospace;font-size:.68rem;"><?php echo str_pad($ti,2,'0',STR_PAD_LEFT); ?></td>
                <td><strong style="color:#fff;"><?php echo esc_html($tname); ?></strong></td>
                <td style="color:var(--km-text-dim);"><?php echo esc_html($td['album']); ?></td>
                <td><span class="km-badge-type"><?php echo esc_html($td['type']); ?></span></td>
                <td class="km-num"><strong><?php echo number_format($td['streams'],0,',',' '); ?></strong></td>
                <?php foreach ($tab_platforms as $pk):
                    $ps = isset($td['platforms'][$pk]) ? $td['platforms'][$pk]['streams'] : 0;
                ?>
                <td class="km-num"><?php echo $ps ? number_format($ps,0,',',' ') : '<span class="km-num-zero">—</span>'; ?></td>
                <?php endforeach; ?>
                <td class="km-num"><?php echo number_format($td['gains_brut'],4,',',''); ?> €</td>
                <td class="km-gains-cell"><?php echo number_format($td['part_artiste'],4,',',''); ?> €</td>
                <td class="km-num" style="color:var(--km-primary);font-size:.7rem;"><?php echo number_format($t_xof,0,',',' '); ?> F</td>
            </tr>
            <?php $ti++; endforeach; ?>
            <?php if (!$agg_tracks): ?><tr><td colspan="10" style="text-align:center;color:var(--km-text-dim);padding:20px;">Aucune piste — réimportez le CSV TuneCore</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>

    <!-- ══ BLOC PAYS ══ -->
    <div id="km-hist-pays" class="km-tab-panel km-tab-block" style="display:none;">
        <div class="km-tab-block-title">PAYS</div>
        <div class="km-tab-block-body">
        <div class="km-reports-table-wrap">
        <table class="km-reports-table">
            <thead><tr>
                <th>#</th>
                <th>PAYS</th>
                <th>CODE</th>
                <th>STREAMS</th>
                <th>% DU TOTAL</th>
            </tr></thead>
            <tbody>
            <?php $rank=1; $total_cnt_streams = array_sum(array_column($agg_countries,'streams'));
            foreach ($agg_countries as $cc => $cdata):
                $pct = $total_cnt_streams > 0 ? round($cdata['streams']/$total_cnt_streams*100,1) : 0;
            ?>
            <tr>
                <td class="km-country-rank <?php echo $rank<=3?'top':''; ?>">#<?php echo $rank; ?></td>
                <td style="color:#fff;font-weight:600;"><?php echo esc_html($cdata['name']); ?></td>
                <td><span class="km-country-code"><?php echo esc_html($cc); ?></span></td>
                <td class="km-num"><strong><?php echo number_format($cdata['streams'],0,',',' '); ?></strong></td>
                <td>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <div style="flex:1;height:4px;background:rgba(255,255,255,.06);border-radius:2px;overflow:hidden;">
                            <div style="width:<?php echo $pct; ?>%;height:100%;background:linear-gradient(90deg,var(--km-primary),var(--km-purple));border-radius:2px;"></div>
                        </div>
                        <span style="font-size:.68rem;color:var(--km-text-dim);min-width:32px;"><?php echo $pct; ?>%</span>
                    </div>
                </td>
            </tr>
            <?php $rank++; endforeach; ?>
            <?php if (!$agg_countries): ?><tr><td colspan="5" style="text-align:center;color:var(--km-text-dim);padding:20px;">Aucune donnée pays</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>

    <?php else: ?>
    <div class="km-empty-state">
        <svg width="52" height="52" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1" opacity=".25"><path d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        <p>Aucun rapport disponible.</p>
        <small>Demandez à votre label d'importer le rapport CSV TuneCore mensuel.</small>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════ PAIEMENTS ════════════════════════════════════════ -->
<div class="km-section km-section-paiements">
    <div class="km-section-header">
        <div><h3>💳 Mes Paiements</h3></div>
    </div>
    <?php if ($paiements): ?>
    <div class="km-reports-table-wrap">
        <table class="km-reports-table">
            <thead><tr><th>Date</th><th>Montant (€)</th><th>Montant (F CFA)</th><th>Méthode</th><th>Statut</th><th>Référence</th><th>Document</th></tr></thead>
            <tbody>
            <?php foreach ($paiements as $p):
                $statut = get_field('statut_paiement', $p->ID);
                $cls    = $statut==='Payé' ? 'km-badge-paye' : ($statut==='Annulé' ? 'km-badge-annule' : 'km-badge-attente');
                $pdf    = get_field('releve_pdf', $p->ID);
                $montant_eur = (float)get_field('montant_paiement',$p->ID);
                $montant_xof = round($montant_eur * KM_EUR_TO_XOF);
            ?>
            <tr>
                <td><?php echo esc_html(get_field('date_paiement',$p->ID)); ?></td>
                <td><strong class="km-gains-cell"><?php echo number_format($montant_eur,2,',',''); ?> €</strong></td>
                <td><strong class="km-gains-cell" style="color:var(--km-primary);"><?php echo number_format($montant_xof,0,',',' '); ?> F CFA</strong></td>
                <td><?php echo esc_html(get_field('methode_paiement',$p->ID)); ?></td>
                <td><span class="km-badge <?php echo $cls; ?>"><?php echo esc_html($statut); ?></span></td>
                <td><?php echo esc_html(get_field('reference_paiement',$p->ID)); ?></td>
                <td><?php echo $pdf ? '<a href="'.esc_url($pdf['url']).'" target="_blank" class="km-pdf-link">📄 PDF</a>' : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="km-empty-state">
        <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1" opacity=".25"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
        <p>Aucun paiement enregistré.</p>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════ COPYRIGHT ════════════════════════════════════════ -->
<div class="km-db-footer" style="display:block!important;visibility:visible!important;opacity:1!important;position:relative!important;z-index:999!important;margin:16px 0 0!important;background:rgba(0,2,18,.95)!important;border-top:2px solid rgba(245,166,35,.25)!important;width:100%!important;box-sizing:border-box!important;">
    <div style="display:flex!important;align-items:center;justify-content:space-between;padding:11px 28px;gap:12px;flex-wrap:wrap;">
        <div><?php if($logo_url): ?><img src="<?php echo esc_url($logo_url); ?>" alt="KOPHI'S MUSIC" style="height:20px;width:auto;opacity:.6;display:block;" /><?php endif; ?></div>
        <div style="flex:1;min-width:0;text-align:center;">
            <div style="display:flex!important;align-items:center;gap:6px;font-size:.63rem;color:rgba(255,255,255,.58)!important;flex-wrap:wrap;justify-content:center;font-family:'DM Sans',sans-serif;line-height:1.8;">
                <span>☎ +225 2731934575</span>
                <span style="color:rgba(255,255,255,.18);">|</span>
                <span>📱 +225 0503404389</span>
                <span style="color:rgba(255,255,255,.18);">|</span>
                <a href="mailto:contact@kophismusic.com" style="color:rgba(255,255,255,.58);text-decoration:none;">✉ contact@kophismusic.com</a>
                <span style="color:rgba(255,255,255,.18);">|</span>
                <span>© KOPHI'S MUSIC, 2025–<?php echo date('Y'); ?></span>
                <span style="color:rgba(255,255,255,.18);">|</span>
                <span>Tous droits réservés</span>
                <span style="color:rgba(255,255,255,.18);">|</span>
                <span>KOPHI'S GROUP SAS, Abj, CI</span>
            </div>
        </div>
        <div><a href="#" onclick="window.scrollTo({top:0,behavior:'smooth'});return false;" style="display:flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:6px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:rgba(255,255,255,.45);text-decoration:none;font-size:.85rem;">↑</a></div>
    </div>
</div>

</div><!-- #km-artist-db -->

<!-- ═══════════ MODAL RELEVÉ ══════════════════════════════════════ -->
<div id="km-releve-modal" class="km-modal-overlay" style="display:none;">
    <div class="km-modal-box">
        <div class="km-modal-header">
            <h3>📄 Relevé de Droits</h3>
            <button class="km-modal-close" onclick="kmCloseReleve()">✕</button>
        </div>
        <div class="km-modal-body" id="km-releve-content"><div class="km-modal-loading">⏳ Génération...</div></div>
        <div class="km-modal-footer">
            <button class="km-btn-releve" onclick="kmPrintReleve()">🖨 Imprimer</button>
            <button class="km-btn-pdf km-btn-releve-pdf" onclick="kmExportRelevePDF()">⬇ Exporter PDF</button>
            <button class="km-btn-reset" onclick="kmCloseReleve()">Fermer</button>
        </div>
    </div>
</div>

<!-- ═══════════ JAVASCRIPT ════════════════════════════════════════ -->
<script>
(function(){
var KM={
    nonce:'<?php echo esc_js($nonce); ?>',
    ajaxUrl:'<?php echo esc_js($ajax_url); ?>',
    userId:<?php echo intval($user_id); ?>,
    year:'<?php echo esc_js(strval($filter_year)); ?>',
    periode:'<?php echo esc_js($filter_periode); ?>',
    releveCSS:'<?php echo esc_js($releve_css_url); ?>',
    artistSlug:'<?php echo esc_js($artist_slug); ?>'
};
// ── Tab switcher — exposé globalement (onclick inline) ────────
window.kmSwitchTab = function(btn, targetId) {
    var section = btn.closest('.km-section-tabs-wrapper') || btn.closest('.km-section');
    if (!section) return;
    section.querySelectorAll('.km-tab-btn').forEach(function(b){ b.classList.remove('km-tab-active'); });
    section.querySelectorAll('.km-tab-panel').forEach(function(p){
        p.style.setProperty('display', 'none', 'important');
    });
    btn.classList.add('km-tab-active');
    var panel = document.getElementById(targetId);
    if (panel) {
        panel.style.setProperty('display', 'block', 'important');
    }
};

// ── Catalogue — toggle carte (cover click) ────────────────────
window.kmToggleCatalogCard = function(cardId) {
    var card = document.getElementById(cardId);
    if (!card) return;
    card.classList.toggle('km-card-open');
    // Icône chevron : tourner quand ouvert
    var icon = card.querySelector('.km-cover-expand-icon svg');
    if (icon) icon.style.transform = card.classList.contains('km-card-open') ? 'rotate(180deg)' : 'rotate(0deg)';
};

document.addEventListener('DOMContentLoaded',function(){
    if(typeof Chart==='undefined') return;
    Chart.defaults.color='#C8D6E8';
    Chart.defaults.font.family="'Space Grotesk',sans-serif";
    Chart.defaults.font.size=12;

    // Helpers
    function fmtEur(v){
        if(v===0) return '0,00 €';
        var abs=Math.abs(v);
        var dec=abs>=100?2:abs>=1?4:6;
        return v.toFixed(dec).replace('.',',')+' €';
    }
    function fmtNum(v){
        if(v>=1000000) return (v/1000000).toFixed(1).replace('.',',')+' M';
        if(v>=1000) return (v/1000).toFixed(1).replace('.',',')+' k';
        return v.toLocaleString('fr-FR');
    }
    function kmGradV(ctx,chartArea,colorTop,colorBot){
        if(!chartArea) return colorTop;
        var g=ctx.createLinearGradient(0,chartArea.top,0,chartArea.bottom);
        g.addColorStop(0,colorTop); g.addColorStop(1,colorBot); return g;
    }

    var ttStyle={
        backgroundColor:'rgba(5,7,20,.95)',
        borderColor:'rgba(255,255,255,.10)',
        borderWidth:1,padding:14,cornerRadius:10,
        titleColor:'#E8F0FF',bodyColor:'#8CA0C8',
        titleFont:{size:12,weight:'600'},bodyFont:{size:11},
        displayColors:true,boxWidth:10,boxHeight:10,boxPadding:4,usePointStyle:true
    };

    // Compteurs entiers animés
    document.querySelectorAll('.km-counter').forEach(function(el){
        var t=parseInt(el.dataset.target)||0;
        if(!t){el.textContent='0';return;}
        var c=0,inc=t/80,tm=setInterval(function(){c=Math.min(c+inc,t);el.textContent=Math.floor(c).toLocaleString('fr-FR');if(c>=t)clearInterval(tm);},16);
    });
    document.querySelectorAll('.km-counter-float').forEach(function(el){
        var t=parseFloat(el.dataset.target)||0;
        if(!t){el.textContent='0,00 €';return;}
        var c=0,inc=t/80,tm=setInterval(function(){c=Math.min(c+inc,t);el.textContent=fmtEur(c);if(c>=t)clearInterval(tm);},16);
    });

    var tlLabels=<?php echo $js_labels; ?>;
    var tlStreams=<?php echo $js_streams; ?>;
    var tlGains=<?php echo $js_gains; ?>;
    var tlGainsXOF=<?php echo $js_gains_xof_vals; ?>;
    var platData=<?php echo $js_plat_st; ?>;
    var gainsData=<?php echo $js_plat_ga; ?>;
    var platLabels=['Spotify','Apple Music','YouTube','Deezer','Tidal'];
    var platColors=['#1DB954','#FC3C44','#FF0000','#A238FF','#00CFFF'];
    var cntLabels=<?php echo $js_cnt_l; ?>;
    var cntStreams=<?php echo $js_cnt_st; ?>;
    var cntPalette=['#F4B942','#00E589','#4C78FF','#FF5A6E','#A238FF','#00CFFF','#FF8C42','#1DB954','#FC3C44','#FFD166','#06D6A0','#EF476F','#118AB2','#9B5DE5','#F15BB5','#FEE440','#00BBF9','#00F5D4','#FF9F1C','#8338EC'];
    var gainsPieData=<?php echo $js_gains_brut_distrib; ?>;
    var pctArtiste=<?php echo $pct_artiste; ?>;
    var pctLabel=<?php echo $pct_label; ?>;

    // Timeline streams + gains EUR
    if(tlLabels.length>0 && document.getElementById('km-chart-timeline')){
        new Chart(document.getElementById('km-chart-timeline'),{
            type:'line',
            data:{labels:tlLabels,datasets:[
                {label:'Streams',data:tlStreams,
                    borderColor:'#F5A623',
                    backgroundColor:function(ctx){var ca=ctx.chart.chartArea;return kmGradV(ctx.chart.ctx,ca,'rgba(245,166,35,.22)','rgba(245,166,35,.01)');},
                    borderWidth:2.5,fill:true,tension:0.42,
                    pointBackgroundColor:'#050710',pointBorderColor:'#F5A623',pointBorderWidth:2,pointRadius:4,pointHoverRadius:7,yAxisID:'y'},
                {label:'Gains (€)',data:tlGains,
                    borderColor:'#00D67F',
                    backgroundColor:function(ctx){var ca=ctx.chart.chartArea;return kmGradV(ctx.chart.ctx,ca,'rgba(0,214,127,.15)','rgba(0,214,127,.01)');},
                    borderWidth:2,fill:true,tension:0.42,
                    pointBackgroundColor:'#050710',pointBorderColor:'#00D67F',pointBorderWidth:2,pointRadius:3,pointHoverRadius:6,yAxisID:'y1'}
            ]},
            options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
                plugins:{legend:{display:false},tooltip:Object.assign({},ttStyle,{callbacks:{label:function(c){
                    if(c.datasetIndex===0) return ' Streams : '+fmtNum(c.parsed.y);
                    return ' Gains : '+fmtEur(c.parsed.y);
                }}})},
                scales:{
                    x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#5A6A8A',maxRotation:30}},
                    y:{grid:{color:'rgba(255,255,255,.04)'},position:'left',ticks:{color:'#F5A623',callback:function(v){return fmtNum(v);}}},
                    y1:{grid:{display:false},position:'right',ticks:{color:'#00D67F',callback:function(v){return fmtEur(v);}}}
                }
            }
        });
    }
    // Donut plateformes (total streams au centre)
    if(platData.some(function(v){return v>0;})&&document.getElementById('km-chart-platforms')){
        var totalStreams=platData.reduce(function(a,b){return a+b;},0);
        var centerLabelPlugin={id:'kmCenterLabel',beforeDraw:function(chart){
            var ctx=chart.ctx,ca=chart.chartArea;
            var cx=(ca.left+ca.right)/2,cy=(ca.top+ca.bottom)/2;
            ctx.save();ctx.textAlign='center';ctx.textBaseline='middle';
            ctx.fillStyle='#E8F0FF';ctx.font='bold 15px "Space Grotesk",sans-serif';
            ctx.fillText(fmtNum(totalStreams),cx,cy-9);
            ctx.fillStyle='#5A6A8A';ctx.font='11px "Space Grotesk",sans-serif';
            ctx.fillText('streams',cx,cy+10);ctx.restore();
        }};
        new Chart(document.getElementById('km-chart-platforms'),{
            type:'doughnut',plugins:[centerLabelPlugin],
            data:{labels:platLabels,datasets:[{data:platData,
                backgroundColor:platColors,hoverBackgroundColor:platColors.map(function(c){return c+'DD';}),
                borderWidth:2,borderColor:'rgba(5,7,20,.8)',hoverOffset:14,borderRadius:4}]},
            options:{responsive:true,maintainAspectRatio:false,cutout:'68%',
                plugins:{
                    legend:{position:'bottom',labels:{padding:12,usePointStyle:true,pointStyle:'circle',color:'#8899BB',font:{size:11}}},
                    tooltip:Object.assign({},ttStyle,{callbacks:{label:function(c){
                        var pct=totalStreams>0?((c.parsed/totalStreams)*100).toFixed(1):'0';
                        return ' '+c.label+': '+fmtNum(c.parsed)+' ('+pct+'%)';
                    }}})
                }
            }
        });
    }
    // Gains par plateforme (barres avec dégradé)
    if(gainsData.some(function(v){return v>0;})&&document.getElementById('km-chart-gains')){
        new Chart(document.getElementById('km-chart-gains'),{
            type:'bar',
            data:{labels:platLabels,datasets:[{data:gainsData,
                backgroundColor:function(ctx){
                    var ca=ctx.chart.chartArea;
                    if(!ca) return platColors[ctx.dataIndex]+'BB';
                    var g=ctx.chart.ctx.createLinearGradient(0,ca.top,0,ca.bottom);
                    var col=platColors[ctx.dataIndex]||'#4C9FFF';
                    g.addColorStop(0,col+'EE');g.addColorStop(1,col+'44');return g;
                },
                borderColor:platColors,borderWidth:1,borderRadius:8,borderSkipped:false,
                hoverBackgroundColor:platColors.map(function(c){return c+'FF';})
            }]},
            options:{responsive:true,maintainAspectRatio:false,
                plugins:{legend:{display:false},tooltip:Object.assign({},ttStyle,{callbacks:{label:function(c){return ' '+c.label+': '+fmtEur(c.parsed.y);}}})},
                scales:{x:{grid:{display:false},ticks:{color:'#5A6A8A'}},y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#5A6A8A',callback:function(v){return fmtEur(v);}}}}
            }
        });
    }
    // Top pays (barres horizontales avec dégradé)
    if(cntLabels.length>0&&document.getElementById('km-chart-countries')){
        new Chart(document.getElementById('km-chart-countries'),{
            type:'bar',
            data:{labels:cntLabels,datasets:[{data:cntStreams,
                backgroundColor:function(ctx){
                    var ca=ctx.chart.chartArea;
                    if(!ca) return cntPalette[ctx.dataIndex%cntPalette.length]+'99';
                    var g=ctx.chart.ctx.createLinearGradient(ca.left,0,ca.right,0);
                    var col=cntPalette[ctx.dataIndex%cntPalette.length];
                    g.addColorStop(0,col+'EE');g.addColorStop(1,col+'33');return g;
                },
                borderColor:cntPalette.map(function(c){return c+'CC';}),
                borderWidth:1,borderRadius:5,borderSkipped:false
            }]},
            options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,
                plugins:{legend:{display:false},tooltip:Object.assign({},ttStyle,{callbacks:{label:function(c){return ' '+fmtNum(c.parsed.x)+' streams';}}})},
                scales:{x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#6A82B0',callback:function(v){return fmtNum(v);}}},y:{grid:{display:false},ticks:{color:'#C8D6F0',font:{size:11}}}}
            }
        });
    }

    // Donut répartition artiste / label
    if(gainsPieData.some(function(v){return v>0;})&&document.getElementById('km-chart-gains-pie')){
        var pieCenterPlugin={id:'kmPieCenter',beforeDraw:function(chart){
            var ctx=chart.ctx,ca=chart.chartArea;
            var cx=(ca.left+ca.right)/2,cy=(ca.top+ca.bottom)/2;
            ctx.save();ctx.textAlign='center';ctx.textBaseline='middle';
            ctx.fillStyle='#00D67F';ctx.font='bold 16px "Space Grotesk",sans-serif';
            ctx.fillText(pctArtiste+'%',cx,cy-9);
            ctx.fillStyle='#5A6A8A';ctx.font='11px "Space Grotesk",sans-serif';
            ctx.fillText('artiste',cx,cy+10);ctx.restore();
        }};
        new Chart(document.getElementById('km-chart-gains-pie'),{
            type:'doughnut',plugins:[pieCenterPlugin],
            data:{labels:['Part Artiste ('+pctArtiste+'%)','Part Label ('+pctLabel+'%)'],datasets:[{
                data:gainsPieData,backgroundColor:['#00D67F','#F5A623'],
                hoverBackgroundColor:['#00F095','#FFB930'],
                borderWidth:2,borderColor:'rgba(5,7,20,.8)',hoverOffset:12,borderRadius:4
            }]},
            options:{responsive:true,maintainAspectRatio:false,cutout:'65%',
                plugins:{
                    legend:{position:'bottom',labels:{padding:12,usePointStyle:true,pointStyle:'circle',color:'#8899BB',font:{size:11}}},
                    tooltip:Object.assign({},ttStyle,{callbacks:{label:function(c){return ' '+c.label+': '+fmtEur(c.parsed);}}})
                }
            }
        });
    }

    // Courbe gains XOF
    if(tlLabels.length>0&&document.getElementById('km-chart-gains-xof')){
        new Chart(document.getElementById('km-chart-gains-xof'),{
            type:'line',
            data:{labels:tlLabels,datasets:[{label:'Gains F CFA',data:tlGainsXOF,
                borderColor:'#F5A623',
                backgroundColor:function(ctx){var ca=ctx.chart.chartArea;return kmGradV(ctx.chart.ctx,ca,'rgba(245,166,35,.28)','rgba(245,166,35,.01)');},
                borderWidth:2.5,fill:true,tension:0.42,
                pointBackgroundColor:'#050710',pointBorderColor:'#F5A623',pointBorderWidth:2,pointRadius:4,pointHoverRadius:7
            }]},
            options:{responsive:true,maintainAspectRatio:false,
                plugins:{legend:{display:false},tooltip:Object.assign({},ttStyle,{callbacks:{label:function(c){return ' '+c.parsed.y.toLocaleString('fr-FR')+' F CFA';}}})},
                scales:{x:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#5A6A8A',maxRotation:30}},y:{grid:{color:'rgba(255,255,255,.04)'},ticks:{color:'#F5A623',callback:function(v){return fmtNum(v);}}}}
            }
        });
    }

    // Radar performance plateformes
    if(platData.some(function(v){return v>0;})&&document.getElementById('km-chart-radar')){
        var radarPlugin={id:'kmRadarGrad',beforeDraw:function(chart){
            var ctx=chart.ctx,ca=chart.chartArea;
            if(!ca) return;
            var cx=(ca.left+ca.right)/2,cy=(ca.top+ca.bottom)/2;
            var r=Math.min(ca.right-cx,ca.bottom-cy);
            var g=ctx.createRadialGradient(cx,cy,0,cx,cy,r);
            g.addColorStop(0,'rgba(76,159,255,.18)');g.addColorStop(1,'rgba(76,159,255,.02)');
            chart.data.datasets[0]._gradFill=g;
        }};
        new Chart(document.getElementById('km-chart-radar'),{
            type:'radar',plugins:[radarPlugin],
            data:{labels:platLabels,datasets:[{label:'Streams',data:platData,
                borderColor:'#4C9FFF',
                backgroundColor:function(ctx){return ctx.dataset._gradFill||'rgba(76,159,255,.12)';},
                pointBackgroundColor:'#050710',pointBorderColor:platColors,
                pointBorderWidth:2,pointRadius:5,pointHoverRadius:8,borderWidth:2,fill:true
            }]},
            options:{responsive:true,maintainAspectRatio:false,
                plugins:{legend:{display:false},tooltip:Object.assign({},ttStyle,{callbacks:{label:function(c){return ' '+c.label+': '+fmtNum(c.parsed.r)+' streams';}}})},
                scales:{r:{
                    grid:{color:'rgba(255,255,255,.08)'},angleLines:{color:'rgba(255,255,255,.08)'},
                    pointLabels:{color:'#8CA0C8',font:{size:12,weight:'500'}},
                    ticks:{color:'#5A6A8A',backdropColor:'transparent',callback:function(v){return fmtNum(v);}}
                }}
            }
        });
    }

}); // end DOMContentLoaded

// Relevé PDF
window.kmOpenReleve=function(){
    document.getElementById('km-releve-modal').style.display='flex';
    document.getElementById('km-releve-content').innerHTML='<div class="km-modal-loading">\u23F3 G\u00E9n\u00E9ration...</div>';
    var d=new FormData();d.append('action','km_get_releve');d.append('nonce',KM.nonce);d.append('user_id',KM.userId);d.append('year',KM.year);d.append('periode',KM.periode);
    fetch(KM.ajaxUrl,{method:'POST',body:d}).then(function(r){return r.json();}).then(function(d){document.getElementById('km-releve-content').innerHTML=d.success?d.data.html:'<p style="color:#FF4E6A;padding:20px">Erreur.</p>';}).catch(function(){document.getElementById('km-releve-content').innerHTML='<p style="color:#FF4E6A;padding:20px">Erreur r\u00E9seau.</p>';});
};
window.kmCloseReleve=function(){document.getElementById('km-releve-modal').style.display='none';};
window.kmPrintReleve=function(){var el=document.getElementById('km-releve-print');if(!el)return;var w=window.open('','_blank');w.document.write('<!DOCTYPE html><html><head><title>Relev\u00E9</title><link rel="stylesheet" href="'+KM.releveCSS+'"></head><body>'+el.outerHTML+'</body></html>');w.document.close();w.focus();setTimeout(function(){w.print();},800);};
window.kmExportRelevePDF=function(){
    var el=document.getElementById('km-releve-print');if(!el){alert('G\u00E9n\u00E9rez d\u0027abord le relev\u00E9.');return;}
    var btn=document.querySelector('.km-btn-releve-pdf');if(btn){btn.textContent='\u23F3...';btn.disabled=true;}
    var jsPDF=window.jspdf&&window.jspdf.jsPDF;if(!jsPDF)return;
    var doc=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
    html2canvas(el,{scale:2,useCORS:true,backgroundColor:'#ffffff'}).then(function(canvas){var imgData=canvas.toDataURL('image/png');var pw=doc.internal.pageSize.getWidth();var ph=doc.internal.pageSize.getHeight();var ih=(canvas.height*pw)/canvas.width;var py=0,rem=ih;while(rem>0){doc.addImage(imgData,'PNG',0,py,pw,ih);rem-=ph;py-=ph;if(rem>0)doc.addPage();}doc.save('Releve_KM_'+KM.artistSlug+'_'+new Date().toLocaleDateString('fr-FR').replace(/\//g,'-')+'.pdf');if(btn){btn.textContent='\u229B Exporter PDF';btn.disabled=false;}});
};
window.kmExportPDF=function(){
    var btn=document.querySelector('.km-btn-pdf');if(btn){btn.textContent='\u23F3...';btn.disabled=true;}
    var jsPDF=window.jspdf&&window.jspdf.jsPDF;if(!jsPDF)return;
    var doc=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
    html2canvas(document.getElementById('km-artist-db'),{scale:1.4,useCORS:true,backgroundColor:'#000530'}).then(function(canvas){var imgData=canvas.toDataURL('image/png');var pw=doc.internal.pageSize.getWidth();var ph=doc.internal.pageSize.getHeight();var ih=(canvas.height*pw)/canvas.width;var py=0,rem=ih;while(rem>0){doc.addImage(imgData,'PNG',0,py,pw,ih);rem-=ph;py-=ph;if(rem>0)doc.addPage();}doc.save('KM_Dashboard_'+KM.artistSlug+'_'+new Date().toLocaleDateString('fr-FR').replace(/\//g,'-')+'.pdf');if(btn){btn.textContent='Dashboard PDF';btn.disabled=false;}});
};
document.addEventListener('keydown',function(e){if(e.key==='Escape')kmCloseReleve();});
})();
</script>
<?php
    return ob_get_clean();
}

// ══════════════════════════════════════════════
// HELPERS — lecture données avec fallbacks robustes
// ══════════════════════════════════════════════

if (!function_exists('km_get_rapport_periode')) {
    function km_get_rapport_periode($post_id) {
        $v = get_field('periode', $post_id);
        if (!$v) $v = get_post_meta($post_id, 'periode', true);
        if (!$v) $v = get_post_meta($post_id, '_periode', true);
        if (!$v) $v = get_the_title($post_id);
        return $v ?: '—';
    }
}
if (!function_exists('km_extract_year')) {
    function km_extract_year($str) {
        if (preg_match('/(\d{4})/', $str, $m)) return $m[1];
        return '';
    }
}
if (!function_exists('km_get_streams')) {
    function km_get_streams($post_id) {
        $v = (int) get_field('total_streams', $post_id);
        if (!$v) $v = (int) get_post_meta($post_id, 'total_streams', true);
        if (!$v) $v = (int) get_post_meta($post_id, '_km_total_streams', true);
        if (!$v) {
            foreach (array('spotify','apple','youtube','deezer','tidal') as $p) {
                $v += km_get_field_int($p.'_streams', $post_id);
            }
        }
        return $v;
    }
}
if (!function_exists('km_get_gains_brut')) {
    function km_get_gains_brut($post_id) {
        $v = (float) get_field('total_gains_brut', $post_id);
        if (!$v) $v = (float) get_post_meta($post_id, 'total_gains_brut', true);
        if (!$v) $v = (float) get_post_meta($post_id, '_km_total_gains', true);
        return $v;
    }
}
if (!function_exists('km_get_part_artiste')) {
    function km_get_part_artiste($post_id) {
        $v = (float) get_field('part_artiste', $post_id);
        if (!$v) $v = (float) get_post_meta($post_id, 'part_artiste', true);
        if (!$v) $v = (float) get_post_meta($post_id, '_km_part_artiste', true);
        return $v;
    }
}
if (!function_exists('km_get_field_int')) {
    function km_get_field_int($key, $post_id) {
        $v = get_field($key, $post_id);
        if ($v !== false && $v !== null) return (int) $v;
        $v = get_post_meta($post_id, $key, true);
        return (int) $v;
    }
}
if (!function_exists('km_get_field_float')) {
    function km_get_field_float($key, $post_id) {
        $v = get_field($key, $post_id);
        if ($v !== false && $v !== null) return (float) $v;
        $v = get_post_meta($post_id, $key, true);
        return (float) $v;
    }
}
if (!function_exists('km_get_json_meta')) {
    function km_get_json_meta($post_id, $key) {
        $raw = get_post_meta($post_id, $key, true);
        if (!$raw) return null;
        return json_decode($raw, true);
    }
}

if (!function_exists('km_get_artist_profile_post')) {
    function km_get_artist_profile_post($user_id) {
        $posts = get_posts(array('post_type'=>'nos-artistes','posts_per_page'=>1,'post_status'=>'publish','meta_key'=>'artiste_user_id','meta_value'=>$user_id));
        return $posts ? $posts[0]->ID : null;
    }
}