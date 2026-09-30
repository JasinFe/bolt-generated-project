<?php
/**
 * Paliers standardisés — 5 niveaux communs à tous les artistes du label
 *
 * @package KMFamily
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KMFamily_Paliers {

    const LEVELS = array( 'bronze', 'argent', 'or', 'platine', 'diamant', 'libre' );

    public static function init() {
        // Rien à initialiser, classe statique de référence
    }

    /**
     * Retourne la liste complète des paliers standardisés du label.
     * Ces paliers sont identiques pour TOUS les artistes.
     */
    public static function get_all() {
        return apply_filters( 'kmfamily_paliers_definition', array(
            'bronze' => array(
                'slug'        => 'bronze',
                'numero'      => '01',
                'nom'         => 'BRONZE',
                'statut'      => 'L\'Ami de l\'artiste',
                'prix'        => 1000,
                'color'       => '#cd7f32',
                'gradient'    => 'linear-gradient(135deg, #cd7f32 0%, #8b4513 100%)',
                'icon'        => '🥉',
                'description' => 'Accès au mur d\'actualités privées et aux infos en avant-première.',
                'benefits'    => array(
                    'Mur d\'actualités privées',
                    'Infos en avant-première',
                    'Badge Bronze sur votre profil',
                ),
            ),
            'argent' => array(
                'slug'        => 'argent',
                'numero'      => '02',
                'nom'         => 'ARGENT',
                'statut'      => 'Le Partenaire',
                'prix'        => 2000,
                'color'       => '#9ea0a3',
                'gradient'    => 'linear-gradient(135deg, #c0c0c0 0%, #808080 100%)',
                'icon'        => '🥈',
                'description' => 'Tout le Bronze + Écoute des nouveaux titres 48h avant tout le monde.',
                'benefits'    => array(
                    'Tous les avantages Bronze',
                    'Écoute des nouveaux titres 48h avant tout le monde',
                    'Badge Argent sur votre profil',
                ),
            ),
            'or' => array(
                'slug'        => 'or',
                'numero'      => '03',
                'nom'         => 'OR',
                'statut'      => 'L\'Ambassadeur',
                'prix'        => 5000,
                'color'       => '#d4af37',
                'gradient'    => 'linear-gradient(135deg, #ffd700 0%, #d4af37 50%, #b8941f 100%)',
                'icon'        => '🥇',
                'description' => 'Tout l\'Argent + Vidéos des coulisses (making-of) et -20% sur les tickets de concert.',
                'benefits'    => array(
                    'Tous les avantages Argent',
                    'Vidéos des coulisses (making-of)',
                    '-20% sur les tickets de concert',
                    'Badge Or sur votre profil',
                ),
            ),
            'platine' => array(
                'slug'        => 'platine',
                'numero'      => '04',
                'nom'         => 'PLATINE',
                'statut'      => 'Le Pilier',
                'prix'        => 15000,
                'color'       => '#e5e4e2',
                'gradient'    => 'linear-gradient(135deg, #f0f0f0 0%, #b8b8b8 100%)',
                'icon'        => '💎',
                'description' => 'Tout l\'Or + Nom au générique des clips et une rencontre virtuelle (Live privé) par mois.',
                'benefits'    => array(
                    'Tous les avantages Or',
                    'Votre nom au générique des clips',
                    'Rencontre virtuelle (Live privé) mensuelle',
                    'Badge Platine exclusif',
                ),
            ),
            'diamant' => array(
                'slug'        => 'diamant',
                'numero'      => '05',
                'nom'         => 'DIAMANT',
                'statut'      => 'Le Bâtisseur',
                'prix'        => 25000,
                'color'       => '#b9f2ff',
                'gradient'    => 'linear-gradient(135deg, #b9f2ff 0%, #4facfe 50%, #00f2fe 100%)',
                'icon'        => '👑',
                'description' => 'Statut de mécène, accès VIP illimité aux concerts, dîner annuel avec les artistes du label.',
                'benefits'    => array(
                    'Statut officiel de mécène',
                    'Accès VIP illimité à tous les concerts',
                    'Dîner annuel avec les artistes du label',
                    'Badge Diamant prestige',
                    'Mention honorifique sur le site',
                ),
            ),
            'libre' => array(
                'slug'        => 'libre',
                'numero'      => '06',
                'nom'         => 'LIBRE',
                'statut'      => 'Le Cœur Généreux',
                'prix'        => 0, // Montant défini par l'utilisateur
                'prix_min'    => 500,
                'is_free'     => true,
                'color'       => '#e91e63',
                'gradient'    => 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)',
                'icon'        => '💝',
                'description' => 'Soutenez l\'artiste avec le montant de votre choix. Chaque don compte, peu importe le montant.',
                'benefits'    => array(
                    'Contribution au montant de votre choix (minimum 500 FCFA)',
                    'Accès aux actualités privées',
                    'Votre nom dans la liste des soutiens',
                    'Badge Cœur Généreux',
                    'La gratitude éternelle de l\'artiste 💛',
                ),
            ),
        ) );
    }

    /**
     * Récupérer un palier spécifique.
     */
    public static function get( $slug ) {
        $paliers = self::get_all();
        return $paliers[ $slug ] ?? null;
    }

    /**
     * Hiérarchie numérique pour comparer les niveaux.
     */
    public static function get_hierarchy() {
        return array(
            'public'  => 0,
            'libre'   => 1,   // Palier libre : accès de base (comme bronze)
            'bronze'  => 1,
            'argent'  => 2,
            'or'      => 3,
            'platine' => 4,
            'diamant' => 5,
        );
    }

    /**
     * Label lisible.
     */
    public static function get_label( $slug ) {
        $palier = self::get( $slug );
        if ( $palier ) return $palier['nom'];
        return ucfirst( $slug );
    }

    /**
     * Couleur d'un palier.
     */
    public static function get_color( $slug ) {
        $palier = self::get( $slug );
        return $palier['color'] ?? '#666';
    }

    /**
     * Icône d'un palier.
     */
    public static function get_icon( $slug ) {
        $palier = self::get( $slug );
        return $palier['icon'] ?? '⭐';
    }

    /**
     * Vérifier qu'un slug est un palier valide.
     */
    public static function exists( $slug ) {
        return in_array( $slug, self::LEVELS, true );
    }

    /**
     * Retourner le palier d'un utilisateur pour un artiste donné.
     * (proxy vers Subscriptions pour faciliter l'accès dans les templates)
     */
    public static function get_user_palier_for_artist( $user_id, $artiste_id ) {
        if ( ! class_exists( 'KMFamily_Subscriptions' ) ) return null;
        $sub = KMFamily_Subscriptions::get_user_subscription( $user_id, $artiste_id );
        if ( ! $sub || empty( $sub['expire'] ) || $sub['expire'] < time() ) return null;
        return $sub['palier'];
    }

    /**
     * Retourner le palier le plus élevé d'un utilisateur tous artistes confondus.
     * Utile pour afficher un badge global sur le profil.
     */
    public static function get_user_highest_palier( $user_id ) {
        if ( ! class_exists( 'KMFamily_Subscriptions' ) ) return null;

        $subs = KMFamily_Subscriptions::get_user_subscriptions( $user_id );
        if ( empty( $subs ) ) return null;

        $hierarchy = self::get_hierarchy();
        $highest_level = 0;
        $highest_slug = null;

        foreach ( $subs as $sub ) {
            if ( empty( $sub['expire'] ) || $sub['expire'] < time() ) continue;

            $level = $hierarchy[ $sub['palier'] ] ?? 0;
            if ( $level > $highest_level ) {
                $highest_level = $level;
                $highest_slug = $sub['palier'];
            }
        }

        return $highest_slug;
    }
}
