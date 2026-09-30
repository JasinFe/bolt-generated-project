<?php
/**
 * KM Family — Moteur d'exclusivité (LOT 3)
 *
 * Un contenu n'est plus « accessible à partir du palier X ». Il a une
 * TRAJECTOIRE dans le temps :
 *
 *   J-7  Diamant   →  J-5  Platine  →  J-3  Or  →  J-1  Argent  →  J  Public
 *
 * C'est l'argument commercial le plus fort de la plateforme : le membre ne
 * paie plus pour accéder à un catalogue, il paie pour arriver AVANT les
 * autres. Sans cette mécanique, un palier supérieur ne se justifie que par
 * une quantité de contenu — ce qui oblige à produire toujours plus.
 *
 * TROIS MODES :
 *  - simple   : comportement historique, palier fixe, disponible tout de suite.
 *  - première : ouverture progressive palier par palier jusqu'à une date de
 *               sortie, puis bascule vers le palier « après sortie ».
 *  - drop     : disponible uniquement dans une fenêtre, puis retiré ou archivé.
 *
 * RÈGLE D'OR : ce module ne décide jamais SEUL. Il calcule le palier requis
 * À CET INSTANT, puis délègue la question « ce membre a-t-il ce palier ? » à
 * KMFamily_Access, qui reste l'unique autorité sur les droits.
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Availability {

    const META_MODE        = 'km_mode_diffusion';   // simple | premiere | drop
    const META_DATE_SORTIE = 'km_date_sortie';
    const META_ROLLOUT     = 'km_rollout';          // aucun | standard | court | flash | personnalise
    const META_ROLLOUT_CUS = 'km_rollout_custom';
    const META_APRES       = 'km_palier_apres';     // public | <palier>
    const META_DROP_DEBUT  = 'km_drop_debut';
    const META_DROP_FIN    = 'km_drop_fin';
    const META_APRES_DROP  = 'km_palier_apres_drop'; // '' = retiré | <palier> = archivé

    public static function init() {
        add_action( 'acf/init', array( __CLASS__, 'register_fields' ) );
    }

    // ==========================================================
    // PALIERS DE DÉPLOIEMENT
    // ==========================================================

    /**
     * Paliers d'ouverture progressive, en jours AVANT la date de sortie.
     * Toujours du plus exigeant au moins exigeant : c'est le sens même de la
     * mécanique — plus on soutient, plus on entend tôt.
     */
    public static function presets() {
        return apply_filters( 'kmfamily_rollout_presets', array(
            'standard' => array( 'diamant' => 7, 'platine' => 5, 'or' => 3, 'argent' => 1 ),
            'court'    => array( 'or' => 3, 'argent' => 1 ),
            'flash'    => array( 'diamant' => 1 ),
        ) );
    }

    public static function preset_labels() {
        return array(
            'aucun'        => __( 'Aucune ouverture progressive', 'km-family' ),
            'standard'     => __( 'Standard — Diamant J-7, Platine J-5, Or J-3, Argent J-1', 'km-family' ),
            'court'        => __( 'Court — Or J-3, Argent J-1', 'km-family' ),
            'flash'        => __( 'Flash — Diamant J-1', 'km-family' ),
            'personnalise' => __( 'Personnalisé', 'km-family' ),
        );
    }

    /**
     * Lit un déploiement personnalisé : une ligne par palier, « palier:jours ».
     * Format volontairement textuel plutôt qu'un champ répétable ACF : c'est
     * lisible, copiable d'un contenu à l'autre, et cela n'ajoute aucune
     * dépendance à une version particulière d'ACF.
     */
    public static function parse_custom( $texte ) {
        $steps = array();
        foreach ( preg_split( '/[\r\n,]+/', (string) $texte ) as $ligne ) {
            $ligne = trim( $ligne );
            if ( $ligne === '' ) continue;
            $parts = array_map( 'trim', explode( ':', $ligne, 2 ) );
            if ( count( $parts ) !== 2 ) continue;

            $palier = sanitize_key( $parts[0] );
            $jours  = abs( (int) $parts[1] );
            if ( ! isset( KMFamily_Memberships::RANKS[ $palier ] ) ) continue;

            $steps[ $palier ] = $jours;
        }
        return $steps;
    }

    // ==========================================================
    // RÉSOLUTION
    // ==========================================================

    private static function ts( $content_id, $meta_key ) {
        $v = get_post_meta( $content_id, $meta_key, true );
        if ( ! $v ) return 0;
        $t = strtotime( (string) $v );
        return $t ?: 0;
    }

    public static function get_mode( $content_id ) {
        $m = get_post_meta( $content_id, self::META_MODE, true );
        return in_array( $m, array( 'premiere', 'drop' ), true ) ? $m : 'simple';
    }

    /** Palier « plancher » du contenu, tel que défini par le champ historique. */
    public static function base_palier( $content_id ) {
        $p = function_exists( 'get_field' ) ? get_field( 'palier_requis', $content_id ) : get_post_meta( $content_id, 'palier_requis', true );
        return $p ? sanitize_key( $p ) : 'bronze';
    }

    /**
     * État d'un contenu à l'instant présent.
     *
     * @return array mode, state, palier, rank, next_at, next_palier, public_at, ends_at
     */
    public static function resolve( $content_id, $now = null ) {
        $content_id = absint( $content_id );
        $now        = $now ?: current_time( 'timestamp' );
        $mode       = self::get_mode( $content_id );
        $base       = self::base_palier( $content_id );

        $out = array(
            'mode'        => $mode,
            'state'       => 'disponible',
            'palier'      => $base,
            'rank'        => KMFamily_Memberships::required_rank( $base ),
            'next_at'     => null,
            'next_palier' => null,
            'public_at'   => null,
            'ends_at'     => null,
        );

        if ( $mode === 'simple' ) return $out;

        // ── DROP : fenêtre de disponibilité ───────────────────
        if ( $mode === 'drop' ) {
            $debut = self::ts( $content_id, self::META_DROP_DEBUT );
            $fin   = self::ts( $content_id, self::META_DROP_FIN );

            $out['ends_at'] = $fin ?: null;

            if ( $debut && $now < $debut ) {
                $out['state']       = 'a_venir';
                $out['next_at']     = $debut;
                $out['next_palier'] = $base;
                return $out;
            }

            if ( $fin && $now > $fin ) {
                $apres = sanitize_key( (string) get_post_meta( $content_id, self::META_APRES_DROP, true ) );
                if ( $apres && isset( KMFamily_Memberships::RANKS[ $apres ] ) ) {
                    // Archivé : toujours là, mais réservé plus haut.
                    $out['state']  = 'archive';
                    $out['palier'] = $apres;
                    $out['rank']   = KMFamily_Memberships::required_rank( $apres );
                } else {
                    $out['state']  = 'termine';
                    $out['palier'] = '';
                    $out['rank']   = PHP_INT_MAX;
                }
            }

            return $out;
        }

        // ── PREMIÈRE : ouverture progressive ──────────────────
        $sortie = self::ts( $content_id, self::META_DATE_SORTIE );
        if ( ! $sortie ) return $out; // date manquante : on ne verrouille rien

        $apres = sanitize_key( (string) get_post_meta( $content_id, self::META_APRES, true ) );
        if ( ! $apres || ! isset( KMFamily_Memberships::RANKS[ $apres ] ) ) $apres = 'public';

        $out['public_at'] = $sortie;

        $rollout = sanitize_key( (string) get_post_meta( $content_id, self::META_ROLLOUT, true ) );
        if ( $rollout === 'personnalise' ) {
            $steps = self::parse_custom( get_post_meta( $content_id, self::META_ROLLOUT_CUS, true ) );
        } else {
            $presets = self::presets();
            $steps   = $presets[ $rollout ] ?? array();
        }

        // Construction de la trajectoire : chaque étape est une date et le
        // palier requis à partir de cette date. On termine par la sortie.
        $trajet = array();
        foreach ( $steps as $palier => $jours ) {
            $trajet[] = array( 'at' => $sortie - ( (int) $jours * DAY_IN_SECONDS ), 'palier' => $palier );
        }
        $trajet[] = array( 'at' => $sortie, 'palier' => $apres );

        usort( $trajet, function ( $a, $b ) { return $a['at'] <=> $b['at']; } );

        $courant = null;
        $suivant = null;
        foreach ( $trajet as $etape ) {
            if ( $etape['at'] <= $now ) {
                $courant = $etape;
            } elseif ( $suivant === null ) {
                $suivant = $etape;
            }
        }

        if ( $courant === null ) {
            // Aucune étape atteinte : personne n'y a encore droit.
            $out['state']       = 'a_venir';
            $out['palier']      = '';
            $out['rank']        = PHP_INT_MAX;
            $out['next_at']     = $trajet[0]['at'];
            $out['next_palier'] = $trajet[0]['palier'];
            return $out;
        }

        $out['palier'] = $courant['palier'];
        $out['rank']   = KMFamily_Memberships::required_rank( $courant['palier'] );

        if ( $suivant ) {
            $out['next_at']     = $suivant['at'];
            $out['next_palier'] = $suivant['palier'];
        }

        return $out;
    }

    // ==========================================================
    // ACCÈS
    // ==========================================================

    /**
     * Le membre peut-il accéder à ce contenu maintenant ?
     *
     * @return array ok (bool), reason, dispo (résolution), next_at
     */
    public static function check( $user_id, $content_id, $now = null ) {
        $dispo = self::resolve( $content_id, $now );

        if ( $user_id && user_can( $user_id, 'manage_options' ) ) {
            // L'administrateur voit tout, y compris avant l'heure — sans quoi
            // il serait impossible de relire une première avant sa sortie.
            return array( 'ok' => true, 'reason' => 'admin', 'dispo' => $dispo );
        }

        if ( $dispo['state'] === 'a_venir' ) {
            return array( 'ok' => false, 'reason' => 'trop_tot', 'dispo' => $dispo );
        }
        if ( $dispo['state'] === 'termine' ) {
            return array( 'ok' => false, 'reason' => 'termine', 'dispo' => $dispo );
        }

        $artiste = function_exists( 'get_field' ) ? get_field( 'artiste_lie', $content_id ) : get_post_meta( $content_id, 'artiste_lie', true );
        if ( ! $artiste ) {
            return array( 'ok' => false, 'reason' => 'sans_artiste', 'dispo' => $dispo );
        }
        $artiste_id = is_object( $artiste ) ? (int) $artiste->ID : absint( $artiste );

        // Le palier effectif du moment est passé à l'autorité des droits.
        $ok = KMFamily_Access::user_has_access( $user_id, $artiste_id, $dispo['palier'] );

        return array(
            'ok'     => $ok,
            'reason' => $ok ? 'ok' : 'palier_insuffisant',
            'dispo'  => $dispo,
        );
    }

    public static function user_can_access( $user_id, $content_id ) {
        $r = self::check( $user_id, $content_id );
        return (bool) $r['ok'];
    }

    /**
     * Quand ce membre précis y aura-t-il droit ?
     * Répondre « dans 2 jours » vaut infiniment mieux que « réservé Diamant » :
     * la première formulation donne une raison d'attendre, la seconde une
     * raison de partir.
     */
    public static function next_access_for_user( $user_id, $content_id, $now = null ) {
        $now   = $now ?: current_time( 'timestamp' );
        $dispo = self::resolve( $content_id, $now );

        if ( $dispo['mode'] !== 'premiere' || ! $dispo['public_at'] ) return null;

        $artiste = function_exists( 'get_field' ) ? get_field( 'artiste_lie', $content_id ) : get_post_meta( $content_id, 'artiste_lie', true );
        if ( ! $artiste ) return null;
        $artiste_id = is_object( $artiste ) ? (int) $artiste->ID : absint( $artiste );

        // Rang du membre chez cet artiste.
        $sub = KMFamily_Subscriptions::get_user_subscription( $user_id, $artiste_id );
        if ( ! $sub || ( ! empty( $sub['expire'] ) && $sub['expire'] < time() ) ) {
            $rang_membre = 0;
        } else {
            $acces = KMFamily_Memberships::resolve_access( $sub['palier'], $sub['montant'] ?? 0 );
            $rang_membre = $acces['rank'];
        }

        // Première étape de la trajectoire que ce rang permet d'atteindre.
        $rollout = sanitize_key( (string) get_post_meta( $content_id, self::META_ROLLOUT, true ) );
        $steps   = $rollout === 'personnalise'
            ? self::parse_custom( get_post_meta( $content_id, self::META_ROLLOUT_CUS, true ) )
            : ( self::presets()[ $rollout ] ?? array() );

        $apres = sanitize_key( (string) get_post_meta( $content_id, self::META_APRES, true ) );
        if ( ! $apres || ! isset( KMFamily_Memberships::RANKS[ $apres ] ) ) $apres = 'public';

        $trajet = array();
        foreach ( $steps as $palier => $jours ) {
            $trajet[] = array( 'at' => $dispo['public_at'] - ( (int) $jours * DAY_IN_SECONDS ), 'palier' => $palier );
        }
        $trajet[] = array( 'at' => $dispo['public_at'], 'palier' => $apres );
        usort( $trajet, function ( $a, $b ) { return $a['at'] <=> $b['at']; } );

        foreach ( $trajet as $etape ) {
            if ( $rang_membre >= KMFamily_Memberships::required_rank( $etape['palier'] ) ) {
                return $etape['at'] > $now ? $etape['at'] : null; // null = déjà accessible
            }
        }

        return null;
    }

    // ==========================================================
    // AFFICHAGE
    // ==========================================================

    /** « 2 j 04 h », « 03:12:45 » — un compte à rebours lisible. */
    public static function countdown( $seconds ) {
        $seconds = max( 0, (int) $seconds );

        if ( $seconds >= DAY_IN_SECONDS ) {
            $j = floor( $seconds / DAY_IN_SECONDS );
            $h = floor( ( $seconds % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
            return sprintf( '%d j %02d h', $j, $h );
        }

        return sprintf( '%02d:%02d:%02d',
            floor( $seconds / HOUR_IN_SECONDS ),
            floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS ),
            $seconds % MINUTE_IN_SECONDS
        );
    }

    /** Étiquette d'état, destinée aux cartes et aux listes. */
    public static function badge( $content_id, $user_id = 0, $now = null ) {
        $now   = $now ?: current_time( 'timestamp' );
        $check = self::check( $user_id, $content_id, $now );
        $d     = $check['dispo'];

        if ( $d['mode'] === 'drop' ) {
            if ( $d['state'] === 'a_venir' ) {
                return array( 'texte' => sprintf( __( 'DROP dans %s', 'km-family' ), self::countdown( $d['next_at'] - $now ) ), 'ton' => 'attente' );
            }
            if ( $d['state'] === 'termine' ) {
                return array( 'texte' => __( 'DROP terminé', 'km-family' ), 'ton' => 'fini' );
            }
            if ( $d['state'] === 'archive' ) {
                return array( 'texte' => __( 'Archive', 'km-family' ), 'ton' => 'neutre' );
            }
            if ( ! empty( $d['ends_at'] ) ) {
                return array( 'texte' => sprintf( __( 'Encore %s', 'km-family' ), self::countdown( $d['ends_at'] - $now ) ), 'ton' => 'urgent' );
            }
        }

        if ( $d['mode'] === 'premiere' ) {
            if ( $check['ok'] ) {
                if ( ! empty( $d['public_at'] ) && $now < $d['public_at'] ) {
                    return array( 'texte' => __( 'En avant-première', 'km-family' ), 'ton' => 'exclusif' );
                }
                return array( 'texte' => '', 'ton' => 'neutre' );
            }

            $quand = $user_id ? self::next_access_for_user( $user_id, $content_id, $now ) : null;
            if ( $quand ) {
                return array( 'texte' => sprintf( __( 'Pour vous dans %s', 'km-family' ), self::countdown( $quand - $now ) ), 'ton' => 'attente' );
            }
            if ( ! empty( $d['next_at'] ) ) {
                return array( 'texte' => sprintf( __( 'Première dans %s', 'km-family' ), self::countdown( $d['next_at'] - $now ) ), 'ton' => 'attente' );
            }
        }

        return array( 'texte' => '', 'ton' => 'neutre' );
    }

    // ==========================================================
    // CHAMPS ACF
    // ==========================================================

    public static function register_fields() {
        if ( ! function_exists( 'acf_add_local_field_group' ) ) return;

        $paliers_apres = array( 'public' => __( 'Public — tout le monde', 'km-family' ) );
        foreach ( array( 'bronze', 'argent', 'or', 'platine', 'diamant' ) as $p ) {
            $paliers_apres[ $p ] = ucfirst( $p ) . ' ' . __( 'et plus', 'km-family' );
        }

        acf_add_local_field_group( array(
            'key'      => 'group_kmfamily_programmation',
            'title'    => __( 'KM Family — Programmation & exclusivité', 'km-family' ),
            'location' => array( array( array(
                'param'    => 'post_type',
                'operator' => '==',
                'value'    => 'contenu_exclusif',
            ) ) ),
            'menu_order' => 5,
            'fields'   => array(
                array(
                    'key'           => 'field_km_mode_diffusion',
                    'label'         => __( 'Mode de diffusion', 'km-family' ),
                    'name'          => self::META_MODE,
                    'type'          => 'radio',
                    'choices'       => array(
                        'simple'   => __( 'Simple — disponible dès la publication, palier fixe', 'km-family' ),
                        'premiere' => __( 'Première — ouverture progressive palier par palier', 'km-family' ),
                        'drop'     => __( 'Drop — disponible sur une fenêtre limitée', 'km-family' ),
                    ),
                    'default_value' => 'simple',
                    'layout'        => 'vertical',
                ),
                array(
                    'key'               => 'field_km_date_sortie',
                    'label'             => __( 'Date de sortie publique (J)', 'km-family' ),
                    'name'              => self::META_DATE_SORTIE,
                    'type'              => 'date_time_picker',
                    'return_format'     => 'Y-m-d H:i:s',
                    'instructions'      => __( 'Point de référence de l\'ouverture progressive. Les paliers y accèdent avant cette date.', 'km-family' ),
                    'conditional_logic' => array( array( array( 'field' => 'field_km_mode_diffusion', 'operator' => '==', 'value' => 'premiere' ) ) ),
                ),
                array(
                    'key'               => 'field_km_rollout',
                    'label'             => __( 'Ouverture progressive', 'km-family' ),
                    'name'              => self::META_ROLLOUT,
                    'type'              => 'select',
                    'choices'           => self::preset_labels(),
                    'default_value'     => 'standard',
                    'conditional_logic' => array( array( array( 'field' => 'field_km_mode_diffusion', 'operator' => '==', 'value' => 'premiere' ) ) ),
                ),
                array(
                    'key'               => 'field_km_rollout_custom',
                    'label'             => __( 'Ouverture personnalisée', 'km-family' ),
                    'name'              => self::META_ROLLOUT_CUS,
                    'type'              => 'textarea',
                    'rows'              => 5,
                    'placeholder'       => "diamant:10\nplatine:7\nor:3\nargent:1",
                    'instructions'      => __( 'Une ligne par palier : <code>palier:jours avant la sortie</code>.', 'km-family' ),
                    'conditional_logic' => array( array(
                        array( 'field' => 'field_km_mode_diffusion', 'operator' => '==', 'value' => 'premiere' ),
                        array( 'field' => 'field_km_rollout', 'operator' => '==', 'value' => 'personnalise' ),
                    ) ),
                ),
                array(
                    'key'               => 'field_km_palier_apres',
                    'label'             => __( 'À partir de la date de sortie', 'km-family' ),
                    'name'              => self::META_APRES,
                    'type'              => 'select',
                    'choices'           => $paliers_apres,
                    'default_value'     => 'public',
                    'instructions'      => __( 'Choisir un palier au lieu de « Public » transforme la sortie en passage aux archives réservées aux membres.', 'km-family' ),
                    'conditional_logic' => array( array( array( 'field' => 'field_km_mode_diffusion', 'operator' => '==', 'value' => 'premiere' ) ) ),
                ),
                array(
                    'key'               => 'field_km_drop_debut',
                    'label'             => __( 'Début du drop', 'km-family' ),
                    'name'              => self::META_DROP_DEBUT,
                    'type'              => 'date_time_picker',
                    'return_format'     => 'Y-m-d H:i:s',
                    'conditional_logic' => array( array( array( 'field' => 'field_km_mode_diffusion', 'operator' => '==', 'value' => 'drop' ) ) ),
                ),
                array(
                    'key'               => 'field_km_drop_fin',
                    'label'             => __( 'Fin du drop', 'km-family' ),
                    'name'              => self::META_DROP_FIN,
                    'type'              => 'date_time_picker',
                    'return_format'     => 'Y-m-d H:i:s',
                    'instructions'      => __( 'C\'est la rareté qui fait le drop : une fenêtre de 48 ou 72 heures fonctionne mieux qu\'une semaine.', 'km-family' ),
                    'conditional_logic' => array( array( array( 'field' => 'field_km_mode_diffusion', 'operator' => '==', 'value' => 'drop' ) ) ),
                ),
                array(
                    'key'               => 'field_km_palier_apres_drop',
                    'label'             => __( 'Après la fenêtre', 'km-family' ),
                    'name'              => self::META_APRES_DROP,
                    'type'              => 'select',
                    'choices'           => array_merge( array( '' => __( 'Retiré — plus accessible', 'km-family' ) ), array(
                        'bronze'  => __( 'Archivé — Bronze et plus', 'km-family' ),
                        'argent'  => __( 'Archivé — Argent et plus', 'km-family' ),
                        'or'      => __( 'Archivé — Or et plus', 'km-family' ),
                        'platine' => __( 'Archivé — Platine et plus', 'km-family' ),
                        'diamant' => __( 'Archivé — Diamant uniquement', 'km-family' ),
                    ) ),
                    'default_value'     => '',
                    'conditional_logic' => array( array( array( 'field' => 'field_km_mode_diffusion', 'operator' => '==', 'value' => 'drop' ) ) ),
                ),
                array(
                    'key'   => 'field_km_saison',
                    'label' => __( 'Saison', 'km-family' ),
                    'name'  => 'km_saison',
                    'type'  => 'number',
                    'min'   => 0,
                    'wrapper' => array( 'width' => '50' ),
                ),
                array(
                    'key'   => 'field_km_episode',
                    'label' => __( 'Épisode', 'km-family' ),
                    'name'  => 'km_episode',
                    'type'  => 'number',
                    'min'   => 0,
                    'wrapper' => array( 'width' => '50' ),
                ),
            ),
        ) );
    }
}
