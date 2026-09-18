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

/*
 * Trouver les lampes tout seul.
 *
 * C'est la raison d'être du plugin autant que la programmation elle-même. Une
 * installation Jeedom un peu fournie compte plusieurs centaines de commandes ;
 * demander à l'utilisateur d'aller y choisir une par une la commande « On » et
 * la commande « Off » de chaque lampe, c'est lui demander le travail que le
 * plugin est censé lui épargner — et c'est la première occasion de se tromper
 * de commande sans le voir.
 *
 * On raisonne donc par équipement et non par commande : un équipement est une
 * lampe s'il porte de quoi l'allumer et de quoi l'éteindre, et le plugin retient
 * lui-même les deux commandes. L'utilisateur ne coche qu'un nom.
 *
 * Trois niveaux de certitude, parce que toutes les installations ne sont pas
 * rangées de la même façon :
 *
 *   « light » : l'équipement porte les types génériques Lumière du coeur. Aucun
 *               doute possible, c'est ce que le plugin propose en premier.
 *   « plug »  : une prise commandée, type générique Energie. C'en est peut-être
 *               une qui porte une lampe, à l'utilisateur de le dire.
 *   « guess » : ni l'un ni l'autre, mais deux commandes d'action qui s'appellent
 *               « On » et « Off ». Beaucoup de protocoles anciens ne remplissent
 *               pas les types génériques ; sans ce dernier filet, leurs lampes
 *               seraient introuvables et le plugin paraîtrait vide.
 *   « unknown »: rien de reconnaissable, mais des commandes d'action. Ceux-là ne
 *               sont proposés que sur demande, dans le sélecteur complet, et
 *               l'utilisateur désigne lui-même les deux commandes.
 */
class simulationpresenceintelligentbeLamps {

    const LIGHT = 'light';
    const PLUG  = 'plug';
    const GUESS = 'guess';

    /* Quatrième famille, celle du sélecteur complet : un équipement dont on ne
     * sait rien dire, mais qui porte des commandes d'action. Le plugin ne
     * devine rien pour lui — c'est l'utilisateur qui désigne la commande qui
     * allume et celle qui éteint. Sans cette porte de sortie, une lampe
     * déclarée en « interrupteur » ou en « module » resterait hors d'atteinte,
     * et le sélecteur aurait l'air de mentir. */
    const UNKNOWN = 'unknown';

    /* Les types génériques du coeur, par rôle. Un équipement qui en porte est
     * décrit par son intégrateur : c'est la meilleure source possible. */
    public static $_generics = array(
        'on'     => array('LIGHT_ON' => self::LIGHT, 'ENERGY_ON' => self::PLUG),
        'off'    => array('LIGHT_OFF' => self::LIGHT, 'ENERGY_OFF' => self::PLUG),
        'toggle' => array('LIGHT_TOGGLE' => self::LIGHT),
        'state'  => array('LIGHT_STATE' => self::LIGHT, 'LIGHT_STATE_BOOL' => self::LIGHT, 'ENERGY_STATE' => self::PLUG),
    );

    /* Noms de commandes acceptés en dernier recours, sans accent ni casse.
     * Volontairement courts et exacts : « Ouvrir » ou « Monter » ne sont pas
     * ici, une lampe n'est pas un volet. */
    public static $_names = array(
        'on'     => array('on', 'allumer', 'marche', 'allume', 'enclencher'),
        'off'    => array('off', 'eteindre', 'arret', 'eteint', 'declencher'),
        'toggle' => array('toggle', 'bascule', 'basculer', 'inverser'),
    );

    /* Mots qui, dans le nom d'un équipement ou de son objet parent, font penser
     * à un éclairage. Ils ne servent qu'à trier : une prise dont le nom parle de
     * lampe est proposée avant les autres, jamais cochée d'office. */
    public static $_hints = array(
        'lampe', 'lampes', 'lumiere', 'lumieres', 'light', 'lights', 'lamp',
        'eclairage', 'spot', 'spots', 'plafonnier', 'plafond', 'applique',
        'led', 'leds', 'lustre', 'veilleuse', 'luminaire', 'halogene',
        'ampoule', 'guirlande', 'neon', 'chevet', 'bandeau', 'liseuse',
        'projecteur', 'suspension', 'lampadaire', 'sapin',
    );

    /*
     * Décide si un équipement est une lampe, d'après ses seules commandes.
     *
     * Volontairement sans Jeedom : $_cmds est une liste de tableaux
     * array('id', 'name', 'type', 'subType', 'generic'). C'est ce qui permet
     * d'éprouver la reconnaissance hors ligne, sur des installations qu'on n'a
     * pas sous la main (voir tests/run.php).
     *
     * Rend null si l'équipement n'a pas de quoi être allumé ou éteint.
     */
    public static function classify($_name, $_cmds, $_objectName = '') {
        $found = array('on' => null, 'off' => null, 'toggle' => null, 'state' => null);
        $confidence = null;

        /* Une commande sans identifiant n'est pas exploitable, et la garder
         * ferait produire un avertissement PHP à chaque passage — trois fois,
         * une par passe de reconnaissance. */
        $cmds = array();
        foreach ($_cmds as $cmd) {
            if (is_array($cmd) && isset($cmd['id'])) {
                $cmds[] = $cmd;
            }
        }
        $_cmds = $cmds;

        /* Premier passage : les types génériques. Ils l'emportent toujours sur
         * les noms, y compris quand une commande « On » traîne à côté. */
        foreach ($_cmds as $cmd) {
            $generic = isset($cmd['generic']) ? (string) $cmd['generic'] : '';
            if ($generic === '') {
                continue;
            }
            foreach (self::$_generics as $role => $generics) {
                if (!isset($generics[$generic]) || $found[$role] !== null) {
                    continue;
                }
                if (!self::roleMatchesType($role, $cmd)) {
                    continue;
                }
                $found[$role] = $cmd['id'];
                if ($generics[$generic] == self::LIGHT) {
                    $confidence = self::LIGHT;
                } elseif ($confidence === null) {
                    $confidence = self::PLUG;
                }
            }
        }

        /* Second passage : les noms, pour les rôles restés vides. */
        foreach ($_cmds as $cmd) {
            foreach (self::$_names as $role => $names) {
                if ($found[$role] !== null || !self::roleMatchesType($role, $cmd)) {
                    continue;
                }
                if (in_array(self::normalize(isset($cmd['name']) ? $cmd['name'] : ''), $names)) {
                    $found[$role] = $cmd['id'];
                    if ($confidence === null) {
                        $confidence = self::GUESS;
                    }
                }
            }
        }

        /*
         * Troisième passage : les noms composés. « Lumière blanche ON » et
         * « Lumière blanche OFF » sur une caméra, « Éclairage terrasse Marche »
         * sur un module — l'ordre est le dernier mot, et le reste du nom dit
         * qu'il s'agit bien d'un éclairage.
         *
         * Les deux conditions comptent autant l'une que l'autre. Sans le dernier
         * mot, « Lumière salon » passerait pour un allumage parce qu'il finit
         * par les lettres « on ». Sans l'indice d'éclairage, « Sortie alarme
         * ON » deviendrait une lampe, et le sélecteur proposerait de programmer
         * une sirène.
         */
        foreach ($_cmds as $cmd) {
            $name = isset($cmd['name']) ? $cmd['name'] : '';
            if (!self::looksLikeLight($name)) {
                continue;
            }
            $words = explode(' ', self::normalizeWords($name));
            $last = end($words);
            foreach (self::$_names as $role => $names) {
                if ($found[$role] !== null || !self::roleMatchesType($role, $cmd)) {
                    continue;
                }
                if (in_array($last, $names)) {
                    $found[$role] = $cmd['id'];
                    if ($confidence === null) {
                        $confidence = self::GUESS;
                    }
                }
            }
        }

        /*
         * Il faut de quoi allumer ET de quoi éteindre.
         *
         * Une bascule suffit pour les deux : beaucoup d'interrupteurs muraux ne
         * savent faire que ça, et le plugin s'en servira pour les deux ordres —
         * au risque assumé d'inverser l'état si la lampe a été touchée à la
         * main entre-temps, ce que la documentation dit sans détour.
         *
         * En revanche un équipement qui n'a qu'un allumage n'est pas une lampe
         * pour ce plugin-ci, et c'est le contraire d'un détail : une simulation
         * de présence qui allume une lampe le soir sans pouvoir l'éteindre la
         * laisse brûler jusqu'au matin — exactement la signature qu'elle
         * cherche à masquer. Ces équipements restent atteignables par le
         * sélecteur complet, où l'utilisateur désigne lui-même les commandes.
         */
        $canSwitchOn  = ($found['on'] !== null || $found['toggle'] !== null);
        $canSwitchOff = ($found['off'] !== null || $found['toggle'] !== null);
        if (!$canSwitchOn || !$canSwitchOff) {
            return null;
        }
        if ($confidence === null) {
            $confidence = self::GUESS;
        }

        $hinted = self::looksLikeLight($_name) || self::looksLikeLight($_objectName);
        return array(
            'on'         => $found['on'],
            'off'        => $found['off'],
            'toggle'     => $found['toggle'],
            'state'      => $found['state'],
            'confidence' => $confidence,
            /* Une prise nommée « Lampe du salon » remonte avec les lampes ; une
             * prise nommée « Congélateur » reste dans son coin. */
            'hinted'     => $hinted ? 1 : 0,
        );
    }

    /* Un ordre est une action, un état est une information. Sans ce contrôle,
     * une commande info nommée « On » serait retenue pour allumer la lampe et
     * l'ordre partirait dans le vide, sans erreur. */
    public static function roleMatchesType($_role, $_cmd) {
        $type = isset($_cmd['type']) ? $_cmd['type'] : '';
        return ($_role == 'state') ? ($type == 'info') : ($type == 'action');
    }

    /* En deçà de cette longueur, un indice n'est cherché qu'en mot entier. Trois
     * lettres se retrouvent partout : « led » est dans « salle de bain », et une
     * sonde de salle de bain remonterait avec les lampes. */
    const HINT_MIN_LENGTH = 5;

    /* Minuscules, sans accent ni ponctuation : « Éteindre » et « eteindre »
     * doivent se ressembler. */
    public static function normalize($_value) {
        return str_replace(' ', '', self::normalizeWords($_value));
    }

    /* La même chose, mais les séparateurs deviennent des espaces : c'est la
     * forme qui permet de chercher un mot entier. */
    public static function normalizeWords($_value) {
        $value = mb_strtolower(trim((string) $_value), 'UTF-8');
        $value = strtr($value, array(
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u',
            'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    /*
     * Le nom parle-t-il d'éclairage ?
     *
     * Deux façons de chercher, parce que les noms d'équipements arrivent sous
     * deux formes : « Lampe du salon », et « spotcuisineplafond » tout collé, tel
     * qu'un identifiant MQTT le donne. Un indice un peu long se cherche donc dans
     * le nom collé, où il reste reconnaissable ; un indice court ne se cherche
     * qu'en mot entier, sans quoi « led » ferait passer une sonde de salle de
     * bain pour un éclairage.
     */
    /* Ce qui annule un indice d'éclairage, même quand le nom parle de lampe.
     * « Volet lampe » ou « Store salon lumière » sont des ouvrants : la
     * troisième passe de reconnaissance se sert de looksLikeLight() pour
     * retenir des commandes, et sans cette liste elle proposerait de programmer
     * un volet roulant parmi les lampes. */
    public static $_notLights = array(
        'volet', 'volets', 'store', 'stores', 'rideau', 'rideaux', 'portail',
        'porte', 'garage', 'vanne', 'chaudiere', 'vmc', 'pompe', 'arrosage',
        'sirene', 'alarme', 'verrou', 'serrure',
    );

    public static function looksLikeLight($_name) {
        $words = self::normalizeWords($_name);
        if ($words === '') {
            return false;
        }
        $collapsed = str_replace(' ', '', $words);
        $spaced = ' ' . $words . ' ';

        foreach (self::$_notLights as $notLight) {
            if (strpos($collapsed, $notLight) !== false) {
                return false;
            }
        }

        foreach (self::$_hints as $hint) {
            if (strlen($hint) >= self::HINT_MIN_LENGTH) {
                if (strpos($collapsed, $hint) !== false) {
                    return true;
                }
            } elseif (strpos($spaced, ' ' . $hint . ' ') !== false) {
                return true;
            }
        }
        return false;
    }

    /* ==================================================== CÔTÉ JEEDOM */

    /*
     * Parcourt l'installation et rend les lampes, groupées par objet.
     *
     * $_all ajoute les équipements dont on ne sait rien dire mais qui portent
     * des commandes d'action : c'est le sélecteur complet, celui qu'on ouvre
     * quand la lampe cherchée n'apparaît nulle part. Ils arrivent sans
     * commandes retenues — l'utilisateur les désigne — et jamais cochés.
     */
    public static function discover($_all = false) {
        $groups = array();

        foreach (eqLogic::all() as $eqLogic) {
            /* Le plugin ne se propose pas lui-même : un groupe qui se contiendrait
             * s'appellerait sans fin, et le sélecteur n'y gagnerait rien. */
            if ($eqLogic->getEqType_name() == 'simulationpresenceintelligentbe') {
                continue;
            }
            /* Le filtre se fait ici et non par eqLogic::all(true) : le SQL du
             * coeur accroche sa condition isEnable au ON d'une jointure, où elle
             * ne filtre rien. Un équipement désactivé n'exécuterait pas l'ordre,
             * le proposer serait promettre une lampe qui ne s'allumera pas. */
            if ($eqLogic->getIsEnable() != 1) {
                continue;
            }

            $objectName = '';
            try {
                $object = $eqLogic->getObject();
                if (is_object($object)) {
                    $objectName = $object->getName();
                }
            } catch (Throwable $e) {
                $objectName = '';
            }

            $cmds = array();
            $actions = array();
            $states = array();
            foreach ($eqLogic->getCmd() as $cmd) {
                $description = array(
                    'id'      => (int) $cmd->getId(),
                    'name'    => $cmd->getName(),
                    'type'    => $cmd->getType(),
                    'subType' => $cmd->getSubType(),
                    'generic' => (string) $cmd->getGeneric_type(),
                );
                $cmds[] = $description;
                if ($description['type'] == 'action') {
                    $actions[] = array('id' => $description['id'], 'name' => $description['name']);
                } elseif ($description['subType'] == 'binary') {
                    $states[] = $description['id'];
                }
            }

            $lamp = self::classify($eqLogic->getName(), $cmds, $objectName);
            if ($lamp === null) {
                /* Sans commande d'action, il n'y a rien à proposer, même dans le
                 * sélecteur complet : une sonde de température n'éclairera
                 * jamais rien. */
                if (!$_all || count($actions) == 0) {
                    continue;
                }
                $lamp = array(
                    'on' => null, 'off' => null, 'toggle' => null,
                    /* Faute de type générique, le premier état binaire de
                     * l'équipement sert de pastille : c'est un indice, pas une
                     * certitude, et il vaut mieux que rien pour reconnaître la
                     * lampe qu'on vient d'allumer. */
                    'state'      => (count($states) > 0) ? $states[0] : null,
                    'confidence' => self::UNKNOWN,
                    'hinted'     => (self::looksLikeLight($eqLogic->getName()) || self::looksLikeLight($objectName)) ? 1 : 0,
                );
            }

            /* Les commandes d'action de l'équipement accompagnent toujours la
             * lampe : elles alimentent les deux listes déroulantes qui
             * permettent de corriger un choix du détecteur, et de désigner les
             * siennes pour un équipement inconnu. */
            $lamp['cmds'] = $actions;

            $lamp['eq']       = (int) $eqLogic->getId();
            $lamp['name']     = $eqLogic->getName();
            $lamp['object']   = ($objectName === '') ? __('Sans objet parent', __FILE__) : $objectName;
            $lamp['plugin']   = $eqLogic->getEqType_name();
            $lamp['value']    = self::readState($lamp['state']);

            $key = $lamp['object'];
            if (!isset($groups[$key])) {
                $groups[$key] = array('object' => $key, 'lamps' => array());
            }
            $groups[$key]['lamps'][] = $lamp;
        }

        /* Les pièces dans l'ordre alphabétique, « Sans objet parent » en dernier :
         * c'est le fourre-tout, il n'a pas à ouvrir la liste. */
        uksort($groups, function ($_a, $_b) {
            $orphan = __('Sans objet parent', __FILE__);
            if ($_a == $orphan) { return 1; }
            if ($_b == $orphan) { return -1; }
            return strcasecmp($_a, $_b);
        });

        foreach ($groups as &$group) {
            usort($group['lamps'], function ($_a, $_b) {
                $rank = array(self::LIGHT => 0, self::PLUG => 1, self::GUESS => 2, self::UNKNOWN => 3);
                if ($rank[$_a['confidence']] != $rank[$_b['confidence']]) {
                    return $rank[$_a['confidence']] - $rank[$_b['confidence']];
                }
                if ($_a['hinted'] != $_b['hinted']) {
                    return $_b['hinted'] - $_a['hinted'];
                }
                return strcasecmp($_a['name'], $_b['name']);
            });
        }
        unset($group);

        return array_values($groups);
    }

    /*
     * L'état d'un groupe, à partir de celui de ses lampes.
     *
     * Allumé dès qu'une seule l'est : c'est ce que dit une pièce où il reste de
     * la lumière, et c'est la question qu'on pose à un groupe — « reste-t-il
     * quelque chose d'allumé ? » — bien plus souvent que « sont-elles toutes
     * allumées ? ».
     *
     * Rend null quand aucune lampe ne publie son état : il faut alors pouvoir
     * dire qu'on ne sait pas, plutôt que de répondre « éteint » pour des lampes
     * dont on ignore tout. Beaucoup de modules commandés en 433 MHz ne
     * renvoient rien, et leur groupe doit garder l'état du dernier ordre.
     *
     * Sans Jeedom : c'est la règle d'agrégation, et elle s'éprouve hors ligne.
     */
    public static function aggregateState($_values) {
        $known = false;
        foreach ($_values as $value) {
            if ($value === null) {
                continue;
            }
            $known = true;
            if ($value == 1) {
                return 1;
            }
        }
        return $known ? 0 : null;
    }

    /* L'état connu d'une lampe, pour la pastille du sélecteur : 1 allumée,
     * 0 éteinte, null inconnu. Le voir en direct est ce qui permet de
     * reconnaître une lampe sans quitter la page. */
    public static function readState($_cmdId) {
        if ($_cmdId === null) {
            return null;
        }
        try {
            $cmd = cmd::byId($_cmdId);
            if (!is_object($cmd)) {
                return null;
            }
            $value = $cmd->execCmd();
            if ($value === '' || $value === null) {
                return null;
            }
            return ($value == 0) ? 0 : 1;
        } catch (Throwable $e) {
            /* Une valeur illisible n'est pas une erreur du sélecteur : la lampe
             * reste proposée, sans pastille. */
            return null;
        }
    }

    /*
     * Retrouve une lampe déjà choisie, pour la réafficher et pour l'exécution.
     *
     * Les commandes sont enregistrées par leur identifiant : un renommage de la
     * lampe ou de la pièce ne casse rien. Une suppression, si — et c'est le seul
     * cas où l'on doit le dire à l'utilisateur plutôt que d'échouer en silence.
     */
    public static function describe($_lamp) {
        $describe = array(
            'eq'      => isset($_lamp['eq']) ? (int) $_lamp['eq'] : 0,
            'name'    => isset($_lamp['name']) ? $_lamp['name'] : '',
            'object'  => isset($_lamp['object']) ? $_lamp['object'] : '',
            'missing' => 0,
        );

        $eqLogic = ($describe['eq'] > 0) ? eqLogic::byId($describe['eq']) : null;
        if (!is_object($eqLogic)) {
            $describe['missing'] = 1;
            return $describe;
        }

        $describe['name'] = $eqLogic->getName();
        try {
            $object = $eqLogic->getObject();
            $describe['object'] = is_object($object) ? $object->getName() : '';
        } catch (Throwable $e) {
            $describe['object'] = '';
        }
        $describe['enabled'] = ($eqLogic->getIsEnable() == 1) ? 1 : 0;
        return $describe;
    }
}
