/**
 * SNIPPET 4 — SHORTCODES ELEMENTOR v2 (Design homepage premium)
 * (même design, mêmes classes CSS)
 * ✅ [derniers_articles] : géré par le SNIPPET 2
 *
 * Shortcodes :
 *   [hero_actualite cat=""]
 *   [articles_par_categorie nb="4" show_all="yes"]
 *   [compteur_articles cat=""]
 *
 * Corrections v2 :
 * ✅ Plus d'erreur fatale si ACF ou le SNIPPET 1 (helpers act_*) est désactivé
 * ✅ [articles_par_categorie] : accents corrects dans les onglets (ÉVÉNEMENTS
 *    et non éVéNEMENTS), plus de plantage si la taxonomie est absente,
 *    onglets accessibles (rôles ARIA, flèches clavier), un seul script partagé
 * ✅ [compteur_articles] : comptage sans charger tous les articles en mémoire
 * ✅ Protection contre les doublons (shortcode_exists), échappement complet
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
if ( ! function_exists( 'kmact_post_data' ) ) {
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
if ( ! function_exists( 'kmact_tax_query' ) ) {
	/** tax_query pour cat="slug" ou cat="slug1,slug2". */
	function kmact_tax_query( $cat ) {
		$slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) $cat ) ) );
		return $slugs ? array( array( 'taxonomy' => 'categorie-actualite', 'field' => 'slug', 'terms' => $slugs ) ) : array();
	}
}

// ═══════════════════════════════════════════════════════════
// [hero_actualite] — Hero pleine largeur premium
// ═══════════════════════════════════════════════════════════
if ( ! shortcode_exists( 'hero_actualite' ) ) :
add_shortcode( 'hero_actualite', function ( $atts ) {
	$atts = shortcode_atts( array( 'cat' => '' ), $atts, 'hero_actualite' );
	$q    = new WP_Query( array(
		'post_type'           => 'actualites',
		'post_status'         => 'publish',
		'posts_per_page'      => 1,
		'orderby'             => 'date',
		'order'               => 'DESC',
		'tax_query'           => kmact_tax_query( $atts['cat'] ), // phpcs:ignore WordPress.DB.SlowDBQuery
		'no_found_rows'       => true,
		'ignore_sticky_posts' => true,
	) );
	if ( ! $q->have_posts() ) {
		return '';
	}
	$q->the_post();

	$pid   = get_the_ID();
	$d     = kmact_post_data( $pid, '#00C8A0', 'full' );
	$color = $d['color'];
	$rgb   = $d['rgb'];
	$badge = $d['term'] ? kmact_call( 'act_get_category_badge', array( $pid, 'large' ), '' ) : '';
	$auth  = get_the_author_meta( 'display_name', get_post_field( 'post_author', $pid ) );
	$title = html_entity_decode( get_post_field( 'post_title', $pid ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$url   = get_permalink();
	wp_reset_postdata();

	ob_start();
	?>
	<div class="act-sc-hero" style="--hero-color:<?php echo esc_attr( $color ); ?>;--hero-rgb:<?php echo esc_attr( $rgb ); ?>;">
		<a href="<?php echo esc_url( $url ); ?>" class="act-sc-hero__link">
			<div class="act-sc-hero__bg" aria-hidden="true">
				<?php if ( $d['img'] ) : ?>
					<img src="<?php echo esc_url( $d['img'] ); ?>" alt="" class="act-sc-hero__bg-img" fetchpriority="high" decoding="async">
				<?php endif; ?>
				<div class="act-sc-hero__bg-overlay" style="background:linear-gradient(to right,rgba(0,0,0,.92) 0%,rgba(0,0,0,.6) 45%,rgba(<?php echo esc_attr( $rgb ); ?>,.15) 100%);"></div>
			</div>

			<div class="act-sc-hero__content">
				<div class="act-sc-hero__eyebrow" style="color:<?php echo esc_attr( $color ); ?>;">
					<span class="act-sc-hero__dot" style="background:<?php echo esc_attr( $color ); ?>;" aria-hidden="true"></span>
					DERNIÈRE ACTUALITÉ
				</div>
				<?php echo $badge; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML de act_get_category_badge(). ?>
				<h2 class="act-sc-hero__title"><?php echo esc_html( $title ); ?></h2>
				<?php if ( $d['exc'] ) : ?>
					<p class="act-sc-hero__exc"><?php echo esc_html( wp_trim_words( $d['exc'], 22 ) ); ?></p>
				<?php endif; ?>
				<div class="act-sc-hero__meta">
					<span><?php echo esc_html( $auth ); ?></span>
					<span class="act-sc-hero__meta-sep" aria-hidden="true">·</span>
					<span><?php echo esc_html( $d['date'] ); ?></span>
					<?php if ( $d['time'] ) : ?>
						<span class="act-sc-hero__meta-sep" aria-hidden="true">·</span>
						<span><?php echo esc_html( $d['time'] ); ?> de lecture</span>
					<?php endif; ?>
				</div>
				<div class="act-sc-hero__btn" style="background:<?php echo esc_attr( $color ); ?>;" aria-hidden="true">
					Lire l'article
					<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
				</div>
			</div>
		</a>
	</div>
	<?php
	return ob_get_clean();
} );
endif;

// ═══════════════════════════════════════════════════════════
// [articles_par_categorie] — Onglets avec design premium
// ═══════════════════════════════════════════════════════════
if ( ! shortcode_exists( 'articles_par_categorie' ) ) :
add_shortcode( 'articles_par_categorie', function ( $atts ) {
	$atts = shortcode_atts( array( 'nb' => 4, 'show_all' => 'yes' ), $atts, 'articles_par_categorie' );
	$nb   = max( 1, min( 12, absint( $atts['nb'] ) ) );
	$cats = get_terms( array( 'taxonomy' => 'categorie-actualite', 'hide_empty' => true ) );
	if ( empty( $cats ) || is_wp_error( $cats ) ) {
		return '';
	}
	$uid      = 'act-tabs-' . wp_unique_id();
	$show_all = in_array( strtolower( (string) $atts['show_all'] ), array( 'yes', 'oui', '1', 'true' ), true );
	$archive  = get_post_type_archive_link( 'actualites' );

	ob_start();
	?>
	<div class="act-sc-tabs" id="<?php echo esc_attr( $uid ); ?>">
		<div class="act-sc-tabs__nav" role="tablist" aria-label="Catégories d'actualités">
			<?php
			foreach ( $cats as $i => $cat ) :
				$c      = kmact_color( kmact_call( 'act_get_category_color', array( $cat->slug ), '#00C8A0' ) );
				$active = 0 === $i;
				?>
				<button type="button" role="tab"
					id="<?php echo esc_attr( $uid . '-tab-' . $i ); ?>"
					aria-controls="<?php echo esc_attr( $uid . '-panel-' . $i ); ?>"
					aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
					tabindex="<?php echo $active ? '0' : '-1'; ?>"
					class="act-sc-tab-btn <?php echo $active ? 'active' : ''; ?>"
					data-tab="<?php echo esc_attr( $cat->slug ); ?>"
					data-color="<?php echo esc_attr( $c ); ?>"
					style="--tab-color:<?php echo esc_attr( $c ); ?>;<?php echo $active ? esc_attr( 'background:' . $c . ';color:#fff;border-color:' . $c . ';' ) : ''; ?>">
					<?php echo esc_html( kmact_upper( $cat->name ) ); ?>
				</button>
			<?php endforeach; ?>
		</div>

		<?php
		foreach ( $cats as $i => $cat ) :
			$c    = kmact_color( kmact_call( 'act_get_category_color', array( $cat->slug ), '#00C8A0' ) );
			$rgb2 = kmact_rgb( $c );
			$q2   = new WP_Query( array(
				'post_type'           => 'actualites',
				'post_status'         => 'publish',
				'posts_per_page'      => $nb,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'tax_query'           => array( array( 'taxonomy' => 'categorie-actualite', 'field' => 'term_id', 'terms' => (int) $cat->term_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			) );
			?>
			<div class="act-sc-tab-panel <?php echo 0 === $i ? 'active' : ''; ?>" role="tabpanel"
				id="<?php echo esc_attr( $uid . '-panel-' . $i ); ?>"
				aria-labelledby="<?php echo esc_attr( $uid . '-tab-' . $i ); ?>"
				data-tab="<?php echo esc_attr( $cat->slug ); ?>"<?php echo 0 === $i ? '' : ' hidden'; ?>>
				<?php if ( $q2->have_posts() ) : ?>
					<div class="act-sc-grid" style="--sc-cols:<?php echo (int) min( $nb, 4 ); ?>;">
						<?php
						while ( $q2->have_posts() ) :
							$q2->the_post();
							$pid   = get_the_ID();
							$img   = kmact_call( 'act_get_cover_image_url', array( $pid, 'medium_large' ) );
							$img   = $img ? $img : get_the_post_thumbnail_url( $pid, 'medium_large' );
							$date  = kmact_call( 'act_format_date_fr', array( $pid ), get_the_date( 'd M Y', $pid ) );
							$title = html_entity_decode( get_post_field( 'post_title', $pid ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
							$url   = get_permalink();
							?>
							<article class="act-sc-card" style="--card-color:<?php echo esc_attr( $c ); ?>;--card-rgb:<?php echo esc_attr( $rgb2 ); ?>;">
								<a href="<?php echo esc_url( $url ); ?>" class="act-sc-card__img-wrap" tabindex="-1" aria-hidden="true">
									<?php if ( $img ) : ?>
										<img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy" decoding="async">
									<?php endif; ?>
									<div class="act-sc-card__img-overlay" style="background:linear-gradient(to top,rgba(<?php echo esc_attr( $rgb2 ); ?>,.9),transparent 60%);"></div>
									<div class="act-sc-card__img-title-overlay">
										<h3 class="act-sc-card__img-title"><?php echo esc_html( $title ); ?></h3>
									</div>
								</a>
								<div class="act-sc-card__body">
									<time class="act-sc-card__date" datetime="<?php echo esc_attr( get_the_date( 'c', $pid ) ); ?>" style="color:<?php echo esc_attr( $c ); ?>;"><?php echo esc_html( $date ); ?></time>
									<h3 class="act-sc-card__title"><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $title ); ?></a></h3>
									<a href="<?php echo esc_url( $url ); ?>" class="act-sc-card__cta" style="color:<?php echo esc_attr( $c ); ?>;border-color:rgba(<?php echo esc_attr( $rgb2 ); ?>,.3);background:rgba(<?php echo esc_attr( $rgb2 ); ?>,.08);" aria-label="<?php echo esc_attr( 'Lire : ' . $title ); ?>">
										Lire <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
									</a>
								</div>
								<div class="act-sc-card__bottom-bar" style="background:<?php echo esc_attr( $c ); ?>;"></div>
							</article>
						<?php endwhile; wp_reset_postdata(); ?>
					</div>
					<?php if ( $show_all && $archive ) : ?>
						<div class="act-sc-tabs__see-all">
							<a href="<?php echo esc_url( add_query_arg( 'categorie', $cat->slug, $archive ) ); ?>" class="act-sc-tabs__btn" style="color:<?php echo esc_attr( $c ); ?>;border-color:<?php echo esc_attr( $c ); ?>;">
								Voir tous les « <?php echo esc_html( $cat->name ); ?> » →
							</a>
						</div>
					<?php endif; ?>
				<?php else : ?>
					<p class="act-sc-empty">Aucun article dans cette catégorie.</p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	// Script des onglets : imprimé une seule fois par page, même avec plusieurs blocs.
	static $script_done = false;
	if ( ! $script_done ) :
		$script_done = true;
		?>
	<script>
	(function(){
		function init(box){
			if(box.getAttribute('data-ready'))return;box.setAttribute('data-ready','1');
			var btns=box.querySelectorAll('.act-sc-tab-btn'),panels=box.querySelectorAll('.act-sc-tab-panel');
			function activate(btn,focus){
				Array.prototype.forEach.call(btns,function(b){
					b.classList.remove('active');b.setAttribute('aria-selected','false');b.setAttribute('tabindex','-1');
					b.style.background='';b.style.color='';b.style.borderColor='';
				});
				Array.prototype.forEach.call(panels,function(p){p.classList.remove('active');p.hidden=true;});
				var color=btn.getAttribute('data-color');
				btn.classList.add('active');btn.setAttribute('aria-selected','true');btn.setAttribute('tabindex','0');
				btn.style.background=color;btn.style.color='#fff';btn.style.borderColor=color;
				var panel=document.getElementById(btn.getAttribute('aria-controls'));
				if(panel){panel.classList.add('active');panel.hidden=false;}
				if(focus)btn.focus();
			}
			Array.prototype.forEach.call(btns,function(btn,i){
				btn.addEventListener('click',function(){activate(btn,false);});
				btn.addEventListener('keydown',function(e){
					var n=null;
					if(e.key==='ArrowRight')n=btns[(i+1)%btns.length];
					else if(e.key==='ArrowLeft')n=btns[(i-1+btns.length)%btns.length];
					else if(e.key==='Home')n=btns[0];
					else if(e.key==='End')n=btns[btns.length-1];
					if(n){e.preventDefault();activate(n,true);}
				});
			});
		}
		function run(){Array.prototype.forEach.call(document.querySelectorAll('.act-sc-tabs'),init);}
		if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',run);}else{run();}
		// Aperçu Elementor : les widgets sont injectés après le chargement.
		if(window.jQuery){jQuery(window).on('elementor/frontend/init',function(){if(window.elementorFrontend&&elementorFrontend.hooks){elementorFrontend.hooks.addAction('frontend/element_ready/global',run);}});}
	})();
	</script>
		<?php
	endif;
	return ob_get_clean();
} );
endif;

// ═══════════════════════════════════════════════════════════
// [compteur_articles cat=""]
// ═══════════════════════════════════════════════════════════
if ( ! shortcode_exists( 'compteur_articles' ) ) :
add_shortcode( 'compteur_articles', function ( $atts ) {
	$atts  = shortcode_atts( array( 'cat' => '' ), $atts, 'compteur_articles' );
	$tax_q = kmact_tax_query( $atts['cat'] );
	if ( $tax_q ) {
		// Une seule ligne chargée : found_posts donne le total.
		$q = new WP_Query( array(
			'post_type'      => 'actualites',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'tax_query'      => $tax_q, // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		$n = (int) $q->found_posts;
	} else {
		$counts = wp_count_posts( 'actualites' );
		$n      = isset( $counts->publish ) ? (int) $counts->publish : 0;
	}
	$s = $n > 1 ? 's' : '';
	return '<span class="act-sc-count"><strong>' . esc_html( number_format_i18n( $n ) ) . '</strong> article' . $s . ' publié' . $s . '</span>';
} );
endif;
