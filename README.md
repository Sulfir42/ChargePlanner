# ChargePlanner — plugin Jeedom

Planification intelligente de la charge des véhicules électriques.

ChargePlanner décide, voiture par voiture, si la borne doit charger à fond
(mode **Normal**) ou rester en **Eco** pour profiter du surplus solaire. Il
ne pilote ni les ampères ni les limites du compteur : il se contente de
basculer le mode de la borne, et laisse votre scénario de pilotage existant
faire le reste.

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
- **Mode simulation** activé à l'installation : tout est calculé et affiché,
  aucune commande n'est envoyée.

## Cohabitation

- Le plugin ne remet en Eco que ce qu'il a lui-même passé en Normal.
- Si vous changez le mode à la main pendant une charge forcée, il rend la main
  pour ce plan et ne le reforce pas.
- Voiture débranchée : aucune action.

## Tests

```
php tests/engine_test.php   # moteur de décision et calendrier
php tests/plugin_test.php   # classe Jeedom avec un noyau simulé
```

## Licence

AGPL
