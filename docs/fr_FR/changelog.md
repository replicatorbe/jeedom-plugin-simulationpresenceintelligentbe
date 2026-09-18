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
- Les journées sans le moindre changement comptent dans l'apprentissage :
  Jeedom n'écrit une ligne d'historique qu'au changement de valeur, et une
  lampe allumée six soirs sur vingt-huit n'a d'historique que ces six jours-là.
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
  réellement le plan.
- Commandes : simulation, démarrer, arrêter, revenir à la condition, source du
  plan, prochain changement, lampes allumées, tirer un autre plan.
- Page Santé : position renseignée, lampes suivies, lampes sans historique. Le
  message d'absence de position disparaît dès que les coordonnées sont
  renseignées.
- Ni démon, ni dépendance, ni appel réseau.
