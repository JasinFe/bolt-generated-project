<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'kmb_register_content_types' );

function kmb_register_content_types() {
	$slug = sanitize_title( kmb_opt( 'slug' ) );
	if ( '' === $slug ) {
		$slug = 'blog';
	}

	register_post_type( 'km_blog', array(
		'labels'        => array(
			'name'               => 'Blog',
			'singular_name'      => 'Article de blog',
			'add_new'            => 'Ajouter un article',
			'add_new_item'       => 'Ajouter un article de blog',
			'edit_item'          => 'Modifier l’article',
			'new_item'           => 'Nouvel article',
			'view_item'          => 'Voir l’article',
			'search_items'       => 'Rechercher un article',
			'not_found'          => 'Aucun article trouvé',
			'not_found_in_trash' => 'Aucun article dans la corbeille',
			'all_items'          => 'Tous les articles',
			'menu_name'          => 'Blog',
		),
		'public'        => true,
		'has_archive'   => $slug,
		'rewrite'       => array( 'slug' => $slug, 'with_front' => false ),
		'show_in_rest'  => true,
		'menu_position' => 5,
		'menu_icon'     => 'dashicons-edit-large',
		'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions' ),
	) );

	register_taxonomy( 'km_blog_cat', 'km_blog', array(
		'labels'            => array(
			'name'          => 'Rubriques',
			'singular_name' => 'Rubrique',
			'menu_name'     => 'Rubriques',
			'add_new_item'  => 'Ajouter une rubrique',
			'edit_item'     => 'Modifier la rubrique',
			'all_items'     => 'Toutes les rubriques',
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
			'add_new_item'  => 'Ajouter un sujet',
			'edit_item'     => 'Modifier le sujet',
			'all_items'     => 'Tous les sujets',
		),
		'hierarchical'      => false,
		'public'            => true,
		'show_in_rest'      => true,
		'show_admin_column' => false,
		'rewrite'           => array( 'slug' => $slug . '/sujet', 'with_front' => false ),
	) );
}

// Régénère les URL après un changement d'adresse du blog.
add_action( 'init', function () {
	if ( get_option( 'kmb_needs_flush' ) ) {
		flush_rewrite_rules( false );
		delete_option( 'kmb_needs_flush' );
	}
}, 99 );

// Champ « Sous-titre ».
add_action( 'add_meta_boxes', function () {
	add_meta_box( 'kmb_subtitle', 'Sous-titre', 'kmb_subtitle_box', 'km_blog', 'normal', 'high' );
} );

function kmb_subtitle_box( $post ) {
	wp_nonce_field( 'kmb_save', 'kmb_nonce' );
	$value = get_post_meta( $post->ID, '_kmb_subtitle', true );
	echo '<input type="text" name="kmb_subtitle" class="widefat" value="' . esc_attr( $value ) . '" placeholder="Une phrase qui donne envie de lire…">';
	echo '<p class="description">Affiché sous le titre de l’article.</p>';
	$featured = get_post_meta( $post->ID, '_kmb_featured', true );
	echo '<p><label><input type="checkbox" name="kmb_featured" value="1" ' . checked( $featured, '1', false ) . '> <strong>Mettre cet article « À la une »</strong> (en grand en haut du blog ; si plusieurs sont cochés, le plus récent est affiché)</label></p>';
}

add_action( 'save_post_km_blog', function ( $post_id ) {
	if ( ! isset( $_POST['kmb_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kmb_nonce'] ) ), 'kmb_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$value = isset( $_POST['kmb_subtitle'] ) ? sanitize_text_field( wp_unslash( $_POST['kmb_subtitle'] ) ) : '';
	update_post_meta( $post_id, '_kmb_subtitle', $value );
	if ( ! empty( $_POST['kmb_featured'] ) ) {
		update_post_meta( $post_id, '_kmb_featured', '1' );
	} else {
		delete_post_meta( $post_id, '_kmb_featured' );
	}
} );
