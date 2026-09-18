<?php
/*
 * Jeu d'essai hors ligne.
 *
 *   php tests/run.php
 *
 * Il ne charge pas Jeedom : seules les deux classes qui décident sont
 * éprouvées — les heures de soleil, et le modèle qui apprend une journée puis
 * en invente une autre. C'est là que se trouvent les erreurs qu'aucune
 * relecture ne montre, et la seule façon de les voir est de faire tourner
 * quelques milliers de journées en une seconde.
 */

date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/../core/class/simulationpresenceintelligentbeSun.class.php';
require_once __DIR__ . '/../core/class/simulationpresenceintelligentbeProfile.class.php';
require_once __DIR__ . '/../core/class/simulationpresenceintelligentbeLamps.class.php';

/* Deux noms courts pour des classes qui n'en ont pas : le jeu d'essai les
 * appelle des centaines de fois, et « simulationpresenceintelligentbeProfile::
 * startRate » sur chaque ligne rendrait les contrôles illisibles. */
class_alias('simulationpresenceintelligentbeSun', 'SUN');
class_alias('simulationpresenceintelligentbeProfile', 'BRAIN');

/* Nivelles, la position de l'installation de référence. */
const LAT = 50.5476;
const LON = 4.0520;

$ok = 0;
$ko = 0;

function verifie($_titre, $_obtenu, $_attendu) {
    global $ok, $ko;
    if ($_obtenu == $_attendu) {
        $ok++;
        printf("  %-62s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-62s ÉCHEC : obtenu %s, attendu %s\n", $_titre,
           var_export($_obtenu, true), var_export($_attendu, true));
}

function verifieVrai($_titre, $_condition) {
    verifie($_titre, $_condition ? true : false, true);
}

/* Une journée « allumée de $on à $off », sous la forme attendue par le modèle. */
function journee($_on, $_off) {
    return array(
        array('t' => 0, 'v' => 0),
        array('t' => $_on, 'v' => 1),
        array('t' => $_off, 'v' => 0),
    );
}

/* ---- 1 ---------------------------------------------------------------------
 * Les heures saisies. Tout ce qui vient d'un formulaire est une chaîne, et une
 * heure mal relue ne se voit jamais : elle décale la soirée d'une heure, ou
 * l'annule, sans rien écrire dans le journal.
 */
echo "\nHeures saisies\n";
verifie('07:05 reste 07:05',            SUN::cleanTime('07:05'), '07:05');
verifie('7:5 est refusé',               SUN::cleanTime('7:5'), '');
verifie('7h05 devient 07:05',           SUN::cleanTime('7h05'), '07:05');
verifie('0705 devient 07:05',           SUN::cleanTime('0705'), '07:05');
verifie('24:00 est refusé',             SUN::cleanTime('24:00'), '');
verifie('07:60 est refusé',             SUN::cleanTime('07:60'), '');
verifie('vide reste vide',              SUN::cleanTime(''), '');
verifie('19:30 vaut 1170 minutes',      SUN::timeToMinute('19:30'), 1170);
verifie('une heure illisible rend null', SUN::timeToMinute('plus tard'), null);
verifie('1170 minutes redonnent 19:30', SUN::minuteToTime(1170), '19:30');
verifie('minuit',                       SUN::minuteToTime(0), '00:00');
verifie('un débordement revient dans la journée', SUN::minuteToTime(1500), '01:00');
verifie('une minute négative aussi',    SUN::minuteToTime(-30), '23:30');

/* ---- 2 ---------------------------------------------------------------------
 * Le soleil. Les journées inventées s'y calent : un coucher faux de deux heures
 * allume les lampes en plein jour, ce qui se remarque de la rue.
 */
echo "\nSoleil\n";
$juin = SUN::sun(strtotime('2026-06-21 03:00:00'), LAT, LON);
$dec  = SUN::sun(strtotime('2026-12-21 03:00:00'), LAT, LON);
verifieVrai('le soleil se couche plus tard en juin qu\'en décembre', $juin['sunset'] > $dec['sunset']);
verifieVrai('et il se lève plus tôt',                                $juin['sunrise'] < $dec['sunrise']);
verifieVrai('coucher de juin après 21 h',                            $juin['sunset'] > 21 * 60);
verifieVrai('coucher de décembre avant 17 h 30',                     $dec['sunset'] < 17 * 60 + 30);
/* Appelé à 23 h 30, le calcul doit porter sur le jour demandé et non sur le
 * lendemain : c'est le piège que règle le passage par midi. */
$tard = SUN::sun(strtotime('2026-06-21 23:30:00'), LAT, LON);
verifie('l\'heure d\'appel ne change pas le résultat', $tard['sunset'], $juin['sunset']);

/* ---- 3 ---------------------------------------------------------------------
 * Une journée observée. L'occupation par tranche et les changements sont la
 * matière première de tout le reste : une erreur ici se propage partout et ne
 * produit qu'un plan « un peu bizarre ».
 */
echo "\nUne journée observée\n";
$jour = BRAIN::buildDay(journee(19 * 60, 23 * 60));
verifie('quatre heures allumée',                 $jour['minutes'], 240);
verifie('tranche de 19 h 00 pleine',             $jour['occupancy'][76], 1.0);
verifie('tranche de 18 h 45 vide',               $jour['occupancy'][75], 0.0);
verifie('un seul allumage',                      array_sum($jour['starts']), 1);
verifie('une seule extinction',                  array_sum($jour['stops']), 1);
verifie('l\'allumage est dans la tranche de 19 h', $jour['starts'][76], 1);
verifie('éteinte au début de la tranche de 19 h', $jour['state_at'][76], 0);
verifie('allumée au début de la tranche de 20 h', $jour['state_at'][80], 1);

/* Un allumage au milieu d'une tranche ne remplit que la part qui reste. */
$partiel = BRAIN::buildDay(journee(19 * 60 + 5, 19 * 60 + 35));
verifie('10 minutes sur 15 dans la première tranche', round($partiel['occupancy'][76], 4), round(10 / 15, 4));
verifie('5 minutes sur 15 dans la dernière',          round($partiel['occupancy'][78], 4), round(5 / 15, 4));
verifie('30 minutes en tout',                          $partiel['minutes'], 30);

/* Une lampe allumée depuis la veille : sans l'état initial, toute la matinée
 * serait comptée éteinte. */
$veille = BRAIN::buildDay(array(array('t' => 0, 'v' => 1), array('t' => 8 * 60, 'v' => 0)));
verifie('allumée dès minuit',        $veille['occupancy'][0], 1.0);
verifie('huit heures au compteur',   $veille['minutes'], 480);
verifie('aucun allumage compté',     array_sum($veille['starts']), 0);

echo "\nPoints désordonnés\n";
$melange = BRAIN::cleanPoints(array(
    array('t' => 1200, 'v' => 0),
    array('t' => 0, 'v' => 0),
    array('t' => 1140, 'v' => 1),
    array('t' => 1140, 'v' => 0),
));
verifie('remis dans l\'ordre',            $melange[1]['t'], 1140);
verifie('deux points d\'une même minute fusionnent', count($melange), 3);
verifie('c\'est le dernier qui tient',    $melange[1]['v'], 0);
verifie('un point hors journée est écarté', count(BRAIN::cleanPoints(array(array('t' => 2000, 'v' => 1)))), 0);

/* ---- 4 ---------------------------------------------------------------------
 * Le profil. Ce qui distingue un mardi d'un samedi, et ce qui arrive quand on
 * n'a pas encore assez vu pour le dire.
 */
echo "\nProfil\n";
$jours = array();
for ($semaine = 0; $semaine < 4; $semaine++) {
    for ($jour = 0; $jour < 7; $jour++) {
        $date = date('Y-m-d', strtotime('2026-01-05 +' . ($semaine * 7 + $jour) . ' day'));
        $numero = (int) date('N', strtotime($date . ' 12:00:00'));
        /* En semaine : 19 h – 23 h. Le week-end : 17 h – 00 h 45, mais borné à
         * la journée. */
        $jours[$date] = ($numero >= 6) ? journee(17 * 60, 23 * 60 + 45) : journee(19 * 60, 23 * 60);
    }
}
$profil = BRAIN::build($jours);
verifie('28 journées',                     $profil['days'], 28);
verifie('la première est le 5 janvier',    $profil['first'], '2026-01-05');
verifie('quatre lundis',                   $profil['buckets']['1']['days'], 4);
verifie('toutes journées confondues : 28', $profil['buckets']['all']['days'], 28);

$lundi  = BRAIN::bucketFor($profil, 1, 3);
$samedi = BRAIN::bucketFor($profil, 6, 3);
verifie('le lundi, rien à 17 h 30',       BRAIN::occupancy($lundi, 70), 0.0);
verifie('le samedi, tout allumé à 17 h 30', BRAIN::occupancy($samedi, 70), 1.0);
verifie('le lundi, allumé à 20 h',        BRAIN::occupancy($lundi, 80), 1.0);

/* Un jour de semaine trop peu vu retombe sur l'ensemble : c'est ce qui permet
 * d'être utile la première semaine sans conclure de travers. */
$maigre = BRAIN::build(array('2026-01-05' => journee(19 * 60, 23 * 60)));
verifie('un seul lundi ne suffit pas', BRAIN::bucketFor($maigre, 1, 3)['days'], 1);
verifieVrai('et c\'est l\'ensemble qui répond',
    BRAIN::bucketFor($maigre, 1, 3) == $maigre['buckets']['all']);

echo "\nLissage\n";
verifie('rien d\'observé rend l\'estimation', round(BRAIN::blend(0, 0, 0.5), 4), 0.5);
verifieVrai('un seul succès ne donne pas la certitude', BRAIN::blend(1, 1, 0.0) < 1.0);
verifieVrai('mais il tire vers le haut',                BRAIN::blend(1, 1, 0.0) > 0.0);
verifieVrai('cent succès l\'emportent sur l\'estimation', BRAIN::blend(100, 100, 0.0) > 0.95);
verifieVrai('le résultat reste une probabilité',
    BRAIN::blend(10, 3, 5.0) <= 1.0 && BRAIN::blend(-10, 3, -5.0) >= 0.0);

echo "\nTaux de changement\n";
verifieVrai('on s\'allume beaucoup dans la tranche de 19 h', BRAIN::startRate($lundi, 76) > 0.8);
verifieVrai('et presque jamais à 15 h',                      BRAIN::startRate($lundi, 60) < 0.2);
verifieVrai('on s\'éteint dans la tranche de 23 h',          BRAIN::stopRate($lundi, 92) > 0.8);
verifieVrai('et pas à 21 h',                                 BRAIN::stopRate($lundi, 84) < 0.2);

/* ---- 5 ---------------------------------------------------------------------
 * La génération. C'est ce que la rue voit.
 */
echo "\nUne journée rejouée\n";
$options = array('window_start' => 0, 'window_end' => 1439, 'variability' => 0);
$plan = BRAIN::generate($lundi, $options, 'test|1');

verifieVrai('quelque chose est prévu', count($plan) > 0);
verifie('le plan est le même à graine égale', BRAIN::generate($lundi, $options, 'test|1'), $plan);
verifieVrai('et différent d\'un jour à l\'autre', BRAIN::generate($lundi, $options, 'test|2') != $plan);

$alterne = true;
$attendu = 1;
$croissant = true;
$precedent = -1;
foreach ($plan as $evenement) {
    if ($evenement['v'] != $attendu) { $alterne = false; }
    $attendu = 1 - $attendu;
    if ($evenement['t'] < $precedent) { $croissant = false; }
    $precedent = $evenement['t'];
}
verifieVrai('allumage et extinction alternent', $alterne);
verifieVrai('les heures vont en croissant',     $croissant);

/* Le modèle doit retrouver la soirée qu'on lui a apprise, à un quart d'heure
 * près : c'est la seule vérification qui dit que tout l'édifice sert à quelque
 * chose. */
$premiers = array();
for ($essai = 0; $essai < 200; $essai++) {
    $tirage = BRAIN::generate($lundi, $options, 'maison|' . $essai);
    if (count($tirage) > 0) {
        $premiers[] = $tirage[0]['t'];
    }
}
verifieVrai('presque toutes les soirées s\'allument', count($premiers) > 190);
$dansLaFenetre = 0;
foreach ($premiers as $minute) {
    if ($minute >= 19 * 60 && $minute < 19 * 60 + 15) { $dansLaFenetre++; }
}
verifieVrai('et dans le bon quart d\'heure', $dansLaFenetre > (count($premiers) * 0.9));

echo "\nFenêtre horaire\n";
$serre = BRAIN::generate($lundi, array('window_start' => 20 * 60, 'window_end' => 21 * 60, 'variability' => 0), 'maison|3');
$dehors = false;
foreach ($serre as $evenement) {
    if ($evenement['t'] < 20 * 60 || $evenement['t'] > 21 * 60) { $dehors = true; }
}
verifieVrai('rien ne sort de la fenêtre', !$dehors);
if (count($serre) > 0) {
    verifie('et la journée finit éteinte', $serre[count($serre) - 1]['v'], 0);
}

/* Une lampe encore allumée à la fermeture est éteinte à la fermeture, pas plus
 * tard : c'est toute la promesse du réglage « rien après 23 h 30 ». */
$deborde = BRAIN::applyWindow(array(
    array('t' => 22 * 60, 'v' => 1),
    array('t' => 23 * 60 + 50, 'v' => 0),
), array('window_start' => 7 * 60, 'window_end' => 23 * 60 + 30));
verifie('l\'extinction est ramenée à la fermeture', $deborde[1]['t'], 23 * 60 + 30);

$jamais = BRAIN::applyWindow(array(array('t' => 3 * 60, 'v' => 1)), array('window_start' => 7 * 60, 'window_end' => 1380));
verifie('un allumage hors fenêtre est abandonné, pas repoussé', count($jamais), 0);

$oubli = BRAIN::applyWindow(array(array('t' => 20 * 60, 'v' => 1)), array('window_start' => 0, 'window_end' => 1380));
verifie('une lampe laissée allumée est éteinte à la fermeture', count($oubli), 2);
verifie('à l\'heure de fermeture',                              $oubli[1]['t'], 1380);

echo "\nDurée d'allumage\n";
$court = BRAIN::generate($lundi, array('window_start' => 0, 'window_end' => 1439, 'variability' => 0, 'min_on' => 60), 'duree|1');
$tropCourt = false;
$debut = null;
foreach ($court as $evenement) {
    if ($evenement['v'] == 1) { $debut = $evenement['t']; continue; }
    if ($debut !== null && ($evenement['t'] - $debut) < 60) { $tropCourt = true; }
    $debut = null;
}
verifieVrai('aucun allumage plus court que la durée minimale', !$tropCourt);

/* ---- 6 ---------------------------------------------------------------------
 * Les journées inventées. Ce sont elles qui tournent le premier jour, donc
 * celles que l'utilisateur juge.
 */
echo "\nUne journée inventée\n";
$soleil = array('sunrise' => 7 * 60, 'sunset' => 21 * 60);
$base = array('window_start' => 0, 'window_end' => 1439, 'evening_chance' => 100, 'morning_chance' => 0,
              'sunset_offset' => -15, 'spread' => 20, 'bedtime' => 23 * 60);

$invente = BRAIN::invent($base, $soleil, 'invente|1');
verifie('une soirée, deux changements',   count($invente), 2);
verifie('elle commence par un allumage',  $invente[0]['v'], 1);
verifieVrai('autour du coucher du soleil',
    abs($invente[0]['t'] - (21 * 60 - 15)) <= 20);
verifieVrai('et s\'éteint autour de l\'heure du coucher',
    abs($invente[1]['t'] - 23 * 60) <= 20);

$options0 = array_merge($base, array('evening_chance' => 0));
verifie('à 0 %, aucune soirée', count(BRAIN::invent($options0, $soleil, 'invente|2')), 0);

$avecMatin = array_merge($base, array('morning_chance' => 100, 'wake' => 7 * 60, 'spread' => 0));
$matin = BRAIN::invent($avecMatin, $soleil, 'invente|3');
verifie('un matin et un soir font quatre changements', count($matin), 4);
verifieVrai('le matin vient en premier', $matin[0]['t'] < 12 * 60);

/* Sans position renseignée, il faut quand même une soirée : un plugin muet
 * passerait pour cassé. */
$sansSoleil = BRAIN::invent($base, array('sunrise' => null, 'sunset' => null), 'invente|4');
verifieVrai('sans position, une soirée quand même', count($sansSoleil) > 0);

/* Toutes les lampes du groupe ne doivent pas s'allumer à la même minute : c'est
 * le défaut qui trahit une simulation vue de la rue. */
$heures = array();
for ($lampe = 0; $lampe < 8; $lampe++) {
    $tirage = BRAIN::invent($base, $soleil, 'groupe|' . $lampe . '|2026-09-18');
    if (count($tirage) > 0) { $heures[] = $tirage[0]['t']; }
}
verifieVrai('les lampes ne s\'allument pas toutes ensemble', count(array_unique($heures)) > 1);

/* ---- 7 ---------------------------------------------------------------------
 * Lecture du plan, et arbitrages de l'exécution.
 */
echo "\nLecture du plan\n";
$exemple = array(array('t' => 19 * 60, 'v' => 1), array('t' => 23 * 60, 'v' => 0));
verifie('éteinte à 18 h',   BRAIN::stateAt($exemple, 18 * 60), 0);
verifie('allumée à 19 h',   BRAIN::stateAt($exemple, 19 * 60), 1);
verifie('allumée à 22 h',   BRAIN::stateAt($exemple, 22 * 60), 1);
verifie('éteinte à 23 h',   BRAIN::stateAt($exemple, 23 * 60), 0);
$resume = BRAIN::summary($exemple);
verifie('un allumage',      $resume['switches'], 1);
verifie('quatre heures',    $resume['minutes'], 240);

echo "\nNombre maximum de lampes allumées\n";
$voulu = array('1' => 1, '2' => 1, '3' => 1, '4' => 0);
$rien = array('lamps' => array());
$limite = BRAIN::capSimultaneous($voulu, $rien, 2);
verifie('deux lampes au plus', array_sum($limite), 2);
verifie('zéro ne limite rien',  array_sum(BRAIN::capSimultaneous($voulu, $rien, 0)), 3);

/* Une lampe déjà allumée garde la main : sans cela, la maison ferait défiler
 * ses pièces d'une minute à l'autre. */
$enCours = array('lamps' => array('3' => array('ordered' => 1, 'ordered_at' => 0, 'manual_until' => 0)));
$garde = BRAIN::capSimultaneous($voulu, $enCours, 1);
verifie('celle qui brûle déjà reste allumée', $garde['3'], 1);
verifie('les autres attendent',                $garde['1'], 0);

echo "\nConditions de départ\n";
verifieVrai('1 égale 1',                    BRAIN::compare('1', '==', '1'));
verifieVrai('1 égale 1.0 en nombre',        BRAIN::compare('1', '==', '1.0'));
verifieVrai('2 est supérieur à 1',          BRAIN::compare(2, '>', 1));
verifieVrai('« Armé » égale « armé »',      BRAIN::compare('Armé', '==', 'armé'));
verifieVrai('« Présent » diffère de « Absent »', BRAIN::compare('Présent', '!=', 'Absent'));
verifieVrai('10 est supérieur à 9 en nombre', BRAIN::compare('10', '>', '9'));
verifieVrai('une valeur vide n\'est pas 1',  !BRAIN::compare('', '==', '1'));

/* ---- 8 ---------------------------------------------------------------------
 * Le tirage reproductible, qui tient tout l'édifice : un plan qui changerait
 * en cours de soirée se verrait de la rue.
 */
echo "\nTirage\n";
$etat1 = BRAIN::seed('lampe|2026-09-18');
$etat2 = BRAIN::seed('lampe|2026-09-18');
verifie('même graine, même départ', $etat1, $etat2);
$suite1 = array(); $suite2 = array();
for ($i = 0; $i < 10; $i++) { $suite1[] = BRAIN::next($etat1); $suite2[] = BRAIN::next($etat2); }
verifie('et même suite', $suite1, $suite2);
$dansLesBornes = true;
foreach ($suite1 as $valeur) { if ($valeur < 0 || $valeur >= 1) { $dansLesBornes = false; } }
verifieVrai('les tirages restent dans [0, 1[', $dansLesBornes);

$etat = BRAIN::seed('bornes');
$hors = false;
for ($i = 0; $i < 2000; $i++) {
    $entier = BRAIN::nextInt($etat, 5, 9);
    if ($entier < 5 || $entier > 9) { $hors = true; }
}
verifieVrai('un entier tiré reste entre ses bornes', !$hors);

/* Une suite partie de zéro serait pauvre : la graine est forcée impaire. */
verifieVrai('la graine n\'est jamais nulle', BRAIN::seed('') != 0);

/* ---- 9 ---------------------------------------------------------------------
 * Les défauts trouvés en revue. Chacun de ces contrôles correspond à un
 * comportement qui a réellement été produit par une version précédente, et
 * qu'aucune relecture n'avait montré.
 */
echo "\nÉclairs à la fermeture\n";
/* Une lampe dont l'habitude déborde la fenêtre produisait un allumage tronqué
 * à la fermeture, parfois de durée nulle : joué à la minute, cela revient à
 * faire clignoter la façade une fois par soir. */
$eclair = BRAIN::applyWindow(array(array('t' => 1439, 'v' => 1)),
    array('window_start' => 0, 'window_end' => 1439, 'min_on' => 60));
verifie('un allumage sans durée est abandonné', count($eclair), 0);

$trop = BRAIN::applyWindow(array(array('t' => 1400, 'v' => 1), array('t' => 1430, 'v' => 0)),
    array('window_start' => 0, 'window_end' => 1410, 'min_on' => 60));
verifie('un allumage trop court après troncature aussi', count($trop), 0);

$assez = BRAIN::applyWindow(array(array('t' => 1200, 'v' => 1), array('t' => 1430, 'v' => 0)),
    array('window_start' => 0, 'window_end' => 1410, 'min_on' => 60));
verifie('un allumage qui tient la durée est gardé', count($assez), 2);

echo "\nLampe allumée en permanence\n";
/* Elle n'a jamais été vue s'allumer : partir d'éteint la condamnait à ne rien
 * faire, et la maison perdait justement la lampe qui reste allumée. */
$toujours = array();
for ($i = 1; $i <= 56; $i++) {
    $toujours[date('Y-m-d', strtotime('2026-01-01 +' . $i . ' day'))] = array(array('t' => 0, 'v' => 1));
}
$veilleuse = BRAIN::bucketFor(BRAIN::build($toujours), 1, 3);
verifie('observée allumée toute la journée', round(BRAIN::occupancy($veilleuse, 40), 3), 1.0);
$plan = BRAIN::generate($veilleuse, array('window_start' => 420, 'window_end' => 1410), 'veilleuse|1');
verifie('elle est allumée dans le plan',      count($plan), 2);
verifie('dès l\'ouverture de la fenêtre',    $plan[0]['t'], 420);
verifie('jusqu\'à sa fermeture',              $plan[1]['t'], 1410);

echo "\nDurées d'allumage tenues à la minute\n";
$profilSoir = array();
for ($i = 1; $i <= 56; $i++) {
    $profilSoir[date('Y-m-d', strtotime('2026-01-01 +' . $i . ' day'))] = journee(19 * 60, 23 * 60);
}
$soir = BRAIN::bucketFor(BRAIN::build($profilSoir), 1, 3);

/*
 * Le profil d'essai est volontairement irrégulier : une longue soirée un jour
 * sur deux. Sur un profil saturé — la lampe allumée à cette heure-là tous les
 * jours sans exception — le plafond ne s'applique pas, et c'est voulu : le
 * faire tomber inventerait une extinction que personne n'a jamais faite, et
 * une veilleuse de couloir clignoterait une fois par nuit. Le contrôle suivant
 * vérifie cette exemption.
 */
$profilLong = array();
for ($i = 1; $i <= 56; $i++) {
    $date = date('Y-m-d', strtotime('2026-01-01 +' . $i . ' day'));
    $profilLong[$date] = ($i % 2 == 0) ? journee(12 * 60, 23 * 60) : array(array('t' => 0, 'v' => 0));
}
$long = BRAIN::bucketFor(BRAIN::build($profilLong), 1, 3);
verifieVrai('le profil d\'essai n\'est pas saturé', BRAIN::occupancy($long, 60) < 0.9);

$violeMin = 0;
$violeMax = 0;
for ($essai = 0; $essai < 500; $essai++) {
    $tirage = BRAIN::generate($long, array('window_start' => 0, 'window_end' => 1439,
        'min_on' => 60, 'max_on' => 90), 'duree|' . $essai);
    $debut = null;
    foreach ($tirage as $evenement) {
        if ($evenement['v'] == 1) { $debut = $evenement['t']; continue; }
        if ($debut === null) { continue; }
        $duree = $evenement['t'] - $debut;
        if ($duree < 60) { $violeMin++; }
        if ($duree > 90) { $violeMax++; }
        $debut = null;
    }
}
verifie('aucun allumage sous la durée minimale', $violeMin, 0);
verifie('aucun allumage au-delà du plafond',     $violeMax, 0);

/* L'exemption elle-même : une lampe allumée tous les jours à la même heure
 * n'est pas coupée au bout du plafond. */
$sature = BRAIN::generate($veilleuse, array('window_start' => 0, 'window_end' => 1439,
    'min_on' => 60, 'max_on' => 90), 'sature|1');
verifieVrai('un profil saturé échappe au plafond',
    count($sature) == 2 && ($sature[1]['t'] - $sature[0]['t']) > 90);

echo "\nBiais du quart d'heure\n";
/* Tirer une minute au hasard dans la tranche décalait systématiquement une
 * lampe de 19 h 00 à 19 h 07 en moyenne. L'heure observée est maintenant
 * gardée, et c'est autour d'elle que le tirage se fait. */
$somme = 0;
$compte = 0;
for ($essai = 0; $essai < 500; $essai++) {
    $tirage = BRAIN::generate($soir, array('window_start' => 0, 'window_end' => 1439), 'biais|' . $essai);
    if (count($tirage) > 0) { $somme += $tirage[0]['t']; $compte++; }
}
$moyenne = $somme / max(1, $compte);
verifieVrai('une lampe de 19 h 00 ressort autour de 19 h 00', abs($moyenne - 19 * 60) <= 3);

echo "\nVariabilité\n";
/* Elle décalait les probabilités et non les heures : les taux appris valant
 * souvent 1, la seule chose qu'elle produisait était une soirée annulée sur
 * quatre — l'inverse de ce que le réglage promet. */
verifie('à 0, aucun décalage',           BRAIN::dayShift(0, 30, 'jour|1'), 0);
$amplitudes = array();
for ($essai = 0; $essai < 500; $essai++) {
    $amplitudes[] = BRAIN::dayShift(100, 30, 'jour|' . $essai);
}
verifieVrai('à 100, le décalage reste dans son amplitude',
    min($amplitudes) >= -30 && max($amplitudes) <= 30);
verifieVrai('et il est réellement tiré',  max($amplitudes) - min($amplitudes) > 30);
verifie('le même jour donne le même décalage',
    BRAIN::dayShift(60, 30, 'jour|1'), BRAIN::dayShift(60, 30, 'jour|1'));

$noires = 0;
for ($essai = 0; $essai < 500; $essai++) {
    $decalage = BRAIN::dayShift(100, 30, 'g|' . $essai) + BRAIN::dayShift(100, 15, 'g|1|' . $essai);
    $tirage = BRAIN::generate($soir, array('window_start' => 0, 'window_end' => 1439), 'g|1|' . $essai, $decalage);
    if (count($tirage) == 0) { $noires++; }
}
verifie('à variabilité maximale, aucune soirée annulée', $noires, 0);

$decale = BRAIN::shiftEvents(array(array('t' => 10, 'v' => 1), array('t' => 1435, 'v' => 0)), 20);
verifie('un décalage ne sort pas de la journée', $decale[1]['t'], 1439);
verifie('et il s\'applique bien',                $decale[0]['t'], 30);

echo "\nCoucher saisi après minuit\n";
$minuit = BRAIN::invent(array('evening_chance' => 100, 'bedtime' => 30, 'spread' => 0,
    'window_start' => 0, 'window_end' => 1439), array('sunrise' => 420, 'sunset' => 1200), 'minuit|1');
verifie('la soirée existe',                       count($minuit), 2);
verifieVrai('et elle dure jusqu\'à la fenêtre', ($minuit[1]['t'] - $minuit[0]['t']) > 120);

echo "\nBornes oubliées\n";
$bornes = BRAIN::cleanOptions(array('sunset_offset' => 100000, 'bedtime' => -5000, 'wake' => 99999));
verifie('décalage du coucher borné', $bornes['sunset_offset'], 720);
verifie('heure de coucher bornée',   $bornes['bedtime'], 0);
verifie('heure de lever bornée',     $bornes['wake'], 1439);

$large = BRAIN::invent(array('evening_chance' => 100, 'spread' => 180, 'bedtime' => 1320,
    'window_start' => 420, 'window_end' => 1410), array('sunrise' => 300, 'sunset' => 1320), 'large|1');
verifieVrai('un flottement large ne perd pas la soirée', count($large) == 2);

echo "\nPoints hors journée\n";
verifie('une minute négative est écartée', count(BRAIN::cleanPoints(array(array('t' => -99999, 'v' => 1)))), 0);
verifie('1440 aussi',                        count(BRAIN::cleanPoints(array(array('t' => 1440, 'v' => 1)))), 0);
verifie('1439 est gardée',                   count(BRAIN::cleanPoints(array(array('t' => 1439, 'v' => 1)))), 1);

echo "\nReconnaissance des lampes\n";
function cmdEssai($_id, $_nom, $_type, $_generic) {
    return array('id' => $_id, 'name' => $_nom, 'type' => $_type, 'subType' => 'other', 'generic' => $_generic);
}
$LAMPS = 'simulationpresenceintelligentbeLamps';
/* Une lampe qu'on ne peut pas éteindre brûlerait jusqu'au matin : c'est
 * exactement la signature qu'une simulation de présence doit masquer. */
verifie('un équipement sans extinction est écarté',
    $LAMPS::classify('Lampe', array(cmdEssai(1, 'On', 'action', 'LIGHT_ON'))), null);
verifieVrai('avec allumage et extinction, il est retenu',
    is_array($LAMPS::classify('Lampe', array(cmdEssai(1, 'On', 'action', 'LIGHT_ON'), cmdEssai(2, 'Off', 'action', 'LIGHT_OFF')))));
verifieVrai('une bascule seule suffit',
    is_array($LAMPS::classify('Lampe', array(cmdEssai(1, 'Toggle', 'action', 'LIGHT_TOGGLE')))));
verifie('une commande sans identifiant ne casse rien',
    $LAMPS::classify('Lampe', array(array('name' => 'On', 'type' => 'action', 'subType' => 'other', 'generic' => 'LIGHT_ON'))), null);
verifieVrai('« Volet lampe » n\'est pas une lampe', !$LAMPS::looksLikeLight('Volet lampe'));
verifieVrai('« Store lumière salon » non plus',      !$LAMPS::looksLikeLight('Store lumière salon'));
verifieVrai('« Plafonnier salon » en est une',        $LAMPS::looksLikeLight('Plafonnier salon'));

echo "\n";
if ($ko == 0) {
    echo "Tous les contrôles passent ($ok).\n";
    exit(0);
}
echo "$ok contrôle(s) passé(s), $ko en échec.\n";
exit(1);
