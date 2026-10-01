<?php
/** Layout principal plein écran. @var string $__content @var string $title */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' );
$license = FKC_License::resolve();
$catalog = FKC_Modules::catalog();
$user    = FKC_Auth::user();
$cur     = function_exists( 'fkc_rel_path' ) ? fkc_rel_path() : trim( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?: '', '/' );
$fkc_soc_active = FKC_Tenant::current();
$fkc_soc_list   = FKC_Tenant::accessibles();
uasort( $catalog, function ( $a, $b ) { return $a['order'] <=> $b['order']; } );
/*
 * MODE UI — Simple / Professionnel / Expert.
 *
 * Le mode ne filtre QUE la présentation : les gardes d'accès (licence, droits,
 * rôles) restent exactement où elles étaient, quelques lignes plus bas. Un
 * écran masqué ici demeure atteignable par son adresse — c'est voulu : masquer
 * n'est pas interdire, et faire croire le contraire serait une faille.
 */
$fkc_mode    = class_exists( 'FKC_ModeUI' ) ? FKC_ModeUI::actif() : 'expert';
$fkc_mode_m  = class_exists( 'FKC_ModeUI' ) ? FKC_ModeUI::meta( $fkc_mode ) : array( 'pastille' => '', 'court' => '' );
$fkc_visible = function ( $route ) use ( $fkc_mode ) {
	return ! class_exists( 'FKC_ModeUI' ) || FKC_ModeUI::routeVisible( $route, $fkc_mode );
};
/*
 * DÉMARRAGE — la troisième question range des modules hors de vue. C'est,
 * comme le mode, une affaire de PRÉSENTATION : le module reste couvert par la
 * licence et ses données sont intactes. Le filtre vit donc ici, dans le
 * gabarit, et jamais dans un moteur.
 */
$fkc_retenu = function ( $code ) {
	return ! class_exists( 'FKC_Onboarding' ) || FKC_Onboarding::moduleRetenu( $code );
};
/*
 * MOTEUR UX — le menu du MÉTIER, avant la liste des modules.
 *
 * La barre latérale énumérait des modules : « Comptabilité », « Inventaire »,
 * « Caisse ». C'est l'organigramme du logiciel, pas celui du travail. Le
 * moteur UX produit d'abord ce que la personne FAIT — vendre, réserver,
 * encaisser, ouvrir un dossier — dans les mots de son métier.
 *
 * Ce menu vient EN PREMIER, et ce qu'il couvre disparaît de la liste des
 * modules — dans tous les modes depuis 1.642.0. Montrer les deux revenait à
 * dire deux fois la même chose, dans deux langues, à quelqu'un qui n'en parle
 * qu'une : « Comptabilité » et « Comptabilité », « Personnel » et « RH ».
 *
 * Ce que le menu métier NE couvre pas reste listé, en Professionnel comme en
 * Expert : le responsable veut ses raccourcis métier ET l'accès complet.
 */
$fkc_ux_menu = array();
$fkc_ux_fam  = array();
$fkc_barre   = array( 'alertes' => 0, 'gravite' => 'info' );
if ( class_exists( 'FKC_Ux' ) && $fkc_soc_active ) {
	try {
		$fkc_ux_menu = FKC_Ux::menu();
		$fkc_ux_fam  = FKC_Ux::famille();
	} catch ( \Throwable $e ) { $fkc_ux_menu = array(); }
}
if ( class_exists( 'FKC_UxContexte' ) && $fkc_soc_active ) {
	try {
		$fkc_c = FKC_UxContexte::compteurAlertes();
		$fkc_barre['alertes'] = (int) $fkc_c['total'];
		$fkc_barre['gravite'] = $fkc_c['danger'] > 0 ? 'danger' : ( $fkc_c['warn'] > 0 ? 'warn' : 'info' );
		// Une visite d'écran comptée une seule fois, ici : c'est le seul point
		// par lequel passent toutes les pages RENDUES — les points d'entrée
		// JSON et les redirections n'ont rien à faire dans les habitudes.
		FKC_UxContexte::noter( $cur );
	} catch ( \Throwable $e ) { /* le confort ne doit jamais casser un écran */ }
}
/*
 * Les modules et référentiels DÉJÀ couverts par le menu métier.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POURQUOI LA DÉDUPLICATION VAUT DANS TOUS LES MODES (1.642.0)
 *
 * La barre latérale empile trois listes construites séparément : le menu
 * métier, le catalogue des modules, le bloc Référentiels. Chacune est juste
 * prise isolément ; ensemble, elles proposaient la même porte plusieurs
 * fois. Un dossier Creative Suite affichait « Comptabilité », « Analytique »,
 * « Fiscalité », « Recouvrement », « Personnel » et « Clients » deux fois —
 * une fois nommés dans la langue du métier, une fois dans celle du logiciel.
 *
 * Le tri n'était fait qu'en Mode Simple. C'était le mode où la barre est déjà
 * la plus courte, donc celui où le doublon gênait le MOINS : la garde était
 * posée exactement à l'envers.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * CE QUE « COUVERT » VEUT DIRE, ET POURQUOI ÇA N'AMPUTE RIEN
 *
 * Un module est couvert dès qu'une entrée métier mène QUELQUE PART dans ce
 * module — pas seulement à son accueil. « Personnel » ouvre la liste des
 * employés plutôt que le hub RH : c'est la bonne porte pour qui vient
 * travailler, et le hub reste à un clic, la barre « Retour » de chaque écran
 * de module y ramenant (voir app/views/_modnav.php).
 *
 * Un module qu'AUCUNE entrée métier ne mentionne reste affiché : simplifier
 * ne doit jamais vouloir dire amputer, et c'était déjà la règle.
 */
$fkc_ux_couverts = array();   // premier segment de route → module couvert
$fkc_ux_routes   = array();   // route exacte → entrée déjà présente
foreach ( $fkc_ux_menu as $fkc_ux_e ) {
	$fkc_ux_r = trim( (string) $fkc_ux_e['route'], '/' );
	if ( '' === $fkc_ux_r ) { continue; }
	$fkc_ux_routes[ $fkc_ux_r ] = true;
	$fkc_ux_seg = strtok( $fkc_ux_r, '/' );
	if ( $fkc_ux_seg ) { $fkc_ux_couverts[ $fkc_ux_seg ] = true; }
}
/**
 * Une route est-elle déjà proposée par le menu métier ?
 *
 * Comparaison sur la route EXACTE : « Clients » du bloc Référentiels et
 * « Clients » du menu métier mènent tous deux à referentiels/clients, et
 * l'un des deux est de trop. En revanche « Fournisseurs », que le menu
 * métier ne porte pas, doit rester.
 */
$fkc_ux_deja = function ( $route ) use ( $fkc_ux_routes ) {
	return isset( $fkc_ux_routes[ trim( (string) $route, '/' ) ] );
};
$fkc_ux_simple = class_exists( 'FKC_ModeUI' ) && 'simple' === $fkc_mode && ! empty( $fkc_ux_menu );
?><!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="color-scheme" content="dark">
	<meta name="theme-color" content="#0A0F1C">
	<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
	<meta name="referrer" content="strict-origin-when-cross-origin">
	<meta name="csrf-token" content="<?= e( FKC_Csrf::token() ) ?>">
	<title><?= e( $title ?? 'FinaKop ERP Core' ) ?> · FinaKop ERP Core</title>
	<link rel="icon" type="image/png" href="<?= e( fkc_asset( 'img/logo-256.png' ) ) ?>">
	<?php if ( ! defined( 'FKC_PLATEFORME' ) ) : // Plateforme : aucune ressource tierce (vie privée, CSP). ?>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<?php endif; ?>
	<link rel="stylesheet" href="<?= e( fkc_asset( 'css/app.css' ) ) ?>?v=<?= e( FKC_VERSION ) ?>">
	<!-- CSRF automatique : doit s'exécuter AVANT tout script inline de vue. -->
	<script src="<?= e( fkc_asset( 'js/csrf.js' ) ) ?>?v=<?= e( FKC_VERSION ) ?>"></script>
</head>
<body class="fkc-mode-<?= e( $fkc_mode ) ?>">
<script>
/*
 * BARRE LATÉRALE REPLIÉE (1.773.0) — état restitué AVANT le rendu.
 *
 * Posé ici et non dans le script de bas de page : entre l'affichage et la
 * fin du document, l'utilisateur verrait la barre s'ouvrir puis se refermer
 * à chaque navigation.
 */
try { if ('1' === localStorage.getItem('fkc_side_replie')) { document.body.classList.add('side-collapsed'); } } catch (e) {}
</script>
<div class="app">
	<div class="side-overlay" id="fkcSideOverlay" hidden></div>
	<aside class="side" id="fkcSide">
		<div class="brand">
			<img class="brand-logo" src="<?= e( fkc_asset( 'img/logo.png' ) ) ?>" alt="FinaKop ERP Core">
			<div class="brand-txt">
				<span class="brand-name">FinaKop</span>
				<span class="brand-sub">ERP CORE</span>
				<?php
				$fkc_ed_lbl  = class_exists( 'FKC_Plans' ) ? FKC_Plans::editionLabel( $license['edition'] ?? 'core' ) : 'CORE';
				$fkc_ed_tier = $license['profile_label'] ?: '—';
				?>
				<span class="brand-edition <?= 'creative' === ( $license['edition'] ?? 'core' ) ? 'is-creative' : '' ?>" title="Édition <?= e( $fkc_ed_lbl . ' · ' . $fkc_ed_tier ) ?>">✦ <?= e( $fkc_ed_lbl ) ?></span>
				<span class="brand-tier" title="<?= e( $fkc_ed_tier ) ?>"><?= e( $fkc_ed_tier ) ?></span>
			</div>
		</div>

		<?php if ( $fkc_soc_active ) : ?>
		<a class="societe-box" href="<?= e( url( 'societes' ) ) ?>" title="Changer d'entité">
			<?php if ( FKC_Societe::hasLogo( $fkc_soc_active ) ) : ?>
				<span class="societe-avatar societe-avatar-img"><img src="<?= e( FKC_Societe::logoUrl( $fkc_soc_active ) ) ?>" alt=""></span>
			<?php else : ?>
				<span class="societe-avatar"><?= e( strtoupper( substr( $fkc_soc_active['sigle'] ?: $fkc_soc_active['raison_sociale'], 0, 2 ) ) ) ?></span>
			<?php endif; ?>
			<span class="societe-info">
				<span class="societe-label">Entité active</span>
				<span class="societe-name"><?= e( $fkc_soc_active['raison_sociale'] ) ?></span>
			</span>
			<?php if ( count( $fkc_soc_list ) > 1 ) : ?><span class="societe-switch">⇄</span><?php endif; ?>
		</a>
		<?php endif; ?>

		<nav class="nav">
			<a class="nav-item <?= ( '' === $cur ) ? 'active' : '' ?>" href="<?= e( url( '' ) ) ?>">
				<span class="nav-ico">▦</span> <span class="nav-lbl">Tableau de bord</span>
			</a>
			<?php if ( ! empty( $fkc_ux_menu ) ) : ?>
				<div class="nav-sep"><?= e( trim( ( $fkc_ux_fam['icone'] ?? '' ) . ' ' . ( $fkc_ux_fam['label'] ?? 'Mon métier' ) ) ) ?></div>
				<?php foreach ( $fkc_ux_menu as $fkc_ux_e ) : ?>
					<a class="nav-item <?= ( $cur === $fkc_ux_e['route'] || 0 === strpos( $cur, $fkc_ux_e['route'] . '/' ) ) ? 'active' : '' ?>"
						href="<?= e( url( $fkc_ux_e['route'] ) ) ?>" title="<?= e( $fkc_ux_e['sous'] ) ?>">
						<span class="nav-ico"><?= e( $fkc_ux_e['icone'] ) ?></span> <span class="nav-lbl"><?= e( $fkc_ux_e['label'] ) ?></span>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php
			/*
			 * Le titre « Modules » n'est écrit QU'APRÈS avoir su qu'il y a
			 * quelque chose dessous. Depuis que les doublons du menu métier
			 * sont retirés, la liste peut être vide — et un intitulé de
			 * section suivi de rien donne l'impression d'un écran cassé.
			 */
			$fkc_routes = array( 'comptabilite' => 'comptabilite/menu', 'analytique' => 'analytique/menu', 'analyse' => 'analyse/menu', 'facturation' => 'facturation/menu', 'fiscalite' => 'fiscalite/menu', 'recouvrement' => 'recouvrement/menu', 'inventaire' => 'inventaire/menu', 'scan' => 'scan/menu', 'caisse' => 'caisse/menu', 'rh' => 'rh/menu', 'paie' => 'paie/menu', 'royalties' => 'royalties/menu', 'api' => 'api-console' );
			/*
			 * Les modules de PACK MÉTIER, distingués des outils transverses :
			 * une édition Creative Suite en réunit plusieurs (Label musical,
			 * Studio…), et chacun est une activité que la barre doit montrer.
			 */
			$fkc_pack_routes = class_exists( 'FKC_Packs' ) ? FKC_Packs::navRoutes() : array();
			$fkc_routes = array_merge( $fkc_routes, $fkc_pack_routes );
			$fkc_sys_compta = array( 'comptabilite/comptes', 'comptabilite/immobilisations', 'comptabilite/etats-financiers', 'comptabilite/etats-dgi', 'comptabilite/extractions' );
			$fkc_mods = array();
			foreach ( $catalog as $code => $info ) :
				// Facturation est désormais intégrée à la Comptabilité (onglet Facturation) : on la masque ici.
				if ( 'facturation' === $code ) { continue; }
				/*
				 * UN SERVICE N'EST PAS UN MODULE DE LA LISTE (1.700.0).
				 *
				 * Il a sa propre section plus bas. Le laisser passer ici le
				 * ferait apparaître DEUX FOIS — et, faute de route dans
				 * $fkc_routes, la première entrée pointerait vers l'écran
				 * Licence : deux libellés identiques menant à deux endroits
				 * différents, dont un qui n'est pas le service.
				 */
				if ( ! empty( $info['service'] ) ) { continue; }
				// Inventaire masqué à la demande lorsqu'un pack métier couvre déjà
				// le catalogue (option désactivée par défaut — voir FKC_ArticleBridge).
				if ( 'inventaire' === $code && class_exists( 'FKC_ArticleBridge' ) && FKC_ArticleBridge::inventaireMasque() ) { continue; }
				$inLicense = in_array( $code, $license['modules'], true );
				// Module exclusif d'une gamme d'éditions (ex. Label musical → Creative) :
				// hors licence, il est masqué pour tout le monde, admin compris — on ne
				// « verrouille » en vitrine que les modules des paliers génériques.
				if ( ! $inLicense && ! empty( $info['exclusif'] ) ) { continue; }
				if ( ! FKC_Auth::isAdmin() && ( ! $inLicense || ! FKC_Auth::canSection( $code ) ) ) { continue; }
				// Mode UI : le module n'est présenté que si son écran d'entrée
				// appartient au mode courant (le droit, lui, reste intact).
				if ( ! $fkc_visible( $fkc_routes[ $code ] ?? $code ) ) { continue; }
				// Démarrage : module que l'entité a choisi de ne pas afficher.
				if ( ! $fkc_retenu( $code ) ) { continue; }
				/*
				 * DOUBLON AVEC LE MENU MÉTIER — dans tous les modes (1.642.0),
				 * mais JAMAIS AU POINT DE FAIRE DISPARAÎTRE UN MÉTIER (1.644.0).
				 *
				 * La règle de 1.642.0 masquait un module dès qu'une entrée
				 * métier menait quelque part dedans. Elle avait raison pour la
				 * Comptabilité — dont l'entrée métier ouvre exactement le même
				 * hub — et tort pour le Label musical : « Artistes » n'ouvre
				 * que le roster, alors que le module porte aussi le catalogue
				 * des œuvres, les contrats et les répartitions. Le pack
				 * disparaissait de la barre pendant que la licence l'affichait.
				 *
				 * Deux natures de modules, donc deux traitements :
				 *
				 *   • un module de PACK MÉTIER ou EXCLUSIF d'une gamme est une
				 *     ACTIVITÉ ENTIÈRE. Une entrée qui en cite un seul écran
				 *     ne le remplace pas, et une édition Creative Suite peut
				 *     réunir plusieurs de ces métiers : les masquer
				 *     reviendrait à cacher ce que le client a acheté. Ils ne
				 *     sortent que si le menu métier ouvre EXACTEMENT leur
				 *     porte d'entrée.
				 *
				 *   • un module TRANSVERSE (comptabilité, fiscalité, RH…) est
				 *     un outil : dès que le menu métier y mène, le nommer une
				 *     seconde fois dans la langue du logiciel n'ajoute rien.
				 */
				$fkc_mod_metier = ! empty( $info['exclusif'] ) || isset( $fkc_pack_routes[ $code ] );
				$fkc_mod_route  = isset( $fkc_routes[ $code ] ) ? trim( (string) $fkc_routes[ $code ], '/' ) : '';
				/*
				 * ─────────────────────────────────────────────────────────
				 * UN LANCEUR N'EST JAMAIS UN DOUBLON (1.652.0).
				 *
				 * La route d'un module est son LANCEUR — « analyse/menu »,
				 * « rh/menu » — c'est-à-dire la page qui liste TOUT ce que le
				 * module contient. Un raccourci métier, lui, ouvre UN écran.
				 * Les confondre fait disparaître la porte du module au profit
				 * d'une de ses pièces.
				 *
				 * C'est précisément ce qui arrivait au module Analyse : le
				 * menu métier porte un raccourci « Rapports » vers la route
				 * `analyse`, et le module s'en trouvait écarté — alors qu'il
				 * ouvre `analyse/menu`, d'où l'on atteint aussi financière,
				 * commerciale, clients, recouvrement. Sept écrans perdus pour
				 * un raccourci qui en ouvre un.
				 *
				 * Le défaut est le MÊME que celui réparé en 1.644.0 sur
				 * « Label musical », sous une autre forme : là c'était le
				 * recouvrement de module, ici c'est la comparaison du CODE du
				 * module à une route. `$fkc_ux_deja( $code )` compare un nom
				 * de module à une route : cela ne coïncide que par accident,
				 * et quand cela coïncide, ce n'est justement pas la porte du
				 * module.
				 */
				$fkc_mod_lanceur = $fkc_mod_route && preg_match( '#(^|/)menu$#', $fkc_mod_route );
				if ( ! empty( $fkc_ux_menu ) ) {
					// Même porte exactement : le doublon est réel dans tous les cas.
					if ( $fkc_mod_route && $fkc_ux_deja( $fkc_mod_route ) ) { continue; }
					// Simple recouvrement de module : ne vaut que pour les outils
					// transverses, et JAMAIS pour un lanceur — voir ci-dessus.
					if ( ! $fkc_mod_metier && ! $fkc_mod_lanceur ) {
						if ( isset( $fkc_ux_couverts[ $code ] ) ) { continue; }
						if ( $fkc_mod_route && isset( $fkc_ux_couverts[ strtok( $fkc_mod_route, '/' ) ] ) ) { continue; }
					}
				}
				$on   = $inLicense;
				$href = isset( $fkc_routes[ $code ] ) ? url( $fkc_routes[ $code ] ) : url( 'licence' );
				$act  = '';
				if ( 0 === strpos( $cur, $code ) ) {
					$act = 'active';
					if ( 'comptabilite' === $code ) {
						foreach ( $fkc_sys_compta as $sp ) { if ( 0 === strpos( $cur, $sp ) ) { $act = ''; break; } }
					}
				}
				$fkc_mods[] = array( 'on' => $on, 'href' => $href, 'act' => $act,
					'icon' => (string) $info['icon'], 'label' => (string) $info['label'] );
			endforeach;
			?>
			<?php if ( $fkc_mods ) : ?>
				<div class="nav-sep">Modules</div>
				<?php foreach ( $fkc_mods as $fkc_m ) : ?>
					<a class="nav-item <?= $fkc_m['on'] ? '' : 'locked' ?> <?= e( $fkc_m['act'] ) ?>"
					   href="<?= $fkc_m['on'] ? e( $fkc_m['href'] ) : '#' ?>"
					   title="<?= e( $fkc_m['label'] ) ?>" <?= $fkc_m['on'] ? '' : 'aria-disabled="true"' ?>>
						<span class="nav-ico"><?= e( $fkc_m['icon'] ) ?></span>
						<span class="nav-lbl"><?= e( $fkc_m['label'] ) ?></span>
						<?php if ( ! $fkc_m['on'] ) : ?><span class="lock">🔒</span><?php endif; ?>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php
			/*
			 * SERVICES — ce qui se vend à part des paliers.
			 *
			 * Ils ne sont PAS listés avec les modules : un module absent se
			 * montre cadenassé pour donner envie de monter de palier, alors
			 * qu'un service non souscrit ne doit pas apparaître du tout — il ne
			 * se débloque par aucune édition, et la promesse serait fausse.
			 */
			$fkc_services = array();
			/*
			 * MAESTRO N'ÉTAIT NULLE PART (corrigé en 1.715.0).
			 *
			 * Une vingtaine d'écrans existaient — priorités, prévisions,
			 * simulation, leviers, explications — et AUCUN lien n'y menait
			 * dans la barre. On ne pouvait y entrer que par le bouton
			 * « Copilot » de la barre supérieure, que rien ne désigne comme
			 * étant Maestro. Des mois de travail invisibles faute d'une
			 * ligne de menu : c'est le genre de défaut que personne ne
			 * signale, parce qu'on ne réclame pas ce qu'on ignore.
			 *
			 * Il entre parmi les SERVICES, au même titre que Connect : c'est
			 * ce qu'il est — une couche transverse, pas un module métier.
			 */
			if ( class_exists( 'FKC_Maestro' ) && FKC_Auth::can( 'analyse' ) && $fkc_visible( 'analyse/maestro' ) ) {
				$fkc_services[] = array( 'analyse/maestro', '🤖', 'Maestro' );
			}
			if ( class_exists( 'FKC_Connect_Droits' ) && FKC_Connect_Droits::serviceActif() && $fkc_visible( 'connect' ) ) {
				$fkc_services[] = array( 'connect', '💬', 'Connect' );
			}
			?>
			<?php if ( $fkc_services ) : ?>
				<div class="nav-sep">Services</div>
				<?php foreach ( $fkc_services as $fkc_sv ) : ?>
					<?php
					/* « maestro/... » doit allumer l'entrée « analyse/maestro » :
					   les vingt sous-écrans vivent sous une autre racine. */
					$fkc_sv_actif = ( 0 === strpos( $cur, $fkc_sv[0] ) )
						|| ( 'analyse/maestro' === $fkc_sv[0] && 0 === strpos( $cur, 'maestro' ) );
					?>
					<a class="nav-item <?= $fkc_sv_actif ? 'active' : '' ?>" href="<?= e( url( $fkc_sv[0] ) ) ?>">
						<span class="nav-ico"><?= e( $fkc_sv[1] ) ?></span> <span class="nav-lbl"><?= e( $fkc_sv[2] ) ?></span>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
			<?php
				$fkc_ref_ok = FKC_Auth::isAdmin()
					|| ( in_array( 'comptabilite', $license['modules'], true ) && FKC_Auth::can( 'comptabilite' ) )
					|| ( in_array( 'facturation', $license['modules'], true ) && FKC_Auth::can( 'facturation' ) );
				// « Items de facturation » relève du paramétrage : il suit le mode,
				// contrairement aux clients et fournisseurs, utiles à tous.
				$fkc_ref_items = $fkc_visible( 'referentiels/items' );
				/*
				 * Le menu métier porte souvent déjà « Clients » — sous ce nom
				 * ou sous celui du métier. Le répéter ici donnait deux entrées
				 * pour la même fiche, à deux endroits de la barre. On ne
				 * conserve que ce qu'il ne couvre pas ; si tout est couvert,
				 * la section entière disparaît plutôt que d'afficher un titre
				 * sans contenu.
				 */
				$fkc_ref_liens = array();
				if ( ! $fkc_ux_deja( 'referentiels/clients' ) ) {
					$fkc_ref_liens[] = array( 'referentiels/clients', '🧑‍💼', 'Clients' );
				}
				if ( ! $fkc_ux_deja( 'referentiels/fournisseurs' ) ) {
					$fkc_ref_liens[] = array( 'referentiels/fournisseurs', '🚚', 'Fournisseurs' );
				}
				if ( $fkc_ref_items && ! $fkc_ux_deja( 'referentiels/items' ) ) {
					$fkc_ref_liens[] = array( 'referentiels/items', '🏷️', 'Items de facturation' );
				}
				if ( $fkc_ref_ok && $fkc_ref_liens ) : ?>
				<div class="nav-sep">Référentiels</div>
				<?php foreach ( $fkc_ref_liens as $fkc_ref_l ) : ?>
					<a class="nav-item <?= ( 0 === strpos( $cur, $fkc_ref_l[0] ) ) ? 'active' : '' ?>" href="<?= e( url( $fkc_ref_l[0] ) ) ?>">
						<span class="nav-ico"><?= e( $fkc_ref_l[1] ) ?></span> <span class="nav-lbl"><?= e( $fkc_ref_l[2] ) ?></span>
					</a>
				<?php endforeach; ?>
				<?php endif; ?>
				<?php /* Bloc « Système » : entités, plan comptable, états, sécurité —
				   du paramétrage et de la technique comptable. Il disparaît en Mode
				   Simple, où il n'aurait aucun sens, et reste entier ailleurs. */ ?>
				<?php if ( $fkc_visible( 'societes' ) ) : ?>
				<div class="nav-sep">Système</div>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'societes' ) ) ? 'active' : '' ?>" href="<?= e( url( 'societes' ) ) ?>">
				<span class="nav-ico">🏢</span> <span class="nav-lbl">Entités</span>
				<?php if ( count( $fkc_soc_list ) > 1 ) : ?><span class="nav-count"><?= count( $fkc_soc_list ) ?></span><?php endif; ?>
			</a>
			<?php if ( in_array( 'comptabilite', $license['modules'], true ) || FKC_Auth::isAdmin() ) : ?>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'comptabilite/comptes' ) ) ? 'active' : '' ?>" href="<?= e( url( 'comptabilite/comptes' ) ) ?>">
				<span class="nav-ico">📚</span> <span class="nav-lbl">Plan comptable</span>
			</a>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'comptabilite/immobilisations' ) ) ? 'active' : '' ?>" href="<?= e( url( 'comptabilite/immobilisations' ) ) ?>">
				<span class="nav-ico">🏗️</span> <span class="nav-lbl">Immobilisations</span>
			</a>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'comptabilite/etats-financiers' ) ) ? 'active' : '' ?>" href="<?= e( url( 'comptabilite/etats-financiers' ) ) ?>">
				<span class="nav-ico">📑</span> <span class="nav-lbl">États financiers</span>
			</a>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'comptabilite/etats-dgi' ) ) ? 'active' : '' ?>" href="<?= e( url( 'comptabilite/etats-dgi' ) ) ?>">
				<span class="nav-ico">🧾</span> <span class="nav-lbl">État DGI</span>
			</a>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'comptabilite/extractions' ) ) ? 'active' : '' ?>" href="<?= e( url( 'comptabilite/extractions' ) ) ?>">
				<span class="nav-ico">📤</span> <span class="nav-lbl">Extractions</span>
			</a>
			<?php endif; ?>
			<?php if ( FKC_Auth::isAdmin() ) : ?>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'utilisateurs' ) ) ? 'active' : '' ?>" href="<?= e( url( 'utilisateurs' ) ) ?>">
				<span class="nav-ico">👤</span> <span class="nav-lbl">Utilisateurs</span>
			</a>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'securite' ) ) ? 'active' : '' ?>" href="<?= e( url( 'securite' ) ) ?>">
				<span class="nav-ico">🛡️</span> <span class="nav-lbl">Sécurité</span>
			</a>
			<?php endif; ?>

			<?php endif; /* fin du bloc Système */ ?>

			<div class="nav-sep">Paramètres</div>
			<?php
			/*
			 * DOUBLON « PARAMÈTRES » (1.651.0).
			 *
			 * Quand la troisième question du démarrage place « Paramètres »
			 * parmi les raccourcis, la barre le portait DEUX FOIS : une fois
			 * en raccourci, une fois par ce lien-ci. Le contrôle B-1 le
			 * signalait depuis 1.642.0.
			 *
			 * La règle de déduplication existait déjà — `$fkc_ux_deja` — mais
			 * n'était appliquée qu'à la boucle des modules ; ce lien-là, écrit
			 * en dur, y échappait. On l'y soumet.
			 *
			 * L'ORDRE COMPTE, ET C'EST LE RACCOURCI QUI GAGNE : il est plus
			 * haut dans la barre et l'utilisateur l'a choisi lui-même au
			 * démarrage. Retirer le raccourci au profit du lien fixe défairait
			 * un réglage explicite.
			 */
			if ( $fkc_visible( 'parametres' ) && ! $fkc_ux_deja( 'parametres' ) ) : ?>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'parametres' ) ) ? 'active' : '' ?>" href="<?= e( url( 'parametres' ) ) ?>">
				<span class="nav-ico">⚙️</span> <span class="nav-lbl">Tous les paramètres</span>
			</a>
			<?php endif; ?>
			<?php if ( class_exists( 'FKC_ModeUI' ) ) : ?>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'parametres/mode' ) ) ? 'active' : '' ?>" href="<?= e( url( 'parametres/mode' ) ) ?>">
				<span class="nav-ico"><?= e( $fkc_mode_m['pastille'] ?? '🎛️' ) ?></span> <span class="nav-lbl">Mode d'affichage</span>
			</a>
			<?php endif; ?>
			<?php if ( class_exists( 'FKC_Ux' ) && $fkc_soc_active && FKC_Ux::capacite( 'ux.profil' ) ) : ?>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'parametres/ux' ) ) ? 'active' : '' ?>" href="<?= e( url( 'parametres/ux' ) ) ?>">
				<span class="nav-ico">🎨</span> <span class="nav-lbl">Profil UX</span>
			</a>
			<?php endif; ?>
			<?php if ( class_exists( 'FKC_Onboarding' ) ) : ?>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'demarrage' ) ) ? 'active' : '' ?>" href="<?= e( url( 'demarrage' ) ) ?>">
				<span class="nav-ico">🚀</span> <span class="nav-lbl">Démarrage</span>
			</a>
			<?php endif; ?>
			<a class="nav-item <?= ( 'licence' === $cur ) ? 'active' : '' ?>" href="<?= e( url( 'licence' ) ) ?>">
				<span class="nav-ico">🔑</span> <span class="nav-lbl">Licence</span>
			</a>
			<a class="nav-item <?= ( 0 === strpos( $cur, 'aide' ) ) ? 'active' : '' ?>" href="<?= e( url( 'aide' ) ) ?>">
				<span class="nav-ico">❓</span> <span class="nav-lbl">Aide &amp; documentation</span>
			</a>
		<script>
		/*
		 * BARRE LATÉRALE EN ACCORDÉON.
		 *
		 * ─────────────────────────────────────────────────────────────────
		 * POURQUOI EN JAVASCRIPT, ET NON EN PHP
		 *
		 * Les groupes de la barre sont écrits par six blocs PHP distincts,
		 * chacun avec ses conditions de licence, de rôle et de mode. Les
		 * enfermer dans des balises ouvrantes et fermantes aurait supposé
		 * que chaque bloc sait où il commence et où il finit — et le jour où
		 * l'un d'eux se termine dans une branche conditionnelle, la barre se
		 * casse en silence.
		 *
		 * Le script regroupe donc APRÈS COUP : chaque intitulé et les liens
		 * qui le suivent, jusqu'au prochain intitulé. Un septième groupe
		 * ajouté demain sera replié sans qu'on ait rien à faire.
		 *
		 * ─────────────────────────────────────────────────────────────────
		 * SANS SCRIPT, TOUT RESTE VISIBLE
		 *
		 * La barre fonctionne telle quelle : le script ne fait qu'ajouter le
		 * pliage. Un navigateur qui l'exécute mal laisse un menu complet,
		 * jamais un menu vide — c'est l'inverse qui serait grave.
		 *
		 * ─────────────────────────────────────────────────────────────────
		 * TOUT EST OUVERT À LA PREMIÈRE VISITE
		 *
		 * Replier d'office ferait disparaître, sans prévenir, des entrées
		 * que l'utilisateur a sous les yeux depuis des mois — il conclurait
		 * à une perte de droits. C'est LUI qui replie ce qu'il ne regarde
		 * pas, et son choix est retenu par groupe. Le groupe de l'écran
		 * courant est toujours rouvert : se retrouver sur une page dont
		 * l'entrée est cachée est la meilleure façon de se croire perdu.
		 */
		(function () {
			var nav = document.querySelector('.nav');
			if (!nav) { return; }
			var CLE = 'fkc_nav_replie';
			var replies = {};
			try { replies = JSON.parse(localStorage.getItem(CLE) || '{}') || {}; } catch (e) { replies = {}; }

			var seps = Array.prototype.slice.call(nav.querySelectorAll('.nav-sep'));
			seps.forEach(function (sep) {
				var titre = (sep.textContent || '').trim();
				if (!titre) { return; }

				/* On rassemble ce qui suit l'intitulé, jusqu'au suivant. */
				var corps = document.createElement('div');
				corps.className = 'nav-groupe-corps';
				var n = sep.nextSibling, actif = false;
				while (n) {
					var suiv = n.nextSibling;
					if (n.nodeType === 1 && n.classList.contains('nav-sep')) { break; }
					if (n.nodeType === 1 && n.classList.contains('active')) { actif = true; }
					corps.appendChild(n);
					n = suiv;
				}
				if (!corps.childNodes.length) { return; }
				sep.parentNode.insertBefore(corps, sep.nextSibling);

				/* L'intitulé devient un bouton : un titre sur lequel on clique
				   sans qu'il en ait l'air laisse l'utilisateur ignorer qu'il
				   peut replier. */
				sep.setAttribute('role', 'button');
				sep.setAttribute('tabindex', '0');
				sep.classList.add('nav-sep-pliable');
				var fleche = document.createElement('span');
				fleche.className = 'nav-chevron';
				fleche.setAttribute('aria-hidden', 'true');
				sep.appendChild(fleche);

				var replie = !actif && !!replies[titre];
				function rendre() {
					sep.classList.toggle('is-replie', replie);
					corps.hidden = replie;
					sep.setAttribute('aria-expanded', replie ? 'false' : 'true');
				}
				function basculer() {
					replie = !replie;
					replies[titre] = replie;
					try { localStorage.setItem(CLE, JSON.stringify(replies)); } catch (e) {}
					rendre();
				}
				sep.addEventListener('click', basculer);
				sep.addEventListener('keydown', function (ev) {
					if ('Enter' === ev.key || ' ' === ev.key) { ev.preventDefault(); basculer(); }
				});
				rendre();
			});
		})();
		</script>
		</nav>
	</aside>

	<div class="main">
		<header class="topbar">
			<button type="button" class="menu-toggle" id="fkcMenuToggle" aria-label="Ouvrir le menu" aria-controls="fkcSide" aria-expanded="false">☰</button>
			<button type="button" class="side-collapse" id="fkcSideCollapse" aria-controls="fkcSide" aria-expanded="true"
				title="Replier la barre latérale (Ctrl + B)" aria-label="Replier la barre latérale">«</button>
			<h1 class="page-title"><?= e( $title ?? '' ) ?></h1>
			<div class="topbar-right">
				<?php if ( $fkc_soc_active && count( $fkc_soc_list ) > 1 ) : ?>
				<form method="post" action="<?= e( url( 'societes/0/activer' ) ) ?>" class="soc-switch" id="socSwitchForm">
					<?= FKC_Csrf::field() ?>
					<select id="socSwitch" onchange="if(this.value){document.getElementById('socSwitchForm').action='<?= e( rtrim( url(''), '/' ) ) ?>/societes/'+this.value+'/activer';this.form.submit();}">
						<?php foreach ( $fkc_soc_list as $so ) : ?>
							<option value="<?= e( $so['id'] ) ?>" <?= (int) $so['id'] === (int) $fkc_soc_active['id'] ? 'selected' : '' ?>><?= e( $so['raison_sociale'] ) ?></option>
						<?php endforeach; ?>
					</select>
				</form>
				<?php endif; ?>
				<?php
				/* FinaKop Connect : la pastille ne compte QUE les conversations dont
				   l'utilisateur est membre. Compter tous les canaux de la société en
				   ferait un chiffre qu'on apprend à ignorer. */
				if ( $fkc_soc_active && class_exists( 'FKC_Connect' ) && ! empty( $user['id'] ) ) :
					$fkc_cx = 0; try { $fkc_cx = FKC_Connect::nonLus( (int) $user['id'] ); } catch ( \Throwable $e ) {}
					if ( class_exists( 'FKC_Connect_Droits' ) && FKC_Connect_Droits::serviceActif() ) : ?>
				<a class="notif-bell" href="<?= e( url( 'connect' ) ) ?>" title="FinaKop Connect" aria-label="Connect">💬<?php if ( $fkc_cx ) : ?><span class="notif-badge"><?= $fkc_cx > 99 ? '99+' : (int) $fkc_cx ?></span><?php endif; ?></a>
				<?php endif; endif; ?>
				<?php if ( $fkc_soc_active && class_exists( 'FKC_Notification' ) && ! empty( $user['id'] ) ) : $fkc_nunread = 0; try { $fkc_nunread = FKC_Notification::unreadCount( (int) $user['id'] ); } catch ( \Throwable $e ) {} ?>
				<a class="notif-bell" href="<?= e( url( 'notifications' ) ) ?>" title="Notifications" aria-label="Notifications">🔔<?php if ( $fkc_nunread ) : ?><span class="notif-badge"><?= $fkc_nunread > 99 ? '99+' : (int) $fkc_nunread ?></span><?php endif; ?></a>
				<?php endif; ?>
				<?php if ( $fkc_soc_active && class_exists( 'FKC_Validation' ) && FKC_Auth::isAdmin() ) : $fkc_vpend = 0; try { $fkc_vpend = FKC_Validation::countPending(); } catch ( \Throwable $e ) {} ?>
				<a class="notif-bell" href="<?= e( url( 'validations' ) ) ?>" title="À valider" aria-label="À valider">✔️<?php if ( $fkc_vpend ) : ?><span class="notif-badge"><?= $fkc_vpend > 99 ? '99+' : (int) $fkc_vpend ?></span><?php endif; ?></a>
				<?php endif; ?>
				<?php if ( class_exists( 'FKC_ModeUI' ) ) : ?>
				<?php /* Bascule de mode : présente sur TOUS les écrans et dans TOUS les
				   modes. C'est la sortie de secours — sans elle, l'utilisateur placé en
				   Mode Simple n'aurait pas une interface simplifiée mais amputée. */ ?>
				<form method="post" action="<?= e( url( 'mode' ) ) ?>" class="mode-switch" id="fkcModeForm">
					<?= FKC_Csrf::field() ?>
					<input type="hidden" name="retour" value="<?= e( (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) ?>">
					<label class="mode-switch-lbl" for="fkcModeSel" title="Mode d'affichage — <?= e( $fkc_mode_m['devise'] ?? '' ) ?>"><?= e( $fkc_mode_m['pastille'] ?? '' ) ?></label>
					<select id="fkcModeSel" name="mode" onchange="document.getElementById('fkcModeForm').submit()">
						<?php foreach ( FKC_ModeUI::modes() as $mc => $mm ) : ?>
							<option value="<?= e( $mc ) ?>" <?= $mc === $fkc_mode ? 'selected' : '' ?>><?= e( $mm['court'] ) ?></option>
						<?php endforeach; ?>
					</select>
				</form>
				<?php endif; ?>
				<span class="lic-dot lic-<?= e( $license['status'] ) ?>" title="<?= e( $license['message'] ) ?>"></span>
				<span class="user"><?= e( $user['nom_complet'] ?? 'Utilisateur' ) ?></span>
				<form method="post" action="<?= e( url( 'logout' ) ) ?>" class="logout-form">
					<?= FKC_Csrf::field() ?>
					<button class="btn-logout" type="submit" title="Déconnexion" aria-label="Déconnexion"><span class="lo-txt">Déconnexion</span><span class="lo-ico" aria-hidden="true">⏻</span></button>
				</form>
			</div>
		</header>
		<?php
		/* Barre transversale : présente sur tous les écrans et dans tous les
		   modes, exactement comme la bascule de mode. Sa constance est sa
		   fonction — un utilisateur qui doit chercher où chercher a déjà perdu. */
		if ( $fkc_soc_active && class_exists( 'FKC_Ux' ) && FKC_Ux::capacite( 'ux.barre' ) ) {
			$fkc_barre['actions']   = FKC_Ux::capacite( 'ux.actions' );
			$fkc_barre['recherche'] = FKC_Ux::capacite( 'ux.recherche' );
			$fkc_barre['panneau_alertes'] = FKC_Ux::capacite( 'ux.alertes' );
			$fkc_barre['copilot']   = FKC_Ux::capacite( 'ux.copilot' );
			FKC_View::partial( '_barre', array( 'fkc_barre' => $fkc_barre ) );
		}
		?>
		<main class="content">
			<?php
			// Dossier d'un client ouvert depuis le pack Cabinet (1.803.0).
			if ( $fkc_soc_active && class_exists( 'FKC_CabinetRetourController' ) && ( $fkc_cab_ctx = FKC_CabinetRetourController::contexte() ) ) {
				FKC_View::partial( '_cabinet_dossier', array( 'ctx' => $fkc_cab_ctx ) );
			}
			/*
			 * Invitation au démarrage. Elle ne s'affiche que pour un
			 * administrateur, hors du parcours lui-même, et disparaît dès qu'il
			 * a répondu OU explicitement écarté. Un bandeau qu'on ne peut pas
			 * faire taire est une nuisance, pas une invitation.
			 */
			if ( class_exists( 'FKC_Onboarding' ) && $fkc_soc_active && FKC_Auth::isAdmin()
				&& ! FKC_Onboarding::estFait() && 0 !== strpos( $cur, 'demarrage' ) ) : ?>
				<div class="onb-invite">
					<span class="onb-invite-ico" aria-hidden="true">🚀</span>
					<span class="onb-invite-txt"><strong>Réglons votre espace en deux minutes.</strong>
						Votre métier est déjà activé par votre licence&nbsp;: il reste à dire comment vous
						travaillez, ce que vous voulez voir et quel est votre rôle.</span>
					<a class="btn btn-primary btn-sm" href="<?= e( url( 'demarrage' ) ) ?>">Commencer</a>
					<form method="post" action="<?= e( url( 'demarrage/ecarter' ) ) ?>" style="display:inline">
						<?= FKC_Csrf::field() ?>
						<button type="submit" class="onb-invite-non">Non merci</button>
					</form>
				</div>
			<?php endif; ?>
			<?php
			/*
			 * RÉGIME FISCAL NON DÉCLARÉ (1.844.0) — bandeau qu'on ne ferme pas :
			 * tant que le régime manque, caisse et facturation sont à l'arrêt,
			 * et l'utilisateur doit savoir pourquoi avant de chercher ailleurs.
			 */
			if ( $fkc_soc_active && class_exists( 'FKC_FiscalConfig' ) && ! FKC_FiscalConfig::regimeDeclare() ) : ?>
				<div class="alert alert-warn" role="alert" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
					<span><strong>Régime fiscal non déclaré.</strong> Il décide si l'entité collecte la TVA ; tant qu'il manque, la caisse ne s'ouvre pas et aucune facture n'est émise.</span>
					<?php if ( FKC_Auth::isAdmin() || FKC_Auth::can( 'fiscalite' ) ) : ?>
						<a class="btn btn-primary btn-sm" href="<?= e( url( FKC_FiscalConfig::urlDeclaration() ) ) ?>">Déclarer le régime</a>
					<?php else : ?>
						<span class="muted" style="font-size:12.5px">Demandez à l'administrateur de le déclarer.</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php
			/*
			 * PACK AMPUTÉ — LE DIRE PLUTÔT QUE DE LAISSER CHERCHER (1.648.0).
			 *
			 * `FKC_Packs::boot()` charge le `hooks.php` de chaque pack dans un
			 * try/catch, et c'est la bonne décision : un pack qui échoue doit
			 * coûter SON pack, pas l'application entière.
			 *
			 * Mais l'incident était noté dans `hooksEnEchec()` et affiché NULLE
			 * PART. Le produit continuait donc à tourner, l'utilisateur voyait
			 * son module dans la barre latérale, ses écrans s'ouvraient — et
			 * seuls disparaissaient, sans un mot, les indicateurs, les KPI du
			 * cockpit, les alertes métier et l'analyseur de l'assistant.
			 *
			 * C'est exactement ce qui s'est produit en 1.644.0 : une erreur de
			 * syntaxe dans `Packs/studio/hooks.php` était avalée ici, et le
			 * pack Studio tournait amputé sans que rien ne le signale. Un
			 * diagnostic qu'on enregistre sans jamais le montrer ne sert
			 * qu'à celui qui lit le code.
			 *
			 * Réservé aux administrateurs : c'est une panne d'installation, pas
			 * une information utile au caissier.
			 */
			$fkc_packs_ko = class_exists( 'FKC_Packs' ) ? FKC_Packs::hooksEnEchec() : array();
			?>
			<?php if ( $fkc_packs_ko && class_exists( 'FKC_Auth' ) && FKC_Auth::isAdmin() ) : ?>
				<div class="alert alert-err" style="margin-bottom:16px">
					<strong>Un pack métier n'a pas pu se charger entièrement.</strong>
					Ses écrans restent accessibles, mais ses indicateurs, ses alertes et ses KPI du cockpit sont absents.
					<ul style="margin:8px 0 0;padding-left:20px">
						<?php foreach ( $fkc_packs_ko as $fkc_code => $fkc_msg ) : ?>
							<li><code><?= e( $fkc_code ) ?></code> — <?= e( $fkc_msg ) ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<?= $__content ?>
		</main>
	</div>
</div>
<script>
/* Tiroir de navigation (mobile/tablette) */
(function(){
	var side=document.getElementById('fkcSide'),ov=document.getElementById('fkcSideOverlay'),tg=document.getElementById('fkcMenuToggle');
	if(!side||!tg)return;
	function open(){side.classList.add('open');ov.hidden=false;ov.classList.add('show');document.body.classList.add('nav-open');tg.setAttribute('aria-expanded','true');}
	function close(){side.classList.remove('open');ov.classList.remove('show');document.body.classList.remove('nav-open');tg.setAttribute('aria-expanded','false');setTimeout(function(){if(!side.classList.contains('open'))ov.hidden=true;},220);}
	function toggle(){side.classList.contains('open')?close():open();}
	tg.addEventListener('click',toggle);
	if(ov)ov.addEventListener('click',close);
	document.addEventListener('keydown',function(e){if('Escape'===e.key)close();});
	/* Refermer après navigation et au passage en mode bureau */
	side.querySelectorAll('a.nav-item').forEach(function(a){a.addEventListener('click',function(){if(window.matchMedia('(max-width:860px)').matches)close();});});
	window.addEventListener('resize',function(){if(window.innerWidth>860)close();});
})();
/*
 * REPLI DE LA BARRE LATÉRALE SUR GRAND ÉCRAN (1.773.0).
 *
 * Le tiroir ci-dessus ne concerne que le mobile : au-delà de 860 px, la barre
 * occupait 250 px que rien ne permettait de récupérer. Sur un tableau large —
 * résultats par axe, grand livre, balance — ces 250 px manquent, et l'écran
 * s'en trouve tronqué à droite.
 *
 * Repliée, la barre garde ses icônes : on continue de naviguer sans la
 * rouvrir. Le choix est mémorisé par navigateur, et Ctrl + B le bascule.
 */
(function(){
	var bt=document.getElementById('fkcSideCollapse'),side=document.getElementById('fkcSide');
	if(!bt||!side)return;
	function replie(){return document.body.classList.contains('side-collapsed');}
	function appliquer(){
		var r=replie();
		bt.textContent=r?'»':'«';
		bt.setAttribute('aria-expanded',r?'false':'true');
		var t=r?'Déplier la barre latérale (Ctrl + B)':'Replier la barre latérale (Ctrl + B)';
		bt.title=t;bt.setAttribute('aria-label',t);
		/* Repliée, chaque entrée doit dire ce qu'elle est au survol. */
		side.querySelectorAll('.nav-item').forEach(function(a){
			if(!a.getAttribute('title')){
				var l=a.querySelector('.nav-lbl');
				if(l)a.setAttribute('title',l.textContent.trim());
			}
		});
	}
	function basculer(){
		document.body.classList.toggle('side-collapsed');
		try{localStorage.setItem('fkc_side_replie',replie()?'1':'0');}catch(e){}
		appliquer();
		/* Les tuiles et tableaux se remesurent sur la largeur retrouvée. */
		window.dispatchEvent(new Event('resize'));
	}
	bt.addEventListener('click',basculer);
	document.addEventListener('keydown',function(e){
		if((e.ctrlKey||e.metaKey)&&'b'===String(e.key).toLowerCase()&&!e.altKey){
			var c=document.activeElement;
			if(c&&/^(INPUT|TEXTAREA|SELECT)$/.test(c.tagName))return; /* Ctrl+B dans un champ : on ne s'en mêle pas */
			e.preventDefault();basculer();
		}
	});
	appliquer();
})();
</script>
<script src="<?= e( fkc_asset( 'js/app.js' ) ) ?>?v=<?= e( FKC_VERSION ) ?>"></script>
<!-- Normalisation horizontale des tuiles d'indicateurs (voir assets/js/layout.js). -->
<script src="<?= e( fkc_asset( 'js/layout.js' ) ) ?>?v=<?= e( FKC_VERSION ) ?>" defer></script>
<script src="<?= e( fkc_asset( 'js/ux-barre.js' ) ) ?>?v=<?= e( FKC_VERSION ) ?>" defer></script>
<?php
/*
 * MODE HORS LIGNE — enregistrement du service worker.
 *
 * L'URL vient de url(), donc de la racine de l'APPLICATION, et non de
 * fkc_asset() qui pointe le dossier du plugin : la portée d'un service worker
 * est limitée au chemin d'où il est servi, et un worker chargé depuis
 * /wp-content/plugins/… s'installerait sans erreur pour n'intercepter jamais
 * rien.
 *
 * L'échec d'enregistrement est SILENCIEUX côté utilisateur mais tracé en
 * console : sans service worker, FinaKop fonctionne exactement comme avant.
 * Une alerte à chaque ouverture sur un navigateur ancien n'apprendrait rien
 * à personne.
 */
?>
<link rel="manifest" href="<?= e( url( 'manifest.webmanifest' ) ) ?>">
<script>
if ('serviceWorker' in navigator) {
	navigator.serviceWorker.register('<?= e( url( 'sw.js' ) ) ?>')
		.catch(function (e) { console.warn('FinaKop : mode hors ligne indisponible', e); });
}
</script>
</body>
</html>
