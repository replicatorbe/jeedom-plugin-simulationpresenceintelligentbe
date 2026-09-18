<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../../../core/php/core.inc.php';
/* La désinstallation passe ici alors que le plugin peut déjà être désactivé :
 * l'autoload ne chargerait alors plus sa classe. */
require_once __DIR__ . '/../core/class/simulationpresenceintelligentbe.class.php';

function simulationpresenceintelligentbe_install() {
    /*
     * Rien à installer : ni démon, ni dépendance, ni table. Le plugin ne vit
     * que des crons du coeur et de l'historique que Jeedom tient déjà.
     *
     * On signale en revanche l'absence de position, parce qu'une journée
     * inventée s'appuie sur le coucher du soleil et que, sans coordonnées,
     * elle se replierait sur 19 h toute l'année — ce qui se remarque de la rue
     * en juin comme en décembre.
     */
    simulationpresenceintelligentbe::checkPosition();
}

function simulationpresenceintelligentbe_update() {
    simulationpresenceintelligentbe_install();
    foreach (eqLogic::byType('simulationpresenceintelligentbe') as $eqLogic) {
        try {
            $eqLogic->createCommands();
            /* Les plans en cache ont été tirés par la version précédente : les
             * garder ferait jouer ce soir un plan qui ne correspond plus au
             * code qui l'exécute. */
            $eqLogic->forgetPlan();
        } catch (Throwable $e) {
            log::add('simulationpresenceintelligentbe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function simulationpresenceintelligentbe_remove() {
    foreach (eqLogic::byType('simulationpresenceintelligentbe') as $eqLogic) {
        try {
            $eqLogic->forgetPlan();
            cache::delete($eqLogic->runtimeKey());
            cache::delete($eqLogic->holdKey());
        } catch (Throwable $e) {
            log::add('simulationpresenceintelligentbe', 'debug', __('Nettoyage du cache impossible :', __FILE__) . ' ' . $e->getMessage());
        }
    }
    message::removeAll('simulationpresenceintelligentbe');
}
