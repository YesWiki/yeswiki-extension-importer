# Importer YesWiki extension

Pouvoir injecter des données dans Bazar depuis une API externe, qui dans un premier temps sera la source de vérité.

## Use cases

- yunohost listes d'apps publiques/privées `YunohostAppImporter`
- Flux RSS `RssImporter` 
- json custom Odoo events `OdooEventsImporter` 
- caldav/cardcard
- peertube en embed
- mastodon activityPub
- YesWiki to YesWiki
- Données géographiques de l'état

## Configuration
add arrays of dataSources in wakka.config.php
```php
 'dataSources' => [
        'korben-rss' => [
            'url' => 'https://korben.info/feed',
            'formId' => '6',
            'importer' => 'Rss',
        ]
    ],
```
## Utilisation

**Dans le répertoire racine du yeswiki**.

Tout importer
```bash
./yeswicli importer:sync
```

Importer la source korben-rss
```bash
./yeswicli importer:sync -s korben-rss
```

Plus d'infos
```bash
./yeswicli importer:sync -h
```
## Idées
- importer YesWiki, qui permettrait de filtrer avec query, ou mot clé, un autre formulaire bazar d'un autre wiki
- importer Ical minimaliste

## Cdc
Une classe abstraite `Importer` et on implémente le code specifique a chaque usage
les donnees sensibles (token, credentials) sont sauvées de le fichier de conf
pour commencer on lancera la sync en ligne de commande (cli symfony), qui pourra etre appelée dans un CRON

Pour chaque usage d'importation : 
- on indique une url d'acces
- modalités de sync : 
    - [ ] bourrine (on efface tout et on recommence)
    - [ ] plus subtil
        - [ ] ajouter les nouvelles fiches
        - [ ] supprimer les fiches disparues
        - [ ] mettre à jour les fiches modifiées
        - [ ] conserver les fiches créées manuellement
        - [ ] garder les champs customs ajoutés

on définit les méthodes suivantes:
- `authenticate` : pour passer les herses http et/ou ajouter un header, un systeme de login
- `parseData` : récuperer les données depuis la source de vérité et les mapper pour qu'elles puissent alimenter le modèle de formulaire bazar créé par `createFormModel`
    - questions : que faire des images/fichiers ? garder l'url ou importer
- `createFormModel` : générer le formulaire de base de données
- `syncData` : selon la stratégie choisie ajouter/supprimer/modifier les fiches du modele de formulaire

## Références

- https://priorites.yeswiki.net/posts/55/pouvoir-consommer-automatiquement-de-la-donnees-externe-via-api
- Doc de nouvelle API YunoHost (TODO Aleks x_x)
- https://lab12.io/wiki/?MonInfrastructureNomade (voir le bazarliste en bas de page)
- https://projetclic.cc/modele/?PagePrincipale
- identifiants yunohost créés par champs bazar https://forge.mrflos.pw/yeswiki/yeswiki-custom-reseau.s-mart.fr/src/branch/main/fields/YunohostUserField.php
