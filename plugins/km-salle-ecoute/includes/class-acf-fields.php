<?php
/**
 * Champs ACF pour KM SALLE D'ÉCOUTE
 * Compatible ACF FREE (pas de Repeater, pas de Clone)
 * Pistes audio : jusqu'à 5 pistes individuelles
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KM_Salle_Ecoute_ACF_Fields {

    // Nombre max de pistes (modifiable)
    const MAX_PISTES = 5;

    public static function init(): void {
        add_action( 'acf/init',            [ __CLASS__, 'register_fields' ] );
        add_action( 'acf/init',            [ __CLASS__, 'register_access_fields' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'ensure_media_scripts' ] );
    }

    public static function ensure_media_scripts( string $hook ): void {
        if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            wp_enqueue_media();
        }
    }

    public static function register_fields(): void {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

        /* ── Groupe principal ── */
        $fields = [];

        /* ══ SECTION ARTISTE ══ */
        $fields[] = [
            'key'          => 'field_km_section_artiste',
            'label'        => '🎤 Artiste',
            'name'         => '',
            'type'         => 'accordion',
            'open'         => 1,
            'multi_expand' => 1,
        ];
        $fields[] = [
            'key'           => 'field_km_artiste_lie',
            'label'         => 'Artiste lié',
            'name'          => 'km_artiste_lie',
            'type'          => 'post_object',
            'post_type'     => [ 'nos-artistes' ],
            'allow_null'    => 1,
            'multiple'      => 0,
            'return_format' => 'object',
            'instructions'  => 'Sélectionnez l\'artiste depuis le CPT "Nos Artistes".',
        ];
        $fields[] = [
            'key'         => 'field_km_artiste_externe',
            'label'       => 'Artiste invité / externe',
            'name'        => 'km_artiste_externe',
            'type'        => 'text',
            'placeholder' => 'Nom de l\'artiste externe (optionnel)',
        ];
        $fields[] = [
            'key'      => 'field_km_end_artiste',
            'label'    => '',
            'name'     => '',
            'type'     => 'accordion',
            'endpoint' => 1,
        ];

        /* ══ SECTION PISTES AUDIO (jusqu'à 5, sans Repeater) ══ */
        $fields[] = [
            'key'          => 'field_km_section_audio',
            'label'        => '🎧 Pistes Audio',
            'name'         => '',
            'type'         => 'accordion',
            'open'         => 1,
            'multi_expand' => 1,
        ];

        $fields[] = [
            'key'          => 'field_km_nb_pistes',
            'label'        => 'Nombre de pistes',
            'name'         => 'km_nb_pistes',
            'type'         => 'select',
            'instructions' => 'Choisissez combien de pistes vous souhaitez ajouter (max ' . self::MAX_PISTES . ').',
            'choices'      => array_combine(
                range( 1, self::MAX_PISTES ),
                range( 1, self::MAX_PISTES )
            ),
            'default_value' => 1,
            'return_format' => 'value',
        ];

        // Générer les champs pour chaque piste
        for ( $i = 1; $i <= self::MAX_PISTES; $i++ ) {
            $num   = $i;
            $label = "Piste $num";

            // Message de séparation
            $fields[] = [
                'key'               => "field_km_piste_{$num}_sep",
                'label'             => "— $label —",
                'name'              => '',
                'type'              => 'message',
                'message'           => "<strong>🎵 $label</strong>",
                'new_lines'         => 'wpautop',
                'esc_html'          => 0,
                'conditional_logic' => $i > 1 ? [
                    [ [ 'field' => 'field_km_nb_pistes', 'operator' => '>=', 'value' => (string) $i ] ],
                ] : [],
            ];

            // Titre
            $fields[] = [
                'key'               => "field_km_piste_{$num}_titre",
                'label'             => "Titre",
                'name'              => "km_piste_{$num}_titre",
                'type'              => 'text',
                'placeholder'       => "Ex : Mon Son (Démo V$num)",
                'wrapper'           => [ 'width' => '50' ],
                'conditional_logic' => $i > 1 ? [
                    [ [ 'field' => 'field_km_nb_pistes', 'operator' => '>=', 'value' => (string) $i ] ],
                ] : [],
            ];

            // Type
            $fields[] = [
                'key'               => "field_km_piste_{$num}_type",
                'label'             => "Type",
                'name'              => "km_piste_{$num}_type",
                'type'              => 'select',
                'choices'           => [
                    'demo'          => 'Démo',
                    'produit_fini'  => 'Produit Fini',
                    'instrumental'  => 'Instrumental',
                    'remix'         => 'Remix',
                ],
                'default_value'     => 'demo',
                'return_format'     => 'value',
                'wrapper'           => [ 'width' => '30' ],
                'conditional_logic' => $i > 1 ? [
                    [ [ 'field' => 'field_km_nb_pistes', 'operator' => '>=', 'value' => (string) $i ] ],
                ] : [],
            ];

            // Durée
            $fields[] = [
                'key'               => "field_km_piste_{$num}_duree",
                'label'             => "Durée",
                'name'              => "km_piste_{$num}_duree",
                'type'              => 'text',
                'placeholder'       => '3:45',
                'wrapper'           => [ 'width' => '20' ],
                'instructions'      => 'mm:ss',
                'conditional_logic' => $i > 1 ? [
                    [ [ 'field' => 'field_km_nb_pistes', 'operator' => '>=', 'value' => (string) $i ] ],
                ] : [],
            ];

            // Fichier audio — champ FILE (ACF Free ✅)
            $fields[] = [
                'key'               => "field_km_piste_{$num}_fichier",
                'label'             => "📁 Fichier Audio",
                'name'              => "km_piste_{$num}_fichier",
                'type'              => 'file',
                'return_format'     => 'array',
                'library'           => 'all',
                'mime_types'        => 'mp3,wav,ogg,aac,m4a,flac',
                'instructions'      => 'Formats acceptés : MP3, WAV, OGG, AAC, M4A, FLAC',
                'wrapper'           => [ 'width' => '100' ],
                'conditional_logic' => $i > 1 ? [
                    [ [ 'field' => 'field_km_nb_pistes', 'operator' => '>=', 'value' => (string) $i ] ],
                ] : [],
            ];

            // Paroles / notes (facultatif) — affichées dans le panneau « Paroles » du lecteur
            $fields[] = [
                'key'               => "field_km_piste_{$num}_paroles",
                'label'             => "Paroles / notes",
                'name'              => "km_piste_{$num}_paroles",
                'type'              => 'textarea',
                'rows'              => 4,
                'new_lines'         => '',
                'instructions'      => 'Facultatif. Une ligne par vers ; laissez une ligne vide entre les couplets.',
                'wrapper'           => [ 'width' => '100' ],
                'conditional_logic' => $i > 1 ? [
                    [ [ 'field' => 'field_km_nb_pistes', 'operator' => '>=', 'value' => (string) $i ] ],
                ] : [],
            ];
        }

        $fields[] = [
            'key'      => 'field_km_end_audio',
            'label'    => '',
            'name'     => '',
            'type'     => 'accordion',
            'endpoint' => 1,
        ];

        /* ══ SECTION DÉTAILS ══ */
        $fields[] = [
            'key'          => 'field_km_section_details',
            'label'        => '📋 Détails de la Session',
            'name'         => '',
            'type'         => 'accordion',
            'open'         => 0,
            'multi_expand' => 1,
        ];
        $fields[] = [
            'key'          => 'field_km_description_session',
            'label'        => 'Description (visible par le partenaire)',
            'name'         => 'km_description_session',
            'type'         => 'wysiwyg',
            'tabs'         => 'visual',
            'toolbar'      => 'basic',
            'media_upload' => 0,
        ];
        $fields[] = [
            'key'            => 'field_km_date_creation',
            'label'          => 'Date de création',
            'name'           => 'km_date_creation',
            'type'           => 'date_picker',
            'display_format' => 'd/m/Y',
            'return_format'  => 'Y-m-d',
            'wrapper'        => [ 'width' => '34' ],
        ];
        $fields[] = [
            'key'         => 'field_km_bpm',
            'label'       => 'BPM',
            'name'        => 'km_bpm',
            'type'        => 'number',
            'min'         => 40,
            'max'         => 250,
            'placeholder' => '120',
            'wrapper'     => [ 'width' => '33' ],
        ];
        $fields[] = [
            'key'         => 'field_km_tonalite',
            'label'       => 'Tonalité',
            'name'        => 'km_tonalite',
            'type'        => 'text',
            'placeholder' => 'Ex: La mineur',
            'wrapper'     => [ 'width' => '33' ],
        ];
        $fields[] = [
            'key'      => 'field_km_end_details',
            'label'    => '',
            'name'     => '',
            'type'     => 'accordion',
            'endpoint' => 1,
        ];

        /* ══ SECTION VISUEL ══ */
        $fields[] = [
            'key'          => 'field_km_section_cover',
            'label'        => '🖼️ Visuel',
            'name'         => '',
            'type'         => 'accordion',
            'open'         => 0,
            'multi_expand' => 1,
        ];
        $fields[] = [
            'key'           => 'field_km_cover_art',
            'label'         => 'Pochette / Cover Art',
            'name'          => 'km_cover_art',
            'type'          => 'image',
            'return_format' => 'array',
            'preview_size'  => 'medium',
            'mime_types'    => 'jpg,jpeg,png,webp',
            'instructions'  => 'Taille recommandée : 800×800 px.',
        ];
        $fields[] = [
            'key'      => 'field_km_end_cover',
            'label'    => '',
            'name'     => '',
            'type'     => 'accordion',
            'endpoint' => 1,
        ];

        acf_add_local_field_group( [
            'key'                   => 'group_kse_session',
            'title'                 => '🎵 Informations de la Session',
            'location'              => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'kse_session' ] ],
            ],
            'menu_order'            => 0,
            'position'              => 'normal',
            'style'                 => 'seamless',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
            'fields'                => $fields,
        ] );
    }

    /* ══ GROUPE ACCÈS & PARTAGE (sidebar) ══ */
    public static function register_access_fields(): void {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

        acf_add_local_field_group( [
            'key'        => 'group_kse_acces',
            'title'      => '🔐 Accès & Partage',
            'location'   => [
                [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'kse_session' ] ],
            ],
            'menu_order' => 10,
            'position'   => 'side',
            'style'      => 'default',
            'fields'     => [
                [
                    'key'           => 'field_km_acces_type',
                    'label'         => 'Mode d\'accès',
                    'name'          => 'km_acces_type',
                    'type'          => 'radio',
                    'choices'       => [
                        'public'   => '🌍 Public (lien libre)',
                        'password' => '🔑 Protégé par mot de passe',
                        'prive'    => '🔒 Privé (connecté)',
                    ],
                    'default_value' => 'password',
                    'layout'        => 'vertical',
                    'required'      => 1,
                ],
                [
                    'key'               => 'field_km_mot_de_passe',
                    'label'             => 'Mot de passe',
                    'name'              => 'km_mot_de_passe',
                    'type'              => 'text',
                    'placeholder'       => 'Ex: partner2025',
                    'instructions'      => 'Partagez uniquement avec les partenaires autorisés.',
                    'conditional_logic' => [
                        [ [ 'field' => 'field_km_acces_type', 'operator' => '==', 'value' => 'password' ] ],
                    ],
                ],
                [
                    'key'            => 'field_km_date_expiration',
                    'label'          => 'Expiration du lien',
                    'name'           => 'km_date_expiration',
                    'type'           => 'date_picker',
                    'display_format' => 'd/m/Y',
                    'return_format'  => 'Y-m-d',
                    'instructions'   => 'Vide = pas d\'expiration.',
                ],
                [
                    'key'         => 'field_km_nom_partenaire',
                    'label'       => 'Partenaire / Destinataire',
                    'name'        => 'km_nom_partenaire',
                    'type'        => 'text',
                    'placeholder' => 'Ex: Sony Music Africa',
                ],
                [
                    'key'   => 'field_km_email_partenaire',
                    'label' => 'Email du partenaire',
                    'name'  => 'km_email_partenaire',
                    'type'  => 'email',
                ],
                [
                    'key'           => 'field_km_telechargement_autorise',
                    'label'         => 'Autoriser le téléchargement ?',
                    'name'          => 'km_telechargement_autorise',
                    'type'          => 'true_false',
                    'default_value' => 0,
                    'ui'            => 1,
                    'ui_on_text'    => 'Oui',
                    'ui_off_text'   => 'Non',
                ],
                [
                    'key'           => 'field_km_marque_watermark',
                    'label'         => 'Mention confidentialité',
                    'name'          => 'km_marque_watermark',
                    'type'          => 'true_false',
                    'default_value' => 1,
                    'ui'            => 1,
                    'ui_on_text'    => 'Activée',
                    'ui_off_text'   => 'Désactivée',
                ],
                [
                    'key'         => 'field_km_message_partenaire',
                    'label'       => 'Message personnalisé',
                    'name'        => 'km_message_partenaire',
                    'type'        => 'textarea',
                    'rows'        => 3,
                    'placeholder' => 'Message affiché en haut de la page d\'écoute…',
                ],
            ],
        ] );
    }
}
