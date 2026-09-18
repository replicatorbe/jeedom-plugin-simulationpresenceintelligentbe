# Simulation de présence intelligente

Faire croire que la maison est habitée, en rejouant ce qu'elle fait d'habitude.

Le plugin ne pilote pas de matériel : il commande les lampes et les prises que
vos autres plugins ont créées — Zigbee, Z-Wave, Hue, MQTT, prises Wi-Fi, modules
anciens. Il lit leur historique, en tire les heures auxquelles chacune s'allume
et s'éteint selon le jour de semaine, et rejoue cette journée avec ce qu'il faut
de hasard pour qu'elle ne se répète jamais.

Il n'y a ni démon à surveiller, ni dépendance à installer : tout est en PHP,
dans le cron du cœur.

## Avant de commencer

Renseignez la position de votre installation dans **Réglages → Système →
Configuration → Général**. Sans latitude ni longitude, les soirées inventées se
calent sur un coucher de soleil fixe à 19 h toute l'année : en juin, les lampes
s'allument en plein jour, et cela se remarque de la rue mieux que n'importe quoi
d'autre.

C'est la même position que celle dont Jeedom se sert pour `#sunset#` dans les
scénarios. La page de configuration du plugin l'affiche, avec le lever et le
coucher du jour, pour qu'une position fausse se voie tout de suite.

Tant qu'elle manque, le plugin pose un message dans le centre de messages de
Jeedom. Il disparaît de lui-même dès que les coordonnées sont renseignées, sans
qu'il y ait rien à acquitter : un avertissement qu'on ne peut pas faire
disparaître en corrigeant ce qu'il reproche est un compteur rouge à vie.

## Créer un groupe

Un **groupe** rassemble les lampes d'une même façade ou d'un même étage, avec la
condition qui lance la simulation et la fenêtre horaire qui la borne.

1. **Plugins → Sécurité → Simulation de présence intelligente → Ajouter un
   groupe.** Donnez-lui le nom de ce qu'il fait croire : « Façade », « Étage »,
   ou simplement « Maison ».
2. **Onglet Lampes.** Choisissez les lampes et les prises dans le sélecteur.
3. **Onglet Apprentissage.** Cliquez sur **Historiser les lampes**. C'est la
   première chose à faire, et la seule qui soit urgente.
4. **Onglet Départ.** Dites au plugin quand partir tout seul, ou laissez la
   condition décochée et pilotez-le avec **Démarrer**.
5. **Sauvegarder.**

## Historiser les lampes, d'abord

Le plugin apprend en relisant **l'historique Jeedom** de la commande d'état de
chaque lampe. Sans historique, il n'y a rien à rejouer — et l'historique de
Jeedom ne commence qu'au moment où on coche la case. Le bouton **Historiser les
lampes**, dans l'onglet *Apprentissage*, active l'historisation sur l'état de
toutes les lampes choisies, en une fois.

Il faut donc quelques jours avant que le plugin rejoue vos habitudes au lieu
d'en inventer : trois journées observées par défaut. D'ici là, il ne reste pas
muet, il invente — mais ce qu'il joue le premier soir ne doit rien à votre
maison.

Le tableau **Ce que le plugin a appris** donne, lampe par lampe, le nombre de
journées observées, la durée moyenne d'allumage et ce qu'il fera ce soir :
rejouer ou inventer. C'est la seule page qui explique pourquoi deux lampes du
même groupe ne sont pas traitées de la même façon.

Jeedom n'écrit une ligne d'historique qu'au changement de valeur : une lampe de
chambre d'amis allumée six soirs sur vingt-huit n'a d'historique que ces six
jours-là. Les vingt-deux autres comptent tout autant, et le plugin les compte —
sans quoi il ne moyennerait que sur les jours actifs, et allumerait cette lampe
presque tous les soirs en annonçant la rejouer fidèlement.

Une lampe qui ne publie pas son état ne pourra jamais être apprise, seulement
inventée. La page **Santé** les compte.

## Le sélecteur de lampes

Le sélecteur parcourt l'installation et ne montre que ce qui peut s'allumer et
s'éteindre, rangé par pièce : d'abord ce qui porte les types génériques
*Lumière* du cœur, puis les prises commandées, puis les équipements reconnus à
leurs commandes « On » et « Off » — beaucoup de protocoles anciens ne
remplissent pas les types génériques, et sans ce filet leurs lampes seraient
introuvables.

Vous cochez des **équipements**, pas des commandes : le plugin retient lui-même
laquelle allume, laquelle éteint et laquelle publie l'état. Chaque ligne permet
d'allumer et d'éteindre la lampe pour de vrai, ce qui reste la façon la plus
rapide de savoir laquelle des trois s'appelle « Module 3 ».

Un équipement n'est proposé que s'il y a de quoi l'allumer **et** de quoi
l'éteindre ; une bascule compte pour les deux. Une lampe qu'on allume sans
pouvoir l'éteindre brûle jusqu'au matin, c'est-à-dire exactement la signature
qu'une simulation de présence cherche à masquer. Ces équipements restent
atteignables par **Tous les équipements**, où vous désignez vous-même la
commande qui allume et celle qui éteint ; si l'une des deux manque encore à la
validation, le plugin vous dit lesquels il a écartés plutôt que de les laisser
disparaître sans un mot.

Ce qui est coché est piloté **pendant la simulation, et uniquement pendant la
simulation** : hors simulation, le plugin ne touche à rien.

## Ce que le plugin apprend

Le modèle tient en quatre idées, et c'est lui qui fait toute la différence entre
une simulation crédible et un minuteur.

**La journée est découpée en tranches d'un quart d'heure.** Personne n'allume le
salon à 19 h 07 toutes les semaines, mais beaucoup de gens l'allument « vers
sept heures et quart ». Plus fin n'apprendrait rien de plus, plus large perdrait
la différence entre le dîner et le coucher.

La tranche décide **si** la lampe s'allume ; l'heure, elle, est apprise à
l'intérieur de la tranche. Le plugin garde où, dans le quart d'heure, le
changement est tombé, et rejoue autour de là, à quelques minutes près. Une lampe
allumée à 19 h 00 tous les soirs ressort donc autour de 19 h 00, et non à
19 h 07 comme le ferait un tirage au hasard dans la tranche — un décalage petit,
mais systématique, et qui se voit sur une maison qu'on observe plusieurs soirs.

**On n'apprend pas la probabilité d'être allumé, mais celles de s'allumer et de
s'éteindre.** C'est la différence entre un plan crédible et un clignotement :
rejouer une probabilité de présence tranche par tranche, indépendamment,
donnerait une lampe qui s'allume et s'éteint dix fois dans la soirée. En
raisonnant sur les changements, le plan hérite naturellement des durées
observées.

**Avec peu de journées, la fréquence observée est corrigée.** Sur trois
semaines, une tranche ne contient que trois échantillons : une fréquence brute
y vaut 0 ou 1, jamais autre chose. Chaque fréquence est donc mélangée à une
estimation tirée de la forme générale de la journée, d'autant plus fort que
les observations sont rares. C'est ce qui rend le plugin utile dès la première
semaine sans lui faire caricaturer le peu qu'il a vu.

**Un profil par jour de semaine, avec repli sur l'ensemble.** Un mardi ressemble
à un mercredi bien plus qu'à un samedi, mais il faut plusieurs semaines pour le
savoir. Tant que le jour de semaine n'a pas été assez observé, le plan est tiré
de toutes les journées confondues.

Les profils sont refaits chaque nuit, une fois par lampe : c'est la seule
opération coûteuse du plugin, et elle ne tombe jamais pendant la soirée.

Une lampe allumée en permanence — une veilleuse, un couloir — est rejouée elle
aussi : vue allumée à minuit tous les jours, jamais vue s'allumer, elle est
allumée à l'ouverture de la fenêtre horaire et éteinte à sa fermeture, plutôt
que laissée éteinte toute la soirée. La maison perdrait sinon justement la lampe
qui ne s'éteint jamais.

**Réglages, onglet *Apprentissage*.**

- **Remonter sur** — la profondeur d'historique relue, 28 jours par défaut.
  Quatre semaines : assez pour que chaque jour de semaine ait été vu quatre
  fois, pas assez pour qu'un changement de saison passe inaperçu.
- **Rejouer dès** — le nombre de journées observées en dessous duquel le plugin
  invente plutôt que de rejouer. Trois par défaut.
- **Variabilité** — de combien la journée entière se décale, 30 % par défaut. Le
  plugin tire un décalage pour tout le groupe — une demi-heure au plus — et un
  second par lampe par-dessus — un quart d'heure au plus — tous deux
  proportionnels au réglage. À 0, toutes les soirées se ressemblent ; à 100,
  elles bougent de trois quarts d'heure dans un sens ou dans l'autre. Aucune
  soirée n'est annulée, quel que soit le réglage : la variabilité décale, elle
  ne supprime pas.

**Les lampes d'un même groupe se décalent ensemble.** C'est ce que fait la part
de groupe du tirage : un soir où l'on rentre tard, c'est toute la maison qui
s'allume tard, et non six lampes qui décident chacune dans leur coin. C'est la
seule corrélation entre lampes que le modèle produise, et c'est celle qui manque
le plus à qui regarde une façade plusieurs soirs de suite — l'ordre dans lequel
les pièces s'éclairent doit avoir une cause visible. La part propre à chaque
lampe, plus petite, empêche que le groupe entier bouge d'un seul bloc.

## Quand il n'a pas encore appris

Une lampe sans passé reçoit une **soirée inventée**, calée sur le coucher du
soleil — la seule chose qu'on sache vraiment de la soirée d'une maison qu'on ne
connaît pas.

- **Une soirée sur** — la probabilité qu'une lampe donnée participe à la soirée,
  60 % par défaut. Moins de 100 % évite que six lampes s'allument tous les soirs
  à la même minute, ce qui est le défaut qui trahit une simulation.
- **Allumer au coucher du soleil** — tant de minutes avant ou après, −15 par
  défaut.
- **Éteindre vers** — l'heure du coucher, 23:00 par défaut. Une heure saisie
  après minuit — « 00:30 » — fait courir la soirée jusqu'à la fermeture de la
  fenêtre horaire, ce qui est bien ce qu'on voulait dire.
- **Flottement** — l'écart aléatoire appliqué de part et d'autre de chaque
  heure, 25 minutes par défaut.
- **Un matin sur** et **Lever vers** — le pendant du matin, désactivé par
  défaut : une maison vide dont les lampes s'allument tous les matins à six
  heures est aussi peu crédible qu'une maison éteinte tous les soirs.

## Un plan qui ne change pas en cours de soirée

Le plan du jour est tiré au sort, mais le tirage est **reproductible** : la
graine est faite de l'identifiant du groupe, de celui de la lampe, de la date et
d'un sel rangé dans la configuration du groupe. Une box redémarrée à 21 h
reprend donc la même soirée là où elle en était, elle n'en recommence pas une
autre par-dessus. Le lendemain, le plan est différent ; le même soir, il est
différent d'une lampe à l'autre.

À chaque minute, le plugin ne rejoue pas les changements manqués un par un : il
lit dans le plan l'état attendu à cette minute et l'impose. Une box arrêtée deux
heures reprend la soirée dans l'état où elle devrait être, sans rafale
d'allumages pour rattraper le retard.

Les **jours où le plugin a lui-même piloté les lampes sont écartés de
l'apprentissage**. Sans cela, il apprendrait ses propres inventions et
dériverait un peu plus chaque semaine. Chaque journée de simulation est marquée
le jour même, et pas seulement au démarrage : une simulation de vacances
démarre une fois pour quinze jours, et les quatorze autres journées doivent
être écartées elles aussi. La trace en est gardée quatre-vingt-dix jours, et
plus longtemps si vous demandez à remonter plus loin — sinon, passé trois mois,
le plugin réapprendrait les journées qu'il a lui-même inventées.

Le bouton **Tirer un autre plan**, dans l'onglet *Apprentissage*, fait avancer
ce sel : le plan du jour est oublié, et celui qui le remplace est un autre
tirage, et non le même reconstruit à l'identique. Appuyez autant de fois qu'il
faut pour tomber sur une soirée qui vous convienne ; entre deux appuis, le plan
ne bouge plus, y compris après un redémarrage de la box.

## Le départ : la condition

Onglet *Départ*. La condition décrit l'état dans lequel doit se trouver la
maison pour que la simulation démarre toute seule : l'alarme est armée, le mode
« Absence » est actif, plus personne n'est détecté.

C'est une **liste** de commandes d'information, chacune avec un test
(`==`, `!=`, `>`, `>=`, `<`, `<=`) et une valeur attendue, et non une condition
unique : « l'alarme est armée » et « il n'y a personne » sont deux informations
distinctes dans presque toutes les installations, et les exiger ensemble est
justement ce qui évite d'allumer les lampes pendant que quelqu'un dort à
l'étage. Le champ **Il faut** décide si toutes les lignes doivent être vraies
(ET) ou une seule (OU). Le bouton **Évaluer maintenant** montre la valeur
actuelle de chaque commande et le résultat.

**Confirmer pendant** — le délai que la condition doit tenir avant le départ,
2 minutes par défaut. Il évite qu'un capteur qui hésite une minute lance toute
une soirée.

**Puis arrêter après** — le délai de retombée, une fois la condition redevenue
fausse. Zéro pour arrêter dans la minute.

Une condition dont la commande a été supprimée est tenue pour fausse : mieux
vaut une simulation qui ne part pas qu'une simulation qui part pendant que la
maison est occupée.

**Démarrer** et **Arrêter** l'emportent sur la condition, et tant que
**Revenir à la condition** n'a pas été joué : quelqu'un qui arrête la simulation
depuis son téléphone ne veut pas la voir repartir la minute suivante parce que
l'alarme est toujours armée.

## La fenêtre horaire et les garde-fous

**Rien avant** / **Rien après** — la fenêtre autorisée, 07:00 à 23:30 par
défaut. Elle ne décale rien : un allumage prévu hors fenêtre est
**abandonné**, jamais repoussé, parce que tout repousser à la minute
d'ouverture se remarquerait de la rue bien plus qu'une lampe qui ne s'allume
pas. En revanche, une lampe encore allumée à l'heure de fermeture est éteinte
— c'est la promesse du réglage.

**Les durées d'allumage sont tenues à la minute.** Un allumage dure au moins
douze minutes et au plus sept heures. Celui qui ne tiendrait pas ses douze
minutes parce que la fenêtre se ferme est abandonné plutôt que joué en éclair :
une façade qui s'allume trois minutes se remarque bien plus qu'une façade qui
reste noire. Le plafond, lui, ne s'applique pas à une lampe observée allumée à
cette heure-là presque tous les jours : la couper au bout de sept heures
inventerait une extinction que personne n'a jamais faite, et une veilleuse de
couloir clignoterait une fois par nuit.

**Lampes allumées au plus** — le nombre de lampes allumées en même temps, 3 par
défaut, 0 pour ne pas limiter. Les lampes déjà allumées gardent la main :
éteindre celle qui brûle depuis une heure pour allumer la suivante produirait un
défilé de pièces qu'aucune maison ne fait.

**Remettre en l'état à l'arrêt** — chaque lampe retrouve l'état qu'elle avait au
démarrage, relevé avant le premier ordre. Sans cela, rentrer à minuit veut dire
éteindre trois lampes qu'on n'a pas allumées, tous les soirs de vacances. Le
relevé porte la lampe entière — son nom et ses commandes, pas seulement son
état — ce qui permet de remettre en place même une lampe retirée du groupe
depuis le démarrage : sinon elle resterait allumée toute la nuit, et vous
chercheriez longtemps pourquoi.

**Après un geste manuel** — si quelqu'un allume ou éteint une lampe à la main
pendant la simulation, le plugin la laisse tranquille ce nombre de minutes,
60 par défaut. Le geste est repéré à l'écart entre ce que le plugin a ordonné et
ce que la lampe publie, passé le délai de réponse réglé dans la configuration du
plugin. Une lampe touchée à la main n'est pas non plus remise en l'état à
l'arrêt : elle appartient à celui qui l'a allumée.

Un champ numérique laissé vide reprend la valeur par défaut, celle que le champ
affiche en gris, et non zéro : « je ne sais pas quoi mettre » n'est pas « pas de
garde-fou ».

## Quand une lampe ne répond pas

Un ordre qui échoue — l'équipement a été désactivé, la commande a disparu, le
module ne répond plus — laisse une ligne dans le journal **et** un message dans
le centre de messages de Jeedom. Une simulation de présence qui se dégrade sans
le dire est exactement ce qu'un plugin de sécurité ne doit pas faire, et le
journal, lui, ne se regarde pas tout seul.

La lampe est ensuite laissée de côté un quart d'heure avant d'être réessayée.
Sans ce délai, un équipement désactivé produirait une erreur et une nouvelle
tentative chaque minute, toute la soirée durant. Les messages d'un groupe sont
effacés à son démarrage suivant : ils décrivent la soirée en cours, pas les
précédentes.

Une lampe **supprimée** de l'installation est signalée dans le tableau des
lampes et dans celui de l'onglet *Apprentissage*. C'est la seule panne que le
plugin ne puisse pas contourner : rouvrez le sélecteur et choisissez la lampe à
nouveau.

## Les commandes créées

| Commande | Type | Rôle |
|---|---|---|
| **Simulation** (`state`) | info binaire | 1 quand la simulation tourne. Historisée, et liée aux deux boutons : la tuile bascule. |
| **Démarrer** (`on`) | action | Démarre tout de suite et l'emporte sur la condition. |
| **Arrêter** (`off`) | action | Arrête, remet les lampes en l'état, et l'emporte sur la condition. |
| **Revenir à la condition** (`auto`) | action | Retire la marque manuelle : la condition reprend la main. Créée masquée. |
| **Source du plan** (`mode`) | info | « historique », « inventé », ou « 2 apprise(s), 3 inventée(s) ». |
| **Prochain changement** (`next`) | info | « 20:42 allumer Salon ». Le seul moyen de vérifier d'un coup d'œil que le plugin a l'intention de faire quelque chose ce soir. |
| **Lampes allumées** (`lit`) | info numérique | Le nombre de lampes que la simulation tient allumées. Historisée. |
| **Tirer un autre plan** (`replan`) | action | Un autre tirage pour la journée, et non le même plan reconstruit. Créée masquée. |

Les deux commandes masquées sont à réafficher si vous en avez l'usage : elles
sont surtout faites pour être jouées depuis un scénario.

## L'aperçu d'une journée

Onglet *Apprentissage*, **Aperçu d'une journée** : aujourd'hui, demain ou
après-demain, lampe par lampe, avec les heures et la source du plan. Il est
calculé par le serveur, avec le code qui jouera réellement le plan — deux
implémentations, une pour l'aperçu et une pour l'exécution, divergeraient au
premier réglage ajouté, et l'aperçu mentirait sans qu'on le sache.

Regarder demain ne change pas ce soir : l'aperçu d'une autre journée ne touche
pas au plan du jour.

## La configuration du plugin

**Plugins → Gestion des plugins → Simulation de présence intelligente →
Configuration.**

- **Délai de réponse d'une lampe** — les secondes laissées à une lampe pour
  confirmer un ordre, 120 par défaut. Passé ce délai, un écart entre l'état
  ordonné et l'état publié est pris pour un geste humain. À augmenter si vos
  modules mettent du temps à publier leur état.
- **Écrire le plan du jour** — chaque plan tiré est écrit dans le journal, lampe
  par lampe, avec ses heures. Bavard, mais c'est la seule façon de comprendre
  après coup pourquoi une soirée s'est déroulée comme elle s'est déroulée.
- **Position** — le rappel de la position de l'installation, avec le lever et le
  coucher du jour.

## Questions fréquentes

**Le plugin n'a rien allumé le premier soir.** C'est normal et voulu : une lampe
sans passé ne participe qu'à six soirées sur dix par défaut, et le tirage peut
l'avoir écartée. L'aperçu du jour le dit ligne par ligne. Vérifiez aussi que la
simulation tourne — la commande **Simulation** vaut 1 — et que l'heure prévue
tombe dans la fenêtre horaire.

**Combien de temps avant qu'il rejoue vraiment mes habitudes ?** Trois journées
observées par lampe, par défaut, et l'historique ne commence qu'au moment où on
clique sur **Historiser les lampes**. Comptez donc une petite semaine, et une
vraie quinzaine pour que le samedi se distingue du mardi.

**Le plugin apprend-il ses propres soirées ?** Non. Les journées pendant
lesquelles il a piloté les lampes sont écartées de l'apprentissage ; il ne
réapprend jamais ce qu'il a inventé.

**J'ai éteint une lampe à la main pendant la simulation.** Le plugin s'en rend
compte et la laisse tranquille le temps réglé dans **Après un geste manuel**.
Elle n'est pas non plus remise en l'état à l'arrêt.

**Certains soirs, toutes les lampes s'allument plus tard.** C'est voulu, et
c'est le décalage de groupe de la **Variabilité** : on ne rentre pas à la même
heure tous les soirs, et quand on rentre tard, toute la maison s'allume tard.
Baissez la variabilité pour resserrer les soirées, montez-la pour les écarter ;
aucune n'est annulée pour autant.

**Ma lampe n'apparaît pas dans le sélecteur.** Il lui manque probablement de
quoi l'éteindre : le plugin n'en propose aucune qu'il ne saurait pas éteindre.
Ouvrez **Tous les équipements** et désignez vous-même la commande qui allume et
celle qui éteint.

**La simulation repart-elle toute seule après un redémarrage ?** Oui. L'état
« en cours » et le relevé complet des lampes sont enregistrés en base, pas en
cache ; le plan, lui, se reconstruit à l'identique grâce à sa graine et à son
sel.

**Puis-je mettre la même lampe dans deux groupes ?** Oui, mais les deux groupes
lui enverront leurs ordres et se contrediront : le dernier arrivé gagne.

**Faut-il un démon, une dépendance, un compte quelque part ?** Non. Tout tourne
dans le cron du cœur, et rien ne sort de la box.

**Où est le journal ?** Analyse → Logs → `simulationpresenceintelligentbe`. Les
démarrages, les arrêts, les gestes manuels et les ordres en échec y laissent une
ligne ; le plan complet y va aussi si vous cochez **Écrire le plan du jour**.
Les ordres en échec sont en outre publiés dans le centre de messages, pour
qu'une panne se voie sans aller la chercher.
