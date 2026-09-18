<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * L'icône est dessinée ici plutôt que déposée en binaire opaque : une maison,
 * deux fenêtres allumées et une éteinte, qu'on peut relire et refaire. Le
 * dessin est fait en 1024 puis réduit en 256, ce qui donne les bords lissés que
 * GD ne produit pas sur un remplissage direct.
 *
 * Une fenêtre éteinte au milieu de deux allumées, c'est tout le sujet du
 * plugin : une maison qui ne s'éclaire pas d'un bloc, mais pièce par pièce.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$mur      = imagecolorallocate($grand, 0x3E, 0x4C, 0x59);
$toit     = imagecolorallocate($grand, 0x2B, 0x35, 0x3F);
$jaune    = imagecolorallocate($grand, 0xF5, 0xB3, 0x00);
$jauneVif = imagecolorallocate($grand, 0xFF, 0xD1, 0x4A);
$eteinte  = imagecolorallocate($grand, 0x1E, 0x25, 0x2B);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

/* Le toit, posé avant les murs : le débord se lit mieux par-dessus. */
imagefilledpolygon($grand, array(
    $e(128), $e(24),
    $e(238), $e(116),
    $e(18),  $e(116),
), $toit);

/* Les murs. */
imagefilledrectangle($grand, $e(44), $e(112), $e(212), $e(232), $mur);

/* Les fenêtres de l'étage : une allumée, une éteinte. */
imagefilledrectangle($grand, $e(66),  $e(134), $e(114), $e(178), $jaune);
imagefilledrectangle($grand, $e(142), $e(134), $e(190), $e(178), $eteinte);

/* La croisée de la fenêtre allumée : sans elle, le carré jaune se lit comme un
 * panneau et non comme une fenêtre. */
imagesetthickness($grand, $e(5));
imageline($grand, $e(90), $e(134), $e(90), $e(178), $toit);
imageline($grand, $e(66), $e(156), $e(114), $e(156), $toit);

/* La porte, éclairée elle aussi : la maison paraît occupée. */
imagefilledrectangle($grand, $e(104), $e(190), $e(152), $e(232), $jaune);
imagefilledellipse($grand, $e(144), $e(212), $e(9), $e(9), $toit);

/* Pas de rayons sortant de la fenêtre : à 32 pixels, taille à laquelle Jeedom
 * affiche l'icône dans son menu, ils se réduisent à deux salissures jaunes à
 * côté de la maison, et on ne lit plus ni l'un ni l'autre. */

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/simulationpresenceintelligentbe_icon.png';
imagepng($icone, $cible);
echo "Écrit : $cible\n";
