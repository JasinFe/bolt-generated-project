<?php
/**
 * Plugin Name: KOPHI'S MUSIC STUDIO Manager
 * Plugin URI: https://studio.kophismusic.com
 * Description: Système complet de gestion du studio - Sessions, Clients, Projets, Réservations, Beats, Planning. Conçu pour WordPress Multisite.
 * Version: 4.8.0
 * Author: KOPHI'S MUSIC STUDIO
 * Author URI: https://kophismusic.com
 * Text Domain: kophi-studio
 */

if (!defined('ABSPATH')) exit;

// ================================================================
// GUARD MULTISITE — Ce plugin tourne UNIQUEMENT sur studio.kophismusic.com
// ================================================================
if (function_exists('is_multisite') && is_multisite()) {
    $current_blog = get_current_blog_id();
    $current_domain = parse_url(get_site_url($current_blog), PHP_URL_HOST);
    // Autoriser studio.kophismusic.com uniquement
    // Si le domaine actuel n'est pas le sous-site studio, ne pas charger
    if ($current_domain && $current_domain !== 'studio.kophismusic.com') {
        return; // Stopper silencieusement sur kophismusic.com
    }
    // Si domaine non déterminable, laisser passer (sécurité)
}

define('KOPHI_VERSION',  '4.8.0');
define('KOPHI_DIR',      plugin_dir_path(__FILE__));
define('KOPHI_URL',      plugin_dir_url(__FILE__));
define('KOPHI_EMAIL',    'contact@kophismusic.com');
define('KOPHI_WA',       '+2250503404389');
define('KOPHI_WA_LINK',  'https://wa.me/2250503404389');
define('KOPHI_WA2',       '+2250576154653');
define('KOPHI_WA2_LINK',  'https://wa.me/2250576154653');

// Formatage "70000" -> "70 000" (espace comme séparateur de milliers, style FCFA).
// Utilisé partout où un tarif kophi_tarif_* est affiché (admin ET front).
if (!function_exists('kophi_fmt_fcfa')) {
    function kophi_fmt_fcfa($n) {
        return number_format((float) $n, 0, ',', ' ');
    }
}

// ================================================================
// CHARGEMENT DES FICHIERS INCLUS
// ================================================================
require_once KOPHI_DIR . 'includes/register-cpts.php'; // CPTs natifs PHP — sans CPT UI
require_once KOPHI_DIR . 'includes/fixes.php';          // Pleine largeur, anciens shortcodes, compatibilité
require_once KOPHI_DIR . 'includes/licence-generator.php';
require_once KOPHI_DIR . 'includes/security.php';       // Anti-abus / limitation de débit
require_once KOPHI_DIR . 'includes/payment-methods.php'; // Moyens de paiement manuels (Wave/OM/MTN/Moov)
require_once KOPHI_DIR . 'includes/payment.php';
require_once KOPHI_DIR . 'includes/payment-center.php';  // Smart Payment Center (adapté de KM Family)
require_once KOPHI_DIR . 'includes/ecoute.php';        // Écoute Client — lecteur HTML version finale
require_once KOPHI_DIR . 'includes/login.php';         // Page de connexion personnalisée

// ================================================================
// 1. ACTIVATION / DÉSACTIVATION
// ================================================================
register_activation_hook(__FILE__, 'kophi_activate');

// Vérification tables au chargement (sécurité après réinstallation)
add_action('init', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'kophi_blocages';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        kophi_create_tables();
    }
}, 5);
register_deactivation_hook(__FILE__, 'kophi_deactivate');

// ── Migration auto des rôles/capacités à chaque mise à jour de version ──
// Permet d'appliquer les nouvelles capacités (ex: accès Assistant/Beatmaker
// étendu) sans devoir désactiver/réactiver le plugin.
add_action('init', function() {
    $installed = get_option('kophi_version', '');
    if ($installed !== KOPHI_VERSION) {
        kophi_create_roles();
        update_option('kophi_version', KOPHI_VERSION);
    }
}, 6);

function kophi_activate($network_wide = false) {
    if ($network_wide && is_multisite()) {
        // Activation réseau : créer les tables uniquement sur studio.kophismusic.com
        $studio_blog = get_blog_id_from_url('studio.kophismusic.com');
        if ($studio_blog) {
            switch_to_blog($studio_blog);
            kophi_create_tables();
            kophi_create_roles();
            restore_current_blog();
        }
    } else {
        // Activation sur site unique (méthode recommandée pour ce plugin)
        kophi_create_tables();
        kophi_create_roles();
    }
    // Créer les pages front si elles n'existent pas
    kophi_create_pages();
    flush_rewrite_rules();
}

function kophi_deactivate() {
    flush_rewrite_rules();
}

// ================================================================
// Création automatique des pages front
// ================================================================
function kophi_create_pages() {
    $pages = [
        'reserver' => [
            'title'   => 'Réserver',
            'content' => '[kophi_reservation]',
            'status'  => 'publish',
        ],
        'beats' => [
            'title'   => 'Beats',
            'content' => '[kophi_beats]',
            'status'  => 'publish',
        ],
        'dashboard' => [
            'title'   => 'Mon Tableau de Bord',
            'content' => '[kophi_dashboard]',
            'status'  => 'publish',
        ],
    ];

    foreach ($pages as $slug => $data) {
        // Vérifier si la page existe déjà (slug exact)
        $existing = get_posts([
            'name'           => $slug,
            'post_type'      => 'page',
            'post_status'    => ['publish','draft'],
            'posts_per_page' => 1,
        ]);

        if (empty($existing)) {
            wp_insert_post([
                'post_type'    => 'page',
                'post_title'   => $data['title'],
                'post_content' => $data['content'],
                'post_status'  => $data['status'],
                'post_name'    => $slug,
            ]);
        }
    }
}

// Créer les pages si manquantes (sans flush pour éviter erreur fatale)
add_action('admin_init', function() {
    if (get_option('kophi_pages_checked_v4')) return;
    kophi_create_pages();
    update_option('kophi_pages_checked_v4', true);
});

// ================================================================
// 2. TABLES BDD PERSONNALISÉES
// ================================================================
function kophi_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $prefix  = $wpdb->prefix;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Table blocages internes
    dbDelta("CREATE TABLE IF NOT EXISTS {$prefix}kophi_blocages (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        titre varchar(200) NOT NULL,
        date_debut date NOT NULL,
        date_fin date NOT NULL,
        heure_debut time DEFAULT NULL,
        heure_fin time DEFAULT NULL,
        journee_complete tinyint(1) DEFAULT 0,
        motif varchar(50) DEFAULT 'autre',
        description text,
        created_by varchar(100),
        created_at timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY date_debut (date_debut),
        KEY date_fin (date_fin)
    ) $charset;");

    // Table créneaux config horaires
    dbDelta("CREATE TABLE IF NOT EXISTS {$prefix}kophi_creneaux (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        jour_semaine tinyint NOT NULL,
        heure_ouverture time DEFAULT '09:00:00',
        heure_fermeture time DEFAULT '22:00:00',
        actif tinyint(1) DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY jour_semaine (jour_semaine)
    ) $charset;");

    // Insérer horaires par défaut si vides
    $exists = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}kophi_creneaux");
    if (!$exists) {
        $horaires = [
            [0,'00:00:00','00:00:00',0],
            [1,'09:00:00','22:00:00',1],
            [2,'09:00:00','22:00:00',1],
            [3,'09:00:00','22:00:00',1],
            [4,'09:00:00','22:00:00',1],
            [5,'09:00:00','22:00:00',1],
            [6,'10:00:00','20:00:00',1],
        ];
        foreach ($horaires as $h) {
            $wpdb->insert("{$prefix}kophi_creneaux", [
                'jour_semaine'    => $h[0],
                'heure_ouverture' => $h[1],
                'heure_fermeture' => $h[2],
                'actif'           => $h[3],
            ]);
        }
    }
}

// ================================================================
// 3. RÔLES UTILISATEURS
// ================================================================
function kophi_create_roles() {
    // Ingénieur du son
    add_role('kophi_ingenieur', 'Ingénieur KOPHI', [
        'read'                      => true,
        'edit_posts'                => false,
        'kophi_manage_sessions'     => true,
        'kophi_view_clients'        => true,
        'kophi_manage_planning'     => true,
    ]);
    // Commercial / réceptionniste
    add_role('kophi_commercial', 'Commercial KOPHI', [
        'read'                      => true,
        'kophi_manage_reservations' => true,
        'kophi_view_clients'        => true,
        'kophi_view_stats'          => true,
    ]);
    // ── Assistant KOPHI / Beatmaker — accès complet à l'espace KOPHI ────
    // Peut : tout l'espace KOPHI (dashboard, sessions, clients, projets,
    //        planning, réservations, statistiques, beats, tarifs)
    //        + uploader des sons (beats) et des médias (médiathèque WP)
    // Ne peut PAS : accéder aux Paramètres KOPHI, ni à l'administration
    //               WordPress sensible (utilisateurs, extensions, thèmes,
    //               réglages généraux) — voir restrictions plus bas.
    $assistant_caps = [
        'read'                      => true,   // Obligatoire pour accéder à l'admin WP
        'level_0'                   => true,   // Niveau minimum WordPress (requis Multisite)
        'kophi_manage_sessions'     => true,   // Sessions + Projets
        'kophi_view_clients'        => true,   // Clients
        'kophi_manage_planning'     => true,   // Planning & Disponibilités
        'kophi_manage_reservations' => true,   // Réservations (valider, confirmer, annuler)
        'kophi_view_stats'          => true,   // Statistiques
        'kophi_manage_beats'        => true,   // Boutique Beats (gestion complète)
        // ── Capacités WordPress nécessaires pour uploader sons & médias ──
        'upload_files'              => true,   // Médiathèque WP (sons, images, covers)
        'edit_posts'                => true,   // Créer/modifier des beats (CPT kophi_beat)
        'edit_published_posts'      => true,
        'publish_posts'             => true,
        'delete_posts'              => true,
        'edit_others_posts'         => true,   // Voir/éditer les beats des autres membres
        // Toujours pas de manage_options (réservé à l'administrateur)
    ];
    if (!get_role('kophi_assistant')) {
        add_role('kophi_assistant', 'Assistant / Beatmaker KOPHI Studio', $assistant_caps);
    } else {
        // Mettre à jour les caps si le rôle existe déjà
        $role = get_role('kophi_assistant');
        foreach ($assistant_caps as $cap => $grant) {
            $role->add_cap($cap, $grant);
        }
        // Mettre à jour le libellé affiché du rôle (WordPress ne le fait pas via add_role)
        global $wp_roles;
        if (!isset($wp_roles)) $wp_roles = wp_roles();
        if (isset($wp_roles->roles['kophi_assistant'])) {
            $wp_roles->roles['kophi_assistant']['name'] = 'Assistant / Beatmaker KOPHI Studio';
            $wp_roles->role_names['kophi_assistant']    = 'Assistant / Beatmaker KOPHI Studio';
            update_option($wp_roles->role_key, $wp_roles->roles);
        }
    }
    // Ajouter capacités à l'admin
    $admin = get_role('administrator');
    if ($admin) {
        $caps = ['kophi_manage_sessions','kophi_view_clients','kophi_manage_planning',
                 'kophi_manage_reservations','kophi_view_stats','kophi_manage_beats'];
        foreach ($caps as $cap) $admin->add_cap($cap);
    }
}

// ================================================================
// 4. MENUS D'ADMINISTRATION
// ================================================================
add_action('admin_menu', 'kophi_admin_menus');
function kophi_admin_menus() {
    // Menu principal
    // Capability de base : admin toujours autorisé, équipe via capabilities custom
    $cap_base   = current_user_can('administrator') ? 'manage_options' : 'kophi_manage_sessions';
    $cap_client = current_user_can('administrator') ? 'manage_options' : 'kophi_view_clients';
    $cap_resv   = current_user_can('administrator') ? 'manage_options' : 'kophi_manage_reservations';
    $cap_stats  = current_user_can('administrator') ? 'manage_options' : 'kophi_view_stats';
    $cap_beats  = current_user_can('administrator') ? 'manage_options' : 'kophi_manage_beats';

    add_menu_page(
        "KOPHI'S MUSIC STUDIO", "KOPHI'S STUDIO", 'read',
        'kophi-dashboard', 'kophi_page_dashboard',
        'dashicons-playlist-audio', 3
    );
    add_submenu_page('kophi-dashboard','Tableau de bord','🎙 Dashboard','read','kophi-dashboard','kophi_page_dashboard');
    add_submenu_page('kophi-dashboard','Sessions','⏱ Sessions',$cap_base,'kophi-sessions','kophi_page_sessions');
    add_submenu_page('kophi-dashboard','Clients','👤 Clients',$cap_client,'kophi-clients','kophi_page_clients');
    add_submenu_page('kophi-dashboard','Projets','🎵 Projets',$cap_base,'kophi-projets','kophi_page_projets');
    add_submenu_page('kophi-dashboard','Planning','📅 Planning',$cap_base,'kophi-planning','kophi_page_planning');
    add_submenu_page('kophi-dashboard','Réservations','📋 Réservations',$cap_resv,'kophi-reservations','kophi_page_reservations');
    add_submenu_page('kophi-dashboard','Statistiques','📊 Statistiques',$cap_stats,'kophi-statistiques','kophi_page_stats');
    add_submenu_page('kophi-dashboard','Boutique Beats','🎹 Beats',$cap_beats,'kophi-beats','kophi_page_beats');
    add_submenu_page('kophi-dashboard','Tarifs','💰 Tarifs','read','kophi-tarifs','kophi_page_tarifs');
    add_submenu_page('kophi-dashboard','Menu Navigation','🔗 Menu Nav','manage_options','edit.php?post_type=kophi_menu_item','');
    add_submenu_page('kophi-dashboard','Paramètres','⚙ Paramètres','manage_options','kophi-settings','kophi_page_settings');
}

// ================================================================
// 5. ENQUEUE ASSETS ADMIN
// ================================================================
/**
 * Détecte si on est sur un écran du plugin KOPHI
 * (page admin kophi-* OU édition d'un CPT KOPHI).
 */
function ksm_is_studio_screen($hook = ''): bool {
    if ($hook && strpos($hook, 'kophi') !== false) return true;
    if (isset($_GET['page']) && strpos((string) $_GET['page'], 'kophi') === 0) return true;
    if (function_exists('get_current_screen')) {
        $s = get_current_screen();
        if ($s && isset($s->post_type) && strpos((string) $s->post_type, 'kophi_') === 0) return true;
        if ($s && isset($s->id) && strpos((string) $s->id, 'kophi') !== false) return true;
    }
    return false;
}

add_action('admin_enqueue_scripts', 'kophi_admin_assets');
function kophi_admin_assets($hook) {
    if (!ksm_is_studio_screen($hook)) return;
    wp_enqueue_style('kophi-admin', KOPHI_URL . 'assets/admin.css', [], KOPHI_VERSION);
    wp_enqueue_script('kophi-admin', KOPHI_URL . 'assets/admin.js', ['jquery'], KOPHI_VERSION, true);
    wp_localize_script('kophi-admin', 'KOPHI', [
        'ajaxurl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('kophi_nonce'),
        'rest_url' => rest_url('kophi/v1/'),
        'wa'       => KOPHI_WA_LINK,
        'email'    => KOPHI_EMAIL,
    ]);
    // Chart.js pour les stats
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true);
}

/**
 * FILET DE SÉCURITÉ — injecte le CSS admin EN LIGNE sur les écrans KOPHI.
 * Garantit que le tableau de bord reste stylé même si le fichier admin.css
 * externe est bloqué, mis en cache de façon agressive (Hostinger / CDN), ou
 * non chargé pour une raison quelconque. C'est ce qui évite l'affichage
 * « tout empilé sans style ».
 */
add_action('admin_head', 'ksm_admin_inline_css', 99);
function ksm_admin_inline_css() {
    if (!ksm_is_studio_screen()) return;
    $css_file = KOPHI_DIR . 'assets/admin.css';
    if (is_readable($css_file)) {
        echo "\n<style id=\"kophi-admin-inline\">\n" . file_get_contents($css_file) . "\n</style>\n";
    }
}

// ================================================================
// 6. ENQUEUE ASSETS FRONT
// ================================================================
add_action('wp_enqueue_scripts', 'kophi_front_assets');
function kophi_front_assets() {
    wp_enqueue_style('kophi-fonts', 'https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Montserrat:wght@300;400;500;600;700&display=swap', [], null);
    wp_enqueue_style('kophi-front', KOPHI_URL . 'assets/front.css', [], KOPHI_VERSION);
    wp_enqueue_script('kophi-front', KOPHI_URL . 'assets/front.js', [], KOPHI_VERSION, true);
    // Lecteur unifié (barre de lecture) : enregistré partout, chargé par les gabarits qui en ont besoin
    wp_register_style('kophi-player', KOPHI_URL . 'assets/css/kophi-player.css', [], KOPHI_VERSION);
    wp_register_script('kophi-player', KOPHI_URL . 'assets/js/kophi-player.js', [], KOPHI_VERSION, true);
    wp_localize_script('kophi-front', 'KOPHI', [
        'ajaxurl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('kophi_front_nonce'),
        'rest_url' => rest_url('kophi/v1/'),
        'wa'       => KOPHI_WA_LINK,
        'wa_num'   => KOPHI_WA,
    ]);
}

// ================================================================
// 7. REST API ENDPOINTS
// ================================================================
add_action('rest_api_init', 'kophi_register_rest_routes');
add_action('rest_api_init', 'kophi_register_daw_routes');

function kophi_register_daw_routes() {
    register_rest_route('kophi/v1', '/daw-tracker', [
        'methods'             => 'POST',
        'callback'            => 'kophi_api_daw_tracker',
        'permission_callback' => 'kophi_check_daw_key',
    ]);
}

function kophi_check_daw_key(WP_REST_Request $req): bool {
    $key = $req->get_header('X-Kophi-DAW-Key');
    return $key === get_option('kophi_daw_api_key', 'KOPHIS_DAW_SECRET_KEY_2025');
}

function kophi_api_daw_tracker(WP_REST_Request $req) {
    $action  = sanitize_text_field($req->get_param('action'));
    $daw_app = sanitize_text_field($req->get_param('daw_app') ?: 'FL Studio');
    $projet  = sanitize_text_field($req->get_param('projet_nom') ?: 'Projet sans titre');
    $start   = (int)$req->get_param('start_ts');

    // Heartbeat — maintenir la session active
    if ($action === 'heartbeat') {
        $existing = get_posts(['post_type'=>'kophi_session','posts_per_page'=>1,'meta_query'=>[
            ['key'=>'_kophi_daw_actif','value'=>'1'],
            ['key'=>'_kophi_daw_app','value'=>$daw_app],
        ]]);
        if (!empty($existing)) {
            update_post_meta($existing[0]->ID, '_kophi_daw_last_hb', time());
            update_post_meta($existing[0]->ID, '_kophi_daw_projet', $projet ?: get_post_meta($existing[0]->ID,'_kophi_daw_projet',true));
            return rest_ensure_response(['success'=>true,'action'=>'heartbeat_ok','id'=>$existing[0]->ID]);
        }
        // Pas de session active → en créer une
        $action = 'start';
    }

    if ($action === 'start' || $action === 'project_change') {
        // Créer ou mettre à jour une session DAW
        $existing = get_posts(['post_type'=>'kophi_session','posts_per_page'=>1,'meta_query'=>[
            ['key'=>'_kophi_daw_actif','value'=>'1'],
            ['key'=>'_kophi_daw_app','value'=>$daw_app],
        ]]);
        if (!empty($existing) && $action === 'project_change') {
            update_post_meta($existing[0]->ID, '_kophi_daw_projet', $projet);
            return rest_ensure_response(['success'=>true,'action'=>'updated','id'=>$existing[0]->ID]);
        }
        if (empty($existing)) {
            $id = wp_insert_post([
                'post_type'   => 'kophi_session',
                'post_title'  => $projet . ' — ' . $daw_app . ' — ' . date('d/m/Y H:i'),
                'post_status' => 'publish',
            ]);
            if (!is_wp_error($id)) {
                update_post_meta($id,'_kophi_type_session','interne');
                update_post_meta($id,'_kophi_daw_actif','1');
                update_post_meta($id,'_kophi_daw_app',$daw_app);
                update_post_meta($id,'_kophi_daw_projet',$projet);
                update_post_meta($id,'_kophi_statut','live');
                update_post_meta($id,'_kophi_date_session',date('Y-m-d'));
                update_post_meta($id,'_kophi_heure_debut',date('H:i'));
                update_post_meta($id,'_kophi_live_start_ts',$start ?: time());
                update_post_meta($id,'_kophi_duree_prevue',8);
                update_post_meta($id,'_kophi_client_nom','Label — Interne');
                return rest_ensure_response(['success'=>true,'action'=>'created','id'=>$id]);
            }
        }
    } elseif ($action === 'stop') {
        $existing = get_posts(['post_type'=>'kophi_session','posts_per_page'=>1,'meta_query'=>[
            ['key'=>'_kophi_daw_actif','value'=>'1'],
            ['key'=>'_kophi_daw_app','value'=>$daw_app],
        ]]);
        if (!empty($existing)) {
            $id = $existing[0]->ID;
            $duree_min = (int)$req->get_param('duree_min');
            update_post_meta($id,'_kophi_statut','done');
            update_post_meta($id,'_kophi_daw_actif','0');
            update_post_meta($id,'_kophi_duree_prevue',round($duree_min/60,1));
            return rest_ensure_response(['success'=>true,'action'=>'stopped','id'=>$id]);
        }
    }
    return rest_ensure_response(['success'=>false,'message'=>'Aucune action effectuée']);
}
function kophi_register_rest_routes() {
    $ns = 'kophi/v1';

    // Disponibilités (public)
    register_rest_route($ns, '/disponibilite', ['methods'=>'GET','callback'=>'kophi_api_disponibilite','permission_callback'=>'__return_true']);
    register_rest_route($ns, '/disponibilite/mois', ['methods'=>'GET','callback'=>'kophi_api_dispo_mois','permission_callback'=>'__return_true']);

    // Réservations (public POST, admin GET)
    register_rest_route($ns, '/reservations', ['methods'=>'POST','callback'=>'kophi_api_create_reservation','permission_callback'=>'__return_true']);
    register_rest_route($ns, '/reservations', ['methods'=>'GET','callback'=>'kophi_api_list_reservations','permission_callback'=>'kophi_check_auth']);
    register_rest_route($ns, '/reservations/(?P<id>\d+)', ['methods'=>['PUT','PATCH'],'callback'=>'kophi_api_update_reservation','permission_callback'=>'kophi_check_auth']);

    // Beats (public GET, admin POST/PUT/DELETE)
    register_rest_route($ns, '/beats', ['methods'=>'GET','callback'=>'kophi_api_list_beats','permission_callback'=>'__return_true']);
    register_rest_route($ns, '/beats/achat', ['methods'=>'POST','callback'=>'kophi_api_achat_beat','permission_callback'=>'__return_true']);

    // Blocages planning (auth)
    register_rest_route($ns, '/blocages', ['methods'=>'GET','callback'=>'kophi_api_list_blocages','permission_callback'=>'kophi_check_auth']);
    register_rest_route($ns, '/blocages', ['methods'=>'POST','callback'=>'kophi_api_create_blocage','permission_callback'=>'kophi_check_auth']);
    register_rest_route($ns, '/blocages/(?P<id>\d+)', ['methods'=>'DELETE','callback'=>'kophi_api_delete_blocage','permission_callback'=>'kophi_check_auth']);

    // Status studio (auth)
    register_rest_route($ns, '/studio-status', ['methods'=>'GET','callback'=>'kophi_api_studio_status','permission_callback'=>'kophi_check_auth']);

    // Stats (auth)
    register_rest_route($ns, '/stats', ['methods'=>'GET','callback'=>'kophi_api_stats','permission_callback'=>'kophi_check_auth']);

    // Réservations admin — prise par téléphone par l'équipe studio
    register_rest_route($ns, '/admin/reservations', [
        'methods'             => 'POST',
        'callback'            => 'kophi_api_admin_reservation',
        'permission_callback' => 'kophi_check_auth',
    ]);

    // Sessions internes label — bloque le créneau
    register_rest_route($ns, '/admin/sessions-internes', [
        'methods'             => 'POST',
        'callback'            => 'kophi_api_session_interne',
        'permission_callback' => 'kophi_check_auth',
    ]);

    // DAW Tracker (script Python sur l'ordi du studio)
    register_rest_route($ns, '/daw-tracker', [
        'methods'             => 'POST',
        'callback'            => 'kophi_api_daw_tracker',
        'permission_callback' => 'kophi_check_daw_key',
    ]);
}

function kophi_check_auth() {
    return is_user_logged_in() && (current_user_can('administrator') || current_user_can('kophi_manage_sessions') || current_user_can('kophi_commercial'));
}

// ================================================================
// 8. API — DISPONIBILITÉS
// ================================================================
function kophi_api_disponibilite(WP_REST_Request $req) {
    global $wpdb;
    $date    = sanitize_text_field($req->get_param('date'));
    $service = sanitize_text_field($req->get_param('service') ?: 'voix');
    $forfait = sanitize_text_field($req->get_param('forfait') ?: '50000');
    $duree   = kophi_get_duree($service, $forfait);

    if (!$date) return new WP_Error('missing_date','Date requise',['status'=>422]);

    $creneaux = kophi_gen_creneaux($date, $duree);
    return rest_ensure_response($creneaux);
}

function kophi_api_dispo_mois(WP_REST_Request $req) {
    $mois    = sanitize_text_field($req->get_param('mois') ?: date('Y-m'));
    $service = sanitize_text_field($req->get_param('service') ?: 'voix');
    $forfait = sanitize_text_field($req->get_param('forfait') ?: '50000');
    $duree   = kophi_get_duree($service, $forfait);

    [$year, $month] = array_pad(explode('-', $mois), 2, '01');
    $days   = (int)date('t', mktime(0,0,0,(int)$month,1,(int)$year));
    $hier   = date('Y-m-d', strtotime('-1 day'));
    $mois_debut = sprintf('%04d-%02d-01', $year, $month);
    $mois_fin   = sprintf('%04d-%02d-%02d', $year, $month, $days);

    global $wpdb;

    // ── 1. Charger les horaires une seule fois ──────────────────
    $horaires = [];
    $rows = $wpdb->get_results("SELECT jour_semaine, actif, heure_ouverture, heure_fermeture FROM {$wpdb->prefix}kophi_creneaux");
    foreach ($rows as $r) {
        $horaires[(int)$r->jour_semaine] = [
            'actif' => (bool)$r->actif,
            'ouv'   => substr($r->heure_ouverture, 0, 5),
            'ferm'  => substr($r->heure_fermeture, 0, 5),
        ];
    }
    $def = [
        0=>['actif'=>false,'ouv'=>'00:00','ferm'=>'00:00'],
        1=>['actif'=>true,'ouv'=>'09:00','ferm'=>'22:00'],
        2=>['actif'=>true,'ouv'=>'09:00','ferm'=>'22:00'],
        3=>['actif'=>true,'ouv'=>'09:00','ferm'=>'22:00'],
        4=>['actif'=>true,'ouv'=>'09:00','ferm'=>'22:00'],
        5=>['actif'=>true,'ouv'=>'09:00','ferm'=>'22:00'],
        6=>['actif'=>true,'ouv'=>'10:00','ferm'=>'20:00'],
    ];

    // ── 2. Charger tous les blocages du mois en 1 requête ───────
    $blocages_mois = [];
    $bls = $wpdb->get_results($wpdb->prepare(
        "SELECT titre, date_debut, date_fin, heure_debut, heure_fin, journee_complete
         FROM {$wpdb->prefix}kophi_blocages
         WHERE date_debut <= %s AND date_fin >= %s",
        $mois_fin, $mois_debut
    ));

    // ── 3. Charger toutes les sessions du mois en 1 requête ─────
    $sessions_mois = $wpdb->get_results($wpdb->prepare(
        "SELECT pm_date.meta_value AS date_s, pm_heure.meta_value AS heure,
                pm_duree.meta_value AS duree, pm_statut.meta_value AS statut
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm_date   ON pm_date.post_id   = p.ID AND pm_date.meta_key   = '_kophi_date_session'
         INNER JOIN {$wpdb->postmeta} pm_heure  ON pm_heure.post_id  = p.ID AND pm_heure.meta_key  = '_kophi_heure_debut'
         INNER JOIN {$wpdb->postmeta} pm_duree  ON pm_duree.post_id  = p.ID AND pm_duree.meta_key  = '_kophi_duree_prevue'
         LEFT  JOIN {$wpdb->postmeta} pm_statut ON pm_statut.post_id = p.ID AND pm_statut.meta_key = '_kophi_statut'
         WHERE p.post_type = 'kophi_session'
           AND p.post_status = 'publish'
           AND pm_date.meta_value BETWEEN %s AND %s
           AND COALESCE(pm_statut.meta_value, 'scheduled') IN ('scheduled','live','interne')",
        $mois_debut, $mois_fin
    ));

    // ── 4. Charger toutes les réservations confirmées du mois ───
    $reservs_mois = $wpdb->get_results($wpdb->prepare(
        "SELECT pm_date.meta_value AS date_r, pm_heure.meta_value AS heure,
                pm_svc.meta_value AS service, pm_forf.meta_value AS forfait,
                pm_statut.meta_value AS statut
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm_date   ON pm_date.post_id   = p.ID AND pm_date.meta_key   = '_kophi_date_souhaitee'
         INNER JOIN {$wpdb->postmeta} pm_heure  ON pm_heure.post_id  = p.ID AND pm_heure.meta_key  = '_kophi_heure_souhaitee'
         LEFT  JOIN {$wpdb->postmeta} pm_svc    ON pm_svc.post_id    = p.ID AND pm_svc.meta_key    = '_kophi_service'
         LEFT  JOIN {$wpdb->postmeta} pm_forf   ON pm_forf.post_id   = p.ID AND pm_forf.meta_key   = '_kophi_forfait'
         LEFT  JOIN {$wpdb->postmeta} pm_statut ON pm_statut.post_id = p.ID AND pm_statut.meta_key = '_kophi_statut'
         WHERE p.post_type = 'kophi_reservation'
           AND p.post_status = 'publish'
           AND pm_date.meta_value BETWEEN %s AND %s
           AND COALESCE(pm_statut.meta_value,'pending') IN ('pending','confirmed')",
        $mois_debut, $mois_fin
    ));

    // ── Indexer par date ─────────────────────────────────────────
    $occ_par_date = [];
    foreach ($bls as $b) {
        for ($ts = strtotime($b->date_debut); $ts <= strtotime($b->date_fin); $ts += 86400) {
            $ds = date('Y-m-d', $ts);
            if ($ds < $mois_debut || $ds > $mois_fin) continue;
            if ($b->journee_complete) {
                $occ_par_date[$ds][] = ['debut'=>0,'fin'=>86399,'type'=>'blocage_jour'];
            } elseif ($b->heure_debut && $b->heure_fin) {
                $occ_par_date[$ds][] = [
                    'debut' => strtotime("$ds {$b->heure_debut}"),
                    'fin'   => strtotime("$ds {$b->heure_fin}"),
                    'type'  => 'blocage',
                ];
            }
        }
    }
    foreach ($sessions_mois as $s) {
        if (!$s->heure || !$s->duree) continue;
        $d = strtotime("{$s->date_s} {$s->heure}");
        $f = $d + (float)$s->duree * 3600;
        $occ_par_date[$s->date_s][] = ['debut'=>$d,'fin'=>$f,'type'=>'session'];
    }
    foreach ($reservs_mois as $r) {
        if (!$r->heure) continue;
        $dur = kophi_get_duree($r->service, $r->forfait);
        $d   = strtotime("{$r->date_r} {$r->heure}");
        $f   = $d + $dur * 3600;
        $occ_par_date[$r->date_r][] = ['debut'=>$d,'fin'=>$f,'type'=>'reservation'];
    }

    // ── Calculer statut de chaque jour ───────────────────────────
    $result = [];
    $sec    = (int)round($duree * 3600);
    $step   = $duree <= 2 ? 1800 : 3600;

    for ($d = 1; $d <= $days; $d++) {
        $ds  = sprintf('%04d-%02d-%02d', $year, $month, $d);
        if ($ds <= $hier) { $result[$ds] = 'passe'; continue; }

        $dow = (int)date('w', strtotime($ds));
        $h   = $horaires[$dow] ?? ($def[$dow] ?? $def[0]);
        if (!$h['actif']) { $result[$ds] = 'ferme'; continue; }

        $occ = $occ_par_date[$ds] ?? [];

        // Vérifier blocage journée complète
        $jour_bloque = false;
        foreach ($occ as $o) { if ($o['type'] === 'blocage_jour') { $jour_bloque = true; break; } }
        if ($jour_bloque) { $result[$ds] = 'bloque'; continue; }

        $ouv  = strtotime("$ds {$h['ouv']}");
        $ferm = strtotime("$ds {$h['ferm']}");
        $total = 0; $libres = 0;

        for ($t = $ouv; ($t + $sec) <= $ferm; $t += $step) {
            $total++;
            $conflict = false;
            foreach ($occ as $o) {
                if ($o['type'] === 'blocage_jour') { $conflict = true; break; }
                if ($t < $o['fin'] && ($t + $sec) > $o['debut']) { $conflict = true; break; }
            }
            if (!$conflict) $libres++;
        }

        $result[$ds] = $total === 0 ? 'ferme'
                     : ($libres === 0 ? 'bloque'
                     : ($libres < $total ? 'partiel' : 'libre'));
    }

    return rest_ensure_response($result);
}


// ================================================================
// 9. FONCTIONS UTILITAIRES DISPONIBILITÉS
// ================================================================
function kophi_get_duree(string $service, string $forfait): float {
    $map = [
        'voix_50000'=>4,'voix_65000'=>6,'voix_90000'=>8,'voix_150000'=>12,
        'faceB_10000'=>1.5,'beats_'=>4,'consultation_'=>1,'achat_beat_'=>0.5,
    ];
    $key = $service.'_'.$forfait;
    if (isset($map[$key])) return $map[$key];
    $base = $service.'_';
    if (isset($map[$base])) return $map[$base];
    $def = ['beats'=>4,'faceB'=>1.5,'consultation'=>1,'mastering'=>2,'mixmaster'=>4];
    return $def[$service] ?? 4;
}

function kophi_get_horaires(int $dow): array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT actif, heure_ouverture, heure_fermeture FROM {$wpdb->prefix}kophi_creneaux WHERE jour_semaine = %d LIMIT 1",
        $dow
    ));
    if ($row) return ['actif'=>(bool)$row->actif,'ouv'=>substr($row->heure_ouverture,0,5),'ferm'=>substr($row->heure_fermeture,0,5)];
    $def = [0=>['actif'=>false,'ouv'=>'00:00','ferm'=>'00:00'],1=>['actif'=>true,'ouv'=>'09:00','ferm'=>'22:00'],2=>['actif'=>true,'ouv'=>'09:00','ferm'=>'22:00'],3=>['actif'=>true,'ouv'=>'09:00','ferm'=>'22:00'],4=>['actif'=>true,'ouv'=>'09:00','ferm'=>'22:00'],5=>['actif'=>true,'ouv'=>'09:00','ferm'=>'22:00'],6=>['actif'=>true,'ouv'=>'10:00','ferm'=>'20:00']];
    return $def[$dow] ?? $def[0];
}

function kophi_get_occupations(string $date): array {
    global $wpdb;
    $occ = [];
    $prefix = $wpdb->prefix;

    // Blocages (avec protection si table manquante)
    $bl = [];
    $tbl_blocages = $prefix . 'kophi_blocages';
    if ($wpdb->get_var("SHOW TABLES LIKE '$tbl_blocages'") === $tbl_blocages) {
        $bl = $wpdb->get_results($wpdb->prepare(
            "SELECT titre,heure_debut,heure_fin,journee_complete FROM {$tbl_blocages} WHERE date_debut <= %s AND date_fin >= %s",
            $date, $date
        )) ?: [];
    }
    foreach ($bl as $b) {
        if ($b->journee_complete) {
            $occ[] = ['debut'=>strtotime("$date 00:00:00"),'fin'=>strtotime("$date 23:59:59"),'label'=>$b->titre,'type'=>'blocage_jour'];
        } elseif ($b->heure_debut && $b->heure_fin) {
            $d=strtotime("$date $b->heure_debut"); $f=strtotime("$date $b->heure_fin");
            if ($f>$d) $occ[]=['debut'=>$d,'fin'=>$f,'label'=>$b->titre,'type'=>'blocage'];
        }
    }

    // Sessions CPT (post_type = kophi_session)
    // Sessions actives — scheduled et live bloquent le créneau
    // done, cancelled ne bloquent plus (créneau libéré)
    $args = ['post_type'=>'kophi_session','posts_per_page'=>-1,'meta_query'=>[
        ['key'=>'_kophi_date_session','value'=>$date,'compare'=>'='],
        ['key'=>'_kophi_statut','value'=>['scheduled','live'],'compare'=>'IN'],
    ]];
    $sessions = get_posts($args);
    foreach ($sessions as $s) {
        $heure  = get_post_meta($s->ID,'_kophi_heure_debut',true);
        $duree  = (float)get_post_meta($s->ID,'_kophi_duree_prevue',true);
        $statut = get_post_meta($s->ID,'_kophi_statut',true);
        if (!$heure || !$duree) continue;
        $d=strtotime("$date $heure"); $f=$d+($duree*3600);
        $occ[]=['debut'=>$d,'fin'=>$f,'label'=>$s->post_title,'type'=>'session_'.$statut];
    }

    // Réservations actives — pending ET confirmed bloquent le créneau (cancelled libère)
    $reservs = get_posts(['post_type'=>'kophi_reservation','posts_per_page'=>-1,'meta_query'=>[
        ['key'=>'_kophi_date_souhaitee','value'=>$date,'compare'=>'='],
        ['key'=>'_kophi_statut','value'=>['pending','confirmed'],'compare'=>'IN'],
        ['key'=>'_kophi_heure_souhaitee','compare'=>'EXISTS'],
    ]]);
    foreach ($reservs as $r) {
        $heure   = get_post_meta($r->ID,'_kophi_heure_souhaitee',true);
        $service = get_post_meta($r->ID,'_kophi_service',true);
        $forfait = get_post_meta($r->ID,'_kophi_forfait',true);
        if (!$heure) continue;
        $dur=kophi_get_duree($service,$forfait);
        $d=strtotime("$date $heure"); $f=$d+($dur*3600);
        $occ[]=['debut'=>$d,'fin'=>$f,'label'=>$r->post_title,'type'=>'reservation'];
    }

    return $occ;
}

function kophi_check_conflicts(array $occ, int $hD, int $hF): array {
    $c=[];
    foreach ($occ as $o) {
        if ($hD < $o['fin'] && $hF > $o['debut']) {
            $msg = match($o['type']) {
                'blocage_jour' => 'Studio indisponible toute la journée — '.$o['label'],
                'blocage'      => 'Réservé en interne '.date('H:i',$o['debut']).' – '.date('H:i',$o['fin']),
                default        => 'Créneau déjà réservé '.date('H:i',$o['debut']).' – '.date('H:i',$o['fin']),
            };
            $c[]=['type'=>$o['type'],'message'=>$msg,'detail'=>$o['label']];
        }
    }
    return $c;
}

function kophi_gen_creneaux(string $date, float $duree): array {
    // Date invalide : aucune disponibilité (auparavant strtotime(false) → 1er janvier 1970)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        return ['ouvert'=>false,'raison'=>'Date invalide','creneaux'=>[]];
    }
    $dow = (int)date('w',strtotime($date));
    $h   = kophi_get_horaires($dow);
    if (!$h['actif']) return ['ouvert'=>false,'raison'=>'Studio fermé ce jour','creneaux'=>[]];
    // « Maintenant » à l'heure du site : les créneaux déjà passés (ou qui commencent
    // dans moins d'une heure) ne sont plus proposés pour aujourd'hui.
    $now_local = (int) current_time('timestamp');

    $ouv  = strtotime("$date ".$h['ouv'].':00');
    $ferm = strtotime("$date ".$h['ferm'].':00');
    $sec  = (int)round($duree*3600);
    $step = $duree<=2 ? 1800 : 3600;
    $occ  = kophi_get_occupations($date);
    $cren = [];

    for ($t=$ouv; ($t+$sec)<=$ferm; $t+=$step) {
        $c = kophi_check_conflicts($occ,$t,$t+$sec);
        if ($t < $now_local + HOUR_IN_SECONDS) {
            $c[] = ['type'=>'passe','message'=>'Créneau déjà passé ou trop proche','detail'=>''];
        }
        $cren[]=['heure'=>date('H:i',$t),'heure_fin'=>date('H:i',$t+$sec),'disponible'=>empty($c),'conflicts'=>$c];
    }
    return ['ouvert'=>true,'ouverture'=>$h['ouv'],'fermeture'=>$h['ferm'],'creneaux'=>$cren];
}

// ================================================================
// 10. API — RÉSERVATIONS
// ================================================================
function kophi_api_create_reservation(WP_REST_Request $req) {
    $nom     = sanitize_text_field($req->get_param('nom_artiste'));
    $tel     = sanitize_text_field($req->get_param('telephone'));
    $date    = sanitize_text_field($req->get_param('date_souhaitee'));
    $heure   = sanitize_text_field($req->get_param('heure_souhaitee') ?: '');
    $service = sanitize_text_field($req->get_param('service') ?: 'voix');
    $forfait = sanitize_text_field($req->get_param('forfait') ?: '');
    $email   = sanitize_email($req->get_param('email') ?: '');
    $message = sanitize_textarea_field($req->get_param('message') ?: '');

    if (!$nom || !$tel || !$date) return new WP_Error('missing','Champs obligatoires manquants',['status'=>422]);

    // Anti-abus : cet endpoint public crée un contenu et déclenche e-mail + WhatsApp
    if (function_exists('kophi_rate_limit_exceeded') && kophi_rate_limit_exceeded('reservation', 6, 10 * MINUTE_IN_SECONDS)) {
        return kophi_rate_limit_error();
    }
    // Validation des formats (auparavant n'importe quelle chaîne était enregistrée)
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        return new WP_Error('bad_date','Date invalide',['status'=>422]);
    }
    if ($date < current_time('Y-m-d')) {
        return new WP_Error('past_date','Cette date est déjà passée',['status'=>422]);
    }
    if ($heure && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure)) {
        return new WP_Error('bad_time','Heure invalide',['status'=>422]);
    }
    if (strlen(preg_replace('/\D/', '', $tel)) < 8) {
        return new WP_Error('bad_phone','Numéro de téléphone invalide',['status'=>422]);
    }
    $nom     = mb_substr($nom, 0, 120);
    $message = mb_substr($message, 0, 2000);

    // Vérification conflits si heure fournie
    if ($heure) {
        $duree = kophi_get_duree($service, $forfait);
        $hD    = strtotime("$date $heure");
        if ($hD < (int) current_time('timestamp')) {
            return new WP_Error('past_slot','Ce créneau est déjà passé',['status'=>422]);
        }
        $hF    = $hD + (int)round($duree * 3600);
        $occ   = kophi_get_occupations($date);
        $conf  = kophi_check_conflicts($occ, $hD, $hF);
        if (!empty($conf)) {
            return new WP_Error('conflict', $conf[0]['message'], ['status'=>409]);
        }
    }

    // Créer le CPT kophi_reservation
    $post_id = wp_insert_post([
        'post_type'   => 'kophi_reservation',
        'post_title'  => sanitize_text_field($nom) . ' — ' . $service . ' — ' . $date,
        'post_status' => 'publish',
    ]);

    if (is_wp_error($post_id)) return new WP_Error('insert_failed','Erreur création',['status'=>500]);

    // Meta fields (compatibles ACF)
    $metas = [
        '_kophi_nom_artiste'     => $nom,
        '_kophi_telephone'       => $tel,
        '_kophi_email'           => $email,
        '_kophi_service'         => $service,
        '_kophi_forfait'         => $forfait,
        '_kophi_date_souhaitee'  => $date,
        '_kophi_heure_souhaitee' => $heure,
        '_kophi_message'         => $message,
        '_kophi_statut'          => 'pending',
    ];
    foreach ($metas as $k => $v) update_post_meta($post_id, $k, $v);

    // Notification complète (email + WhatsApp)
    kophi_notify_all([
        'type'    => 'reservation',
        'nom'     => $nom,
        'tel'     => $tel,
        'email'   => $email,
        'service' => $service,
        'date'    => $date,
        'heure'   => $heure,
        'forfait' => $forfait,
        'notes'   => $message,
        'source'  => 'site',
        'statut'  => 'pending',
        'post_id' => $post_id,
    ]);

    return rest_ensure_response(['success'=>true,'id'=>$post_id]);
}

function kophi_api_list_reservations(WP_REST_Request $req) {
    $statut = sanitize_text_field($req->get_param('statut') ?: '');
    $args   = ['post_type'=>'kophi_reservation','posts_per_page'=>-1,'orderby'=>'date','order'=>'DESC'];
    if ($statut) $args['meta_query'] = [['key'=>'_kophi_statut','value'=>$statut,'compare'=>'=']];
    $posts = get_posts($args);
    $data  = [];
    foreach ($posts as $p) {
        $d = get_post_meta($p->ID);
        $data[] = [
            'id'             => $p->ID,
            'nom_artiste'    => get_post_meta($p->ID,'_kophi_nom_artiste',true),
            'telephone'      => get_post_meta($p->ID,'_kophi_telephone',true),
            'email'          => get_post_meta($p->ID,'_kophi_email',true),
            'service'        => get_post_meta($p->ID,'_kophi_service',true),
            'forfait'        => get_post_meta($p->ID,'_kophi_forfait',true),
            'date_souhaitee' => get_post_meta($p->ID,'_kophi_date_souhaitee',true),
            'heure_souhaitee'=> get_post_meta($p->ID,'_kophi_heure_souhaitee',true),
            'message'        => get_post_meta($p->ID,'_kophi_message',true),
            'statut'         => get_post_meta($p->ID,'_kophi_statut',true),
            'created'        => $p->post_date,
        ];
    }
    return rest_ensure_response($data);
}

function kophi_api_update_reservation(WP_REST_Request $req) {
    $id     = (int)$req->get_param('id');
    $statut = sanitize_text_field($req->get_param('statut'));

    update_post_meta($id, '_kophi_statut', $statut);

    // Si confirmé → créer session CPT automatiquement
    if ($statut === 'confirmed') {
        $service = get_post_meta($id,'_kophi_service',true);
        if ($service !== 'consultation') {
            $nom     = get_post_meta($id,'_kophi_nom_artiste',true);
            $date    = get_post_meta($id,'_kophi_date_souhaitee',true);
            $heure   = get_post_meta($id,'_kophi_heure_souhaitee',true);
            $forfait = get_post_meta($id,'_kophi_forfait',true);
            $duree   = kophi_get_duree($service, $forfait);

            $sess_id = wp_insert_post([
                'post_type'   => 'kophi_session',
                'post_title'  => $nom . ' — ' . $service . ' — ' . $date,
                'post_status' => 'publish',
            ]);
            if (!is_wp_error($sess_id)) {
                update_post_meta($sess_id,'_kophi_client_nom',$nom);
                update_post_meta($sess_id,'_kophi_type_session',$service);
                update_post_meta($sess_id,'_kophi_date_session',$date);
                update_post_meta($sess_id,'_kophi_heure_debut',$heure);
                update_post_meta($sess_id,'_kophi_duree_prevue',$duree);
                update_post_meta($sess_id,'_kophi_montant',$forfait);
                update_post_meta($sess_id,'_kophi_statut','scheduled');
                update_post_meta($sess_id,'_kophi_notes','Via réservation en ligne. Tél : '.get_post_meta($id,'_kophi_telephone',true));
            }
        }
    }

    return rest_ensure_response(['success'=>true]);
}


// ================================================================
// 8b. API ADMIN — RÉSERVATION PAR TÉLÉPHONE (équipe studio)
// ================================================================
function kophi_api_admin_reservation(WP_REST_Request $req) {
    $nom     = sanitize_text_field($req->get_param('nom_artiste') ?: '');
    $tel     = sanitize_text_field($req->get_param('telephone') ?: '');
    $service = sanitize_text_field($req->get_param('service') ?: 'voix');
    $forfait = sanitize_text_field($req->get_param('forfait') ?: '50000');
    $date    = sanitize_text_field($req->get_param('date_souhaitee') ?: '');
    $heure   = sanitize_text_field($req->get_param('heure_souhaitee') ?: '');
    $notes   = sanitize_textarea_field($req->get_param('notes') ?: '');
    $statut  = in_array($req->get_param('statut'), ['pending','confirmed']) ? $req->get_param('statut') : 'confirmed';
    $source  = 'telephone'; // Origine : prise de RDV par téléphone

    if (!$nom || !$date) {
        return new WP_Error('missing', 'Nom et date obligatoires', ['status'=>400]);
    }

    // Vérifier les conflits si heure fournie
    if ($heure) {
        $occ   = kophi_get_occupations($date);
        $duree = kophi_get_duree($service, $forfait);
        $debut = strtotime("$date $heure");
        $fin   = $debut + (int)($duree * 3600);
        $conflicts = kophi_check_conflicts($occ, $debut, $fin);
        if (!empty($conflicts) && $statut === 'confirmed') {
            return new WP_Error('conflict', 'Créneau déjà occupé : ' . $conflicts[0]['message'], ['status'=>409]);
        }
    }

    // Créer la réservation CPT
    $id = wp_insert_post([
        'post_type'   => 'kophi_reservation',
        'post_title'  => "$nom — $service — $date",
        'post_status' => 'publish',
    ]);
    if (is_wp_error($id)) {
        return new WP_Error('insert_fail', 'Erreur création', ['status'=>500]);
    }

    update_post_meta($id, '_kophi_nom_artiste',    $nom);
    update_post_meta($id, '_kophi_telephone',       $tel);
    update_post_meta($id, '_kophi_service',         $service);
    update_post_meta($id, '_kophi_forfait',         $forfait);
    update_post_meta($id, '_kophi_date_souhaitee',  $date);
    update_post_meta($id, '_kophi_heure_souhaitee', $heure);
    update_post_meta($id, '_kophi_statut',          $statut);
    update_post_meta($id, '_kophi_source',          $source);
    update_post_meta($id, '_kophi_notes',           $notes);
    update_post_meta($id, '_kophi_created_by',      get_current_user_id());

    // Si confirmée, créer aussi la session directement
    if ($statut === 'confirmed' && $heure) {
        $duree = kophi_get_duree($service, $forfait);
        $sess  = wp_insert_post([
            'post_type'   => 'kophi_session',
            'post_title'  => "$nom — $service — $date",
            'post_status' => 'publish',
        ]);
        if (!is_wp_error($sess)) {
            update_post_meta($sess, '_kophi_client_nom',   $nom);
            update_post_meta($sess, '_kophi_telephone',     $tel);
            update_post_meta($sess, '_kophi_type_session',  $service);
            update_post_meta($sess, '_kophi_date_session',  $date);
            update_post_meta($sess, '_kophi_heure_debut',   $heure);
            update_post_meta($sess, '_kophi_duree_prevue',  $duree);
            update_post_meta($sess, '_kophi_montant',       $forfait);
            update_post_meta($sess, '_kophi_statut',        'scheduled');
            update_post_meta($sess, '_kophi_notes',         "Source : réservation téléphone. $notes");
            update_post_meta($sess, '_kophi_reservation_id', $id);
        }
    }

    return rest_ensure_response(['success'=>true, 'id'=>$id, 'statut'=>$statut]);
}

// ================================================================
// 8c. API ADMIN — SESSION INTERNE LABEL (compte comme réservation)
// ================================================================
function kophi_api_session_interne(WP_REST_Request $req) {
    $titre   = sanitize_text_field($req->get_param('titre') ?: 'Session Label');
    $artiste = sanitize_text_field($req->get_param('artiste') ?: 'Label — Interne');
    $date    = sanitize_text_field($req->get_param('date') ?: '');
    $heure   = sanitize_text_field($req->get_param('heure') ?: '');
    $duree   = (float)($req->get_param('duree') ?: 4);
    $projet  = sanitize_text_field($req->get_param('projet') ?: '');
    $notes   = sanitize_textarea_field($req->get_param('notes') ?: '');
    $daw     = sanitize_text_field($req->get_param('daw') ?: '');

    if (!$date || !$heure) {
        return new WP_Error('missing', 'Date et heure obligatoires', ['status'=>400]);
    }

    // Vérifier les conflits — les sessions internes occupent le créneau
    $occ      = kophi_get_occupations($date);
    $debut    = strtotime("$date $heure");
    $fin      = $debut + (int)($duree * 3600);
    $conflicts = kophi_check_conflicts($occ, $debut, $fin);
    if (!empty($conflicts)) {
        return new WP_Error('conflict', 'Créneau occupé : ' . $conflicts[0]['message'], ['status'=>409]);
    }

    // Créer la réservation interne (bloque le créneau pour les clients)
    $res_id = wp_insert_post([
        'post_type'   => 'kophi_reservation',
        'post_title'  => "INTERNE : $titre — $artiste — $date",
        'post_status' => 'publish',
    ]);
    if (!is_wp_error($res_id)) {
        update_post_meta($res_id, '_kophi_nom_artiste',    $artiste);
        update_post_meta($res_id, '_kophi_service',        'interne');
        update_post_meta($res_id, '_kophi_date_souhaitee', $date);
        update_post_meta($res_id, '_kophi_heure_souhaitee',$heure);
        update_post_meta($res_id, '_kophi_statut',         'confirmed');
        update_post_meta($res_id, '_kophi_source',         'interne_label');
        update_post_meta($res_id, '_kophi_notes',          $notes);
    }

    // Créer la session CPT
    $sess_id = wp_insert_post([
        'post_type'   => 'kophi_session',
        'post_title'  => "$titre — $artiste — $date",
        'post_status' => 'publish',
    ]);
    if (is_wp_error($sess_id)) {
        return new WP_Error('session_fail', 'Erreur création session', ['status'=>500]);
    }

    update_post_meta($sess_id, '_kophi_client_nom',     $artiste);
    update_post_meta($sess_id, '_kophi_type_session',   'interne');
    update_post_meta($sess_id, '_kophi_date_session',   $date);
    update_post_meta($sess_id, '_kophi_heure_debut',    $heure);
    update_post_meta($sess_id, '_kophi_duree_prevue',   $duree);
    update_post_meta($sess_id, '_kophi_statut',         'interne');
    update_post_meta($sess_id, '_kophi_notes',          $notes);
    update_post_meta($sess_id, '_kophi_reservation_id', $res_id ?: 0);
    if ($projet) update_post_meta($sess_id, '_kophi_daw_projet', $projet);
    if ($daw)    update_post_meta($sess_id, '_kophi_daw_app',    $daw);

    return rest_ensure_response([
        'success'     => true,
        'session_id'  => $sess_id,
        'reservation_id' => $res_id ?: null,
        'message'     => 'Session interne créée et créneau bloqué',
    ]);
}


// ================================================================
// 11. API — BEATS
// ================================================================
function kophi_api_list_beats(WP_REST_Request $req) {
    $genre = sanitize_text_field($req->get_param('genre') ?: '');
    $args  = ['post_type'=>'kophi_beat','posts_per_page'=>-1,'post_status'=>'publish','orderby'=>'date','order'=>'DESC'];
    if ($genre) $args['tax_query'] = [['taxonomy'=>'kophi_genre','field'=>'name','terms'=>$genre]];
    $posts = get_posts($args);
    $data  = [];
    foreach ($posts as $p) {
        $genres = wp_get_post_terms($p->ID,'kophi_genre',['fields'=>'names']);
        $data[] = [
            'id'          => $p->ID,
            'titre'       => $p->post_title,
            'genre'       => !empty($genres) ? $genres[0] : '',
            'tempo'       => (int)get_post_meta($p->ID,'_kophi_tempo',true),
            'duree_sec'   => (int)get_post_meta($p->ID,'_kophi_duree_sec',true),
            'prix_ne'     => (int)get_post_meta($p->ID,'_kophi_prix_non_exclusive',true),
            'prix_ex'     => (int)get_post_meta($p->ID,'_kophi_prix_exclusive',true),
            'description' => $p->post_excerpt,
            'tags'        => get_post_meta($p->ID,'_kophi_tags',true),
            'prod'        => get_post_meta($p->ID,'_kophi_producteur',true),
            'fichier'     => get_post_meta($p->ID,'_kophi_fichier_audio',true),
            'thumbnail'   => get_the_post_thumbnail_url($p->ID,'medium'),
        ];
    }
    return rest_ensure_response($data);
}

function kophi_api_achat_beat(WP_REST_Request $req) {
    $beat_id = (int)$req->get_param('beat_id');
    $nom     = sanitize_text_field($req->get_param('nom_acheteur'));
    $tel     = sanitize_text_field($req->get_param('telephone'));
    $email   = sanitize_email($req->get_param('email') ?: '');
    $licence = sanitize_text_field($req->get_param('licence') ?: 'non_exclusive');
    $message = sanitize_textarea_field($req->get_param('message') ?: '');

    if (!$beat_id || !$nom || !$tel) return new WP_Error('missing','Données manquantes',['status'=>422]);

    if (function_exists('kophi_rate_limit_exceeded') && kophi_rate_limit_exceeded('achat_beat', 8, 10 * MINUTE_IN_SECONDS)) {
        return kophi_rate_limit_error();
    }
    if (!in_array($licence, ['exclusive', 'non_exclusive'], true)) $licence = 'non_exclusive';

    // Uniquement un beat publié : auparavant n'importe quel ID de contenu (page privée,
    // brouillon, session client…) était accepté et son titre renvoyé dans la réponse.
    $beat  = get_post($beat_id);
    if (!$beat || $beat->post_type !== 'kophi_beat' || $beat->post_status !== 'publish') {
        return new WP_Error('not_found','Beat introuvable',['status'=>404]);
    }
    if ($licence === 'exclusive' && get_post_meta($beat_id, '_kophi_exclusive_vendu', true)) {
        return new WP_Error('sold','La licence exclusive de ce beat est déjà vendue',['status'=>409]);
    }

    $prix_key = $licence === 'exclusive' ? '_kophi_prix_exclusive' : '_kophi_prix_non_exclusive';
    $prix     = (int)get_post_meta($beat_id, $prix_key, true);

    // Créer CPT réservation type achat_beat
    $post_id = wp_insert_post([
        'post_type'   => 'kophi_reservation',
        'post_title'  => 'ACHAT BEAT — ' . $beat->post_title . ' — ' . $nom,
        'post_status' => 'publish',
    ]);

    if (!is_wp_error($post_id)) {
        update_post_meta($post_id,'_kophi_nom_artiste',$nom);
        update_post_meta($post_id,'_kophi_telephone',$tel);
        update_post_meta($post_id,'_kophi_email',$email);
        update_post_meta($post_id,'_kophi_service','achat_beat');
        update_post_meta($post_id,'_kophi_forfait',(string)$prix);
        update_post_meta($post_id,'_kophi_beat_id',$beat_id);
        update_post_meta($post_id,'_kophi_beat_titre',$beat->post_title);
        update_post_meta($post_id,'_kophi_licence_type',$licence);
        update_post_meta($post_id,'_kophi_message',$message);
        update_post_meta($post_id,'_kophi_statut','pending');
        update_post_meta($post_id,'_kophi_date_souhaitee',date('Y-m-d'));
    }

    // Email
    kophi_send_email_notif([
        'type'    => 'achat_beat',
        'nom'     => $nom,
        'tel'     => $tel,
        'service' => 'Achat beat — '.$beat->post_title.' ('.$licence.')',
        'prix'    => $prix,
        'date'    => date('d/m/Y'),
    ]);

    return rest_ensure_response([
        'success' => true,
        'id'      => $post_id ?? 0,
        'wa_link' => 'https://wa.me/' . preg_replace('/[^0-9]/','',get_option('kophi_whatsapp','2250503404389'))
                     . '?text=' . urlencode("Bonjour KOPHI'S MUSIC STUDIO ! Je voudrais acheter le beat «" . $beat->post_title . "» (" . $licence . ") — " . $prix . " F CFA. Nom: " . $nom),
    ]);
}

// ================================================================
// 12. API — BLOCAGES
// ================================================================
function kophi_api_list_blocages(WP_REST_Request $req) {
    global $wpdb;
    $from = sanitize_text_field($req->get_param('from') ?: date('Y-m-01'));
    $to   = sanitize_text_field($req->get_param('to') ?: date('Y-m-t'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}kophi_blocages WHERE date_debut <= %s AND date_fin >= %s ORDER BY date_debut ASC",
        $to, $from
    ));
    return rest_ensure_response($rows);
}

function kophi_api_create_blocage(WP_REST_Request $req) {
    global $wpdb;
    $data = [
        'titre'            => sanitize_text_field($req->get_param('titre')),
        'date_debut'       => sanitize_text_field($req->get_param('date_debut')),
        'date_fin'         => sanitize_text_field($req->get_param('date_fin')),
        'heure_debut'      => sanitize_text_field($req->get_param('heure_debut') ?: ''),
        'heure_fin'        => sanitize_text_field($req->get_param('heure_fin') ?: ''),
        'journee_complete' => (int)$req->get_param('journee_complete'),
        'motif'            => sanitize_text_field($req->get_param('motif') ?: 'autre'),
        'description'      => sanitize_textarea_field($req->get_param('description') ?: ''),
        'created_by'       => wp_get_current_user()->display_name,
    ];
    if (!$data['titre'] || !$data['date_debut'] || !$data['date_fin']) {
        return new WP_Error('missing','Titre et dates obligatoires',['status'=>422]);
    }
    $wpdb->insert($wpdb->prefix.'kophi_blocages', $data);
    return rest_ensure_response(['success'=>true,'id'=>$wpdb->insert_id]);
}

function kophi_api_delete_blocage(WP_REST_Request $req) {
    global $wpdb;
    $id = (int)$req->get_param('id');
    $wpdb->delete($wpdb->prefix.'kophi_blocages',['id'=>$id]);
    return rest_ensure_response(['success'=>true]);
}

// ================================================================
// 13. API — STATS
// ================================================================
function kophi_api_stats(WP_REST_Request $req) {
    $mois   = date('Y-m');
    $debut  = $mois . '-01';
    $fin    = $mois . '-' . date('t');

    $sessions = get_posts(['post_type'=>'kophi_session','posts_per_page'=>-1,'meta_query'=>[
        ['key'=>'_kophi_date_session','value'=>[$debut,$fin],'compare'=>'BETWEEN','type'=>'DATE']
    ]]);

    $revenus = 0; $heures = 0;
    foreach ($sessions as $s) {
        $revenus += (float)get_post_meta($s->ID,'_kophi_montant',true);
        $heures  += (float)get_post_meta($s->ID,'_kophi_duree_prevue',true);
    }

    $nb_clients  = wp_count_posts('kophi_client')->publish ?? 0;
    $nb_projets  = wp_count_posts('kophi_projet');
    $nb_pending  = get_posts(['post_type'=>'kophi_reservation','posts_per_page'=>-1,'meta_query'=>[['key'=>'_kophi_statut','value'=>'pending']]]);

    return rest_ensure_response([
        'revenus_mois'         => $revenus,
        'sessions_mois'        => count($sessions),
        'heures_mois'          => $heures,
        'nb_clients'           => $nb_clients,
        'projets_actifs'       => ($nb_projets->publish ?? 0),
        'reservations_pending' => count($nb_pending),
    ]);
}

function kophi_api_studio_status() {
    $live = get_posts(['post_type'=>'kophi_session','posts_per_page'=>1,'meta_query'=>[
        ['key'=>'_kophi_statut','value'=>'live','compare'=>'=']
    ]]);
    if (!empty($live)) {
        $s = $live[0];
        return rest_ensure_response(['live'=>true,'client'=>get_post_meta($s->ID,'_kophi_client_nom',true),'duree'=>get_post_meta($s->ID,'_kophi_duree_prevue',true)]);
    }
    return rest_ensure_response(['live'=>false]);
}

// ================================================================
// 14. EMAIL NOTIFICATIONS
// ================================================================
function kophi_send_email_notif(array $data): void {
    // Alias vers kophi_notify_all pour rétrocompatibilité
    kophi_notify_all($data);
}


// ================================================================
// PAGE TEMPLATE — Boutique Beats (chargé depuis le plugin)
// ================================================================
add_filter('theme_page_templates', 'kophi_register_page_templates', 10, 3);
function kophi_register_page_templates($templates, $theme, $post) {
    $templates['kophi-beats-template'] = 'Boutique Beats KM STUDIO';
    return $templates;
}

add_filter('template_include', 'kophi_load_page_template', 99);
function kophi_load_page_template($template) {
    if (!is_page()) return $template;
    global $post;
    $tpl = get_post_meta($post->ID, '_wp_page_template', true);
    if ($tpl === 'kophi-beats-template') {
        $plugin_template = KOPHI_DIR . 'templates/page/beats.php';
        if (file_exists($plugin_template)) return $plugin_template;
    }
    return $template;
}

// ================================================================
// 15. SHORTCODES
// ================================================================
add_shortcode('kophi_reservation', 'kophi_shortcode_reservation');
add_shortcode('kophi_beats',       'kophi_shortcode_beats');
add_shortcode('kophi_dashboard',   'kophi_shortcode_dashboard');

function kophi_shortcode_reservation($atts) {
    ob_start();
    include KOPHI_DIR . 'templates/front/reservation.php';
    return ob_get_clean();
}

function kophi_shortcode_beats($atts) {
    // Appliquer les classes body et le CSS fullwidth AVANT ob_start
    add_filter('body_class', function($classes) {
        return array_merge($classes, ['kophi-fullwidth', 'kophi-beats-page']);
    });
    add_action('wp_head', function() {
        echo '<style id="beats-page-override">
        .site-header,#masthead,.ast-above-header-wrap,.ast-below-header-wrap,.main-header-bar{display:none!important}
        .site-footer,footer#colophon{display:none!important}
        body.kophi-beats-page .site-content,
        body.kophi-beats-page #content,
        body.kophi-beats-page #primary,
        body.kophi-beats-page .entry-content,
        body.kophi-beats-page .ast-container,
        body.kophi-beats-page article.page,
        body.kophi-beats-page main.site-main{max-width:100%!important;width:100%!important;padding:0!important;margin:0!important;background:transparent!important}
        body.kophi-beats-page .entry-header,
        body.kophi-beats-page .ast-breadcrumbs-wrap{display:none!important}
        body.kophi-beats-page{background:#0D0D0D!important}
        </style>';
    }, 1);
    wp_enqueue_style('kophi-player'); wp_enqueue_script('kophi-player');
    ob_start();
    include KOPHI_DIR . 'templates/front/beats-store.php';
    return ob_get_clean();
}

function kophi_shortcode_dashboard($atts) {
    if (!current_user_can('read') || !current_user_can('kophi_manage_sessions')) {
        return '<p>Accès réservé à l\'équipe KOPHI\'S MUSIC STUDIO. <a href="' . wp_login_url(get_permalink()) . '">Se connecter</a></p>';
    }
    ob_start();
    include KOPHI_DIR . 'templates/front/dashboard.php';
    return ob_get_clean();
}

// ================================================================
// 16. PAGES ADMIN (render)
// ================================================================
function kophi_page_dashboard()  { include KOPHI_DIR . 'templates/admin/dashboard.php'; }
function kophi_page_sessions()   { include KOPHI_DIR . 'templates/admin/sessions.php'; }
function kophi_page_clients()    { include KOPHI_DIR . 'templates/admin/clients.php'; }
function kophi_page_projets()    { include KOPHI_DIR . 'templates/admin/projets.php'; }
function kophi_page_planning()   { include KOPHI_DIR . 'templates/admin/planning.php'; }
function kophi_page_reservations(){ include KOPHI_DIR . 'templates/admin/reservations.php'; }
function kophi_page_stats()       { include KOPHI_DIR . 'templates/admin/statistiques.php'; }
function kophi_page_beats()      { include KOPHI_DIR . 'templates/admin/beats.php'; }
function kophi_page_tarifs()     { include KOPHI_DIR . 'templates/admin/tarifs.php'; }
function kophi_page_settings()   { include KOPHI_DIR . 'templates/admin/settings.php'; }

// ================================================================
// 17. AJAX HANDLERS (fallback si REST non dispo)
// ================================================================
add_action('wp_ajax_kophi_get_creneaux',       'kophi_ajax_creneaux');
add_action('wp_ajax_nopriv_kophi_get_creneaux','kophi_ajax_creneaux');
function kophi_ajax_creneaux() {
    check_ajax_referer('kophi_front_nonce','nonce');
    $date    = sanitize_text_field($_POST['date'] ?? '');
    $service = sanitize_text_field($_POST['service'] ?? 'voix');
    $forfait = sanitize_text_field($_POST['forfait'] ?? '50000');
    $duree   = kophi_get_duree($service,$forfait);
    wp_send_json_success(kophi_gen_creneaux($date,$duree));
}

add_action('wp_ajax_kophi_save_session',       'kophi_ajax_save_session');
function kophi_ajax_save_session() {
    check_ajax_referer('kophi_nonce','nonce');
    if (!current_user_can('kophi_manage_sessions')) wp_send_json_error('Permission refusée');

    $post_id = (int)($_POST['id'] ?? 0);
    $data    = [
        '_kophi_client_nom'    => sanitize_text_field($_POST['client_nom'] ?? ''),
        '_kophi_type_session'  => sanitize_text_field($_POST['type_session'] ?? ''),
        '_kophi_date_session'  => sanitize_text_field($_POST['date_session'] ?? ''),
        '_kophi_heure_debut'   => sanitize_text_field($_POST['heure_debut'] ?? ''),
        '_kophi_duree_prevue'  => (float)($_POST['duree_prevue'] ?? 4),
        '_kophi_montant'       => (float)($_POST['montant'] ?? 0),
        '_kophi_statut'        => sanitize_text_field($_POST['statut'] ?? 'scheduled'),
        '_kophi_notes'         => sanitize_textarea_field($_POST['notes'] ?? ''),
    ];

    if (!$post_id) {
        $post_id = wp_insert_post(['post_type'=>'kophi_session','post_title'=>$data['_kophi_client_nom'].' — '.$data['_kophi_date_session'],'post_status'=>'publish']);
    }
    if (is_wp_error($post_id)) wp_send_json_error('Erreur création');
    foreach ($data as $k=>$v) update_post_meta($post_id,$k,$v);
    wp_send_json_success(['id'=>$post_id]);
}

// ================================================================
// 18. WIDGET TABLEAU DE BORD WP
// ================================================================
add_action('wp_dashboard_setup', 'kophi_dashboard_widget');
function kophi_dashboard_widget() {
    if (!current_user_can('kophi_manage_sessions')) return;
    wp_add_dashboard_widget('kophi_studio_widget','🎙 KOPHI Studio — Résumé du jour','kophi_render_wp_widget');
}
function kophi_render_wp_widget() {
    $today    = date('Y-m-d');
    $sessions = get_posts(['post_type'=>'kophi_session','posts_per_page'=>-1,'meta_query'=>[['key'=>'_kophi_date_session','value'=>$today]]]);
    $pending  = get_posts(['post_type'=>'kophi_reservation','posts_per_page'=>-1,'meta_query'=>[['key'=>'_kophi_statut','value'=>'pending']]]);
    echo '<p><strong>Sessions aujourd\'hui :</strong> ' . count($sessions) . '</p>';
    echo '<p><strong>Réservations en attente :</strong> ' . count($pending) . '</p>';
    echo '<p><a href="' . admin_url('admin.php?page=kophi-dashboard') . '" style="background:#E8B84B;color:#000;padding:6px 14px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:12px">Ouvrir le studio →</a></p>';
}

// ================================================================
// 19. MULTISITE — CRÉER TABLES SUR CHAQUE SOUS-SITE
// ================================================================
add_action('wpmu_new_blog', 'kophi_new_blog', 10, 6);
function kophi_new_blog($blog_id) {
    switch_to_blog($blog_id);
    kophi_create_tables();
    restore_current_blog();
}

// ================================================================
// HANDLERS ADMIN-POST — Réservations (confirm / cancel / reassign)
// ================================================================
add_action('admin_post_kophi_confirm_res', function() {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id || !check_admin_referer('kophi_confirm_res_' . $id)) wp_die('Accès refusé');
    update_post_meta($id, '_kophi_statut', 'confirmed');

    // Créer la session si pas encore créée
    $existing_sess = get_post_meta($id, '_kophi_session_id', true);
    if (!$existing_sess) {
        $nom     = get_post_meta($id, '_kophi_nom_artiste', true);
        $service = get_post_meta($id, '_kophi_service', true);
        $date    = get_post_meta($id, '_kophi_date_souhaitee', true);
        $heure   = get_post_meta($id, '_kophi_heure_souhaitee', true);
        $forfait = get_post_meta($id, '_kophi_forfait', true);
        $duree   = kophi_get_duree($service, $forfait);
        $sess_id = wp_insert_post([
            'post_type'   => 'kophi_session',
            'post_title'  => "$nom — $service — $date",
            'post_status' => 'publish',
        ]);
        if (!is_wp_error($sess_id)) {
            update_post_meta($sess_id, '_kophi_client_nom',     $nom);
            update_post_meta($sess_id, '_kophi_type_session',   $service);
            update_post_meta($sess_id, '_kophi_date_session',   $date);
            update_post_meta($sess_id, '_kophi_heure_debut',    $heure);
            update_post_meta($sess_id, '_kophi_duree_prevue',   $duree);
            update_post_meta($sess_id, '_kophi_montant',        $forfait);
            update_post_meta($sess_id, '_kophi_statut',         'scheduled');
            update_post_meta($sess_id, '_kophi_telephone',      get_post_meta($id, '_kophi_telephone', true));
            update_post_meta($id,      '_kophi_session_id',     $sess_id);
        }
    }

    // Notifier par email
    $nom = get_post_meta($id, '_kophi_nom_artiste', true);
    $tel = get_post_meta($id, '_kophi_telephone', true);
    $date = get_post_meta($id, '_kophi_date_souhaitee', true);
    $service = get_post_meta($id, '_kophi_service', true);
    kophi_notify_all([
        'type'    => 'confirmation',
        'nom'     => $nom,
        'tel'     => $tel,
        'email'   => get_post_meta($id, '_kophi_email', true),
        'service' => $service,
        'date'    => $date,
        'heure'   => get_post_meta($id, '_kophi_heure_souhaitee', true),
        'forfait' => get_post_meta($id, '_kophi_forfait', true),
        'notes'   => get_post_meta($id, '_kophi_notes', true),
        'source'  => 'confirmation_admin',
        'statut'  => 'confirmed',
        'post_id' => $id,
    ]);

    // Notification confirmation
    kophi_notify_all([
        'type'    => 'confirmation',
        'nom'     => $nom,
        'tel'     => $tel,
        'service' => $service,
        'date'    => $date,
        'heure'   => get_post_meta($id, '_kophi_heure_souhaitee', true),
        'forfait' => get_post_meta($id, '_kophi_forfait', true),
        'source'  => 'confirmation_admin',
        'statut'  => 'confirmed',
        'post_id' => $id,
    ]);
    wp_redirect(admin_url('admin.php?page=kophi-reservations&kophi_msg=confirmed'));
    exit;
});


// ================================================================
// ANNULATION — Fonction centrale avec cascade + notification
// ================================================================
function kophi_do_cancel(string $type, int $id): array {
    if (!$id) return ['success' => false, 'message' => 'ID invalide'];

    $nom    = '';
    $tel    = '';
    $email  = '';
    $date   = '';
    $heure  = '';
    $service= '';
    $forfait= '';

    if ($type === 'reservation') {
        $old_statut = get_post_meta($id, '_kophi_statut', true);
        if ($old_statut === 'cancelled') return ['success' => false, 'message' => 'Déjà annulée'];

        // Récupérer les infos AVANT annulation
        $nom     = get_post_meta($id, '_kophi_nom_artiste',    true);
        $tel     = get_post_meta($id, '_kophi_telephone',      true);
        $email   = get_post_meta($id, '_kophi_email',          true);
        $date    = get_post_meta($id, '_kophi_date_souhaitee', true);
        $heure   = get_post_meta($id, '_kophi_heure_souhaitee',true);
        $service = get_post_meta($id, '_kophi_service',        true);
        $forfait = get_post_meta($id, '_kophi_forfait',        true);

        // Annuler la réservation
        update_post_meta($id, '_kophi_statut', 'cancelled');

        // Cascade → annuler la session liée
        $sess_id = (int)get_post_meta($id, '_kophi_session_id', true);
        if ($sess_id) {
            $st = get_post_meta($sess_id, '_kophi_statut', true);
            if (in_array($st, ['scheduled', 'live', 'interne'])) {
                update_post_meta($sess_id, '_kophi_statut', 'cancelled');
            }
        }

    } elseif ($type === 'session') {
        $old_statut = get_post_meta($id, '_kophi_statut', true);
        if ($old_statut === 'cancelled') return ['success' => false, 'message' => 'Déjà annulée'];

        // Récupérer les infos AVANT annulation
        $nom     = get_post_meta($id, '_kophi_client_nom',   true) ?: get_the_title($id);
        $tel     = get_post_meta($id, '_kophi_telephone',    true);
        $date    = get_post_meta($id, '_kophi_date_session', true);
        $heure   = get_post_meta($id, '_kophi_heure_debut',  true);
        $service = get_post_meta($id, '_kophi_type_session', true);

        // Annuler la session
        update_post_meta($id, '_kophi_statut', 'cancelled');

        // Cascade → annuler la réservation liée
        $res_id = (int)get_post_meta($id, '_kophi_reservation_id', true);
        if ($res_id) {
            $st = get_post_meta($res_id, '_kophi_statut', true);
            if (in_array($st, ['pending', 'confirmed'])) {
                update_post_meta($res_id, '_kophi_statut', 'cancelled');
            }
        }

        // Si session interne → supprimer le blocage créneau lié
        if ($service === 'interne' && $date && $heure) {
            global $wpdb;
            $tbl = $wpdb->prefix . 'kophi_blocages';
            if ($wpdb->get_var("SHOW TABLES LIKE '$tbl'") === $tbl) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $tbl WHERE date_debut = %s AND heure_debut = %s",
                    $date, $heure
                ));
            }
        }

    } elseif ($type === 'blocage') {
        // Supprimer le blocage de la table
        global $wpdb;
        $tbl = $wpdb->prefix . 'kophi_blocages';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tbl WHERE id = %d", $id));
        if (!$row) return ['success' => false, 'message' => 'Blocage introuvable'];
        $nom  = $row->titre;
        $date = $row->date_debut;
        $wpdb->delete($tbl, ['id' => $id]);
    }

    // Notification annulation (email + WhatsApp)
    kophi_notify_all([
        'type'    => 'annulation',
        'nom'     => $nom     ?: 'N/A',
        'tel'     => $tel     ?: '',
        'email'   => $email   ?: '',
        'service' => $service ?: '',
        'date'    => $date    ?: '',
        'heure'   => $heure   ?: '',
        'forfait' => $forfait ?: '',
        'notes'   => "Annulation de : " . ($type === 'blocage' ? 'Blocage' : ucfirst($type)),
        'source'  => 'annulation_admin',
        'statut'  => 'cancelled',
        'post_id' => $id,
    ]);

    return ['success' => true, 'cancelled' => true, 'message' => 'Annulé avec succès'];
}

add_action('admin_post_kophi_cancel_res', function() {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id || !check_admin_referer('kophi_cancel_res_' . $id)) wp_die('Accès refusé');
    // Annulation + cascade + notification
    kophi_do_cancel('reservation', $id);
    wp_redirect(admin_url('admin.php?page=kophi-reservations&kophi_msg=cancelled'));
    exit;
});

// Hook email - forcer l'expéditeur KOPHI
add_filter('wp_mail_from',      function() { return KOPHI_EMAIL; }); // contact@kophismusic.com
add_filter('wp_mail_from_name', function() { return "KOPHI'S MUSIC STUDIO"; });

// ================================================================
// ROUTE DAW TRACKER — réajoutée (perdue lors refactorisation)
// ================================================================
add_action('rest_api_init', function() {
    register_rest_route('kophi/v1', '/daw-tracker', [
        'methods'             => 'POST',
        'callback'            => 'kophi_api_daw_tracker',
        'permission_callback' => 'kophi_check_daw_key',
    ]);
});

// ================================================================
// HANDLERS — Session LIVE / DONE (manquants → page blanche)
// ================================================================
add_action('admin_post_kophi_set_live', function() {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id || !check_admin_referer('kophi_set_live_' . $id)) wp_die('Accès refusé');
    update_post_meta($id, '_kophi_statut', 'live');
    update_post_meta($id, '_kophi_live_start_ts', time());
    wp_redirect(admin_url('admin.php?page=kophi-sessions&kophi_msg=live'));
    exit;
});

add_action('admin_post_kophi_set_done', function() {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id || !check_admin_referer('kophi_set_done_' . $id)) wp_die('Accès refusé');
    $start = (int)get_post_meta($id, '_kophi_live_start_ts', true);
    if ($start) {
        $duree_min  = round((time() - $start) / 60);
        $duree_h    = round($duree_min / 60, 1);
        update_post_meta($id, '_kophi_duree_reelle', $duree_h);
    }
    update_post_meta($id, '_kophi_statut', 'done');
    // Fermer aussi les sessions DAW associées
    $daw = get_posts(['post_type'=>'kophi_session','posts_per_page'=>1,'meta_query'=>[
        ['key'=>'_kophi_daw_actif','value'=>'1'],
    ]]);
    foreach ($daw as $d) {
        update_post_meta($d->ID, '_kophi_daw_actif', '0');
        update_post_meta($d->ID, '_kophi_statut', 'done');
    }
    wp_redirect(admin_url('admin.php?page=kophi-sessions&kophi_msg=done'));
    exit;
});

add_action('admin_post_kophi_confirm_session', function() {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id || !check_admin_referer('kophi_confirm_session_' . $id)) wp_die('Accès refusé');
    update_post_meta($id, '_kophi_statut', 'scheduled');
    wp_redirect(admin_url('admin.php?page=kophi-sessions&kophi_msg=confirmed'));
    exit;
});

// ================================================================
// FIX MULTISITE — Forcer le contexte du sous-site pour les REST
// ================================================================
add_filter('rest_pre_serve_request', function($served, $result, $request) {
    // S'assurer que les cookies de session sont valides sur studio.*
    if (!is_user_logged_in()) {
        $user_id = wp_validate_auth_cookie('', 'logged_in');
        if ($user_id) wp_set_current_user($user_id);
    }
    return $served;
}, 10, 3);

// Autoriser aussi via nonce admin pour les appels planning (AJAX fiable sur multisite)
add_action('wp_ajax_kophi_save_blocage', 'kophi_ajax_save_blocage');
function kophi_ajax_save_blocage() {
    check_ajax_referer('kophi_ajax_nonce', 'nonce');
    global $wpdb;
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $titre  = sanitize_text_field($data['titre'] ?? '');
    $debut  = sanitize_text_field($data['date_debut'] ?? '');
    $fin    = sanitize_text_field($data['date_fin'] ?? $debut);
    $hjour  = !empty($data['journee_complete']);
    $hdeb   = sanitize_text_field($data['heure_debut'] ?? '');
    $hfin   = sanitize_text_field($data['heure_fin'] ?? '');
    $motif  = sanitize_text_field($data['motif'] ?? 'autre');
    $desc   = sanitize_textarea_field($data['description'] ?? '');
    if (!$titre || !$debut) { wp_send_json_error(['message'=>'Titre et date obligatoires']); }
    $id = $wpdb->insert($wpdb->prefix.'kophi_blocages', [
        'titre'=>$titre,'date_debut'=>$debut,'date_fin'=>$fin,
        'journee_complete'=>$hjour?1:0,'heure_debut'=>$hdeb?:null,'heure_fin'=>$hfin?:null,
        'motif'=>$motif,'description'=>$desc,'created_by'=>wp_get_current_user()->user_login,
    ]);
    if ($id !== false) {
        wp_send_json_success(['id'=>$wpdb->insert_id,'success'=>true]);
    } else {
        wp_send_json_error(['message'=>'Erreur base de données: '.$wpdb->last_error]);
    }
}

add_action('wp_ajax_kophi_save_session_interne', 'kophi_ajax_save_session_interne');
function kophi_ajax_save_session_interne() {
    check_ajax_referer('kophi_ajax_nonce', 'nonce');
    $data    = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $titre   = sanitize_text_field($data['titre'] ?? 'Session Label');
    $artiste = sanitize_text_field($data['artiste'] ?? 'Label — Interne');
    $date    = sanitize_text_field($data['date'] ?? '');
    $heure   = sanitize_text_field($data['heure'] ?? '');
    $duree   = (float)($data['duree'] ?? 4);
    $notes   = sanitize_textarea_field($data['notes'] ?? '');
    $daw     = sanitize_text_field($data['daw'] ?? '');
    if (!$date || !$heure) { wp_send_json_error(['message'=>'Date et heure obligatoires']); }
    $sess_id = wp_insert_post(['post_type'=>'kophi_session','post_title'=>"$titre — $artiste — $date",'post_status'=>'publish']);
    if (!is_wp_error($sess_id)) {
        update_post_meta($sess_id,'_kophi_client_nom',$artiste);
        update_post_meta($sess_id,'_kophi_type_session','interne');
        update_post_meta($sess_id,'_kophi_date_session',$date);
        update_post_meta($sess_id,'_kophi_heure_debut',$heure);
        update_post_meta($sess_id,'_kophi_duree_prevue',$duree);
        update_post_meta($sess_id,'_kophi_statut','live');
        update_post_meta($sess_id,'_kophi_daw_actif','1');
        update_post_meta($sess_id,'_kophi_daw_app',$daw?:'FL Studio');
        update_post_meta($sess_id,'_kophi_live_start_ts',time());
        update_post_meta($sess_id,'_kophi_notes',$notes);
        wp_send_json_success(['success'=>true,'session_id'=>$sess_id]);
    }
    wp_send_json_error(['message'=>'Erreur création session']);
}

add_action('wp_ajax_kophi_save_resa_tel', 'kophi_ajax_save_resa_tel');
function kophi_ajax_save_resa_tel() {
    check_ajax_referer('kophi_ajax_nonce', 'nonce');
    $data    = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $nom     = sanitize_text_field($data['nom_artiste'] ?? '');
    $tel     = sanitize_text_field($data['telephone'] ?? '');
    $service = sanitize_text_field($data['service'] ?? 'voix');
    $forfait = sanitize_text_field($data['forfait'] ?? '50000');
    $date    = sanitize_text_field($data['date_souhaitee'] ?? '');
    $heure   = sanitize_text_field($data['heure_souhaitee'] ?? '');
    $statut  = sanitize_text_field($data['statut'] ?? 'confirmed');
    $notes   = sanitize_textarea_field($data['notes'] ?? '');
    if (!$nom || !$date) { wp_send_json_error(['message'=>'Nom et date obligatoires']); }
    $id = wp_insert_post(['post_type'=>'kophi_reservation','post_title'=>"$nom — $service — $date",'post_status'=>'publish']);
    if (!is_wp_error($id)) {
        update_post_meta($id,'_kophi_nom_artiste',$nom);
        update_post_meta($id,'_kophi_telephone',$tel);
        update_post_meta($id,'_kophi_service',$service);
        update_post_meta($id,'_kophi_forfait',$forfait);
        update_post_meta($id,'_kophi_date_souhaitee',$date);
        update_post_meta($id,'_kophi_heure_souhaitee',$heure);
        update_post_meta($id,'_kophi_statut',$statut);
        update_post_meta($id,'_kophi_source','telephone');
        update_post_meta($id,'_kophi_notes',$notes);
        wp_send_json_success(['success'=>true,'id'=>$id]);
    }
    wp_send_json_error(['message'=>'Erreur création réservation']);
}

// ── AJAX Reset Sessions ─────────────────────────────────────────
add_action('wp_ajax_kophi_reset_sessions', function() {
    check_ajax_referer('kophi_ajax_nonce', 'nonce');
    if (!current_user_can('administrator') && !current_user_can('kophi_manage_sessions')) {
        wp_send_json_error(['message' => 'Permission refusée']);
    }
    $live_sessions = get_posts([
        'post_type'      => 'kophi_session',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => '_kophi_statut', 'value' => 'live']],
    ]);
    $count = 0;
    foreach ($live_sessions as $s) {
        update_post_meta($s->ID, '_kophi_statut', 'done');
        update_post_meta($s->ID, '_kophi_daw_actif', '0');
        $count++;
    }
    wp_send_json_success(['count' => $count]);
});

// ================================================================
// PLANNING — Handlers AJAX (100% fiables sur Multisite)
// ================================================================

// Vérification nonce pour toutes les actions planning
function kophi_planning_check_nonce() {
    if (!check_ajax_referer('kophi_planning_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Session expirée. Rechargez la page.']);
    }
}

// ── Disponibilités du mois ──────────────────────────────────────
add_action('wp_ajax_kophi_planning_dispo_mois', function() {
    kophi_planning_check_nonce();
    $mois = sanitize_text_field($_POST['mois'] ?? date('Y-m'));
    [$year, $month] = array_pad(explode('-', $mois), 2, '01');
    $days      = (int)date('t', mktime(0,0,0,(int)$month,1,(int)$year));
    $mois_debut = sprintf('%04d-%02d-01', $year, $month);
    $mois_fin   = sprintf('%04d-%02d-%02d', $year, $month, $days);
    $hier       = date('Y-m-d', strtotime('-1 day'));
    global $wpdb;

    // Charger blocages du mois (avec protection si table manquante)
    $bls = [];
    $table_blocages = $wpdb->prefix . 'kophi_blocages';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_blocages'") === $table_blocages) {
        $bls = $wpdb->get_results($wpdb->prepare(
            "SELECT titre, date_debut, date_fin, heure_debut, heure_fin, journee_complete FROM {$table_blocages} WHERE date_debut <= %s AND date_fin >= %s",
            $mois_fin, $mois_debut
        )) ?: [];
    }

    // Charger sessions et réservations confirmées
    // Sessions bloquantes : scheduled et live SEULEMENT
    // done = terminé = créneau libéré ; cancelled = annulé = libéré
    $sessions = get_posts(['post_type'=>'kophi_session','posts_per_page'=>-1,'meta_query'=>[
        ['key'=>'_kophi_date_session','value'=>[$mois_debut,$mois_fin],'compare'=>'BETWEEN','type'=>'DATE'],
        ['key'=>'_kophi_statut','value'=>['scheduled','live'],'compare'=>'IN'],
    ]]);
    $reservations = get_posts(['post_type'=>'kophi_reservation','posts_per_page'=>-1,'meta_query'=>[
        ['key'=>'_kophi_date_souhaitee','value'=>[$mois_debut,$mois_fin],'compare'=>'BETWEEN','type'=>'DATE'],
        ['key'=>'_kophi_statut','value'=>['pending','confirmed'],'compare'=>'IN'],
    ]]);

    // Indexer par date
    $occ = [];
    foreach ($bls as $b) {
        for ($ts = strtotime($b->date_debut); $ts <= strtotime($b->date_fin); $ts += 86400) {
            $ds = date('Y-m-d', $ts);
            $occ[$ds][] = $b->journee_complete ? ['type'=>'jour'] : ['type'=>'heure','h'=>$b->heure_debut,'hf'=>$b->heure_fin];
        }
    }
    foreach ($sessions as $s) {
        $d = get_post_meta($s->ID,'_kophi_date_session',true);
        if ($d) $occ[$d][] = ['type'=>'session'];
    }
    foreach ($reservations as $r) {
        $d = get_post_meta($r->ID,'_kophi_date_souhaitee',true);
        if ($d) $occ[$d][] = ['type'=>'resa'];
    }

    $result = [];
    for ($d = 1; $d <= $days; $d++) {
        $ds  = sprintf('%04d-%02d-%02d', $year, $month, $d);
        if ($ds <= $hier)        { $result[$ds] = 'passe';  continue; }
        $dow = (int)date('w', strtotime($ds));
        $h   = kophi_get_horaires($dow);
        if (!$h['actif'])        { $result[$ds] = 'ferme';  continue; }
        $day_occ = $occ[$ds] ?? [];
        $has_jour = !empty(array_filter($day_occ, function($o){return $o['type']==='jour';}));
        if ($has_jour) { $result[$ds] = 'bloque'; continue; }
        $nb_occ = count(array_filter($day_occ, function($o){return $o['type']!=='jour';}));
        $result[$ds] = $nb_occ === 0 ? 'libre' : ($nb_occ >= 3 ? 'bloque' : 'partiel');
    }
    wp_send_json_success($result);
});

// ── Créneaux d'un jour ──────────────────────────────────────────
add_action('wp_ajax_kophi_planning_creneaux', function() {
    kophi_planning_check_nonce();
    $date = sanitize_text_field($_POST['date'] ?? '');
    if (!$date) wp_send_json_error(['message'=>'Date manquante']);
    $dow  = (int)date('w', strtotime($date));
    $h    = kophi_get_horaires($dow);
    if (!$h['actif']) { wp_send_json_success(['ouvert'=>false,'creneaux'=>[]]); return; }
    $occ  = kophi_get_occupations($date);
    $ouv  = strtotime("$date {$h['ouv']}");
    $ferm = strtotime("$date {$h['ferm']}");
    $step = 3600;
    $dur  = 4 * 3600;
    $creneaux = [];
    for ($t = $ouv; ($t + $dur) <= $ferm; $t += $step) {
        $conflicts = kophi_check_conflicts($occ, $t, $t + $dur);
        $creneaux[] = [
            'heure'      => date('H:i', $t),
            'heure_fin'  => date('H:i', $t + $dur),
            'disponible' => empty($conflicts),
        ];
    }
    wp_send_json_success(['ouvert'=>true,'creneaux'=>$creneaux]);
});

// ── Liste des blocages actifs ───────────────────────────────────
add_action('wp_ajax_kophi_planning_blocages', function() {
    kophi_planning_check_nonce();
    global $wpdb;
    $today = date('Y-m-d');
    $fin60 = date('Y-m-d', strtotime('+60 days'));
    
    // Blocages manuels
    $blocages = [];
    $tbl_bl = $wpdb->prefix . 'kophi_blocages';
    if ($wpdb->get_var("SHOW TABLES LIKE '$tbl_bl'") === $tbl_bl) {
        $blocages = $wpdb->get_results($wpdb->prepare(
            "SELECT id, titre, date_debut, date_fin, heure_debut, heure_fin, journee_complete, motif FROM {$tbl_bl} WHERE date_debut <= %s AND date_fin >= %s ORDER BY date_debut ASC",
            $fin60, $today
        ), ARRAY_A) ?: [];
    }
    
    // Sessions planifiées (60 jours)
    $sessions = get_posts(['post_type'=>'kophi_session','posts_per_page'=>30,'meta_query'=>[
        ['key'=>'_kophi_date_session','value'=>[$today,$fin60],'compare'=>'BETWEEN','type'=>'DATE'],
        ['key'=>'_kophi_statut','value'=>['scheduled','live'],'compare'=>'IN'],
    ],'orderby'=>'meta_value','meta_key'=>'_kophi_date_session','order'=>'ASC']);
    $sessions_data = [];
    foreach ($sessions as $s) {
        $sessions_data[] = [
            'id'           => $s->ID,
            'type'         => 'session',
            'titre'        => $s->post_title,
            'client'       => get_post_meta($s->ID,'_kophi_client_nom',true),
            'date_debut'   => get_post_meta($s->ID,'_kophi_date_session',true),
            'heure_debut'  => get_post_meta($s->ID,'_kophi_heure_debut',true),
            'duree'        => get_post_meta($s->ID,'_kophi_duree_prevue',true),
            'statut'       => get_post_meta($s->ID,'_kophi_statut',true),
            'service'      => get_post_meta($s->ID,'_kophi_type_session',true),
        ];
    }
    
    // Réservations confirmées (60 jours)
    $resas = get_posts(['post_type'=>'kophi_reservation','posts_per_page'=>30,'meta_query'=>[
        ['key'=>'_kophi_date_souhaitee','value'=>[$today,$fin60],'compare'=>'BETWEEN','type'=>'DATE'],
        ['key'=>'_kophi_statut','value'=>['pending','confirmed'],'compare'=>'IN'],
    ],'orderby'=>'meta_value','meta_key'=>'_kophi_date_souhaitee','order'=>'ASC']);
    $resas_data = [];
    foreach ($resas as $r) {
        $resas_data[] = [
            'id'          => $r->ID,
            'type'        => 'reservation',
            'titre'       => $r->post_title,
            'client'      => get_post_meta($r->ID,'_kophi_nom_artiste',true),
            'date_debut'  => get_post_meta($r->ID,'_kophi_date_souhaitee',true),
            'heure_debut' => get_post_meta($r->ID,'_kophi_heure_souhaitee',true),
            'service'     => get_post_meta($r->ID,'_kophi_service',true),
            'forfait'     => get_post_meta($r->ID,'_kophi_forfait',true),
            'statut'      => get_post_meta($r->ID,'_kophi_statut',true),
            'telephone'   => get_post_meta($r->ID,'_kophi_telephone',true),
        ];
    }
    
    wp_send_json_success([
        'blocages'   => $blocages ?: [],
        'sessions'   => $sessions_data,
        'reservations' => $resas_data,
    ]);
});

// ── Supprimer un blocage ────────────────────────────────────────
add_action('wp_ajax_kophi_planning_delete_blocage', function() {
    kophi_planning_check_nonce();
    $id = (int)($_POST['blocage_id'] ?? 0);
    if (!$id) wp_send_json_error(['message'=>'ID manquant']);
    // Suppression + notification libération créneau
    $result = kophi_do_cancel('blocage', $id);
    wp_send_json_success(['deleted' => true, 'notified' => $result['success']]);
});

// ── Créer un blocage ────────────────────────────────────────────
add_action('wp_ajax_kophi_planning_save_blocage', function() {
    kophi_planning_check_nonce();
    global $wpdb;
    $titre  = sanitize_text_field($_POST['titre']           ?? '');
    $debut  = sanitize_text_field($_POST['date_debut']      ?? '');
    $fin    = sanitize_text_field($_POST['date_fin']        ?? $debut);
    $isJour = ($_POST['journee_complete'] ?? '0') === '1';
    $hdebut = sanitize_text_field($_POST['heure_debut']     ?? '');
    $hfin   = sanitize_text_field($_POST['heure_fin']       ?? '');
    $motif  = sanitize_text_field($_POST['motif']           ?? 'autre');
    $desc   = sanitize_textarea_field($_POST['description'] ?? '');
    if (!$titre || !$debut) wp_send_json_error(['message'=>'Titre et date obligatoires']);
    if (!$isJour && (!$hdebut || !$hfin)) wp_send_json_error(['message'=>'Heures obligatoires pour un creneau']);
    $ok = $wpdb->insert($wpdb->prefix.'kophi_blocages', [
        'titre'            => $titre,
        'date_debut'       => $debut,
        'date_fin'         => $fin,
        'journee_complete' => $isJour ? 1 : 0,
        'heure_debut'      => $isJour ? null : $hdebut,
        'heure_fin'        => $isJour ? null : $hfin,
        'motif'            => $motif,
        'description'      => $desc,
        'created_by'       => wp_get_current_user()->user_login,
    ]);
    if ($ok !== false) {
        $bl_id = $wpdb->insert_id;
        // Notification équipe (email + WhatsApp)
        kophi_notify_all([
            'type'     => 'blocage',
            'nom'      => $titre,
            'tel'      => '',
            'service'  => 'blocage',
            'date'     => $debut,
            'date_fin' => $fin !== $debut ? $fin : '',
            'heure'    => $isJour ? '' : $hdebut,
            'heure_fin'=> $isJour ? '' : $hfin,
            'duree'    => '',
            'forfait'  => '',
            'notes'    => $desc ?: $motif,
            'source'   => 'admin',
            'statut'   => 'scheduled',
            'post_id'  => 0,
        ]);
        wp_send_json_success(['id'=>$bl_id,'success'=>true]);
    } else {
        wp_send_json_error(['message'=>'Erreur BDD: '.$wpdb->last_error]);
    }
});

// ── Créer une session interne ───────────────────────────────────
add_action('wp_ajax_kophi_planning_save_session', function() {
    kophi_planning_check_nonce();
    $titre   = sanitize_text_field($_POST['titre']   ?? 'Session Label');
    $artiste = sanitize_text_field($_POST['artiste'] ?? 'Label - Interne');
    $date    = sanitize_text_field($_POST['date']    ?? '');
    $heure   = sanitize_text_field($_POST['heure']   ?? '');
    $duree   = (float)($_POST['duree']               ?? 4);
    $daw     = sanitize_text_field($_POST['daw']     ?? '');
    $notes   = sanitize_textarea_field($_POST['notes'] ?? '');
    if (!$date || !$heure) wp_send_json_error(['message'=>'Date et heure obligatoires']);

    // Vérifier les conflits
    $occ = kophi_get_occupations($date);
    $ts_debut = strtotime("$date $heure");
    $ts_fin   = $ts_debut + (int)($duree * 3600);
    $conflicts = kophi_check_conflicts($occ, $ts_debut, $ts_fin);
    if (!empty($conflicts)) {
        wp_send_json_error(['message'=>'Conflit: '.$conflicts[0]['message']]);
    }

    // Créer la session
    $sess_id = wp_insert_post([
        'post_type'  => 'kophi_session',
        'post_title' => "$titre — $artiste — $date",
        'post_status'=> 'publish',
    ]);
    if (is_wp_error($sess_id)) wp_send_json_error(['message'=>'Erreur creation session']);
    update_post_meta($sess_id,'_kophi_client_nom',$artiste);
    update_post_meta($sess_id,'_kophi_type_session','interne');
    update_post_meta($sess_id,'_kophi_date_session',$date);
    update_post_meta($sess_id,'_kophi_heure_debut',$heure);
    update_post_meta($sess_id,'_kophi_duree_prevue',$duree);
    update_post_meta($sess_id,'_kophi_statut','scheduled');
    update_post_meta($sess_id,'_kophi_notes',$notes);
    if ($daw) {
        update_post_meta($sess_id,'_kophi_daw_app',$daw);
        update_post_meta($sess_id,'_kophi_daw_actif','0');
    }

    // Créer aussi une réservation interne pour bloquer le créneau
    $res_id = wp_insert_post([
        'post_type'  => 'kophi_reservation',
        'post_title' => "INTERNE: $titre — $date",
        'post_status'=> 'publish',
    ]);
    if (!is_wp_error($res_id)) {
        update_post_meta($res_id,'_kophi_nom_artiste',$artiste);
        update_post_meta($res_id,'_kophi_service','interne');
        update_post_meta($res_id,'_kophi_date_souhaitee',$date);
        update_post_meta($res_id,'_kophi_heure_souhaitee',$heure);
        update_post_meta($res_id,'_kophi_forfait','0');
        update_post_meta($res_id,'_kophi_statut','confirmed');
        update_post_meta($res_id,'_kophi_source','interne_label');
        update_post_meta($res_id,'_kophi_notes',$notes);
        update_post_meta($sess_id,'_kophi_reservation_id',$res_id);
    }
    // Notification équipe KOPHI
    // Calculer heure fin
    $heure_fin_session = '';
    if ($heure && $duree) {
        $ts_hf = strtotime("$date $heure") + (int)($duree * 3600);
        $heure_fin_session = date('H:i', $ts_hf);
    }
    kophi_notify_all([
        'type'     => 'session_interne',
        'nom'      => $titre . ' — ' . $artiste,
        'tel'      => '',
        'service'  => 'interne',
        'date'     => $date,
        'date_fin' => '',
        'heure'    => $heure,
        'heure_fin'=> $heure_fin_session,
        'duree'    => $duree ? $duree . 'h' : '',
        'forfait'  => '',
        'notes'    => $notes,
        'daw'      => $daw,
        'source'   => 'interne_label',
        'statut'   => 'scheduled',
        'post_id'  => $sess_id,
    ]);
    wp_send_json_success(['success'=>true,'session_id'=>$sess_id,'reservation_id'=>$res_id??null]);
});

// ── Créer une réservation téléphone ────────────────────────────
add_action('wp_ajax_kophi_planning_save_resa', function() {
    kophi_planning_check_nonce();
    $nom     = sanitize_text_field($_POST['nom']     ?? '');
    $tel     = sanitize_text_field($_POST['tel']     ?? '');
    $service = sanitize_text_field($_POST['service'] ?? 'voix');
    $forfait = sanitize_text_field($_POST['forfait'] ?? '50000');
    $date    = sanitize_text_field($_POST['date']    ?? '');
    $heure   = sanitize_text_field($_POST['heure']   ?? '');
    $statut  = sanitize_text_field($_POST['statut']  ?? 'confirmed');
    $notes   = sanitize_textarea_field($_POST['notes'] ?? '');
    if (!$nom || !$date) wp_send_json_error(['message'=>'Nom et date obligatoires']);

    // Vérifier conflits si heure fournie
    if ($heure) {
        $occ   = kophi_get_occupations($date);
        $duree = kophi_get_duree($service, $forfait);
        $ts_d  = strtotime("$date $heure");
        $ts_f  = $ts_d + (int)($duree * 3600);
        $conf  = kophi_check_conflicts($occ, $ts_d, $ts_f);
        if (!empty($conf) && $statut === 'confirmed') {
            wp_send_json_error(['message'=>'Conflit de creneau: '.$conf[0]['message']]);
        }
    }

    $res_id = wp_insert_post([
        'post_type'  => 'kophi_reservation',
        'post_title' => "$nom — $service — $date",
        'post_status'=> 'publish',
    ]);
    if (is_wp_error($res_id)) wp_send_json_error(['message'=>'Erreur creation']);
    update_post_meta($res_id,'_kophi_nom_artiste',$nom);
    update_post_meta($res_id,'_kophi_telephone',$tel);
    update_post_meta($res_id,'_kophi_service',$service);
    update_post_meta($res_id,'_kophi_forfait',$forfait);
    update_post_meta($res_id,'_kophi_date_souhaitee',$date);
    update_post_meta($res_id,'_kophi_heure_souhaitee',$heure);
    update_post_meta($res_id,'_kophi_statut',$statut);
    update_post_meta($res_id,'_kophi_source','telephone');
    update_post_meta($res_id,'_kophi_notes',$notes);
    update_post_meta($res_id,'_kophi_created_by',get_current_user_id());

    // Si confirmée et heure dispo → créer la session aussi
    if ($statut === 'confirmed' && $heure) {
        $duree   = kophi_get_duree($service, $forfait);
        $sess_id = wp_insert_post([
            'post_type'  => 'kophi_session',
            'post_title' => "$nom — $service — $date",
            'post_status'=> 'publish',
        ]);
        if (!is_wp_error($sess_id)) {
            update_post_meta($sess_id,'_kophi_client_nom',$nom);
            update_post_meta($sess_id,'_kophi_telephone',$tel);
            update_post_meta($sess_id,'_kophi_type_session',$service);
            update_post_meta($sess_id,'_kophi_date_session',$date);
            update_post_meta($sess_id,'_kophi_heure_debut',$heure);
            update_post_meta($sess_id,'_kophi_duree_prevue',$duree);
            update_post_meta($sess_id,'_kophi_montant',$forfait);
            update_post_meta($sess_id,'_kophi_statut','scheduled');
            update_post_meta($sess_id,'_kophi_notes',"Tel: $tel. $notes");
            update_post_meta($sess_id,'_kophi_reservation_id',$res_id);
            update_post_meta($res_id,'_kophi_session_id',$sess_id);
        }
    }
    // Notification équipe KOPHI
    // Calculer heure fin selon forfait
    $duree_resa = kophi_get_duree($service, $forfait);
    $heure_fin_resa = '';
    if ($heure && $duree_resa) {
        $ts_hf = strtotime("$date $heure") + (int)($duree_resa * 3600);
        $heure_fin_resa = date('H:i', $ts_hf);
    }
    kophi_notify_all([
        'type'     => 'reservation',
        'nom'      => $nom,
        'tel'      => $tel,
        'email'    => '',
        'service'  => $service,
        'date'     => $date,
        'date_fin' => '',
        'heure'    => $heure,
        'heure_fin'=> $heure_fin_resa,
        'duree'    => $duree_resa ? $duree_resa . 'h' : '',
        'forfait'  => $forfait,
        'notes'    => $notes,
        'source'   => 'telephone',
        'statut'   => $statut,
        'post_id'  => $res_id,
    ]);
    wp_send_json_success(['success'=>true,'id'=>$res_id]);
});

// ================================================================
// AJAX — Annuler une réservation (depuis le planning admin)
// ================================================================
add_action('wp_ajax_kophi_cancel_reservation', function() {
    check_ajax_referer('kophi_ajax_nonce', 'nonce');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(['message' => 'ID manquant']);
    // Annulation + cascade + notification
    $result = kophi_do_cancel('reservation', $id);
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
});

// AJAX — Annuler une session (depuis planning ou sessions admin)
add_action('wp_ajax_kophi_cancel_session', function() {
    check_ajax_referer('kophi_ajax_nonce', 'nonce');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(['message' => 'ID manquant']);
    // Annulation + cascade + notification
    $result = kophi_do_cancel('session', $id);
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
});

// ================================================================
// FIX — kophi_get_occupations : garantir cohérence totale
// ================================================================
// Note: la fonction est définie plus haut, ces hooks s'assurent
// que toute annulation déclenche un recalcul propre

// Quand un post_meta _kophi_statut change → invalider le cache REST
add_action('updated_post_meta', function($meta_id, $post_id, $meta_key, $meta_value) {
    if ($meta_key !== '_kophi_statut') return;
    // Invalider le cache REST pour les endpoints de disponibilité
    delete_transient('kophi_dispo_cache_' . date('Y-m'));
    delete_transient('kophi_dispo_cache_' . date('Y-m', strtotime('+1 month')));
}, 10, 4);

// ================================================================
// AJAX — Vérifier sessions live (polling dashboard toutes les 20s)
// ================================================================
add_action('wp_ajax_kophi_check_live_sessions', function() {
    // Pas de nonce strict ici — lecture seule, juste pour le polling
    $live = get_posts([
        'post_type'      => 'kophi_session',
        'posts_per_page' => 20,
        'meta_query'     => [['key' => '_kophi_statut', 'value' => 'live']],
        'fields'         => 'ids',
    ]);
    // Retourner les IDs des sessions encore live
    wp_send_json_success(array_map('strval', $live));
});

// ================================================================
// SHORTCODES PAGE ACCUEIL
// ================================================================
require_once KOPHI_DIR . 'templates/front/shortcode-beats-home.php';
require_once KOPHI_DIR . 'templates/front/shortcode-reservation-home.php';

// AJAX — Soumission formulaire réservation accueil
add_action('wp_ajax_kophi_resa_home_submit',        'kophi_resa_home_submit');
add_action('wp_ajax_nopriv_kophi_resa_home_submit', 'kophi_resa_home_submit');
function kophi_resa_home_submit() {
    // Le jeton n'est exigé que pour les utilisateurs connectés : pour un visiteur anonyme
    // il n'apporte aucune protection, et il expirait sur les pages servies par un cache
    // (« Session expirée » alors que le visiteur venait d'arriver). L'anti-abus par IP
    // ci-dessous protège le formulaire.
    if (is_user_logged_in() && !check_ajax_referer('kophi_resa_home', 'nonce', false)) {
        wp_send_json_error(['message' => 'Session expirée, rechargez la page.']);
    }
    $nom     = sanitize_text_field(wp_unslash($_POST['nom']     ?? ''));
    $tel     = sanitize_text_field($_POST['tel']     ?? '');
    $service = sanitize_text_field($_POST['service'] ?? 'voix');
    $forfait = sanitize_text_field($_POST['forfait'] ?? '50000');
    $date    = sanitize_text_field($_POST['date']    ?? '');
    $heure   = sanitize_text_field($_POST['heure']   ?? '');
    $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));

    if (!$nom || !$tel || !$date) {
        wp_send_json_error(['message' => 'Nom, téléphone et date obligatoires.']);
    }
    // Anti-abus + validation (mêmes règles que l'API de réservation)
    if (function_exists('kophi_rate_limit_exceeded') && kophi_rate_limit_exceeded('reservation', 6, 10 * MINUTE_IN_SECONDS)) {
        wp_send_json_error(['message' => 'Trop de demandes depuis cette connexion. Réessayez dans quelques minutes.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        wp_send_json_error(['message' => 'Date invalide.']);
    }
    if ($date < current_time('Y-m-d')) {
        wp_send_json_error(['message' => 'Cette date est déjà passée.']);
    }
    if ($heure && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure)) $heure = '';
    if (strlen(preg_replace('/\D/', '', $tel)) < 8) {
        wp_send_json_error(['message' => 'Numéro de téléphone invalide.']);
    }
    $nom     = mb_substr($nom, 0, 120);
    $message = mb_substr($message, 0, 2000);
    // Créneau précis demandé : refuser s'il est déjà pris (comme la page Réserver)
    if ($heure) {
        $hD   = strtotime("$date $heure");
        $hF   = $hD + (int) round(kophi_get_duree($service, $forfait) * 3600);
        $conf = kophi_check_conflicts(kophi_get_occupations($date), $hD, $hF);
        if (!empty($conf)) wp_send_json_error(['message' => $conf[0]['message']]);
    }

    // Créer la réservation
    $res_id = wp_insert_post([
        'post_type'   => 'kophi_reservation',
        'post_title'  => "$nom — $service — $date",
        'post_status' => 'publish',
    ]);

    if (is_wp_error($res_id)) {
        wp_send_json_error(['message' => 'Erreur lors de la création de la réservation.']);
    }

    update_post_meta($res_id, '_kophi_nom_artiste',    $nom);
    update_post_meta($res_id, '_kophi_telephone',      $tel);
    update_post_meta($res_id, '_kophi_service',        $service);
    update_post_meta($res_id, '_kophi_forfait',        $forfait);
    update_post_meta($res_id, '_kophi_date_souhaitee', $date);
    update_post_meta($res_id, '_kophi_heure_souhaitee',$heure);
    update_post_meta($res_id, '_kophi_statut',         'pending');
    update_post_meta($res_id, '_kophi_source',         'site_accueil');
    update_post_meta($res_id, '_kophi_notes',          $message);
    update_post_meta($res_id, '_kophi_message',        $message); // clé lue par l'admin (comme la page Réserver)

    // Notification complète (email + WhatsApp) — un seul appel
    kophi_notify_all([
        'type'    => 'reservation',
        'nom'     => $nom,
        'tel'     => $tel,
        'email'   => '',
        'service' => $service,
        'date'    => $date,
        'heure'   => $heure,
        'forfait' => $forfait,
        'notes'   => $message,
        'source'  => 'site_accueil',
        'statut'  => 'pending',
        'post_id' => $res_id,
    ]);
    wp_send_json_success(['id' => $res_id, 'message' => 'Réservation créée avec succès.']);
}

// Shortcode page accueil complète
require_once KOPHI_DIR . 'templates/front/shortcode-accueil.php';

// Forcer la mise à jour de la clé DAW si elle est encore à l'ancienne valeur
add_action('admin_init', function() {
    $current = get_option('kophi_daw_api_key', '');
    $old_keys = ['KOPHI_DAW_SECRET_KEY_2024', 'KOPHI_DAW_SECRET_KEY_2025', ''];
    if (empty($current) || in_array($current, $old_keys)) {
        update_option('kophi_daw_api_key', 'KOPHIS_DAW_SECRET_KEY_2025');
    }
});

// ================================================================
// SYSTÈME DE NOTIFICATIONS COMPLET
// Email HTML + WhatsApp (UltraMsg / Twilio)
// Déclenchement : toute réservation ou session créée
// ================================================================

// ── Libellés services ─────────────────────────────────────────
function kophi_svc_label(string $svc): string {
    $map = [
        'voix'         => 'Enregistrement Voix / Mix & Master',
        'faceB'        => 'Face B',
        'beats'        => 'Beat Custom',
        'consultation' => 'Consultation / RDV',
        'mixmaster'    => 'Mix & Master',
        'mastering'    => 'Mastering',
        'interne'      => 'Session Interne Label',
        'achat_beat'   => 'Achat Beat',
    ];
    return $map[$svc] ?? ucfirst($svc);
}

// ── Envoi WhatsApp — Multi-provider ───────────────────────────
// Provider actif configurable dans Paramètres → Notifications
function kophi_send_whatsapp(string $phone, string $message): bool {
    $provider = get_option('kophi_wa_provider', 'ultramsg');

    if ($provider === 'ultramsg') {
        return kophi_wa_ultramsg($phone, $message);
    } elseif ($provider === 'twilio') {
        return kophi_wa_twilio($phone, $message);
    }
    // Autres providers → fallback silencieux
    return false;
}

// ── UltraMsg (QR code scan — le plus simple) ─────────────────
// Setup : ultramsg.com → créer instance → scanner QR → copier instance_id + token
function kophi_wa_ultramsg(string $phone, string $message): bool {
    // Sélectionner l'instance UltraMsg selon le numéro destinataire
    $phone_clean = preg_replace('/[^0-9]/', '', $phone);
    $wa2_clean   = preg_replace('/[^0-9]/', '', KOPHI_WA2);

    if ($phone_clean === $wa2_clean) {
        // Numéro 2 (+2250576154653) → Instance 2
        $instance = get_option('kophi_ultramsg_instance2', '');
        $token    = get_option('kophi_ultramsg_token2', '');
        // Fallback sur instance 1 si instance 2 non configurée
        if (empty($instance) || empty($token)) {
            $instance = get_option('kophi_ultramsg_instance', '');
            $token    = get_option('kophi_ultramsg_token', '');
        }
    } else {
        // Numéro 1 (+2250503404389) → Instance 1
        $instance = get_option('kophi_ultramsg_instance', '');
        $token    = get_option('kophi_ultramsg_token', '');
    }

    if (empty($instance) || empty($token)) return false;

    $url = "https://api.ultramsg.com/{$instance}/messages/chat";
    $response = wp_remote_post($url, [
        'timeout' => 15,
        'blocking'=> false,
        'body'    => [
            'token'  => $token,
            'to'     => '+' . $phone_clean,
            'body'   => $message,
        ],
    ]);
    return !is_wp_error($response);
}

// ── Twilio WhatsApp API ───────────────────────────────────────
// Setup : twilio.com → compte → sandbox WhatsApp → Account SID + Auth Token
function kophi_wa_twilio(string $phone, string $message): bool {
    $sid    = get_option('kophi_twilio_sid', '');
    $token  = get_option('kophi_twilio_token', '');
    $from   = get_option('kophi_twilio_from', ''); // Ex: whatsapp:+14155238886
    if (empty($sid) || empty($token) || empty($from)) return false;

    $phone_clean = preg_replace('/[^0-9+]/', '', $phone);
    $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

    $response = wp_remote_post($url, [
        'timeout' => 15,
        'blocking'=> false,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode("{$sid}:{$token}"),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body' => [
            'From' => $from,
            'To'   => 'whatsapp:' . $phone_clean,
            'Body' => $message,
        ],
    ]);
    return !is_wp_error($response);
}

// ── Construire le message de notification ─────────────────────
function kophi_build_notif_message(array $data, string $format = 'text'): string {
    $type    = $data['type']    ?? 'reservation';
    $nom     = $data['nom']     ?? '';
    $tel     = $data['tel']     ?? '';
    $email   = $data['email']   ?? '';
    $service = $data['service'] ?? '';
    $date     = $data['date']     ?? '';
    $date_fin = $data['date_fin'] ?? '';
    $heure    = $data['heure']    ?? '';
    $heure_fin= $data['heure_fin']?? '';
    $duree    = $data['duree']    ?? '';
    $forfait  = $data['forfait']  ?? '';
    $notes   = $data['notes']   ?? '';
    $source  = $data['source']  ?? 'site';
    $statut  = $data['statut']  ?? 'pending';
    $beat    = $data['beat']    ?? '';
    $daw     = $data['daw']     ?? '';

    $svc_label  = kophi_svc_label($service);
    $date_fmt    = $date      ? date('d/m/Y', strtotime($date))      : '—';
    $date_fin_fmt= $date_fin  ? date('d/m/Y', strtotime($date_fin))  : '';
    $heure_fmt   = $heure     ? date('H:i',   strtotime($heure))     : '—';
    $heure_fin_fmt=$heure_fin ? date('H:i',   strtotime($heure_fin)) : '';
    // Affichage plage date/heure
    $date_display  = $date_fin_fmt && $date_fin_fmt !== $date_fmt ? "{$date_fmt} → {$date_fin_fmt}" : $date_fmt;
    $heure_display = $heure_fin_fmt ? "{$heure_fmt} → {$heure_fin_fmt}" . ($duree ? " ({$duree}h)" : '') : $heure_fmt . ($duree ? " ({$duree}h)" : '');
    $montant    = $forfait ? number_format((float)$forfait, 0, ',', ' ') . ' F CFA' : '—';
    $manage_url = admin_url('admin.php?page=kophi-reservations');

    // Icône selon type
    $icons = [
        'reservation'     => '📋',
        'session_interne' => '🎵',
        'blocage'         => '🔒',
        'confirmation'    => '✅',
        'achat_beat'      => '🎹',
        'annulation'      => '❌',
        'paiement_declare'=> '💰',
        'paiement_confirme'=> '✅',
    ];
    $icon = $icons[$type] ?? '📋';

    // Titre
    $titles = [
        'reservation'     => 'NOUVELLE RÉSERVATION CLIENT',
        'session_interne' => 'NOUVELLE SESSION INTERNE LABEL',
        'blocage'         => 'CRÉNEAU BLOQUÉ',
        'confirmation'    => 'RÉSERVATION CONFIRMÉE',
        'achat_beat'      => 'DEMANDE ACHAT BEAT',
        'annulation'      => 'ANNULATION — CRÉNEAU LIBÉRÉ',
        'paiement_declare'=> 'PAIEMENT DÉCLARÉ — À VÉRIFIER',
        'paiement_confirme'=> 'PAIEMENT CONFIRMÉ',
    ];
    $title = $titles[$type] ?? 'NOUVELLE RÉSERVATION';

    $sources_label = ['site'=>'Site web','telephone'=>'Téléphone','whatsapp'=>'WhatsApp','interne_label'=>'Interne Label','site_accueil'=>'Page Accueil'];
    $source_label  = $sources_label[$source] ?? $source;

    if ($format === 'html') {
        // ── Email HTML ───────────────────────────────────────────
        $rows = [
            ['Artiste / Nom', esc_html($nom)],
            ['Téléphone',     esc_html($tel)],
        ];
        if ($email)   $rows[] = ['Email',     esc_html($email)];
        $rows[] = ['Service',   esc_html($svc_label)];
        $rows[] = ['Date',      esc_html($date_display)];
        $rows[] = ['Heure',     esc_html($heure_display)];
        if ($forfait && $type !== 'session_interne' && $type !== 'blocage')
            $rows[] = ['Montant', '<strong style="color:#E8B84B">' . esc_html($montant) . '</strong>'];
        if ($daw)     $rows[] = ['DAW',       esc_html($daw)];
        if ($beat)    $rows[] = ['Beat',      esc_html($beat)];
        if ($notes)   $rows[] = ['Notes',     esc_html($notes)];
        $rows[] = ['Source',    esc_html($source_label)];

        $table_rows = '';
        foreach ($rows as [$lbl, $val]) {
            $table_rows .= "<tr><td style='padding:10px 16px;background:#1a0a30;color:#9E98B8;font-size:12px;width:140px;white-space:nowrap'>{$lbl}</td><td style='padding:10px 16px;color:#F0ECF8;font-size:13px'>{$val}</td></tr>";
        }

        $statut_color = $statut === 'confirmed' ? '#00C87A' : ($statut === 'pending' ? '#E8B84B' : '#A855F7');
        $statut_label = ['pending'=>'En attente','confirmed'=>'Confirmée','live'=>'En cours','scheduled'=>'Planifiée'][$statut] ?? $statut;

        return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:0;background:#0D0D0D;font-family:Inter,Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#0D0D0D;padding:24px 0'>
<tr><td align='center'>
<table width='580' cellpadding='0' cellspacing='0' style='background:#130826;border:1px solid rgba(139,63,217,.3);border-radius:16px;overflow:hidden'>

  <!-- Header -->
  <tr><td style='background:linear-gradient(135deg,#1e0938,#2d0a5e);padding:28px 32px;text-align:center'>
    <img src='https://studio.kophismusic.com/wp-content/uploads/sites/8/2026/03/LOGO-KOPHIS-MUSIC-STUDIO.png' width='48' height='48' style='border-radius:12px;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto'>
    <p style='color:#9E98B8;font-size:10px;letter-spacing:3px;text-transform:uppercase;margin:0 0 6px'>KOPHI'S MUSIC STUDIO</p>
    <h1 style='color:#F0ECF8;font-size:20px;margin:0;letter-spacing:1px'>{$icon} {$title}</h1>
    <span style='display:inline-block;margin-top:10px;padding:4px 14px;background:{$statut_color}22;border:1px solid {$statut_color}55;border-radius:20px;color:{$statut_color};font-size:10px;letter-spacing:1.5px;text-transform:uppercase'>{$statut_label}</span>
  </td></tr>

  <!-- Tableau infos -->
  <tr><td style='padding:24px 32px'>
    <table width='100%' cellpadding='0' cellspacing='0' style='border-radius:10px;overflow:hidden;border:1px solid rgba(255,255,255,.07)'>
      {$table_rows}
    </table>
  </td></tr>

  <!-- CTA -->
  <tr><td style='padding:0 32px 28px;text-align:center'>
    <a href='" . esc_url($manage_url) . "' style='display:inline-block;padding:13px 28px;background:linear-gradient(135deg,#8B3FD9,#E8B84B);color:#fff;text-decoration:none;border-radius:10px;font-size:13px;font-weight:700;letter-spacing:.5px'>Gérer dans le tableau de bord →</a>
  </td></tr>

  <!-- Footer -->
  <tr><td style='background:#0D0D0D;padding:16px 32px;text-align:center;border-top:1px solid rgba(255,255,255,.06)'>
    <p style='color:#555;font-size:10px;margin:0'>KOPHI'S MUSIC STUDIO · Abidjan, Côte d'Ivoire · contact@kophismusic.com · +225 05 03 40 43 89</p>
  </td></tr>

</table>
</td></tr></table>
</body></html>";

    } else {
        // ── WhatsApp / Texte ─────────────────────────────────────
        $lines = [
            "{$icon} *{$title}*",
            "━━━━━━━━━━━━━━━━━━━━",
            "👤 *Artiste:* {$nom}",
            "📱 *Tel:* {$tel}",
        ];
        if ($email)   $lines[] = "📧 *Email:* {$email}";
        $lines[] = "🎙 *Service:* {$svc_label}";
        $lines[] = "📅 *Date:* {$date_display}";
        $lines[] = "🕐 *Heure:* {$heure_display}";
        if ($forfait && $type !== 'session_interne' && $type !== 'blocage')
            $lines[] = "💰 *Montant:* {$montant}";
        if ($daw)     $lines[] = "🎛 *DAW:* {$daw}";
        if ($beat)    $lines[] = "🎹 *Beat:* {$beat}";
        if ($notes)   $lines[] = "📝 *Notes:* {$notes}";
        $lines[] = "🔗 *Source:* {$source_label}";
        $lines[] = "━━━━━━━━━━━━━━━━━━━━";
        $lines[] = "KOPHI'S MUSIC STUDIO";

        return implode("\n", $lines);
    }
}

// ── Notification au CLIENT (confirmation de sa réservation) ───
function kophi_notify_client(array $data): void {
    $tel   = $data['tel']   ?? '';
    $email = $data['email'] ?? '';
    $nom   = $data['nom']   ?? 'Artiste';
    $type  = $data['type']  ?? 'reservation';

    // Pas de notification client pour les sessions internes/blocages
    if (in_array($type, ['session_interne', 'blocage'])) return;

    // Message spécifique pour les annulations
    if ($type === 'annulation') {
        $msg_client = "❌ *Annulation de votre réservation*

"
                    . "Bonjour {$nom},

"
                    . "Votre réservation du " . ($date_fmt ?? '—') . " a été annulée.

"
                    . "Pour toute question ou re-réservation :
"
                    . "📱 WhatsApp : +225 05 03 40 43 89
"
                    . "🌐 studio.kophismusic.com

"
                    . "KOPHI'S MUSIC STUDIO 🎵";
        if ($email) {
            $sub_client = "❌ Annulation — KOPHI'S MUSIC STUDIO";
            $html_ann   = "<html><body style='background:#0D0D0D;font-family:Inter,sans-serif;padding:24px'>"
                        . "<div style='max-width:500px;margin:0 auto;background:#130826;border:1px solid rgba(255,71,87,.3);border-radius:16px;padding:28px;color:#F0ECF8'>"
                        . "<h2 style='color:#FF4757;margin:0 0 16px'>❌ Réservation annulée</h2>"
                        . "<p>Bonjour <strong>" . esc_html($nom) . "</strong>,</p>"
                        . "<p style='color:#9E98B8'>Votre réservation a été annulée. Pour toute question ou nouvelle réservation :</p>"
                        . "<a href='https://wa.me/2250503404389' style='display:inline-block;margin-top:12px;padding:11px 22px;background:#25D366;color:#fff;text-decoration:none;border-radius:10px;font-size:13px;font-weight:700'>💬 Nous contacter sur WhatsApp</a>"
                        . "<p style='margin-top:20px;font-size:11px;color:#555'>KOPHI'S MUSIC STUDIO · Abidjan · contact@kophismusic.com</p>"
                        . "</div></body></html>";
            $h = ['Content-Type: text/html; charset=UTF-8'];
            wp_mail($email, $sub_client, $html_ann, $h);
        }
        return;
    }

    $svc_label = kophi_svc_label($data['service'] ?? '');
    $date_fmt  = !empty($data['date'])  ? date('d/m/Y', strtotime($data['date']))  : '—';
    $heure_fmt = !empty($data['heure']) ? date('H:i',   strtotime($data['heure'])) : '—';
    $montant   = !empty($data['forfait']) ? number_format((float)$data['forfait'], 0, ',', ' ') . ' F CFA' : '';

    // Message WhatsApp client
    $wa_client = "✅ *Demande reçue — KOPHI'S MUSIC STUDIO*\n\n"
               . "Bonjour {$nom} !\n\n"
               . "Votre demande de réservation a bien été reçue.\n\n"
               . "📋 *Récapitulatif :*\n"
               . "🎙 Service : {$svc_label}\n"
               . "📅 Date souhaitée : {$date_fmt}\n"
               . "🕐 Heure : {$heure_fmt}\n"
               . ($montant ? "💰 Montant : {$montant}\n" : '')
               . "\nNous vous confirmons votre session dans les plus brefs délais.\n"
               . "📱 Contact : +225 05 03 40 43 89\n\n"
               . "KOPHI'S MUSIC STUDIO 🎵";

    // Email client
    if ($email) {
        $subject = "✅ Demande reçue — KOPHI'S MUSIC STUDIO";
        $body = str_replace(
            ['{icon} *{title}*', '━━━━━━━━━━━━━━━━━━━━'],
            ['', ''],
            $wa_client
        );
        $html_body = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:0;background:#0D0D0D;font-family:Inter,Arial,sans-serif'>
<table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center' style='padding:24px'>
<table width='560' cellpadding='0' cellspacing='0' style='background:#130826;border:1px solid rgba(0,200,122,.3);border-radius:16px;overflow:hidden'>
  <tr><td style='background:linear-gradient(135deg,#0a1e0d,#0d2b11);padding:24px 32px;text-align:center'>
    <img src='https://studio.kophismusic.com/wp-content/uploads/sites/8/2026/03/LOGO-KOPHIS-MUSIC-STUDIO.png' width='44' height='44' style='border-radius:10px;margin-bottom:10px;display:block;margin:0 auto 10px'>
    <h1 style='color:#00C87A;font-size:18px;margin:0'>✅ Demande bien reçue !</h1>
    <p style='color:#9E98B8;font-size:12px;margin:8px 0 0'>KOPHI'S MUSIC STUDIO — Abidjan</p>
  </td></tr>
  <tr><td style='padding:24px 32px'>
    <p style='color:#F0ECF8;font-size:14px;margin:0 0 16px'>Bonjour <strong>" . esc_html($nom) . "</strong>,</p>
    <p style='color:#9E98B8;font-size:13px;line-height:1.6;margin:0 0 20px'>Votre demande de réservation a bien été reçue. Nous vous confirmons votre session dans les plus brefs délais.</p>
    <table width='100%' cellpadding='0' cellspacing='0' style='background:#0D0D0D;border-radius:10px;overflow:hidden;border:1px solid rgba(255,255,255,.07)'>
      <tr><td style='padding:10px 16px;color:#9E98B8;font-size:11px;width:130px'>Service</td><td style='padding:10px 16px;color:#F0ECF8;font-size:12px'>" . esc_html($svc_label) . "</td></tr>
      <tr><td style='padding:10px 16px;color:#9E98B8;font-size:11px;background:#1a0a30'>Date</td><td style='padding:10px 16px;color:#F0ECF8;font-size:12px;background:#1a0a30'>" . esc_html($date_fmt) . "</td></tr>
      <tr><td style='padding:10px 16px;color:#9E98B8;font-size:11px'>Heure</td><td style='padding:10px 16px;color:#F0ECF8;font-size:12px'>" . esc_html($heure_fmt) . "</td></tr>
      " . ($montant ? "<tr><td style='padding:10px 16px;color:#9E98B8;font-size:11px;background:#1a0a30'>Montant</td><td style='padding:10px 16px;color:#E8B84B;font-size:13px;font-weight:700;background:#1a0a30'>" . esc_html($montant) . "</td></tr>" : '') . "
    </table>
  </td></tr>
  <tr><td style='padding:0 32px 24px;text-align:center'>
    <a href='" . esc_url(KOPHI_WA_LINK . '?text=' . urlencode("Bonjour KOPHI'S MUSIC STUDIO, je voulais confirmer ma réservation du {$date_fmt}")) . "' style='display:inline-block;padding:12px 24px;background:#25D366;color:#fff;text-decoration:none;border-radius:10px;font-size:12px;font-weight:700'>💬 Nous contacter sur WhatsApp</a>
  </td></tr>
  <tr><td style='background:#0D0D0D;padding:14px 32px;text-align:center;border-top:1px solid rgba(255,255,255,.06)'>
    <p style='color:#555;font-size:10px;margin:0'>KOPHI'S MUSIC STUDIO · Abidjan · +225 05 03 40 43 89</p>
  </td></tr>
</table></td></tr></table></body></html>";

        // Headers email — sans From: explicite (géré par wp_mail_from filter)
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: ' . KOPHI_EMAIL,
    ];
        wp_mail($email, $subject, $html_body, $headers);
    }

    // WhatsApp client (si provider configuré)
    if ($tel) {
        $tel_clean = preg_replace('/[^0-9+]/', '', $tel);
        $client_key = get_option('kophi_wa_notify_client', '');
        if ($client_key) {
            $msg_wa_confirm = "✅ *Votre réservation est confirmée !*\n\n"
                            . "Bonjour {$nom},\n"
                            . "Rendez-vous le {$date_fmt} à {$heure_fmt}.\n"
                            . "KOPHI'S MUSIC STUDIO 🎵";
            kophi_send_whatsapp($tel_clean, $msg_wa_confirm);
        }
    }
}

// ── Fonction centrale : notifier l'équipe KOPHI ───────────────
function kophi_notify_all(array $data): void {
    $type    = $data['type']  ?? 'reservation';
    $nom     = $data['nom']   ?? '';
    $service = $data['service'] ?? '';

    // ── 1. EMAIL HTML → contact@kophismusic.com ──────────────
    $subjects = [
        'reservation'     => "🎙 Nouvelle réservation — {$nom}",
        'session_interne' => "🎵 Session Interne Label — {$nom}",
        'blocage'         => "🔒 Créneau bloqué — " . ($data['date'] ?? ''),
        'confirmation'    => "✅ Réservation confirmée — {$nom}",
        'achat_beat'      => "🎹 Achat Beat — {$nom}",
        'annulation'      => "❌ Annulation — {$nom} — " . ($data['date'] ?? ''),
        'paiement_declare'=> "💰 Paiement déclaré à vérifier — {$nom}",
        'paiement_confirme'=> "✅ Paiement confirmé — {$nom}",
    ];
    $subject = $subjects[$type] ?? "📋 Notification KOPHI — {$nom}";
    $html    = kophi_build_notif_message($data, 'html');
    // Headers email — sans From: explicite (géré par wp_mail_from filter)
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'Reply-To: ' . KOPHI_EMAIL,
    ];

    // Envoyer aux 2 adresses email de l'équipe
    $recipients = [
        KOPHI_EMAIL,                   // contact@kophismusic.com
        'gadagadagnahoua@gmail.com',   // Adresse secondaire
    ];

    // Essai 1 : email HTML
    foreach ($recipients as $recipient) {
        $sent = wp_mail($recipient, $subject, $html, $headers);
        if (!$sent) {
            // Essai 2 : fallback texte brut si HTML échoue
            $plain_text = kophi_build_notif_message($data, 'text');
            $plain_headers = ['Content-Type: text/plain; charset=UTF-8'];
            $sent2 = wp_mail($recipient, $subject, $plain_text, $plain_headers);
            if (!$sent2) {
                error_log('[KOPHI] Email ÉCHEC (HTML+texte) vers ' . $recipient . ' | sujet: ' . $subject);
            } else {
                error_log('[KOPHI] Email texte brut OK vers ' . $recipient);
            }
        }
    }

    // ── 2. WHATSAPP → +2250503404389 (numéro 1) ──────────────
    $wa_text = kophi_build_notif_message($data, 'text');
    kophi_send_whatsapp(KOPHI_WA, $wa_text);

    // ── 3. WHATSAPP → +2250576154653 (numéro 2) ──────────────
    kophi_send_whatsapp(KOPHI_WA2, $wa_text);

    // ── 4. Notification au client/artiste ────────────────────
    kophi_notify_client($data);

    // ── 5. Logger dans les meta du post si ID fourni ─────────
    if (!empty($data['post_id'])) {
        update_post_meta($data['post_id'], '_kophi_notif_sent', date('Y-m-d H:i:s'));
        update_post_meta($data['post_id'], '_kophi_notif_email', '1');
    }
}

// ── Remplacer kophi_send_email_notif par un alias ─────────────
// (rétrocompatibilité — redirige vers kophi_notify_all)
function kophi_send_email_notif_v2(array $data): void {
    kophi_notify_all($data);
}

// ================================================================
// ASSISTANT KOPHI — Restrictions interface WordPress
// ================================================================


// ── Forcer l'accès admin pour l'assistant KOPHI (Multisite) ─────
// Hook très précoce — avant que WordPress vérifie l'autorisation
add_action('plugins_loaded', function() {
    if (!is_admin()) return;
    $user = wp_get_current_user();
    if (!$user || !$user->ID) return;
    // Lire les caps depuis la meta du sous-site
    global $wpdb;
    $cap_key   = $wpdb->get_blog_prefix() . 'capabilities';
    $user_caps = get_user_meta($user->ID, $cap_key, true);
    if (!is_array($user_caps) || empty($user_caps['kophi_assistant'])) return;
    // L'utilisateur est bien un assistant → forcer 'read' sur l'objet WP_User
    // pour que WordPress autorise l'accès à l'admin
    $user->add_cap('read',    true);
    $user->add_cap('level_0', true);
    // Mémoriser pour kophi_is_assistant()
    global $kophi_assistant_force_access;
    $kophi_assistant_force_access = true;
}, 1);

// ── 1. Masquer tous les menus WordPress natifs pour l'assistant ──
add_action('admin_menu', function() {
    if (!kophi_is_assistant()) return;

    // Menus WordPress natifs à masquer
    $remove = [
        'index.php',              // Tableau de bord WP
        'edit.php',               // Articles
        // 'upload.php',           // Médias — AUTORISÉ (upload sons/médias)
        'edit.php?post_type=page',// Pages
        'edit-comments.php',      // Commentaires
        'themes.php',             // Apparence
        'plugins.php',            // Extensions
        'users.php',              // Utilisateurs
        'tools.php',              // Outils
        'options-general.php',    // Réglages
        'profile.php',            // Profil (laissé accessible)
    ];
    foreach ($remove as $menu) {
        remove_menu_page($menu);
    }

    // Sous-menu KOPHI inaccessible à l'assistant : uniquement Paramètres
    remove_submenu_page('kophi-dashboard', 'kophi-settings');
    remove_submenu_page('kophi-dashboard', 'edit.php?post_type=kophi_menu_item');
    // Beats et Statistiques sont désormais accessibles à l'assistant/beatmaker
}, 999);

// ── 2. Rediriger l'assistant vers le tableau de bord KOPHI ───────
add_action('admin_init', function() {
    if (!kophi_is_assistant()) return;

    $screen       = get_current_screen();
    $current_page = sanitize_text_field($_GET['page'] ?? '');
    $current_action = sanitize_text_field($_GET['action'] ?? '');

    // Pages KOPHI autorisées
    $allowed_pages = [
        'kophi-dashboard', 'kophi-sessions', 'kophi-clients',
        'kophi-projets', 'kophi-planning', 'kophi-reservations',
        'kophi-tarifs', 'kophi-statistiques', 'kophi-beats',
    ];

    // Bases de pages autorisées (WP natif nécessaires)
    $allowed_bases = [
        'profile', 'profile-network', 'user-edit',
    ];

    // Écrans de création/édition autorisés uniquement pour le CPT kophi_beat
    $allowed_post_types = ['kophi_beat'];

    if (!$screen) {
        // Pas encore de screen → laisser passer (AJAX, etc.)
        return;
    }

    // Si on est sur le tableau de bord WP natif → rediriger vers KOPHI
    if ($screen->base === 'dashboard') {
        wp_redirect(admin_url('admin.php?page=kophi-dashboard'));
        exit;
    }

    // Si on est sur une page KOPHI → OK
    if ($current_page && in_array($current_page, $allowed_pages)) {
        return;
    }

    // Médiathèque — liste, ajout, édition d'un média (upload.php, media-new.php, post.php?action=edit sur un média) → OK
    if (in_array($screen->base, ['upload', 'media', 'async-upload'], true)) {
        return;
    }

    // Création ou édition d'un beat (post-new.php / post.php pour kophi_beat) → OK
    if (in_array($screen->base, ['post', 'edit'], true)) {
        $post_type = $screen->post_type ?? '';
        if (in_array($post_type, $allowed_post_types, true)) {
            return;
        }
        // Tout autre post_type (articles, pages, autres CPT KOPHI) → continue vers la redirection
    }

    // Si on est sur le profil ou une base autorisée → OK
    if (in_array($screen->base, $allowed_bases, true)) {
        return;
    }

    // Si pas de page spécifique (accès direct à /wp-admin/) → rediriger
    if (!$current_page && $screen->base === 'dashboard') {
        wp_redirect(admin_url('admin.php?page=kophi-dashboard'));
        exit;
    }

    // Toute autre page non autorisée → rediriger
    if ($current_page && !in_array($current_page, $allowed_pages)) {
        wp_redirect(admin_url('admin.php?page=kophi-dashboard'));
        exit;
    }
});

// ── 3. Après connexion → rediriger vers tableau de bord KOPHI ────
add_filter('login_redirect', function($redirect_to, $request, $user) {
    if (is_wp_error($user) || !$user) return $redirect_to;
    if (in_array('kophi_assistant', (array)$user->roles)) {
        // Rediriger directement vers la page KOPHI (évite /wp-admin/ intermédiaire)
        return admin_url('admin.php?page=kophi-dashboard');
    }
    return $redirect_to;
}, 10, 3);

// Empêcher WordPress d'afficher "Vous n'avez pas l'autorisation" pour l'assistant
// en autorisant explicitement l'accès à l'admin
add_filter('woocommerce_prevent_admin_access', '__return_false');
add_action('admin_init', function() {
    // Autoriser explicitement l'accès admin pour l'assistant KOPHI
    if (!kophi_is_assistant()) return;
    // WordPress peut bloquer l'accès via auth_redirect — on force l'autorisation
}, 1); // Priorité 1 = très tôt

// ── 4. CSS — masquer éléments inutiles dans l'admin ─────────────
add_action('admin_head', function() {
    if (!kophi_is_assistant()) return;
    echo '<style>
        /* Masquer barre admin supérieure sauf profil */
        #wp-admin-bar-new-content,
        #wp-admin-bar-comments,
        #wp-admin-bar-updates,
        #wp-admin-bar-site-editor,
        #wp-admin-bar-customize,
        #wp-admin-bar-themes,
        #wp-admin-bar-wp-logo > .ab-item > .ab-icon,
        #wp-admin-bar-wp-logo .ab-submenu { display:none !important; }
        /* Footer WP */
        #wpfooter { display:none !important; }
        /* Message de bienvenue */
        .welcome-panel { display:none !important; }
    </style>';
});

// ── 5. Fonction utilitaire ────────────────────────────────────────
function kophi_is_assistant(): bool {
    $user = wp_get_current_user();
    return $user && in_array('kophi_assistant', (array)$user->roles);
}
// ── Autoriser explicitement l'assistant à accéder aux pages admin KOPHI ──
add_filter('user_has_cap', function($allcaps, $caps, $args, $user) {
    if (!$user || !in_array('kophi_assistant', (array)$user->roles)) return $allcaps;
    // S'assurer que 'read' est toujours true pour accéder à l'admin
    $allcaps['read'] = true;
    return $allcaps;
}, 5, 4); // Priorité 5 = avant le filtre de blocage (priorité 10)


// ── 6. Aussi s'assurer que l'assistant ne peut pas éditer les options ─
add_filter('user_has_cap', function($caps, $cap, $args, $user) {
    if (!isset($user) || !in_array('kophi_assistant', (array)$user->roles)) return $caps;
    // Bloquer capabilities dangereuses
    $blocked = ['manage_options', 'edit_plugins', 'activate_plugins', 'install_plugins',
                'edit_themes', 'switch_themes', 'manage_network', 'edit_users',
                'create_users', 'delete_users', 'promote_users', 'remove_users'];
    foreach ($blocked as $b) {
        $caps[$b] = false;
    }
    return $caps;
}, 10, 4);


// ── Helper : vérifier rôle assistant sur le sous-site courant ────
function kophi_user_is_assistant_on_blog(int $uid): bool {
    global $wpdb;
    // Sur Multisite, les caps sont dans wp_{blog_id}_capabilities
    $cap_key = $wpdb->get_blog_prefix() . 'capabilities';
    $caps    = get_user_meta($uid, $cap_key, true);
    if (is_array($caps) && isset($caps['kophi_assistant'])) {
        return (bool)$caps['kophi_assistant'];
    }
    // Fallback : vérifier via WP_User avec switch_to_blog
    if (is_multisite()) {
        $blog_id = get_current_blog_id();
        $user    = new WP_User($uid, '', $blog_id);
        return in_array('kophi_assistant', (array)$user->roles);
    }
    $user = get_user_by('id', $uid);
    return $user && in_array('kophi_assistant', (array)$user->roles);
}

// ── AJAX — Changer le mot de passe d'un assistant ────────────────
add_action('wp_ajax_kophi_change_assistant_pass', function() {
    if (!check_ajax_referer('kophi_change_pass_ajax', 'nonce', false)) {
        wp_send_json_error('Session expirée, rechargez la page.');
    }
    if (!current_user_can('administrator')) {
        wp_send_json_error('Accès refusé.');
    }
    $uid      = (int)($_POST['uid'] ?? 0);
    $new_pass = sanitize_text_field($_POST['new_password'] ?? '');
    if (!$uid || !$new_pass) {
        wp_send_json_error('UID ou mot de passe manquant.');
    }
    $u = get_user_by('id', $uid);
    if (!$u || !kophi_user_is_assistant_on_blog($uid)) {
        wp_send_json_error('Utilisateur introuvable ou non assistant.');
    }
    wp_set_password($new_pass, $uid);
    // Notifier l'assistant par email
    wp_mail(
        $u->user_email,
        "Votre nouveau mot de passe — KOPHI'S MUSIC STUDIO",
        "Bonjour {$u->display_name},

Votre mot de passe a été modifié.

Nouveau mot de passe : {$new_pass}
URL : " . admin_url() . "

KOPHI'S MUSIC STUDIO",
        ['Content-Type: text/plain; charset=UTF-8']
    );
    wp_send_json_success('Mot de passe modifié et email envoyé.');
});

// ── AJAX — Supprimer un compte assistant ────────────────────────
add_action('wp_ajax_kophi_delete_assistant', function() {
    if (!check_ajax_referer('kophi_delete_assistant_' . (int)($_POST['uid'] ?? 0), 'nonce', false)) {
        wp_send_json_error('Nonce invalide.');
    }
    if (!current_user_can('administrator')) {
        wp_send_json_error('Acces refuse.');
    }
    $uid = (int)($_POST['uid'] ?? 0);
    if (!$uid) wp_send_json_error('UID invalide.');

    $u = get_user_by('id', $uid);
    if (!$u || !kophi_user_is_assistant_on_blog($uid)) {
        wp_send_json_error('Utilisateur introuvable ou non assistant.');
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';
    if (is_multisite()) {
        remove_user_from_blog($uid, get_current_blog_id());
        if (function_exists('wpmu_delete_user')) {
            wpmu_delete_user($uid);
        }
    } else {
        wp_delete_user($uid);
    }
    wp_cache_flush();
    clean_user_cache($uid);
    wp_send_json_success('Compte supprime.');
});
