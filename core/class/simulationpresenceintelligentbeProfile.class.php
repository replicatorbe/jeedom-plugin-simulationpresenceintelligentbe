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
 * Ce que fait une lampe d'habitude, et une journée plausible pour demain.
 *
 * C'est le seul endroit du plugin où se trouve quelque chose qu'on puisse
 * appeler de l'intelligence, et c'est pourquoi il ne connaît ni Jeedom, ni base
 * de données, ni commande : il reçoit des journées observées sous forme de
 * listes de nombres, il rend une journée à jouer sous la même forme. Tout se
 * vérifie hors ligne, sur des milliers de tirages, en une seconde
 * (voir tests/run.php).
 *
 * Le modèle tient en trois idées.
 *
 * 1. La journée est découpée en tranches d'un quart d'heure. Plus fin
 *    n'apprendrait rien de plus : personne n'allume le salon à 19 h 07 toutes
 *    les semaines, mais beaucoup de gens l'allument « vers sept heures et
 *    quart ». Plus large perdrait la différence entre le dîner et le coucher.
 *
 * 2. On ne retient pas seulement la probabilité qu'une lampe soit allumée dans
 *    une tranche, mais celle qu'elle s'allume alors qu'elle était éteinte, et
 *    celle qu'elle s'éteigne alors qu'elle était allumée. C'est la différence
 *    entre un plan crédible et un clignotement : rejouer une probabilité de
 *    présence tranche par tranche, indépendamment, donnerait une lampe qui
 *    s'allume et s'éteint dix fois dans la soirée. En raisonnant sur les
 *    changements, on hérite naturellement des durées observées.
 *
 * 3. Avec trois semaines d'observation, une tranche ne contient que trois
 *    échantillons : une fréquence brute y vaut 0 ou 1, jamais autre chose. On
 *    mélange donc chaque fréquence à une estimation tirée de la forme générale
 *    de la journée, d'autant plus fort que les observations sont rares. C'est
 *    ce qui permet au plugin d'être utile dès la première semaine sans
 *    caricaturer le peu qu'il a vu.
 */
class simulationpresenceintelligentbeProfile {

    /* Largeur d'une tranche, en minutes, et nombre de tranches dans la journée.
     * Les deux vont ensemble : changer l'une sans l'autre casse tout. */
    const SLOT_MINUTES = 15;
    const SLOTS        = 96;

    /* Poids de l'estimation de repli, exprimé en nombre de journées fictives.
     * Deux journées : avec deux semaines d'observation, l'observé et l'estimé
     * pèsent à peu près autant ; avec deux mois, l'observé l'emporte largement. */
    const PRIOR_WEIGHT = 2.0;

    /* En dessous, une lampe n'a pas assez d'histoire pour qu'on prétende la
     * rejouer : le plugin invente une journée plutôt que de caricaturer trois
     * soirées. */
    const MIN_DAYS = 3;

    /* Durées d'allumage imposées au plan, quoi qu'en dise le modèle. Une lampe
     * allumée quatre minutes ne trompe personne, une lampe allumée neuf heures
     * d'affilée non plus. */
    const MIN_ON_MINUTES = 12;
    const MAX_ON_MINUTES = 420;

    /* Flottement autour de l'heure habituelle, en minutes, quand la tranche a
     * déjà été observée. Quelques minutes suffisent : on cherche à ne pas
     * répéter la même minute tous les soirs, pas à s'éloigner de l'habitude. */
    const SLOT_JITTER = 4;

    /* Amplitude maximale du décalage d'une journée entière, à variabilité 100.
     * Le premier s'applique à tout un groupe, le second à chaque lampe par
     * dessus : c'est ce qui fait qu'un soir « tardif » l'est pour toute la
     * maison, et non pour six lampes indépendantes qui se contrediraient. */
    const SHIFT_GROUP = 30;
    const SHIFT_LAMP  = 15;

    /* ==================================================== TIRAGE REPRODUCTIBLE */

    /*
     * Un générateur aléatoire dont la suite est entièrement déterminée par une
     * graine textuelle.
     *
     * rand() ne conviendrait pas. Le plan d'une journée est recalculé chaque
     * fois que le plugin redémarre, que l'équipement est enregistré ou que le
     * fichier du jour est perdu ; avec rand(), la maison changerait de programme
     * en milieu de soirée, et la même journée ne pourrait jamais être rejouée
     * deux fois pour être examinée. Avec une graine qui contient la lampe et la
     * date, le plan est stable de minuit à minuit, différent le lendemain, et
     * différent d'une lampe à l'autre le même soir.
     */
    public static function seed($_string) {
        /* On force un état non nul : une suite congruentielle partie de zéro
         * avec ces coefficients reste pauvre pendant plusieurs tirages. */
        return (crc32((string) $_string) & 0x7FFFFFFF) | 1;
    }

    /* Le tirage suivant, dans [0, 1[. L'état avance par référence. */
    public static function next(&$_state) {
        $_state = (int) ((($_state * 1103515245) + 12345) & 0x7FFFFFFF);
        return $_state / 2147483648.0;
    }

    /* Un entier entre deux bornes incluses, tiré sur la même suite. */
    public static function nextInt(&$_state, $_min, $_max) {
        if ($_max <= $_min) {
            return (int) $_min;
        }
        return (int) $_min + (int) floor(self::next($_state) * (($_max - $_min) + 1));
    }

    /* ============================================================ OBSERVATIONS */

    /*
     * Une journée observée, à partir de ses changements d'état.
     *
     * $_points est une liste de array('t' => minute du jour, 'v' => 0 ou 1),
     * dans n'importe quel ordre. Le premier point d'une journée bien tenue est
     * l'état à minuit ; s'il manque, la lampe est considérée éteinte jusqu'au
     * premier changement connu — l'hypothèse prudente, celle qui n'invente pas
     * de présence.
     *
     * Rend, pour chaque tranche : la part du temps passée allumée, l'état au
     * début de la tranche, et les changements qui s'y sont produits.
     */
    public static function buildDay($_points) {
        $day = array(
            'occupancy' => array_fill(0, self::SLOTS, 0.0),
            'state_at'  => array_fill(0, self::SLOTS, 0),
            'starts'    => array_fill(0, self::SLOTS, 0),
            'stops'     => array_fill(0, self::SLOTS, 0),
            /* Où, dans la tranche, le changement est tombé. Sans cette mesure,
             * rejouer une tranche revient à tirer une minute au hasard dedans :
             * une lampe allumée tous les soirs à 19 h 00 précises ressortirait
             * en moyenne à 19 h 07, et une lampe de 19 h 14 à 19 h 10. Le
             * décalage est petit mais il est systématique, et il se voit sur
             * une maison qu'on observe plusieurs soirs. */
            'start_at'  => array_fill(0, self::SLOTS, 0),
            'stop_at'   => array_fill(0, self::SLOTS, 0),
            'minutes'   => 0,
        );

        $points = self::cleanPoints($_points);
        if (count($points) == 0) {
            return $day;
        }

        $state = 0;
        $index = 0;
        $count = count($points);

        /* Tout point à minuit ou avant décrit l'état initial et non un
         * changement : le journal en pose un chaque nuit pour que la première
         * soirée d'une lampe déjà allumée ne passe pas pour une extinction. */
        while ($index < $count && $points[$index]['t'] <= 0) {
            $state = $points[$index]['v'];
            $index++;
        }

        $previousMinute = 0;
        for ($slot = 0; $slot < self::SLOTS; $slot++) {
            $slotStart = $slot * self::SLOT_MINUTES;
            $slotEnd   = $slotStart + self::SLOT_MINUTES;
            $day['state_at'][$slot] = $state;

            $minutesOn = 0;
            $previousMinute = $slotStart;

            while ($index < $count && $points[$index]['t'] < $slotEnd) {
                $point = $points[$index];
                if ($point['v'] != $state) {
                    if ($state == 1) {
                        $minutesOn += $point['t'] - $previousMinute;
                        $day['stops'][$slot]++;
                        $day['stop_at'][$slot] += $point['t'] - $slotStart;
                    } else {
                        $day['starts'][$slot]++;
                        $day['start_at'][$slot] += $point['t'] - $slotStart;
                    }
                    $state = $point['v'];
                    $previousMinute = $point['t'];
                }
                $index++;
            }

            if ($state == 1) {
                $minutesOn += $slotEnd - $previousMinute;
            }
            $day['occupancy'][$slot] = $minutesOn / self::SLOT_MINUTES;
            $day['minutes'] += $minutesOn;
        }
        return $day;
    }

    /* Points triés, bornés à la journée, et débarrassés des répétitions.
     *
     * Le tri n'est pas une précaution de style : l'historique de Jeedom arrive
     * de deux tables réunies par un UNION, et le journal du plugin peut avoir
     * été complété après coup par une reprise d'historique. Deux points de même
     * minute sont ramenés à un seul, le dernier — c'est la valeur qui a tenu. */
    public static function cleanPoints($_points) {
        if (!is_array($_points)) {
            return array();
        }
        $byMinute = array();
        foreach ($_points as $point) {
            if (!is_array($point) || !isset($point['t'])) {
                continue;
            }
            $minute = (int) $point['t'];
            /* Hors de la journée, des deux côtés. Une minute négative venue
             * d'une reprise d'historique mal bornée serait sinon retenue comme
             * l'état initial, et une minute au-delà de 1439 ne serait jamais
             * consommée : dans les deux cas on perdrait l'information sans
             * jamais s'en apercevoir. */
            if ($minute < 0 || $minute >= simulationpresenceintelligentbeSun::DAY_MINUTES) {
                continue;
            }
            $byMinute[$minute] = array(
                't' => $minute,
                'v' => (isset($point['v']) && $point['v'] == 1) ? 1 : 0,
            );
        }
        ksort($byMinute);
        return array_values($byMinute);
    }

    /*
     * Le profil d'une lampe : la moyenne de ses journées, par jour de semaine
     * et toutes journées confondues.
     *
     * $_days est un tableau 'Y-m-d' => liste de points. Les deux niveaux sont
     * tenus en même temps, et c'est délibéré : un mardi ressemble à un mercredi
     * bien plus qu'à un samedi, mais il faut plusieurs semaines pour le savoir.
     * Le plan du jour prend le jour de semaine quand il est assez fourni, et
     * retombe sur l'ensemble sinon.
     */
    public static function build($_days) {
        $profile = array(
            'days'    => 0,
            'first'   => '',
            'last'    => '',
            'buckets' => array('all' => self::emptyBucket()),
        );
        if (!is_array($_days)) {
            return $profile;
        }

        $dates = array_keys($_days);
        sort($dates);

        foreach ($dates as $date) {
            $timestamp = strtotime($date . ' 12:00:00');
            if ($timestamp === false) {
                continue;
            }
            $day = self::buildDay($_days[$date]);
            $weekday = (string) ((int) date('N', $timestamp));

            if (!isset($profile['buckets'][$weekday])) {
                $profile['buckets'][$weekday] = self::emptyBucket();
            }
            self::addDay($profile['buckets'][$weekday], $day);
            self::addDay($profile['buckets']['all'], $day);

            $profile['days']++;
            if ($profile['first'] === '') {
                $profile['first'] = $date;
            }
            $profile['last'] = $date;
        }
        return $profile;
    }

    public static function emptyBucket() {
        return array(
            'days'      => 0,
            'occupancy' => array_fill(0, self::SLOTS, 0.0),
            'from_off'  => array_fill(0, self::SLOTS, 0),
            'from_on'   => array_fill(0, self::SLOTS, 0),
            'starts'    => array_fill(0, self::SLOTS, 0),
            'stops'     => array_fill(0, self::SLOTS, 0),
            'start_at'  => array_fill(0, self::SLOTS, 0),
            'stop_at'   => array_fill(0, self::SLOTS, 0),
            'minutes'   => 0,
        );
    }

    private static function addDay(&$_bucket, $_day) {
        $_bucket['days']++;
        $_bucket['minutes'] += $_day['minutes'];
        for ($slot = 0; $slot < self::SLOTS; $slot++) {
            $_bucket['occupancy'][$slot] += $_day['occupancy'][$slot];
            $_bucket['starts'][$slot]    += $_day['starts'][$slot];
            $_bucket['stops'][$slot]     += $_day['stops'][$slot];
            $_bucket['start_at'][$slot]  += $_day['start_at'][$slot];
            $_bucket['stop_at'][$slot]   += $_day['stop_at'][$slot];
            if ($_day['state_at'][$slot] == 1) {
                $_bucket['from_on'][$slot]++;
            } else {
                $_bucket['from_off'][$slot]++;
            }
        }
    }

    /*
     * La tranche de profil à utiliser pour un jour donné.
     *
     * Le jour de semaine s'il a été observé assez souvent, l'ensemble sinon.
     * Le seuil est bas — trois journées — parce que le mélange avec
     * l'estimation de repli protège déjà des conclusions hâtives, et qu'un
     * seuil élevé priverait l'utilisateur de la distinction semaine / week-end
     * pendant deux mois.
     */
    public static function bucketFor($_profile, $_weekday, $_minDays = self::MIN_DAYS) {
        $key = (string) ((int) $_weekday);
        if (isset($_profile['buckets'][$key]) && $_profile['buckets'][$key]['days'] >= $_minDays) {
            return $_profile['buckets'][$key];
        }
        if (isset($_profile['buckets']['all'])) {
            return $_profile['buckets']['all'];
        }
        return self::emptyBucket();
    }

    /* La probabilité moyenne d'être allumée dans une tranche. */
    public static function occupancy($_bucket, $_slot) {
        if ($_bucket['days'] <= 0) {
            return 0.0;
        }
        $slot = self::wrapSlot($_slot);
        return $_bucket['occupancy'][$slot] / $_bucket['days'];
    }

    public static function wrapSlot($_slot) {
        $slot = ((int) $_slot) % self::SLOTS;
        return ($slot < 0) ? $slot + self::SLOTS : $slot;
    }

    /*
     * La probabilité que la lampe s'allume dans cette tranche, sachant qu'elle
     * est éteinte.
     *
     * Observé et estimé, mélangés. L'estimation vient de la montée de la courbe
     * d'occupation : si la lampe est allumée une fois sur dix à 19 h 00 et une
     * fois sur deux à 19 h 15, c'est que quatre soirs sur dix elle s'allume
     * dans cette tranche-là. C'est grossier, mais c'est une forme, et une forme
     * vaut mieux qu'une fréquence tirée de trois échantillons.
     */
    public static function startRate($_bucket, $_slot) {
        $slot = self::wrapSlot($_slot);
        $current  = self::occupancy($_bucket, $slot);
        $previous = self::occupancy($_bucket, $slot - 1);

        $rise = $current - $previous;
        $room = 1.0 - $previous;
        /*
         * Quand la lampe était déjà allumée tous les jours à la tranche
         * précédente, il n'y a plus de place pour une montée : la pente ne dit
         * rien, et un repli sur zéro voulait dire « elle ne s'allume jamais »
         * pour une lampe qui est allumée en permanence. C'est son occupation
         * qu'il faut lire — si elle est allumée à cette heure-là tous les
         * jours, et qu'on la trouve éteinte, elle doit s'allumer.
         */
        $prior = ($room > 0.01) ? max(0.0, $rise) / $room : $current;

        return self::blend($_bucket['starts'][$slot], $_bucket['from_off'][$slot], $prior);
    }

    /* La même chose pour l'extinction, en lisant la courbe dans l'autre sens. */
    public static function stopRate($_bucket, $_slot) {
        $slot = self::wrapSlot($_slot);
        $current  = self::occupancy($_bucket, $slot);
        $previous = self::occupancy($_bucket, $slot - 1);

        $fall = $previous - $current;
        $prior = ($previous > 0.01) ? max(0.0, $fall) / $previous : 0.0;

        return self::blend($_bucket['stops'][$slot], $_bucket['from_on'][$slot], $prior);
    }

    /* Le mélange lui-même : la fréquence observée attirée vers l'estimation,
     * d'autant plus fort que les observations sont peu nombreuses. */
    public static function blend($_count, $_trials, $_prior) {
        $count  = (float) $_count;
        $trials = (float) $_trials;
        $prior  = max(0.0, min(1.0, (float) $_prior));

        $rate = ($count + self::PRIOR_WEIGHT * $prior) / ($trials + self::PRIOR_WEIGHT);
        return max(0.0, min(1.0, $rate));
    }

    /* ============================================================ GÉNÉRATION */

    /*
     * Une journée à jouer, tirée du profil.
     *
     * Rend une liste de array('t' => minute, 'v' => 0 ou 1) : les changements à
     * produire, dans l'ordre. Le plan est complet — il se termine toujours par
     * une extinction si la lampe est encore allumée à la fin de la fenêtre.
     *
     * $_shift décale la journée entière de quelques minutes. C'est lui qui
     * porte la variabilité : multiplier les probabilités, comme on le faisait
     * d'abord, ne décalait aucune heure — les taux appris valant souvent 1, la
     * seule chose qu'on obtenait en montant la variabilité était une soirée sur
     * quatre purement annulée, c'est-à-dire l'inverse de ce que le réglage
     * promet.
     *
     * $_options :
     *   window_start / window_end : minutes, la fenêtre autorisée
     *   min_on / max_on           : bornes de durée d'un allumage
     */
    public static function generate($_bucket, $_options, $_seed, $_shift = 0) {
        $options = self::cleanOptions($_options);
        $state   = self::seed($_seed);
        $events  = array();

        /*
         * L'état de départ est tiré de l'occupation de la première tranche, et
         * non posé à « éteint ». Une lampe allumée en permanence — un couloir,
         * une veilleuse — a été vue allumée à minuit tous les jours et n'a
         * jamais été vue s'allumer : partir d'éteint la condamnerait à ne
         * jamais rien faire, et la maison perdrait justement la lampe qui reste
         * allumée.
         */
        $isOn = (self::next($state) < self::occupancy($_bucket, 0)) ? 1 : 0;
        $onSince = 0;
        if ($isOn == 1) {
            $events[] = array('t' => 0, 'v' => 1);
        }

        for ($slot = 0; $slot < self::SLOTS; $slot++) {
            $slotStart = $slot * self::SLOT_MINUTES;

            if ($isOn == 0) {
                if (self::next($state) < self::startRate($_bucket, $slot)) {
                    $minute = self::slotMinute($state, $_bucket, $slot, 'start');
                    $events[] = array('t' => $minute, 'v' => 1);
                    $isOn = 1;
                    $onSince = $minute;
                }
                continue;
            }

            /* Le plafond de durée se tient à la minute et non à la tranche :
             * l'évaluer sur la fin de tranche laissait dépasser jusqu'à un
             * quart d'heure, soit deux allumages sur cinq au-delà de ce qui
             * était demandé. */
            /*
             * Le plafond de durée ne s'applique pas à une lampe qui, à cette
             * heure-là, a été observée allumée presque tous les jours : la
             * couper au bout de sept heures inventerait une extinction que
             * personne n'a jamais faite, et une veilleuse de couloir
             * clignoterait une fois par nuit.
             */
            $deadline = $onSince + $options['max_on'];
            if ($deadline < $slotStart + self::SLOT_MINUTES && self::occupancy($_bucket, $slot) < 0.9) {
                $events[] = array('t' => max($slotStart, $deadline), 'v' => 0);
                $isOn = 0;
                continue;
            }

            /* Une lampe allumée depuis moins que la durée minimale ne s'éteint
             * pas : sans cette retenue, deux tranches consécutives un peu
             * bavardes produisent un clignotement que personne n'a jamais
             * observé dans une vraie maison. */
            $floor = $onSince + $options['min_on'];
            if ($floor >= $slotStart + self::SLOT_MINUTES) {
                continue;
            }

            if (self::next($state) < self::stopRate($_bucket, $slot)) {
                /* max() et non min() : le plancher de durée l'emporte sur la
                 * fin de tranche, sans quoi la durée minimale est ratée d'une
                 * minute exactement, une fois sur quatre. */
                $events[] = array('t' => max($floor, self::slotMinute($state, $_bucket, $slot, 'stop')), 'v' => 0);
                $isOn = 0;
            }
        }

        return self::applyWindow(self::shiftEvents($events, $_shift), $options);
    }

    /*
     * La minute du changement à l'intérieur de sa tranche.
     *
     * Centrée sur ce qui a été observé quand la tranche a déjà vu des
     * changements, tirée uniformément sinon. Tirer toujours au hasard dans le
     * quart d'heure introduit un biais systématique : une lampe allumée tous
     * les soirs à 19 h 00 ressort à 19 h 07 en moyenne, une lampe de 19 h 14
     * ressort à 19 h 10. L'information est pourtant là, il suffit de la garder.
     */
    public static function slotMinute(&$_state, $_bucket, $_slot, $_role) {
        $slot = self::wrapSlot($_slot);
        $slotStart = $slot * self::SLOT_MINUTES;

        $countKey = ($_role == 'start') ? 'starts' : 'stops';
        $sumKey   = ($_role == 'start') ? 'start_at' : 'stop_at';
        $count = isset($_bucket[$countKey][$slot]) ? $_bucket[$countKey][$slot] : 0;
        $sum   = isset($_bucket[$sumKey][$slot]) ? $_bucket[$sumKey][$slot] : 0;

        if ($count <= 0) {
            return $slotStart + self::nextInt($_state, 0, self::SLOT_MINUTES - 1);
        }

        /* Un flottement de quelques minutes autour de l'habitude : sans lui,
         * une lampe allumée trois fois à 19 h 02 repartirait à 19 h 02 tous les
         * soirs de l'année, ce qui est la minuterie qu'on cherchait à fuir. */
        $minute = ((int) round($sum / $count)) + self::nextInt($_state, -self::SLOT_JITTER, self::SLOT_JITTER);
        return $slotStart + max(0, min(self::SLOT_MINUTES - 1, $minute));
    }

    /*
     * Le décalage d'une journée, en minutes, tiré une fois pour toutes.
     *
     * Appelé deux fois par le plan : une fois pour le groupe, une fois pour la
     * lampe. La somme donne des soirées qui bougent ensemble — on rentre plus
     * tard, toute la maison s'allume plus tard — tout en restant distinctes.
     * C'est la seule corrélation entre lampes que le modèle produise, et c'est
     * celle qui manque le plus à un observateur qui regarde la façade.
     */
    public static function dayShift($_variability, $_amplitude, $_seed) {
        $amplitude = (int) round(max(0, min(100, (int) $_variability)) / 100.0 * (int) $_amplitude);
        if ($amplitude <= 0) {
            return 0;
        }
        $state = self::seed($_seed);
        return self::nextInt($state, -$amplitude, $amplitude);
    }

    /* Décale tous les changements d'une journée, en restant dans la journée :
     * un allumage repoussé au lendemain appartiendrait au plan du lendemain,
     * qui a déjà le sien. */
    public static function shiftEvents($_events, $_shift) {
        $shift = (int) $_shift;
        if ($shift == 0) {
            return $_events;
        }
        $shifted = array();
        foreach ($_events as $event) {
            $minute = ((int) $event['t']) + $shift;
            $shifted[] = array(
                't' => max(0, min(simulationpresenceintelligentbeSun::DAY_MINUTES - 1, $minute)),
                'v' => $event['v'],
            );
        }
        return $shifted;
    }

    /*
     * Une journée inventée de toutes pièces, pour une lampe sans passé.
     *
     * C'est le cas le plus fréquent au premier jour, et c'est pour cette raison
     * qu'il est traité avec autant de soin que l'autre : un plugin qui ne fait
     * rien tant qu'il n'a pas appris n'est jamais adopté. On se cale sur le
     * coucher du soleil, qui est la seule chose qu'on sache vraiment de la
     * soirée d'une maison qu'on ne connaît pas, et on tire le reste.
     *
     * $_sun : array('sunrise' => minute|null, 'sunset' => minute|null)
     */
    public static function invent($_options, $_sun, $_seed, $_shift = 0) {
        $options = self::cleanOptions($_options);
        $state   = self::seed($_seed);
        $events  = array();

        $sunset  = isset($_sun['sunset'])  ? $_sun['sunset']  : null;
        $sunrise = isset($_sun['sunrise']) ? $_sun['sunrise'] : null;

        /* Sans position renseignée, on se rabat sur une soirée d'équinoxe. Le
         * plugin le dit dans sa page de configuration ; il ne reste pas muet
         * pour autant. */
        if ($sunset === null) {
            $sunset = 19 * 60;
        }
        if ($sunrise === null) {
            $sunrise = 7 * 60;
        }

        /* La soirée : une lampe sur deux par défaut, pour qu'un groupe de six
         * n'allume pas six lampes tous les soirs à la même minute. */
        if (self::next($state) * 100.0 < $options['evening_chance']) {
            $on = $sunset + $options['sunset_offset'] + self::nextInt($state, -$options['spread'], $options['spread']);
            /* Le flottement ne doit pas faire sortir l'allumage de la fenêtre :
             * il serait abandonné plus loin, en silence, et un flottement large
             * en juin ferait disparaître une soirée sur sept sans que rien ne
             * le dise. */
            $on = self::clampToWindow($on, $options);
            $off = $options['bedtime'] + self::nextInt($state, -$options['spread'], $options['spread']);
            /*
             * Un coucher saisi après minuit — « 00:30 » — donne une heure plus
             * petite que l'allumage. Sans ce contrôle, le plancher de durée
             * transformait la soirée entière en un éclair de douze minutes,
             * pour une saisie parfaitement naturelle. La soirée court alors
             * jusqu'à la fermeture de la fenêtre, ce que l'utilisateur voulait
             * dire.
             */
            if ($off <= $on) {
                $off = simulationpresenceintelligentbeSun::DAY_MINUTES - 1;
            }
            if ($off - $on < $options['min_on']) {
                $off = $on + $options['min_on'];
            }
            if ($off - $on > $options['max_on']) {
                $off = $on + $options['max_on'];
            }
            $events[] = array('t' => $on, 'v' => 1);
            $events[] = array('t' => $off, 'v' => 0);
        }

        /* Le matin, plus rare : une maison vide dont les lampes s'allument tous
         * les matins à six heures est aussi peu crédible qu'une maison éteinte
         * tous les soirs. */
        if ($options['morning_chance'] > 0 && self::next($state) * 100.0 < $options['morning_chance']) {
            $on = self::clampToWindow($options['wake'] + self::nextInt($state, -$options['spread'], $options['spread']), $options);
            $off = min($sunrise + 30, $on + $options['min_on'] + self::nextInt($state, 10, 60));
            if ($off <= $on) {
                $off = $on + $options['min_on'];
            }
            $events[] = array('t' => $on, 'v' => 1);
            $events[] = array('t' => $off, 'v' => 0);
        }

        usort($events, function ($_a, $_b) {
            return $_a['t'] - $_b['t'];
        });
        return self::applyWindow(self::shiftEvents($events, $_shift), $options);
    }

    /* Ramène une heure d'allumage dans la fenêtre, en laissant de quoi tenir la
     * durée minimale : allumer trois minutes avant la fermeture ne trompe
     * personne et se remarque. */
    public static function clampToWindow($_minute, $_options) {
        $ceiling = $_options['window_end'] - $_options['min_on'];
        if ($ceiling < $_options['window_start']) {
            $ceiling = $_options['window_start'];
        }
        return max($_options['window_start'], min($ceiling, (int) $_minute));
    }

    /*
     * La fenêtre autorisée, appliquée au plan.
     *
     * Elle ne décale rien : un allumage prévu hors fenêtre est retiré, pas
     * repoussé. Repousser regrouperait tous les allumages de la nuit sur la
     * minute d'ouverture, ce qui se remarque de la rue bien plus qu'une lampe
     * qui ne s'allume pas. Seule exception, l'état initial : une lampe déjà
     * allumée à minuit est ramenée à l'ouverture, parce qu'il ne s'agit pas
     * d'un allumage décidé mais d'un état qui dure.
     *
     * Une lampe encore allumée à la fermeture est éteinte : c'est la promesse
     * de la fenêtre, et la seule façon de tenir « rien après 23 h 30 ».
     *
     * Le travail se fait par paires et non événement par événement, parce que
     * c'est la seule façon de connaître la durée : tronquer un allumage à la
     * fermeture sans la regarder produisait des éclairs de quelques minutes, et
     * parfois de zéro, c'est-à-dire une façade qui clignote une fois par soir.
     */
    public static function applyWindow($_events, $_options) {
        $options = self::cleanOptions($_options);
        $start = $options['window_start'];
        $end   = $options['window_end'];

        $pairs = array();
        $onAt = null;
        foreach ($_events as $event) {
            $minute = (int) $event['t'];
            if ($event['v'] == 1) {
                if ($onAt === null) {
                    $onAt = $minute;
                }
                continue;
            }
            if ($onAt !== null) {
                $pairs[] = array($onAt, $minute);
                $onAt = null;
            }
        }
        if ($onAt !== null) {
            $pairs[] = array($onAt, simulationpresenceintelligentbeSun::DAY_MINUTES - 1);
        }

        $events = array();
        foreach ($pairs as $pair) {
            $on = $pair[0];
            if ($on < $start) {
                /* Minuit exactement : c'est l'état initial, on le ramène à
                 * l'ouverture. Toute autre heure est un allumage décidé hors
                 * fenêtre, et il est abandonné. */
                if ($on != 0) {
                    continue;
                }
                $on = $start;
            }
            if ($on > $end) {
                continue;
            }
            $off = min($pair[1], $end);
            if (($off - $on) < $options['min_on']) {
                continue;
            }
            $events[] = array('t' => $on, 'v' => 1);
            $events[] = array('t' => $off, 'v' => 0);
        }
        return $events;
    }

    /* Les réglages de génération, ramenés à des nombres utilisables. Tout ce
     * qui vient du formulaire est une chaîne, y compris « 0 » et « ». */
    public static function cleanOptions($_options) {
        $options = is_array($_options) ? $_options : array();
        $clean = array(
            'window_start'   => 0,
            'window_end'     => simulationpresenceintelligentbeSun::DAY_MINUTES - 1,
            'variability'    => 30,
            'min_on'         => self::MIN_ON_MINUTES,
            'max_on'         => self::MAX_ON_MINUTES,
            'evening_chance' => 60,
            'morning_chance' => 0,
            'sunset_offset'  => -15,
            'spread'         => 25,
            'bedtime'        => 23 * 60,
            'wake'           => 7 * 60,
        );
        foreach ($clean as $key => $default) {
            if (isset($options[$key]) && $options[$key] !== '') {
                $clean[$key] = (int) $options[$key];
            }
        }

        $clean['window_start']   = max(0, min(simulationpresenceintelligentbeSun::DAY_MINUTES - 1, $clean['window_start']));
        $clean['window_end']     = max($clean['window_start'], min(simulationpresenceintelligentbeSun::DAY_MINUTES - 1, $clean['window_end']));
        $clean['variability']    = max(0, min(100, $clean['variability']));
        $clean['evening_chance'] = max(0, min(100, $clean['evening_chance']));
        $clean['morning_chance'] = max(0, min(100, $clean['morning_chance']));
        $clean['spread']         = max(0, min(180, $clean['spread']));
        $clean['min_on']         = max(1, min(720, $clean['min_on']));
        $clean['max_on']         = max($clean['min_on'], min(1440, $clean['max_on']));
        /* Ces trois-là étaient oubliés. Un décalage de coucher aberrant sortait
         * l'allumage de la journée, la fenêtre l'abandonnait ensuite en
         * silence, et la lampe ne s'allumait plus jamais sans qu'un seul
         * message ne soit écrit nulle part. */
        $clean['sunset_offset']  = max(-720, min(720, $clean['sunset_offset']));
        $clean['bedtime']        = max(0, min(simulationpresenceintelligentbeSun::DAY_MINUTES - 1, $clean['bedtime']));
        $clean['wake']           = max(0, min(simulationpresenceintelligentbeSun::DAY_MINUTES - 1, $clean['wake']));
        return $clean;
    }

    /* =========================================================== ARBITRAGES */

    /*
     * Le nombre maximum de lampes allumées en même temps.
     *
     * Les lampes déjà allumées gardent la main : éteindre celle qui brûle
     * depuis une heure pour allumer la suivante produirait un défilé de pièces
     * qu'aucune maison ne fait. Les autres sont servies dans l'ordre du groupe,
     * qui est celui que l'utilisateur a choisi.
     */
    public static function capSimultaneous($_wanted, $_runtime, $_max) {
        if ($_max <= 0) {
            return $_wanted;
        }
        $already = array();
        $candidates = array();
        foreach ($_wanted as $key => $value) {
            if ($value != 1) {
                continue;
            }
            $ordered = isset($_runtime['lamps'][$key]['ordered']) ? $_runtime['lamps'][$key]['ordered'] : null;
            if ($ordered == 1) {
                $already[] = $key;
            } else {
                $candidates[] = $key;
            }
        }
        $allowed = array_slice(array_merge($already, $candidates), 0, $_max);
        foreach ($_wanted as $key => $value) {
            if ($value == 1 && !in_array($key, $allowed)) {
                $_wanted[$key] = 0;
            }
        }
        return $_wanted;
    }

    /* La comparaison elle-même, sans Jeedom : deux nombres se comparent comme
     * des nombres, deux textes comme des textes. Sans cette distinction,
     * « Armé » > « 0 » donnerait des résultats que personne ne peut prévoir. */
    public static function compare($_value, $_operator, $_expected) {
        $numeric = is_numeric($_value) && is_numeric($_expected);
        $left  = $numeric ? (float) $_value : mb_strtolower(trim((string) $_value));
        $right = $numeric ? (float) $_expected : mb_strtolower(trim((string) $_expected));

        switch ($_operator) {
            case '!=': return $left != $right;
            case '>':  return $left >  $right;
            case '>=': return $left >= $right;
            case '<':  return $left <  $right;
            case '<=': return $left <= $right;
        }
        return $left == $right;
    }

    /* ================================================================ LECTURE */

    /* Le nombre de minutes allumées d'un plan, et le nombre d'allumages : de
     * quoi dire à l'utilisateur ce que sa soirée va donner sans lui faire lire
     * une liste d'heures. */
    public static function summary($_events) {
        $summary = array('switches' => 0, 'minutes' => 0, 'first' => null, 'last' => null);
        $onAt = null;
        foreach ($_events as $event) {
            if ($event['v'] == 1) {
                $summary['switches']++;
                $onAt = (int) $event['t'];
                if ($summary['first'] === null) {
                    $summary['first'] = $onAt;
                }
                continue;
            }
            if ($onAt !== null) {
                $summary['minutes'] += max(0, ((int) $event['t']) - $onAt);
                $summary['last'] = (int) $event['t'];
                $onAt = null;
            }
        }
        return $summary;
    }

    /* L'état que le plan prévoit à une minute donnée. Sert au rattrapage : une
     * box redémarrée à 21 h doit rallumer ce qui devait l'être, sans rejouer
     * les allumages du début de soirée. */
    public static function stateAt($_events, $_minute) {
        $state = 0;
        foreach ($_events as $event) {
            if ((int) $event['t'] > (int) $_minute) {
                break;
            }
            $state = ($event['v'] == 1) ? 1 : 0;
        }
        return $state;
    }
}
