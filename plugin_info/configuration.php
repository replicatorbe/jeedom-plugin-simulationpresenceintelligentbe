<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
/* L'autoload du coeur ne connaît que la classe qui porte le nom du plugin :
 * celle du soleil, utilisée plus bas, se charge par elle. */
require_once __DIR__ . '/../core/class/simulationpresenceintelligentbe.class.php';
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-lightbulb"></i> {{Lampes}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Délai de réponse d'une lampe}}</label>
			<div class="col-md-2">
				<input type="number" min="30" max="900" class="configKey form-control" data-l1key="order_grace" placeholder="120">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Secondes laissées à une lampe pour confirmer un ordre. Passé ce délai, un écart entre l'état ordonné et l'état publié est pris pour un geste humain, et le plugin laisse la lampe tranquille. Augmentez-le si vos modules mettent du temps à publier leur état.}}</span>
			</div>
		</div>
	</fieldset>
	<fieldset>
		<legend><i class="fas fa-file-alt"></i> {{Journal}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Écrire le plan du jour}}</label>
			<div class="col-md-2">
				<input type="checkbox" class="configKey" data-l1key="log_plan">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Chaque plan tiré est écrit dans le journal du plugin, lampe par lampe, avec ses heures. Bavard, mais c'est la seule façon de comprendre après coup pourquoi une soirée s'est déroulée comme elle s'est déroulée.}}</span>
			</div>
		</div>
	</fieldset>
	<fieldset>
		<legend><i class="fas fa-sun"></i> {{Position}}</legend>
		<div class="form-group">
			<div class="col-md-11 col-md-offset-1">
			<?php
			$latitude  = config::byKey('info::latitude');
			$longitude = config::byKey('info::longitude');
			if ($latitude == '' || $longitude == '') {
				echo '<div class="alert alert-warning" style="margin:0;">';
				echo '{{La position de votre installation n\'est pas renseignée. Les journées inventées se caleront sur un coucher de soleil fixe à 19 h, toute l\'année. Réglages → Système → Configuration → Général.}}';
				echo '</div>';
			} else {
				$sun = simulationpresenceintelligentbeSun::sun(time(), $latitude, $longitude);
				echo '<div class="alert alert-info" style="margin:0;">';
				echo '{{Position}} : ' . $latitude . ' / ' . $longitude . '. ';
				echo '{{Aujourd\'hui, lever}} ' . ($sun['sunrise'] === null ? '—' : simulationpresenceintelligentbeSun::minuteToTime($sun['sunrise']));
				echo ', {{coucher}} ' . ($sun['sunset'] === null ? '—' : simulationpresenceintelligentbeSun::minuteToTime($sun['sunset'])) . '.';
				echo '</div>';
			}
			?>
			</div>
		</div>
	</fieldset>
</form>
