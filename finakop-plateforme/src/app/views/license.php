<?php /** @var array $license @var array|null $flash */
defined( 'FKC_ROOT' ) || die( 'Accès direct interdit.' ); ?>
<?php if ( $flash ) : ?><div class="alert alert-<?= 'ok' === $flash['type'] ? 'ok' : 'err' ?>"><?= e( $flash['msg'] ) ?></div><?php endif; ?>
<?php if ( FKC_License::verrouille() ) : ?>
<div class="alert alert-err" style="max-width:780px"><strong>Licence requise.</strong>
	<?= FKC_Auth::isAdmin()
		? 'Installez ci-dessous la clé de licence de votre société pour accéder aux modules. Elle doit avoir été émise pour l\'adresse ' . e( strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) ) . '.'
		: 'Votre espace n\'a pas encore de licence active. Contactez l\'administrateur de votre société.' ?>
</div>
<?php endif; ?>

<div class="card" style="max-width:780px">
	<div class="card-head"><h2>État de la licence</h2>
		<span class="pill pill-<?= e( $license['status'] ) ?>"><?= e( $license['profile_label'] ?: ucfirst( $license['status'] ) ) ?></span>
	</div>
	<div class="card-body">
		<table class="kv">
			<tr><th>Client</th><td><?= e( $license['client'] ?: '—' ) ?></td></tr>
			<tr><th>Édition</th><td><strong><?= e( class_exists( 'FKC_Plans' ) ? FKC_Plans::editionLabel( $license['edition'] ?? 'core' ) : 'CORE' ) ?></strong></td></tr>
			<tr><th>Palier</th><td><?= e( $license['profile_label'] ?: '—' ) ?></td></tr>
			<tr><th>Statut</th><td><?= e( $license['message'] ) ?></td></tr>
			<tr><th>Vérification distante</th><td><?= e( $license['remote'] ) ?></td></tr>
			<?php if ( class_exists( 'FKC_License_Depot' ) ) : $fkc_dep = FKC_License_Depot::diagnostic(); ?>
			<tr><th>Dépôt de licences</th><td>
				<?php if ( ! $fkc_dep['configure'] ) : ?>
					<span class="muted">Non configuré — la révocation à distance est inactive.</span>
				<?php else : ?>
					<?= e( $fkc_dep['url'] ) ?>
					<?php if ( $fkc_dep['vu_a'] ) : ?>
						<div class="help" style="margin:4px 0 0">
							Dernière consultation : <?= e( date( 'd/m/Y H:i', (int) $fkc_dep['vu_a'] ) ) ?>
							<?php if ( 'injoignable' === $fkc_dep['etat'] ) : ?>
								— <strong>dépôt injoignable</strong>. Cela ne change rien à votre licence :
								un réseau coupé ne bloque jamais l'application.
							<?php elseif ( 'invalide' === $fkc_dep['etat'] ) : ?>
								— <strong>réponse non signée ou périmée</strong>, ignorée. Votre licence est inchangée.
							<?php elseif ( '' !== $fkc_dep['etat'] ) : ?>
								— verdict : <?= e( $fkc_dep['etat'] ) ?>.
							<?php endif; ?>
						</div>
					<?php else : ?>
						<div class="help" style="margin:4px 0 0">Jamais consultée.</div>
					<?php endif; ?>
				<?php endif; ?>
			</td></tr>
			<?php endif; ?>
			<tr><th>Échéance</th><td><?= $license['expires_at'] ? e( date( 'd/m/Y', strtotime( $license['expires_at'] ) ) ) : '—' ?></td></tr>
			<tr><th>Modules</th><td>
				<?php if ( ! empty( $license['modules'] ) ) : foreach ( $license['modules'] as $m ) : ?>
					<span class="tag"><?= e( FKC_Modules::label( $m ) ) ?></span>
				<?php endforeach; else : echo '—'; endif; ?>
			</td></tr>
		</table>
	</div>
</div>

<?php
// Quotas / limites du palier (avec consommation pour les compteurs connus).
$lim = class_exists( 'FKC_License' ) ? FKC_License::limits() : array();
$keys = class_exists( 'FKC_Plans' ) ? FKC_Plans::limitKeys() : array();
$conso = array();
if ( class_exists( 'FKC_Societe' ) ) { $conso['societes'] = FKC_Societe::count(); }
if ( class_exists( 'FKC_User' ) ) { $conso['utilisateurs'] = FKC_User::count(); }
// Compteurs sectoriels (selon l'édition/les modules) — tolérants aux tables absentes.
foreach ( array(
	'etablissements' => array( 'FKC_Etablissement', 'count' ),
	'entrepots'  => array( 'FKC_InvEntrepot', 'count' ),
	'artistes'   => array( 'FKC_Artiste', 'count' ),
	'projets'    => array( 'FKC_Projet', 'count' ),
	'oeuvres'    => array( 'FKC_Oeuvre', 'count' ),
	'contrats'   => array( 'FKC_Contrat', 'count' ),
	'royalties'  => array( 'FKC_Royalty', 'countMois' ),
	'api'        => array( 'FKC_ApiKey', 'monthlyCalls' ),
) as $k => $cm ) {
	if ( class_exists( $cm[0] ) && method_exists( $cm[0], $cm[1] ) ) {
		try { $conso[ $k ] = (int) call_user_func( array( $cm[0], $cm[1] ) ); } catch ( \Throwable $e ) {}
	}
}
// Stockage : consommation réelle (octets) face au quota en Go.
$stockUsed = class_exists( 'FKC_Storage' ) ? FKC_Storage::usedBytes() : null;
$fmt = function ( $v ) { return (int) $v < 0 ? 'Illimité' : number_format( (int) $v, 0, ',', ' ' ); };
?>
<div class="card" style="max-width:780px">
	<div class="card-head"><h2>Limites & quotas du palier</h2></div>
	<div class="card-body">
		<table class="kv">
			<?php foreach ( $keys as $k => $label ) : $v = $lim[ $k ] ?? -1; ?>
				<tr>
					<th><?= e( $label ) ?></th>
					<td>
						<?php if ( 'stockage_go' === $k && null !== $stockUsed ) : ?>
							<strong><?= e( FKC_Storage::human( $stockUsed ) ) ?></strong> / <?= (int) $v < 0 ? 'Illimité' : e( $fmt( $v ) . ' Go' ) ?>
							<?php if ( (int) $v >= 0 && $stockUsed >= (int) $v * FKC_Storage::GO ) : ?><span class="tag" style="background:var(--danger-bg);color:var(--danger)">atteint</span><?php endif; ?>
						<?php elseif ( isset( $conso[ $k ] ) && (int) $v >= 0 ) : ?>
							<strong><?= e( number_format( (int) $conso[ $k ], 0, ',', ' ' ) ) ?></strong> / <?= e( $fmt( $v ) ) ?>
							<?php if ( (int) $conso[ $k ] >= (int) $v ) : ?><span class="tag" style="background:var(--danger-bg);color:var(--danger)">atteint</span><?php endif; ?>
						<?php elseif ( isset( $conso[ $k ] ) ) : ?>
							<strong><?= e( number_format( (int) $conso[ $k ], 0, ',', ' ' ) ) ?></strong> / <?= e( $fmt( $v ) ) ?>
						<?php else : ?>
							<?= e( $fmt( $v ) ) ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<p class="help" style="margin-bottom:0">Les quotas sont fixés par votre clé de licence signée. Une montée en gamme se fait en installant une nouvelle clé.</p>
	</div>
</div>

<?php
// Capacités avancées (caps) du palier.
$caps    = class_exists( 'FKC_License' ) ? FKC_License::caps() : array();
$capDefs = class_exists( 'FKC_Plans' ) ? FKC_Plans::capLabels() : array();
if ( $capDefs ) : ?>
<div class="card" style="max-width:780px;margin-top:18px">
	<div class="card-head"><h2>Capacités avancées</h2></div>
	<div class="card-body">
		<table class="kv">
			<?php foreach ( $capDefs as $ck => $clabel ) : $cv = $caps[ $ck ] ?? false; $on = ! ( false === $cv || null === $cv || '' === $cv || 0 === $cv || '0' === $cv ); ?>
				<tr>
					<th><?= e( $clabel ) ?></th>
					<td>
						<?php if ( $on ) : ?>
							<span class="tag" style="background:var(--ok-bg);color:var(--ok)"><?= e( FKC_Plans::capValueLabel( $cv ) ) ?></span>
						<?php else : ?>
							<span class="muted">—</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<p class="help" style="margin-bottom:0">Ces capacités conditionnent l'accès à certaines fonctions avancées. Les fonctions non incluses sont masquées ou bloquées selon le palier.</p>
	</div>
</div>
<?php endif; ?>

<?php
/*
 * PACKS MÉTIER SOUSCRITS (1.680.0).
 *
 * Le client doit pouvoir lire ici, noir sur blanc, ce que sa clé lui ouvre —
 * c'est la réponse à « pourquoi ne vois-je pas le pack Pharmacie ? ». Le bloc
 * n'est affiché que si la clé NOMME les packs : sur un jeton antérieur, il
 * afficherait la liste complète du catalogue et ferait croire à une souscription
 * que le client n'a pas payée.
 */
$packsOk = class_exists( 'FKC_Packs' ) ? FKC_Packs::autorises() : array();
$packsNommes = class_exists( 'FKC_License' ) ? FKC_License::packsNommes() : false;
if ( $packsNommes && $packsOk ) :
	$libelles = FKC_Packs::options();
	$principal = FKC_Packs::activeCode();
?>
<div class="card" style="max-width:780px;margin-top:18px">
	<div class="card-head"><h2>Packs Métier inclus dans votre clé</h2></div>
	<div class="card-body">
		<p>
		<?php foreach ( $packsOk as $pc ) : ?>
			<span class="tag" style="<?= $pc === $principal ? 'background:var(--ok-bg);color:var(--ok)' : '' ?>">
				<?= e( $libelles[ $pc ] ?? $pc ) ?><?= $pc === $principal ? ' · actif' : '' ?>
			</span>
		<?php endforeach; ?>
		</p>
		<p class="help" style="margin-bottom:0">Un Pack Métier ne s'active que par la licence. Les autres verticaux
			de la gamme FinaKop ne sont ni listés ni activables tant qu'ils ne figurent pas dans votre clé ;
			pour en ajouter un, contactez votre partenaire FinaKop.</p>
	</div>
</div>
<?php endif; ?>

<?php
/*
 * INTERRUPTEURS DE L'ÉDITEUR. Ce bloc n'apparaît QUE si le jeton en porte :
 * une licence ordinaire n'a rien à afficher ici, et une section vide ferait
 * croire à une restriction qui n'existe pas.
 *
 * Il est affiché à dessein. Un client qui ne trouve plus un écran doit pouvoir
 * lire ici POURQUOI, plutôt que d'ouvrir un ticket — et surtout ne pas se voir
 * proposer une montée de palier qui ne débloquerait rien.
 */
$modOff  = class_exists( 'FKC_License' ) ? FKC_License::modulesOff() : array();
$featOff = class_exists( 'FKC_License' ) ? FKC_License::featureOverrides() : array();
if ( $modOff || $featOff ) : ?>
<div class="card" style="max-width:780px;margin-top:18px">
	<div class="card-head"><h2>Réglages appliqués par votre partenaire</h2></div>
	<div class="card-body">
		<?php if ( $modOff ) : ?>
			<p><strong>Modules désactivés</strong></p>
			<p>
			<?php foreach ( $modOff as $m ) : ?>
				<span class="tag" style="background:var(--danger-bg);color:var(--danger)"><?= e( FKC_Modules::label( $m ) ) ?></span>
			<?php endforeach; ?>
			</p>
		<?php endif; ?>
		<?php if ( $featOff ) : ?>
			<p style="margin-top:12px"><strong>Fonctions réglées individuellement</strong></p>
			<table class="kv">
				<?php foreach ( $featOff as $fc => $fv ) : ?>
					<tr>
						<th><?= e( FKC_Modules::featureLabel( $fc ) ) ?></th>
						<td>
							<?php if ( $fv ) : ?>
								<span class="tag" style="background:var(--ok-bg);color:var(--ok)">Ouverte</span>
							<?php else : ?>
								<span class="tag" style="background:var(--danger-bg);color:var(--danger)">Fermée</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>
		<p class="help" style="margin-bottom:0">Ces réglages sont inscrits dans votre clé de licence et ne dépendent
			pas du palier : une montée de gamme ne les modifie pas. Pour les faire évoluer, contactez votre
			partenaire FinaKop, qui vous remettra une nouvelle clé.</p>
	</div>
</div>
<?php endif; ?>

<?php if ( FKC_Auth::isAdmin() ) : // seul l'administrateur installe ou remplace la licence ?>
<div class="card" style="max-width:780px">
	<div class="card-head"><h2>Activer / mettre à jour la clé</h2></div>
	<div class="card-body">
		<form method="post" action="<?= e( url( 'licence' ) ) ?>">
			<?= FKC_Csrf::field() ?>
			<label class="fld">
				<span>Clé de licence FinaKop (jeton signé)</span>
				<textarea name="token" rows="4" placeholder="eyJ...payload....signature"></textarea>
			</label>
			<p class="help">Collez la clé fournie par KophisGroup. Elle détermine votre édition (CORE Starter → CREATIVE SUITE) et les modules activés.</p>
			<button class="btn btn-primary" type="submit">Installer la licence</button>
		</form>
	</div>
</div>
<?php endif; ?>
