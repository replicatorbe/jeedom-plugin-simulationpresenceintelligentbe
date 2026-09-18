# Changelog

## 1.0

Première version.

- Groupes de simulation : un groupe rassemble des lampes et des prises, une
  condition de départ et une fenêtre horaire.
- Apprentissage sur l'historique Jeedom de l'état de chaque lampe : le plugin
  en tire les heures auxquelles elle s'allume et s'éteint, par jour de semaine,
  et rejoue cette journée avec ce qu'il faut de hasard. L'heure est apprise à
  l'intérieur du quart d'heure : une lampe allumée à 19 h 00 tous les soirs
  ressort autour de 19 h 00.
- Les habitudes apprises suivent le soleil : le plan est décalé de l'écart
  entre le coucher moyen des journées apprises et celui du jour rejoué, les
  allumages d'avant midi sur le lever et ceux d'après midi sur le coucher, la
  durée des allumages conservée et le décalage borné à quatre heures. Sans
  cela, une soirée apprise à la mi-septembre, où le soleil se couche à 19 h 51,
  allumerait la façade trois heures après la tombée de la nuit au solstice, où
  il se couche à 16 h 41.
- Les journées sans le moindre changement comptent dans l'apprentissage :
  Jeedom n'écrit une ligne d'historique qu'au changement de valeur, et une
  lampe allumée six soirs sur vingt-huit n'a d'historique que ces six jours-là.
- Deux exceptions à cela : les journées antérieures au début de l'historique de
  la lampe — une commande historisée hier n'a rien à dire des vingt-sept jours
  précédents — et, avec « Ignorer les journées vides », celles où aucune lampe
  du groupe n'a bougé, qui sont des maisons vides. Une journée où cette lampe
  n'a pas servi alors que d'autres bougeaient reste une vraie observation, et
  elle est conservée ; le nombre de journées écartées s'affiche dans le tableau
  de l'apprentissage.
- Une lampe allumée en permanence — veilleuse, couloir — est rejouée comme
  telle : allumée à l'ouverture de la fenêtre horaire, éteinte à sa fermeture.
- Bouton « Historiser les lampes » : l'historisation de l'état des lampes
  choisies s'active en un clic, sans quoi il n'y aurait rien à rejouer.
- Soirées inventées pour les lampes sans passé, calées sur le coucher du soleil
  de votre position, avec probabilité de participation, flottement aléatoire et
  heure de coucher — y compris une heure de coucher après minuit, qui fait
  courir la soirée jusqu'à la fermeture de la fenêtre.
- Variabilité : elle décale la journée entière, d'un tirage pour tout le groupe
  et d'un second par lampe par-dessus. Les lampes d'un même groupe se décalent
  donc ensemble — un soir où l'on rentre tard, toute la maison s'allume tard —
  et aucune soirée n'est annulée, quel que soit le réglage.
- Plan tiré une fois par jour et reproductible : une box redémarrée en pleine
  soirée reprend la même, elle n'en recommence pas une autre par-dessus. Le
  bouton « Tirer un autre plan » en tire un autre, pour de bon.
- Les journées pendant lesquelles le plugin a piloté les lampes sont écartées
  de l'apprentissage, et marquées chaque jour de simulation et non au seul
  démarrage : il n'apprend jamais ses propres inventions, pas même celles d'une
  simulation de vacances démarrée une fois pour quinze jours.
- Condition de départ : une liste de commandes d'information avec test et
  valeur attendue, combinées en ET ou en OU, avec délai de confirmation et
  délai de retombée.
- Les bornes de la fenêtre horaire acceptent une heure de soleil autant qu'une
  heure fixe : « coucher-30 », « lever+15 », saisis en français comme en
  anglais et rangés en anglais, le décalage borné à douze heures. « Rien avant
  07:00 » interdisait deux heures de plein jour en juin, où le soleil se lève à
  05 h 31 ; l'heure que la borne donne aujourd'hui s'affiche à côté du champ.
- Garde-fous : fenêtre horaire, nombre maximum de lampes allumées en même
  temps, durées d'allumage minimale et maximale tenues à la minute, retour à
  l'état initial à l'arrêt — même pour une lampe retirée du groupe entre-temps
  — et respect d'un geste manuel. Un champ numérique vidé reprend sa valeur par
  défaut.
- Sélecteur de lampes : parcourt l'installation, range par pièce, distingue les
  lampes des prises et des équipements reconnus au nom, et allume la lampe pour
  la reconnaître. Il ne propose que ce qu'il saurait aussi éteindre ; le reste
  passe par « Tous les équipements », où vous désignez les commandes.
- Les pannes se voient : un ordre en échec est publié dans le centre de
  messages de Jeedom, la lampe est laissée de côté un quart d'heure au lieu
  d'être retentée chaque minute, et une lampe supprimée de l'installation est
  signalée dans les tableaux.
- Tableau de ce que le plugin a appris, lampe par lampe, et aperçu d'une
  journée — aujourd'hui, demain, après-demain — calculé par le code qui jouera
  réellement le plan. L'aperçu dessine chaque lampe en une barre de
  vingt-quatre heures : périodes allumées, fenêtre autorisée en fond,
  graduations toutes les six heures, repère au coucher du soleil et, pour
  aujourd'hui, repère à l'heure qu'il est. La liste des heures reste sous la
  barre.
- Bouton « Répéter la soirée en deux minutes » : le plan du jour joué pour de
  vrai sur les lampes, compressé sur deux minutes, avec barre de progression et
  journal des changements. La plage étalée est celle où il se passe quelque
  chose, et non la fenêtre entière ; les lampes sont remises comme elles
  étaient à la fin ; le déroulé est piloté par le navigateur, une requête par
  changement, donc fermer la page arrête tout. Le bouton refuse de démarrer si
  la simulation est en cours.
- Commandes : simulation, démarrer, arrêter, revenir à la condition, source du
  plan, prochain changement, lampes allumées, tirer un autre plan.
- Page Santé : position renseignée, lampes suivies, lampes sans historique. Le
  message d'absence de position disparaît dès que les coordonnées sont
  renseignées.
- Ni démon, ni dépendance, ni appel réseau.
