/**
 * SNIPPET 3 — SINGLE PAGE ACTUALITÉS v6
 * (remplace la v5 — même design, mêmes classes CSS)
 *
 * Corrections v6 :
 * ✅ Contenu vide : si le champ ACF « article_body » est vide, le texte de
 *    l'éditeur s'affichait « Contenu non disponible » (le filtre qui masque le
 *    contenu du thème vidait aussi notre propre contenu)
 * ✅ Plus d'erreur fatale si ACF ou le SNIPPET 1 (helpers act_*) est désactivé :
 *    on laisse alors la page par défaut du thème au lieu de casser le site
 * ✅ Le titre n'est plus effacé ailleurs que dans l'en-tête du thème
 *    (menus, widgets, fil d'Ariane SEO restent corrects)
 * ✅ Galerie / lightbox : boutons nommés pour les lecteurs d'écran, retour du
 *    focus à la fermeture, ouverture au clavier, Échap fiable
 * ✅ Image héro chargée en priorité, échappement complet des sorties
 */

/* ══════════════════════════════════════════════════
   OUTILS PARTAGÉS (identiques dans les snippets 2, 3 et 4,
   protégés par function_exists : aucun risque de doublon)
══════════════════════════════════════════════════ */
if ( ! function_exists( 'kmact_field' ) ) {
	function kmact_field( $key, $post_id = null ) {
		return function_exists( 'get_field' ) ? get_field( $key, $post_id ) : null;
	}
}
if ( ! function_exists( 'kmact_rgb' ) ) {
	function kmact_rgb( $hex, $fallback = '0,200,160' ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return $fallback;
		}
		return hexdec( substr( $hex, 0, 2 ) ) . ',' . hexdec( substr( $hex, 2, 2 ) ) . ',' . hexdec( substr( $hex, 4, 2 ) );
	}
}
if ( ! function_exists( 'kmact_color' ) ) {
	function kmact_color( $hex, $fallback = '#00C8A0' ) {
		$c = sanitize_hex_color( (string) $hex );
		return $c ? $c : $fallback;
	}
}
if ( ! function_exists( 'kmact_upper' ) ) {
	function kmact_upper( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text, 'UTF-8' ) : strtoupper( $text );
	}
}
if ( ! function_exists( 'kmact_call' ) ) {
	function kmact_call( $fn, $args = array(), $default = null ) {
		return function_exists( $fn ) ? call_user_func_array( $fn, (array) $args ) : $default;
	}
}

/* ══════════════════════════════════════════════════
   BRANCHEMENT SUR ASTRA
══════════════════════════════════════════════════ */
if ( ! function_exists( 'act_single_ready' ) ) {
	/** Le gabarit personnalisé n'est utilisé que si les helpers du SNIPPET 1 sont là. */
	function act_single_ready() {
		return function_exists( 'act_get_primary_category' )
			&& function_exists( 'act_get_category_color' )
			&& function_exists( 'act_get_cover_image_url' );
	}
}

if ( ! function_exists( 'act_single_hide_title' ) ) {
	/** Masque uniquement le titre affiché par le thème dans la boucle principale. */
	function act_single_hide_title( $title, $id = null ) {
		if ( $id && in_the_loop() && is_main_query() && (int) $id === (int) get_queried_object_id() && 'actualites' === get_post_type( $id ) ) {
			return '';
		}
		return $title;
	}
}

if ( ! function_exists( 'act_single_hide_content' ) ) {
	/** Masque le contenu affiché par le thème (notre gabarit l'affiche déjà). */
	function act_single_hide_content( $content ) {
		if ( is_singular( 'actualites' ) && in_the_loop() && is_main_query() ) {
			return '';
		}
		return $content;
	}
}

add_action( 'wp', function () {
	if ( ! is_singular( 'actualites' ) || ! act_single_ready() ) {
		return; // Sans les helpers, on garde la page standard du thème (pas d'erreur fatale).
	}
	remove_all_actions( 'astra_entry_top' );
	remove_action( 'astra_entry_content_before', 'astra_entry_header_template', 10 );
	remove_action( 'astra_primary_content_top', 'astra_breadcrumb_template', 10 );
	add_filter( 'astra_breadcrumbs_enabled', '__return_false' );
	add_filter( 'the_title', 'act_single_hide_title', 99, 2 );
	add_filter( 'the_content', 'act_single_hide_content', 99 );
	add_action( 'astra_entry_content_before', 'act_render_single_template', 1 );
}, 1 );


if ( ! function_exists( 'act_render_single_template' ) ) :
function act_render_single_template() {
	if ( ! is_singular( 'actualites' ) || ! act_single_ready() ) {
		return;
	}
	global $post;

	$pid       = $post->ID;
	$term      = act_get_primary_category( $pid );
	$color     = kmact_color( $term ? act_get_category_color( $term->slug ) : '#00C8A0' );
	$rgb       = function_exists( 'act_hex_to_rgb' ) ? act_hex_to_rgb( $color ) : kmact_rgb( $color );
	$rgb       = preg_match( '/^\d{1,3},\d{1,3},\d{1,3}$/', str_replace( ' ', '', (string) $rgb ) ) ? str_replace( ' ', '', $rgb ) : kmact_rgb( $color );
	$title_raw = get_post_field( 'post_title', $pid );
	$title_up  = kmact_upper( $title_raw );
	$img_url   = act_get_cover_image_url( $pid, 'full' );
	$date      = kmact_call( 'act_format_date_fr', array( $pid ), get_the_date( 'd M Y', $pid ) );
	$read_time = kmact_call( 'act_get_reading_time', array( $pid ), '' );
	$author    = get_the_author_meta( 'display_name', $post->post_author );

	// Corps : champ ACF, sinon contenu de l'éditeur. On retire momentanément
	// notre filtre « masquer le contenu » pour ne pas vider notre propre texte.
	$body = kmact_field( 'article_body', $pid );
	if ( ! $body ) {
		$had_filter = has_filter( 'the_content', 'act_single_hide_content' );
		if ( $had_filter ) {
			remove_filter( 'the_content', 'act_single_hide_content', 99 );
		}
		$body = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		if ( $had_filter ) {
			add_filter( 'the_content', 'act_single_hide_content', 99 );
		}
	}

	$artistes  = kmact_field( 'artistes_lies', $pid );
	$evenement = kmact_field( 'evenement_lie', $pid );
	$lien_ext  = kmact_field( 'lien_externe', $pid );
	$prev      = get_previous_post( false, '', 'categorie-actualite' );
	$next      = get_next_post( false, '', 'categorie-actualite' );

	$galerie = array();
	for ( $g = 1; $g <= 10; $g++ ) {
		$photo = kmact_field( 'galerie_photo_' . $g, $pid );
		if ( $photo && is_array( $photo ) && ! empty( $photo['url'] ) ) {
			$galerie[] = $photo;
		}
	}
	?>

	<div class="acts-wrap" style="--ac:<?php echo esc_attr( $color ); ?>;--ar:<?php echo esc_attr( $rgb ); ?>;">

	<!-- ═══ BLOC 1 — HEADER DARK NAVY CENTRÉ ═══ -->
	<header class="acts-header">
		<div class="acts-header__bg" aria-hidden="true">
			<div class="acts-header__bg-glow" style="background:radial-gradient(ellipse at 50% -20%,rgba(<?php echo esc_attr( $rgb ); ?>,.45) 0%,rgba(<?php echo esc_attr( $rgb ); ?>,.08) 45%,transparent 70%);"></div>
			<div class="acts-header__bg-bottom" style="background:linear-gradient(to bottom,transparent 70%,rgba(<?php echo esc_attr( $rgb ); ?>,.08) 100%);"></div>
			<div class="acts-header__bg-grid"></div>
		</div>

		<div class="acts-header__inner">

			<h1 class="acts-header__title" style="color:<?php echo esc_attr( $color ); ?>;">
				<?php echo esc_html( $title_up ); ?>
			</h1>

			<div class="acts-header__sep" style="background:<?php echo esc_attr( $color ); ?>;" aria-hidden="true"></div>

			<div class="acts-header__meta">
				<?php echo kmact_call( 'act_get_category_badge', array( $pid, 'large' ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php if ( $read_time ) : ?>
					<span class="acts-header__time" style="border-color:rgba(<?php echo esc_attr( $rgb ); ?>,.2);">
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
						<?php echo esc_html( $read_time ); ?> de lecture
					</span>
					<span class="acts-header__meta-sep" aria-hidden="true">·</span>
				<?php endif; ?>
				<span class="acts-header__meta-item">
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
					<?php echo esc_html( $author ); ?>
				</span>
				<span class="acts-header__meta-sep" aria-hidden="true">·</span>
				<span class="acts-header__meta-item">
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
					<time datetime="<?php echo esc_attr( get_the_date( 'c', $pid ) ); ?>"><?php echo esc_html( $date ); ?></time>
				</span>
			</div>

			<?php if ( $artistes && is_array( $artistes ) ) : ?>
				<div class="acts-header__artists">
					<?php
					foreach ( $artistes as $a ) :
						$a_id = is_object( $a ) ? $a->ID : (int) $a;
						if ( ! $a_id ) {
							continue;
						}
						$at = get_the_post_thumbnail_url( $a_id, 'thumbnail' );
						?>
						<a href="<?php echo esc_url( get_permalink( $a_id ) ); ?>" class="acts-header__artist-pill" style="border-color:rgba(<?php echo esc_attr( $rgb ); ?>,.45);color:<?php echo esc_attr( $color ); ?>;">
							<?php if ( $at ) : ?><img src="<?php echo esc_url( $at ); ?>" alt="" loading="lazy"><?php endif; ?>
							<?php echo esc_html( get_the_title( $a_id ) ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $lien_ext ) : ?>
				<a href="<?php echo esc_url( $lien_ext ); ?>" target="_blank" rel="noopener noreferrer" class="acts-header__ext" style="background:rgba(<?php echo esc_attr( $rgb ); ?>,.15);border-color:rgba(<?php echo esc_attr( $rgb ); ?>,.4);color:<?php echo esc_attr( $color ); ?>;">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
					VOIR LA SOURCE EXTERNE<span class="screen-reader-text"> (nouvel onglet)</span>
				</a>
			<?php endif; ?>
		</div>

		<div class="acts-header__bar" style="background:linear-gradient(to right,transparent,<?php echo esc_attr( $color ); ?>,transparent);" aria-hidden="true"></div>
	</header>

	<!-- ═══ BLOC 2 — IMAGE HÉRO CINÉMATIQUE ═══ -->
	<?php if ( $img_url ) : ?>
	<div class="acts-hero-img">
		<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( html_entity_decode( $title_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?>" class="acts-hero-img__photo" fetchpriority="high" decoding="async">
		<div class="acts-hero-img__left" style="background:linear-gradient(to right,rgba(<?php echo esc_attr( $rgb ); ?>,.3),transparent);"></div>
		<div class="acts-hero-img__right" style="background:linear-gradient(to left,rgba(<?php echo esc_attr( $rgb ); ?>,.15),transparent);"></div>
		<div class="acts-hero-img__fade"></div>
		<?php if ( $term ) : ?>
			<div class="acts-hero-img__tag" style="background:rgba(<?php echo esc_attr( $rgb ); ?>,.15);border-color:rgba(<?php echo esc_attr( $rgb ); ?>,.5);color:<?php echo esc_attr( $color ); ?>;">
				<?php echo esc_html( kmact_upper( $term->name ) ); ?>
			</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ═══ BLOC 3 — CORPS DE L'ARTICLE ═══ -->
	<section class="acts-body">
		<div class="acts-body__inner">

			<div class="acts-body__section-label" style="color:<?php echo esc_attr( $color ); ?>;">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
				ARTICLE
			</div>

			<?php if ( $body ) : ?>
				<div class="acts-body__content" style="--cc:<?php echo esc_attr( $color ); ?>;--cr:<?php echo esc_attr( $rgb ); ?>;">
					<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- contenu rédigé dans l'admin (ACF WYSIWYG / éditeur), embeds compris. ?>
				</div>
			<?php else : ?>
				<p class="acts-body__empty">Contenu non disponible.</p>
			<?php endif; ?>

			<?php
			$ev_id = is_object( $evenement ) ? $evenement->ID : ( is_numeric( $evenement ) ? (int) $evenement : 0 );
			if ( $ev_id ) :
				?>
				<div class="acts-body__event-row">
					<span class="acts-body__event-label">
						<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
						ÉVÉNEMENT LIÉ
					</span>
					<a href="<?php echo esc_url( get_permalink( $ev_id ) ); ?>" class="acts-body__event-pill" style="border-color:rgba(<?php echo esc_attr( $rgb ); ?>,.4);color:<?php echo esc_attr( $color ); ?>;">
						<?php echo esc_html( get_the_title( $ev_id ) ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
	</section>

	<!-- ═══ BLOC 4 — GALERIE PHOTO ═══ -->
	<?php
	if ( ! empty( $galerie ) ) :
		$total  = count( $galerie );
		$gid    = 'act-gallery-' . $pid;
		$nb_cls = 'act-gallery__grid--' . min( $total, 5 ) . 'up';
		$alt_fb = html_entity_decode( $title_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		?>
		<section class="acts-gallery-wrap act-gallery" id="<?php echo esc_attr( $gid ); ?>" aria-label="Galerie photo" style="--gallery-color:<?php echo esc_attr( $color ); ?>;--gallery-rgb:<?php echo esc_attr( $rgb ); ?>;">
			<div class="act-gallery__inner">
				<div class="act-gallery__header">
					<div class="act-gallery__header-left">
						<div class="act-gallery__icon" style="background:rgba(<?php echo esc_attr( $rgb ); ?>,.15);color:<?php echo esc_attr( $color ); ?>;" aria-hidden="true">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
						</div>
						<div>
							<h3 class="act-gallery__title">Galerie Photo</h3>
							<span class="act-gallery__count" style="color:<?php echo esc_attr( $color ); ?>;"><?php echo (int) $total; ?> photo<?php echo $total > 1 ? 's' : ''; ?></span>
						</div>
					</div>
					<button type="button" class="act-gallery__expand-all" data-gallery="<?php echo esc_attr( $gid ); ?>" style="border-color:<?php echo esc_attr( $color ); ?>;color:<?php echo esc_attr( $color ); ?>;">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
						Tout voir
					</button>
				</div>

				<div class="act-gallery__grid <?php echo esc_attr( $nb_cls ); ?>">
					<?php
					foreach ( $galerie as $idx => $photo ) :
						$sizes  = isset( $photo['sizes'] ) && is_array( $photo['sizes'] ) ? $photo['sizes'] : array();
						if ( 0 === $idx ) {
							$src = isset( $sizes['large'] ) ? $sizes['large'] : $photo['url'];
						} else {
							$src = isset( $sizes['medium_large'] ) ? $sizes['medium_large'] : ( isset( $sizes['large'] ) ? $sizes['large'] : $photo['url'] );
						}
						$alt    = ! empty( $photo['alt'] ) ? $photo['alt'] : $alt_fb;
						$cap    = isset( $photo['caption'] ) ? $photo['caption'] : '';
						$last_v = ( 4 === $idx && $total > 5 );
						?>
						<div class="act-gallery__item <?php echo 0 === $idx ? 'act-gallery__item--featured' : ''; ?> <?php echo $idx >= 5 ? 'act-gallery__item--hidden' : ''; ?>">
							<button type="button" class="act-gallery__thumb-btn" data-gallery="<?php echo esc_attr( $gid ); ?>" data-index="<?php echo (int) $idx; ?>" aria-label="<?php echo esc_attr( sprintf( 'Agrandir la photo %d sur %d', $idx + 1, $total ) ); ?>">
								<img src="<?php echo esc_url( $src ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="<?php echo $idx < 3 ? 'eager' : 'lazy'; ?>" decoding="async" class="act-gallery__thumb-img">
								<div class="act-gallery__thumb-overlay" style="background:rgba(<?php echo esc_attr( $rgb ); ?>,.75);" aria-hidden="true">
									<svg class="act-gallery__zoom-icon" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
									<?php if ( $cap ) : ?><span class="act-gallery__thumb-cap"><?php echo esc_html( $cap ); ?></span><?php endif; ?>
								</div>
								<?php if ( 0 === $idx ) : ?><div class="act-gallery__feat-badge" style="background:<?php echo esc_attr( $color ); ?>;">⭐ Photo principale</div><?php endif; ?>
								<?php if ( $last_v ) : ?><div class="act-gallery__more-badge" style="background:rgba(<?php echo esc_attr( $rgb ); ?>,.88);">+<?php echo (int) ( $total - 5 ); ?></div><?php endif; ?>
							</button>
						</div>
					<?php endforeach; ?>
				</div>

				<script type="application/json" id="<?php echo esc_attr( $gid ); ?>-data">
				<?php
				$jd = array();
				foreach ( $galerie as $ph ) {
					$s    = isset( $ph['sizes'] ) && is_array( $ph['sizes'] ) ? $ph['sizes'] : array();
					$jd[] = array(
						'full'    => esc_url_raw( $ph['url'] ),
						'medium'  => esc_url_raw( isset( $s['large'] ) ? $s['large'] : $ph['url'] ),
						'thumb'   => esc_url_raw( isset( $s['thumbnail'] ) ? $s['thumbnail'] : $ph['url'] ),
						'alt'     => isset( $ph['alt'] ) ? $ph['alt'] : '',
						'title'   => isset( $ph['title'] ) ? $ph['title'] : '',
						'caption' => isset( $ph['caption'] ) ? $ph['caption'] : '',
					);
				}
				echo wp_json_encode( $jd, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
				?>
				</script>
			</div>
		</section>

		<!-- LIGHTBOX -->
		<div class="act-lightbox" id="<?php echo esc_attr( $gid ); ?>-lightbox" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( 'Galerie : ' . $alt_fb ); ?>" aria-hidden="true" style="--lb-color:<?php echo esc_attr( $color ); ?>;--lb-rgb:<?php echo esc_attr( $rgb ); ?>;">
			<div class="act-lightbox__backdrop"></div>
			<div class="act-lightbox__container">
				<div class="act-lightbox__topbar">
					<div class="act-lightbox__topbar-left">
						<div class="act-lightbox__dot" style="background:<?php echo esc_attr( $color ); ?>;" aria-hidden="true"></div>
						<span class="act-lightbox__gallery-name"><?php echo esc_html( $title_up ); ?></span>
					</div>
					<div class="act-lightbox__counter" aria-live="polite"><span class="act-lightbox__current">1</span><span> / </span><span class="act-lightbox__total"><?php echo (int) $total; ?></span></div>
					<button type="button" class="act-lightbox__close" aria-label="Fermer la galerie"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
				</div>
				<div class="act-lightbox__stage">
					<button type="button" class="act-lightbox__nav act-lightbox__prev" aria-label="Photo précédente"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></button>
					<div class="act-lightbox__img-wrap">
						<div class="act-lightbox__loader"><div class="act-lightbox__spinner" style="border-top-color:<?php echo esc_attr( $color ); ?>;"></div></div>
						<img class="act-lightbox__img" src="" alt="" draggable="false">
						<div class="act-lightbox__caption-bar" style="display:none;"><span class="act-lightbox__caption-text"></span></div>
					</div>
					<button type="button" class="act-lightbox__nav act-lightbox__next" aria-label="Photo suivante"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></button>
				</div>
				<div class="act-lightbox__filmstrip">
					<div class="act-lightbox__filmstrip-track">
						<?php
						foreach ( $galerie as $fi => $fp ) :
							$ft = isset( $fp['sizes']['thumbnail'] ) ? $fp['sizes']['thumbnail'] : $fp['url'];
							?>
							<button type="button" class="act-lightbox__filmstrip-item <?php echo 0 === $fi ? 'active' : ''; ?>" data-index="<?php echo (int) $fi; ?>" aria-label="<?php echo esc_attr( sprintf( 'Photo %d', $fi + 1 ) ); ?>">
								<img src="<?php echo esc_url( $ft ); ?>" alt="" loading="lazy">
								<div class="act-lightbox__filmstrip-active-bar" style="background:<?php echo esc_attr( $color ); ?>;"></div>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
		<script>
		(function(){
			var GID=<?php echo wp_json_encode( $gid ); ?>;
			var de=document.getElementById(GID+'-data'),lb=document.getElementById(GID+'-lightbox'),gal=document.getElementById(GID);
			if(!de||!lb||!gal)return;
			var photos=[];try{photos=JSON.parse(de.textContent||de.innerText)||[];}catch(e){return;}
			if(!photos.length)return;
			var cur=0,open=false,zoom=false,lastFocus=null;
			var img=lb.querySelector('.act-lightbox__img'),loader=lb.querySelector('.act-lightbox__loader'),capBar=lb.querySelector('.act-lightbox__caption-bar'),capTxt=lb.querySelector('.act-lightbox__caption-text'),ctr=lb.querySelector('.act-lightbox__current'),fItems=lb.querySelectorAll('.act-lightbox__filmstrip-item'),fWrap=lb.querySelector('.act-lightbox__filmstrip'),cls=lb.querySelector('.act-lightbox__close'),prv=lb.querySelector('.act-lightbox__prev'),nxt=lb.querySelector('.act-lightbox__next'),bkd=lb.querySelector('.act-lightbox__backdrop');
			function load(i){
				cur=((i%photos.length)+photos.length)%photos.length;zoom=false;
				img.style.transform='scale(1)';img.style.cursor='zoom-in';loader.style.display='flex';img.style.opacity='0';
				var p=photos[cur],t=new Image(),want=cur;
				t.onload=function(){if(want!==cur)return;img.src=t.src;img.alt=p.alt||'';img.style.opacity='1';loader.style.display='none';};
				t.onerror=function(){if(want!==cur)return;img.src=p.full;img.style.opacity='1';loader.style.display='none';};
				t.src=(p.medium&&p.medium!==p.full)?p.medium:p.full;
				var c=p.caption||p.title||'';capTxt.textContent=c;capBar.style.display=c?'flex':'none';ctr.textContent=cur+1;
				Array.prototype.forEach.call(fItems,function(f,ix){f.classList.toggle('active',ix===cur);f.setAttribute('aria-current',ix===cur?'true':'false');});
				if(fItems[cur]&&fWrap&&fWrap.scrollTo){var it=fItems[cur];fWrap.scrollTo({left:it.offsetLeft-fWrap.offsetWidth/2+it.offsetWidth/2,behavior:'smooth'});}
				var s=photos.length>1;prv.style.display=s?'flex':'none';nxt.style.display=s?'flex':'none';
			}
			function openLB(i){lastFocus=document.activeElement;open=true;lb.classList.add('is-open');lb.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';load(i);setTimeout(function(){cls.focus();},120);}
			function closeLB(){open=false;lb.classList.remove('is-open');lb.setAttribute('aria-hidden','true');document.body.style.overflow='';if(lastFocus&&lastFocus.focus){lastFocus.focus();}}
			Array.prototype.forEach.call(gal.querySelectorAll('.act-gallery__thumb-btn'),function(b){b.addEventListener('click',function(){openLB(parseInt(this.getAttribute('data-index'),10)||0);});});
			var eb=gal.querySelector('.act-gallery__expand-all');if(eb)eb.addEventListener('click',function(){openLB(0);});
			prv.addEventListener('click',function(){load(cur-1);});nxt.addEventListener('click',function(){load(cur+1);});
			cls.addEventListener('click',closeLB);bkd.addEventListener('click',closeLB);
			Array.prototype.forEach.call(fItems,function(f){f.addEventListener('click',function(){load(parseInt(this.getAttribute('data-index'),10)||0);});});
			document.addEventListener('keydown',function(e){
				if(!open)return;
				if(e.key==='Escape'){e.preventDefault();closeLB();}
				else if(e.key==='ArrowLeft'){load(cur-1);}
				else if(e.key==='ArrowRight'){load(cur+1);}
				else if(e.key==='Tab'){ /* garde le focus dans la lightbox */
					var f=lb.querySelectorAll('button');if(!f.length)return;var first=f[0],last=f[f.length-1];
					if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
					else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
				}
			});
			var txS=0,stg=lb.querySelector('.act-lightbox__stage');
			stg.addEventListener('touchstart',function(e){txS=e.changedTouches[0].screenX;},{passive:true});
			stg.addEventListener('touchend',function(e){var dx=e.changedTouches[0].screenX-txS;if(Math.abs(dx)>44){if(dx<0){load(cur+1);}else{load(cur-1);}}},{passive:true});
			img.addEventListener('click',function(){zoom=!zoom;img.style.transform=zoom?'scale(1.7)':'scale(1)';img.style.cursor=zoom?'zoom-out':'zoom-in';});
		})();
		</script>
	<?php endif; ?>

	<!-- ═══ BLOC 5 — NAVIGATION PRÉCÉDENT / SUIVANT ═══ -->
	<nav class="acts-nav" aria-label="Navigation entre articles">
		<div class="acts-nav__inner acts-nav__inner--always-row">
			<?php
			foreach ( array( 'prev' => $prev, 'next' => $next ) as $dir => $np ) :
				$is_prev = 'prev' === $dir;
				if ( ! $np ) :
					?>
					<div class="acts-nav__empty<?php echo $is_prev ? '' : ' acts-nav__empty--r'; ?>"><span><?php echo $is_prev ? 'Pas d\'article précédent' : 'Pas d\'article suivant'; ?></span></div>
					<?php
					continue;
				endif;
				$nterm  = act_get_primary_category( $np->ID );
				$ncol   = kmact_color( $nterm ? act_get_category_color( $nterm->slug ) : '#555555', '#555555' );
				$nrgb   = kmact_rgb( $ncol, '85,85,85' );
				$nsq    = kmact_call( 'act_get_square_image_url', array( $np->ID, 'thumbnail' ), '' );
				$nimg   = $nsq ? $nsq : act_get_cover_image_url( $np->ID, 'medium' );
				$ntitle = kmact_upper( get_post_field( 'post_title', $np->ID ) );
				$nbadge = $nterm ? kmact_call( 'act_get_category_badge', array( $np->ID ), '' ) : '';
				ob_start();
				?>
				<div class="acts-nav__thumb acts-nav__thumb--square" style="border-color:rgba(<?php echo esc_attr( $nrgb ); ?>,.5);">
					<?php if ( $nimg ) : ?><img src="<?php echo esc_url( $nimg ); ?>" alt="" loading="lazy"><?php else : ?><div class="acts-nav__thumb-ph" style="background:rgba(<?php echo esc_attr( $nrgb ); ?>,.2);"></div><?php endif; ?>
				</div>
				<?php
				$thumb = ob_get_clean();
				?>
				<a href="<?php echo esc_url( get_permalink( $np->ID ) ); ?>" class="acts-nav__card <?php echo $is_prev ? 'acts-nav__prev' : 'acts-nav__next'; ?>" rel="<?php echo $is_prev ? 'prev' : 'next'; ?>">
					<?php if ( $is_prev ) { echo $thumb; } // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<div class="acts-nav__text<?php echo $is_prev ? '' : ' acts-nav__text--r'; ?>">
						<span class="acts-nav__dir">
							<?php if ( $is_prev ) : ?>
								<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
								PRÉCÉDENT
							<?php else : ?>
								SUIVANT
								<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
							<?php endif; ?>
						</span>
						<?php echo $nbadge; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span class="acts-nav__title"><?php echo esc_html( $ntitle ); ?></span>
					</div>
					<?php if ( ! $is_prev ) { echo $thumb; } // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</a>
			<?php endforeach; ?>
		</div>
	</nav>

	</div><!-- .acts-wrap -->
	<?php
}
endif;
