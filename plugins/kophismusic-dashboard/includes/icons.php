<?php
// Helper function pour les icônes SVG plateformes
defined( 'ABSPATH' ) || exit;

function km_platform_icon($platform, $size = 18) {
    $icons = array(
        'spotify' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="white"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.622.622 0 01-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.622.622 0 11-.277-1.215c3.809-.87 7.076-.496 9.712 1.115a.623.623 0 01.207.857zm1.223-2.722a.779.779 0 01-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 01-.973-.519.779.779 0 01.519-.972c3.632-1.102 8.147-.568 11.234 1.328a.779.779 0 01.257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.935.935 0 11-.543-1.79c3.532-1.072 9.404-.865 13.115 1.338a.935.935 0 01-.955 1.609z"/></svg>',
        'apple'   => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="white"><path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09z"/><path d="M15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701z"/></svg>',
        'youtube' => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="white"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
        'deezer'  => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="white"><path d="M19 15.094H5v1.602h14V15.094zM19 12H5v1.6h14V12zm0-3.09H5v1.6h14V8.91zm0-3.09H5v1.601h14V5.82zM5 18.18h14v-1.6H5v1.6z"/></svg>',
        'tidal'   => '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="white"><path d="M12.012 3.992L8.008 7.996 4.004 3.992 0 7.996l4.004 4.004L8.008 7.996l4.004 4.004 4.004-4.004L12.012 3.992zM8.008 12l-4.004 4.004L8.008 20.008l4.004-4.004L8.008 12zM16.016 12.004l-4.004 4.004 4.004 4.004 4.004-4.004-4.004-4.004z"/></svg>',
    );
    return isset($icons[$platform]) ? $icons[$platform] : '';
}

function km_platform_color($platform) {
    $colors = array(
        'spotify' => '#1DB954',
        'apple'   => '#FC3C44',
        'youtube' => '#FF0000',
        'deezer'  => '#A238FF',
        'tidal'   => '#00CFFF',
    );
    return isset($colors[$platform]) ? $colors[$platform] : '#666';
}

function km_platform_name($platform) {
    $names = array(
        'spotify' => 'Spotify',
        'apple'   => 'Apple Music',
        'youtube' => 'YouTube',
        'deezer'  => 'Deezer',
        'tidal'   => 'Tidal',
    );
    return isset($names[$platform]) ? $names[$platform] : ucfirst($platform);
}

function km_render_logo_header($subtitle = 'Label Urban Gospel') {
    $logo = defined('KM_LOGO_URL') ? KM_LOGO_URL : '';
    $out  = '<div class="km-header-logo">';
    if ($logo) {
        $out .= '<img src="'.esc_url($logo).'" alt="KOPHI\'S MUSIC" />';
    } else {
        $out .= '<div class="km-header-logo-fallback">KM</div>';
    }
    $out .= '</div>';
    $out .= '<div class="km-db-user-info">';
    $out .= '<span class="km-db-label-tag">KOPHI\'S MUSIC</span>';
    $out .= '<span class="km-db-role-tag">'.esc_html($subtitle).'</span>';
    $out .= '</div>';
    return $out;
}
