<?php
/**
 * KM SALLE D'ÉCOUTE v1.7 — Template session d'écoute
 * Lecteur HTML5 unique (une seule balise <audio>), barre de lecture pro.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$I     = 'KM_Salle_Ecoute_Icons';
$pid   = get_the_ID();
$label = KM_Salle_Ecoute_ACF_Helper::label_nom();
$email = get_option( 'kse_label_email', '' );

// Champs ACF
$acces        = KM_Salle_Ecoute_Access_Control::access_type( $pid );
$has_access   = KM_Salle_Ecoute_Access_Control::has_access( $pid );
$wm           = get_field( 'km_marque_watermark',    $pid );
$dl_ok        = KM_Salle_Ecoute_Access_Control::download_allowed( $pid );
$msg_partner  = get_field( 'km_message_partenaire',  $pid );
$desc         = get_field( 'km_description_session', $pid );
$artiste_obj  = KM_Salle_Ecoute_ACF_Helper::artiste_obj( $pid );
$cover        = get_field( 'km_cover_art',           $pid );
$bpm          = get_field( 'km_bpm',                 $pid );
$tona         = get_field( 'km_tonalite',            $pid );
$date_c       = get_field( 'km_date_creation',       $pid );
$expire_ts    = KM_Salle_Ecoute_Access_Control::expiration_timestamp( $pid );

$pistes = KM_Salle_Ecoute_ACF_Helper::get_pistes( $pid );
$titre  = get_the_title();

$artiste_nom = KM_Salle_Ecoute_ACF_Helper::artiste_nom( $pid );

if ( ! is_array( $cover ) ) $cover = [];
$cover_url   = $cover['url']                ?? '';
$cover_med   = $cover['sizes']['medium']    ?? $cover_url;
$cover_thumb = $cover['sizes']['thumbnail'] ?? $cover_med;

$types  = get_the_terms( $pid, 'kse_type_contenu' ) ?: [];
$genres = get_the_terms( $pid, 'kse_genre' )        ?: [];
if ( is_wp_error( $types ) )  $types  = [];
if ( is_wp_error( $genres ) ) $genres = [];

$type_labels = [
    'demo'         => __( 'Démo', 'km-salle-ecoute' ),
    'produit_fini' => __( 'Produit Fini', 'km-salle-ecoute' ),
    'instrumental' => __( 'Instrumental', 'km-salle-ecoute' ),
    'remix'        => __( 'Remix', 'km-salle-ecoute' ),
];
$chip_cls    = [ 'Produit Fini' => 'km-chip-produit', 'Démo' => 'km-chip-demo', 'Demo' => 'km-chip-demo', 'Instrumental' => 'km-chip-instr', 'Remix' => 'km-chip-remix' ];

$nb_pistes = count( $pistes );
$kse_error = isset( $_GET['kse_error'] ) ? sanitize_key( wp_unslash( $_GET['kse_error'] ) ) : '';
$acc       = $I::access( (string) $acces );
$nb_txt    = sprintf( _n( '%d piste', '%d pistes', $nb_pistes, 'km-salle-ecoute' ), $nb_pistes );

get_header();
?>
<div class="km-page km-scope"
     data-session-title="<?php echo esc_attr( $titre ); ?>"
     data-session-artist="<?php echo esc_attr( $artiste_nom ?: $label ); ?>"
     data-session-album="<?php echo esc_attr( $label ); ?>"
     data-session-cover="<?php echo esc_url( $cover_med ); ?>">

<?php if ( ! $has_access && 'password' === $acces ) : /* ══ ACCÈS PROTÉGÉ ══ */ ?>

<div class="km-gate">
    <div class="km-gate-card">
        <div class="km-gate-icon"><?php echo $I::svg( 'lock', 30 ); ?></div>
        <h2><?php esc_html_e( 'Accès restreint', 'km-salle-ecoute' ); ?></h2>
        <p><?php esc_html_e( 'Cette session est protégée par le Label. Saisissez le mot de passe pour accéder aux pistes.', 'km-salle-ecoute' ); ?></p>

        <div class="km-gate-prev">
            <?php if ( $cover_thumb ) : ?>
            <img src="<?php echo esc_url( $cover_thumb ); ?>" class="km-gate-prev-img" alt="">
            <?php else : ?>
            <div class="km-gate-prev-ph"><?php echo $I::svg( 'music', 20 ); ?></div>
            <?php endif; ?>
            <div>
                <p class="km-gate-prev-name"><?php echo esc_html( $titre ); ?></p>
                <?php if ( $artiste_nom ) : ?><p class="km-gate-prev-artist"><?php echo esc_html( $artiste_nom ); ?></p><?php endif; ?>
            </div>
        </div>

        <div id="km-err" class="km-gate-err" role="alert"><?php echo $I::svg( 'alert', 16 ); ?><span id="km-err-txt"></span></div>
        <?php if ( '' !== $kse_error ) :
            $kse_err_msg = ( '2' === $kse_error )
                ? __( 'Trop de tentatives. Réessayez dans quelques minutes.', 'km-salle-ecoute' )
                : __( 'Mot de passe incorrect. Réessayez.', 'km-salle-ecoute' );
        ?>
        <div class="km-gate-err show" role="alert"><?php echo $I::svg( 'alert', 16 ); ?><span><?php echo esc_html( $kse_err_msg ); ?></span></div>
        <?php endif; ?>

        <form class="km-gate-form" id="km-gate-form" method="post" novalidate>
        <?php wp_nonce_field( 'kse_password', 'kse_password_nonce' ); ?>
        <input type="hidden" name="kse_post_id" value="<?php echo esc_attr( $pid ); ?>">
        <input type="text" name="username" value="<?php echo esc_attr( $titre ); ?>" autocomplete="username" hidden aria-hidden="true" tabindex="-1">
        <div class="km-gate-field">
            <input type="password" id="km-pwd" name="km_password" class="km-gate-input" required
                   placeholder="<?php esc_attr_e( 'Mot de passe', 'km-salle-ecoute' ); ?>" autocomplete="current-password" autofocus>
            <button type="button" id="km-pwd-eye" class="km-b km-gate-eye" aria-label="<?php esc_attr_e( 'Afficher le mot de passe', 'km-salle-ecoute' ); ?>">
                <span class="ico-on"><?php echo $I::svg( 'eye', 18 ); ?></span><span class="ico-off"><?php echo $I::svg( 'eye-off', 18 ); ?></span>
            </button>
        </div>
        <button type="submit" id="km-pwd-btn" class="km-b km-gate-btn">
            <?php echo $I::svg( 'lock', 16 ); ?><span class="km-btn-label"><?php esc_html_e( 'Accéder à l\'écoute', 'km-salle-ecoute' ); ?></span>
        </button>
        </form>
        <?php if ( $email ) : ?>
        <p class="km-gate-hint"><?php esc_html_e( 'Pas de mot de passe ?', 'km-salle-ecoute' ); ?>
            <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php esc_html_e( 'Contacter le Label', 'km-salle-ecoute' ); ?> →</a></p>
        <?php endif; ?>
    </div>
</div>

<?php elseif ( ! $has_access ) : /* ══ ACCÈS REFUSÉ (sécurité : rien d'autre n'est affiché) ══ */ ?>

<div class="km-gate">
    <div class="km-gate-card">
        <div class="km-gate-icon"><?php echo $I::svg( 'ban', 30 ); ?></div>
        <h2><?php esc_html_e( 'Accès restreint', 'km-salle-ecoute' ); ?></h2>
        <p><?php esc_html_e( 'Vous n\'avez pas accès à cette session d\'écoute.', 'km-salle-ecoute' ); ?></p>
        <?php if ( $email ) : ?>
        <a class="km-b km-gate-btn" href="mailto:<?php echo esc_attr( $email ); ?>"><?php esc_html_e( 'Contacter le Label', 'km-salle-ecoute' ); ?></a>
        <?php endif; ?>
    </div>
</div>

<?php else : /* ══ SESSION ══ */

$title_len = mb_strlen( $titre );
$title_cls = $title_len <= 14 ? 'km-title-xl' : ( $title_len <= 26 ? 'km-title-lg' : ( $title_len <= 44 ? 'km-title-md' : 'km-title-sm' ) );
$known_total = 0;
foreach ( $pistes as $p ) $known_total += KM_Salle_Ecoute_ACF_Helper::duration_to_seconds( $p['duree'] );
$year     = ( $date_c && strtotime( $date_c ) ) ? wp_date( 'Y', strtotime( $date_c ) ) : '';
$cat_url  = KM_Salle_Ecoute_Pages::catalogue_url();
$has_any_lyrics = false;
foreach ( $pistes as $p ) { if ( '' !== $p['paroles'] ) { $has_any_lyrics = true; break; } }
$avatar   = $artiste_obj ? get_the_post_thumbnail_url( $artiste_obj->ID, 'thumbnail' ) : '';
?>

<!-- ── BARRE SUPÉRIEURE (apparaît au défilement) ── -->
<div class="km-top" id="km-top">
    <a class="km-b km-top-back" href="<?php echo esc_url( $cat_url ); ?>" aria-label="<?php esc_attr_e( 'Toutes les sessions', 'km-salle-ecoute' ); ?>" title="<?php esc_attr_e( 'Toutes les sessions', 'km-salle-ecoute' ); ?>"><?php echo $I::svg( 'arrow-left', 18 ); ?></a>
    <div class="km-top-now">
        <?php if ( $nb_pistes ) : ?>
        <button type="button" class="km-b km-top-play km-pp" id="km-top-play" tabindex="-1" aria-label="<?php esc_attr_e( 'Lecture / pause', 'km-salle-ecoute' ); ?>">
            <span class="ico-play"><?php echo $I::svg( 'play', 18 ); ?></span><span class="ico-pause"><?php echo $I::svg( 'pause', 18 ); ?></span>
        </button>
        <?php endif; ?>
        <span class="km-top-title"><?php echo esc_html( $titre ); ?></span>
    </div>
    <span class="km-top-label"><?php echo $I::svg( 'headphones', 14 ); ?><?php echo esc_html( $label ); ?></span>
</div>

<!-- ── HERO ── -->
<header class="km-hero">
    <?php if ( $cover_url ) : ?>
    <div class="km-hero-bg" style="background-image:url('<?php echo esc_url( $cover_med ?: $cover_url ); ?>')"></div>
    <?php endif; ?>
    <div class="km-hero-gradient"></div>

    <div class="km-hero-inner">
        <div class="km-cover-wrap">
            <?php if ( $cover_url ) : ?>
            <button type="button" class="km-b km-cover-btn" id="km-cover-btn" data-full="<?php echo esc_url( $cover_url ); ?>" aria-label="<?php esc_attr_e( 'Agrandir la pochette', 'km-salle-ecoute' ); ?>">
                <img src="<?php echo esc_url( $cover['sizes']['large'] ?? $cover_url ); ?>" alt="<?php echo esc_attr( $titre ); ?>"
                     class="km-cover-img" id="km-cover" data-sample="<?php echo esc_url( $cover_thumb ?: $cover_url ); ?>" loading="eager" decoding="async">
                <span class="km-cover-zoom" aria-hidden="true"><?php echo $I::svg( 'expand', 18 ); ?></span>
            </button>
            <?php else : ?>
            <div class="km-cover-ph"><?php echo $I::svg( 'music', 84 ); ?></div>
            <?php endif; ?>
        </div>

        <div class="km-hero-text">
            <span class="km-type-label"><?php esc_html_e( 'Session d\'écoute', 'km-salle-ecoute' ); ?></span>
            <h1 class="km-title <?php echo esc_attr( $title_cls ); ?>"><?php echo esc_html( $titre ); ?></h1>

            <div class="km-byline">
                <?php if ( $avatar ) : ?><img src="<?php echo esc_url( $avatar ); ?>" class="km-artist-avatar" alt=""><?php endif; ?>
                <?php
                $by = [];
                if ( $artiste_nom ) $by[] = '<strong class="km-by-artist">' . esc_html( $artiste_nom ) . '</strong>';
                $by[] = '<span>' . esc_html( $label ) . '</span>';
                if ( $year ) $by[] = '<span>' . esc_html( $year ) . '</span>';
                if ( $nb_pistes ) $by[] = '<span>' . esc_html( $nb_txt ) . '<span id="km-total-dur" data-known="' . (int) $known_total . '"></span></span>';
                echo implode( '<span class="dot" aria-hidden="true">•</span>', $by );
                ?>
            </div>

            <?php if ( $types || $genres || $bpm || $tona ) : ?>
            <div class="km-chips">
                <?php foreach ( $types as $t ) :
                    echo '<span class="km-chip ' . esc_attr( $chip_cls[ $t->name ] ?? '' ) . '">' . $I::svg( $I::for_type( $t->name ), 12 ) . esc_html( $t->name ) . '</span>';
                endforeach;
                foreach ( $genres as $g ) :
                    echo '<span class="km-chip km-chip-genre">' . esc_html( $g->name ) . '</span>';
                endforeach;
                if ( $bpm )  echo '<span class="km-chip km-chip-genre">' . esc_html( $bpm ) . ' BPM</span>';
                if ( $tona ) echo '<span class="km-chip km-chip-genre">' . esc_html( $tona ) . '</span>'; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="km-body">
    <!-- ── ACTIONS ── -->
    <?php if ( $nb_pistes ) : ?>
    <div class="km-actions">
        <button type="button" class="km-b km-btn-play-main km-pp" id="km-btn-main" aria-label="<?php esc_attr_e( 'Lancer l\'écoute', 'km-salle-ecoute' ); ?>">
            <span class="ico-play"><?php echo $I::svg( 'play', 28 ); ?></span><span class="ico-pause"><?php echo $I::svg( 'pause', 28 ); ?></span>
            <span class="km-sr" id="km-main-label"><?php esc_html_e( 'Écouter', 'km-salle-ecoute' ); ?></span>
        </button>
        <button type="button" class="km-b km-act km-act-shuffle" id="km-act-shuffle" aria-pressed="false" title="<?php esc_attr_e( 'Lecture aléatoire (A)', 'km-salle-ecoute' ); ?>" aria-label="<?php esc_attr_e( 'Lecture aléatoire', 'km-salle-ecoute' ); ?>"><?php echo $I::svg( 'shuffle', 24 ); ?></button>
        <button type="button" class="km-b km-act" id="km-act-lyrics" title="<?php esc_attr_e( 'Paroles (L)', 'km-salle-ecoute' ); ?>" aria-label="<?php esc_attr_e( 'Afficher les paroles', 'km-salle-ecoute' ); ?>"<?php echo $has_any_lyrics ? '' : ' hidden'; ?>><?php echo $I::svg( 'lyrics', 24 ); ?></button>
        <div class="km-help-wrap">
            <button type="button" class="km-b km-act" id="km-help-btn" aria-expanded="false" aria-controls="km-help" title="<?php esc_attr_e( 'Raccourcis clavier', 'km-salle-ecoute' ); ?>" aria-label="<?php esc_attr_e( 'Raccourcis clavier', 'km-salle-ecoute' ); ?>"><?php echo $I::svg( 'keyboard', 24 ); ?></button>
            <div class="km-help" id="km-help" hidden>
                <h4><?php esc_html_e( 'Raccourcis clavier', 'km-salle-ecoute' ); ?></h4>
                <ul>
                    <li><span><?php esc_html_e( 'Lecture / pause', 'km-salle-ecoute' ); ?></span><kbd>Espace</kbd></li>
                    <li><span><?php esc_html_e( 'Avancer / reculer 10 s', 'km-salle-ecoute' ); ?></span><kbd>← →</kbd></li>
                    <li><span><?php esc_html_e( 'Aller à 0 % … 90 %', 'km-salle-ecoute' ); ?></span><kbd>0 – 9</kbd></li>
                    <li><span><?php esc_html_e( 'Piste suivante / précédente', 'km-salle-ecoute' ); ?></span><kbd>N · P</kbd></li>
                    <li><span><?php esc_html_e( 'Volume', 'km-salle-ecoute' ); ?></span><kbd>↑ ↓</kbd></li>
                    <li><span><?php esc_html_e( 'Muet', 'km-salle-ecoute' ); ?></span><kbd>M</kbd></li>
                    <li><span><?php esc_html_e( 'Répétition', 'km-salle-ecoute' ); ?></span><kbd>R</kbd></li>
                    <li><span><?php esc_html_e( 'Lecture aléatoire', 'km-salle-ecoute' ); ?></span><kbd>A</kbd></li>
                    <li><span><?php esc_html_e( 'Vitesse', 'km-salle-ecoute' ); ?></span><kbd>S</kbd></li>
                    <li><span><?php esc_html_e( 'Paroles', 'km-salle-ecoute' ); ?></span><kbd>L</kbd></li>
                </ul>
            </div>
        </div>
        <div class="km-facts">
            <span class="km-fact"><?php echo $I::svg( $acc['icon'], 14 ); ?><?php echo esc_html( $acc['label'] ); ?></span>
            <?php if ( $expire_ts ) :
                $days_left = (int) floor( ( $expire_ts - time() ) / DAY_IN_SECONDS ); ?>
            <span class="km-fact<?php echo $days_left < 3 ? ' is-warn' : ''; ?>"><?php echo $I::svg( 'clock', 14 ); ?><?php echo esc_html( sprintf( __( 'Jusqu\'au %s', 'km-salle-ecoute' ), wp_date( 'j F Y', $expire_ts ) ) ); ?></span>
            <?php endif; ?>
            <?php if ( ! $dl_ok ) : ?>
            <span class="km-fact"><?php echo $I::svg( 'ban', 14 ); ?><?php esc_html_e( 'Écoute seule', 'km-salle-ecoute' ); ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ( $msg_partner || $wm ) : ?>
    <div class="km-hero-notices">
        <?php if ( $msg_partner ) : ?>
        <div class="km-notice km-notice-info"><?php echo $I::svg( 'message', 16 ); ?><div><?php echo wp_kses_post( nl2br( $msg_partner ) ); ?></div></div>
        <?php endif; ?>
        <?php if ( $wm ) : ?>
        <div class="km-notice km-notice-warn"><?php echo $I::svg( 'alert', 16 ); ?><div><?php esc_html_e( 'Contenu confidentiel — usage évaluation uniquement. Toute reproduction est interdite.', 'km-salle-ecoute' ); ?></div></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="km-main<?php echo ( $has_any_lyrics || $desc ) ? ' has-side' : ''; ?>">
    <!-- ── LISTE DES PISTES ── -->
    <div class="km-tracklist">
    <?php if ( $pistes ) : ?>
        <div class="km-tl-head" aria-hidden="true">
            <div class="km-th center">#</div><div class="km-th"><?php esc_html_e( 'Titre', 'km-salle-ecoute' ); ?></div>
            <div class="km-th"></div><div class="km-th right"><?php echo $I::svg( 'clock', 15 ); ?></div>
        </div>

        <?php foreach ( $pistes as $i => $p ) :
            $audio_url = $p['url'];
            $tid       = 'tid' . abs( $pid ) . 'i' . $i;
            $dur       = $p['duree'] ?: '—';
            $dl_url    = ( $dl_ok && $audio_url ) ? KM_Salle_Ecoute_Access_Control::get_download_url( $pid, $i ) : '';
        ?>
        <div class="km-track<?php echo $audio_url ? '' : ' is-disabled'; ?>" data-tid="<?php echo esc_attr( $tid ); ?>" data-url="<?php echo esc_url( $audio_url ); ?>" data-title="<?php echo esc_attr( $p['titre'] ); ?>" data-duration="<?php echo esc_attr( KM_Salle_Ecoute_ACF_Helper::duration_to_seconds( $p['duree'] ) ); ?>">
            <div class="km-tr" role="button" tabindex="<?php echo $audio_url ? '0' : '-1'; ?>"
                 aria-label="<?php echo esc_attr( sprintf( __( 'Lire %s', 'km-salle-ecoute' ), $p['titre'] ) ); ?>"
                 <?php if ( ! $audio_url ) echo 'aria-disabled="true"'; ?>>
                <div class="km-tr-n">
                    <span class="km-tr-num"><?php echo (int) ( $i + 1 ); ?></span>
                    <span class="km-tr-icon"><span class="ico-play"><?php echo $I::svg( 'play', 18 ); ?></span><span class="ico-pause"><?php echo $I::svg( 'pause', 18 ); ?></span></span>
                    <span class="km-tr-spinner" aria-hidden="true"></span>
                    <span class="km-eq" aria-hidden="true"><span class="km-eq-b"></span><span class="km-eq-b"></span><span class="km-eq-b"></span><span class="km-eq-b"></span></span>
                </div>
                <div class="km-tr-info">
                    <div class="km-tr-title"><?php echo esc_html( $p['titre'] ); ?></div>
                    <div class="km-tr-sub">
                        <span class="km-tr-badge km-b-<?php echo esc_attr( $p['type'] ); ?>"><?php echo esc_html( $type_labels[ $p['type'] ] ?? $p['type'] ); ?></span>
                        <?php if ( $artiste_nom ) : ?><span class="km-tr-artist"><?php echo esc_html( $artiste_nom ); ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="km-tr-tools">
                    <?php if ( '' !== $p['paroles'] ) : ?>
                    <button type="button" class="km-b km-tr-tool km-tr-lyr" title="<?php esc_attr_e( 'Paroles', 'km-salle-ecoute' ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Paroles de %s', 'km-salle-ecoute' ), $p['titre'] ) ); ?>"><?php echo $I::svg( 'lyrics', 16 ); ?></button>
                    <?php endif; ?>
                    <?php if ( $dl_url ) : ?>
                    <a href="<?php echo esc_url( $dl_url ); ?>" class="km-b km-tr-tool km-dl-link" title="<?php esc_attr_e( 'Télécharger', 'km-salle-ecoute' ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Télécharger %s', 'km-salle-ecoute' ), $p['titre'] ) ); ?>"><?php echo $I::svg( 'download', 16 ); ?></a>
                    <?php endif; ?>
                </div>
                <div class="km-tr-dur"><?php echo esc_html( $dur ); ?></div>
            </div>
            <?php if ( '' !== $p['paroles'] ) : ?>
            <div class="km-tr-lyrics" hidden><?php echo esc_html( $p['paroles'] ); ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <audio class="km-audio" id="km-audio" preload="none" playsinline></audio>
        <div class="km-toast" id="km-toast" role="status" aria-live="polite" hidden></div>

    <?php else : ?>
        <div class="km-empty"><?php echo $I::svg( 'music', 52 ); ?><p><?php esc_html_e( 'Aucune piste disponible pour cette session.', 'km-salle-ecoute' ); ?></p></div>
    <?php endif; ?>
    </div>

    <?php if ( $has_any_lyrics || $desc ) : ?>
    <div class="km-side">
        <?php if ( $has_any_lyrics ) : ?>
        <section class="km-card km-lyrics" id="km-lyrics" aria-live="polite">
            <div class="km-card-head">
                <h2><?php esc_html_e( 'Paroles', 'km-salle-ecoute' ); ?></h2>
                <span class="km-lyrics-track" id="km-lyrics-track"></span>
            </div>
            <div class="km-lyrics-body" id="km-lyrics-body">
                <p class="km-lyrics-empty"><?php esc_html_e( 'Lancez un titre pour afficher ses paroles.', 'km-salle-ecoute' ); ?></p>
            </div>
        </section>
        <?php endif; ?>
        <?php if ( $desc ) : ?>
        <section class="km-card km-about">
            <div class="km-card-head"><h2><?php esc_html_e( 'À propos', 'km-salle-ecoute' ); ?></h2></div>
            <div class="km-about-body"><?php echo wp_kses_post( $desc ); ?></div>
        </section>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    </div><!-- .km-main -->
</div><!-- .km-body -->

<?php if ( $cover_url ) : ?>
<div class="km-lightbox" id="km-lightbox" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Pochette', 'km-salle-ecoute' ); ?>" hidden>
    <button type="button" class="km-b km-lightbox-close" id="km-lightbox-close" aria-label="<?php esc_attr_e( 'Fermer', 'km-salle-ecoute' ); ?>"><?php echo $I::svg( 'close', 22 ); ?></button>
    <img alt="<?php echo esc_attr( $titre ); ?>" id="km-lightbox-img">
</div>
<?php endif; ?>

<?php if ( $pistes ) : ?>
<!-- ── BARRE DE LECTURE ── -->
<div class="km-np" id="km-np" role="region" aria-label="<?php esc_attr_e( 'Lecteur', 'km-salle-ecoute' ); ?>">
    <button type="button" class="km-b km-np-collapse" id="np-collapse" aria-label="<?php esc_attr_e( 'Réduire le lecteur', 'km-salle-ecoute' ); ?>"><?php echo $I::svg( 'chevron-down', 26 ); ?></button>
    <div class="km-np-art">
        <?php if ( $cover_url ) : ?><img src="<?php echo esc_url( $cover['sizes']['large'] ?? $cover_url ); ?>" alt="" loading="lazy">
        <?php else : ?><div class="km-np-art-ph"><?php echo $I::svg( 'music', 72 ); ?></div><?php endif; ?>
    </div>
    <div class="km-np-inner">
        <div class="km-np-left" id="np-open" role="button" tabindex="-1" aria-label="<?php esc_attr_e( 'Agrandir le lecteur', 'km-salle-ecoute' ); ?>">
            <?php if ( $cover_thumb ) : ?><img src="<?php echo esc_url( $cover_thumb ); ?>" class="km-np-thumb" alt="">
            <?php else : ?><div class="km-np-thumb-ph"><?php echo $I::svg( 'music', 20 ); ?></div><?php endif; ?>
            <div class="km-np-meta">
                <div class="km-np-track" id="np-track">—</div>
                <div class="km-np-artist"><?php echo esc_html( $artiste_nom ?: $label ); ?></div>
            </div>
        </div>

        <div class="km-np-center">
            <div class="km-np-controls">
                <button type="button" class="km-b km-ibtn km-np-btn-shuffle" id="np-shuffle" aria-pressed="false" title="<?php esc_attr_e( 'Lecture aléatoire (A)', 'km-salle-ecoute' ); ?>" aria-label="<?php esc_attr_e( 'Lecture aléatoire : désactivée', 'km-salle-ecoute' ); ?>"><?php echo $I::svg( 'shuffle', 18 ); ?></button>
                <button type="button" class="km-b km-ibtn km-np-btn-prev" id="np-prev" title="<?php esc_attr_e( 'Précédent (P)', 'km-salle-ecoute' ); ?>" aria-label="<?php esc_attr_e( 'Piste précédente', 'km-salle-ecoute' ); ?>"><?php echo $I::svg( 'prev', 22 ); ?></button>
                <button type="button" class="km-b km-np-btn-play km-pp" id="np-play" aria-label="<?php esc_attr_e( 'Lecture / pause', 'km-salle-ecoute' ); ?>">
                    <span class="ico-play"><?php echo $I::svg( 'play', 22 ); ?></span><span class="ico-pause"><?php echo $I::svg( 'pause', 22 ); ?></span>
                </button>
                <button type="button" class="km-b km-ibtn km-np-btn-next" id="np-next" title="<?php esc_attr_e( 'Suivant (N)', 'km-salle-ecoute' ); ?>" aria-label="<?php esc_attr_e( 'Piste suivante', 'km-salle-ecoute' ); ?>"><?php echo $I::svg( 'next', 22 ); ?></button>
                <button type="button" class="km-b km-ibtn km-np-btn-repeat" id="np-repeat" data-repeat="off" aria-pressed="false" title="<?php esc_attr_e( 'Répétition (R)', 'km-salle-ecoute' ); ?>" aria-label="<?php esc_attr_e( 'Répétition : désactivée', 'km-salle-ecoute' ); ?>">
                    <span class="km-icon-repeat"><?php echo $I::svg( 'repeat', 18 ); ?></span><span class="km-icon-repeat-1"><?php echo $I::svg( 'repeat-1', 18 ); ?></span>
                </button>
            </div>
            <div class="km-np-progress">
                <span class="km-np-t" id="np-cur">0:00</span>
                <div class="km-seek km-np-seek" id="np-seek" data-seek="dock" role="slider" tabindex="0" aria-label="<?php esc_attr_e( 'Position de lecture', 'km-salle-ecoute' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <div class="km-seek-track"><div class="km-seek-buf"></div><div class="km-seek-fill"></div><div class="km-seek-thumb"></div></div>
                    <div class="km-seek-tip">0:00</div>
                </div>
                <span class="km-np-t" id="np-dur">—</span>
            </div>
        </div>

        <div class="km-np-right">
            <button type="button" class="km-b km-ibtn" id="np-lyrics" aria-pressed="false" title="<?php esc_attr_e( 'Paroles (L)', 'km-salle-ecoute' ); ?>" aria-label="<?php esc_attr_e( 'Paroles', 'km-salle-ecoute' ); ?>"<?php echo $has_any_lyrics ? '' : ' hidden'; ?>><?php echo $I::svg( 'lyrics', 18 ); ?></button>
            <button type="button" class="km-b km-np-speed" id="np-speed" title="<?php esc_attr_e( 'Vitesse de lecture (S)', 'km-salle-ecoute' ); ?>" aria-label="<?php esc_attr_e( 'Vitesse de lecture', 'km-salle-ecoute' ); ?>">1×</button>
            <div class="km-np-vol" id="np-vol-wrap" data-level="2">
                <button type="button" class="km-b km-ibtn" id="np-mute" title="<?php esc_attr_e( 'Muet (M)', 'km-salle-ecoute' ); ?>" aria-label="<?php esc_attr_e( 'Couper le son', 'km-salle-ecoute' ); ?>">
                    <span class="ico-vol ico-vol-2"><?php echo $I::svg( 'volume-2', 18 ); ?></span><span class="ico-vol ico-vol-1"><?php echo $I::svg( 'volume-1', 18 ); ?></span><span class="ico-vol ico-vol-x"><?php echo $I::svg( 'volume-x', 18 ); ?></span>
                </button>
                <input type="range" class="km-vol-range" id="np-vol" min="0" max="100" value="80" aria-label="<?php esc_attr_e( 'Volume', 'km-salle-ecoute' ); ?>">
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="km-footer">
    <div class="km-footer-inner">
        <p class="km-footer-brand"><strong><?php echo esc_html( $label ); ?></strong> — <?php esc_html_e( 'Propriété exclusive. Usage évaluation uniquement.', 'km-salle-ecoute' ); ?></p>
        <p class="km-footer-copy">© <?php echo esc_html( wp_date( 'Y' ) ); ?> <?php echo esc_html( $label ); ?></p>
    </div>
</div>

<?php endif; /* fin session */ ?>
</div><!-- .km-page -->
<?php get_footer(); ?>
