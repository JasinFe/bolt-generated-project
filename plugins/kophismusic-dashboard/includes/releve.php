<?php
// ============================================================
// KOPHI'S MUSIC — Relevé de Droits Artiste v3.0
// QR Certification | Détail Titre | Détail Album | EUR+XOF
// ============================================================
defined( 'ABSPATH' ) || exit;

if ( ! defined('KM_EUR_TO_XOF') ) define('KM_EUR_TO_XOF', 655.957);

// ============================================================
// CERTIFICATION — stockage et vérification côté serveur
// ============================================================
// À chaque génération d'un relevé (impression, export, ouverture du
// modal), un jeton aléatoire (cert_hash) est créé et associé à une
// référence unique (cert_ref) dans l'option 'km_certifications'.
// La page publique de vérification (km_render_certification_page)
// ne recalcule jamais rien : elle relit ce qui a été stocké ici et
// compare avec hash_equals(), en temps constant, pour éviter les
// attaques par comparaison temporelle. Un hash ou une référence
// inventés ne peuvent donc jamais correspondre à une entrée stockée.
if ( ! function_exists( 'km_store_certification' ) ) {
    function km_store_certification( $ref, $user_id, $periode_label, $hash, $date_edition ) {
        $certs = get_option( 'km_certifications', array() );
        $certs[ $ref ] = array(
            'uid'     => (int) $user_id,
            'periode' => $periode_label,
            'hash'    => $hash,
            'date'    => $date_edition,
            'created' => time(),
        );
        // Garde-fou anti-croissance illimitée : ne conserve que les 1000
        // certifications les plus récentes (l'option wp_options n'est pas
        // faite pour stocker un historique infini).
        if ( count( $certs ) > 1000 ) {
            uasort( $certs, function( $a, $b ) { return $a['created'] <=> $b['created']; } );
            $certs = array_slice( $certs, -1000, null, true );
        }
        update_option( 'km_certifications', $certs, false );
    }
}

if ( ! function_exists( 'km_verify_certification' ) ) {
    function km_verify_certification( $ref, $user_id, $hash ) {
        if ( ! $ref || ! $hash || strlen( $hash ) !== 16 ) return false;
        $certs = get_option( 'km_certifications', array() );
        if ( ! isset( $certs[ $ref ] ) ) return false;
        $rec = $certs[ $ref ];
        if ( (int) $rec['uid'] !== (int) $user_id ) return false;
        return hash_equals( (string) $rec['hash'], (string) $hash );
    }
}

if ( ! function_exists( 'km_get_certification' ) ) {
    function km_get_certification( $ref ) {
        $certs = get_option( 'km_certifications', array() );
        return isset( $certs[ $ref ] ) ? $certs[ $ref ] : null;
    }
}

function km_render_releve_html( $user_id, $filter_year = '', $filter_periode = '' ) {

    $user = get_user_by( 'id', $user_id );
    if ( ! $user ) return '';

    $profile_id     = km_get_artist_profile_post( $user_id );
    $artist_photo   = $profile_id ? get_the_post_thumbnail_url( $profile_id, 'medium' ) : '';
    $genre          = $profile_id ? get_field('genre_musical',  $profile_id) : '';
    $date_sign      = $profile_id ? get_field('date_signature', $profile_id) : '';
    $statut_contrat = $profile_id ? get_field('statut_contrat', $profile_id) : '';
    $tunecore_name  = $profile_id ? get_field('tunecore_name',  $profile_id) : $user->display_name;

    $artist_email      = $user->user_email;
    $artist_first_name = get_user_meta($user_id, 'first_name', true);
    $artist_last_name  = get_user_meta($user_id, 'last_name',  true);
    $artist_full_name  = trim($artist_first_name . ' ' . $artist_last_name) ?: $user->display_name;

    $year_sign = '';
    if ($date_sign && preg_match('/(\d{4})/', $date_sign, $m)) $year_sign = $m[1];

    $commission_label = km_get_artist_commission($user_id);
    $pct_artiste      = round((1 - $commission_label) * 100, 1);
    $pct_label        = round($commission_label * 100, 1);

    $rapports = get_posts(array(
        'post_type'      => 'rapport_mensuel',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_key'       => 'artiste_user_id',
        'meta_value'     => $user_id,
        'orderby'        => 'date',
        'order'          => 'ASC',
    ));

    $total_streams = 0; $total_brut = 0.0; $total_artiste = 0.0;
    $platform_streams = array('apple'=>0,'youtube'=>0,'deezer'=>0,'tidal'=>0);
    $platform_gains   = array('apple'=>0,'youtube'=>0,'deezer'=>0,'tidal'=>0);
    $lignes_rapports  = array();
    $tracks_agg       = array();
    $albums_agg       = array();

    foreach ($rapports as $r) {
        $periode = get_field('periode', $r->ID);
        $year    = substr($periode, -4);
        if ($filter_year    && $year    !== strval($filter_year))    continue;
        if ($filter_periode && $periode !== $filter_periode)         continue;

        $ts = (int)  (get_field('total_streams',    $r->ID) ?: get_post_meta($r->ID,'_km_total_streams',true));
        $tg = (float)(get_field('total_gains_brut', $r->ID) ?: get_post_meta($r->ID,'_km_total_gains',true));
        $pa = (float)(get_field('part_artiste',      $r->ID) ?: get_post_meta($r->ID,'_km_part_artiste',true));

        $total_streams += $ts; $total_brut += $tg; $total_artiste += $pa;

        $row = array('periode'=>$periode,'streams'=>$ts,'brut'=>$tg,'artiste'=>$pa);
        foreach (array_keys($platform_streams) as $p) {
            $ps = (int)get_field($p.'_streams',$r->ID);
            $pg = (float)get_field($p.'_gains',$r->ID);
            $platform_streams[$p]+=$ps; $platform_gains[$p]+=$pg;
            $row[$p.'_streams']=$ps; $row[$p.'_gains']=$pg;
        }
        $lignes_rapports[] = $row;

        $cat_raw = get_post_meta($r->ID,'_km_catalog',true);
        if ($cat_raw) {
            $cat = json_decode($cat_raw,true);
            if (is_array($cat)) {
                $nb_releases = max(1, count($cat));
                foreach ($cat as $release => $rdata) {
                    $rtype = $rdata['type'] ?? 'Son';
                    $nb_tracks = max(1, count($rdata['tracks'] ?? array()));
                    if (!isset($albums_agg[$release])) {
                        $albums_agg[$release] = array('type'=>$rtype,'streams'=>0,'gains'=>0.0,'tracks'=>array());
                    }
                    foreach ((array)($rdata['tracks'] ?? array()) as $track) {
                        $t_st = intval($ts / $nb_releases / $nb_tracks);
                        $t_ga = $tg / $nb_releases / $nb_tracks;
                        if (!isset($tracks_agg[$track])) {
                            $tracks_agg[$track] = array('streams'=>0,'gains'=>0.0,'album'=>$release,'type'=>$rtype);
                        }
                        $tracks_agg[$track]['streams'] += $t_st;
                        $tracks_agg[$track]['gains']   += $t_ga;
                        if (!in_array($track,$albums_agg[$release]['tracks'],true))
                            $albums_agg[$release]['tracks'][] = $track;
                        $albums_agg[$release]['streams'] += $t_st;
                        $albums_agg[$release]['gains']   += $t_ga;
                    }
                }
            }
        }
    }

    arsort($tracks_agg);

    $periode_label     = $filter_periode ?: ($filter_year ? 'Année '.$filter_year : 'Toutes périodes');
    $date_edition      = date_i18n('d/m/Y à H:i');
    $logo_url          = defined('KM_LOGO_URL') ? KM_LOGO_URL : '';
    $genres_list       = $genre ? (is_array($genre) ? implode(', ',$genre) : $genre) : '';

    $total_brut_xof    = round($total_brut    * KM_EUR_TO_XOF);
    $total_artiste_xof = round($total_artiste * KM_EUR_TO_XOF);
    $total_label_xof   = round(($total_brut - $total_artiste) * KM_EUR_TO_XOF);

    // ── Certification : jeton aléatoire non devinable, enregistré côté
    // serveur (voir km_store_certification / km_verify_certification
    // plus bas dans ce fichier). La page de vérification ne fait AUCUN
    // calcul : elle compare le hash reçu à celui stocké au moment de
    // l'émission, via hash_equals(). Impossible donc de forger une URL
    // valide sans connaître un hash déjà émis par le serveur.
    $cert_ref  = 'KM-' . $user_id . '-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $cert_hash = strtoupper(bin2hex(random_bytes(8)));
    km_store_certification($cert_ref, $user_id, $periode_label, $cert_hash, $date_edition);
    $verify_url= home_url('/certification-artiste/?uid=' . $user_id . '&ref=' . urlencode($cert_ref) . '&hash=' . $cert_hash);

    ob_start(); ?>
<div id="km-releve-print" class="km-releve">

<div class="km-releve-header">
    <div class="km-releve-header-left">
        <?php if ($logo_url): ?><img src="<?php echo esc_url($logo_url); ?>" alt="KOPHI'S MUSIC" class="km-releve-logo" /><?php else: ?><div class="km-releve-logo-text">KM</div><?php endif; ?>
        <div class="km-releve-label-info">
            <strong>KOPHI'S MUSIC</strong>
            <span>Label Urban Gospel</span>
            <span><a href="https://kophismusic.com" target="_blank" style="color:#F5A623;text-decoration:none;font-weight:600;">kophismusic.com</a></span>
        </div>
    </div>
    <div class="km-releve-header-right">
        <div class="km-releve-doc-title">RELEVÉ DE DROITS</div>
        <div class="km-releve-doc-meta">
            <span>Période : <strong><?php echo esc_html($periode_label); ?></strong></span>
            <span>Édité le : <?php echo $date_edition; ?></span>
            <span>Réf. : <?php echo esc_html($cert_ref); ?></span>
        </div>
    </div>
</div>

<div class="km-releve-artist-block">
    <div class="km-releve-artist-left">
        <?php if ($artist_photo): ?>
        <img src="<?php echo esc_url($artist_photo); ?>" alt="<?php echo esc_attr($artist_full_name); ?>" class="km-releve-artist-photo" />
        <?php else: ?>
        <div class="km-releve-artist-initials"><?php echo strtoupper(substr($artist_full_name,0,1)); ?></div>
        <?php endif; ?>
        <div class="km-releve-artist-details">
            <h2><?php echo esc_html($artist_full_name); ?></h2>
            <p class="km-releve-artist-sub"><?php echo esc_html($user->display_name); ?><?php if ($tunecore_name && $tunecore_name!==$user->display_name) echo ' · TuneCore : '.esc_html($tunecore_name); ?></p>
            <?php if ($genres_list): ?><p><span class="km-releve-chip"><?php echo esc_html($genres_list); ?></span></p><?php endif; ?>
        </div>
    </div>
    <div class="km-releve-artist-right">
        <table class="km-releve-info-table">
            <tr><th>Email</th><td><?php echo esc_html($artist_email); ?></td></tr>
            <?php if ($date_sign): ?><tr><th>Signé le</th><td><?php echo esc_html($date_sign); ?></td></tr><?php endif; ?>
            <?php if ($statut_contrat): ?><tr><th>Contrat</th><td><?php echo esc_html($statut_contrat); ?></td></tr><?php endif; ?>
        </table>
    </div>
</div>

<div class="km-releve-contrat">
    <div class="km-releve-contrat-item">
        <span class="km-releve-contrat-pct"><?php echo $pct_artiste; ?>%</span>
        <span>Part Artiste</span>
    </div>
    <div class="km-releve-contrat-sep">/</div>
    <div class="km-releve-contrat-item km-releve-contrat-label">
        <span class="km-releve-contrat-pct"><?php echo $pct_label; ?>%</span>
        <span>Commission Label</span>
    </div>
</div>

<div class="km-releve-kpis">
    <div class="km-releve-kpi">
        <span class="km-releve-kpi-label">TOTAL STREAMS</span>
        <span class="km-releve-kpi-value"><?php echo number_format($total_streams,0,',',' '); ?></span>
    </div>
    <div class="km-releve-kpi km-releve-kpi-brut">
        <span class="km-releve-kpi-label">GAINS BRUTS</span>
        <span class="km-releve-kpi-value"><?php echo number_format($total_brut,4,',',' '); ?> €</span>
        <span class="km-releve-kpi-xof"><?php echo number_format($total_brut_xof,0,',',' '); ?> F CFA</span>
    </div>
    <div class="km-releve-kpi km-releve-kpi-artiste">
        <span class="km-releve-kpi-label">PART ARTISTE NET</span>
        <span class="km-releve-kpi-value"><?php echo number_format($total_artiste,4,',',' '); ?> €</span>
        <span class="km-releve-kpi-xof"><?php echo number_format($total_artiste_xof,0,',',' '); ?> F CFA</span>
    </div>
    <div class="km-releve-kpi">
        <span class="km-releve-kpi-label">RETENUS LABEL</span>
        <span class="km-releve-kpi-value"><?php echo number_format($total_brut-$total_artiste,4,',',' '); ?> €</span>
        <span class="km-releve-kpi-xof"><?php echo number_format($total_label_xof,0,',',' '); ?> F CFA</span>
    </div>
</div>

<div class="km-releve-section">
    <h3 class="km-releve-section-title">Détail par Plateforme de Streaming</h3>
    <div class="km-releve-table-wrap">
    <table class="km-releve-table km-releve-table-platform">
        <thead>
            <tr><th>Plateforme</th><th>Streams</th><th>Gains Bruts (€)</th><th>Gains (F CFA)</th><th>Part Artiste (€)</th><th>Part Artiste (F CFA)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($platform_streams as $pkey => $pval):
            $pname=$km_pn=km_platform_name($pkey);
            $pgain=$platform_gains[$pkey];
            $ppart=$pgain*(1-$commission_label);
            $pgain_xof=round($pgain*KM_EUR_TO_XOF);
            $ppart_xof=round($ppart*KM_EUR_TO_XOF);
        ?>
            <tr>
                <td class="km-releve-plat-name"><span class="km-releve-plat-dot" style="background:<?php echo km_platform_color($pkey); ?>"></span><?php echo esc_html($pname); ?></td>
                <td><?php echo number_format($pval,0,',',' '); ?></td>
                <td><?php echo number_format($pgain,4,',',''); ?> €</td>
                <td style="color:#6B7280;"><?php echo number_format($pgain_xof,0,',',' '); ?> F CFA</td>
                <td class="km-releve-td-artiste"><?php echo number_format($ppart,4,',',''); ?> €</td>
                <td style="color:#B45309;"><?php echo number_format($ppart_xof,0,',',' '); ?> F CFA</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td><strong>TOTAL</strong></td>
                <td><strong><?php echo number_format($total_streams,0,',',' '); ?></strong></td>
                <td><strong><?php echo number_format($total_brut,4,',',''); ?> €</strong></td>
                <td><strong><?php echo number_format($total_brut_xof,0,',',' '); ?> F CFA</strong></td>
                <td class="km-releve-td-artiste"><strong><?php echo number_format($total_artiste,4,',',''); ?> €</strong></td>
                <td class="km-releve-td-artiste"><strong><?php echo number_format($total_artiste_xof,0,',',' '); ?> F CFA</strong></td>
            </tr>
        </tfoot>
    </table>
    </div><!-- .km-releve-table-wrap -->
</div>

<?php if ($tracks_agg): ?>
<div class="km-releve-section km-releve-section-titles">
    <h3 class="km-releve-section-title">Détail par Titre</h3>
    <div class="km-releve-table-wrap">
    <table class="km-releve-table km-releve-table-titles">
        <thead>
            <tr>
                <th>N°</th>
                <th>TITRE</th>
                <th>ALBUM / SORTIE</th>
                <th>STREAMS</th>
                <th>PART ARTISTE (€)</th>
                <th>PART ARTISTE (F FCFA)</th>
            </tr>
        </thead>
        <tbody>
        <?php $ti=1; foreach ($tracks_agg as $track_name => $tdata):
            $t_part=$tdata['gains']*(1-$commission_label);
            $t_part_xof=round($t_part*KM_EUR_TO_XOF);
        ?>
            <tr>
                <td><?php echo str_pad($ti,2,'0',STR_PAD_LEFT); ?></td>
                <td><?php echo esc_html($track_name); ?></td>
                <td><?php echo esc_html($tdata['album']); ?></td>
                <td><?php echo $tdata['streams'] ? number_format($tdata['streams'],0,',',' ') : '—'; ?></td>
                <td class="km-releve-td-artiste"><?php echo number_format($t_part,4,',',''); ?> €</td>
                <td><?php echo number_format($t_part_xof,0,',',' '); ?> F CFA</td>
            </tr>
        <?php $ti++; endforeach; ?>
        </tbody>
    </table>
    </div><!-- .km-releve-table-wrap -->
    <p style="font-size:.68rem;color:#9CA3AF;margin-top:5px;">* Streams et gains par titre : estimations proportionnelles calculées à partir des données TuneCore agrégées.</p>
</div>
<?php endif; ?>

<?php if ($albums_agg): ?>
<div class="km-releve-section km-releve-section-albums">
    <h3 class="km-releve-section-title">Détail par Album, EP &amp; Single</h3>
    <?php foreach ($albums_agg as $alb_name => $alb):
        $alb_part=$alb['gains']*(1-$commission_label);
        $alb_part_xof=round($alb_part*KM_EUR_TO_XOF);
        $type_label=strtoupper($alb['type'] ?? 'SON');
    ?>
    <div class="km-releve-album-card">
        <div class="km-releve-album-header">
            <span class="km-releve-album-type"><?php echo esc_html($type_label); ?></span>
            <strong class="km-releve-album-title"><?php echo esc_html($alb_name); ?></strong>
            <span class="km-releve-album-tracks"><?php echo count($alb['tracks']); ?> piste(s)</span>
        </div>
        <div class="km-releve-album-kpis">
            <div><span>Streams est.</span><strong><?php echo number_format($alb['streams'],0,',',' '); ?></strong></div>
            <div><span>Gains bruts</span><strong><?php echo number_format($alb['gains'],4,',',''); ?> €</strong></div>
            <div><span>Part artiste</span><strong><?php echo number_format($alb_part,4,',',''); ?> €</strong></div>
            <div><span>Part artiste</span><strong><?php echo number_format($alb_part_xof,0,',',' '); ?> F CFA</strong></div>
        </div>
        <?php if ($alb['tracks']): ?>
        <div class="km-releve-album-tracklist">
            <?php foreach ($alb['tracks'] as $n=>$tr): ?>
            <span class="km-releve-album-track"><span style="color:#9CA3AF;"><?php echo str_pad($n+1,2,'0',STR_PAD_LEFT); ?>.</span> <?php echo esc_html($tr); ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (count($lignes_rapports) > 1): ?>
<div class="km-releve-section">
    <h3 class="km-releve-section-title">Détail Mensuel</h3>
    <div class="km-releve-table-wrap">
    <table class="km-releve-table km-releve-table-monthly">
        <thead>
            <tr><th>Période</th><th>Streams</th><th>Apple Music</th><th>YouTube</th><th>Deezer</th><th>Tidal</th><th>Gains Bruts (€)</th><th>Gains (F CFA)</th><th>Part Artiste (€)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($lignes_rapports as $ligne):
            $l_brut_xof=round($ligne['brut']*KM_EUR_TO_XOF);
        ?>
            <tr>
                <td><strong><?php echo esc_html($ligne['periode']); ?></strong></td>
                <td><?php echo number_format($ligne['streams'],0,',',' '); ?></td>
                <td><?php echo number_format($ligne['apple_streams']??0,0,',',' '); ?></td>
                <td><?php echo number_format($ligne['youtube_streams']??0,0,',',' '); ?></td>
                <td><?php echo number_format($ligne['deezer_streams']??0,0,',',' '); ?></td>
                <td><?php echo number_format($ligne['tidal_streams']??0,0,',',' '); ?></td>
                <td><?php echo number_format($ligne['brut'],4,',',''); ?> €</td>
                <td style="color:#6B7280;"><?php echo number_format($l_brut_xof,0,',',' '); ?> F CFA</td>
                <td class="km-releve-td-artiste"><?php echo number_format($ligne['artiste'],4,',',''); ?> €</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div><!-- .km-releve-table-wrap -->
</div>
<?php endif; ?>

<?php
$paiements_r = get_posts(array('post_type'=>'paiement','posts_per_page'=>-1,'post_status'=>'publish','meta_key'=>'artiste_user_id','meta_value'=>$user_id,'orderby'=>'date','order'=>'DESC'));
if ($paiements_r): ?>
<div class="km-releve-section">
    <h3 class="km-releve-section-title">Historique des Paiements</h3>
    <div class="km-releve-table-wrap">
    <table class="km-releve-table">
        <thead>
            <tr><th>Date</th><th>Montant (€)</th><th>Montant (F CFA)</th><th>Méthode</th><th>Référence</th><th>Statut</th></tr>
        </thead>
        <tbody>
        <?php foreach ($paiements_r as $p):
            $statut=$pstat=get_field('statut_paiement',$p->ID);
            $statut_class=$pstat==='Payé'?'km-releve-badge-paye':($pstat==='Annulé'?'km-releve-badge-annule':'km-releve-badge-attente');
            $m_eur=(float)get_field('montant_paiement',$p->ID);
            $m_xof=round($m_eur*KM_EUR_TO_XOF);
        ?>
            <tr>
                <td><?php echo esc_html(get_field('date_paiement',$p->ID)); ?></td>
                <td><strong><?php echo number_format($m_eur,2,',',' '); ?> €</strong></td>
                <td><?php echo number_format($m_xof,0,',',' '); ?> F CFA</td>
                <td><?php echo esc_html(get_field('methode_paiement',$p->ID)); ?></td>
                <td><?php echo esc_html(get_field('reference_paiement',$p->ID)); ?></td>
                <td><span class="km-releve-badge <?php echo $statut_class; ?>"><?php echo esc_html($pstat); ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div><!-- .km-releve-table-wrap -->
</div>
<?php endif; ?>

<div class="km-releve-footer">
    <div class="km-releve-footer-left">
        <p class="km-releve-disclaimer">Ce relevé est établi sur la base des données de distribution TuneCore. Les montants en euros (€) et en Francs CFA (F CFA) sont calculés selon les conditions contractuelles en vigueur entre l'artiste et KOPHI'S MUSIC. 1 EUR = 655,957 F CFA (taux fixe UEMOA). Ce document est généré automatiquement et certifié valide par le système KOPHI'S MUSIC.</p>
        <p>Document officiel — <a href="https://kophismusic.com" target="_blank" style="color:#F5A623;text-decoration:underline;font-weight:600;">kophismusic.com</a> &nbsp;·&nbsp; Édité le <?php echo $date_edition; ?></p>
    </div>
    <div class="km-releve-footer-right">
        <div class="km-releve-cert-block">
            <span class="km-releve-cert-title">Certification KOPHI'S MUSIC</span>
            <div class="km-releve-qr-wrap">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?php echo urlencode($verify_url); ?>&bgcolor=ffffff&color=0f172a&margin=2"
                     alt="QR Certification"
                     style="width:100%;height:100%;display:block;border-radius:4px;"
                     onerror="this.outerHTML='<div style=\'font-size:.5rem;color:#64748B;text-align:center;padding:4px;font-family:monospace;line-height:1.3;\'><?php echo esc_js($cert_hash); ?></div>'" />
            </div>
            <span class="km-releve-cert-label">Réf. : <?php echo esc_html($cert_hash); ?></span>
            <span class="km-releve-cert-stamp">Document Certifié ✓</span>
            <span style="font-size:.58rem;color:#6B7280;margin-top:2px;text-align:center;">KOPHI'S MUSIC | KOPHI'S GROUP SAS</span>
        </div>
    </div>
</div>

</div><!-- #km-releve-print -->
    <?php
    return ob_get_clean();
}

add_action('wp_ajax_km_get_releve', 'km_ajax_get_releve');
function km_ajax_get_releve() {
    if (!is_user_logged_in()) wp_die('Non autorisé',403);
    check_ajax_referer('km_releve_nonce','nonce');
    $current=$_cu=get_current_user_id();
    $user_id=isset($_POST['user_id'])?intval($_POST['user_id']):$current;
    $filter_year=isset($_POST['year'])?sanitize_text_field($_POST['year']):'';
    $filter_periode=isset($_POST['periode'])?sanitize_text_field($_POST['periode']):'';
    $u=wp_get_current_user();
    if(!in_array('administrator',(array)$u->roles)&&!in_array('manager_label',(array)$u->roles)) $user_id=$current;
    $html=km_render_releve_html($user_id,$filter_year,$filter_periode);
    wp_send_json_success(array('html'=>$html));
}

/**
 * Page de Certification Artiste — [km_certification_page]
 */
add_shortcode('km_certification_page','km_render_certification_page');

// Interception au niveau template_redirect : la page certification
// est standalone (genere son propre DOCTYPE), on court-circuite WP
add_action('template_redirect', 'km_certification_standalone', 5);
function km_certification_standalone() {
    // Uniquement sur la page certification-artiste
    if ( ! function_exists('km_get_page_id') ) return;
    $cert_page_id = km_get_page_id('certification-artiste');
    if ( ! $cert_page_id ) return;

    $is_cert_page = false;
    if ( is_page( $cert_page_id ) ) $is_cert_page = true;
    if ( isset($_GET['page_id']) && (int)$_GET['page_id'] === (int)$cert_page_id ) $is_cert_page = true;
    if ( isset($_GET['pagename']) && $_GET['pagename'] === 'certification-artiste' ) $is_cert_page = true;

    if ( ! $is_cert_page ) return;

    // Rendre la page et stopper WordPress
    echo km_render_certification_page();
    exit;
}

function km_render_certification_page() {
    $uid  = isset($_GET['uid'])  ? intval($_GET['uid']) : 0;
    $ref  = isset($_GET['ref'])  ? sanitize_text_field($_GET['ref']) : '';
    $hash = isset($_GET['hash']) ? sanitize_text_field($_GET['hash']) : '';
    $logo_url = defined('KM_LOGO_URL') ? KM_LOGO_URL : '';

    // ── Helper CSS + structure (standalone, independant du theme) ──
    $shared_css = '
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    html,body{margin:0;padding:0;background:#050710;}
    body{font-family:"DM Sans",system-ui,-apple-system,sans-serif;color:#F5F8FF;min-height:100vh;position:relative;overflow-x:hidden;}
    .km-cert-bg{position:fixed;inset:0;z-index:0;pointer-events:none;overflow:hidden;}
    .km-cert-orb{position:absolute;border-radius:50%;filter:blur(100px);opacity:.35;animation:kmCertFloat 22s ease-in-out infinite;}
    .km-cert-orb-1{width:480px;height:480px;background:radial-gradient(circle,#F5A623 0%,transparent 70%);top:-180px;left:-120px;}
    .km-cert-orb-2{width:560px;height:560px;background:radial-gradient(circle,#00D67F 0%,transparent 70%);bottom:-220px;right:-180px;animation-delay:-8s;}
    .km-cert-orb-3{width:380px;height:380px;background:radial-gradient(circle,#4C9FFF 0%,transparent 70%);top:40%;left:45%;animation-delay:-14s;}
    @keyframes kmCertFloat{0%,100%{transform:translate(0,0) scale(1);}33%{transform:translate(50px,-30px) scale(1.08);}66%{transform:translate(-30px,40px) scale(.96);}}
    .km-cert-grid{position:fixed;inset:0;z-index:0;pointer-events:none;background-image:linear-gradient(rgba(255,255,255,.02) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.02) 1px,transparent 1px);background-size:50px 50px;-webkit-mask-image:radial-gradient(ellipse at center,black 30%,transparent 80%);mask-image:radial-gradient(ellipse at center,black 30%,transparent 80%);}
    .km-cert-wrap{position:relative;z-index:1;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;}
    ';

    // ── Etat : pas d'UID ──
    if (!$uid) {
        ob_start(); ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vérification · KOPHI'S MUSIC</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
<?php echo $shared_css; ?>
.km-cert-empty{width:100%;max-width:460px;padding:40px 32px;background:rgba(18,24,40,.6);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.08);border-radius:24px;text-align:center;box-shadow:0 32px 80px rgba(0,0,0,.5);}
.km-cert-empty-icon{width:72px;height:72px;border-radius:50%;background:rgba(76,159,255,.12);border:2px solid rgba(76,159,255,.3);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;color:#4C9FFF;}
.km-cert-logo{height:44px;margin:0 auto 20px;display:block;}
.km-cert-empty h1{font-family:"Outfit",sans-serif;font-size:22px;font-weight:800;color:#F5F8FF;margin-bottom:10px;letter-spacing:-.01em;}
.km-cert-empty p{color:#8A9AB8;font-size:13px;line-height:1.55;margin-bottom:24px;}
.km-cert-btn{display:inline-flex;align-items:center;gap:7px;padding:12px 22px;background:linear-gradient(135deg,#F5A623,#FFB940);color:#000;border-radius:10px;font-weight:700;text-decoration:none;font-size:13px;box-shadow:0 8px 24px rgba(245,166,35,.28);transition:all .2s;}
.km-cert-btn:hover{transform:translateY(-2px);box-shadow:0 12px 32px rgba(245,166,35,.4);}
</style>
</head>
<body>
<div class="km-cert-bg"><div class="km-cert-orb km-cert-orb-1"></div><div class="km-cert-orb km-cert-orb-2"></div><div class="km-cert-orb km-cert-orb-3"></div></div>
<div class="km-cert-grid"></div>
<div class="km-cert-wrap">
  <div class="km-cert-empty">
    <?php if($logo_url):?><img src="<?php echo esc_url($logo_url);?>" alt="KOPHI'S MUSIC" class="km-cert-logo"/><?php endif;?>
    <div class="km-cert-empty-icon"><svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>
    <h1>Vérification de document</h1>
    <p>Scannez un QR code sur un relevé officiel KOPHI'S MUSIC pour vérifier l'authenticité du document.</p>
    <a href="https://kophismusic.com" class="km-cert-btn">Visiter kophismusic.com <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></a>
  </div>
</div>
</body>
</html>
        <?php return ob_get_clean();
    }

    $user = get_user_by('id',$uid);
    if (!$user) {
        ob_start(); ?>
<!DOCTYPE html>
<html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Artiste introuvable · KOPHI'S MUSIC</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
<style><?php echo $shared_css; ?>.km-cert-error{max-width:420px;padding:32px;background:rgba(18,24,40,.6);backdrop-filter:blur(20px);border:1px solid rgba(255,78,106,.3);border-radius:20px;text-align:center;}
.km-cert-error h1{font-size:18px;color:#FF4E6A;margin-bottom:8px;}.km-cert-error p{color:#C8D1E0;font-size:13px;}</style></head>
<body><div class="km-cert-bg"><div class="km-cert-orb km-cert-orb-1"></div></div><div class="km-cert-wrap"><div class="km-cert-error"><h1>⚠ Artiste introuvable</h1><p>Ce document ne correspond à aucun artiste enregistré dans le système KOPHI'S MUSIC.</p></div></div></body></html>
        <?php return ob_get_clean();
    }

    $profile_id   = km_get_artist_profile_post($uid);
    $artist_photo = $profile_id ? get_the_post_thumbnail_url($profile_id,'medium') : '';
    $date_sign    = $profile_id ? get_field('date_signature',$profile_id) : '';
    $genres       = $profile_id ? get_field('genre_musical',$profile_id)  : '';
    $year_sign    = '';
    if ($date_sign && preg_match('/(\d{4})/',$date_sign,$m)) $year_sign=$m[1];
    $full_name    = trim(get_user_meta($uid,'first_name',true).' '.get_user_meta($uid,'last_name',true)) ?: $user->display_name;
    $genres_str   = $genres ? (is_array($genres)?implode(', ',$genres):$genres) : '';
    $is_valid     = km_verify_certification($ref, $uid, $hash);
    $cert_record  = $is_valid ? km_get_certification($ref) : null;
    $date_verif   = date_i18n('j F Y · H:i');

    ob_start(); ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Certification · <?php echo esc_html($full_name); ?> · KOPHI'S MUSIC</title>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800;900&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
<?php echo $shared_css; ?>

/* ── Carte principale ── */
.km-cert-card{width:100%;max-width:480px;background:rgba(18,24,40,.55);backdrop-filter:blur(24px) saturate(180%);-webkit-backdrop-filter:blur(24px) saturate(180%);border:1px solid rgba(255,255,255,.08);border-radius:26px;overflow:hidden;box-shadow:0 40px 100px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.02) inset;animation:kmCertIn .6s cubic-bezier(.2,.8,.2,1);}
@keyframes kmCertIn{from{opacity:0;transform:translateY(30px);}to{opacity:1;transform:translateY(0);}}

/* ── Header avec dégradé ── */
.km-cert-header{position:relative;padding:30px 30px 22px;text-align:center;background:linear-gradient(135deg,rgba(245,166,35,.12),rgba(0,214,127,.06));border-bottom:1px solid rgba(255,255,255,.05);}
.km-cert-header::before{content:"";position:absolute;top:0;left:0;width:100%;height:2px;background:linear-gradient(90deg,transparent,#F5A623,#00D67F,transparent);}

.km-cert-logo{height:38px;width:auto;filter:drop-shadow(0 2px 8px rgba(245,166,35,.3));margin-bottom:16px;}
.km-cert-logo-fallback{width:42px;height:42px;margin:0 auto 16px;background:linear-gradient(135deg,#F5A623,#FFB940);border-radius:10px;display:flex;align-items:center;justify-content:center;font-family:"Outfit",sans-serif;font-weight:900;font-size:16px;color:#000;}

/* ── Badge d'authentification ── */
.km-cert-badge{display:inline-flex;align-items:center;gap:7px;padding:7px 16px;background:rgba(0,214,127,.12);border:1px solid rgba(0,214,127,.35);border-radius:100px;font-size:11px;font-weight:700;color:#00D67F;letter-spacing:1px;text-transform:uppercase;box-shadow:0 0 20px rgba(0,214,127,.15);}
.km-cert-badge-dot{width:6px;height:6px;background:#00D67F;border-radius:50%;box-shadow:0 0 8px #00D67F;animation:kmPulse 2s ease-in-out infinite;}
@keyframes kmPulse{0%,100%{opacity:1;transform:scale(1);}50%{opacity:.6;transform:scale(1.3);}}

.km-cert-badge-error{background:rgba(255,78,106,.1);border-color:rgba(255,78,106,.3);color:#FF7590;}
.km-cert-badge-error .km-cert-badge-dot{background:#FF4E6A;box-shadow:0 0 8px #FF4E6A;}

.km-cert-title{font-size:10px;font-weight:700;color:#8A9AB8;letter-spacing:1.8px;text-transform:uppercase;margin-top:14px;}

/* ── Body ── */
.km-cert-body{padding:30px 30px 24px;text-align:center;}

.km-cert-photo{position:relative;width:92px;height:92px;margin:0 auto 18px;border-radius:50%;overflow:hidden;background:linear-gradient(135deg,#1A2540,#0D1120);border:3px solid #F5A623;display:flex;align-items:center;justify-content:center;box-shadow:0 0 0 4px rgba(245,166,35,.08),0 8px 24px rgba(0,0,0,.4);}
.km-cert-photo img{width:100%;height:100%;object-fit:cover;}
.km-cert-photo-initial{font-family:"Outfit",sans-serif;font-size:36px;font-weight:900;color:#F5A623;}
.km-cert-photo::before{content:"";position:absolute;inset:-3px;border-radius:50%;background:conic-gradient(from 0deg,#F5A623,#00D67F,#4C9FFF,#F5A623);z-index:-1;filter:blur(8px);opacity:.35;animation:kmCertSpin 8s linear infinite;}
@keyframes kmCertSpin{to{transform:rotate(360deg);}}

.km-cert-name{font-family:"Outfit",sans-serif;font-size:24px;font-weight:800;color:#F5F8FF;letter-spacing:-.02em;line-height:1.1;margin-bottom:4px;}
.km-cert-username{font-size:12px;color:#8A9AB8;margin-bottom:14px;}

.km-cert-chips{display:flex;flex-wrap:wrap;justify-content:center;gap:6px;margin-bottom:18px;}
.km-cert-chip{display:inline-flex;align-items:center;padding:5px 12px;border-radius:100px;font-size:11px;font-weight:600;background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.25);color:#F5A623;}
.km-cert-chip-accent{background:rgba(0,214,127,.1);border-color:rgba(0,214,127,.25);color:#00D67F;}

.km-cert-mention{font-size:15px;font-weight:700;color:#F5A623;margin-bottom:4px;font-family:"Outfit",sans-serif;}
.km-cert-signed{font-size:12px;color:#C8D1E0;}
.km-cert-signed strong{color:#F5F8FF;font-weight:600;}

.km-cert-attestation{margin-top:20px;padding:16px 18px;background:rgba(245,166,35,.05);border:1px solid rgba(245,166,35,.15);border-radius:14px;text-align:left;}
.km-cert-attestation-title{font-size:11px;font-weight:700;color:#F5A623;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.km-cert-attestation-text{font-size:12px;color:#C8D1E0;line-height:1.55;}

/* ── Footer ── */
.km-cert-footer{padding:18px 30px;background:rgba(0,0,0,.25);border-top:1px solid rgba(255,255,255,.05);}
.km-cert-footer-row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.km-cert-ref{font-family:"JetBrains Mono","Courier New",monospace;font-size:10px;color:#8A9AB8;background:rgba(0,0,0,.3);padding:4px 10px;border-radius:6px;border:1px solid rgba(255,255,255,.05);}
.km-cert-date{font-size:10px;color:#5A6A8A;}

.km-cert-copyright{text-align:center;font-size:10px;color:#5A6A8A;margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.05);letter-spacing:.3px;}
.km-cert-copyright strong{color:#F5A623;font-weight:700;}

.km-cert-cta{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:14px;padding:12px 22px;background:linear-gradient(135deg,#F5A623,#FFB940);color:#000;border-radius:10px;font-weight:700;text-decoration:none;font-size:13px;transition:all .2s;box-shadow:0 6px 18px rgba(245,166,35,.22);}
.km-cert-cta:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(245,166,35,.35);}

/* ── Error state inside main card ── */
.km-cert-warning{margin:18px 30px 0;padding:12px 16px;background:rgba(255,78,106,.08);border:1px solid rgba(255,78,106,.3);border-radius:10px;color:#FF7590;font-size:12px;display:flex;align-items:flex-start;gap:8px;line-height:1.4;}
.km-cert-warning svg{flex-shrink:0;margin-top:1px;}

/* ── Responsive ── */
@media (max-width:520px){
  .km-cert-wrap{padding:24px 16px;}
  .km-cert-card{border-radius:22px;}
  .km-cert-header{padding:24px 22px 18px;}
  .km-cert-body{padding:24px 22px 20px;}
  .km-cert-footer{padding:14px 22px;}
  .km-cert-name{font-size:20px;}
  .km-cert-photo{width:80px;height:80px;}
  .km-cert-photo-initial{font-size:30px;}
  .km-cert-mention{font-size:14px;}
  .km-cert-footer-row{flex-direction:column;align-items:center;gap:6px;}
}
@media (prefers-reduced-motion:reduce){
  .km-cert-orb,.km-cert-photo::before,.km-cert-badge-dot{animation:none;}
}
</style>
</head>
<body>
<div class="km-cert-bg"><div class="km-cert-orb km-cert-orb-1"></div><div class="km-cert-orb km-cert-orb-2"></div><div class="km-cert-orb km-cert-orb-3"></div></div>
<div class="km-cert-grid"></div>

<div class="km-cert-wrap">
  <div class="km-cert-card">

    <!-- HEADER -->
    <div class="km-cert-header">
      <?php if($logo_url):?>
        <img src="<?php echo esc_url($logo_url);?>" alt="KOPHI'S MUSIC" class="km-cert-logo"/>
      <?php else:?>
        <div class="km-cert-logo-fallback">KM</div>
      <?php endif;?>

      <?php if($is_valid):?>
        <div class="km-cert-badge">
          <span class="km-cert-badge-dot"></span>
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
          Document Authentifié
        </div>
      <?php else:?>
        <div class="km-cert-badge km-cert-badge-error">
          <span class="km-cert-badge-dot"></span>
          Référence non reconnue
        </div>
      <?php endif;?>

      <div class="km-cert-title">Relevé de Droits · Artiste Officiel</div>
    </div>

    <?php if(!$is_valid):?>
    <div class="km-cert-warning">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span>La signature numérique de ce document est invalide ou expirée. Contactez le support KOPHI'S MUSIC pour vérification.</span>
    </div>
    <?php endif;?>

    <!-- BODY -->
    <div class="km-cert-body">
      <div class="km-cert-photo">
        <?php if($artist_photo):?>
          <img src="<?php echo esc_url($artist_photo);?>" alt="<?php echo esc_attr($full_name);?>"/>
        <?php else:?>
          <span class="km-cert-photo-initial"><?php echo esc_html(strtoupper(substr($full_name,0,1)));?></span>
        <?php endif;?>
      </div>

      <h1 class="km-cert-name"><?php echo esc_html($full_name);?></h1>
      <div class="km-cert-username">@<?php echo esc_html($user->user_login);?></div>

      <div class="km-cert-chips">
        <?php if($genres_str):?>
          <span class="km-cert-chip">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
            <?php echo esc_html($genres_str);?>
          </span>
        <?php endif;?>
        <?php if($year_sign):?>
          <span class="km-cert-chip km-cert-chip-accent">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            Signé <?php echo esc_html($year_sign);?>
          </span>
        <?php endif;?>
      </div>

      <div class="km-cert-mention">Artiste KOPHI'S MUSIC<?php if($year_sign):?> depuis <?php echo esc_html($year_sign);?><?php endif;?></div>
      <?php if($date_sign):?>
        <div class="km-cert-signed">Contrat signé le <strong><?php echo esc_html($date_sign);?></strong> · KOPHI'S GROUP SAS</div>
      <?php endif;?>

      <div class="km-cert-attestation">
        <div class="km-cert-attestation-title">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Attestation officielle
        </div>
        <p class="km-cert-attestation-text">
          Ce document atteste de l'authenticité du relevé de droits de streaming émis par <strong style="color:#F5A623;">KOPHI'S MUSIC</strong> au nom de l'artiste susmentionné. Relevé certifié par <strong style="color:#F5F8FF;">KOPHI'S GROUP SAS</strong>.
        </p>
      </div>
    </div>

    <!-- FOOTER -->
    <div class="km-cert-footer">
      <div class="km-cert-footer-row">
        <?php if($ref):?>
          <div class="km-cert-ref">Réf · <?php echo esc_html($ref);?></div>
        <?php endif;?>
        <div class="km-cert-date">
          <?php if($cert_record && !empty($cert_record['date'])):?>
            Émis le <?php echo esc_html($cert_record['date']);?> · Vérifié le <?php echo esc_html($date_verif);?>
          <?php else:?>
            Vérifié le <?php echo esc_html($date_verif);?>
          <?php endif;?>
        </div>
      </div>
      <div class="km-cert-copyright">
        &copy; <strong>KOPHI'S MUSIC</strong> <?php echo date('Y');?> · KOPHI'S GROUP SAS, Abidjan, CI
      </div>
      <a href="https://kophismusic.com" class="km-cert-cta">
        Visiter kophismusic.com
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
      </a>
    </div>

  </div>
</div>
</body>
</html>
    <?php
    return ob_get_clean();
}
