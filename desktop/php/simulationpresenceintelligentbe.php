<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('simulationpresenceintelligentbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter un groupe}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-user-secret"></i> {{Mes simulations}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucune simulation pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter un groupe » et donnez-lui un nom — « Façade », « Étage », ou simplement « Maison ».}}</li>';
			echo '<li>{{Choisissez les lampes et les prises à simuler dans le sélecteur : le plugin va les chercher tout seul dans votre installation.}}</li>';
			echo '<li>{{Laissez le plugin historiser leur état. Sans historique, il n\'a rien à rejouer — il inventera des soirées en attendant d\'avoir appris.}}</li>';
			echo '<li>{{Dites-lui quand démarrer : une condition — l\'alarme est armée, personne n\'est là — ou l\'ordre « Démarrer » depuis un scénario.}}</li>';
			echo '</ol></div>';
		}

		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div></div>';

		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			$summary = $eqLogic->cardSummary();
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas ' . ($summary['active'] ? 'fa-user-secret' : 'fa-moon') . '" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<br><span style="font-size:0.85em;opacity:0.7;">' . $summary['lamps'] . ' {{lampe(s)}}</span>';
			echo '<br><span style="font-size:0.85em;' . ($summary['active'] ? 'color:#5cb85c;' : 'opacity:0.7;') . '">' . $summary['text'] . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-lightbulb"></i><span class="hidden-xs"> {{Lampes}}</span></a></li>
			<li role="presentation"><a href="#departtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-key"></i><span class="hidden-xs"> {{Départ}}</span></a></li>
			<li role="presentation"><a href="#learningtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-graduation-cap"></i><span class="hidden-xs"> {{Apprentissage}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ============================================== LAMPES ============================================== -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom du groupe}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Façade, Étage, Maison…}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach (jeeObject::buildTree(null, false) as $object) {
											echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-play-circle"></i> {{Marche}}</legend>
							<div class="form-group">
								<div class="col-sm-12">
									<div id="div_spiStatus" class="alert alert-info" style="margin:0 0 8px 0;">{{Enregistrez le groupe pour connaître son état.}}</div>
									<a class="btn btn-success btn-sm" id="bt_spiStart"><i class="fas fa-play"></i> {{Démarrer maintenant}}</a>
									<a class="btn btn-danger btn-sm" id="bt_spiStop"><i class="fas fa-stop"></i> {{Arrêter}}</a>
									<a class="btn btn-default btn-sm" id="bt_spiAuto"><i class="fas fa-magic"></i> {{Rendre la main à la condition}}</a>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-xs-12">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-lightbulb"></i> {{Lampes et prises simulées}}</legend>
							<div class="alert alert-info" style="margin:5px;">
								{{Le sélecteur parcourt votre installation et propose d'abord ce qui porte les types génériques « Lumière », puis les prises commandées. Chaque lampe peut être allumée depuis le sélecteur pour la reconnaître. Ce qui est coché ici sera piloté pendant la simulation — et uniquement pendant la simulation.}}
							</div>
							<div class="form-group">
								<div class="col-sm-12">
									<a class="btn btn-primary" id="bt_spiPick"><i class="fas fa-list-alt"></i> {{Choisir les lampes}}</a>
									<span id="span_spiLampCount" class="label label-info" style="margin-left:8px;">0 {{lampe(s)}}</span>
								</div>
							</div>
							<div class="form-group">
								<div class="col-sm-12">
									<table class="table table-condensed table-bordered" id="table_spiLamps">
										<thead>
											<tr>
												<th style="width:40px;">{{Active}}</th>
												<th>{{Lampe}}</th>
												<th>{{Pièce}}</th>
												<th>{{État historisé}}</th>
												<th style="width:60px;">{{Retirer}}</th>
											</tr>
										</thead>
										<tbody></tbody>
									</table>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ============================================== DÉPART ============================================== -->
			<div role="tabpanel" class="tab-pane" id="departtab">
				<br>
				<div class="col-lg-7">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-key"></i> {{Condition de départ}}</legend>
							<div class="alert alert-info" style="margin:5px;">
								{{L'état dans lequel doit se trouver la maison pour que la simulation démarre toute seule : l'alarme est armée, le mode « Absence » est actif, plus personne n'est détecté. Le plugin surveille ces commandes chaque minute et démarre quand elles disent toutes oui — ou l'une d'elles, à votre choix.}}
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Démarrer sur condition}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="conditions" data-l3key="enable">
									<span class="help-block" style="margin:0;">{{Décoché, la simulation ne part que sur l'ordre « Démarrer ».}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Il faut}}</label>
								<div class="col-sm-4">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="conditions" data-l3key="combine">
										<option value="and">{{toutes les conditions}}</option>
										<option value="or">{{au moins une condition}}</option>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Confirmer pendant}}</label>
								<div class="col-sm-3">
									<div class="input-group">
										<input type="number" min="0" max="720" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="conditions" data-l3key="delay" placeholder="2">
										<span class="input-group-addon">{{min}}</span>
									</div>
								</div>
								<div class="col-sm-5">
									<span class="help-block" style="margin:0;">{{Avant de démarrer. Évite qu'un capteur qui hésite une minute lance toute une soirée.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Puis arrêter après}}</label>
								<div class="col-sm-3">
									<div class="input-group">
										<input type="number" min="0" max="720" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="conditions" data-l3key="release" placeholder="2">
										<span class="input-group-addon">{{min}}</span>
									</div>
								</div>
								<div class="col-sm-5">
									<span class="help-block" style="margin:0;">{{Une fois la condition retombée. Zéro pour arrêter dans la minute.}}</span>
								</div>
							</div>
							<div class="form-group">
								<div class="col-sm-12">
									<table class="table table-condensed table-bordered" id="table_spiConditions">
										<thead>
											<tr>
												<th>{{Commande}}</th>
												<th style="width:90px;">{{Test}}</th>
												<th style="width:140px;">{{Valeur attendue}}</th>
												<th style="width:110px;">{{Maintenant}}</th>
												<th style="width:50px;"></th>
											</tr>
										</thead>
										<tbody></tbody>
									</table>
									<a class="btn btn-default btn-sm" id="bt_spiAddCondition"><i class="fas fa-plus-circle"></i> {{Ajouter une condition}}</a>
									<a class="btn btn-default btn-sm" id="bt_spiTestConditions"><i class="fas fa-vial"></i> {{Évaluer maintenant}}</a>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-5">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-clock"></i> {{Fenêtre horaire}}</legend>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Rien avant}}</label>
								<div class="col-sm-4">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="window" data-l3key="start" placeholder="07:00">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Rien après}}</label>
								<div class="col-sm-4">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="window" data-l3key="end" placeholder="23:30">
								</div>
							</div>
							<div class="form-group">
								<div class="col-sm-12">
									<span class="help-block" style="margin:0;">{{Une lampe encore allumée à l'heure de fermeture est éteinte. Un allumage prévu hors fenêtre est abandonné, jamais repoussé : tout repousser à la même minute se remarquerait de la rue bien plus qu'une lampe qui ne s'allume pas.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-shield-alt"></i> {{Garde-fous}}</legend>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Lampes allumées au plus}}</label>
								<div class="col-sm-3">
									<input type="number" min="0" max="50" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="guards" data-l3key="max_on" placeholder="3">
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{En même temps. 0 pour ne pas limiter.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Remettre en l'état à l'arrêt}}</label>
								<div class="col-sm-7">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="guards" data-l3key="restore" checked>
									<span class="help-block" style="margin:0;">{{Chaque lampe retrouve l'état qu'elle avait au démarrage. Sans cela, rentrer à minuit veut dire éteindre trois lampes qu'on n'a pas allumées.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Après un geste manuel}}</label>
								<div class="col-sm-3">
									<div class="input-group">
										<input type="number" min="0" max="1440" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="guards" data-l3key="manual" placeholder="60">
										<span class="input-group-addon">{{min}}</span>
									</div>
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{Durée pendant laquelle le plugin ne touche plus à une lampe allumée ou éteinte à la main. 0 pour reprendre la main tout de suite.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================================== APPRENTISSAGE ========================================== -->
			<div role="tabpanel" class="tab-pane" id="learningtab">
				<br>
				<div class="col-lg-5">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-graduation-cap"></i> {{Apprendre}}</legend>
							<div class="form-group">
								<label class="col-sm-6 control-label">{{Remonter sur}}</label>
								<div class="col-sm-4">
									<div class="input-group">
										<input type="number" min="7" max="365" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="learning" data-l3key="depth" placeholder="28">
										<span class="input-group-addon">{{jours}}</span>
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-6 control-label">{{Rejouer dès}}</label>
								<div class="col-sm-4">
									<div class="input-group">
										<input type="number" min="1" max="30" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="learning" data-l3key="min_days" placeholder="3">
										<span class="input-group-addon">{{jours}}</span>
									</div>
								</div>
								<div class="col-sm-12">
									<span class="help-block" style="margin:0;">{{En dessous de ce nombre de journées observées, le plugin invente la soirée plutôt que de caricaturer le peu qu'il a vu.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-6 control-label">{{Variabilité}}</label>
								<div class="col-sm-4">
									<div class="input-group">
										<input type="number" min="0" max="100" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="invent" data-l3key="variability" placeholder="30">
										<span class="input-group-addon">%</span>
									</div>
								</div>
								<div class="col-sm-12">
									<span class="help-block" style="margin:0;">{{L'écart autorisé aux habitudes observées. À 0, toutes les soirées se ressemblent ; à 100, elles s'éloignent franchement sans quitter les habitudes de la maison.}}</span>
								</div>
							</div>
							<div class="form-group">
								<div class="col-sm-12">
									<a class="btn btn-primary btn-sm" id="bt_spiHistorize"><i class="fas fa-database"></i> {{Historiser les lampes}}</a>
									<span class="help-block" style="margin:4px 0 0 0;">{{Active l'historisation de l'état des lampes choisies. Sans historique, il n'y a rien à rejouer — et l'historique ne commence qu'au moment où on coche la case.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-moon"></i> {{Soirées inventées}}</legend>
							<div class="alert alert-info" style="margin:5px;">
								{{Utilisées pour les lampes sans passé, et le premier jour pour toutes. Elles se calent sur le coucher du soleil de votre position.}}
							</div>
							<div class="form-group">
								<label class="col-sm-6 control-label">{{Une soirée sur}}</label>
								<div class="col-sm-4">
									<div class="input-group">
										<input type="number" min="0" max="100" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="invent" data-l3key="evening_chance" placeholder="60">
										<span class="input-group-addon">%</span>
									</div>
								</div>
								<div class="col-sm-12">
									<span class="help-block" style="margin:0;">{{Probabilité qu'une lampe donnée participe à la soirée. Moins de 100 % évite que six lampes s'allument tous les soirs à la même minute.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-6 control-label">{{Allumer au coucher du soleil}}</label>
								<div class="col-sm-4">
									<div class="input-group">
										<input type="number" min="-180" max="180" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="invent" data-l3key="sunset_offset" placeholder="-15">
										<span class="input-group-addon">{{min}}</span>
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-6 control-label">{{Éteindre vers}}</label>
								<div class="col-sm-4">
									<input type="text" class="eqLogicAttr form-control spiTime" data-l1key="configuration" data-l2key="invent" data-l3key="bedtime" placeholder="23:00">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-6 control-label">{{Flottement}}</label>
								<div class="col-sm-4">
									<div class="input-group">
										<input type="number" min="0" max="180" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="invent" data-l3key="spread" placeholder="25">
										<span class="input-group-addon">{{min}}</span>
									</div>
								</div>
								<div class="col-sm-12">
									<span class="help-block" style="margin:0;">{{Écart aléatoire appliqué de part et d'autre de chaque heure.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-6 control-label">{{Un matin sur}}</label>
								<div class="col-sm-4">
									<div class="input-group">
										<input type="number" min="0" max="100" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="invent" data-l3key="morning_chance" placeholder="0">
										<span class="input-group-addon">%</span>
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-6 control-label">{{Lever vers}}</label>
								<div class="col-sm-4">
									<input type="text" class="eqLogicAttr form-control spiTime" data-l1key="configuration" data-l2key="invent" data-l3key="wake" placeholder="07:00">
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-7">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-chart-bar"></i> {{Ce que le plugin a appris}}</legend>
							<div class="form-group">
								<div class="col-sm-12">
									<table class="table table-condensed table-bordered" id="table_spiLearning">
										<thead>
											<tr>
												<th>{{Lampe}}</th>
												<th style="width:90px;">{{Journées}}</th>
												<th style="width:110px;">{{Allumée/jour}}</th>
												<th style="width:120px;">{{Ce soir}}</th>
											</tr>
										</thead>
										<tbody></tbody>
									</table>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-eye"></i> {{Aperçu d'une journée}}</legend>
							<div class="form-group">
								<div class="col-sm-12">
									<a class="btn btn-default btn-sm spiPreview" data-day="0"><i class="fas fa-calendar-day"></i> {{Aujourd'hui}}</a>
									<a class="btn btn-default btn-sm spiPreview" data-day="1"><i class="fas fa-calendar-plus"></i> {{Demain}}</a>
									<a class="btn btn-default btn-sm spiPreview" data-day="2">{{Après-demain}}</a>
									<a class="btn btn-warning btn-sm" id="bt_spiReplan"><i class="fas fa-dice"></i> {{Tirer un autre plan}}</a>
									<span class="help-block" style="margin:4px 0 0 0;">{{L'aperçu est calculé par le serveur, avec le code qui jouera réellement le plan.}}</span>
								</div>
							</div>
							<div class="form-group">
								<div class="col-sm-12">
									<div id="div_spiPreview"><span class="help-block">{{Enregistrez le groupe, puis demandez un aperçu.}}</span></div>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ============================================ COMMANDES ============================================ -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="alert alert-info" style="margin:5px;">
					{{Ces commandes sont créées et tenues à jour par le plugin. « Démarrer » et « Arrêter » l'emportent sur la condition jusqu'à ce que « Revenir à la condition » soit joué : quelqu'un qui arrête la simulation depuis son téléphone ne veut pas la voir repartir la minute suivante.}}
				</div>
				<table id="table_cmd" class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>{{Nom}}</th>
							<th>{{Type}}</th>
							<th>{{Options}}</th>
							<th>{{Action}}</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'simulationpresenceintelligentbe', 'js', 'simulationpresenceintelligentbe'); ?>
