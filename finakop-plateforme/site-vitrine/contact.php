<?php
/**
 * FinaKop ERP — site vitrine : réception du formulaire « Nous contacter / devis ».
 *
 * Sécurité :
 *   • POST uniquement, et seulement depuis le site lui-même (en-tête Origin/Referer) ;
 *   • champ piège invisible et délai minimal de remplissage (robots) ;
 *   • limite de débit par adresse IP et limite globale (pas d'inondation de la boîte) ;
 *   • longueurs bornées, adresses vérifiées, retours à la ligne retirés des en-têtes
 *     (pas d'injection d'en-têtes) ; le visiteur ne reçoit AUCUN courriel automatique
 *     (le formulaire ne peut pas servir à envoyer des messages à un tiers) ;
 *   • chaque demande est aussi enregistrée HORS du dossier web (copie de secours).
 */

declare(strict_types=1);

/* ── Réglages ─────────────────────────────────────────────────────────── */
const DESTINATAIRE = 'supports@finakoperp.com';
const EXPEDITEUR   = 'supports@finakoperp.com';   // doit être une boîte du domaine (exigence de l'hébergeur)
const HOTES        = ['finakoperp.com', 'www.finakoperp.com'];
const MAX_PAR_IP   = 5;    // demandes par heure et par adresse IP
const MAX_GLOBAL   = 60;   // demandes par heure, toutes adresses confondues
const DELAI_MIN    = 4;    // secondes minimales entre l'affichage et l'envoi

/* Dossier de données hors web : ~/domains/finakoperp.com/vitrine-donnees */
$DONNEES = dirname(__DIR__) . '/vitrine-donnees';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$json = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function repondre(bool $ok, string $message, int $code = 200): void
{
    global $json;
    http_response_code($code);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: /' . ($ok ? 'merci.html' : '?erreur=1#contact'), true, 303);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /#contact', true, 303);
    exit;
}

/* Provenance : le formulaire doit venir du site. */
$source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$hote   = strtolower((string) parse_url($source, PHP_URL_HOST));
if ($source !== '' && !in_array($hote, HOTES, true)) {
    repondre(false, 'Origine refusée.', 403);
}

/* Robots : champ piège rempli, ou envoi trop rapide. */
if (trim((string) ($_POST['site_web'] ?? '')) !== '') {
    repondre(true, 'Merci.');   // on ne signale rien au robot
}
$t = (int) ($_POST['t'] ?? 0);
if ($t > 0 && (time() - $t) < DELAI_MIN) {
    repondre(false, 'Envoi trop rapide : merci de vérifier votre demande.', 429);
}

/* Lecture et nettoyage des champs. */
function champ(string $nom, int $max): string
{
    $v = $_POST[$nom] ?? '';
    if (!is_string($v)) { return ''; }
    $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
    return mb_substr($v, 0, $max);
}
function ligne(string $v): string { return trim(str_replace(["\r", "\n"], ' ', $v)); }

$d = [
    'nom'          => ligne(champ('nom', 100)),
    'entreprise'   => ligne(champ('entreprise', 120)),
    'email'        => ligne(champ('email', 150)),
    'telephone'    => ligne(champ('telephone', 30)),
    'fonction'     => ligne(champ('fonction', 80)),
    'ville'        => ligne(champ('ville', 80)),
    'type'         => ligne(champ('type', 40)),
    'activite'     => ligne(champ('activite', 80)),
    'edition'      => ligne(champ('edition', 40)),
    'societes'     => (string) max(1, min(50, (int) ($_POST['societes'] ?? 1))),
    'utilisateurs' => (string) max(1, min(100, (int) ($_POST['utilisateurs'] ?? 1))),
    'delai'        => ligne(champ('delai', 40)),
    'reprise'      => ligne(champ('reprise', 20)),
    'preference'   => ligne(champ('preference', 20)),
    'message'      => champ('message', 3000),
];
$mods = $_POST['modules'] ?? [];
$d['modules'] = is_array($mods)
    ? implode(', ', array_slice(array_map(fn($m) => ligne(mb_substr((string) $m, 0, 40)), array_filter($mods, 'is_string')), 0, 12))
    : '';
if ($d['utilisateurs'] === '100') { $d['utilisateurs'] = '100 et plus'; }

if ($d['nom'] === '' || $d['entreprise'] === '' || $d['telephone'] === '') {
    repondre(false, 'Merci de renseigner votre nom, votre entreprise et votre téléphone.', 422);
}
if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
    repondre(false, 'Adresse e-mail invalide.', 422);
}
if (!preg_match('/^[0-9 +().\-]{6,30}$/', $d['telephone'])) {
    repondre(false, 'Numéro de téléphone invalide.', 422);
}
if (($_POST['accord'] ?? '') !== '1') {
    repondre(false, 'Merci d\'accepter d\'être recontacté.', 422);
}

/* Limite de débit (fichier verrouillé, hors web). */
if (!is_dir($DONNEES)) { @mkdir($DONNEES, 0700, true); }
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '?');
$ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '?';
$fl = $DONNEES . '/debit.json';
$fh = @fopen($fl, 'c+');
if (!$fh) { error_log('FinaKop vitrine : limite de débit inactive (dossier ' . $DONNEES . ' non inscriptible)'); }
if ($fh) {
    flock($fh, LOCK_EX);
    $etat = json_decode((string) stream_get_contents($fh), true) ?: [];
    $maintenant = time();
    $etat = array_filter($etat, fn($l) => is_array($l));
    foreach ($etat as $k => $liste) {
        $etat[$k] = array_values(array_filter($liste, fn($ts) => $ts > $maintenant - 3600));
        if (!$etat[$k]) { unset($etat[$k]); }
    }
    $cle = hash('sha256', $ip);   // l'IP n'est pas stockée en clair
    $total = array_sum(array_map('count', $etat));
    if (count($etat[$cle] ?? []) >= MAX_PAR_IP || $total >= MAX_GLOBAL) {
        flock($fh, LOCK_UN); fclose($fh);
        repondre(false, 'Trop de demandes envoyées. Merci de réessayer plus tard, ou de nous écrire sur WhatsApp.', 429);
    }
    $etat[$cle][] = $maintenant;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($etat));
    flock($fh, LOCK_UN); fclose($fh);
    @chmod($fl, 0600);
}

/* Copie de secours (une ligne JSON par demande). */
$fichier = $DONNEES . '/demandes-' . date('Y-m') . '.jsonl';
$conserve = false !== @file_put_contents($fichier, json_encode(['date' => date('c')] + $d, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
if ($conserve) { @chmod($fichier, 0600); } else { error_log('FinaKop vitrine : copie de secours impossible dans ' . $DONNEES); }

/* Courriel à l'équipe. */
$sujet = sprintf('[FinaKop] %s — %s (%s)', $d['type'] ?: 'Demande', $d['entreprise'], $d['nom']);
$corps = "Nouvelle demande reçue depuis finakoperp.com\n"
    . str_repeat('─', 44) . "\n"
    . "Type de demande : {$d['type']}\n\n"
    . "Nom : {$d['nom']}\nEntreprise : {$d['entreprise']}\nFonction : {$d['fonction']}\n"
    . "E-mail : {$d['email']}\nTéléphone / WhatsApp : {$d['telephone']}\nVille / pays : {$d['ville']}\n"
    . "Préférence de contact : {$d['preference']}\n\n"
    . "Activité : {$d['activite']}\nÉdition envisagée : {$d['edition']}\n"
    . "Utilisateurs : {$d['utilisateurs']}\nSociétés : {$d['societes']}\nModules : {$d['modules']}\n"
    . "Démarrage : {$d['delai']}\nReprise de données : {$d['reprise']}\n\n"
    . "Message :\n{$d['message']}\n\n"
    . str_repeat('─', 44) . "\n"
    . 'WhatsApp direct : https://wa.me/' . preg_replace('/\D/', '', $d['telephone']) . "\n"
    . 'Reçue le ' . date('d/m/Y à H:i') . "\n";

$entetes = [
    'From: FinaKop - site <' . EXPEDITEUR . '>',
    'Reply-To: ' . mb_encode_mimeheader($d['nom'], 'UTF-8') . ' <' . $d['email'] . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: FinaKop-Vitrine',
];
$envoye = @mail(DESTINATAIRE, mb_encode_mimeheader($sujet, 'UTF-8'), $corps, implode("\r\n", $entetes), '-f' . EXPEDITEUR);

if (!$envoye) {
    error_log('FinaKop vitrine : envoi du courriel impossible' . ($conserve ? ', demande conservée dans ' . basename($fichier) : ''));
    if (!$conserve) {
        // Ni courriel ni copie : on ne prétend pas que la demande est partie.
        repondre(false, 'Votre demande n\'a pas pu être transmise.', 503);
    }
}
repondre(true, 'Merci, votre demande est bien partie.');
