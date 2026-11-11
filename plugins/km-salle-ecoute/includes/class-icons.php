<?php
/**
 * KM SALLE D'ÉCOUTE — Icônes SVG inline (remplace les emojis, rendu net et cohérent)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class KM_Salle_Ecoute_Icons {

    /** Icônes "pleines" (lecteur) */
    private const FILLED = [
        'play'  => '<path d="M8 5v14l11-7z"/>',
        'pause' => '<path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>',
        'prev'  => '<path d="M6 6h2v12H6zm3.5 6 8.5 6V6z"/>',
        'next'  => '<path d="M6 18l8.5-6L6 6v12zM16 6h2v12h-2z"/>',
    ];

    /** Icônes "filaire" (interface) */
    private const STROKE = [
        'music'      => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
        'headphones' => '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>',
        'lock'       => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'key'        => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
        'globe'      => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'alert'      => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'message'    => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'ban'        => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
        'clock'      => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'volume-2'   => '<path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/>',
        'volume-1'   => '<path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/>',
        'volume-x'   => '<path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="m22 9-6 6"/><path d="m16 9 6 6"/>',
        'search'     => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'check'      => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
        'mic'        => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/>',
        'sliders'    => '<path d="M4 21v-7"/><path d="M4 10V3"/><path d="M12 21v-9"/><path d="M12 8V3"/><path d="M20 21v-5"/><path d="M20 12V3"/><path d="M1 14h6"/><path d="M9 8h6"/><path d="M17 16h6"/>',
        'shuffle'    => '<path d="M16 3h5v5"/><path d="M4 20 21 3"/><path d="M21 16v5h-5"/><path d="m15 15 6 6"/><path d="M4 4l5 5"/>',
        'repeat'     => '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
        'repeat-1'   => '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/><path d="M11 10h1v4"/>',
        'eye'        => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off'    => '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3 8 10 8a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/>',
        'keyboard'   => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M6 8h.01M10 8h.01M14 8h.01M18 8h.01M8 12h.01M12 12h.01M16 12h.01M7 16h10"/>',
        'download'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'arrow-left' => '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
        'close'      => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'lyrics'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 8h8"/><path d="M8 12h5"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'expand'     => '<path d="M15 3h6v6"/><path d="M9 21H3v-6"/><path d="m21 3-7 7"/><path d="m3 21 7-7"/>',
        'info'       => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
    ];

    /**
     * Retourne le balisage SVG d'une icône (contenu statique, sûr).
     */
    public static function svg( string $name, int $size = 18, string $extra_class = '' ): string {
        $class = 'km-i km-i-' . $name . ( $extra_class ? ' ' . $extra_class : '' );

        if ( isset( self::FILLED[ $name ] ) ) {
            return sprintf(
                '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">%s</svg>',
                esc_attr( $class ), $size, $size, self::FILLED[ $name ]
            );
        }
        if ( isset( self::STROKE[ $name ] ) ) {
            return sprintf(
                '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
                esc_attr( $class ), $size, $size, self::STROKE[ $name ]
            );
        }
        return '';
    }

    /** Icône associée à un type de contenu (nom ou slug) */
    public static function for_type( string $type ): string {
        $key = sanitize_title( $type );
        $map = [
            'demo'          => 'mic',
            'produit-fini'  => 'check',
            'produit_fini'  => 'check',
            'instrumental'  => 'sliders',
            'remix'         => 'shuffle',
        ];
        return $map[ $key ] ?? 'music';
    }

    /** Libellé + icône du mode d'accès */
    public static function access( string $mode ): array {
        $map = [
            'public'   => [ 'icon' => 'globe', 'label' => __( 'Accès public',  'km-salle-ecoute' ) ],
            'password' => [ 'icon' => 'key',   'label' => __( 'Accès protégé', 'km-salle-ecoute' ) ],
            'prive'    => [ 'icon' => 'lock',  'label' => __( 'Accès privé',   'km-salle-ecoute' ) ],
        ];
        return $map[ $mode ] ?? $map['prive'];
    }
}
