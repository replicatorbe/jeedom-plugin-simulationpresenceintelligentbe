<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Une modale de plugin est incluse par index.php, qui n'a vérifié que la
 * connexion : le profil, lui, se contrôle ici, comme sur la page du plugin. */
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}

/*
 * La charpente seulement : la liste est posée par le JS depuis l'action ajax
 * « lamps ». Les noms affichés ici viennent des équipements de l'utilisateur,
 * et sont donc écrits en texte par le JS, jamais en balisage.
 */
?>
<div id="div_simulationpresenceintelligentbePicker">
	<div class="alert alert-info" style="margin:0 0 10px 0;">
		{{Le plugin a parcouru votre installation et retenu les équipements qui savent s'allumer et s'éteindre. Cochez ceux de ce groupe. Le bouton}} <i class="fas fa-lightbulb"></i> {{allume la lampe pour vous permettre de la reconnaître dans la pièce, et}} <i class="fas fa-sliders-h"></i> {{montre les deux commandes retenues, qu'on peut changer.}}
		<br>{{Votre lampe n'apparaît pas ? « Tous les équipements » montre tout ce qui porte une commande d'action — un module, un interrupteur, une prise mal déclarée — à charge de désigner vous-même la commande qui allume et celle qui éteint.}}
	</div>

	<div class="row" style="margin:0 0 10px 0;">
		<div class="col-sm-6" style="padding-left:0;">
			<div class="input-group">
				<span class="input-group-addon roundedLeft"><i class="fas fa-search"></i></span>
				<input type="text" class="form-control roundedRight" id="in_simulationpresenceintelligentbeSearch" placeholder="{{Filtrer par nom de lampe ou de pièce}}">
			</div>
		</div>
		<div class="col-sm-6 text-right" style="padding-right:0;">
			<div class="btn-group">
				<a class="btn btn-sm btn-default active spiFilter" data-filter="light"><i class="fas fa-lightbulb"></i> {{Lampes}} <span class="badge" data-count="light">0</span></a>
				<a class="btn btn-sm btn-default active spiFilter" data-filter="plug"><i class="fas fa-plug"></i> {{Prises}} <span class="badge" data-count="plug">0</span></a>
				<a class="btn btn-sm btn-default active spiFilter" data-filter="guess"><i class="fas fa-question"></i> {{Autres}} <span class="badge" data-count="guess">0</span></a>
				<a class="btn btn-sm btn-default spiFilter" data-filter="unknown" title="{{Montre tous les équipements qui portent une commande d'action}}"><i class="fas fa-th-list"></i> {{Tous les équipements}} <span class="badge" data-count="unknown">0</span></a>
			</div>
		</div>
	</div>

	<div id="div_simulationpresenceintelligentbePickerList" style="max-height:55vh;overflow:auto;">
		<div class="text-center" style="padding:30px;"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
	</div>

	<div style="border-top:1px solid rgba(128,128,128,0.3);margin-top:10px;padding-top:10px;">
		<span id="span_simulationpresenceintelligentbePickerCount" class="label label-info">0 {{sélectionnée(s)}}</span>
		<span class="pull-right">
			<a class="btn btn-default" id="bt_simulationpresenceintelligentbePickerCancel">{{Annuler}}</a>
			<a class="btn btn-success" id="bt_simulationpresenceintelligentbePickerValidate"><i class="fas fa-check"></i> {{Valider la sélection}}</a>
		</span>
	</div>
</div>
