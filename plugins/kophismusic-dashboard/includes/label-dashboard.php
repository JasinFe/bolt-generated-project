<?php
// ============================================================
// KOPHI'S MUSIC — Tableau de Bord Label v3.0
// Design Premium — Filtres avancés, graphiques inspirés Ditto
// ============================================================
defined( 'ABSPATH' ) || exit;

// ── Tri chronologique des libellés de période ──────────────────
// ============================================================
// Le champ ACF "periode" est stocké comme un libellé humain en français
// (ex: "Avril 2026"), pas comme une date. Un simple ksort() trie ces
// chaînes par ordre ALPHABÉTIQUE ("Avril" < "Décembre" < "Février" < ...),
// ce qui casse complètement l'ordre chronologique des graphiques
// d'évolution (Performance Globale, Évolution Revenus F CFA, etc.).
// Cette fonction reconvertit le libellé en timestamp pour permettre un
// tri correct, sans avoir besoin de réimporter les rapports existants.
if ( ! function_exists( 'km_periode_label_to_ts' ) ) {
    function km_periode_label_to_ts( $label ) {
        $mois_fr = array(
            'janvier' => 1, 'février' => 2, 'fevrier' => 2, 'mars' => 3, 'avril' => 4,
            'mai' => 5, 'juin' => 6, 'juillet' => 7, 'août' => 8, 'aout' => 8,
            'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12, 'decembre' => 12,
        );
        $label_norm = trim( $label );
        // Format attendu : "Mois AAAA" (ex: "Avril 2026")
        if ( preg_match( '/^([A-Za-zÀ-ÿ]+)\s+(\d{4})$/u', $label_norm, $m ) ) {
            $mois_key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $m[1], 'UTF-8' ) : strtolower( $m[1] );
            if ( isset( $mois_fr[ $mois_key ] ) ) {
                return mktime( 0, 0, 0, $mois_fr[ $mois_key ], 1, (int) $m[2] );
            }
        }
        // Repli : si le format ne correspond pas (ancien format, donnée
        // inattendue), on retombe sur strtotime, et en dernier recours sur
        // PHP_INT_MAX pour pousser l'entrée non reconnue en fin de liste
        // plutôt que de fausser silencieusement le tri des dates connues.
        $ts = strtotime( $label_norm );
        return $ts !== false ? $ts : PHP_INT_MAX;
    }
}

// ── Tri d'un tableau associatif {libellé_periode => données} par ordre
//    chronologique réel, en s'appuyant sur km_periode_label_to_ts(). ──
if ( ! function_exists( 'km_ksort_periods' ) ) {
    function km_ksort_periods( &$timeline ) {
        uksort( $timeline, function( $a, $b ) {
            return km_periode_label_to_ts( $a ) <=> km_periode_label_to_ts( $b );
        } );
    }
}

add_shortcode( 'km_label_dashboard', 'km_render_label_dashboard' );

function km_render_label_dashboard() {

    if ( ! is_user_logged_in() ) {
        return '<script>window.location.replace(' . wp_json_encode( km_dashboard_url( 'connexion-artiste' ) ) . ');</script>';
    }
    $user = wp_get_current_user();
    if ( ! km_user_is_label_staff( $user ) ) {
        return '<div class="km-db-wrap"><div class="km-empty-state" style="margin:80px auto;text-align:center;"><p style="font-size:1.1rem;color:#FF4E6A;">🚫 Accès réservé au label.</p></div></div>';
    }

    $filter_artist = isset($_GET['artist']) ? intval($_GET['artist'])             : '';
    $filter_year   = isset($_GET['year'])   ? intval($_GET['year'])               : '';
    $filter_store  = isset($_GET['store'])  ? sanitize_text_field($_GET['store']) : '';
    $filter_album  = isset($_GET['album'])  ? sanitize_text_field($_GET['album']) : '';
    $filter_track  = isset($_GET['track'])  ? sanitize_text_field($_GET['track']) : '';

    // Taux de conversion EUR → XOF (F CFA) — taux fixe UEMOA
    if ( ! defined('KM_EUR_TO_XOF') ) define('KM_EUR_TO_XOF', 655.957);

    $all_rapports  = get_posts(array('post_type'=>'rapport_mensuel','posts_per_page'=>-1,'post_status'=>'publish','orderby'=>'date','order'=>'DESC'));
    $artists_users = get_users(array('role'=>'artiste_label','orderby'=>'display_name'));

    $global_streams  = 0; $global_gains = 0.0; $global_part_artiste = 0.0;
    $per_artist      = array();
    $per_platform    = array('spotify'=>0,'apple'=>0,'youtube'=>0,'deezer'=>0,'tidal'=>0);
    $per_platform_g  = array('spotify'=>0,'apple'=>0,'youtube'=>0,'deezer'=>0,'tidal'=>0);
    $timeline        = array();
    $years_available = array();
    $label_countries = array();
    $label_catalog   = array();
    $label_releases_agg = array(); // artiste_key → release → {type, streams, gains_brut, part_artiste, tracks}
    $label_tracks_agg   = array(); // artiste_key → track  → {album, type, streams, gains_brut, part_artiste, artist_name, platforms}

    foreach ( $all_rapports as $r ) {
        $artist_uid = (int) get_field('artiste_user_id', $r->ID);
        $tc_name    = get_field('tunecore_artist_name', $r->ID);
        if ( $artist_uid === 0 && $tc_name ) $artist_uid = km_get_artist_user_id($tc_name);
        $artist_u   = $artist_uid ? get_user_by('id',$artist_uid) : null;
        $artist_name = $artist_u ? $artist_u->display_name : ($tc_name ?: 'Artiste #'.$r->ID);
        $artist_profile_id = $artist_uid ? km_get_artist_profile_post($artist_uid) : null;
        if (!$artist_profile_id && $tc_name) {
            $tc_p = get_posts(array('post_type'=>'nos-artistes','posts_per_page'=>1,'post_status'=>'publish','meta_query'=>array(array('key'=>'tunecore_name','value'=>$tc_name,'compare'=>'LIKE'))));
            if ($tc_p) $artist_profile_id = $tc_p[0]->ID;
        }
        $artist_key = $artist_uid ? $artist_uid : 'tc_'.sanitize_title($tc_name);
        $periode    = get_field('periode',$r->ID) ?: get_the_title($r->ID);
        $year       = substr($periode,-4);
        $years_available[$year] = true;

        if ($filter_artist && $artist_uid !== intval($filter_artist)) continue;
        if ($filter_year   && $year !== strval($filter_year))         continue;

        $ts = (int)get_field('total_streams',$r->ID);
        if (!$ts) $ts = (int)get_post_meta($r->ID,'_km_total_streams',true);
        if (!$ts) { foreach(array('spotify','apple','youtube','deezer','tidal') as $_p) $ts += (int)get_field($_p.'_streams',$r->ID); }
        $tg = (float)get_field('total_gains_brut',$r->ID);
        if (!$tg) $tg = (float)get_post_meta($r->ID,'_km_total_gains',true);
        $pa = (float)get_field('part_artiste',$r->ID);
        if (!$pa) $pa = (float)get_post_meta($r->ID,'_km_part_artiste',true);

        $global_streams      += $ts;
        $global_gains        += $tg;
        $global_part_artiste += $pa;

        if (!isset($per_artist[$artist_key])) {
            $per_artist[$artist_key] = array('name'=>$artist_name,'streams'=>0,'gains_brut'=>0,'part_artiste'=>0,'rapports'=>0,'uid'=>$artist_uid,'profile_id'=>$artist_profile_id,'tc_name'=>$tc_name);
        }
        $per_artist[$artist_key]['streams']      += $ts;
        $per_artist[$artist_key]['gains_brut']   += $tg;
        $per_artist[$artist_key]['part_artiste'] += $pa;
        $per_artist[$artist_key]['rapports']     ++;

        foreach (array_keys($per_platform) as $p) {
            if ($filter_store && $p !== $filter_store) continue;
            $per_platform[$p]   += (int)  get_field($p.'_streams',$r->ID);
            $per_platform_g[$p] += (float)get_field($p.'_gains',  $r->ID);
        }

        if (!isset($timeline[$periode])) $timeline[$periode] = array('streams'=>0,'gains'=>0);
        $timeline[$periode]['streams'] += $ts;
        $timeline[$periode]['gains']   += $tg;

        // Pays
        $cd_raw = get_post_meta($r->ID,'_km_countries',true);
        if ($cd_raw) {
            $cd = json_decode($cd_raw,true);
            if (is_array($cd)) {
                foreach ($cd as $cc=>$cdata) {
                    if (!isset($label_countries[$cc])) $label_countries[$cc]=array('streams'=>0,'gains'=>0.0,'name'=>'');
                    $label_countries[$cc]['streams'] += (int)$cdata['streams'];
                    $label_countries[$cc]['gains']   += (float)$cdata['gains'];
                    $label_countries[$cc]['name']     = km_country_name($cc);
                }
            }
        }
        // Catalogue
        $cat_raw = get_post_meta($r->ID,'_km_catalog',true);
        if ($cat_raw) {
            $cat = json_decode($cat_raw,true);
            if (is_array($cat)) {
                foreach ($cat as $release=>$rdata) {
                    $key = $artist_key.'|||'.$release;
                    if (!isset($label_catalog[$key])) $label_catalog[$key]=array('artist'=>$tc_name,'release'=>$release,'type'=>$rdata['type'],'upc'=>isset($rdata['upc'])?$rdata['upc']:'','tracks'=>array());
                    foreach ((array)$rdata['tracks'] as $track) {
                        if (!in_array($track,$label_catalog[$key]['tracks'],true)) $label_catalog[$key]['tracks'][] = $track;
                    }
                    // Releases agrégées avec streams
                    $rel_agg_key = $artist_key.'|||'.$release;
                    if (!isset($label_releases_agg[$rel_agg_key])) {
                        $label_releases_agg[$rel_agg_key] = array('artist_name'=>$artist_name,'release'=>$release,'type'=>$rdata['type']??'Son','streams'=>0,'gains_brut'=>0.0,'part_artiste'=>0.0,'tracks'=>array());
                    }
                    $rel_s = intval($rdata['streams'] ?? 0);
                    $rel_g = floatval($rdata['gains']  ?? 0);
                    $r_commission = km_get_artist_commission($artist_uid);
                    // Utilise la part artiste pré-calculée à l'import (correcte
                    // pour les overrides par titre ET les répartitions multi-
                    // artistes) si disponible ; sinon repli sur l'ancien calcul.
                    $rel_part = isset($rdata['part_artiste']) ? (float) $rdata['part_artiste'] : ($rel_g * (1 - $r_commission));
                    $label_releases_agg[$rel_agg_key]['streams']      += $rel_s;
                    $label_releases_agg[$rel_agg_key]['gains_brut']   += $rel_g;
                    $label_releases_agg[$rel_agg_key]['part_artiste'] += $rel_part;
                    foreach ((array)$rdata['tracks'] as $track) {
                        if (!in_array($track,$label_releases_agg[$rel_agg_key]['tracks'],true)) $label_releases_agg[$rel_agg_key]['tracks'][] = $track;
                    }
                }
            }
        }
        // Pistes agrégées
        $tracks_raw = get_post_meta($r->ID,'_km_tracks',true);
        if ($tracks_raw) {
            $rtracks = json_decode($tracks_raw,true);
            if (is_array($rtracks)) {
                $r_commission = km_get_artist_commission($artist_uid);
                foreach ($rtracks as $tname => $td) {
                    $tk = $artist_key.'|||'.$tname;
                    if (!isset($label_tracks_agg[$tk])) {
                        $label_tracks_agg[$tk] = array('artist_name'=>$artist_name,'album'=>$td['album']??'','type'=>$td['type']??'Son','streams'=>0,'gains_brut'=>0.0,'part_artiste'=>0.0,'platforms'=>array());
                    }
                    $t_g = floatval($td['gains']??0);
                    $label_tracks_agg[$tk]['streams']      += intval($td['streams']??0);
                    $label_tracks_agg[$tk]['gains_brut']   += $t_g;
                    // Idem : utilise la part pré-calculée à l'import si disponible.
                    $label_tracks_agg[$tk]['part_artiste'] += isset($td['part_artiste']) ? (float) $td['part_artiste'] : ($t_g * (1 - $r_commission));
                    if (!empty($td['platform']) && is_array($td['platform'])) {
                        foreach ($td['platform'] as $pk2=>$pdata2) {
                            if (!isset($label_tracks_agg[$tk]['platforms'][$pk2])) $label_tracks_agg[$tk]['platforms'][$pk2] = array('streams'=>0,'gains'=>0.0);
                            $label_tracks_agg[$tk]['platforms'][$pk2]['streams'] += intval($pdata2['streams']??0);
                            $label_tracks_agg[$tk]['platforms'][$pk2]['gains']   += floatval($pdata2['gains']??0);
                        }
                    }
                }
            }
        }
    }

    krsort($years_available);
    uasort($label_countries, function($a,$b){return $b['streams']-$a['streams'];});
    $top_label_countries = array_slice($label_countries,0,20,true);
    uasort($per_artist, function($a,$b){return $b['streams']-$a['streams'];});

    $all_paiements = get_posts(array('post_type'=>'paiement','posts_per_page'=>-1,'post_status'=>'publish','orderby'=>'date','order'=>'DESC'));
    $global_payed  = 0.0;
    foreach ($all_paiements as $p) {
        if ($filter_artist && (int)get_field('artiste_user_id',$p->ID) !== $filter_artist) continue;
        if (get_field('statut_paiement',$p->ID)==='Payé') $global_payed += floatval(get_field('montant_paiement',$p->ID));
    }

    km_ksort_periods($timeline);
    $tl_labels  = array_keys($timeline);
    $tl_streams = array_column(array_values($timeline),'streams');
    $tl_gains   = array_column(array_values($timeline),'gains');

    $chart_artist_names   = array_column(array_values($per_artist),'name');
    $chart_artist_streams = array_column(array_values($per_artist),'streams');

    $total_artists  = wp_count_posts('nos-artistes')->publish;
    $total_releases = count($label_catalog);

    // Top pays pour graphique
    $top10cnt    = array_slice($label_countries,0,10,true);
    $js_cnt_lbl  = json_encode(array_values(array_map(function($v){return $v['name'];},$top10cnt)));
    $js_cnt_st   = json_encode(array_values(array_map(function($v){return $v['streams'];},$top10cnt)));

    $global_payed_xof  = round($global_payed * KM_EUR_TO_XOF);
    $global_gains_xof  = round($global_gains * KM_EUR_TO_XOF);
    $global_marge_xof  = round($global_marge * KM_EUR_TO_XOF);

    // Listes albums/pistes disponibles
    $label_albums = array();
    $label_tracks = array();
    foreach ($label_catalog as $key => $cdata) {
        if (!in_array($cdata['release'], $label_albums, true)) $label_albums[] = $cdata['release'];
        foreach ($cdata['tracks'] as $t) {
            if (!in_array($t, $label_tracks, true)) $label_tracks[] = $t;
        }
    }
    sort($label_albums);
    sort($label_tracks);

    $logo_url = defined('KM_LOGO_URL') ? KM_LOGO_URL : '';

    // Marge label
    $global_marge = $global_gains - $global_part_artiste;

    ob_start(); ?>
<div id="km-label-db" class="km-db-wrap km-db-label">

<!-- ═══════════════════════════════════════════════════════════════
     HEADER LABEL
═══════════════════════════════════════════════════════════════ -->
<header class="km-db-header">
    <div class="km-db-header-left">
        <?php if ($logo_url) : ?>
        <div class="km-header-logo-wrap">
            <img src="<?php echo esc_url($logo_url); ?>" alt="KOPHI'S MUSIC" />
        </div>
        <?php else : ?>
        <div class="km-header-logo-fallback">KM</div>
        <?php endif; ?>
        <div class="km-db-user-info">
            <span class="km-db-label-tag">TABLEAU DE BORD LABEL</span>
            <span class="km-db-artist-name">KOPHI'S MUSIC</span>
            <span class="km-db-role-tag">Vue d'ensemble &nbsp;·&nbsp; Label Urban Gospel</span>
        </div>
    </div>
    <div class="km-db-header-right">
        <a href="https://kophismusic.com/" class="km-btn-icon" title="Retour au site">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        </a>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=rapport_mensuel')); ?>" class="km-btn-releve">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            Rapports
        </a>
        <button class="km-btn-pdf" onclick="kmLabelExportPDF()">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
            Export PDF
        </button>
        <a href="<?php echo esc_url(wp_logout_url(home_url('/connexion-artiste/'))); ?>" class="km-btn-logout">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
            Déconnexion
        </a>
    </div>
</header>

<!-- ═══════════════════════════════════════════════════════════════
     FILTRES AVANCÉS
═══════════════════════════════════════════════════════════════ -->
<div class="km-filters-bar">
    <form method="get" id="km-label-filters">
        <div class="km-filters-row">
            <div class="km-filter-group">
                <label>🎤 Artiste</label>
                <select name="artist" onchange="this.form.submit()">
                    <option value="">Tous les artistes</option>
                    <?php foreach ($artists_users as $au) : ?>
                    <option value="<?php echo $au->ID; ?>" <?php selected($filter_artist,$au->ID); ?>><?php echo esc_html($au->display_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="km-filter-group">
                <label>📅 Année</label>
                <select name="year" onchange="this.form.submit()">
                    <option value="">Toutes les années</option>
                    <?php foreach (array_keys($years_available) as $y) : ?>
                    <option value="<?php echo $y; ?>" <?php selected($filter_year,$y); ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="km-filter-group">
                <label>📱 Plateforme</label>
                <select name="store" onchange="this.form.submit()">
                    <option value="">Toutes les plateformes</option>
                    <?php foreach (array('spotify'=>'Spotify','apple'=>'Apple Music','youtube'=>'YouTube','deezer'=>'Deezer','tidal'=>'Tidal') as $sk=>$sn) : ?>
                    <option value="<?php echo $sk; ?>" <?php selected($filter_store,$sk); ?>><?php echo $sn; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filter_artist || $filter_year || $filter_store) : ?>
            <div class="km-filter-group km-filter-reset">
                <label>&nbsp;</label>
                <a href="<?php echo esc_url(get_permalink()); ?>" class="km-btn-reset">✕ Tout afficher</a>
            </div>
            <?php endif; ?>
            <div class="km-filter-group">
                <label>💿 Album / Sortie</label>
                <select name="album" onchange="this.form.submit()">
                    <option value="">Tous les albums</option>
                    <?php foreach ($label_albums as $alb) : ?>
                    <option value="<?php echo esc_attr($alb); ?>" <?php selected($filter_album,$alb); ?>><?php echo esc_html($alb); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="km-filter-group">
                <label>🎤 Morceau</label>
                <select name="track" onchange="this.form.submit()">
                    <option value="">Tous les morceaux</option>
                    <?php foreach ($label_tracks as $trk) : ?>
                    <option value="<?php echo esc_attr($trk); ?>" <?php selected($filter_track,$trk); ?>><?php echo esc_html($trk); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filter_album || $filter_track) : ?>
            <div class="km-filter-group km-filter-reset">
                <label>&nbsp;</label>
                <a href="<?php echo esc_url(get_permalink()); ?>" class="km-btn-reset">✕ Tout afficher</a>
            </div>
            <?php endif; ?>
            <div class="km-filter-summary">
                <span class="km-summary-label"><?php echo count($per_artist); ?> artistes</span>
                <span class="km-summary-sep">•</span>
                <span class="km-summary-streams"><?php echo number_format($global_streams,0,',',' '); ?> streams</span>
                <span class="km-summary-sep">•</span>
                <span class="km-summary-gains"><?php echo number_format($global_gains,4,',',''); ?> € bruts</span>
            </div>
        </div>
    </form>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     KPI GRID — 5 indicateurs label
═══════════════════════════════════════════════════════════════ -->
<div class="km-kpi-grid km-kpi-grid-5">

    <div class="km-kpi-card km-kpi-gold">
        <div class="km-kpi-icon-wrap">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/></svg>
        </div>
        <div class="km-kpi-body">
            <span class="km-kpi-label">Total Streams</span>
            <span class="km-kpi-value km-counter" data-target="<?php echo intval($global_streams); ?>">0</span>
            <span class="km-kpi-sub"><?php echo count($all_rapports); ?> rapports</span>
        </div>
    </div>

    <div class="km-kpi-card km-kpi-green">
        <div class="km-kpi-icon-wrap">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="km-kpi-body">
            <span class="km-kpi-label">Revenus Bruts</span>
            <span class="km-kpi-value km-counter-float" data-target="<?php echo esc_attr($global_gains); ?>">0,0000 €</span>
            <span class="km-kpi-xof"><?php echo number_format($global_gains_xof, 0, ',', ' '); ?> F CFA</span>
            <span class="km-kpi-sub">Marge label : <?php echo number_format($global_marge,4,',',''); ?> € (<?php echo number_format($global_marge_xof,0,',',' '); ?> F CFA)</span>
        </div>
    </div>

    <div class="km-kpi-card">
        <div class="km-kpi-icon-wrap">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
        </div>
        <div class="km-kpi-body">
            <span class="km-kpi-label">Total Versé</span>
            <span class="km-kpi-value"><?php echo number_format($global_payed,2,',',''); ?> €</span>
            <span class="km-kpi-xof"><?php echo number_format($global_payed_xof, 0, ',', ' '); ?> F CFA</span>
            <span class="km-kpi-sub"><?php echo count($all_paiements); ?> paiement(s)</span>
        </div>
    </div>

    <div class="km-kpi-card">
        <div class="km-kpi-icon-wrap">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
        </div>
        <div class="km-kpi-body">
            <span class="km-kpi-label">Artistes Actifs</span>
            <span class="km-kpi-value"><?php echo count($per_artist); ?></span>
            <span class="km-kpi-sub"><?php echo $total_artists; ?> au catalogue</span>
        </div>
    </div>

    <div class="km-kpi-card">
        <div class="km-kpi-icon-wrap">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
        </div>
        <div class="km-kpi-body">
            <span class="km-kpi-label">Sorties Catalogue</span>
            <span class="km-kpi-value"><?php echo count($label_catalog); ?></span>
            <span class="km-kpi-sub"><?php echo count($label_countries); ?> pays atteints</span>
        </div>
    </div>
</div>

<?php
/**
 * PONT KM FAMILY — récapitulatif des soutiens communautaires par artiste.
 * Rendu par includes/km-family-bridge.php ; silencieux si KM Family est absent.
 */
do_action( 'km_label_dashboard_after_kpi' );
?>

<!-- ═══════════════════════════════════════════════════════════════
     PLATEFORMES STRIP
═══════════════════════════════════════════════════════════════ -->
<div class="km-platform-strip">
    <?php foreach ($per_platform as $pk => $pst) : ?>
    <div class="km-plat-pill km-plat-pill--<?php echo $pk; ?>" data-platform="<?php echo esc_attr($pk); ?>">
        <div class="km-plat-icon km-plat-icon--<?php echo $pk; ?>"><?php echo km_platform_icon($pk,20); ?></div>
        <div class="km-plat-info">
            <span class="km-plat-name"><?php echo km_platform_name($pk); ?></span>
            <span class="km-plat-value"><?php echo number_format($pst,0,',',' '); ?></span>
            <?php if ($global_streams > 0) : ?>
            <div class="km-plat-bar"><div class="km-plat-bar-fill" style="width:<?php echo round($pst/max(1,$global_streams)*100); ?>%"></div></div>
            <?php endif; ?>
        </div>
        <div class="km-plat-gains"><?php echo number_format($per_platform_g[$pk],3,',',''); ?> €</div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     GRAPHIQUES — style Ditto Music (timeline grande + 3 petits)
═══════════════════════════════════════════════════════════════ -->
<div class="km-charts-row-main">
    <!-- Timeline label — barres + ligne (style Ditto) -->
    <div class="km-chart-card km-chart-main">
        <div class="km-chart-header">
            <div>
                <h4 class="km-chart-title">Performance Globale du Label</h4>
                <p class="km-chart-sub">Streams et gains sur toute la période</p>
            </div>
            <div class="km-chart-legend">
                <span class="km-legend-item"><span class="km-legend-dot" style="background:#F5A623;"></span> Streams</span>
                <span class="km-legend-item"><span class="km-legend-dot" style="background:#00D67F;"></span> Gains</span>
            </div>
        </div>
        <?php if (count($tl_labels)>0) : ?>
        <div class="km-chart-canvas-wrap"><canvas id="km-chart-label-timeline"></canvas></div>
        <?php else : ?>
        <div class="km-chart-empty"><p>Importez un CSV TuneCore pour voir les données</p></div>
        <?php endif; ?>
    </div>

    <!-- Colonne droite -->
    <div class="km-chart-col-right">
        <!-- Résumé rapport mensuel style Ditto -->
        <div class="km-monthly-card">
            <div class="km-monthly-label">Rapport Label</div>
            <div class="km-monthly-periode">Dernière période importée</div>
            <div class="km-monthly-gains"><?php echo number_format($global_gains,4,',',''); ?> <span>€</span></div>
            <div class="km-kpi-xof" style="margin-bottom:5px;"><?php echo number_format($global_gains_xof, 0, ',', ' '); ?> F CFA</div>
            <div class="km-monthly-streams"><?php echo number_format($global_streams,0,',',' '); ?> streams cumulés</div>
            <div class="km-monthly-brut">
                <span>Part artistes : <?php echo number_format($global_part_artiste,4,',',''); ?> €</span>
                <span>Marge label : <?php echo number_format($global_marge,4,',',''); ?> €</span>
            </div>
        </div>

        <!-- Donut répartition artistes -->
        <div class="km-chart-card km-chart-donut-card">
            <div class="km-chart-header">
                <h4 class="km-chart-title">Streams par Artiste</h4>
            </div>
            <?php if (array_sum($chart_artist_streams)>0) : ?>
            <div class="km-chart-canvas-wrap km-donut-wrap"><canvas id="km-chart-artist-split"></canvas></div>
            <?php else : ?>
            <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 2ème ligne graphiques -->
<div class="km-charts-row-secondary">
    <div class="km-chart-card">
        <div class="km-chart-header">
            <h4 class="km-chart-title">Streams par Plateforme</h4>
        </div>
        <?php if (array_sum($per_platform)>0) : ?>
        <div class="km-chart-canvas-wrap"><canvas id="km-chart-label-platforms"></canvas></div>
        <?php else : ?>
        <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée</p></div>
        <?php endif; ?>
    </div>
    <div class="km-chart-card">
        <div class="km-chart-header">
            <h4 class="km-chart-title">Top Pays d'écoute</h4>
        </div>
        <?php if ($top_label_countries) : ?>
        <div class="km-chart-canvas-wrap"><canvas id="km-chart-label-countries"></canvas></div>
        <?php else : ?>
        <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée pays</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- 3ème ligne graphiques — Secteur + Courbe XOF + Radar -->
<?php
$js_lbl_gains_pie   = json_encode(array($global_part_artiste, $global_marge));
$js_lbl_tl_gains_xof= json_encode(array_map(function($v){ return round($v * KM_EUR_TO_XOF); }, $tl_gains));
?>
<div class="km-charts-row-3">

    <!-- Pie gains artiste vs label -->
    <div class="km-chart-card">
        <div class="km-chart-header">
            <div>
                <h4 class="km-chart-title">Répartition Revenus</h4>
                <p class="km-chart-sub">Part artistes vs marge label</p>
            </div>
        </div>
        <?php if ($global_gains > 0) : ?>
        <div class="km-chart-canvas-wrap" style="height:200px;"><canvas id="km-chart-label-pie"></canvas></div>
        <?php else : ?>
        <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée</p></div>
        <?php endif; ?>
    </div>

    <!-- Courbe XOF timeline -->
    <div class="km-chart-card">
        <div class="km-chart-header">
            <div>
                <h4 class="km-chart-title">Évolution Revenus (F CFA)</h4>
                <p class="km-chart-sub">Équivalent en Francs CFA</p>
            </div>
        </div>
        <?php if (count($tl_labels) > 0) : ?>
        <div class="km-chart-canvas-wrap"><canvas id="km-chart-label-xof"></canvas></div>
        <?php else : ?>
        <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée</p></div>
        <?php endif; ?>
    </div>

    <!-- Radar par plateforme -->
    <div class="km-chart-card">
        <div class="km-chart-header">
            <div>
                <h4 class="km-chart-title">Répartition Streams</h4>
                <p class="km-chart-sub">Vue radar plateformes</p>
            </div>
        </div>
        <?php if (array_sum($per_platform) > 0) : ?>
        <div class="km-chart-canvas-wrap" style="height:200px;"><canvas id="km-chart-label-radar"></canvas></div>
        <?php else : ?>
        <div class="km-chart-empty km-chart-empty-sm"><p>Aucune donnée</p></div>
        <?php endif; ?>
    </div>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     CLASSEMENT ARTISTES — cards premium
═══════════════════════════════════════════════════════════════ -->
<div class="km-section">
    <div class="km-section-header">
        <div>
            <h3>🏆 Classement Artistes</h3>
            <span class="km-section-sub"><?php echo count($per_artist); ?> artistes actifs</span>
        </div>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=rapport_mensuel')); ?>" class="km-link-badge">Tous les rapports →</a>
    </div>
    <div class="km-artist-ranking">
    <?php $rank=1; foreach ($per_artist as $akey => $adata) :
        $uid_com = is_int($akey) ? $akey : $adata['uid'];
        $com     = km_get_artist_commission($uid_com);
        $pct_a   = round((1-$com)*100,1);
        $pct_l   = round($com*100,1);
        $ap_id   = isset($adata['profile_id']) ? $adata['profile_id'] : null;
        $aphoto  = $ap_id ? get_the_post_thumbnail_url($ap_id,'thumbnail') : '';
        $aavatar = $adata['uid'] ? get_avatar_url($adata['uid'],array('size'=>56,'default'=>'identicon')) : '';
        $aimg    = $aphoto ?: $aavatar;
        $pct_of_total = $global_streams > 0 ? round($adata['streams']/$global_streams*100,1) : 0;
    ?>
    <div class="km-artist-rank-card <?php echo $rank===1?'km-rank-1':($rank===2?'km-rank-2':($rank===3?'km-rank-3':'')); ?>">
        <div class="km-rank-badge"><?php echo $rank; ?></div>
        <div class="km-rank-avatar">
            <?php if ($aimg) : ?>
            <img src="<?php echo esc_url($aimg); ?>" alt="<?php echo esc_attr($adata['name']); ?>" />
            <?php else : ?>
            <span><?php echo strtoupper(substr($adata['name'],0,1)); ?></span>
            <?php endif; ?>
        </div>
        <div class="km-rank-info">
            <strong class="km-rank-name"><?php echo esc_html($adata['name']); ?></strong>
            <div class="km-rank-meta">
                <span><?php echo $adata['rapports']; ?> rapport(s)</span>
                <span class="km-rank-sep">·</span>
                <span>Artiste <?php echo $pct_a; ?>% / Label <?php echo $pct_l; ?>%</span>
            </div>
            <div class="km-rank-bar-wrap">
                <div class="km-rank-bar" style="width:<?php echo $pct_of_total; ?>%"></div>
            </div>
        </div>
        <div class="km-rank-stats">
            <div class="km-rank-streams"><?php echo number_format($adata['streams'],0,',',' '); ?></div>
            <div class="km-rank-gains"><?php echo number_format($adata['gains_brut'],4,',',''); ?> €</div>
            <div class="km-rank-pct"><?php echo $pct_of_total; ?>% du total</div>
        </div>
    </div>
    <?php $rank++; endforeach; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     DÉTAIL PAR ARTISTE & CATALOGUE — 3 Tabs
═══════════════════════════════════════════════════════════════ -->
<?php
uasort($label_releases_agg, function($a,$b){ return $b['streams'] - $a['streams']; });
uasort($label_tracks_agg,   function($a,$b){ return $b['streams'] - $a['streams']; });
$label_tab_platforms = array_keys(array_filter($per_platform, function($v){ return $v > 0; }));
if (!$label_tab_platforms) $label_tab_platforms = array('apple','youtube','deezer','tidal');
?>
<div class="km-section km-section-tabs-wrapper">
    <div class="km-section-header">
        <div>
            <h3>📊 Détail Artistes & Catalogue</h3>
            <span class="km-section-sub">Releases, pistes et pays par artiste</span>
        </div>
        <div class="km-table-filters">
            <select id="km-tf-artist" onchange="kmTableFilterLabel()" class="km-tf-select">
                <option value="">Tous les artistes</option>
                <?php foreach ($per_artist as $akey=>$adata) : ?>
                <option value="<?php echo esc_attr($adata['name']); ?>"><?php echo esc_html($adata['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button onclick="kmTableResetLabel()" class="km-tf-reset">✕</button>
        </div>
    </div>

    <!-- Tabs -->
    <div class="km-tab-bar">
        <button class="km-tab-btn km-tab-active" onclick="kmSwitchTab(this,'km-lbl-sorties')">🎵 SORTIE(S)</button>
        <button class="km-tab-btn" onclick="kmSwitchTab(this,'km-lbl-titres')">🎶 TITRES (PISTE)</button>
        <button class="km-tab-btn" onclick="kmSwitchTab(this,'km-lbl-pays')">🌍 PAYS</button>
    </div>

    <!-- ══ BLOC SORTIE(S) ══ -->
    <div id="km-lbl-sorties" class="km-tab-panel km-tab-block">
        <div class="km-tab-block-title">SORTIE(S)</div>
        <div class="km-tab-block-body">
        <div class="km-reports-table-wrap">
        <table class="km-reports-table" id="km-detail-table">
            <thead><tr>
                <th>ARTISTE</th>
                <th>SORTIE</th>
                <th>TYPE</th>
                <th>STREAMS</th>
                <?php foreach ($label_tab_platforms as $pk) : ?>
                <th><span class="km-th-plat" style="border-left:2px solid <?php echo km_platform_color($pk); ?>;"><?php echo km_platform_name($pk); ?></span></th>
                <?php endforeach; ?>
                <th>GAINS BRUTS</th>
                <th>PART ARTISTE</th>
                <th>F CFA</th>
            </tr></thead>
            <tbody>
            <?php foreach ($label_releases_agg as $rkey => $rel) :
                $rel_xof = round($rel['part_artiste'] * KM_EUR_TO_XOF);
            ?>
            <tr data-artist="<?php echo esc_attr($rel['artist_name']); ?>">
                <td>
                    <div class="km-td-artist">
                        <strong style="color:#fff;"><?php echo esc_html($rel['artist_name']); ?></strong>
                    </div>
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span class="km-release-dot"></span>
                        <strong style="color:#fff;"><?php echo esc_html($rel['release']); ?></strong>
                    </div>
                    <?php if ($rel['tracks']): ?>
                    <div class="km-tracks-list" style="margin-top:4px;">
                    <?php foreach ($rel['tracks'] as $ti=>$t): ?>
                        <div class="km-cell-track"><span class="km-track-num-sm"><?php echo str_pad($ti+1,2,'0',STR_PAD_LEFT); ?></span><?php echo esc_html($t); ?></div>
                    <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td><span class="km-badge-type"><?php echo esc_html($rel['type']); ?></span></td>
                <td class="km-num"><strong><?php echo number_format($rel['streams'],0,',',' '); ?></strong></td>
                <?php foreach ($label_tab_platforms as $pk):
                    $plat_s = 0;
                    foreach ($label_tracks_agg as $tk => $td) {
                        if ($td['album'] === $rel['release'] && isset($td['platforms'][$pk]))
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
            <?php if (!$label_releases_agg): ?><tr><td colspan="9" style="text-align:center;color:var(--km-text-dim);padding:20px;">Aucune sortie — importez un CSV TuneCore</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>

    <!-- ══ BLOC TITRES (PISTE) ══ -->
    <div id="km-lbl-titres" class="km-tab-panel km-tab-block" style="display:none;">
        <div class="km-tab-block-title">TITRES (PISTE)</div>
        <div class="km-tab-block-body">
        <div class="km-reports-table-wrap">
        <table class="km-reports-table">
            <thead><tr>
                <th>#</th>
                <th>ARTISTE</th>
                <th>TITRE</th>
                <th>ALBUM / SORTIE</th>
                <th>TYPE</th>
                <th>STREAMS</th>
                <?php foreach ($label_tab_platforms as $pk) : ?>
                <th><span class="km-th-plat" style="border-left:2px solid <?php echo km_platform_color($pk); ?>;"><?php echo km_platform_name($pk); ?></span></th>
                <?php endforeach; ?>
                <th>GAINS BRUTS</th>
                <th>PART ARTISTE</th>
                <th>F CFA</th>
            </tr></thead>
            <tbody>
            <?php $ti=1; foreach ($label_tracks_agg as $tk => $td):
                $t_xof = round($td['part_artiste'] * KM_EUR_TO_XOF);
                list($akey2, $tname2) = explode('|||',$tk,2);
            ?>
            <tr data-artist="<?php echo esc_attr($td['artist_name']); ?>">
                <td style="color:var(--km-text-dim);font-family:'JetBrains Mono',monospace;font-size:.68rem;"><?php echo str_pad($ti,2,'0',STR_PAD_LEFT); ?></td>
                <td style="color:var(--km-text-dim);font-size:.74rem;"><?php echo esc_html($td['artist_name']); ?></td>
                <td><strong style="color:#fff;"><?php echo esc_html($tname2); ?></strong></td>
                <td style="color:var(--km-text-dim);"><?php echo esc_html($td['album']); ?></td>
                <td><span class="km-badge-type"><?php echo esc_html($td['type']); ?></span></td>
                <td class="km-num"><strong><?php echo number_format($td['streams'],0,',',' '); ?></strong></td>
                <?php foreach ($label_tab_platforms as $pk):
                    $ps = isset($td['platforms'][$pk]) ? $td['platforms'][$pk]['streams'] : 0;
                ?>
                <td class="km-num"><?php echo $ps ? number_format($ps,0,',',' ') : '<span class="km-num-zero">—</span>'; ?></td>
                <?php endforeach; ?>
                <td class="km-num"><?php echo number_format($td['gains_brut'],4,',',''); ?> €</td>
                <td class="km-gains-cell"><?php echo number_format($td['part_artiste'],4,',',''); ?> €</td>
                <td class="km-num" style="color:var(--km-primary);font-size:.7rem;"><?php echo number_format($t_xof,0,',',' '); ?> F</td>
            </tr>
            <?php $ti++; endforeach; ?>
            <?php if (!$label_tracks_agg): ?><tr><td colspan="11" style="text-align:center;color:var(--km-text-dim);padding:20px;">Aucune piste — réimportez le CSV TuneCore</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>

    <!-- ══ BLOC PAYS ══ -->
    <div id="km-lbl-pays" class="km-tab-panel km-tab-block" style="display:none;">
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
            <?php $rank=1; $total_lbl_cnt = array_sum(array_column($label_countries,'streams'));
            foreach ($top_label_countries as $cc=>$cdata):
                $pct = $total_lbl_cnt > 0 ? round($cdata['streams']/$total_lbl_cnt*100,1) : 0;
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
            <?php if (!$top_label_countries): ?><tr><td colspan="5" style="text-align:center;color:var(--km-text-dim);padding:20px;">Aucune donnée pays</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     AUDIENCE MONDIALE
═══════════════════════════════════════════════════════════════ -->
<?php if ($top_label_countries) : ?>
<div class="km-section km-section-countries">
    <div class="km-section-header">
        <div>
            <h3>🌍 Audience Mondiale</h3>
            <span class="km-section-sub"><?php echo count($label_countries); ?> pays atteints</span>
        </div>
    </div>
    <div class="km-countries-grid">
    <?php $rank=0; foreach ($top_label_countries as $cc=>$cdata) : $rank++;
        $pct = $top_label_countries ? round($cdata['streams']/max(1,reset($top_label_countries)['streams'])*100) : 0;
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

<!-- ═══════════════════════════════════════════════════════════════
     CATALOGUE LABEL — filtres JS dynamiques
═══════════════════════════════════════════════════════════════ -->
<?php if ($label_catalog) :
    $catalog_artists_list = array();
    foreach ($label_catalog as $item) {
        if (!in_array($item['artist'],$catalog_artists_list,true)) $catalog_artists_list[] = $item['artist'];
    }
?>
<div class="km-section km-section-catalog">
    <div class="km-section-header">
        <div>
            <h3>🎵 Catalogue du Label</h3>
            <span class="km-section-sub"><?php echo count($label_catalog); ?> sorties</span>
        </div>
        <div class="km-table-filters">
            <select class="km-catalog-filter-select km-tf-select" onchange="kmFilterCatalog(this.value,'type')">
                <option value="">Tous types</option>
                <option value="Son">Singles</option>
                <option value="Album">Albums</option>
                <option value="EP">EPs</option>
            </select>
            <select class="km-catalog-filter-select km-tf-select" onchange="kmFilterCatalog(this.value,'artist')">
                <option value="">Tous artistes</option>
                <?php foreach ($catalog_artists_list as $ca) : ?>
                <option value="<?php echo esc_attr($ca); ?>"><?php echo esc_html($ca); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="km-catalog-grid" id="km-catalog-grid">
    <?php
    foreach ($label_catalog as $item) :
        $cover_url2 = km_get_release_cover($item['release'], $item['upc']);
        $card_id2   = 'km-lbl-cat-'.sanitize_title($item['release']).'-'.sanitize_title($item['artist']).'-'.substr(md5($item['release'].$item['artist']),0,5);
    ?>
        <div class="km-catalog-card" id="<?php echo esc_attr($card_id2); ?>"
             data-type="<?php echo esc_attr($item['type']); ?>"
             data-artist="<?php echo esc_attr($item['artist']); ?>"
             onclick="kmToggleCatalogCard('<?php echo esc_js($card_id2); ?>')"
             role="button" tabindex="0">
            <!-- Cover carrée -->
            <div class="km-catalog-artwork">
                <?php if ($cover_url2): ?>
                <img src="<?php echo esc_url($cover_url2); ?>" alt="<?php echo esc_attr($item['release']); ?>" loading="lazy" />
                <?php else: ?>
                <div class="km-catalog-artwork-placeholder">
                    <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="rgba(245,166,35,.45)" stroke-width="1">
                        <circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3.5" fill="rgba(245,166,35,.3)" stroke="none"/>
                        <circle cx="12" cy="12" r="1" fill="rgba(245,166,35,.7)" stroke="none"/>
                    </svg>
                </div>
                <?php endif; ?>
                <span class="km-cover-type-badge"><?php echo esc_html($item['type']); ?></span>
                <div class="km-cover-expand-icon">
                    <svg width="22" height="22" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24" opacity=".9"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>
            <!-- Titre + artiste -->
            <div class="km-catalog-info">
                <span class="km-catalog-title"><?php echo esc_html($item['release']); ?></span>
                <div class="km-catalog-meta-row">
                    <span class="km-catalog-artist"><?php echo esc_html($item['artist']); ?></span>
                    <?php if ($item['upc']): ?><span class="km-catalog-upc" style="margin-left:auto;">UPC <?php echo esc_html($item['upc']); ?></span><?php endif; ?>
                </div>
                <div style="margin-top:3px;"><span class="km-catalog-count"><?php echo count($item['tracks']); ?> piste(s)</span></div>
            </div>
            <!-- Pistes dépliables -->
            <?php if ($item['tracks']): ?>
            <div class="km-catalog-tracks">
                <?php foreach ($item['tracks'] as $ti=>$track): ?>
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

<!-- ═══════════════════════════════════════════════════════════════
     PAIEMENTS RÉCENTS
═══════════════════════════════════════════════════════════════ -->
<div class="km-section km-section-paiements">
    <div class="km-section-header">
        <div><h3>💳 Paiements Récents</h3></div>
        <a href="<?php echo esc_url(admin_url('edit.php?post_type=paiement')); ?>" class="km-link-badge">Gérer →</a>
    </div>
    <?php if ($all_paiements) : ?>
    <div class="km-reports-table-wrap">
        <table class="km-reports-table">
            <thead>
                <tr><th>Date</th><th>Artiste</th><th>Montant</th><th>Méthode</th><th>Statut</th><th>Document</th></tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($all_paiements,0,15) as $p) :
                $paid_uid = (int)get_field('artiste_user_id',$p->ID);
                if ($filter_artist && $paid_uid !== $filter_artist) continue;
                $paid_u = get_user_by('id',$paid_uid);
                $statut = get_field('statut_paiement',$p->ID);
                $cls    = $statut==='Payé' ? 'km-badge-paye' : ($statut==='Annulé' ? 'km-badge-annule' : 'km-badge-attente');
                $pdf    = get_field('releve_pdf',$p->ID);
            ?>
            <tr>
                <td><?php echo esc_html(get_field('date_paiement',$p->ID)); ?></td>
                <td><?php echo esc_html($paid_u ? $paid_u->display_name : 'N/A'); ?></td>
                <td><strong class="km-gains-cell"><?php echo number_format((float)get_field('montant_paiement',$p->ID),2,',',''); ?> €</strong></td>
                <td><?php echo esc_html(get_field('methode_paiement',$p->ID)); ?></td>
                <td><span class="km-badge <?php echo $cls; ?>"><?php echo esc_html($statut); ?></span></td>
                <td><?php echo $pdf ? '<a href="'.esc_url($pdf['url']).'" target="_blank" class="km-pdf-link">📄 PDF</a>' : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else : ?>
    <div class="km-empty-state"><svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1" opacity=".3"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg><p>Aucun paiement enregistré.</p></div>
    <?php endif; ?>
</div>

<!-- ═══════════ COPYRIGHT ════════════════════════════════════════ -->
<div class="km-db-footer" style="display:block!important;visibility:visible!important;opacity:1!important;position:relative!important;z-index:999!important;margin:16px 0 0!important;background:rgba(0,2,18,.95)!important;border-top:2px solid rgba(245,166,35,.25)!important;width:100%!important;box-sizing:border-box!important;">
    <div style="display:flex!important;align-items:center;justify-content:space-between;padding:11px 28px;gap:12px;flex-wrap:wrap;">
        <div><?php if ($logo_url) : ?><img src="<?php echo esc_url($logo_url); ?>" alt="KOPHI'S MUSIC" style="height:20px;width:auto;opacity:.6;display:block;" /><?php endif; ?></div>
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

</div><!-- #km-label-db -->

<script>
// ── Tab switcher ──────────────────────────────────────────────
function kmSwitchTab(btn, targetId) {
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
}

// ── Catalogue — toggle carte (cover click) ────────────────────
function kmToggleCatalogCard(cardId) {
    var card = document.getElementById(cardId);
    if (!card) return;
    card.classList.toggle('km-card-open');
    var icon = card.querySelector('.km-cover-expand-icon svg');
    if (icon) icon.style.transform = card.classList.contains('km-card-open') ? 'rotate(180deg)' : 'rotate(0deg)';
}

document.addEventListener('DOMContentLoaded',function(){
    if(typeof Chart==='undefined') return;
    Chart.defaults.color='#C8D6E8';
    Chart.defaults.font.family="'Space Grotesk',sans-serif";
    Chart.defaults.font.size=12;

    document.querySelectorAll('.km-counter').forEach(function(el){
        var t=parseInt(el.dataset.target)||0,c=0,inc=t/80;
        if(!t){el.textContent='0';return;}
        var tm=setInterval(function(){c=Math.min(c+inc,t);el.textContent=Math.floor(c).toLocaleString('fr-FR');if(c>=t)clearInterval(tm);},16);
    });
    document.querySelectorAll('.km-counter-float').forEach(function(el){
        var t=parseFloat(el.dataset.target)||0,c=0,inc=t/80;
        if(!t){el.textContent='0,0000 €';return;}
        var tm=setInterval(function(){c=Math.min(c+inc,t);el.textContent=c.toFixed(4).replace('.',',')+' €';if(c>=t)clearInterval(tm);},16);
    });

    /* ── Style de tooltip premium partagé par tous les graphiques :
       carte flottante à coins arrondis, ombre douce, padding généreux. ── */
    var kmTooltipBase = {
        backgroundColor:'rgba(8,11,22,.97)',
        titleColor:'#F5F8FF',
        bodyColor:'#C8D6E8',
        borderColor:'rgba(245,166,35,.25)',
        borderWidth:1,
        padding:{top:10,bottom:10,left:13,right:13},
        cornerRadius:10,
        displayColors:true,
        boxPadding:5,
        titleFont:{weight:'700',size:12},
        bodyFont:{size:12},
        caretSize:6
    };

    /* ── Crée un gradient vertical adapté à la hauteur réelle du canvas.
       Chart.js rappelle ce callback à chaque resize, donc le dégradé
       reste cohérent en responsive (pas de bandes figées). ── */
    function kmVGradient(ctx, chartArea, stops){
        if(!chartArea) return stops[stops.length-1][1];
        var g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        stops.forEach(function(s){ g.addColorStop(s[0], s[1]); });
        return g;
    }

    document.querySelectorAll('.km-counter').forEach(function(el){});

    var tlLabels=<?php echo json_encode($tl_labels); ?>;
    var tlStreams=<?php echo json_encode($tl_streams); ?>;
    var tlGains=<?php echo json_encode($tl_gains); ?>;
    var artistNames=<?php echo json_encode($chart_artist_names); ?>;
    var artistStreams=<?php echo json_encode($chart_artist_streams); ?>;
    var platStreams=<?php echo json_encode(array_values($per_platform)); ?>;
    var platLabels=['Spotify','Apple Music','YouTube','Deezer','Tidal'];
    var platColors=['#1DB954','#FF4F8B','#FF3B30','#A238FF','#00CFFF'];
    var multiColors=['#F5A623','#00D67F','#FF6B6B','#A238FF','#00CFFF','#FFB347','#4ECDC4'];
    var cntLabels=<?php echo $js_cnt_lbl; ?>;
    var cntStreams=<?php echo $js_cnt_st; ?>;
    var cntPalette=['#F4B942','#00E589','#4C78FF','#FF5A6E','#A238FF','#00CFFF','#FF8C42','#1DB954','#FC3C44','#FFD166','#06D6A0','#EF476F','#118AB2','#9B5DE5','#F15BB5','#FEE440','#00BBF9','#00F5D4','#FF9F1C','#8338EC'];

    /* Timeline label — barres + ligne style Ditto — ordre chronologique */
    if(tlLabels.length>0 && document.getElementById('km-chart-label-timeline')){
        new Chart(document.getElementById('km-chart-label-timeline'),{
            type:'bar',
            data:{labels:tlLabels,datasets:[
                {label:'Streams',data:tlStreams,
                    backgroundColor:function(c){
                        return kmVGradient(c.chart.ctx, c.chart.chartArea, [[0,'rgba(255,200,90,.85)'],[1,'rgba(245,166,35,.35)']]);
                    },
                    hoverBackgroundColor:'rgba(255,200,90,.95)',
                    borderRadius:8,borderSkipped:false,
                    categoryPercentage:0.65,barPercentage:0.85,
                    yAxisID:'y'},
                {label:'Gains (€)',data:tlGains,type:'line',
                    borderColor:'#00E589',
                    backgroundColor:function(c){
                        return kmVGradient(c.chart.ctx, c.chart.chartArea, [[0,'rgba(0,229,137,.32)'],[1,'rgba(0,229,137,0)']]);
                    },
                    borderWidth:3,fill:true,tension:0.42,
                    pointRadius:4,pointHoverRadius:7,
                    pointBackgroundColor:'#0A0F1E',pointBorderColor:'#00E589',pointBorderWidth:2,
                    pointHoverBackgroundColor:'#00E589',pointHoverBorderColor:'#fff',
                    shadowOffsetY:0,
                    yAxisID:'y1'}
            ]},
            options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},
                animation:{duration:900,easing:'easeOutQuart'},
                plugins:{legend:{display:false},tooltip:Object.assign({},kmTooltipBase,{callbacks:{
                    label:function(c){return ' '+c.dataset.label+': '+(c.datasetIndex===0?c.parsed.y.toLocaleString('fr-FR')+' streams':c.parsed.y.toFixed(4).replace('.',',')+' €');}
                }})},
                scales:{
                    x:{grid:{color:'rgba(255,255,255,.04)',drawTicks:false},border:{display:false},ticks:{color:'#6A82B0',maxRotation:30}},
                    y:{grid:{color:'rgba(255,255,255,.04)',drawTicks:false},border:{display:false},position:'left',ticks:{color:'#F4B942',callback:function(v){return v.toLocaleString('fr-FR');}}},
                    y1:{grid:{display:false},border:{display:false},position:'right',ticks:{color:'#00E589',callback:function(v){return v.toFixed(3)+'€';}}}
                }
            }
        });
    }

    /* Donut artistes */
    if(artistNames.length>0 && document.getElementById('km-chart-artist-split')){
        new Chart(document.getElementById('km-chart-artist-split'),{
            type:'doughnut',
            data:{labels:artistNames,datasets:[{data:artistStreams,backgroundColor:multiColors.slice(0,artistNames.length),borderWidth:3,borderColor:'#121828',hoverOffset:14,hoverBorderWidth:0,borderRadius:4,spacing:2}]},
            options:{responsive:true,maintainAspectRatio:true,cutout:'66%',
                animation:{animateRotate:true,animateScale:true,duration:850,easing:'easeOutQuart'},
                plugins:{
                    legend:{position:'bottom',labels:{padding:12,usePointStyle:true,pointStyleWidth:9,boxHeight:9,color:'#8899BB',font:{size:11}}},
                    tooltip:Object.assign({},kmTooltipBase,{callbacks:{label:function(c){
                        var total=c.dataset.data.reduce(function(a,b){return a+b;},0);
                        var pct=total>0?Math.round(c.parsed/total*1000)/10:0;
                        return ' '+c.label+': '+c.parsed.toLocaleString('fr-FR')+' streams ('+pct+'%)';
                    }}})
                }
            }
        });
    }

    /* Barres plateformes */
    if(platStreams.some(function(v){return v>0;}) && document.getElementById('km-chart-label-platforms')){
        new Chart(document.getElementById('km-chart-label-platforms'),{
            type:'bar',
            data:{labels:platLabels,datasets:[{data:platStreams,
                backgroundColor:platColors.map(function(c){return c+'CC';}),
                hoverBackgroundColor:platColors,
                borderRadius:8,borderSkipped:false,categoryPercentage:0.6,barPercentage:0.85}]},
            options:{responsive:true,maintainAspectRatio:true,
                animation:{duration:800,easing:'easeOutQuart'},
                plugins:{legend:{display:false},tooltip:Object.assign({},kmTooltipBase,{callbacks:{label:function(c){return ' '+c.parsed.y.toLocaleString('fr-FR')+' streams';}}})},
                scales:{
                    x:{grid:{display:false},border:{display:false},ticks:{color:'#5A6A8A'}},
                    y:{grid:{color:'rgba(255,255,255,.04)'},border:{display:false},ticks:{color:'#5A6A8A',callback:function(v){return v.toLocaleString('fr-FR');}}}
                }
            }
        });
    }

    /* Pays horizontal — couleur différente par pays */
    if(cntLabels.length>0 && document.getElementById('km-chart-label-countries')){
        var cntBg = cntLabels.map(function(_,i){return cntPalette[i%cntPalette.length];});
        new Chart(document.getElementById('km-chart-label-countries'),{
            type:'bar',
            data:{labels:cntLabels,datasets:[{data:cntStreams,backgroundColor:cntBg.map(function(c){return c+'99';}),hoverBackgroundColor:cntBg,borderColor:cntBg,borderWidth:1.5,borderRadius:6,barPercentage:0.8}]},
            options:{indexAxis:'y',responsive:true,maintainAspectRatio:true,
                animation:{duration:800,easing:'easeOutQuart'},
                plugins:{legend:{display:false},tooltip:Object.assign({},kmTooltipBase,{callbacks:{label:function(c){return ' '+c.parsed.x.toLocaleString('fr-FR')+' streams';}}})},
                scales:{
                    x:{grid:{color:'rgba(255,255,255,.04)'},border:{display:false},ticks:{color:'#6A82B0',callback:function(v){return v.toLocaleString('fr-FR');}}},
                    y:{grid:{display:false},border:{display:false},ticks:{color:'#C8D6F0',font:{size:11}}}
                }
            }
        });
    }

    /* Pie répartition artistes/label */
    var labelPieData=<?php echo $js_lbl_gains_pie; ?>;
    if(labelPieData.some(function(v){return v>0;}) && document.getElementById('km-chart-label-pie')){
        new Chart(document.getElementById('km-chart-label-pie'),{
            type:'pie',
            data:{labels:['Part Artistes','Marge Label'],datasets:[{data:labelPieData,backgroundColor:['#00D67F','#F5A623'],borderWidth:3,borderColor:'#121828',hoverOffset:12,hoverBorderWidth:0}]},
            options:{responsive:true,maintainAspectRatio:false,
                animation:{animateRotate:true,animateScale:true,duration:850,easing:'easeOutQuart'},
                plugins:{legend:{position:'bottom',labels:{padding:12,usePointStyle:true,pointStyleWidth:9,boxHeight:9,color:'#8899BB',font:{size:11}}},
                tooltip:Object.assign({},kmTooltipBase,{callbacks:{label:function(c){return ' '+c.label+': '+c.parsed.toFixed(5).replace('.',',')+' €';}}})}}
        });
    }

    /* Courbe XOF */
    var tlGainsXOF=<?php echo $js_lbl_tl_gains_xof; ?>;
    if(tlLabels.length>0 && document.getElementById('km-chart-label-xof')){
        new Chart(document.getElementById('km-chart-label-xof'),{
            type:'line',
            data:{labels:tlLabels,datasets:[{label:'Revenus F CFA',data:tlGainsXOF,
                borderColor:'#F5A623',
                backgroundColor:function(c){
                    return kmVGradient(c.chart.ctx, c.chart.chartArea, [[0,'rgba(245,166,35,.30)'],[1,'rgba(245,166,35,0)']]);
                },
                borderWidth:3,fill:true,tension:0.42,
                pointBackgroundColor:'#0A0F1E',pointBorderColor:'#F5A623',pointBorderWidth:2,pointRadius:4,pointHoverRadius:7,
                pointHoverBackgroundColor:'#F5A623',pointHoverBorderColor:'#fff'}]},
            options:{responsive:true,maintainAspectRatio:false,
                animation:{duration:900,easing:'easeOutQuart'},
                plugins:{legend:{display:false},tooltip:Object.assign({},kmTooltipBase,{callbacks:{label:function(c){return ' '+c.parsed.y.toLocaleString('fr-FR')+' F CFA';}}})},
                scales:{x:{grid:{color:'rgba(255,255,255,.04)'},border:{display:false},ticks:{color:'#5A6A8A',maxRotation:30}},y:{grid:{color:'rgba(255,255,255,.04)'},border:{display:false},ticks:{color:'#F5A623',callback:function(v){return v.toLocaleString('fr-FR');}}}}}
        });
    }

    /* Radar plateformes label — échelle en racine carrée : nécessaire car
       une plateforme (souvent Spotify) écrase visuellement les autres en
       valeur brute. La racine carrée resserre l'écart sans inverser l'ordre,
       le tooltip affiche toujours le vrai chiffre. */
    if(platStreams.some(function(v){return v>0;}) && document.getElementById('km-chart-label-radar')){
        var platStreamsSqrt = platStreams.map(function(v){ return Math.sqrt(v); });
        new Chart(document.getElementById('km-chart-label-radar'),{
            type:'radar',
            data:{labels:platLabels,datasets:[{label:'Streams',data:platStreamsSqrt,
                borderColor:'#F5A623',
                backgroundColor:'rgba(245,166,35,.14)',
                pointBackgroundColor:'#0A0F1E',pointBorderColor:'#F5A623',pointBorderWidth:2,
                pointHoverBackgroundColor:'#F5A623',pointHoverBorderColor:'#fff',
                borderWidth:2.5,pointRadius:4,pointHoverRadius:6}]},
            options:{responsive:true,maintainAspectRatio:false,
                animation:{duration:800,easing:'easeOutQuart'},
                plugins:{legend:{display:false},tooltip:Object.assign({},kmTooltipBase,{callbacks:{
                    label:function(c){ return ' '+platStreams[c.dataIndex].toLocaleString('fr-FR')+' streams'; }
                }})},
                scales:{r:{grid:{color:'rgba(255,255,255,.07)'},angleLines:{color:'rgba(255,255,255,.07)'},pointLabels:{color:'#8CA0C8',font:{size:11}},ticks:{display:false}}}}
        });
    }

    /* ── Dots de pagination pour les lignes de graphiques scrollables
       (mobile, voir CSS @media max-width:900px). Purement visuels :
       ils reflètent la position de scroll, sans bloquer le swipe natif. ── */
    function kmInitScrollDots(rowSelector){
        document.querySelectorAll(rowSelector).forEach(function(row){
            var cards = row.children.length;
            if (cards < 2) return;
            var dots = document.createElement('div');
            dots.className = 'km-scroll-dots';
            for (var i=0;i<cards;i++){
                var d=document.createElement('span');
                d.className='km-scroll-dot'+(i===0?' is-active':'');
                dots.appendChild(d);
            }
            row.insertAdjacentElement('afterend', dots);
            var dotEls = dots.querySelectorAll('.km-scroll-dot');
            row.addEventListener('scroll', function(){
                var idx = Math.round(row.scrollLeft / (row.scrollWidth / cards));
                idx = Math.max(0, Math.min(cards-1, idx));
                dotEls.forEach(function(d,i){ d.classList.toggle('is-active', i===idx); });
            }, {passive:true});
        });
    }
    if (window.matchMedia('(max-width: 900px)').matches) {
        kmInitScrollDots('.km-charts-row-main');
        kmInitScrollDots('.km-charts-row-secondary');
        kmInitScrollDots('.km-charts-row-3');
    }
});

/* Filtre table détail JS — filtre toutes les tables avec data-artist dans les panels visibles */
function kmTableFilter(){
    var val=document.getElementById('km-tf-artist') ? document.getElementById('km-tf-artist').value.toLowerCase() : '';
    document.querySelectorAll('.km-tab-panel tbody tr[data-artist]').forEach(function(tr){
        tr.style.display=(!val||tr.dataset.artist.toLowerCase()===val)?'':'none';
    });
}
function kmTableFilterLabel(){
    var val=document.getElementById('km-tf-artist') ? document.getElementById('km-tf-artist').value.toLowerCase() : '';
    document.querySelectorAll('.km-tab-panel tbody tr[data-artist]').forEach(function(tr){
        tr.style.display=(!val||tr.dataset.artist.toLowerCase()===val)?'':'none';
    });
}
function kmTableReset(){
    if(document.getElementById('km-tf-artist')) document.getElementById('km-tf-artist').value='';
    document.querySelectorAll('.km-tab-panel tbody tr[data-artist]').forEach(function(tr){tr.style.display='';});
}
function kmTableResetLabel(){
    if(document.getElementById('km-tf-artist')) document.getElementById('km-tf-artist').value='';
    document.querySelectorAll('.km-tab-panel tbody tr[data-artist]').forEach(function(tr){tr.style.display='';});
}

/* Filtre catalogue JS */
function kmFilterCatalog(val,type){
    var selects=document.querySelectorAll('.km-catalog-filter-select');
    var activeType=selects[0]?selects[0].value:'';
    var activeArtist=selects[1]?selects[1].value:'';
    document.querySelectorAll('#km-catalog-grid .km-catalog-card').forEach(function(card){
        var show=(!activeType||card.dataset.type===activeType)&&(!activeArtist||card.dataset.artist===activeArtist);
        card.style.display=show?'':'none';
    });
}

/* Export PDF */
function kmLabelExportPDF(){
    var btn=document.querySelector('.km-btn-pdf');
    if(btn){btn.textContent='⏳ Génération...';btn.disabled=true;}
    var jsPDF=window.jspdf&&window.jspdf.jsPDF;
    if(!jsPDF){alert('jsPDF non chargé.');return;}
    var doc=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
    html2canvas(document.getElementById('km-label-db'),{scale:1.4,useCORS:true,backgroundColor:'#000530'}).then(function(canvas){
        var imgData=canvas.toDataURL('image/png');var pw=doc.internal.pageSize.getWidth();var ph=doc.internal.pageSize.getHeight();var ih=(canvas.height*pw)/canvas.width;var py=0,rem=ih;
        while(rem>0){doc.addImage(imgData,'PNG',0,py,pw,ih);rem-=ph;py-=ph;if(rem>0)doc.addPage();}
        doc.save('KOPHISMUSIC_Label_'+new Date().toLocaleDateString('fr-FR').replace(/\//g,'-')+'.pdf');
        if(btn){btn.textContent='Export PDF';btn.disabled=false;}
    });
}
</script>
<?php
    return ob_get_clean();
}
