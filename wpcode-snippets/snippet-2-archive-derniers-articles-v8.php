/**
 * SNIPPET 2 — ARCHIVE + [derniers_articles] v8
 * (remplace la v7 PREMIUM — même design, mêmes classes CSS)
 *
 * Corrections v8 :
 * ✅ Filtres de l'archive : on pouvait sélectionner une autre catégorie/année
 *    sans effet (champs cachés en double qui écrasaient le nouveau choix)
 * ✅ Pagination qui fonctionne aussi sur une page statique / page d'accueil
 * ✅ Plus d'erreur fatale si ACF ou le SNIPPET 1 (helpers act_*) est désactivé :
 *    les shortcodes passent sur des valeurs de repli au lieu de planter
 * ✅ Filtres d'URL nettoyés (sanitize_title), échappement complet des sorties
 * ✅ Titres en majuscules accentuées fiables, requêtes allégées
 * ✅ Accessibilité : libellés des filtres reliés, aria-current sur la page,
 *    liens d'image ignorés au clavier (doublons du titre)
 *
 * Shortcodes :
 *   [actualites_archive per_page="12"]
 *   [derniers_articles nb="4" cols="4" cat="" titre="ACTUALITÉS" voir_plus="yes"]
 */

/* ══════════════════════════════════════════════════
   OUTILS PARTAGÉS (identiques dans les snippets 2, 3 et 4,
   protégés par function_exists : aucun risque de doublon)
══════════════════════════════════════════════════ */
if ( ! function_exists( 'kmact_field' ) ) {
	/** Champ ACF sans dépendance dure à ACF. */
	function kmact_field( $key, $post_id = null ) {
		return function_exists( 'get_field' ) ? get_field( $key, $post_id ) : null;
	}
}
if ( ! function_exists( 'kmact_rgb' ) ) {
	/** "#00C8A0" → "0,200,160" (accepte #abc / #aabbcc, repli sûr). */
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
	/** Couleur hexadécimale validée, sinon couleur de repli. */
	function kmact_color( $hex, $fallback = '#00C8A0' ) {
		$c = sanitize_hex_color( (string) $hex );
		return $c ? $c : $fallback;
	}
}
if ( ! function_exists( 'kmact_upper' ) ) {
	/** Majuscules UTF-8 (accents compris), entités décodées. */
	function kmact_upper( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text, 'UTF-8' ) : strtoupper( $text );
	}
}
if ( ! function_exists( 'kmact_call' ) ) {
	/** Appelle un helper act_* s'il existe (SNIPPET 1), sinon renvoie $default. */
	function kmact_call( $fn, $args = array(), $default = null ) {
		return function_exists( $fn ) ? call_user_func_array( $fn, (array) $args ) : $default;
	}
}
if ( ! function_exists( 'kmact_post_data' ) ) {
	/** Données d'affichage communes d'une actualité (catégorie, couleur, date, lecture…). */
	function kmact_post_data( $pid, $fallback_color = '#00C8A0', $img_size = 'large' ) {
		$term  = kmact_call( 'act_get_primary_category', array( $pid ) );
		$color = $term ? kmact_call( 'act_get_category_color', array( $term->slug ), $fallback_color ) : $fallback_color;
		$color = kmact_color( $color, $fallback_color );
		$img   = kmact_call( 'act_get_cover_image_url', array( $pid, $img_size ) );
		if ( ! $img ) {
			$img = get_the_post_thumbnail_url( $pid, $img_size );
		}
		$exc = kmact_field( 'article_resume', $pid );
		if ( ! $exc ) {
			$exc = get_the_excerpt( $pid );
		}
		return array(
			'term'  => $term,
			'color' => $color,
			'rgb'   => kmact_rgb( $color ),
			'img'   => $img,
			'date'  => kmact_call( 'act_format_date_fr', array( $pid ), get_the_date( 'd M Y', $pid ) ),
			'time'  => kmact_call( 'act_get_reading_time', array( $pid ), '' ),
			'exc'   => wp_strip_all_tags( (string) $exc ),
			'badge' => ( $term && function_exists( 'act_get_category_badge' ) ) ? act_get_category_badge( $pid ) : '',
		);
	}
}

/* ══════════════════════════════════════════════════
   SHORTCODE : [actualites_archive]
══════════════════════════════════════════════════ */
if ( ! shortcode_exists( 'actualites_archive' ) ) :
add_shortcode( 'actualites_archive', function ( $atts ) {
	global $wpdb;

	$atts     = shortcode_atts( array( 'per_page' => 12 ), $atts, 'actualites_archive' );
	$per_page = max( 1, min( 48, absint( $atts['per_page'] ) ) );

	// « paged » sur les archives, « page » sur une page statique / d'accueil.
	$paged = max( 1, absint( get_query_var( 'paged' ) ), absint( get_query_var( 'page' ) ) );

	$cat_filter  = isset( $_GET['categorie'] ) ? sanitize_title( wp_unslash( $_GET['categorie'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$year_filter = isset( $_GET['annee'] ) ? absint( $_GET['annee'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $year_filter && ( $year_filter < 1970 || $year_filter > 2100 ) ) {
		$year_filter = 0;
	}

	$base_url   = get_permalink();
	$menu_color = kmact_color( kmact_call( 'act_get_menu_actualites_color', array(), '#00C8A0' ) );
	$menu_rgb   = kmact_rgb( $menu_color );

	$tax_q  = $cat_filter ? array( array( 'taxonomy' => 'categorie-actualite', 'field' => 'slug', 'terms' => $cat_filter ) ) : array();
	$date_q = $year_filter ? array( array( 'year' => $year_filter ) ) : array();

	$query = new WP_Query( array(
		'post_type'      => 'actualites',
		'post_status'    => 'publish',
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'tax_query'      => $tax_q, // phpcs:ignore WordPress.DB.SlowDBQuery
		'date_query'     => $date_q,
	) );

	$all_cats = get_terms( array( 'taxonomy' => 'categorie-actualite', 'hide_empty' => true ) );
	if ( is_wp_error( $all_cats ) ) {
		$all_cats = array();
	}

	// Années disponibles (mises en cache 12 h, vidées à chaque publication).
	$years = get_transient( 'kmact_years' );
	if ( false === $years ) {
		$years = $wpdb->get_col( "SELECT DISTINCT YEAR(post_date) FROM {$wpdb->posts} WHERE post_type='actualites' AND post_status='publish' ORDER BY post_date DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		set_transient( 'kmact_years', $years, 12 * HOUR_IN_SECONDS );
	}

	$active_cat_name = '';
	foreach ( $all_cats as $c ) {
		if ( $c->slug === $cat_filter ) {
			$active_cat_name = $c->name;
			break;
		}
	}
	$displayed = (int) $query->found_posts;
	$uid       = 'acta-' . wp_unique_id();

	ob_start(); ?>

	<div class="acta-wrap" style="--mc:<?php echo esc_attr( $menu_color ); ?>;--mr:<?php echo esc_attr( $menu_rgb ); ?>;">

	<div class="acta-bar">
		<div class="acta-bar__bg">
			<div class="acta-bar__glow" style="background:radial-gradient(ellipse at 0% 50%,rgba(<?php echo esc_attr( $menu_rgb ); ?>,.35) 0%,transparent 55%);"></div>
			<div class="acta-bar__noise"></div>
		</div>
		<div class="acta-bar__row">
			<div class="acta-bar__left">
				<span class="acta-bar__label"><?php
					if ( $cat_filter && $active_cat_name ) {
						echo esc_html( kmact_upper( $active_cat_name ) );
					} elseif ( $year_filter ) {
						echo 'ANNÉE ' . esc_html( $year_filter );
					} else {
						echo 'TOUTES LES ACTUALITÉS';
					}
				?></span>
				<span class="acta-bar__badge" style="background:<?php echo esc_attr( $menu_color ); ?>;"><?php echo (int) $displayed; ?></span>
				<?php if ( $cat_filter || $year_filter ) : ?>
					<a href="<?php echo esc_url( $base_url ); ?>" class="acta-bar__clear" aria-label="Effacer les filtres" title="Effacer les filtres">
						<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
					</a>
				<?php endif; ?>
			</div>
			<div class="acta-bar__right">
				<span class="acta-bar__voir-par">VOIR LES ACTUALITÉS PAR</span>
				<?php /* Un seul champ par filtre : les anciens champs cachés en double écrasaient le choix. */ ?>
				<form class="acta-bar__form" method="get" action="<?php echo esc_url( $base_url ); ?>">
					<div class="acta-bar__filter">
						<label class="acta-bar__filter-label" for="<?php echo esc_attr( $uid ); ?>-cat">CATÉGORIE</label>
						<div class="acta-bar__select-wrap">
							<select id="<?php echo esc_attr( $uid ); ?>-cat" name="categorie" class="acta-bar__select" onchange="this.form.submit()">
								<option value="">TOUTES LES CATÉGORIES</option>
								<?php foreach ( $all_cats as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat->slug ); ?>" <?php selected( $cat_filter, $cat->slug ); ?>>
										<?php echo esc_html( kmact_upper( $cat->name ) ); ?> (<?php echo (int) $cat->count; ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<svg class="acta-bar__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
						</div>
					</div>
					<div class="acta-bar__filter">
						<label class="acta-bar__filter-label" for="<?php echo esc_attr( $uid ); ?>-year">ANNÉE</label>
						<div class="acta-bar__select-wrap">
							<select id="<?php echo esc_attr( $uid ); ?>-year" name="annee" class="acta-bar__select" onchange="this.form.submit()">
								<option value="">TOUTES LES ANNÉES</option>
								<?php foreach ( (array) $years as $yr ) : ?>
									<option value="<?php echo esc_attr( $yr ); ?>" <?php selected( $year_filter, (int) $yr ); ?>><?php echo esc_html( $yr ); ?></option>
								<?php endforeach; ?>
							</select>
							<svg class="acta-bar__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
						</div>
					</div>
					<noscript><button type="submit" class="acta-bar__clear">OK</button></noscript>
				</form>
			</div>
		</div>
	</div>

	<?php
	// Hero « Dernière actualité » : uniquement en page 1.
	if ( 1 === $paged ) :
		$hq = new WP_Query( array(
			'post_type'           => 'actualites',
			'post_status'         => 'publish',
			'posts_per_page'      => 1,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'tax_query'           => $tax_q, // phpcs:ignore WordPress.DB.SlowDBQuery
			'date_query'          => $date_q,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		) );
		if ( $hq->have_posts() ) :
			$hq->the_post();
			$hid    = get_the_ID();
			$h      = kmact_post_data( $hid, $menu_color, 'full' );
			$hterm  = $h['term'];
			$hcol   = $h['color'];
			$hrgb   = $h['rgb'];
			$himg   = $h['img'];
			$hurl   = get_permalink();
			$htitle = kmact_upper( get_post_field( 'post_title', $hid ) );
			$hbadge = ( $hterm && function_exists( 'act_get_category_badge' ) ) ? act_get_category_badge( $hid, 'large' ) : '';
			wp_reset_postdata();
			?>
	<div class="acta-hero" style="--hc:<?php echo esc_attr( $hcol ); ?>;--hr:<?php echo esc_attr( $hrgb ); ?>;">
		<?php if ( $himg ) : ?>
			<div class="acta-hero__bg">
				<img src="<?php echo esc_url( $himg ); ?>" alt="" class="acta-hero__bg-img" fetchpriority="high">
				<div class="acta-hero__bg-veil"></div>
				<div class="acta-hero__bg-grad" style="background:linear-gradient(to right,rgba(4,8,20,.97) 0%,rgba(4,8,20,.88) 42%,rgba(4,8,20,.45) 70%,rgba(<?php echo esc_attr( $hrgb ); ?>,.1) 100%);"></div>
			</div>
		<?php else : ?>
			<div class="acta-hero__bg" style="background:linear-gradient(135deg,#040814,rgba(<?php echo esc_attr( $hrgb ); ?>,.2));"></div>
		<?php endif; ?>
		<div class="acta-hero__inner">
			<div class="acta-hero__content">
				<div class="acta-hero__badges">
					<span class="acta-hero__live" style="color:<?php echo esc_attr( $hcol ); ?>;border-color:rgba(<?php echo esc_attr( $hrgb ); ?>,.5);background:rgba(<?php echo esc_attr( $hrgb ); ?>,.1);">
						<span class="acta-hero__dot" style="background:<?php echo esc_attr( $hcol ); ?>;"></span>
						DERNIÈRE ACTUALITÉ
					</span>
					<?php echo $hbadge; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML généré par act_get_category_badge(). ?>
				</div>
				<h2 class="acta-hero__title"><a href="<?php echo esc_url( $hurl ); ?>"><?php echo esc_html( $htitle ); ?></a></h2>
				<?php if ( $h['exc'] ) : ?><p class="acta-hero__excerpt"><?php echo esc_html( wp_trim_words( $h['exc'], 20 ) ); ?></p><?php endif; ?>
				<div class="acta-hero__meta">
					<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
					<?php echo esc_html( $h['date'] ); ?>
					<?php if ( $h['time'] ) : ?>
						<span aria-hidden="true">·</span>
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
						<?php echo esc_html( $h['time'] ); ?> de lecture
					<?php endif; ?>
				</div>
				<a href="<?php echo esc_url( $hurl ); ?>" class="acta-hero__cta" style="background:<?php echo esc_attr( $hcol ); ?>;">LIRE L'ARTICLE <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></a>
			</div>
			<?php if ( $himg ) : ?>
			<div class="acta-hero__img-col" aria-hidden="true">
				<div class="acta-hero__img-frame" style="border-color:rgba(<?php echo esc_attr( $hrgb ); ?>,.4);box-shadow:0 0 80px rgba(<?php echo esc_attr( $hrgb ); ?>,.25),0 40px 80px rgba(0,0,0,.5);">
					<img src="<?php echo esc_url( $himg ); ?>" alt="">
					<div class="acta-hero__img-bottom" style="background:linear-gradient(to top,rgba(<?php echo esc_attr( $hrgb ); ?>,.7) 0%,transparent 50%);"></div>
					<?php if ( $hterm ) : ?><div class="acta-hero__img-tag" style="background:rgba(<?php echo esc_attr( $hrgb ); ?>,.15);border-color:rgba(<?php echo esc_attr( $hrgb ); ?>,.5);color:<?php echo esc_attr( $hcol ); ?>"><?php echo esc_html( kmact_upper( $hterm->name ) ); ?></div><?php endif; ?>
					<?php if ( $h['time'] ) : ?><div class="acta-hero__img-reading"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?php echo esc_html( $h['time'] ); ?> de lecture</div><?php endif; ?>
				</div>
				<div class="acta-hero__img-glow" style="background:radial-gradient(ellipse at center,rgba(<?php echo esc_attr( $hrgb ); ?>,.28) 0%,transparent 70%);"></div>
			</div>
			<?php endif; ?>
		</div>
		<div class="acta-hero__bar" style="background:linear-gradient(to right,<?php echo esc_attr( $hcol ); ?>,rgba(<?php echo esc_attr( $hrgb ); ?>,.2),transparent);"></div>
	</div>
			<?php
		endif;
		wp_reset_postdata();
	endif;
	?>

	<?php if ( ! $query->have_posts() ) : ?>
		<div class="acta-empty">
			<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.15)" stroke-width="1.5" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
			<p>Aucun article trouvé.</p>
			<a href="<?php echo esc_url( $base_url ); ?>" style="color:<?php echo esc_attr( $menu_color ); ?>;">← Toutes les actualités</a>
		</div>
	<?php else : ?>
		<div class="acta-list-wrap">
			<div class="acta-grid">
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				$pid  = get_the_ID();
				$url  = get_permalink();
				$ttl  = kmact_upper( get_post_field( 'post_title', $pid ) );
				$d    = kmact_post_data( $pid, $menu_color, 'large' );
				$col  = $d['color'];
				$crgb = $d['rgb'];
				$pin  = kmact_field( 'article_vedette', $pid );
				?>
				<article class="acta-card" style="--cc:<?php echo esc_attr( $col ); ?>;--cr:<?php echo esc_attr( $crgb ); ?>;">
					<a href="<?php echo esc_url( $url ); ?>" class="acta-card__img-wrap" tabindex="-1" aria-hidden="true">
						<?php if ( $d['img'] ) : ?><img src="<?php echo esc_url( $d['img'] ); ?>" alt="" loading="lazy" decoding="async" class="acta-card__img"><?php else : ?><div class="acta-card__img-placeholder" style="background:linear-gradient(135deg,rgba(<?php echo esc_attr( $crgb ); ?>,.3),rgba(<?php echo esc_attr( $crgb ); ?>,.05));"></div><?php endif; ?>
						<div class="acta-card__overlay" style="background:linear-gradient(to top,rgba(<?php echo esc_attr( $crgb ); ?>,.88) 0%,rgba(0,0,0,.2) 50%,transparent 100%);"></div>
						<div class="acta-card__img-bar" style="background:<?php echo esc_attr( $col ); ?>;"></div>
						<?php if ( $pin ) : ?><span class="acta-card__pin">⭐ À LA UNE</span><?php endif; ?>
						<?php if ( $d['badge'] ) : ?><div class="acta-card__cat-badge"><?php echo $d['badge']; // phpcs:ignore WordPress.Security.EscapeOutput ?></div><?php endif; ?>
					</a>
					<div class="acta-card__body">
						<div class="acta-card__meta">
							<span><?php echo esc_html( $d['date'] ); ?></span>
							<?php if ( $d['time'] ) : ?><span class="acta-card__meta-dot"></span><span><?php echo esc_html( $d['time'] ); ?> de lecture</span><?php endif; ?>
						</div>
						<h3 class="acta-card__title"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $ttl ); ?></a></h3>
						<?php if ( $d['exc'] ) : ?><p class="acta-card__excerpt"><?php echo esc_html( wp_trim_words( $d['exc'], 14 ) ); ?></p><?php endif; ?>
						<a href="<?php echo esc_url( $url ); ?>" class="acta-card__cta" style="color:<?php echo esc_attr( $col ); ?>;border-color:rgba(<?php echo esc_attr( $crgb ); ?>,.35);" aria-label="<?php echo esc_attr( 'Lire la suite : ' . get_post_field( 'post_title', $pid ) ); ?>">LIRE LA SUITE <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></a>
					</div>
					<div class="acta-card__side" style="background:<?php echo esc_attr( $col ); ?>;"></div>
				</article>
			<?php endwhile; wp_reset_postdata(); ?>
			</div>
		</div>
	<?php endif; ?>

	<?php
	if ( $query->max_num_pages > 1 ) :
		$big = 999999999;
		$pa  = array(
			'base'      => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
			'format'    => '?paged=%#%',
			'current'   => $paged,
			'total'     => (int) $query->max_num_pages,
			'prev_text' => '← Précédent',
			'next_text' => 'Suivant →',
			'type'      => 'array',
			'add_args'  => array(),
		);
		if ( $cat_filter ) {
			$pa['add_args']['categorie'] = $cat_filter;
		}
		if ( $year_filter ) {
			$pa['add_args']['annee'] = $year_filter;
		}
		$pgs = (array) paginate_links( $pa );
		?>
		<nav class="acta-pag" style="--pc:<?php echo esc_attr( $menu_color ); ?>;" aria-label="Pagination des actualités">
			<div class="acta-pag__info">Page <strong><?php echo (int) $paged; ?></strong> / <strong><?php echo (int) $query->max_num_pages; ?></strong></div>
			<div class="acta-pag__links"><?php foreach ( $pgs as $pg ) { echo $pg; } // phpcs:ignore WordPress.Security.EscapeOutput -- HTML de paginate_links(). ?></div>
		</nav>
	<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
} );
endif;

// Vide le cache des années quand une actualité est publiée, modifiée ou supprimée.
if ( ! has_action( 'save_post_actualites', 'kmact_flush_years' ) ) {
	if ( ! function_exists( 'kmact_flush_years' ) ) {
		function kmact_flush_years() {
			delete_transient( 'kmact_years' );
		}
	}
	add_action( 'save_post_actualites', 'kmact_flush_years' );
	add_action( 'deleted_post', 'kmact_flush_years' );
}


/* ══════════════════════════════════════════════════════════════════
   SHORTCODE : [derniers_articles] v8
   Image CARRÉE ACF (square_image) → repli image de couverture.
   Usage : [derniers_articles nb="4" cols="4" cat="" titre="ACTUALITÉS" voir_plus="yes"]
══════════════════════════════════════════════════════════════════ */
if ( ! shortcode_exists( 'derniers_articles' ) ) :
add_shortcode( 'derniers_articles', function ( $atts ) {
	$atts = shortcode_atts( array(
		'nb'        => 4,
		'cat'       => '',
		'cols'      => 4,
		'titre'     => 'ACTUALITÉS',
		'voir_plus' => 'yes',
	), $atts, 'derniers_articles' );

	$cats  = array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts['cat'] ) ) );
	$tax_q = $cats ? array( array( 'taxonomy' => 'categorie-actualite', 'field' => 'slug', 'terms' => $cats ) ) : array();

	$q = new WP_Query( array(
		'post_type'           => 'actualites',
		'post_status'         => 'publish',
		'posts_per_page'      => max( 1, min( 24, absint( $atts['nb'] ) ) ),
		'orderby'             => 'date',
		'order'               => 'DESC',
		'tax_query'           => $tax_q, // phpcs:ignore WordPress.DB.SlowDBQuery
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	) );

	if ( ! $q->have_posts() ) {
		return '';
	}

	$archive_url = kmact_call( 'act_get_archive_url', array(), '' );
	if ( ! $archive_url ) {
		$archive_url = get_post_type_archive_link( 'actualites' );
	}
	$menu_color = kmact_color( kmact_call( 'act_get_menu_actualites_color', array(), '#00C8A0' ) );
	$menu_rgb   = kmact_rgb( $menu_color );
	$cols       = max( 1, min( 4, absint( $atts['cols'] ) ) );
	$voir_plus  = in_array( strtolower( (string) $atts['voir_plus'] ), array( 'yes', 'oui', '1', 'true' ), true );

	/* ── Collecte des posts ── */
	$posts = array();
	while ( $q->have_posts() ) {
		$q->the_post();
		$pid = get_the_ID();
		$d   = kmact_post_data( $pid, $menu_color, 'medium_large' );

		/* Image carrée ACF en priorité (tableau, ID ou URL selon le format de retour ACF). */
		$sq_field = kmact_field( 'square_image', $pid );
		$sq_url   = '';
		if ( is_array( $sq_field ) && ! empty( $sq_field['url'] ) ) {
			$sq_url = isset( $sq_field['sizes']['medium_large'] ) ? $sq_field['sizes']['medium_large'] : ( isset( $sq_field['sizes']['large'] ) ? $sq_field['sizes']['large'] : $sq_field['url'] );
		} elseif ( is_numeric( $sq_field ) ) {
			$sq_url = wp_get_attachment_image_url( (int) $sq_field, 'medium_large' );
		} elseif ( is_string( $sq_field ) && filter_var( $sq_field, FILTER_VALIDATE_URL ) ) {
			$sq_url = $sq_field;
		}

		$posts[] = array(
			'pid'   => $pid,
			'url'   => get_permalink(),
			'ttl'   => html_entity_decode( get_post_field( 'post_title', $pid ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'term'  => $d['term'],
			'color' => $d['color'],
			'crgb'  => $d['rgb'],
			'img'   => $sq_url ? $sq_url : $d['img'],
			'date'  => $d['date'],
			'time'  => $d['time'],
			'exc'   => $d['exc'],
			'badge' => $d['badge'],
		);
	}
	wp_reset_postdata();

	ob_start();
	?>
	<div class="da-wrap" style="--mc:<?php echo esc_attr( $menu_color ); ?>;--mr:<?php echo esc_attr( $menu_rgb ); ?>;">

		<div class="da-head">
			<div class="da-head__left">
				<?php if ( '' !== trim( (string) $atts['titre'] ) ) : ?>
					<h2 class="da-head__title">
						<?php echo esc_html( $atts['titre'] ); ?>
						<span class="da-head__title-accent" style="background:<?php echo esc_attr( $menu_color ); ?>;" aria-hidden="true"></span>
					</h2>
				<?php endif; ?>
			</div>
			<?php if ( $voir_plus && $archive_url ) : ?>
				<a href="<?php echo esc_url( $archive_url ); ?>" class="da-head__cta" style="color:<?php echo esc_attr( $menu_color ); ?>;border-color:rgba(<?php echo esc_attr( $menu_rgb ); ?>,.45);">
					VOIR TOUT
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
				</a>
			<?php endif; ?>
		</div>

		<div class="da-grid da-grid--<?php echo (int) $cols; ?>col">
			<?php foreach ( $posts as $p ) : ?>
			<article class="da-card" style="--cc:<?php echo esc_attr( $p['color'] ); ?>;--cr:<?php echo esc_attr( $p['crgb'] ); ?>;">

				<div class="da-card__side" style="background:<?php echo esc_attr( $p['color'] ); ?>;"></div>

				<a href="<?php echo esc_url( $p['url'] ); ?>" class="da-card__img-link" tabindex="-1" aria-hidden="true">
					<?php if ( $p['img'] ) : ?>
						<img src="<?php echo esc_url( $p['img'] ); ?>" alt="" class="da-card__img" loading="lazy" decoding="async">
					<?php else : ?>
						<div class="da-card__img-ph" style="background:linear-gradient(135deg,rgba(<?php echo esc_attr( $p['crgb'] ); ?>,.4),rgba(<?php echo esc_attr( $p['crgb'] ); ?>,.08));"></div>
					<?php endif; ?>
					<div class="da-card__img-overlay"></div>
					<?php if ( $p['badge'] ) : ?>
						<div class="da-card__cat-badge"><?php echo $p['badge']; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
					<?php endif; ?>
					<?php if ( $p['time'] ) : ?>
						<div class="da-card__img-time">
							<svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
							<?php echo esc_html( $p['time'] ); ?>
						</div>
					<?php endif; ?>
					<div class="da-card__img-bar" style="background:<?php echo esc_attr( $p['color'] ); ?>;"></div>
				</a>

				<div class="da-card__body">
					<div class="da-card__meta">
						<?php if ( $p['term'] ) : ?>
							<span class="da-card__cat-label" style="color:<?php echo esc_attr( $p['color'] ); ?>;"><?php echo esc_html( kmact_upper( $p['term']->name ) ); ?></span>
							<span class="da-card__meta-dot" style="background:<?php echo esc_attr( $p['color'] ); ?>;" aria-hidden="true"></span>
						<?php endif; ?>
						<time class="da-card__date" datetime="<?php echo esc_attr( get_the_date( 'c', $p['pid'] ) ); ?>"><?php echo esc_html( $p['date'] ); ?></time>
					</div>

					<h3 class="da-card__title"><a href="<?php echo esc_url( $p['url'] ); ?>"><?php echo esc_html( $p['ttl'] ); ?></a></h3>

					<?php if ( $p['exc'] ) : ?>
						<p class="da-card__exc"><?php echo esc_html( wp_trim_words( $p['exc'], 16 ) ); ?></p>
					<?php endif; ?>

					<a href="<?php echo esc_url( $p['url'] ); ?>" class="da-card__cta" style="color:<?php echo esc_attr( $p['color'] ); ?>;" aria-label="<?php echo esc_attr( 'Lire la suite : ' . $p['ttl'] ); ?>">
						LIRE LA SUITE
						<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
					</a>
				</div>
			</article>
			<?php endforeach; ?>
		</div>

		<?php if ( $voir_plus && $archive_url ) : ?>
		<div class="da-footer">
			<a href="<?php echo esc_url( $archive_url ); ?>" class="da-footer__btn" style="color:<?php echo esc_attr( $menu_color ); ?>;border-color:rgba(<?php echo esc_attr( $menu_rgb ); ?>,.5);">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
				VOIR TOUTES LES ACTUALITÉS
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
			</a>
		</div>
		<?php endif; ?>

	</div>
	<?php
	return ob_get_clean();
} );
endif;
