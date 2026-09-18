# Plugin Jeedom — Simulation de présence intelligente

Faire croire que la maison est habitée, en rejouant ce qu'elle fait d'habitude.

## Ce qu'il apporte

- **Il apprend au lieu de clignoter.** Le plugin relit l'historique Jeedom de
  l'état de chaque lampe, en tire les heures auxquelles elle s'allume et
  s'éteint selon le jour de semaine, et rejoue cette journée. Ce n'est pas un
  minuteur avec du hasard : c'est votre soirée, décalée.
- **Un bouton pour rendre l'apprentissage possible.** « Historiser les lampes »
  active l'historisation de l'état des lampes choisies. Sans historique, il n'y
  a rien à rejouer — et l'historique ne commence qu'au moment où on coche la
  case.
- **Une soirée crédible dès le premier jour.** Une lampe sans passé reçoit une
  soirée inventée, calée sur le coucher du soleil, avec une probabilité de
  participation par lampe et un flottement aléatoire. Un plugin qui ne fait rien
  tant qu'il n'a pas appris n'est jamais adopté.
- **Un plan qui ne se contredit pas.** Le tirage est reproductible — graine =
  groupe, lampe, date, et un sel propre au groupe : une box redémarrée à 21 h
  reprend la même soirée, elle n'en recommence pas une autre par-dessus. Le
  bouton « Tirer un autre plan » fait avancer ce sel, et tire donc un autre
  plan.
- **Une maison qui rentre tard rentre tard partout.** La variabilité décale la
  journée entière : un tirage pour tout le groupe, un second par lampe
  par-dessus. Les lampes d'un même groupe bougent donc ensemble, et aucune
  soirée n'est annulée, quel que soit le réglage.
- **Il n'apprend pas ses propres inventions.** Les journées pendant lesquelles
  il a piloté les lampes sont écartées de l'apprentissage.
- **Un départ qui décrit la maison, pas une heure.** Une liste de commandes
  d'information avec test et valeur attendue, en ET ou en OU — l'alarme est
  armée et personne n'est présent — avec délai de confirmation et délai de
  retombée.
- **Des garde-fous.** Fenêtre horaire, nombre maximum de lampes allumées en même
  temps, durées d'allumage tenues à la minute, retour à l'état initial à
  l'arrêt, et respect d'un geste manuel : une lampe touchée à la main est
  laissée tranquille.
- **Des pannes qui se voient.** Un ordre en échec est publié dans le centre de
  messages de Jeedom et la lampe est laissée de côté un quart d'heure, au lieu
  d'être retentée chaque minute sans que personne n'en sache rien.
- **Un aperçu qui ne ment pas.** Aujourd'hui, demain, après-demain, lampe par
  lampe, calculés par le code qui jouera réellement le plan.

## Ce qu'il ne fait pas

Il ne pilote rien hors simulation : ce qui est coché dans un groupe n'est touché
qu'entre le démarrage et l'arrêt.

Il ne règle ni l'intensité, ni la couleur, ni la température de couleur. Une
lampe s'allume et s'éteint, c'est tout ce qu'il promet — et c'est tout ce que
voit la rue.

Il n'a ni démon, ni dépendance, ni appel réseau : tout est en PHP, dans le cron
du cœur.

## Prérequis

- Jeedom 4.4 ou plus récent.
- La position de l'installation renseignée dans Réglages → Système →
  Configuration → Général. Sans elle, les journées inventées se calent sur un
  coucher de soleil fixe à 19 h toute l'année.
- Des lampes ou des prises pilotables, créées par vos autres plugins. Celles qui
  publient leur état sont les seules qui puissent être apprises.

## Installation

Plugins → Gestion des plugins → Ajouter → Github, puis :

| Champ | Valeur |
|---|---|
| Utilisateur | `replicatorbe` |
| Dépôt | `jeedom-plugin-simulationpresenceintelligentbe` |
| Branche | `master` (stable) ou `beta` |

## Branches

- **`master`** — version stable. Tout commit poussé ici est proposé en mise à
  jour aux utilisateurs, Jeedom identifiant la version par le SHA du dernier
  commit de la branche.
- **`beta`** — développement. C'est la branche par défaut du dépôt.

## Architecture

```
cron du cœur (chaque minute)            cron quotidien (la nuit)
        │                                        │
        │                            buildProfiles()
        │                              historique Jeedom, lampe par lampe
        │                                        │
        │                              ...Profile::build()
        │                                  tranches d'un quart d'heure,
        │                                  taux d'allumage et d'extinction,
        │                                  profil par jour de semaine
        │                                        │  (cache)
        └─ runMinute()                           ▼
             condition de départ ──── ...Profile::compare()
             plan du jour ─────────── ...Profile::generate()  si assez appris
                (cache, graine          ...Profile::invent()   sinon, au soleil
                 groupe|lampe|date)     ...Profile::applyWindow()
             état attendu à la minute ─ ...Profile::stateAt()
             arbitrage ──────────────── ...Profile::capSimultaneous()
                        │
                        └──► ordre à la commande d'action de la lampe
                             (plugin tiers : Zigbee, Z-Wave, Hue, MQTT…)
```

Tout ce qui décide est dans `simulationpresenceintelligentbeProfile`, qui ne
connaît ni Jeedom, ni base de données, ni commande : il reçoit des journées
observées sous forme de listes de nombres et rend une journée à jouer sous la
même forme. C'est ce qui permet de l'éprouver hors ligne sur des milliers de
tirages. `simulationpresenceintelligentbeSun` — le soleil et la minute du jour —
ne connaît pas Jeedom non plus. La reconnaissance des lampes est dans
`simulationpresenceintelligentbeLamps`, et la classe principale ne fait que lire
l'historique, exécuter des commandes et tenir des compteurs.

## Contrôles

```bash
php tests/run.php            # 133 contrôles hors ligne, sans Jeedom
php tests/check-classes.php  # les pièges du cœur, par réflexion
```

## Documentation

- [Français](docs/fr_FR/index.md)
- [English](docs/en_US/index.md)

## Licence

AGPL v3. Voir [LICENSE](LICENSE).
