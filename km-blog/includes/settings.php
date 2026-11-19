<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_submenu_page(
		'edit.php?post_type=km_blog',
		'Réglages du blog',
		'Réglages',
		'manage_options',
		'kmb-settings',
		'kmb_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'kmb_group', 'kmb_settings', array(
		'type'              => 'array',
		'sanitize_callback' => 'kmb_sanitize_settings',
		'default'           => kmb_default_settings(),
	) );
} );

// Note : options.php a déjà appliqué wp_unslash() aux valeurs reçues.
function kmb_sanitize_settings( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$d   = kmb_default_settings();
	$old = kmb_get_settings();
	$out = array();

	$out['slug']     = sanitize_title( isset( $in['slug'] ) ? $in['slug'] : $d['slug'] );
	$out['slug']     = '' === $out['slug'] ? $d['slug'] : $out['slug'];
	$out['title']    = isset( $in['title'] ) ? sanitize_text_field( $in['title'] ) : $d['title'];
	$out['title']    = '' === $out['title'] ? $d['title'] : $out['title'];
	$out['intro']    = isset( $in['intro'] ) ? sanitize_textarea_field( $in['intro'] ) : $d['intro'];
	$out['per_page'] = isset( $in['per_page'] ) ? max( 1, min( 30, absint( $in['per_page'] ) ) ) : $d['per_page'];
	$accent          = isset( $in['accent'] ) ? sanitize_hex_color( $in['accent'] ) : '';
	$out['accent']   = $accent ? $accent : $d['accent'];
	$out['mode']     = ( isset( $in['mode'] ) && 'light' === $in['mode'] ) ? 'light' : 'dark';

	foreach ( array( 'use_templates', 'show_author', 'show_reading', 'show_share', 'show_related', 'show_featured', 'show_progress', 'show_toc', 'cta_newsletter', 'cta_family' ) as $key ) {
		$out[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
	}

	foreach ( array( 'newsletter_url', 'family_url' ) as $key ) {
		$raw = isset( $in[ $key ] ) ? trim( (string) $in[ $key ] ) : '';
		// Chemin relatif (/newsletter/) conservé tel quel ; sinon URL http(s) uniquement.
		if ( '' !== $raw && '/' === $raw[0] && ( ! isset( $raw[1] ) || '/' !== $raw[1] ) ) {
			$url = '/' . ltrim( preg_replace( '#[^A-Za-z0-9\-._~/?\#=&%+]#', '', $raw ), '/' );
		} else {
			$url = esc_url_raw( $raw, array( 'http', 'https' ) );
		}
		$out[ $key ] = '' !== $url ? $url : $d[ $key ];
	}

	if ( $out['slug'] !== $old['slug'] ) {
		update_option( 'kmb_needs_flush', 1 );
		if ( get_page_by_path( $out['slug'] ) ) {
			add_settings_error( 'kmb_settings', 'kmb_slug_conflict', sprintf( 'Attention : une page utilise déjà l’adresse « %s ». Le blog risque de la masquer (ou inversement).', $out['slug'] ), 'warning' );
		}
	}
	return $out;
}

function kmb_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = kmb_get_settings();
	?>
	<div class="wrap">
		<h1>Réglages du blog</h1>
		<?php settings_errors(); ?>
		<p>Adresse actuelle du blog : <a href="<?php echo esc_url( home_url( '/' . $s['slug'] . '/' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( home_url( '/' . $s['slug'] . '/' ) ); ?></a></p>
		<form method="post" action="options.php">
			<?php settings_fields( 'kmb_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="kmb_slug">Adresse (slug)</label></th>
					<td><input id="kmb_slug" name="kmb_settings[slug]" type="text" class="regular-text" value="<?php echo esc_attr( $s['slug'] ); ?>">
					<p class="description">Évitez un slug déjà utilisé par une page du site (ex. <code>actualite</code>, <code>actualites</code>).</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="kmb_title">Titre de la page blog</label></th>
					<td><input id="kmb_title" name="kmb_settings[title]" type="text" class="regular-text" value="<?php echo esc_attr( $s['title'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="kmb_intro">Introduction</label></th>
					<td><textarea id="kmb_intro" name="kmb_settings[intro]" rows="3" class="large-text"><?php echo esc_textarea( $s['intro'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="kmb_pp">Articles par page</label></th>
					<td><input id="kmb_pp" name="kmb_settings[per_page]" type="number" min="1" max="30" value="<?php echo esc_attr( $s['per_page'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="kmb_accent">Couleur d’accent</label></th>
					<td><input id="kmb_accent" name="kmb_settings[accent]" type="color" value="<?php echo esc_attr( $s['accent'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row">Thème visuel</th>
					<td>
						<label><input type="radio" name="kmb_settings[mode]" value="dark" <?php checked( $s['mode'], 'dark' ); ?>> Sombre (comme le site)</label><br>
						<label><input type="radio" name="kmb_settings[mode]" value="light" <?php checked( $s['mode'], 'light' ); ?>> Clair</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Affichage</th>
					<td>
						<label><input type="checkbox" name="kmb_settings[show_author]" value="1" <?php checked( $s['show_author'], 1 ); ?>> Encadré auteur</label><br>
						<label><input type="checkbox" name="kmb_settings[show_reading]" value="1" <?php checked( $s['show_reading'], 1 ); ?>> Temps de lecture</label><br>
						<label><input type="checkbox" name="kmb_settings[show_share]" value="1" <?php checked( $s['show_share'], 1 ); ?>> Boutons de partage (WhatsApp, Facebook, X, copier le lien)</label><br>
						<label><input type="checkbox" name="kmb_settings[show_related]" value="1" <?php checked( $s['show_related'], 1 ); ?>> Articles similaires</label><br>
						<label><input type="checkbox" name="kmb_settings[show_progress]" value="1" <?php checked( $s['show_progress'], 1 ); ?>> Barre de progression de lecture</label><br>
						<label><input type="checkbox" name="kmb_settings[show_toc]" value="1" <?php checked( $s['show_toc'], 1 ); ?>> Sommaire automatique (si l’article compte au moins 3 intertitres H2)</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Article « À la une »</th>
					<td><label><input type="checkbox" name="kmb_settings[show_featured]" value="1" <?php checked( $s['show_featured'], 1 ); ?>> Afficher un article en grand en haut du blog</label>
					<p class="description">Cochez « À la une » dans la fiche de l’article concerné.</p></td>
				</tr>
				<tr>
					<th scope="row">Fin d’article</th>
					<td>
						<label><input type="checkbox" name="kmb_settings[cta_newsletter]" value="1" <?php checked( $s['cta_newsletter'], 1 ); ?>> Encart newsletter</label><br>
						<input type="text" class="regular-text" aria-label="Adresse de la newsletter" name="kmb_settings[newsletter_url]" value="<?php echo esc_attr( $s['newsletter_url'] ); ?>"><br><br>
						<label><input type="checkbox" name="kmb_settings[cta_family]" value="1" <?php checked( $s['cta_family'], 1 ); ?>> Encart KM Family</label><br>
						<input type="text" class="regular-text" aria-label="Adresse KM Family" name="kmb_settings[family_url]" value="<?php echo esc_attr( $s['family_url'] ); ?>">
						<p class="description">Adresses de destination des boutons (chemin comme <code>/newsletter/</code> ou URL complète).</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Modèles du plugin</th>
					<td><label><input type="checkbox" name="kmb_settings[use_templates]" value="1" <?php checked( $s['use_templates'], 1 ); ?>> Utiliser les modèles de page intégrés</label>
					<p class="description">Décochez pour concevoir vous-même les pages avec Elementor (Theme Builder). Le shortcode <code>[km_blog]</code> reste disponible.</p></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<hr>
		<h2>Shortcode</h2>
		<p><code>[km_blog limit="6" columns="3" category="slug-rubrique" more="1"]</code> affiche les derniers articles n’importe où (widget Shortcode d’Elementor, par exemple).</p>
	</div>
	<?php
}
