<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'kmblog_register_content_types' );

function kmblog_register_content_types() {
	$slug = sanitize_title( kmblog_opt( 'slug' ) );
	if ( '' === $slug ) {
		$slug = 'blog';
	}

	/*
	 * Les taxonomies sont enregistrées AVANT le type de contenu : leurs règles
	 * de réécriture (blog/rubrique/…, blog/sujet/…) doivent passer avant celles
	 * des articles (blog/%km_blog%/…), sinon WordPress lit « rubrique » comme le
	 * slug d'un article et les pages de rubriques/sujets renvoient une 404.
	 */
	register_taxonomy( 'km_blog_cat', 'km_blog', array(
		'labels'            => array(
			'name'          => 'Rubriques',
			'singular_name' => 'Rubrique',
			'menu_name'     => 'Rubriques',
			'search_items'  => 'Rechercher une rubrique',
			'add_new_item'  => 'Ajouter une rubrique',
			'edit_item'     => 'Modifier la rubrique',
			'all_items'     => 'Toutes les rubriques',
			'not_found'     => 'Aucune rubrique',
		),
		'hierarchical'      => true,
		'public'            => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'rewrite'           => array( 'slug' => $slug . '/rubrique', 'with_front' => false ),
	) );

	register_taxonomy( 'km_blog_tag', 'km_blog', array(
		'labels'            => array(
			'name'          => 'Sujets',
			'singular_name' => 'Sujet',
			'menu_name'     => 'Sujets',
			'search_items'  => 'Rechercher un sujet',
			'add_new_item'  => 'Ajouter un sujet',
			'edit_item'     => 'Modifier le sujet',
			'all_items'     => 'Tous les sujets',
			'not_found'     => 'Aucun sujet',
		),
		'hierarchical'      => false,
		'public'            => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'rewrite'           => array( 'slug' => $slug . '/sujet', 'with_front' => false ),
	) );

	register_post_type( 'km_blog', array(
		'labels'        => array(
			'name'                  => 'Blog',
			'singular_name'         => 'Article de blog',
			'add_new'               => 'Ajouter un article',
			'add_new_item'          => 'Ajouter un article de blog',
			'edit_item'             => 'Modifier l’article',
			'new_item'              => 'Nouvel article',
			'view_item'             => 'Voir l’article',
			'view_items'            => 'Voir le blog',
			'search_items'          => 'Rechercher un article',
			'not_found'             => 'Aucun article trouvé',
			'not_found_in_trash'    => 'Aucun article dans la corbeille',
			'all_items'             => 'Tous les articles',
			'menu_name'             => 'Blog',
			'featured_image'        => 'Image de couverture',
			'set_featured_image'    => 'Définir l’image de couverture',
			'remove_featured_image' => 'Retirer l’image de couverture',
			'use_featured_image'    => 'Utiliser comme image de couverture',
			'item_published'        => 'Article publié.',
			'item_updated'          => 'Article mis à jour.',
		),
		'public'        => true,
		'has_archive'   => $slug,
		'rewrite'       => array( 'slug' => $slug, 'with_front' => false, 'feeds' => true ),
		'show_in_rest'  => true,
		'menu_position' => 5,
		'menu_icon'     => 'dashicons-edit-large',
		'taxonomies'    => array( 'km_blog_cat', 'km_blog_tag' ),
		'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions', 'custom-fields' ),
	) );

	// Métadonnées exposées à l'API REST (éditeur de blocs, Elementor, apps).
	register_post_meta( 'km_blog', '_kmb_subtitle', array(
		'type'              => 'string',
		'single'            => true,
		'show_in_rest'      => true,
		'sanitize_callback' => 'sanitize_text_field',
		'auth_callback'     => function ( $allowed, $key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		},
	) );
	register_post_meta( 'km_blog', '_kmb_featured', array(
		'type'              => 'string',
		'single'            => true,
		'show_in_rest'      => true,
		'sanitize_callback' => function ( $v ) {
			return '1' === (string) $v ? '1' : '';
		},
		'auth_callback'     => function ( $allowed, $key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		},
	) );
}

// Régénère les URL après un changement d'adresse du blog ou une mise à jour du plugin.
add_action( 'init', function () {
	if ( get_option( 'kmblog_needs_flush' ) || KMBLOG_VERSION !== get_option( 'kmblog_version' ) ) {
		flush_rewrite_rules( false );
		delete_option( 'kmblog_needs_flush' );
		update_option( 'kmblog_version', KMBLOG_VERSION, false );
	}
}, 99 );

// Champ « Sous-titre » + « À la une ».
add_action( 'add_meta_boxes', function () {
	add_meta_box( 'kmb_subtitle', 'Sous-titre et mise en avant', 'kmblog_subtitle_box', 'km_blog', 'normal', 'high' );
} );

function kmblog_subtitle_box( $post ) {
	wp_nonce_field( 'kmb_save', 'kmb_nonce' );
	$value    = get_post_meta( $post->ID, '_kmb_subtitle', true );
	$featured = get_post_meta( $post->ID, '_kmb_featured', true );
	echo '<p><label for="kmb_subtitle_field"><strong>Sous-titre</strong></label></p>';
	echo '<input type="text" id="kmb_subtitle_field" name="kmb_subtitle" class="widefat" maxlength="200" value="' . esc_attr( $value ) . '" placeholder="Une phrase qui donne envie de lire…">';
	echo '<p class="description">Affiché sous le titre de l’article (200 caractères max).</p>';
	echo '<p><label><input type="checkbox" name="kmb_featured" value="1" ' . checked( $featured, '1', false ) . '> <strong>Mettre cet article « À la une »</strong> (en grand en haut du blog ; si plusieurs sont cochés, le plus récent est affiché)</label></p>';
}

add_action( 'save_post_km_blog', function ( $post_id, $post ) {
	// Temps de lecture : recalculé à chaque enregistrement (révisions exclues).
	if ( ! wp_is_post_revision( $post_id ) ) {
		update_post_meta( $post_id, '_kmb_reading', max( 1, (int) ceil( kmblog_count_words( $post->post_content ) / 230 ) ) );
	}

	if ( ! isset( $_POST['kmb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kmb_nonce'] ) ), 'kmb_save' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$value = isset( $_POST['kmb_subtitle'] ) ? sanitize_text_field( wp_unslash( $_POST['kmb_subtitle'] ) ) : '';
	$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 200 ) : substr( $value, 0, 200 );
	if ( '' === $value ) {
		delete_post_meta( $post_id, '_kmb_subtitle' );
	} else {
		update_post_meta( $post_id, '_kmb_subtitle', $value );
	}
	if ( ! empty( $_POST['kmb_featured'] ) ) {
		update_post_meta( $post_id, '_kmb_featured', '1' );
	} else {
		delete_post_meta( $post_id, '_kmb_featured' );
	}
}, 10, 2 );

// Colonne « À la une » dans la liste des articles.
add_filter( 'manage_km_blog_posts_columns', function ( $cols ) {
	$out = array();
	foreach ( $cols as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'title' === $key ) {
			$out['kmb_featured'] = '<span class="dashicons dashicons-star-filled" title="À la une"></span><span class="screen-reader-text">À la une</span>';
		}
	}
	return $out;
} );

add_action( 'manage_km_blog_posts_custom_column', function ( $col, $post_id ) {
	if ( 'kmb_featured' === $col && '1' === get_post_meta( $post_id, '_kmb_featured', true ) ) {
		$is_shown = (int) kmblog_featured_id() === (int) $post_id;
		echo '<span class="dashicons dashicons-star-filled" style="color:' . ( $is_shown ? '#d4a24c' : '#bbb' ) . '" title="' . esc_attr( $is_shown ? 'Affiché à la une' : 'Coché, mais un article plus récent est à la une' ) . '"></span>';
	}
}, 10, 2 );

add_action( 'admin_head-edit.php', function () {
	if ( 'km_blog' === get_current_screen()->post_type ) {
		echo '<style>.column-kmb_featured{width:36px;text-align:center}</style>';
	}
} );
