# ChargePlanner — plugin Jeedom

Plugin privé de pilotage des deux Wallbox de la maison : régulation des
ampères (surplus solaire, limite du compteur), modes Normal / Eco par borne,
priorisation, et planification de la charge des voitures (objectifs, boost,
jours creux).

## Équipements

- **Pilotage** : priorisation (Désactivée / WB1 / WB2), surplus, budget,
  autorisation, urgence, raison, fonctionnement.
- **Borne 1 / Borne 2** : mode (Normal / Eco), consigne en ampères, raison.
- **Une fiche par voiture** : objectifs, boost, borne du jour.

## Observation puis actif

À l'installation, le plugin est en **observation** : il calcule chaque cycle,
publie ce qu'il ferait et l'écrit dans son log à côté de la consigne du
scénario de référence, mais n'envoie rien aux bornes. En passant en **actif**
(configuration du plugin), il reprend l'état du scénario de référence et le
désactive ; le retour en observation le réactive. Il n'y a jamais deux
pilotes en même temps.

La régulation est un portage fidèle du scénario « Pilotage Wallbox V4.0 »,
vérifié cycle par cycle contre l'original (`tests/regulator_diff_test.php`).
Tous ses paramètres sont réglables dans la configuration du plugin.

## Fonctions

- **Objectifs** (jusqu'à 4 par voiture) : « vendredi 0h → 50 % », « dimanche
  22h → 100 % ». Le plugin calcule l'énergie manquante (capacité, rendement),
  la durée de charge à la puissance de la borne, et ne passe en Normal que
  juste assez tôt. Entre-temps, le solaire travaille.
- **Préférence jours creux** : s'il faut compléter avant une échéance précédée
  d'un jour plein, la charge est placée sur le dernier jour creux.
- **Boost** ponctuel : « prochain jour creux » (démarre à 0h05 par défaut) ou
  « immédiat ». Il s'arrête tout seul quand la charge est finie ou que la
  voiture est débranchée, et la borne revient à son mode d'origine.
- **Calendrier des jours creux** : jours de la semaine au choix, jours fériés
  français calculés automatiquement (Pâques, Ascension, Pentecôte inclus),
  dates supplémentaires.
- **Borne du jour** : si les voitures sont exceptionnellement inversées, la
  commande « Choisir la borne » change la borne ; elle revient sur l'habituelle
  30 min après le débranchement.
- **Batterie estimée** : entre deux remontées (souvent espacées) du
  constructeur, le % est complété avec l'énergie comptée par la borne.

## Cohabitation

- Le plugin ne remet en Eco que ce qu'il a lui-même passé en Normal.
- Si vous changez le mode à la main pendant une charge forcée, il rend la main
  pour ce plan et ne le reforce pas.
- Voiture débranchée : aucune action.

## Tests

```
php tests/engine_test.php   # moteur de décision et calendrier
php tests/plugin_test.php   # voitures, avec un noyau Jeedom simulé
php tests/site_test.php     # régulation, bornes, observation / actif
php tests/regulator_diff_test.php  # portage comparé au scénario d'origine
```

## Licence

AGPL
