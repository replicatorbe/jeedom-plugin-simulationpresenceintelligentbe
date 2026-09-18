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

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/simulationpresenceintelligentbeSun.class.php';
require_once __DIR__ . '/simulationpresenceintelligentbeLamps.class.php';
require_once __DIR__ . '/simulationpresenceintelligentbeProfile.class.php';

/*
 * Faire croire que la maison est habitée, en rejouant ce qu'elle fait d'habitude.
 *
 * Un équipement du plugin, c'est un groupe de lampes et de prises, une
 * condition de départ, et une fenêtre horaire. Quand la condition est remplie —
 * l'alarme est armée, personne n'est là — le plugin construit pour la journée
 * un plan tiré de l'historique de chaque lampe, puis le joue minute par minute.
 *
 * Là où il n'y a pas assez d'histoire, il en invente une, calée sur le coucher
 * du soleil. Un plugin qui ne fait rien tant qu'il n'a pas appris n'est jamais
 * adopté.
 *
 * Tout ce qui décide se trouve dans simulationpresenceintelligentbeProfile, qui
 * ne connaît pas Jeedom et s'éprouve hors ligne. Cette classe-ci ne fait que
 * lire l'historique, exécuter des commandes et tenir des compteurs.
 */
class simulationpresenceintelligentbe extends eqLogic {

    /* Profondeur d'apprentissage par défaut, en jours. Quatre semaines : assez
     * pour que chaque jour de semaine ait été vu quatre fois, pas assez pour
     * qu'un changement de saison passe inaperçu. */
    const DEFAULT_DEPTH = 28;

    /* Délai laissé à une lampe pour confirmer un ordre avant qu'un écart soit
     * pris pour un geste humain. Deux minutes couvrent le pire des modules
     * MQTT qui ne publient leur état qu'au passage suivant ; le réglage est
     * dans la page de configuration du plugin, parce que la valeur juste
     * dépend du protocole et qu'aucune valeur ne convient à tout le monde. */
    const DEFAULT_ORDER_GRACE = 120;

    public static function orderGrace() {
        return max(30, (int) config::byKey('order_grace', __CLASS__, self::DEFAULT_ORDER_GRACE));
    }

    /* Durée de vie du plan et du profil en cache. Le plan vaut pour la journée,
     * le profil est refait chaque nuit ; on garde deux jours pour qu'une box
     * redémarrée à minuit ne reparte pas de rien. */
    const CACHE_TTL = 172800;

    /* Les jours où le plugin a lui-même piloté les lampes sont écartés de
     * l'apprentissage : sans cela, il apprendrait ses propres inventions et
     * dériverait un peu plus chaque semaine. On en garde la trace pour deux
     * fois la profondeur d'apprentissage. */
    const SIMULATED_MEMORY = 90;

    /* Délai avant de réessayer une lampe dont l'ordre a échoué. Sans lui, une
     * lampe dont l'équipement a été désactivé produit une exception, une ligne
     * d'erreur et une tentative par minute, toute la soirée durant. */
    const FAILURE_BACKOFF = 900;

    public static $_operators = array('==', '!=', '>', '>=', '<', '<=');

    /* ==================================================================== CRON */

    /*
     * Chaque minute : regarder la condition, jouer le plan.
     *
     * Tous les plugins partagent le même processus et plugin::cron a deux
     * minutes pour finir. Le travail coûteux — relire l'historique, construire
     * un profil — est donc fait la nuit et mis en cache ; ici, on ne fait que
     * comparer des nombres et pousser les ordres dus à cette minute.
     */
    public static function cron() {
        if (!jeedom::isStarted()) {
            return;
        }
        $now = time();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->runMinute($now);
            } catch (Throwable $e) {
                /* Un groupe en échec ne doit pas priver les autres de leur
                 * soirée : la maison resterait noire pour une lampe absente. */
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /*
     * Chaque nuit : refaire les profils, oublier les vieux jours simulés.
     *
     * cronDaily tombe à minuit et dispose de quatre heures. C'est là que se
     * paie le coût de la lecture d'historique, une fois par lampe et par jour,
     * plutôt qu'à chaque minute.
     */
    public static function cronDaily() {
        self::checkPosition();
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            try {
                $eqLogic->forgetPlan();
                $eqLogic->buildProfiles();
                $eqLogic->pruneSimulatedDays();
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
    }

    /* =============================================================== POSITION */

    /*
     * La position de l'installation, et le message qui va avec.
     *
     * Posé à l'installation et retiré dès que les coordonnées apparaissent :
     * un avertissement qu'on ne peut pas faire disparaître en corrigeant ce
     * qu'il reproche est un compteur rouge à vie dans le centre de messages.
     */
    public static function checkPosition() {
        if (config::byKey('info::latitude') == '' || config::byKey('info::longitude') == '') {
            message::add(__CLASS__, __('Renseignez la position de votre installation (Réglages → Système → Configuration → Général) pour que les journées inventées suivent le coucher du soleil.', __FILE__), '', 'position');
            return false;
        }
        message::removeAll(__CLASS__, 'position');
        return true;
    }

    /* ===================================================== CYCLE DE VIE eqLogic */

    public function preSave() {
        $this->setConfiguration('lamps', self::cleanLamps($this->getConfiguration('lamps')));
        $this->setConfiguration('conditions', self::cleanConditions($this->getConfiguration('conditions')));
        $this->setConfiguration('window', self::cleanWindow($this->getConfiguration('window')));
        $this->setConfiguration('guards', self::cleanGuards($this->getConfiguration('guards')));
        $this->setConfiguration('learning', self::cleanLearning($this->getConfiguration('learning')));
        $this->setConfiguration('invent', self::cleanInvent($this->getConfiguration('invent')));

        /* La tuile porte deux lignes de texte sous son icône ; la largeur par
         * défaut du coeur les coupe. */
        if ($this->getDisplay('width') == '') {
            $this->setDisplay('width', '300px');
        }

        /*
         * Aucune exception ici, même sans lampe et sans condition : le coeur
         * crée l'équipement avec son seul nom, et toute validation rendrait le
         * bouton « Ajouter » définitivement inopérant.
         */
    }

    public function postSave() {
        $this->createCommands();
        try {
            /* Les lampes ont pu changer : le plan du jour ne leur correspond
             * plus, et le profil non plus. */
            $this->forgetPlan();
            $this->refreshInfo(time());
        } catch (Throwable $e) {
            log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . $e->getMessage());
        }
    }

    /* preRemove et non postRemove : DB::remove() met l'identifiant à null avant
     * d'appeler postRemove, et les clés de cache seraient introuvables. */
    public function preRemove() {
        $this->forgetPlan();
        cache::delete($this->runtimeKey());
        cache::delete($this->holdKey());
        /* Les profils sont rangés par lampe : les oublier laisserait derrière
         * chaque groupe supprimé autant d'entrées de cache que de lampes, pour
         * deux jours. */
        foreach ($this->getConfiguration('lamps', array()) as $lamp) {
            cache::delete($this->profileKey($lamp['eq']));
        }
        message::removeAll(__CLASS__, $this->messageKey(), true);
    }

    /* ============================================================== COMMANDES */

    /*
     * Les commandes du groupe.
     *
     * Aucune ne porte de type générique, et c'est un choix : le coeur n'en
     * propose que pour des lampes (LIGHT_*) et des prises (ENERGY_*), or « la
     * simulation tourne-t-elle ? » n'est ni l'un ni l'autre. Poser LIGHT_ON sur
     * « Démarrer » ferait apparaître le groupe comme une lampe dans tous les
     * sélecteurs de l'installation — y compris ceux des plugins voisins, qui
     * proposeraient de programmer la simulation de présence comme un éclairage.
     * Le prix à payer est que les assistants vocaux ignorent ces commandes.
     */
    public function createCommands() {
        $order = 0;
        $definitions = array(
            array('logicalId' => 'state', 'name' => __('Simulation', __FILE__),
                  'type' => 'info', 'subType' => 'binary', 'generic' => '',
                  'visible' => 1, 'historized' => 1, 'icon' => 'fas fa-user-secret'),
            array('logicalId' => 'on', 'name' => __('Démarrer', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 1, 'icon' => 'fas fa-play'),
            array('logicalId' => 'off', 'name' => __('Arrêter', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 1, 'icon' => 'fas fa-stop'),
            array('logicalId' => 'auto', 'name' => __('Revenir à la condition', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => 'fas fa-magic'),
            array('logicalId' => 'mode', 'name' => __('Source du plan', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 1, 'icon' => 'fas fa-graduation-cap'),
            array('logicalId' => 'next', 'name' => __('Prochain changement', __FILE__),
                  'type' => 'info', 'subType' => 'string', 'generic' => '',
                  'visible' => 1, 'icon' => 'fas fa-clock'),
            array('logicalId' => 'lit', 'name' => __('Lampes allumées', __FILE__),
                  'type' => 'info', 'subType' => 'numeric', 'generic' => '',
                  'visible' => 1, 'historized' => 1, 'icon' => 'fas fa-lightbulb'),
            array('logicalId' => 'replan', 'name' => __('Tirer un autre plan', __FILE__),
                  'type' => 'action', 'subType' => 'other', 'generic' => '',
                  'visible' => 0, 'icon' => 'fas fa-dice'),
        );

        foreach ($definitions as $definition) {
            $cmd = $this->getCmd(null, $definition['logicalId']);
            $isNew = !is_object($cmd);
            if ($isNew) {
                $cmd = new simulationpresenceintelligentbeCmd();
                $cmd->setLogicalId($definition['logicalId']);
                $cmd->setEqLogic_id($this->getId());
                $cmd->setName($definition['name']);
                $cmd->setIsVisible($definition['visible']);
                $cmd->setIsHistorized(isset($definition['historized']) ? $definition['historized'] : 0);
                if ($definition['icon'] != '') {
                    $cmd->setDisplay('icon', '<i class="' . $definition['icon'] . '"></i>');
                }
            }
            /*
             * Le type et le type générique sont reposés à chaque enregistrement,
             * le nom et la visibilité non : ces deux-là appartiennent à
             * l'utilisateur dès qu'il y a touché, et les réécrire annulerait sa
             * personnalisation à chaque mise à jour du plugin.
             */
            $cmd->setType($definition['type']);
            $cmd->setSubType($definition['subType']);
            $cmd->setGeneric_type($definition['generic']);
            $cmd->setOrder($order++);
            $cmd->save();
        }

        /* Les boutons agissent sur l'état affiché : c'est ce lien qui donne au
         * tableau de bord une tuile qui bascule au lieu de deux boutons muets. */
        $state = $this->getCmd(null, 'state');
        foreach (array('on', 'off') as $logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            if (is_object($cmd) && is_object($state) && $cmd->getValue() != $state->getId()) {
                $cmd->setValue($state->getId());
                $cmd->save();
            }
        }
    }

    /* =========================================================== NORMALISATION */

    /*
     * Les lampes choisies, ramenées à leur forme utile.
     *
     * Une lampe est retenue par identifiants : celui de l'équipement, et ceux
     * de ses commandes. Un renommage ne casse donc rien ; une suppression, si,
     * et c'est le seul cas où il faut le dire plutôt qu'échouer en silence.
     */
    public static function cleanLamps($_lamps) {
        $clean = array();
        if (!is_array($_lamps)) {
            return $clean;
        }
        $seen = array();
        foreach ($_lamps as $lamp) {
            if (!is_array($lamp) || !isset($lamp['eq'])) {
                continue;
            }
            $eq = (int) $lamp['eq'];
            if ($eq <= 0 || isset($seen[$eq])) {
                continue;
            }
            $seen[$eq] = true;
            $clean[] = array(
                'eq'      => $eq,
                'name'    => isset($lamp['name']) ? (string) $lamp['name'] : '',
                'object'  => isset($lamp['object']) ? (string) $lamp['object'] : '',
                'on'      => self::cleanCmdId(isset($lamp['on']) ? $lamp['on'] : null),
                'off'     => self::cleanCmdId(isset($lamp['off']) ? $lamp['off'] : null),
                'toggle'  => self::cleanCmdId(isset($lamp['toggle']) ? $lamp['toggle'] : null),
                'state'   => self::cleanCmdId(isset($lamp['state']) ? $lamp['state'] : null),
                'enabled' => (isset($lamp['enabled']) && $lamp['enabled'] == 0) ? 0 : 1,
            );
        }
        return $clean;
    }

    public static function cleanCmdId($_value) {
        if ($_value === null || $_value === '' || $_value === false) {
            return null;
        }
        $id = (int) str_replace('#', '', (string) $_value);
        return ($id > 0) ? $id : null;
    }

    /*
     * Les conditions de départ.
     *
     * Une liste, et non une seule condition : « l'alarme est armée » et « il n'y
     * a personne » sont deux informations distinctes dans presque toutes les
     * installations, et les exiger ensemble est justement ce qui évite
     * d'allumer les lampes pendant que quelqu'un dort à l'étage.
     */
    public static function cleanConditions($_conditions) {
        $clean = array(
            'enable'  => 0,
            'combine' => 'and',
            'delay'   => 2,
            'release' => 2,
            'rows'    => array(),
        );
        if (!is_array($_conditions)) {
            return $clean;
        }
        $clean['enable']  = (isset($_conditions['enable']) && $_conditions['enable'] == 1) ? 1 : 0;
        $clean['combine'] = (isset($_conditions['combine']) && $_conditions['combine'] == 'or') ? 'or' : 'and';
        /* Un champ numérique vidé vaut sa valeur par défaut, pas zéro : « je
         * ne sais pas quoi mettre » n'est pas « démarre tout de suite ». Le
         * marqueur du champ annonce d'ailleurs la valeur par défaut. */
        if (isset($_conditions['delay']) && $_conditions['delay'] !== '') {
            $clean['delay'] = max(0, min(720, (int) $_conditions['delay']));
        }
        if (isset($_conditions['release']) && $_conditions['release'] !== '') {
            $clean['release'] = max(0, min(720, (int) $_conditions['release']));
        }

        if (isset($_conditions['rows']) && is_array($_conditions['rows'])) {
            foreach ($_conditions['rows'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cmdId = self::cleanCmdId(isset($row['cmd']) ? $row['cmd'] : null);
                if ($cmdId === null) {
                    continue;
                }
                $operator = (isset($row['operator']) && in_array($row['operator'], self::$_operators)) ? $row['operator'] : '==';
                $clean['rows'][] = array(
                    'cmd'      => $cmdId,
                    'operator' => $operator,
                    'value'    => isset($row['value']) ? trim((string) $row['value']) : '1',
                    /* Le nom lisible est gardé comme libellé de repli. Il n'est
                     * jamais relu pour décider quoi que ce soit — c'est
                     * l'identifiant qui compte, et un renommage ne casse donc
                     * rien — mais sans lui le tableau affiche « Choisissez une
                     * commande » sur des conditions parfaitement réglées, tant
                     * que le serveur n'a pas répondu. Ou pour toujours, s'il ne
                     * répond pas. */
                    'name'     => isset($row['name']) ? trim((string) $row['name']) : '',
                );
            }
        }
        return $clean;
    }

    public static function cleanWindow($_window) {
        $clean = array('start' => '07:00', 'end' => '23:30');
        if (!is_array($_window)) {
            return $clean;
        }
        foreach (array('start', 'end') as $key) {
            if (!isset($_window[$key])) {
                continue;
            }
            $time = simulationpresenceintelligentbeSun::cleanTime($_window[$key]);
            if ($time !== '') {
                $clean[$key] = $time;
            }
        }
        return $clean;
    }

    public static function cleanGuards($_guards) {
        $clean = array('max_on' => 3, 'restore' => 1, 'manual' => 60);
        if (!is_array($_guards)) {
            return $clean;
        }
        if (isset($_guards['max_on']) && $_guards['max_on'] !== '') {
            /* Zéro veut dire « sans limite ». Le champ le dit, et c'est plus
             * honnête qu'un nombre arbitrairement grand. Un champ vidé, en
             * revanche, reprend la valeur par défaut : sans cette distinction,
             * effacer le contenu du champ retirerait le garde-fou. */
            $clean['max_on'] = max(0, min(50, (int) $_guards['max_on']));
        }
        $clean['restore'] = (isset($_guards['restore']) && $_guards['restore'] == 0) ? 0 : 1;
        if (isset($_guards['manual']) && $_guards['manual'] !== '') {
            $clean['manual'] = max(0, min(1440, (int) $_guards['manual']));
        }
        return $clean;
    }

    public static function cleanLearning($_learning) {
        $clean = array('depth' => self::DEFAULT_DEPTH, 'min_days' => simulationpresenceintelligentbeProfile::MIN_DAYS);
        if (!is_array($_learning)) {
            return $clean;
        }
        if (isset($_learning['depth']) && $_learning['depth'] !== '') {
            $clean['depth'] = max(7, min(365, (int) $_learning['depth']));
        }
        if (isset($_learning['min_days']) && $_learning['min_days'] !== '') {
            $clean['min_days'] = max(1, min(30, (int) $_learning['min_days']));
        }
        return $clean;
    }

    /*
     * Les réglages d'invention.
     *
     * Deux d'entre eux sont des heures et non des durées — le coucher et le
     * lever — et une heure reste une heure jusqu'à la génération : stockée en
     * minutes, elle réapparaîtrait dans le formulaire sous la forme « 1380 »,
     * que personne ne sait relire. La conversion se fait donc au dernier
     * moment, dans buildPlan().
     */
    public static function cleanInvent($_invent) {
        $invent = is_array($_invent) ? $_invent : array();

        $times = array('bedtime' => '23:00', 'wake' => '07:00');
        $kept = array();
        foreach ($times as $key => $default) {
            $time = simulationpresenceintelligentbeSun::cleanTime(isset($invent[$key]) ? $invent[$key] : '');
            $kept[$key] = ($time === '') ? $default : $time;
            unset($invent[$key]);
        }

        $clean = simulationpresenceintelligentbeProfile::cleanOptions($invent);
        /* La fenêtre appartient à l'onglet Départ, pas à celui-ci : la laisser
         * ici en ferait deux réglages pour une seule idée, et le dernier
         * enregistré gagnerait. */
        unset($clean['window_start'], $clean['window_end']);
        return array_merge($clean, $kept);
    }

    /* ============================================================ APPRENTISSAGE */

    /*
     * Historiser l'état des lampes choisies.
     *
     * Sans historique, il n'y a rien à rejouer, et l'historique de Jeedom ne
     * commence qu'au moment où on coche la case : c'est donc la toute première
     * chose que le plugin doit faire d'une lampe qu'on lui confie, sans attendre
     * que l'utilisateur découvre trois semaines plus tard qu'il n'a rien appris.
     *
     * Rend le nombre de commandes nouvellement historisées.
     */
    public function historizeLamps() {
        $count = 0;
        foreach ($this->getConfiguration('lamps', array()) as $lamp) {
            if ($lamp['state'] === null) {
                continue;
            }
            $cmd = cmd::byId($lamp['state']);
            if (!is_object($cmd) || $cmd->getIsHistorized() == 1) {
                continue;
            }
            $cmd->setIsHistorized(1);
            $cmd->save();
            $count++;
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('historisation activée sur', __FILE__) . ' ' . $cmd->getHumanName());
        }
        return $count;
    }

    /* Les lampes qui ne publient pas d'état, ou dont l'état n'est pas
     * historisé : elles ne pourront jamais être apprises, seulement inventées. */
    public function silentLamps() {
        $silent = array();
        foreach ($this->getConfiguration('lamps', array()) as $lamp) {
            if ($lamp['enabled'] != 1) {
                continue;
            }
            if ($lamp['state'] === null) {
                $silent[] = array('name' => $lamp['name'], 'reason' => 'nostate');
                continue;
            }
            $cmd = cmd::byId($lamp['state']);
            if (!is_object($cmd)) {
                $silent[] = array('name' => $lamp['name'], 'reason' => 'missing');
            } elseif ($cmd->getIsHistorized() != 1) {
                $silent[] = array('name' => $lamp['name'], 'reason' => 'nohistory');
            }
        }
        return $silent;
    }

    /*
     * Les journées observées d'une commande d'état, prêtes pour le profil.
     *
     * Une seule requête pour toute la profondeur, et non une par jour. À vingt-
     * huit jours et huit lampes, une requête par jour faisait deux cent vingt-
     * quatre requêtes dans la minute du cron — laquelle dispose de deux minutes
     * pour tous les plugins de la box réunis. À trois cent soixante-cinq jours,
     * elle n'en sortait plus.
     *
     * history::all() réunit les tables history et historyArch, rend les points
     * triés, et son dernier paramètre ajoute la dernière valeur connue avant le
     * début de la plage : c'est elle qui donne l'état initial, sans lequel une
     * lampe allumée depuis la veille passerait pour éteinte toute la matinée.
     *
     * Les journées pendant lesquelles le plugin pilotait lui-même les lampes
     * sont écartées : il apprendrait ses propres inventions.
     */
    public function collectDays($_cmdId, $_depth) {
        $days = array();
        $cmd = cmd::byId($_cmdId);
        if (!is_object($cmd) || $cmd->getIsHistorized() != 1) {
            return $days;
        }

        $simulated = $this->getConfiguration('simulated_days', array());
        if (!is_array($simulated)) {
            $simulated = array();
        }

        $depth = max(1, (int) $_depth);
        $from  = strtotime('today -' . $depth . ' day');
        /* La journée en cours est exclue : elle est incomplète, et c'est
         * justement celle que le plugin est peut-être en train de jouer. */
        $to    = strtotime('today') - 1;

        $rows = $cmd->getHistory(date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to), null, true);
        if (!is_array($rows)) {
            $rows = array();
        }

        $byDate = array();
        $state = 0;
        foreach ($rows as $row) {
            $timestamp = strtotime($row->getDatetime());
            if ($timestamp === false) {
                continue;
            }
            $value = ($row->getValue() == 1) ? 1 : 0;
            if ($timestamp < $from) {
                /* Le point que le coeur ajoute pour l'état initial est daté
                 * deux secondes avant le début de la plage : il appartient à la
                 * veille et ne décrit que l'état de départ. */
                $state = $value;
                continue;
            }
            $date = date('Y-m-d', $timestamp);
            if (!isset($byDate[$date])) {
                $byDate[$date] = array();
            }
            $byDate[$date][] = array('t' => simulationpresenceintelligentbeSun::minuteOfDay($timestamp), 'v' => $value);
        }

        /*
         * Chaque journée de la plage reçoit une entrée, y compris celles qui
         * n'ont produit aucune ligne d'historique.
         *
         * Jeedom n'écrit une ligne que lorsque la valeur change : une lampe de
         * chambre d'amis allumée six soirs sur vingt-huit n'a d'historique que
         * ces six jours-là. Sauter les vingt-deux autres revenait à ne
         * moyenner que sur les jours actifs, et le plugin allumait cette lampe
         * presque tous les soirs — en annonçant la rejouer fidèlement.
         */
        foreach (self::dateRange($depth) as $date) {
            $day = array(array('t' => 0, 'v' => $state));
            if (isset($byDate[$date])) {
                foreach ($byDate[$date] as $point) {
                    $day[] = $point;
                }
                $closing = end($byDate[$date]);
                $state = $closing['v'];
            }
            /* L'état est reporté même pour un jour écarté : sans cela, le jour
             * suivant repartirait d'un état faux. */
            if (!in_array($date, $simulated)) {
                $days[$date] = $day;
            }
        }
        return $days;
    }

    /* Les dates de la plage d'apprentissage, de la plus ancienne à la veille. */
    public static function dateRange($_depth) {
        $dates = array();
        for ($offset = (int) $_depth; $offset >= 1; $offset--) {
            $dates[] = date('Y-m-d', strtotime('today -' . $offset . ' day'));
        }
        return $dates;
    }

    /*
     * Refaire le profil de chaque lampe et le mettre en cache.
     *
     * Une fois par nuit : c'est la seule opération coûteuse du plugin, une
     * requête d'historique par lampe et par journée de profondeur.
     */
    public function buildProfiles() {
        $learning = $this->getConfiguration('learning', array());
        $depth = isset($learning['depth']) ? (int) $learning['depth'] : self::DEFAULT_DEPTH;
        $built = 0;

        foreach ($this->getConfiguration('lamps', array()) as $lamp) {
            if ($lamp['enabled'] != 1 || $lamp['state'] === null) {
                continue;
            }
            $days = $this->collectDays($lamp['state'], $depth);
            $profile = simulationpresenceintelligentbeProfile::build($days);
            cache::set($this->profileKey($lamp['eq']), json_encode($profile), self::CACHE_TTL);
            $built++;
        }
        return $built;
    }

    /* Le profil d'une lampe, construit à la demande s'il n'est pas en cache :
     * le cache est volatile par nature, et un vidage ne doit pas priver la
     * maison de sa soirée. */
    public function profileFor($_lamp) {
        $raw = cache::byKey($this->profileKey($_lamp['eq']))->getValue('');
        if ($raw !== '') {
            $profile = json_decode($raw, true);
            if (is_array($profile) && isset($profile['buckets'])) {
                return $profile;
            }
        }
        if ($_lamp['state'] === null) {
            return simulationpresenceintelligentbeProfile::build(array());
        }
        $learning = $this->getConfiguration('learning', array());
        $depth = isset($learning['depth']) ? (int) $learning['depth'] : self::DEFAULT_DEPTH;
        $profile = simulationpresenceintelligentbeProfile::build($this->collectDays($_lamp['state'], $depth));
        cache::set($this->profileKey($_lamp['eq']), json_encode($profile), self::CACHE_TTL);
        return $profile;
    }

    /* ============================================================ PLAN DU JOUR */

    /*
     * Le plan de la journée : pour chaque lampe, la liste de ses changements.
     *
     * Construit une fois par jour et mis en cache. Le tirage étant entièrement
     * déterminé par la graine — l'identifiant du groupe, celui de la lampe et
     * la date — un plan perdu est reconstruit à l'identique : une box redémarrée
     * à 21 h reprend la soirée là où elle en était, et ne recommence pas une
     * autre soirée par-dessus.
     */
    public function planFor($_date = '') {
        $date = ($_date === '') ? date('Y-m-d') : $_date;
        $raw = cache::byKey($this->planKey($date))->getValue('');
        if ($raw !== '') {
            $plan = json_decode($raw, true);
            if (is_array($plan) && isset($plan['lamps'])) {
                return $plan;
            }
        }
        $plan = $this->buildPlan($date);
        cache::set($this->planKey($date), json_encode($plan), self::CACHE_TTL);
        return $plan;
    }

    public function buildPlan($_date) {
        $timestamp = strtotime($_date . ' 12:00:00');
        if ($timestamp === false) {
            $timestamp = time();
        }
        $window   = $this->getConfiguration('window', array());
        $learning = $this->getConfiguration('learning', array());
        $invent   = $this->getConfiguration('invent', array());
        $minDays  = isset($learning['min_days']) ? (int) $learning['min_days'] : simulationpresenceintelligentbeProfile::MIN_DAYS;

        $start = simulationpresenceintelligentbeSun::timeToMinute(isset($window['start']) ? $window['start'] : '');
        $end   = simulationpresenceintelligentbeSun::timeToMinute(isset($window['end']) ? $window['end'] : '');
        $invent = is_array($invent) ? $invent : array();
        foreach (array('bedtime' => 23 * 60, 'wake' => 7 * 60) as $key => $default) {
            $minute = simulationpresenceintelligentbeSun::timeToMinute(isset($invent[$key]) ? $invent[$key] : '');
            $invent[$key] = ($minute === null) ? $default : $minute;
        }
        $options = simulationpresenceintelligentbeProfile::cleanOptions(array_merge($invent, array(
            'window_start' => ($start === null) ? 0 : $start,
            'window_end'   => ($end === null) ? simulationpresenceintelligentbeSun::DAY_MINUTES - 1 : $end,
        )));

        $sun = simulationpresenceintelligentbeSun::sun($timestamp, config::byKey('info::latitude'), config::byKey('info::longitude'));
        $weekday = (int) date('N', $timestamp);

        /*
         * Le sel du retirage. Sans lui, « Tirer un autre plan » rendait
         * exactement le même plan : la graine ne dépendant que du groupe, de la
         * lampe et de la date, vider le cache et reconstruire redonne le même
         * tirage à la minute près. Le bouton promettait donc quelque chose que
         * le code ne pouvait pas faire. Le sel ne change qu'à la demande, ce
         * qui préserve la reprise à l'identique après un redémarrage.
         */
        $salt = (int) $this->getConfiguration('replan_salt', 0);

        /*
         * Le décalage du jour, en deux parts. Celle du groupe s'applique à
         * toutes ses lampes : un soir où l'on rentre tard, c'est toute la
         * maison qui s'allume tard, et non six lampes qui décident chacune dans
         * leur coin. Sans cette part commune, l'ordre dans lequel les pièces
         * s'éclairent n'a aucune cause visible, et c'est ce qui trahit une
         * simulation pour qui regarde la façade plusieurs soirs.
         */
        $groupShift = simulationpresenceintelligentbeProfile::dayShift(
            $options['variability'],
            simulationpresenceintelligentbeProfile::SHIFT_GROUP,
            $this->getId() . '|' . $_date . '|' . $salt
        );

        $plan = array('date' => $_date, 'learned' => 0, 'invented' => 0, 'lamps' => array());
        foreach ($this->getConfiguration('lamps', array()) as $lamp) {
            if ($lamp['enabled'] != 1) {
                continue;
            }
            $seed = $this->getId() . '|' . $lamp['eq'] . '|' . $_date . '|' . $salt;
            $shift = $groupShift + simulationpresenceintelligentbeProfile::dayShift(
                $options['variability'],
                simulationpresenceintelligentbeProfile::SHIFT_LAMP,
                $seed
            );

            $profile = $this->profileFor($lamp);
            $bucket  = simulationpresenceintelligentbeProfile::bucketFor($profile, $weekday, $minDays);

            if ($bucket['days'] >= $minDays && $bucket['minutes'] > 0) {
                $events = simulationpresenceintelligentbeProfile::generate($bucket, $options, $seed, $shift);
                $source = 'learned';
                $plan['learned']++;
            } else {
                $events = simulationpresenceintelligentbeProfile::invent($options, $sun, $seed, $shift);
                $source = 'invented';
                $plan['invented']++;
            }

            $plan['lamps'][(string) $lamp['eq']] = array(
                'source' => $source,
                'days'   => (int) $bucket['days'],
                'events' => $events,
            );

            if (config::byKey('log_plan', __CLASS__, 0) == 1) {
                $steps = array();
                foreach ($events as $event) {
                    $steps[] = simulationpresenceintelligentbeSun::minuteToTime($event['t']) . (($event['v'] == 1) ? '+' : '-');
                }
                log::add(__CLASS__, 'info', $this->getHumanName() . ' — ' . $_date . ' — ' . $lamp['name']
                    . ' (' . $source . ', ' . $bucket['days'] . ' ' . __('journée(s)', __FILE__) . ') : '
                    . (count($steps) == 0 ? __('rien', __FILE__) : implode(' ', $steps)));
            }
        }
        return $plan;
    }

    /* Un plan retiré est un plan refait au prochain passage : c'est ce que fait
     * le bouton « Retirer un plan », et ce que fait l'enregistrement d'un
     * groupe dont les lampes ont changé. */
    public function forgetPlan() {
        cache::delete($this->planKey(date('Y-m-d')));
        cache::delete($this->planKey(date('Y-m-d', strtotime('tomorrow'))));
    }

    /* ========================================================== CONDITION */

    /*
     * La condition de départ est-elle remplie ?
     *
     * Rend null quand il n'y a rien à évaluer — pas de condition, ou aucune
     * commande lisible. C'est différent de « non » : un groupe sans condition
     * doit rester sur son réglage manuel, pas s'arrêter tout seul.
     */
    public function conditionMet() {
        $conditions = $this->getConfiguration('conditions', array());
        if (!is_array($conditions) || !isset($conditions['enable']) || $conditions['enable'] != 1
            || !isset($conditions['rows']) || count($conditions['rows']) == 0) {
            return null;
        }

        $results = array();
        foreach ($conditions['rows'] as $row) {
            $cmd = cmd::byId($row['cmd']);
            if (!is_object($cmd)) {
                /* Une condition dont la commande a disparu ne peut pas être
                 * vraie : mieux vaut une simulation qui ne part pas qu'une
                 * simulation qui part pendant que la maison est occupée. */
                $results[] = false;
                continue;
            }
            try {
                $results[] = simulationpresenceintelligentbeProfile::compare($cmd->execCmd(), $row['operator'], $row['value']);
            } catch (Throwable $e) {
                $results[] = false;
            }
        }

        if (count($results) == 0) {
            return null;
        }
        if ($conditions['combine'] == 'or') {
            return in_array(true, $results, true);
        }
        return !in_array(false, $results, true);
    }

    /* ============================================================= EXÉCUTION */

    /*
     * Une minute de simulation.
     *
     * L'ordre des opérations compte : on décide d'abord si la simulation doit
     * tourner, ensuite seulement on joue le plan. L'inverse laisserait une
     * lampe s'allumer dans la seconde qui suit le désarmement de l'alarme.
     */
    public function runMinute($_now) {
        $active = ($this->getConfiguration('active', 0) == 1);
        $manual = $this->getConfiguration('manual', '');

        $met = $this->conditionMet();
        if ($met !== null && $manual === '') {
            $conditions = $this->getConfiguration('conditions', array());
            $delay   = isset($conditions['delay']) ? (int) $conditions['delay'] : 0;
            $release = isset($conditions['release']) ? (int) $conditions['release'] : 0;
            if ($met && !$active) {
                /* Le délai de confirmation évite qu'un capteur qui hésite une
                 * minute lance toute une soirée. */
                if ($this->holdFor('met', $_now, $delay)) {
                    $this->startSimulation($_now, __('condition remplie', __FILE__));
                    $active = true;
                }
            } elseif (!$met && $active) {
                if ($this->holdFor('unmet', $_now, $release)) {
                    $this->stopSimulation($_now, __('condition retombée', __FILE__));
                    $active = false;
                }
            } else {
                $this->clearHold();
            }
        }

        /*
         * L'état d'exécution est relu ici et non au début : un démarrage ou un
         * arrêt vient peut-être de l'effacer, et le réécrire tel qu'il était
         * avant ferait croire au passage suivant que les lampes ont déjà reçu
         * leurs ordres. Elles resteraient éteintes toute la soirée.
         */
        $runtime = $this->runtime();

        /*
         * Le jour en cours est marqué comme simulé à chaque minute active, et
         * non au seul démarrage. Une simulation de vacances démarre une fois
         * pour deux semaines : n'écrire qu'au démarrage ne marquait que le
         * premier jour, et les treize autres revenaient dans l'apprentissage —
         * le plugin réapprenait ses propres inventions, exactement la dérive
         * qu'on voulait éviter. L'écriture en base n'a lieu qu'une fois par
         * journée, au premier passage.
         */
        if ($active && $this->rememberSimulatedDay(date('Y-m-d', $_now))) {
            $this->save();
        }

        /* Un seul plan pour la minute : playPlan et refreshInfo le lisaient
         * chacun de leur côté, soit deux lectures de cache et deux décodages
         * JSON par minute et par groupe. */
        $plan = $this->planFor(date('Y-m-d', $_now));
        if ($active) {
            $this->playPlan($_now, $runtime, $plan);
        }
        $this->saveRuntime($runtime);
        $this->refreshInfo($_now, $runtime, $plan);
    }

    /*
     * Une condition tenue assez longtemps.
     *
     * Le premier passage note l'heure, les suivants comparent. Un délai nul
     * répond vrai tout de suite, ce qui reste le réglage le plus courant.
     */
    private function holdFor($_key, $_now, $_minutes) {
        if ($_minutes <= 0) {
            $this->clearHold();
            return true;
        }
        $hold = cache::byKey($this->holdKey())->getValue('');
        $parts = explode('|', (string) $hold);
        if (count($parts) != 2 || $parts[0] != $_key) {
            cache::set($this->holdKey(), $_key . '|' . $_now, 86400);
            return false;
        }
        if (($_now - (int) $parts[1]) < ($_minutes * 60)) {
            return false;
        }
        $this->clearHold();
        return true;
    }

    private function clearHold() {
        cache::delete($this->holdKey());
    }

    /*
     * Démarrer.
     *
     * L'état de chaque lampe est relevé avant le premier ordre : c'est lui qui
     * sera remis à l'arrêt. Le relever après aurait la même valeur que de ne
     * rien relever du tout.
     */
    public function startSimulation($_now, $_reason) {
        /*
         * L'instantané porte la définition complète de chaque lampe, et pas
         * seulement son état.
         *
         * C'est ce qui permet de la remettre en place à l'arrêt même si elle a
         * été retirée du groupe entre-temps : sinon elle resterait allumée
         * toute la nuit, et l'utilisateur chercherait longtemps pourquoi.
         */
        $snapshot = array();
        foreach ($this->getConfiguration('lamps', array()) as $lamp) {
            if ($lamp['enabled'] != 1) {
                continue;
            }
            $snapshot[(string) $lamp['eq']] = array(
                'name'   => $lamp['name'],
                'on'     => $lamp['on'],
                'off'    => $lamp['off'],
                'toggle' => $lamp['toggle'],
                'state'  => $lamp['state'],
                'value'  => simulationpresenceintelligentbeLamps::readState($lamp['state']),
            );
        }

        $this->setConfiguration('active', 1);
        $this->setConfiguration('started_at', $_now);
        $this->setConfiguration('snapshot', $snapshot);
        $this->rememberSimulatedDay(date('Y-m-d', $_now));
        $this->save();

        cache::delete($this->runtimeKey());
        $this->clearHold();
        /* Les pannes de la soirée précédente ne concernent plus celle-ci. */
        message::removeAll(__CLASS__, $this->messageKey(), true);
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('simulation démarrée', __FILE__) . ' (' . $_reason . ')');
    }

    /*
     * Arrêter, et remettre les lampes comme on les avait trouvées.
     *
     * Le retour à l'état initial n'est pas un luxe : sans lui, rentrer chez soi
     * à minuit veut dire trouver trois lampes allumées qu'on n'a pas allumées,
     * et les éteindre à la main tous les soirs de vacances.
     */
    public function stopSimulation($_now, $_reason) {
        $guards = self::cleanGuards($this->getConfiguration('guards'));
        $runtime = $this->runtime();

        if ($guards['restore'] == 1) {
            $snapshot = $this->getConfiguration('snapshot', array());
            if (!is_array($snapshot)) {
                $snapshot = array();
            }
            /*
             * On parcourt l'instantané et non la liste des lampes : c'est lui
             * qui dit ce qu'il y avait à remettre en place, y compris pour une
             * lampe retirée du groupe depuis le démarrage.
             *
             * Et on ne se sert plus de l'état d'exécution pour décider : il vit
             * en cache, et un vidage de cache pendant la soirée laissait la
             * maison allumée à l'arrêt, sans trace. L'instantané, lui, est en
             * base.
             */
            foreach ($snapshot as $key => $entry) {
                $lamp = $this->snapshotLamp($key, $entry);
                if ($lamp === null) {
                    continue;
                }
                /* Une lampe allumée ou éteinte à la main pendant la simulation
                 * appartient à celui qui l'a touchée. */
                if (isset($runtime['lamps'][$key]) && $runtime['lamps'][$key]['manual_until'] > $_now) {
                    continue;
                }
                $target = ($lamp['value'] === null) ? 0 : (int) $lamp['value'];
                /* Une lampe déjà dans l'état où on veut la remettre n'a pas
                 * besoin d'ordre : sans ce contrôle, chaque arrêt de simulation
                 * publie autant de messages qu'il y a de lampes, pour rien. */
                $actual = simulationpresenceintelligentbeLamps::readState($lamp['state']);
                if ($actual !== null && $actual == $target) {
                    continue;
                }
                $this->orderLamp($lamp, $target, $_now, $runtime);
            }
        }

        $this->setConfiguration('active', 0);
        $this->setConfiguration('snapshot', array());
        $this->save();

        cache::delete($this->runtimeKey());
        $this->clearHold();
        log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('simulation arrêtée', __FILE__) . ' (' . $_reason . ')');
    }

    /*
     * Jouer le plan de la minute.
     *
     * On ne rejoue pas les changements manqués un par un : on lit dans le plan
     * l'état attendu à cette minute et on l'impose. Une box arrêtée deux heures
     * reprend ainsi la soirée dans l'état où elle devrait être, sans rafale
     * d'allumages pour rattraper le retard.
     */
    private function playPlan($_now, &$_runtime, $_plan) {
        $plan = $_plan;
        $guards = self::cleanGuards($this->getConfiguration('guards'));
        $minute = simulationpresenceintelligentbeSun::minuteOfDay($_now);

        $wanted = array();
        foreach ($this->getConfiguration('lamps', array()) as $lamp) {
            $key = (string) $lamp['eq'];
            if ($lamp['enabled'] != 1 || !isset($plan['lamps'][$key])) {
                continue;
            }
            $wanted[$key] = simulationpresenceintelligentbeProfile::stateAt($plan['lamps'][$key]['events'], $minute);
        }

        $wanted = simulationpresenceintelligentbeProfile::capSimultaneous($wanted, $_runtime, (int) $guards['max_on']);

        foreach ($this->getConfiguration('lamps', array()) as $lamp) {
            $key = (string) $lamp['eq'];
            if (!isset($wanted[$key])) {
                continue;
            }
            $entry = $this->lampRuntime($_runtime, $key);

            /* Une lampe dont l'ordre a échoué est laissée de côté un quart
             * d'heure. Sans ce délai, un équipement désactivé produit une
             * exception, une ligne d'erreur et une nouvelle tentative à chaque
             * minute, toute la soirée durant. */
            if ($entry['failed_until'] > $_now) {
                $_runtime['lamps'][$key] = $entry;
                continue;
            }

            /* Un geste humain rend la lampe à son propriétaire pour un temps.
             * On le repère à l'écart entre ce qu'on a ordonné et ce que la
             * lampe dit, passé le délai qu'elle a pour répondre. */
            $actual = simulationpresenceintelligentbeLamps::readState($lamp['state']);
            if ($actual !== null && $entry['ordered'] !== null
                && $actual != $entry['ordered']
                && ($_now - $entry['ordered_at']) > self::orderGrace()) {
                $manual = (int) $guards['manual'];
                if ($manual > 0) {
                    $entry['manual_until'] = $_now + $manual * 60;
                    log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . $lamp['name'] . ' ' . __('touchée à la main, laissée tranquille', __FILE__));
                }
                $entry['ordered'] = $actual;
                $_runtime['lamps'][$key] = $entry;
                continue;
            }

            if ($entry['manual_until'] > $_now) {
                $_runtime['lamps'][$key] = $entry;
                continue;
            }

            /* Premier passage d'une simulation qui démarre : une lampe déjà
             * dans l'état que le plan lui demande n'a rien à recevoir. C'est le
             * cas de presque toutes au démarrage — tout est éteint — et leur
             * envoyer un ordre d'extinction se voit dans les journaux de la
             * maison entière sans rien changer. */
            if ($entry['ordered'] === null && $actual !== null && $actual == $wanted[$key]) {
                $entry['ordered'] = $wanted[$key];
                $entry['ordered_at'] = $_now;
                $_runtime['lamps'][$key] = $entry;
                continue;
            }

            if ($entry['ordered'] === null || $entry['ordered'] != $wanted[$key]) {
                $this->orderLamp($lamp, $wanted[$key], $_now, $_runtime);
            } else {
                $_runtime['lamps'][$key] = $entry;
            }
        }
    }

    /*
     * Pousser un ordre à une lampe.
     *
     * La bascule ne sert qu'en dernier recours, quand l'équipement n'a ni
     * allumage ni extinction : elle est incapable de savoir où elle va, et
     * l'utiliser alors qu'une commande franche existe finirait par inverser
     * toute la maison.
     */
    public function orderLamp($_lamp, $_value, $_now, &$_runtime) {
        $key = (string) $_lamp['eq'];
        $entry = $this->lampRuntime($_runtime, $key);

        $cmdId = ($_value == 1) ? $_lamp['on'] : $_lamp['off'];
        if ($cmdId === null) {
            $cmdId = $_lamp['toggle'];
            if ($cmdId !== null) {
                $actual = simulationpresenceintelligentbeLamps::readState($_lamp['state']);
                if ($actual !== null && $actual == $_value) {
                    /* Déjà dans l'état voulu : basculer l'en ferait sortir. */
                    $entry['ordered'] = $_value;
                    $entry['ordered_at'] = $_now;
                    $_runtime['lamps'][$key] = $entry;
                    return true;
                }
            }
        }
        if ($cmdId === null) {
            /* Aucune commande pour cet ordre : la lampe a été retenue sans de
             * quoi l'éteindre, ou sa commande a disparu. Se taire reviendrait à
             * la laisser brûler jusqu'au matin sans que personne ne sache
             * pourquoi. */
            $entry['failed_until'] = $_now + self::FAILURE_BACKOFF;
            $_runtime['lamps'][$key] = $entry;
            $this->reportFailure($_lamp, __('aucune commande pour cet ordre', __FILE__));
            return false;
        }

        try {
            $cmd = cmd::byId($cmdId);
            if (!is_object($cmd)) {
                throw new Exception(__('commande introuvable, choisissez la lampe à nouveau', __FILE__));
            }
            $cmd->execCmd();
            $entry['ordered'] = ($_value == 1) ? 1 : 0;
            $entry['ordered_at'] = $_now;
            $_runtime['lamps'][$key] = $entry;
            log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $_lamp['name'] . ' ' . (($_value == 1) ? __('allumée', __FILE__) : __('éteinte', __FILE__)));
            return true;
        } catch (Throwable $e) {
            /* L'échec est noté comme un ordre le serait : sans cela la même
             * erreur repart chaque minute, et le journal du plugin se remplit
             * d'une ligne par minute et par lampe sans que personne n'en soit
             * averti pour autant. */
            $entry['ordered_at'] = $_now;
            $entry['failed_until'] = $_now + self::FAILURE_BACKOFF;
            $_runtime['lamps'][$key] = $entry;
            $this->reportFailure($_lamp, $e->getMessage());
            return false;
        }
    }

    /*
     * Dire qu'une lampe ne répond pas.
     *
     * Dans le journal pour le détail, et dans le centre de messages de Jeedom
     * pour que ça se voie : log::add n'y publie rien, et une simulation de
     * présence qui se dégrade sans le dire est exactement ce qu'un plugin de
     * sécurité ne doit pas faire.
     */
    public function reportFailure($_lamp, $_message) {
        $name = isset($_lamp['name']) ? $_lamp['name'] : '';
        $eqId = isset($_lamp['eq']) ? $_lamp['eq'] : null;
        /*
         * « warning » et non « error » : au niveau erreur, le coeur publie de
         * lui-même un message dans le centre de messages, avec une clé qu'il
         * engendre — on se retrouvait avec deux lignes par panne, dont une que
         * le plugin ne savait plus retirer. La panne est de toute façon dite
         * juste en dessous, avec une clé par lampe.
         */
        log::add(__CLASS__, 'warning', $this->getHumanName() . ' — ' . $name . ' : ' . $_message, $this->messageKey($eqId));
        /* Une seule ligne par lampe : on remplace la précédente plutôt que d'en
         * ajouter une à chaque tentative. */
        message::removeAll(__CLASS__, $this->messageKey($eqId));
        message::add(__CLASS__, $this->getHumanName() . ' — ' . $name . ' : ' . $_message, '', $this->messageKey($eqId));
    }

    /*
     * La lampe d'une entrée d'instantané.
     *
     * L'ancienne forme ne gardait que l'état ; on retombe alors sur la
     * définition courante du groupe, faute de mieux.
     */
    public function snapshotLamp($_key, $_entry) {
        if (is_array($_entry)) {
            return array(
                'eq'     => (int) $_key,
                'name'   => isset($_entry['name']) ? $_entry['name'] : '',
                'on'     => isset($_entry['on']) ? $_entry['on'] : null,
                'off'    => isset($_entry['off']) ? $_entry['off'] : null,
                'toggle' => isset($_entry['toggle']) ? $_entry['toggle'] : null,
                'state'  => isset($_entry['state']) ? $_entry['state'] : null,
                'value'  => isset($_entry['value']) ? $_entry['value'] : null,
            );
        }
        foreach ($this->getConfiguration('lamps', array()) as $lamp) {
            if ((string) $lamp['eq'] === (string) $_key) {
                $lamp['value'] = $_entry;
                return $lamp;
            }
        }
        return null;
    }

    /* ============================================================== AFFICHAGE */

    /*
     * Les commandes d'information du groupe.
     *
     * checkAndUpdateCmd n'écrit que si la valeur change : appelé chaque minute,
     * il ne produit ni événement ni ligne d'historique tant que rien ne bouge.
     */
    public function refreshInfo($_now, $_runtime = null, $_plan = null) {
        $active = ($this->getConfiguration('active', 0) == 1);
        $this->checkAndUpdateCmd('state', $active ? 1 : 0);

        $runtime = ($_runtime === null) ? $this->runtime() : $_runtime;
        $lit = 0;
        foreach ($runtime['lamps'] as $entry) {
            if ($entry['ordered'] == 1) {
                $lit++;
            }
        }
        $this->checkAndUpdateCmd('lit', $active ? $lit : 0);

        $plan = ($_plan === null) ? $this->planFor(date('Y-m-d', $_now)) : $_plan;
        $total = $plan['learned'] + $plan['invented'];
        if ($total == 0) {
            $mode = __('aucune lampe', __FILE__);
        } elseif ($plan['invented'] == 0) {
            $mode = __('historique', __FILE__);
        } elseif ($plan['learned'] == 0) {
            $mode = __('inventé', __FILE__);
        } else {
            $mode = sprintf(__('%1$s apprise(s), %2$s inventée(s)', __FILE__), $plan['learned'], $plan['invented']);
        }
        $this->checkAndUpdateCmd('mode', $mode);

        $next = $this->nextChange($_now, $plan);
        $this->checkAndUpdateCmd('next', $next === null ? __('rien de prévu', __FILE__) : $next);
    }

    /* Le prochain changement du plan, en clair. C'est la ligne que l'utilisateur
     * lit sur sa tuile, et le seul moyen de vérifier d'un coup d'oeil que le
     * plugin a bien l'intention de faire quelque chose ce soir. */
    public function nextChange($_now, $_plan = null) {
        $plan = ($_plan === null) ? $this->planFor(date('Y-m-d', $_now)) : $_plan;
        $minute = simulationpresenceintelligentbeSun::minuteOfDay($_now);
        $names = array();
        foreach ($this->getConfiguration('lamps', array()) as $lamp) {
            $names[(string) $lamp['eq']] = $lamp['name'];
        }

        $best = null;
        foreach ($plan['lamps'] as $key => $entry) {
            foreach ($entry['events'] as $event) {
                if ((int) $event['t'] <= $minute) {
                    continue;
                }
                if ($best === null || $event['t'] < $best['t']) {
                    $best = array('t' => (int) $event['t'], 'v' => $event['v'], 'key' => $key);
                }
                break;
            }
        }
        if ($best === null) {
            return null;
        }
        $name = isset($names[$best['key']]) ? $names[$best['key']] : '';
        return simulationpresenceintelligentbeSun::minuteToTime($best['t']) . ' '
            . (($best['v'] == 1) ? __('allumer', __FILE__) : __('éteindre', __FILE__)) . ' ' . $name;
    }

    /* Le résumé porté par la tuile de la page du plugin. */
    public function cardSummary() {
        $lamps = $this->getConfiguration('lamps', array());
        $enabled = 0;
        foreach ($lamps as $lamp) {
            if ($lamp['enabled'] == 1) {
                $enabled++;
            }
        }
        $active = ($this->getConfiguration('active', 0) == 1);
        $conditions = self::cleanConditions($this->getConfiguration('conditions'));

        if ($active) {
            $text = __('en cours', __FILE__);
        } elseif ($enabled == 0) {
            $text = __('aucune lampe choisie', __FILE__);
        } elseif ($conditions['enable'] == 1 && count($conditions['rows']) > 0) {
            $text = __('en attente de la condition', __FILE__);
        } else {
            $text = __('en attente d\'un ordre', __FILE__);
        }
        return array('lamps' => $enabled, 'active' => $active ? 1 : 0, 'text' => $text);
    }

    /* ================================================================= ÉTAT */

    /* L'état d'exécution, celui qui n'a pas besoin de survivre à un vidage de
     * cache : ce que le plugin a ordonné à chaque lampe, et jusqu'à quand il la
     * laisse tranquille. Ce qui doit survivre — la simulation est-elle en
     * cours, dans quel état étaient les lampes — est dans la configuration de
     * l'équipement, c'est-à-dire en base. */
    public function runtime() {
        $raw = cache::byKey($this->runtimeKey())->getValue('');
        $runtime = ($raw === '') ? null : json_decode($raw, true);
        if (!is_array($runtime) || !isset($runtime['lamps'])) {
            $runtime = array('lamps' => array());
        }
        return $runtime;
    }

    public function saveRuntime($_runtime) {
        cache::set($this->runtimeKey(), json_encode($_runtime), self::CACHE_TTL);
    }

    private function lampRuntime(&$_runtime, $_key) {
        $empty = array('ordered' => null, 'ordered_at' => 0, 'manual_until' => 0, 'failed_until' => 0);
        if (!isset($_runtime['lamps'][$_key])) {
            $_runtime['lamps'][$_key] = $empty;
            return $_runtime['lamps'][$_key];
        }
        /* Une entrée écrite par une version précédente n'a pas toutes les
         * clés : les compléter ici évite un avertissement PHP à chaque minute
         * pendant les deux jours de vie du cache. */
        $_runtime['lamps'][$_key] = array_merge($empty, $_runtime['lamps'][$_key]);
        return $_runtime['lamps'][$_key];
    }

    /* Rend vrai quand la journée vient d'être ajoutée : c'est à l'appelant
     * d'enregistrer, pour qu'une minute de cron n'écrive pas en base pour
     * rien. */
    public function rememberSimulatedDay($_date) {
        $days = $this->getConfiguration('simulated_days', array());
        if (!is_array($days)) {
            $days = array();
        }
        if (in_array($_date, $days)) {
            return false;
        }
        $days[] = $_date;
        $this->setConfiguration('simulated_days', $days);
        return true;
    }

    /*
     * Oublier les jours simulés trop anciens pour compter.
     *
     * La limite suit la profondeur d'apprentissage : la garder fixe à quatre-
     * vingt-dix jours alors que l'utilisateur peut demander un an revenait à
     * réapprendre, passé trois mois, les journées que le plugin avait
     * lui-même inventées.
     */
    public function pruneSimulatedDays() {
        $days = $this->getConfiguration('simulated_days', array());
        if (!is_array($days) || count($days) == 0) {
            return;
        }
        $learning = self::cleanLearning($this->getConfiguration('learning'));
        $memory = max(self::SIMULATED_MEMORY, $learning['depth'] + 1);
        $limit = date('Y-m-d', strtotime('today -' . $memory . ' day'));

        $kept = array();
        foreach ($days as $day) {
            if ($day >= $limit) {
                $kept[] = $day;
            }
        }
        if (count($kept) == count($days)) {
            return;
        }

        /*
         * Relecture avant écriture. cronDaily et le cron de la minute sont deux
         * tâches distinctes du coeur, donc deux processus : un élagage qui dure
         * pendant qu'une simulation démarre écraserait « active » et
         * l'instantané des lampes avec sa copie périmée, et la simulation
         * mourrait en silence en laissant les lampes allumées.
         */
        $fresh = self::byId($this->getId());
        if (!is_object($fresh)) {
            return;
        }
        $fresh->setConfiguration('simulated_days', $kept);
        $fresh->save();
        $this->setConfiguration('simulated_days', $kept);
    }

    public function runtimeKey() {
        return __CLASS__ . '::runtime::' . $this->getId();
    }

    public function holdKey() {
        return __CLASS__ . '::hold::' . $this->getId();
    }

    public function planKey($_date) {
        return __CLASS__ . '::plan::' . $this->getId() . '::' . $_date;
    }

    public function profileKey($_eqId) {
        return __CLASS__ . '::profile::' . $this->getId() . '::' . $_eqId;
    }

    /*
     * La clé des messages du centre de messages.
     *
     * Une clé par lampe, et le séparateur final compte : message::removeAll
     * cherche en LIKE quand on lui demande de balayer un groupe, et
     * « group::1 » attraperait aussi « group::10 ». Sans clé distincte par
     * lampe, chaque panne empilait une ligne de plus toutes les quinze minutes.
     */
    public function messageKey($_eqId = null) {
        $key = 'group::' . $this->getId() . '::';
        return ($_eqId === null) ? $key : $key . 'lamp::' . ((int) $_eqId);
    }

    /* ============================================================ ORDRE REÇU */

    /*
     * Ce que font les boutons du groupe.
     *
     * « Démarrer » et « Arrêter » posent une marque manuelle qui l'emporte sur
     * la condition : quelqu'un qui arrête la simulation depuis son téléphone ne
     * veut pas la voir repartir à la minute suivante parce que l'alarme est
     * toujours armée. « Revenir à la condition » retire cette marque.
     */
    public function applyAction($_order) {
        $now = time();
        switch ($_order) {
            /*
             * Un seul enregistrement par ordre. startSimulation() et
             * stopSimulation() écrivent déjà, et chaque écriture recrée les
             * huit commandes du groupe : enregistrer avant elles multipliait
             * par trois le coût d'un simple clic sur « Démarrer ».
             */
            case 'on':
                $this->setConfiguration('manual', 'on');
                if ($this->getConfiguration('active', 0) != 1) {
                    $this->startSimulation($now, __('ordre manuel', __FILE__));
                } else {
                    $this->save();
                }
                break;
            case 'off':
                $this->setConfiguration('manual', 'off');
                if ($this->getConfiguration('active', 0) == 1) {
                    $this->stopSimulation($now, __('ordre manuel', __FILE__));
                } else {
                    $this->save();
                }
                break;
            case 'auto':
                $this->setConfiguration('manual', '');
                $this->save();
                break;
            case 'replan':
                /* Le sel change, donc le tirage change. C'est la seule chose
                 * qui distingue ce bouton d'un vidage de cache. */
                $this->setConfiguration('replan_salt', ((int) $this->getConfiguration('replan_salt', 0)) + 1);
                $this->save();
                $this->forgetPlan();
                break;
        }
        $this->refreshInfo($now);
        return true;
    }

    /* ================================================================= SANTÉ */

    public static function health() {
        $health = array();

        $position = (config::byKey('info::latitude') != '' && config::byKey('info::longitude') != '');
        $health[] = array(
            'test'   => __('Position de l\'installation', __FILE__),
            'result' => $position ? __('renseignée', __FILE__) : __('absente', __FILE__),
            'advice' => $position ? '' : __('Réglages → Système → Configuration → Général : sans elle, les journées inventées se calent sur un coucher de soleil à 19 h toute l\'année.', __FILE__),
            'state'  => $position,
        );

        $silent = 0;
        $lamps = 0;
        foreach (self::byType(__CLASS__, true) as $eqLogic) {
            foreach ($eqLogic->getConfiguration('lamps', array()) as $lamp) {
                if ($lamp['enabled'] == 1) {
                    $lamps++;
                }
            }
            $silent += count($eqLogic->silentLamps());
        }

        $health[] = array(
            'test'   => __('Lampes suivies', __FILE__),
            'result' => $lamps,
            'advice' => ($lamps > 0) ? '' : __('Aucune lampe choisie : ouvrez un groupe et utilisez le sélecteur.', __FILE__),
            'state'  => ($lamps > 0),
        );

        $health[] = array(
            'test'   => __('Lampes sans historique', __FILE__),
            'result' => $silent,
            'advice' => ($silent == 0) ? '' : __('Ces lampes ne seront qu\'inventées. Le bouton « Historiser les lampes » de l\'onglet Apprentissage active l\'historisation de leur état.', __FILE__),
            'state'  => ($silent == 0),
        );

        return $health;
    }
}

/*
 * La classe de commande est obligatoire, même réduite à son execute() : sans
 * elle, le coeur refuse de créer un équipement du plugin.
 */
class simulationpresenceintelligentbeCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        switch ($this->getLogicalId()) {
            case 'on':
            case 'off':
            case 'auto':
            case 'replan':
                $eqLogic->applyAction($this->getLogicalId());
                return;
        }
    }
}
