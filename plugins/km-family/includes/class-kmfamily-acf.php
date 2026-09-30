<?php
/**
 * Enregistrement des groupes ACF via PHP — v2
 *
 * Les paliers ne sont plus définis par artiste (repeater) mais partagés.
 * Chaque artiste peut seulement :
 * - ajouter un message d'accroche personnalisé
 * - personnaliser les avantages des paliers (optionnel)
 * - activer/désactiver certains paliers pour son cas
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_ACF {

    public static function init() {
        add_action( 'acf/init', array( __CLASS__, 'register_field_groups' ) );
    }

    public static function register_field_groups() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

        // ========================================
        // GROUPE 1 : Détails Contenu Exclusif
        // ========================================
        acf_add_local_field_group( array(
            'key'    => 'group_kmfamily_contenu_exclusif',
            'title'  => __( 'Détails du Contenu Exclusif', 'km-family' ),
            'fields' => array(
                array(
                    'key'           => 'field_kmfamily_artiste_lie',
                    'label'         => __( 'Artiste concerné', 'km-family' ),
                    'name'          => 'artiste_lie',
                    'type'          => 'post_object',
                    'instructions'  => __( 'Sélectionnez l\'artiste auquel ce contenu appartient.', 'km-family' ),
                    'required'      => 1,
                    'post_type'     => array( 'nos-artistes' ),
                    'allow_null'    => 0,
                    'multiple'      => 0,
                    'return_format' => 'object',
                    'ui'            => 1,
                ),
                array(
                    'key'           => 'field_kmfamily_palier_requis',
                    'label'         => __( 'Palier minimum requis', 'km-family' ),
                    'name'          => 'palier_requis',
                    'type'          => 'select',
                    'instructions'  => __( 'Niveau de soutien minimum pour débloquer ce contenu. Les paliers supérieurs y ont aussi accès.', 'km-family' ),
                    'required'      => 1,
                    'choices'       => array(
                        'public'  => __( '🌍 Public (accès libre)', 'km-family' ),
                        'bronze'  => __( '🥉 Bronze — L\'Ami', 'km-family' ),
                        'libre'   => __( '💝 Libre — Le Cœur Généreux', 'km-family' ),
                        'argent'  => __( '🥈 Argent — Le Partenaire', 'km-family' ),
                        'or'      => __( '🥇 Or — L\'Ambassadeur', 'km-family' ),
                        'platine' => __( '💎 Platine — Le Pilier', 'km-family' ),
                        'diamant' => __( '👑 Diamant — Le Bâtisseur', 'km-family' ),
                    ),
                    'default_value' => 'bronze',
                    'return_format' => 'value',
                ),
                array(
                    'key'           => 'field_kmfamily_type_media',
                    'label'         => __( 'Type de média', 'km-family' ),
                    'name'          => 'type_media',
                    'type'          => 'radio',
                    'choices'       => array(
                        'video'   => __( 'Vidéo', 'km-family' ),
                        'audio'   => __( 'Audio', 'km-family' ),
                        'texte'   => __( 'Texte / Article', 'km-family' ),
                        'live'    => __( 'Live', 'km-family' ),
                        'galerie' => __( 'Galerie photo', 'km-family' ),
                    ),
                    'default_value' => 'video',
                    'layout'        => 'horizontal',
                    'return_format' => 'value',
                ),
                array(
                    'key'           => 'field_kmfamily_fichier_media',
                    'label'         => __( 'Fichier média (protégé)', 'km-family' ),
                    'name'          => 'fichier_media',
                    'type'          => 'file',
                    'instructions'  => __( 'Les fichiers uploadés ici seront automatiquement protégés : leur URL directe ne fonctionnera plus, ils seront servis via streaming sécurisé.', 'km-family' ),
                    'return_format' => 'array',
                    'library'       => 'all',
                ),
                array(
                    'key'          => 'field_kmfamily_url_externe',
                    'label'        => __( 'URL vidéo externe', 'km-family' ),
                    'name'         => 'url_video_externe',
                    'type'         => 'url',
                    'instructions' => __( 'URL YouTube, Vimeo ou autre. Idéal pour vidéos non listées.', 'km-family' ),
                ),
                array(
                    'key'          => 'field_kmfamily_teaser',
                    'label'        => __( 'Teaser (visible aux non-membres)', 'km-family' ),
                    'name'         => 'teaser_public',
                    'type'         => 'textarea',
                    'instructions' => __( 'Court extrait affiché aux visiteurs non-membres pour donner envie.', 'km-family' ),
                    'rows'         => 3,
                    'new_lines'    => 'wpautop',
                ),
                array(
                    'key'            => 'field_kmfamily_date_expiration',
                    'label'          => __( 'Date d\'expiration (optionnel)', 'km-family' ),
                    'name'           => 'date_expiration',
                    'type'           => 'date_picker',
                    'instructions'   => __( 'Laissez vide pour un contenu permanent.', 'km-family' ),
                    'display_format' => 'd/m/Y',
                    'return_format'  => 'U',
                    'first_day'      => 1,
                ),
                array(
                    'key'          => 'field_kmfamily_duree',
                    'label'        => __( 'Durée estimée', 'km-family' ),
                    'name'         => 'duree_estimee',
                    'type'         => 'text',
                    'instructions' => __( 'Ex : 3:45 ou 12 min', 'km-family' ),
                    'placeholder'  => '3:45',
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'contenu_exclusif',
                    ),
                ),
            ),
            'menu_order'            => 0,
            'position'              => 'normal',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'active'                => true,
            'show_in_rest'          => 0,
        ) );

        // ========================================
        // GROUPE 2 : Configuration KM Family par artiste (simplifié v2)
        // ========================================
        acf_add_local_field_group( array(
            'key'    => 'group_kmfamily_artiste_config',
            'title'  => __( 'KM Family — Configuration Artiste', 'km-family' ),
            'fields' => array(
                array(
                    'key'          => 'field_kmfamily_activer_km_family',
                    'label'        => __( 'Activer KM Family pour cet artiste', 'km-family' ),
                    'name'         => 'kmfamily_active',
                    'type'         => 'true_false',
                    'instructions' => __( 'Si désactivé, l\'artiste n\'apparaîtra pas dans les pages d\'adhésion.', 'km-family' ),
                    'ui'           => 1,
                    'default_value' => 1,
                ),
                array(
                    'key'          => 'field_kmfamily_message_accroche',
                    'label'        => __( 'Message d\'accroche aux futurs membres', 'km-family' ),
                    'name'         => 'message_accroche',
                    'type'         => 'wysiwyg',
                    'instructions' => __( 'Message personnel affiché sur la page d\'adhésion de cet artiste. Laissez vide pour utiliser le message standard du label.', 'km-family' ),
                    'tabs'         => 'all',
                    'toolbar'      => 'basic',
                    'media_upload' => 0,
                ),
                array(
                    'key'          => 'field_kmfamily_paliers_actifs',
                    'label'        => __( 'Paliers proposés pour cet artiste', 'km-family' ),
                    'name'         => 'paliers_actifs',
                    'type'         => 'checkbox',
                    'instructions' => __( 'Cochez les paliers que cet artiste propose à ses fans. Par défaut, tous les paliers sont actifs.', 'km-family' ),
                    'choices'      => array(
                        'bronze'  => '🥉 Bronze — L\'Ami (1 000 FCFA)',
                        'argent'  => '🥈 Argent — Le Partenaire (2 000 FCFA)',
                        'or'      => '🥇 Or — L\'Ambassadeur (5 000 FCFA)',
                        'platine' => '💎 Platine — Le Pilier (15 000 FCFA)',
                        'diamant' => '👑 Diamant — Le Bâtisseur (25 000 FCFA)',
                        'libre'   => '💝 Libre — Le Cœur Généreux (montant au choix)',
                    ),
                    'default_value' => array( 'bronze', 'argent', 'or', 'platine', 'diamant', 'libre' ),
                    'layout'        => 'vertical',
                    'return_format' => 'value',
                ),
                array(
                    'key'          => 'field_kmfamily_avantages_custom',
                    'label'        => __( 'Avantages personnalisés (optionnel)', 'km-family' ),
                    'name'         => 'avantages_custom',
                    'type'         => 'wysiwyg',
                    'instructions' => __( 'Avantages supplémentaires spécifiques à cet artiste, qui s\'ajoutent aux avantages standards du label.', 'km-family' ),
                    'tabs'         => 'all',
                    'toolbar'      => 'basic',
                    'media_upload' => 0,
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'nos-artistes',
                    ),
                ),
            ),
            'menu_order'            => 10,
            'position'              => 'normal',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'active'                => true,
        ) );
    }
}
