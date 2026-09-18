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
 * Le soleil, et la minute du jour.
 *
 * Deux sujets minuscules, mais qui traversent tout le plugin : une journée
 * simulée se raisonne en minutes depuis minuit — c'est l'unité du journal, du
 * profil et du plan — et une journée inventée de toutes pièces se cale sur le
 * coucher du soleil, faute de quoi elle allumerait le salon à 15 h en juin.
 *
 * Cette classe ne connaît pas Jeedom : elle reçoit une position et rend des
 * nombres. C'est ce qui permet de l'éprouver sur une année entière en une
 * seconde, hors ligne (voir tests/run.php).
 */
class simulationpresenceintelligentbeSun {

    /* Une journée en minutes. Le plugin ne descend jamais sous la minute :
     * personne ne regarde une maison à la seconde près, et l'historique de
     * Jeedom lui-même n'est relu qu'à la minute. */
    const DAY_MINUTES = 1440;

    /*
     * Lever et coucher du soleil, en minutes depuis minuit, pour un jour donné.
     *
     * date_sun_info() est dans PHP depuis la version 5.1 : aucune dépendance à
     * installer, aucun service à interroger, et le même résultat que celui
     * qu'affichent les scénarios du coeur, qui appellent la même fonction avec
     * la même position.
     *
     * Elle rend true ou false, et non un horodatage, quand le soleil ne se lève
     * ou ne se couche pas du jour — cercle polaire. On rend alors null : une
     * heure qui n'existe pas ne doit pas se replier sur minuit, sinon la
     * simulation allumerait tout à 00 h 00.
     */
    public static function sun($_dayTimestamp, $_latitude, $_longitude) {
        /* Midi et non l'heure reçue : à 23 h 30, le coucher que date_sun_info
         * rend pour l'instant présent est celui du jour en cours, mais un appel
         * fait à 00 h 10 porterait déjà sur le lendemain. Partir de midi rend le
         * résultat indépendant de l'heure d'appel. */
        $noon = mktime(12, 0, 0, (int) date('n', $_dayTimestamp), (int) date('j', $_dayTimestamp), (int) date('Y', $_dayTimestamp));
        $info = @date_sun_info($noon, (float) $_latitude, (float) $_longitude);

        $result = array('sunrise' => null, 'sunset' => null);
        if (!is_array($info)) {
            return $result;
        }
        foreach (array('sunrise', 'sunset') as $key) {
            if (isset($info[$key]) && !is_bool($info[$key])) {
                $result[$key] = self::minuteOfDay((int) $info[$key]);
            }
        }
        return $result;
    }

    /* La minute du jour d'un horodatage : 0 à minuit, 1439 à 23 h 59. */
    public static function minuteOfDay($_timestamp) {
        return ((int) date('H', $_timestamp)) * 60 + ((int) date('i', $_timestamp));
    }

    /* « 7:5 », « 07h05 », « 0705 » — tout ce qu'un humain tape pour une heure,
     * ramené à HH:MM, ou vide si ce n'en est pas une. */
    public static function cleanTime($_value) {
        $value = trim((string) $_value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^(\d{1,2})[:hH.]?(\d{2})$/', $value, $matches)) {
            return '';
        }
        $hours   = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours > 23 || $minutes > 59) {
            return '';
        }
        return sprintf('%02d:%02d', $hours, $minutes);
    }

    /*
     * Une borne de fenêtre horaire : une heure fixe, ou une heure de soleil.
     *
     * « Rien avant 07:00 » n'a aucun sens en juin, où le soleil se lève à
     * 05 h 31 : la fenêtre censée protéger les nuits interdit alors deux heures
     * de plein jour. Une borne peut donc s'écrire « sunset-30 » ou
     * « sunrise+15 », et suivre le soleil toute l'année.
     *
     * Le mot-clé est rangé en anglais, comme les tags du coeur (#sunset#), mais
     * la saisie accepte aussi le français : ce qui est stocké ne dépend pas de
     * la langue de celui qui l'a tapé.
     */
    const BOUND_SUNSET  = 'sunset';
    const BOUND_SUNRISE = 'sunrise';

    public static function cleanBound($_value) {
        $value = trim((string) $_value);
        if ($value === '') {
            return '';
        }

        $time = self::cleanTime($value);
        if ($time !== '') {
            return $time;
        }

        $normalise = mb_strtolower(str_replace(array(' ', 'é'), array('', 'e'), $value), 'UTF-8');
        /* Cinq chiffres et non trois : un décalage aberrant doit être compris
         * puis borné, pas rejeté en silence — sinon la saisie est ignorée et
         * l'ancienne valeur reste, sans que rien ne le dise. */
        if (!preg_match('/^(sunset|coucher|sunrise|lever)([+-]\d{1,5})?$/', $normalise, $matches)) {
            return '';
        }
        $mot = (in_array($matches[1], array('sunset', 'coucher'))) ? self::BOUND_SUNSET : self::BOUND_SUNRISE;
        $offset = isset($matches[2]) ? (int) $matches[2] : 0;
        /* Douze heures de décalage suffisent à tout usage réel et empêchent
         * qu'un réglage absurde ouvre la fenêtre la veille. */
        $offset = max(-720, min(720, $offset));
        return $mot . (($offset >= 0) ? '+' : '') . $offset;
    }

    /*
     * La borne, en minutes, pour un jour dont on connaît le soleil.
     *
     * Rend $_default quand la borne est vide, illisible, ou quand le soleil ne
     * se lève pas ce jour-là : une fenêtre qui n'existe pas doit se replier sur
     * quelque chose, sinon la simulation s'arrête au cercle polaire.
     */
    public static function resolveBound($_bound, $_sun, $_default) {
        $bound = self::cleanBound($_bound);
        if ($bound === '') {
            return $_default;
        }

        $minute = self::timeToMinute($bound);
        if ($minute !== null) {
            return $minute;
        }

        if (!preg_match('/^(sunset|sunrise)([+-]\d{1,3})$/', $bound, $matches)) {
            return $_default;
        }
        $reference = isset($_sun[$matches[1]]) ? $_sun[$matches[1]] : null;
        if ($reference === null) {
            return $_default;
        }
        return max(0, min(self::DAY_MINUTES - 1, $reference + (int) $matches[2]));
    }

    /* Une borne telle qu'on l'affiche : l'heure fixe telle quelle, l'heure de
     * soleil résolue et annotée, pour que l'utilisateur voie ce que son réglage
     * donne aujourd'hui. */
    public static function describeBound($_bound, $_sun) {
        $bound = self::cleanBound($_bound);
        if ($bound === '' || self::timeToMinute($bound) !== null) {
            return $bound;
        }
        $minute = self::resolveBound($bound, $_sun, null);
        return ($minute === null) ? $bound : $bound . ' (' . self::minuteToTime($minute) . ')';
    }

    /* HH:MM vers la minute du jour, ou null si l'heure est vide ou illisible. */
    public static function timeToMinute($_value) {
        $time = self::cleanTime($_value);
        if ($time === '') {
            return null;
        }
        $parts = explode(':', $time);
        return ((int) $parts[0]) * 60 + ((int) $parts[1]);
    }

    /* L'inverse, pour l'affichage. Une minute au-delà de la journée est ramenée
     * dans la journée : un plan calculé sur « coucher + 6 h » en décembre peut
     * déborder, et « 25:10 » n'est une heure pour personne. */
    public static function minuteToTime($_minute) {
        $minute = ((int) $_minute) % self::DAY_MINUTES;
        if ($minute < 0) {
            $minute += self::DAY_MINUTES;
        }
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
