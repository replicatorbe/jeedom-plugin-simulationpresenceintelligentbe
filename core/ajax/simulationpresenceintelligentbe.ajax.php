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

try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');
    /* L'autoload du coeur ne connaît que la classe qui porte le nom du plugin :
     * les trois autres se chargent par elle. */
    require_once __DIR__ . '/../class/simulationpresenceintelligentbe.class.php';

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

    ajax::init();

    /* Un groupe du plugin, et rien d'autre : l'identifiant vient du navigateur. */
    $getGroup = function ($_id) {
        $eqLogic = simulationpresenceintelligentbe::byId($_id);
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'simulationpresenceintelligentbe') {
            throw new Exception(__('Groupe introuvable', __FILE__));
        }
        return $eqLogic;
    };

    /*
     * Une commande d'une lampe, contrôlée.
     *
     * Jamais exécuter un identifiant de commande venu du navigateur sans le
     * rattacher à son équipement : la page du plugin est réservée aux
     * administrateurs, mais rien n'oblige l'appel à venir de la page.
     */
    $lampCmd = function ($_eqId, $_role, $_cmdId = '') {
        $eqLogic = eqLogic::byId($_eqId);
        if (!is_object($eqLogic)) {
            throw new Exception(__('Équipement introuvable', __FILE__));
        }
        if ($eqLogic->getEqType_name() == 'simulationpresenceintelligentbe') {
            throw new Exception(__('Un groupe du plugin ne peut pas être une lampe.', __FILE__));
        }

        if ($_cmdId !== '' && $_cmdId !== null) {
            $cmd = cmd::byId((int) $_cmdId);
            if (!is_object($cmd) || $cmd->getEqLogic_id() != $eqLogic->getId()) {
                throw new Exception(__('Cette commande n\'appartient pas à cet équipement.', __FILE__));
            }
            if ($cmd->getType() != 'action') {
                throw new Exception(__('Ce n\'est pas une commande d\'action.', __FILE__));
            }
            return $cmd;
        }

        /* Aucune commande désignée : c'est au serveur de retrouver celle qui
         * joue ce rôle, avec le même détecteur que le sélecteur lui-même. Deux
         * façons de décider, une ici et une dans le navigateur, finiraient par
         * ne plus dire la même chose. */
        $cmds = array();
        foreach ($eqLogic->getCmd() as $cmd) {
            $cmds[] = array(
                'id'      => (int) $cmd->getId(),
                'name'    => $cmd->getName(),
                'type'    => $cmd->getType(),
                'subType' => $cmd->getSubType(),
                'generic' => (string) $cmd->getGeneric_type(),
            );
        }
        $lamp = simulationpresenceintelligentbeLamps::classify($eqLogic->getName(), $cmds);
        $cmdId = null;
        if (is_array($lamp)) {
            $cmdId = ($lamp[$_role] !== null) ? $lamp[$_role] : $lamp['toggle'];
        }
        if ($cmdId === null) {
            throw new Exception(__('Aucune commande pour cet ordre sur cette lampe.', __FILE__));
        }
        return cmd::byId($cmdId);
    };

    if (init('action') == 'lamps') {
        ajax::success(array(
            'groups' => simulationpresenceintelligentbeLamps::discover(init('all') == 1),
        ));
    }

    /* Allumer une lampe depuis le sélecteur, pour la reconnaître sans quitter
     * la page. C'est ce qui rend le choix possible dans une installation dont
     * les équipements s'appellent « shellyplafondsaloncotebaie5 ». */
    if (init('action') == 'switchLamp') {
        unautorizedInDemo();
        $order = (init('order') == 'off') ? 'off' : 'on';
        $cmd = $lampCmd(init('eq'), $order, init('cmd'));
        $cmd->execCmd();
        ajax::success(array('summary' => $cmd->getHumanName() . ' : ' . __('ordre envoyé', __FILE__)));
    }

    /*
     * Historiser l'état des lampes choisies.
     *
     * C'est l'action la plus utile du plugin le premier jour : sans historique,
     * il n'y a rien à rejouer, et l'historique ne commence qu'au moment où la
     * case est cochée.
     */
    if (init('action') == 'historize') {
        unautorizedInDemo();
        $eqLogic = $getGroup(init('id'));
        $count = $eqLogic->historizeLamps();
        ajax::success(array(
            'historized' => $count,
            'silent'     => $eqLogic->silentLamps(),
            'summary'    => ($count == 0)
                ? __('Rien à faire : les états sont déjà historisés.', __FILE__)
                : $count . ' ' . __('commande(s) d\'état désormais historisée(s).', __FILE__),
        ));
    }

    /*
     * Ce que le plugin a appris, lampe par lampe.
     *
     * Le nombre de journées observées est la seule chose qui explique pourquoi
     * une lampe est rejouée et une autre inventée. Sans cette page, le plugin
     * paraîtrait décider au hasard.
     */
    if (init('action') == 'learning') {
        $eqLogic = $getGroup(init('id'));
        $learning = simulationpresenceintelligentbe::cleanLearning($eqLogic->getConfiguration('learning'));
        $depth = $learning['depth'];
        $minDays = $learning['min_days'];

        /* Le jour de semaine d'aujourd'hui, parce que c'est lui qui décidera
         * ce soir : annoncer « rejouée » d'après l'ensemble des journées alors
         * que le plan se fera sur le seau du mardi — peut-être vide — ferait
         * dire au tableau l'inverse de ce qui sera joué. */
        $weekday = (int) date('N');

        $rows = array();
        foreach ($eqLogic->getConfiguration('lamps', array()) as $lamp) {
            /* Les lampes désactivées sont décrites elles aussi : les sauter
             * laissait leur ligne du tableau des lampes à « — » pour toujours,
             * état historisé comme état courant. */
            $profile = $eqLogic->profileFor($lamp);
            $bucket = simulationpresenceintelligentbeProfile::bucketFor($profile, $weekday, $minDays);
            $stateCmd = ($lamp['state'] === null) ? null : cmd::byId($lamp['state']);
            if (!is_object($stateCmd)) {
                $stateCmd = null;
            }
            $describe = simulationpresenceintelligentbeLamps::describe($lamp);
            $rows[] = array(
                'eq'       => $lamp['eq'],
                'name'     => ($describe['name'] !== '') ? $describe['name'] : $lamp['name'],
                'object'   => $describe['object'],
                'enabled'  => (int) $lamp['enabled'],
                /* La lampe a-t-elle disparu de l'installation ? C'est la seule
                 * panne que le plugin ne peut pas contourner, et la seule qu'il
                 * doit dire au lieu d'échouer en silence. */
                'missing'  => (int) $describe['missing'],
                'days'     => (int) $bucket['days'],
                'first'    => $profile['first'],
                'last'     => $profile['last'],
                'minutes'  => ($bucket['days'] > 0) ? (int) round($bucket['minutes'] / $bucket['days']) : 0,
                'enough'   => ($bucket['days'] >= $minDays && $bucket['minutes'] > 0) ? 1 : 0,
                'historized' => ($stateCmd !== null && $stateCmd->getIsHistorized() == 1) ? 1 : 0,
                /* L'état vient d'être lu : celui gardé dans la sélection date du
                 * jour où la lampe a été cochée, et une pastille qui ment est
                 * pire qu'une pastille absente. */
                'value'      => simulationpresenceintelligentbeLamps::readState($lamp['state']),
            );
        }
        /* Ce que la fenêtre donne aujourd'hui : une borne écrite « coucher-30 »
         * ne veut rien dire tant qu'on ne voit pas l'heure qu'elle vaut ce
         * soir. */
        $sun = simulationpresenceintelligentbeSun::sun(time(), config::byKey('info::latitude'), config::byKey('info::longitude'));
        $window = simulationpresenceintelligentbe::cleanWindow($eqLogic->getConfiguration('window'));
        $quiet = 0;
        foreach ($eqLogic->getConfiguration('lamps', array()) as $lamp) {
            $profile = $eqLogic->profileFor($lamp);
            if (isset($profile['quiet'])) {
                $quiet = (int) $profile['quiet'];
                break;
            }
        }

        ajax::success(array(
            'depth'    => $depth,
            'min_days' => $minDays,
            'lamps'    => $rows,
            'quiet'    => $quiet,
            'anchor'   => $learning['anchor'],
            'window'   => array(
                'start' => simulationpresenceintelligentbeSun::describeBound($window['start'], $sun),
                'end'   => simulationpresenceintelligentbeSun::describeBound($window['end'], $sun),
            ),
        ));
    }

    /*
     * L'aperçu d'une journée.
     *
     * Calculé par le serveur, avec le code qui jouera réellement le plan : deux
     * implémentations, une pour l'aperçu et une pour l'exécution, divergeraient
     * au premier réglage ajouté, et l'aperçu mentirait sans qu'on le sache.
     */
    if (init('action') == 'preview') {
        $eqLogic = $getGroup(init('id'));
        $date = init('date');
        if ($date == '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        /* L'aperçu ne touche pas au plan du jour en cache : regarder demain ne
         * doit pas changer ce soir. */
        $plan = ($date == date('Y-m-d')) ? $eqLogic->planFor($date) : $eqLogic->buildPlan($date);

        $names = array();
        foreach ($eqLogic->getConfiguration('lamps', array()) as $lamp) {
            $names[(string) $lamp['eq']] = $lamp['name'];
        }
        $lamps = array();
        foreach ($plan['lamps'] as $key => $entry) {
            $summary = simulationpresenceintelligentbeProfile::summary($entry['events']);
            $steps = array();
            foreach ($entry['events'] as $event) {
                $steps[] = array(
                    'time'   => simulationpresenceintelligentbeSun::minuteToTime($event['t']),
                    /* La minute brute sert à dessiner la journée en barres :
                     * la calculer dans le navigateur en relisant « HH:MM »
                     * ferait deux vérités pour une seule donnée. */
                    'minute' => (int) $event['t'],
                    'value'  => (int) $event['v'],
                );
            }
            $lamps[] = array(
                'eq'       => (int) $key,
                'name'     => isset($names[$key]) ? $names[$key] : '',
                'source'   => $entry['source'],
                'days'     => $entry['days'],
                'switches' => $summary['switches'],
                'minutes'  => $summary['minutes'],
                'steps'    => $steps,
            );
        }
        $sun = simulationpresenceintelligentbeSun::sun(strtotime($date . ' 12:00:00'), config::byKey('info::latitude'), config::byKey('info::longitude'));
        $window = simulationpresenceintelligentbe::cleanWindow($eqLogic->getConfiguration('window'));
        ajax::success(array(
            'date'     => $date,
            'learned'  => $plan['learned'],
            'invented' => $plan['invented'],
            'lamps'    => $lamps,
            'window'   => array(
                'start' => simulationpresenceintelligentbeSun::resolveBound($window['start'], $sun, 0),
                'end'   => simulationpresenceintelligentbeSun::resolveBound($window['end'], $sun, simulationpresenceintelligentbeSun::DAY_MINUTES - 1),
            ),
            'sunrise'  => $sun['sunrise'],
            'sunset'   => $sun['sunset'],
        ));
    }

    /* Retirer le plan du jour : le prochain passage en tire un autre. Utile
     * pour voir tout de suite l'effet d'un réglage. */
    if (init('action') == 'replan') {
        unautorizedInDemo();
        $eqLogic = $getGroup(init('id'));
        $eqLogic->forgetPlan();
        ajax::success(array('summary' => __('Nouveau plan tiré pour aujourd\'hui.', __FILE__)));
    }

    /* Démarrer, arrêter, ou rendre la main à la condition. */
    if (init('action') == 'apply') {
        unautorizedInDemo();
        $eqLogic = $getGroup(init('id'));
        $order = init('order');
        if (!in_array($order, array('on', 'off', 'auto'))) {
            throw new Exception(__('Ordre inconnu :', __FILE__) . ' ' . $order);
        }
        $eqLogic->applyAction($order);
        ajax::success(array(
            'active'  => ($eqLogic->getConfiguration('active', 0) == 1) ? 1 : 0,
            'summary' => __('Ordre pris en compte.', __FILE__),
        ));
    }

    /* L'état des conditions, ligne par ligne : c'est ce qui permet de
     * comprendre pourquoi la simulation ne démarre pas. */
    if (init('action') == 'conditions') {
        $eqLogic = $getGroup(init('id'));
        $conditions = simulationpresenceintelligentbe::cleanConditions($eqLogic->getConfiguration('conditions'));
        $rows = array();
        foreach ($conditions['rows'] as $row) {
            $cmd = cmd::byId($row['cmd']);
            $value = null;
            if (is_object($cmd)) {
                try {
                    $value = $cmd->execCmd();
                } catch (Throwable $e) {
                    $value = null;
                }
            }
            $rows[] = array(
                'cmd'   => $row['cmd'],
                'name'  => is_object($cmd) ? $cmd->getHumanName() : __('commande supprimée', __FILE__),
                'value' => ($value === null) ? '' : (string) $value,
                'met'   => (is_object($cmd) && simulationpresenceintelligentbeProfile::compare($value, $row['operator'], $row['value'])) ? 1 : 0,
            );
        }
        $met = $eqLogic->conditionMet();
        ajax::success(array(
            'rows'   => $rows,
            'met'    => ($met === null) ? null : (($met) ? 1 : 0),
            'manual' => $eqLogic->getConfiguration('manual', ''),
            'active' => ($eqLogic->getConfiguration('active', 0) == 1) ? 1 : 0,
        ));
    }

    /*
     * La répétition accélérée. Elle allume et éteint réellement les lampes :
     * d'où le refus quand la simulation tourne déjà, et la remise en place
     * systématique à la fin.
     */
    if (init('action') == 'rehearse') {
        unautorizedInDemo();
        $eqLogic = $getGroup(init('id'));
        if ($eqLogic->getConfiguration('active', 0) == 1) {
            throw new Exception(__('La simulation est en cours : arrêtez-la avant de répéter.', __FILE__));
        }
        $duration = max(30, min(600, (int) init('duration', 120)));
        $rehearsal = $eqLogic->rehearsalPlan($duration);
        if (count($rehearsal['steps']) == 0) {
            throw new Exception(__('Rien à jouer aujourd\'hui : le plan est vide.', __FILE__));
        }
        ajax::success($rehearsal);
    }

    if (init('action') == 'rehearseStep') {
        unautorizedInDemo();
        $eqLogic = $getGroup(init('id'));
        ajax::success($eqLogic->rehearsalStep(init('eq'), init('value')));
    }

    if (init('action') == 'rehearseStop') {
        unautorizedInDemo();
        $eqLogic = $getGroup(init('id'));
        $remises = $eqLogic->rehearsalStop();
        ajax::success(array(
            'restored' => $remises,
            'summary'  => ($remises == 0)
                ? __('Répétition terminée ; les lampes n\'avaient pas bougé.', __FILE__)
                : $remises . ' ' . __('lampe(s) remise(s) comme elles étaient.', __FILE__),
        ));
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

} catch (Throwable $e) {
    // Throwable et non Exception : en PHP 8 une Error (méthode inexistante,
    // erreur de type) n'hérite pas d'Exception et donnerait un HTTP 500 muet.
    ajax::error(displayException($e), $e->getCode());
}
