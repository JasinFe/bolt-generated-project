<?php
// ============================================================
// KOPHI'S MUSIC — Importateur CSV TuneCore v2.2
// ============================================================
defined( 'ABSPATH' ) || exit;

// ── Encodage JSON sûr pour le stockage en post_meta ────────────
// ============================================================
// CAUSE RACINE DU BUG D'ENCODAGE (titres "Ju00c9SUS" au lieu de "JÉSUS") :
// WordPress applique automatiquement wp_unslash() — donc stripslashes() —
// à TOUTE valeur passée à update_post_meta(), avant de la stocker en base.
// Or json_encode() par défaut échappe les caractères accentués en notation
// "\u00E9", et stripslashes() détruit cet antislash, transformant
// littéralement "\u00e9" en "u00e9" dans le texte stocké — irréversible
// au moment de relire avec json_decode(), puisque ce n'est plus un JSON
// d'échappement valide. Ce n'est PAS un problème du CSV TuneCore : le
// fichier source est toujours en UTF-8 propre. La corruption est
// introduite par le plugin lui-même au moment de l'enregistrement.
//
// Solution : (1) json_encode avec JSON_UNESCAPED_UNICODE pour qu'il n'y
// ait jamais de séquence "\uXXXX" à perdre — les accents restent en UTF-8
// brut dans le JSON ; (2) wp_slash() avant l'appel à update_post_meta(),
// pour neutraliser le wp_unslash() interne et protéger aussi les
// guillemets structurels du JSON (un titre contenant lui-même un
// guillemet droit casserait sinon le JSON stocké).
if ( ! function_exists( 'km_safe_json_encode' ) ) {
    function km_safe_json_encode( $data ) {
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        if ( $json === false ) return '';
        return wp_slash( $json );
    }
}

// ── Lecture symétrique ──────────────────────────────────────────
// get_post_meta() ne re-slashe pas automatiquement, donc un simple
// json_decode() suffit à la lecture — mais on centralise ici pour
// que toute évolution future (ex: anciennes données encore "slashées"
// par un autre chemin) reste cohérente entre tous les fichiers du plugin.
if ( ! function_exists( 'km_safe_json_decode' ) ) {
    function km_safe_json_decode( $raw, $assoc = true ) {
        if ( $raw === '' || $raw === null || $raw === false ) return $assoc ? array() : null;
        $decoded = json_decode( $raw, $assoc );
        return $decoded;
    }
}

// ── Réparation des caractères accentués mal échappés ───────────
// ============================================================
// Certains rapports TuneCore (ou un traitement intermédiaire avant
// l'upload) contiennent des titres où l'échappement Unicode "\u00C9"
// a perdu son antislash, laissant littéralement "u00C9" collé au
// texte (ex: "Ju00c9SUS M'APPELLE" au lieu de "JÉSUS M'APPELLE",
// "LA RELu00c8VE" au lieu de "LA RELÈVE"). La fonction ci-dessous
// détecte ce motif précis et le convertit dans le vrai caractère
// accentué — uniquement quand le résultat est une lettre ou un
// symbole de ponctuation normal, jamais un caractère de contrôle,
// pour éviter tout faux positif sur un texte qui contiendrait par
// hasard "u" suivi de 4 chiffres hexadécimaux.
if ( ! function_exists( 'km_repair_lost_unicode_escapes' ) ) {
    function km_repair_lost_unicode_escapes( $text ) {
        if ( $text === '' || $text === null ) return $text;
        if ( strpos( $text, 'u' ) === false && strpos( $text, 'U' ) === false ) return $text;

        return preg_replace_callback( '/u([0-9a-fA-F]{4})/', function( $m ) {
            $codepoint = hexdec( $m[1] );

            // Liste blanche de plages Unicode "sûres" pour des titres de
            // musique : Latin-1 Supplement (lettres accentuées + symboles
            // courants), Latin Extended-A (œ, etc.) et ponctuation
            // typographique usuelle (apostrophes, tirets, guillemets).
            $safe_ranges = array(
                array( 0x00A0, 0x00FF ), // Latin-1 Supplement : é è ô ç ñ ü etc.
                array( 0x0100, 0x017F ), // Latin Extended-A : œ, etc.
                array( 0x2010, 0x2027 ), // tirets, apostrophes typographiques, guillemets
            );

            $is_safe = false;
            foreach ( $safe_ranges as $range ) {
                if ( $codepoint >= $range[0] && $codepoint <= $range[1] ) { $is_safe = true; break; }
            }
            if ( ! $is_safe ) return $m[0]; // on laisse intact si hors liste blanche

            // Conversion du point de code Unicode en caractère UTF-8.
            if ( function_exists( 'mb_convert_encoding' ) ) {
                return mb_convert_encoding( '&#' . $codepoint . ';', 'UTF-8', 'HTML-ENTITIES' );
            }
            // Repli si l'extension mbstring n'est pas disponible sur l'hébergement :
            // encodage UTF-8 manuel. Les plages Latin-1/Latin Extended-A tiennent
            // sur 2 octets, la ponctuation typographique (U+2010 et au-delà) sur 3.
            if ( $codepoint <= 0x7FF ) {
                return chr( 0xC0 | ( $codepoint >> 6 ) ) . chr( 0x80 | ( $codepoint & 0x3F ) );
            }
            return chr( 0xE0 | ( $codepoint >> 12 ) )
                 . chr( 0x80 | ( ( $codepoint >> 6 ) & 0x3F ) )
                 . chr( 0x80 | ( $codepoint & 0x3F ) );
        }, $text );
    }
}

// ── Réparation récursive d'une structure de données déjà en base ──
// Utilisée par l'outil d'admin "Réparer l'encodage" pour corriger les
// rapports déjà importés (catalogue, pistes), sans avoir à réimporter
// les CSV d'origine. Répare aussi bien les clés de tableau (titres
// utilisés comme index) que les valeurs textuelles.
if ( ! function_exists( 'km_repair_unicode_deep' ) ) {
    function km_repair_unicode_deep( $data, &$changed = null ) {
        if ( $changed === null ) { $local_changed = false; $changed =& $local_changed; }

        if ( is_array( $data ) ) {
            $repaired = array();
            foreach ( $data as $key => $value ) {
                $new_key = is_string( $key ) ? km_repair_lost_unicode_escapes( $key ) : $key;
                if ( $new_key !== $key ) $changed = true;
                $repaired[ $new_key ] = km_repair_unicode_deep( $value, $changed );
            }
            return $repaired;
        }
        if ( is_string( $data ) ) {
            $new_val = km_repair_lost_unicode_escapes( $data );
            if ( $new_val !== $data ) $changed = true;
            return $new_val;
        }
        return $data;
    }
}

// ── Découpage des artistes en featuring / collaboration ───────
// ============================================================
// TuneCore renvoie parfois la colonne "Artist" avec plusieurs noms
// d'artistes séparés par "&", "feat.", "ft.", "featuring", "with",
// "x" ou une virgule (ex: "Artiste A & Artiste B", "Artiste A feat.
// Artiste B"). Sans traitement, le plugin créerait une seule fiche
// TuneCore "Artiste A & Artiste B" non rattachée à aucun utilisateur
// WordPress réel. Les fonctions ci-dessous détectent ces cas et
// répartissent la ligne CSV vers CHAQUE artiste mentionné.
if ( ! function_exists( 'km_split_artist_names' ) ) {
    /**
     * Découpe une chaîne "Artist" TuneCore en plusieurs noms d'artistes.
     * Gère : &, feat., feat, ft., ft, featuring, with, x (mot isolé), virgule.
     * Normalise espaces multiples. Ne touche pas à la casse d'affichage.
     *
     * @param string $raw Nom brut tel que fourni par le CSV.
     * @return array Liste de noms d'artistes uniques, trim, non vides.
     */
    function km_split_artist_names( $raw ) {
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return array();

        // Séparateurs robustes, du plus "sûr" au plus ambigu.
        // - "&" et la virgule n'exigent pas d'espace strict autour.
        // - "feat"/"ft" tolèrent un point optionnel SANS espace après
        //   (ex: "feat.Jay Stone") tout en restant capturé dans le motif,
        //   pour ne pas laisser de point résiduel sur le morceau suivant.
        // - "featuring", "with", "x" exigent des espaces des deux côtés,
        //   pour ne jamais casser un nom d'artiste contenant ces lettres
        //   (ex: "DJ X", "X Ambassadors", "Xavier Dupont", "Dax").
        $pattern = '/\s*&\s*|\s*,\s*|\s+featuring\s+|\s+feat\.?\s*|\s+ft\.?\s*|\s+with\s+|\s+x\s+/i';

        $parts = preg_split( $pattern, $raw, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! $parts ) return array( $raw );

        $names = array();
        foreach ( $parts as $p ) {
            $p = trim( preg_replace( '/\s+/', ' ', $p ) );
            if ( $p === '' ) continue;
            if ( ! in_array( $p, $names, true ) ) $names[] = $p;
        }

        return $names ? $names : array( $raw );
    }
}

if ( ! function_exists( 'km_normalize_artist_key' ) ) {
    /**
     * Normalise un nom d'artiste pour servir de CLÉ de comparaison
     * (mapping, déduplication) — insensible à la casse et aux espaces.
     * L'affichage utilise toujours le nom original, pas cette clé.
     */
    function km_normalize_artist_key( $name ) {
        $name = trim( preg_replace( '/\s+/', ' ', (string) $name ) );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
    }
}

if ( ! function_exists( 'km_get_collab_split_mode' ) ) {
    /**
     * Détermine le mode de répartition pour un combo de collaboration
     * donné (ex: "Artiste A & Artiste B"), réglable au cas par cas
     * depuis KM Dashboard → Réglages → Collaborations.
     *
     * @param string $combo_key Clé normalisée du combo brut (nom complet avant split).
     * @return string 'equal' (répartition égale), 'full' (100% chacun) ou
     *                'custom' (répartition personnalisée par artiste + label,
     *                voir km_get_collab_custom_split()).
     */
    function km_get_collab_split_mode( $combo_key ) {
        $settings = get_option( 'km_collab_split_modes', array() );
        if ( isset( $settings[ $combo_key ] ) && in_array( $settings[ $combo_key ], array( 'equal', 'full', 'custom' ), true ) ) {
            return $settings[ $combo_key ];
        }
        return 'equal'; // Par défaut : répartition équitable, pour ne jamais sur-compter les gains du label.
    }
}

if ( ! function_exists( 'km_get_collab_custom_split' ) ) {
    /**
     * Répartition personnalisée (par artiste + label) définie pour un combo
     * de collaboration précis, depuis KM Dashboard → Collaborations, quand
     * le mode 'custom' est sélectionné pour ce combo.
     *
     * @param string $combo_key Clé normalisée du combo.
     * @return array|null array('label_pct'=>float, 'artists'=>array(user_id=>pct,...)) ou null.
     */
    function km_get_collab_custom_split( $combo_key ) {
        $splits = get_option( 'km_collab_custom_splits', array() );
        if ( isset( $splits[ $combo_key ] ) && ! empty( $splits[ $combo_key ]['artists'] ) ) {
            return $splits[ $combo_key ];
        }
        return null;
    }
}

if ( ! function_exists( 'km_importer_menu' ) ) {

add_action( 'admin_menu', 'km_importer_menu' );
function km_importer_menu() {
    add_submenu_page(
        'edit.php?post_type=rapport_mensuel',
        'Importer CSV TuneCore',
        '📥 Import TuneCore',
        'manage_options',
        'km-tunecore-import',
        'km_importer_page'
    );
}

function km_importer_page() {
    $results = null;
    if ( isset($_POST['km_import_nonce']) && wp_verify_nonce($_POST['km_import_nonce'],'km_import_csv') && isset($_FILES['tunecore_csv']) ) {
        $file = $_FILES['tunecore_csv'];
        if ( $file['error'] === UPLOAD_ERR_OK && strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)) === 'csv' ) {
            $results = km_process_csv($file['tmp_name']);
        } else {
            $results = array('error'=>'Fichier invalide. Utilisez un fichier .csv TuneCore.');
        }
    }
    ?>
    <div class="wrap">
        <h1>📥 Importateur TuneCore — KOPHI'S MUSIC</h1>
        <p style="color:#888;">Uploadez le rapport CSV mensuel depuis TuneCore. Données pays + catalogue importés automatiquement.</p>

        <?php if ($results && isset($results['error'])) : ?>
            <div class="notice notice-error"><p>❌ <?php echo esc_html($results['error']); ?></p></div>
        <?php elseif ($results) : ?>
            <div class="notice notice-success">
                <p>✅ Import terminé — <strong><?php echo intval($results['created']); ?> créés</strong>, <strong><?php echo intval($results['updated']); ?> mis à jour</strong>, <?php echo intval($results['skipped']); ?> ignorés.</p>
            </div>
            <table class="widefat striped" style="max-width:1000px;margin-top:16px;">
                <thead>
                    <tr><th>Statut</th><th>Artiste</th><th>Période</th><th>Streams</th><th>Gains Bruts (€)</th><th>Taux Label</th><th>Part Artiste (€)</th><th>Pays</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($results['details'] as $d) : ?>
                <tr>
                    <td><?php echo $d['action']==='created' ? '<span style="background:#d4edda;padding:2px 8px;border-radius:4px;color:#155724;font-size:.8em;">✅ Créé</span>' : '<span style="background:#fff3cd;padding:2px 8px;border-radius:4px;color:#856404;font-size:.8em;">🔄 MàJ</span>'; ?></td>
                    <td><?php echo esc_html($d['artist']); ?></td>
                    <td><?php echo esc_html($d['periode']); ?></td>
                    <td><?php echo number_format($d['streams'],0,',',' '); ?></td>
                    <td><?php echo number_format($d['gains'],4,',',''); ?> €</td>
                    <td><span style="background:#e8f0fe;padding:2px 8px;border-radius:4px;font-size:.8em;color:#1a56db;"><?php echo esc_html($d['taux_label']); ?>%</span></td>
                    <td><strong><?php echo number_format($d['part_artiste'],4,',',''); ?> €</strong></td>
                    <td style="font-size:.8em;color:#666;"><?php echo intval($d['nb_pays']); ?> pays</td>
                    <td><a href="<?php echo esc_url(get_edit_post_link($d['post_id'])); ?>" target="_blank" style="font-size:.8em;">Voir →</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:1000px;margin-top:24px;">
            <div style="background:#fff;border:1px solid #ccd0d4;padding:24px;border-radius:8px;">
                <h2 style="margin-top:0;font-size:1.1em;">Importer un rapport CSV</h2>
                <p style="color:#666;font-size:.9em;">Format : <code>tunecore-musicsales-sales_period-MM-YYYY.csv</code></p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('km_import_csv','km_import_nonce'); ?>
                    <div style="margin:16px 0;">
                        <label for="tunecore_csv" style="display:block;font-weight:600;margin-bottom:8px;">Fichier CSV TuneCore</label>
                        <input type="file" name="tunecore_csv" id="tunecore_csv" accept=".csv" required style="width:100%;" />
                    </div>
                    <p class="submit"><input type="submit" class="button button-primary button-large" value="📥 Lancer l'import" /></p>
                </form>
            </div>
            <div style="background:#f0f6fc;border-left:4px solid #0073aa;padding:20px;border-radius:0 8px 8px 0;">
                <h3 style="margin-top:0;font-size:1em;">⚙️ Mapping Artistes TuneCore</h3>
                <p style="color:#666;font-size:.85em;margin-bottom:12px;">
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=km-settings')); ?>">Réglages → KM Dashboard</a>
                </p>
                <?php
                $tc_artists = km_get_all_tunecore_artists();
                if ($tc_artists) {
                    foreach ($tc_artists as $tc_name) {
                        $uid  = km_get_artist_user_id($tc_name);
                        $u    = $uid ? get_user_by('id',$uid) : null;
                        $icon = $u ? '✅' : '⚠️';
                        $info = $u ? esc_html($u->display_name).' (ID '.$uid.')' : '<span style="color:#c00;">Non mappé</span>';
                        echo '<div style="margin-bottom:6px;font-size:.88em;"><code>'.esc_html($tc_name).'</code> → '.$icon.' '.$info.'</div>';
                    }
                } else {
                    echo '<p style="color:#888;font-size:.85em;">Importez d\'abord un CSV.</p>';
                }
                ?>
            </div>
        </div>
    </div>
    <?php
}

// ── Pays : code ISO → nom français ────────────────────────────
function km_country_name($code) {
    $map = array(
        'AE'=>'Émirats Arabes Unis','AO'=>'Angola','AR'=>'Argentine','BD'=>'Bangladesh',
        'BE'=>'Belgique','BG'=>'Bulgarie','BR'=>'Brésil','BY'=>'Biélorussie',
        'CA'=>'Canada','CD'=>'Congo (RDC)','CG'=>'Congo (Brazza)','CH'=>'Suisse',
        'CI'=>'Côte d\'Ivoire','CL'=>'Chili','CO'=>'Colombie','CZ'=>'Tchéquie',
        'DE'=>'Allemagne','DK'=>'Danemark','DO'=>'Rép. Dominicaine','EG'=>'Égypte',
        'ES'=>'Espagne','FR'=>'France','GB'=>'Royaume-Uni','GF'=>'Guyane française',
        'GP'=>'Guadeloupe','GR'=>'Grèce','HU'=>'Hongrie','ID'=>'Indonésie',
        'IL'=>'Israël','IN'=>'Inde','IT'=>'Italie','JP'=>'Japon',
        'KE'=>'Kenya','LK'=>'Sri Lanka','LT'=>'Lituanie','LU'=>'Luxembourg',
        'LV'=>'Lettonie','LY'=>'Libye','MA'=>'Maroc','MD'=>'Moldavie',
        'NG'=>'Nigéria','NL'=>'Pays-Bas','NO'=>'Norvège','PA'=>'Panama',
        'PH'=>'Philippines','PK'=>'Pakistan','PL'=>'Pologne','PT'=>'Portugal',
        'RE'=>'La Réunion','RO'=>'Roumanie','SE'=>'Suède','SN'=>'Sénégal',
        'TH'=>'Thaïlande','TR'=>'Turquie','TZ'=>'Tanzanie','UA'=>'Ukraine',
        'UG'=>'Ouganda','US'=>'États-Unis','ZA'=>'Afrique du Sud','ZM'=>'Zambie',
    );
    return isset($map[$code]) ? $map[$code] : $code;
}

// ── Traitement CSV ─────────────────────────────────────────────
function km_process_csv($file_path) {
    $handle = fopen($file_path,'r');
    if (!$handle) return array('error'=>'Impossible d\'ouvrir le fichier.');

    $headers = fgetcsv($handle);
    if (!$headers) { fclose($handle); return array('error'=>'Fichier CSV vide.'); }
    $headers = array_map('trim',$headers);
    $col     = array_flip($headers);

    $required = array('Sales Period','Artist','Store Name','Country Of Sale','# Units Sold','Total Earned','Release Title','Song Title','Release Type');
    foreach ($required as $req) {
        if (!isset($col[$req])) { fclose($handle); return array('error'=>'Colonne manquante : '.$req); }
    }

    $data    = array();   // period → artist → platform → {streams, gains}
    $country = array();   // period → artist → country → {streams, gains}
    $catalog = array();   // period → artist → release → {type, tracks, streams, gains}
    $tracks  = array();   // period → artist → song_title → {streams, gains, album, platform}

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 5) continue;

        $period       = trim($row[$col['Sales Period']]);
        $artist_raw   = km_repair_lost_unicode_escapes(trim($row[$col['Artist']]));
        $store        = trim($row[$col['Store Name']]);
        $country_code = trim($row[$col['Country Of Sale']]);
        $release_title= km_repair_lost_unicode_escapes(trim($row[$col['Release Title']]));
        $song_title   = km_repair_lost_unicode_escapes(trim($row[$col['Song Title']]));
        $release_type = trim($row[$col['Release Type']]);
        $upc          = isset($col['UPC']) ? trim($row[$col['UPC']]) : '';
        $opt_upc      = isset($col['Optional UPC']) ? trim($row[$col['Optional UPC']]) : '';
        $upc_final    = $upc ? $upc : $opt_upc;
        $isrc         = isset($col['ISRC']) ? trim($row[$col['ISRC']]) : '';
        $streams_raw  = max(0,(int)trim($row[$col['# Units Sold']]));
        $earned_raw   = (float)trim($row[$col['Total Earned']]);
        $platform     = km_map_platform($store);

        // ── Featuring / collaboration ──
        // "Artiste A & Artiste B" (ou "feat.", "ft.", "x", virgule, etc.)
        // est éclaté en plusieurs artistes distincts. Par défaut, chacun
        // reçoit une part égale (ou 100% si configuré en mode "full").
        //
        // Deux façons de définir une répartition personnalisée (% par
        // artiste + % label, au lieu d'un partage égal suivi d'une
        // commission séparée) — la première trouvée l'emporte :
        //  1. Par ISRC/Titre précis (KM Dashboard → Commissions par titre
        //     → mode "Multi-artistes") — pour un son en particulier.
        //  2. Par combo de collaboration (KM Dashboard → Collaborations
        //     → mode "Répartition personnalisée") — pour TOUS les sons
        //     de ce groupe d'artistes (ex: tout un EP en featuring).
        $artist_names_raw = km_split_artist_names( $artist_raw );
        $nb_artists        = count( $artist_names_raw );
        $combo_key         = km_normalize_artist_key( $artist_raw );
        $split_mode        = $nb_artists > 1 ? km_get_collab_split_mode( $combo_key ) : 'full';
        // Filet de sécurité : si le mode est 'custom' mais que la
        // répartition n'est finalement pas exploitable pour cette ligne
        // (voir plus bas), on retombe sur 'équitable' (1/N) plutôt que sur
        // '100% chacun', pour ne jamais risquer de compter les gains du
        // label en double par défaut.
        $factor            = ( $nb_artists > 1 && $split_mode !== 'full' ) ? ( 1 / $nb_artists ) : 1;

        // Certains noms détectés sur la ligne peuvent en réalité être le
        // LABEL lui-même (ex: "KOPHI'S MUSIC & Mdrick Oninni" — le label
        // crédité en co-artiste, marqué "🏷️ C'est le Label" depuis KM
        // Dashboard → Mapping Artistes). On les exclut du partage : leur
        // part (calculée avec le MÊME facteur que les vrais artistes, donc
        // sans gonfler ni réduire la part de ces derniers) n'est attribuée
        // à personne ici — elle reste implicitement dans la part du label,
        // au lieu de créer une fiche "artiste" fantôme jamais mappée ni
        // payée.
        $artist_names = array_values( array_filter( $artist_names_raw, function( $an ) {
            return ! km_is_label_tunecore_name( $an );
        } ) );

        // Résout, dans l'ordre de priorité, un éventuel jeu de pourcentages
        // personnalisés par nom d'artiste détecté sur cette ligne. Ne
        // s'applique que si CHAQUE artiste RÉEL de la ligne (hors label) est
        // mappé à un compte WordPress ET présent dans la répartition
        // trouvée — sinon on abandonne et on retombe sur le comportement
        // standard, pour ne jamais risquer un calcul avec des pourcentages
        // incomplets.
        $split_pct_by_name = array();
        $use_multi_split   = false;

        $candidate_split = km_get_track_multi_split( $isrc, $song_title );
        if ( ! $candidate_split && $split_mode === 'custom' ) {
            $candidate_split = km_get_collab_custom_split( $combo_key );
        }

        if ( $candidate_split && ! empty( $candidate_split['artists'] ) && $artist_names ) {
            $use_multi_split = true;
            foreach ( $artist_names as $an ) {
                $an_uid = km_get_artist_user_id( $an );
                if ( $an_uid && isset( $candidate_split['artists'][ $an_uid ] ) ) {
                    $split_pct_by_name[ $an ] = (float) $candidate_split['artists'][ $an_uid ];
                } else {
                    $use_multi_split = false;
                    break;
                }
            }
        }

        // On garde une trace du nom brut combiné (pour la page Réglages →
        // Collaborations), séparément de la liste des artistes individuels
        // — ici volontairement la liste FILTRÉE (sans le label), pour que
        // la colonne "Artistes détectés" de cette page ne liste que les
        // vrais artistes.
        if ($nb_artists > 1) {
            km_register_collab_combo($artist_raw, $artist_names);
        }

        // Tous les noms bruts (y compris le label) sont enregistrés comme
        // "nom TuneCore connu" pour qu'ils apparaissent sur la page Mapping
        // Artistes — c'est là que l'admin les marque "🏷️ C'est le Label".
        foreach ($artist_names_raw as $an_raw) {
            km_register_tunecore_artist($an_raw);
        }

        foreach ($artist_names as $artist) {
            $art_factor = $use_multi_split ? ( $split_pct_by_name[ $artist ] / 100 ) : $factor;
            $streams = ($art_factor === 1) ? $streams_raw : (int) round($streams_raw * $art_factor);
            $earned  = $earned_raw * $art_factor;

            // ── Data par plateforme ──
            if (!isset($data[$period][$artist][$platform])) {
                $data[$period][$artist][$platform] = array('streams'=>0,'gains'=>0.0);
            }
            $data[$period][$artist][$platform]['streams'] += $streams;
            $data[$period][$artist][$platform]['gains']   += $earned;

            // ── Data par pays ──
            if (!isset($country[$period][$artist][$country_code])) {
                $country[$period][$artist][$country_code] = array('streams'=>0,'gains'=>0.0);
            }
            $country[$period][$artist][$country_code]['streams'] += $streams;
            $country[$period][$artist][$country_code]['gains']   += $earned;

            // ── Catalogue sorties / pistes ──
            if (!isset($catalog[$period][$artist][$release_title])) {
                $catalog[$period][$artist][$release_title] = array(
                    'type'   => $release_type,
                    'upc'    => $upc_final,
                    'tracks' => array(),
                    'streams'=> 0,
                    'gains'  => 0.0,
                    'collab' => $nb_artists > 1 ? $artist_names : array(),
                );
            }
            $catalog[$period][$artist][$release_title]['streams'] += $streams;
            $catalog[$period][$artist][$release_title]['gains']   += $earned;
            if ($song_title && !in_array($song_title,$catalog[$period][$artist][$release_title]['tracks'],true)) {
                $catalog[$period][$artist][$release_title]['tracks'][] = $song_title;
            }

            // ── Streams / gains par piste ──
            if ($song_title) {
                if (!isset($tracks[$period][$artist][$song_title])) {
                    $tracks[$period][$artist][$song_title] = array('streams'=>0,'gains'=>0.0,'album'=>$release_title,'type'=>$release_type,'isrc'=>$isrc,'platform'=>array(),'collab'=>$nb_artists > 1 ? $artist_names : array(),'multi_split'=>false);
                }
                if ($isrc && empty($tracks[$period][$artist][$song_title]['isrc'])) {
                    $tracks[$period][$artist][$song_title]['isrc'] = $isrc;
                }
                $tracks[$period][$artist][$song_title]['streams'] += $streams;
                $tracks[$period][$artist][$song_title]['gains']   += $earned;
                // Marque le titre comme issu d'une répartition multi-artistes
                // personnalisée : le montant accumulé dans 'gains' est déjà
                // NET (la part label a été retirée de l'assiette globale au
                // moment du split), donc aucune commission supplémentaire ne
                // devra être appliquée dessus plus loin.
                if ($use_multi_split) {
                    $tracks[$period][$artist][$song_title]['multi_split'] = true;
                }
                if (!isset($tracks[$period][$artist][$song_title]['platform'][$platform])) {
                    $tracks[$period][$artist][$song_title]['platform'][$platform] = array('streams'=>0,'gains'=>0.0);
                }
                $tracks[$period][$artist][$song_title]['platform'][$platform]['streams'] += $streams;
                $tracks[$period][$artist][$song_title]['platform'][$platform]['gains']   += $earned;
            }
        }
    }
    fclose($handle);

    $platforms = array('spotify','apple','youtube','deezer','tidal');
    $results   = array('created'=>0,'updated'=>0,'skipped'=>0,'details'=>array());
    $mois = array(1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre');

    foreach ($data as $period => $artists_data) {
        $ts_date      = strtotime($period);
        $m            = (int)date('n',$ts_date);
        $y            = date('Y',$ts_date);
        $periode_label= (isset($mois[$m]) ? $mois[$m] : date('F',$ts_date)).' '.$y;

        foreach ($artists_data as $artist_name => $plats) {
            $user_id = km_get_artist_user_id($artist_name);

            // Calcul totaux — avec commission par titre si override defini
            $total_streams = 0;
            $total_gains   = 0.0;
            foreach ($plats as $pdata) {
                $total_streams += $pdata['streams'];
                $total_gains   += $pdata['gains'];
            }

            // ── Part artiste : toujours calculée titre par titre ────────────
            // (et jamais globalement) pour que :
            //  - les overrides ISRC/Titre (Commissions par titre) s'appliquent
            //  - les répartitions multi-artistes personnalisées, déjà nettes,
            //    ne subissent pas de commission supplémentaire
            //  - le détail par titre/sortie affiché sur les dashboards
            //    artiste et label (qui relit ces mêmes valeurs pré-calculées)
            //    corresponde EXACTEMENT au total du relevé, sans recalcul
            //    divergent utilisant une commission générique.
            // On stocke le résultat par titre (et on l'accumule par sortie
            // dans le catalogue) directement dans les tableaux $tracks et
            // $catalog, qui seront sauvegardés tels quels en meta plus bas.
            $artist_tracks = isset($tracks[$period][$artist_name]) ? $tracks[$period][$artist_name] : array();

            if ($artist_tracks) {
                $part_artiste = 0.0;
                foreach ($artist_tracks as $t_title => $t_data) {
                    $t_gains = isset($t_data['gains']) ? (float) $t_data['gains'] : 0.0;

                    if ( ! empty($t_data['multi_split']) ) {
                        // Répartition multi-artistes : la part label a déjà été
                        // retirée de l'assiette globale au moment de l'import —
                        // ce montant EST la part artiste finale pour ce titre.
                        $t_part = round($t_gains, 6);
                    } else {
                        $t_isrc = isset($t_data['isrc']) ? $t_data['isrc'] : '';
                        $t_part = km_calculate_part_track($t_gains, $user_id, $t_title, $t_isrc);
                    }

                    $tracks[$period][$artist_name][$t_title]['part_artiste'] = $t_part;
                    $part_artiste += $t_part;

                    // Accumulation au niveau de la sortie (release) du catalogue
                    $rel = isset($t_data['album']) ? $t_data['album'] : '';
                    if ($rel !== '' && isset($catalog[$period][$artist_name][$rel])) {
                        if ( ! isset($catalog[$period][$artist_name][$rel]['part_artiste']) ) {
                            $catalog[$period][$artist_name][$rel]['part_artiste'] = 0.0;
                        }
                        $catalog[$period][$artist_name][$rel]['part_artiste'] += $t_part;
                    }
                }
                $part_artiste = round($part_artiste, 6);
                // Taux effectif moyen pondere (pour affichage)
                $taux_label = $total_gains > 0 ? round(1 - ($part_artiste / $total_gains), 4) : km_get_artist_commission($user_id);
            } else {
                // Aucune piste détaillée pour cet artiste sur la période
                // (cas rare : CSV sans "Song Title") — fallback sur la
                // commission par défaut appliquée au total.
                $part_artiste = km_calculate_part_artiste($total_gains, $user_id);
                $taux_label   = km_get_artist_commission($user_id);
            }
            $pct_label    = round($taux_label * 100, 1);

            // Données pays pour cet artiste et cette période — tri par streams décroissants.
            // (arsort() ne fonctionne pas sur un tableau de tableaux : uasort() avec un
            // comparateur explicite est la seule méthode correcte ici.)
            $countries_data = isset($country[$period][$artist_name]) ? $country[$period][$artist_name] : array();
            uasort($countries_data, function($a,$b){ return $b['streams'] - $a['streams']; });

            // Catalogue pour cet artiste
            $catalog_data = isset($catalog[$period][$artist_name]) ? $catalog[$period][$artist_name] : array();

            // Trouver post existant.
            // IMPORTANT : quand l'artiste n'est pas mappé à un compte WordPress
            // (user_id = 0, cas très fréquent), il ne faut JAMAIS chercher
            // uniquement par "artiste_user_id = 0 ET période", car cela
            // matcherait le rapport de N'IMPORTE QUEL AUTRE artiste non mappé
            // de la même période — et écraserait son contenu (catalogue,
            // pistes, gains) avec celui de l'artiste en cours de traitement.
            // Le nom TuneCore doit donc toujours faire partie du filtre dès
            // que user_id = 0.
            if ( $user_id > 0 ) {
                $existing = get_posts(array(
                    'post_type'      => 'rapport_mensuel',
                    'post_status'    => 'any',
                    'fields'         => 'ids',
                    'posts_per_page' => 1,
                    'meta_query'     => array(
                        'relation' => 'AND',
                        array('key'=>'artiste_user_id','value'=>$user_id),
                        array('key'=>'periode','value'=>$periode_label),
                    ),
                ));
            } else {
                $existing = get_posts(array(
                    'post_type'=>'rapport_mensuel','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,
                    'meta_query'=>array('relation'=>'AND',
                        array('key'=>'artiste_user_id','value'=>0),
                        array('key'=>'tunecore_artist_name','value'=>$artist_name),
                        array('key'=>'periode','value'=>$periode_label),
                    ),
                ));
            }

            if ($existing) {
                $post_id = $existing[0];
                $results['updated']++;
                $action = 'updated';
            } else {
                $post_id = wp_insert_post(array(
                    'post_type'   => 'rapport_mensuel',
                    'post_title'  => $artist_name.' — '.$periode_label,
                    'post_status' => 'publish',
                ));
                $results['created']++;
                $action = 'created';
            }

            if (is_wp_error($post_id)) { $results['skipped']++; continue; }

            // Sauvegarder champs ACF
            update_field('artiste_user_id',      $user_id,           $post_id);
            update_field('tunecore_artist_name',  $artist_name,       $post_id);
            update_field('periode',               $periode_label,     $post_id);
            update_field('total_streams',         $total_streams,     $post_id);
            update_field('total_gains_brut',      round($total_gains,6), $post_id);
            update_field('part_artiste',          $part_artiste,      $post_id);
            update_field('notes_rapport',
                'Import TuneCore — '.date('d/m/Y H:i').' | Commission label : '.$pct_label.'%',
                $post_id
            );

            // Plateformes
            foreach ($platforms as $pkey) {
                $ps = isset($plats[$pkey]) ? $plats[$pkey]['streams'] : 0;
                $pg = isset($plats[$pkey]) ? $plats[$pkey]['gains']   : 0.0;
                update_field($pkey.'_streams', $ps,             $post_id);
                update_field($pkey.'_gains',   round($pg,6),    $post_id);
            }

            // Pays (stocké en meta JSON)
            update_post_meta($post_id, '_km_countries',  km_safe_json_encode($countries_data));
            // Catalogue (stocké en meta JSON)
            update_post_meta($post_id, '_km_catalog',    km_safe_json_encode($catalog_data));
            // Pistes (streams/gains par titre)
            $tracks_data = isset($tracks[$period][$artist_name]) ? $tracks[$period][$artist_name] : array();
            update_post_meta($post_id, '_km_tracks', km_safe_json_encode($tracks_data));
            // Fallback total_streams en post_meta
            update_post_meta($post_id, '_km_total_streams', $total_streams);
            update_post_meta($post_id, '_km_total_gains',   round($total_gains,6));
            update_post_meta($post_id, '_km_part_artiste',  $part_artiste);

            $results['details'][] = array(
                'action'       => $action,
                'artist'       => $artist_name,
                'periode'      => $periode_label,
                'streams'      => $total_streams,
                'gains'        => round($total_gains,6),
                'part_artiste' => $part_artiste,
                'taux_label'   => $pct_label,
                'nb_pays'      => count($countries_data),
                'post_id'      => $post_id,
            );
        }
    }
    return $results;
}

function km_map_platform($store) {
    $store = strtolower($store);
    if (strpos($store,'spotify') !== false)  return 'spotify';
    if (strpos($store,'apple')   !== false)  return 'apple';
    if (strpos($store,'itunes')  !== false)  return 'apple';
    if (strpos($store,'youtube') !== false)  return 'youtube';
    if (strpos($store,'deezer')  !== false)  return 'deezer';
    if (strpos($store,'tidal')   !== false)  return 'tidal';
    return sanitize_title($store);
}

function km_register_tunecore_artist($name) {
    $known = get_option('km_tunecore_artists',array());
    if (!in_array($name,$known,true)) { $known[] = $name; update_option('km_tunecore_artists',$known); }
}

function km_get_all_tunecore_artists() {
    return get_option('km_tunecore_artists',array());
}

// ── Registre des combos de collaboration détectés ──────────────
// Distinct de km_tunecore_artists (qui ne doit contenir que des
// artistes individuels, pour un mapping TuneCore → WordPress propre).
// Alimente la page Réglages → Collaborations.
if ( ! function_exists( 'km_register_collab_combo' ) ) {
    function km_register_collab_combo( $raw_name, $parts ) {
        $known = get_option( 'km_collab_combos', array() );
        $key   = km_normalize_artist_key( $raw_name );
        if ( ! isset( $known[ $key ] ) ) {
            $known[ $key ] = array( 'raw' => $raw_name, 'parts' => $parts );
            update_option( 'km_collab_combos', $known );
        }
    }
}

if ( ! function_exists( 'km_get_all_collab_combos' ) ) {
    function km_get_all_collab_combos() {
        return get_option( 'km_collab_combos', array() );
    }
}

} // end function_exists
