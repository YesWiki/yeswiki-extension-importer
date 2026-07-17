# Importer YesWiki extension

Pouvoir injecter des données dans Bazar depuis une API externe, qui dans un
premier temps sera la source de vérité.

## Use cases

- yunohost listes d'apps publiques/privées `YunohostAppImporter`
- Flux RSS `RssImporter`
- json custom Odoo events `OdooEventsImporter`
- caldav/cardcard
- peertube en embed
- mastodon activityPub
- YesWiki to YesWiki (liste de fiches `id_fiche`/`bf_titre`) `YesWikiListImporter`
- Données géographiques de l'état

## Configuration

Deux façons d'ajouter une source :

- à la main, en ajoutant des entrées au tableau `dataSources` dans
  `wakka.config.php`
- via la page d'admin des importers (`{{adminimporters}}`, action
  `AdminImportersAction`), qui écrit/supprime directement les sources dans
  `wakka.config.php`

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

### `YesWikiList`

Récupère des fiches depuis l'API d'un autre YesWiki (Bazar) et construit/met à
jour une liste locale (`ListManager`) à partir des couples `id_fiche`/`bf_titre`
retournés. Seuls ces deux champs sont utilisés, même si l'url en renvoie
d'autres (utile par exemple pour filtrer via `query`). Aucune fiche ni
formulaire Bazar n'est créé : le résultat est uniquement une liste
(utilisable ensuite comme options d'un champ `checkbox`/`select`/`radio`
d'un formulaire Bazar).

```php
 'dataSources' => [
        'hpf-accompagnement' => [
            'importer' => 'YesWikiList',
            'url' => 'https://www.habitatparticipatif-france.fr/?api/forms/16/entries&fields=id_fiche,bf_titre,url,checkboxListeActivitesProposees&query=checkboxListeActivitesProposees=accompagnement',
            'listId' => 'ListeAccompagnateurutricesHPF',
            // 'title' => 'Accompagnateur·ices HPF', // optionnel, par défaut = listId
        ]
    ],
```

À chaque synchronisation, la liste locale `listId` est entièrement remplacée
par le contenu à jour de l'url distante (les clés/labels disparus côté source
disparaissent aussi localement).

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

### Depuis un webhook/cron externe

Une route `GET /api/sync` permet de déclencher la synchronisation de toutes
les sources (équivalent à `./yeswicli importer:sync`), protégée par un secret
partagé plutôt que par les ACLs du wiki (utile pour un cron externe ou un
webhook).

Ajouter dans `wakka.config.php` :

```php
'sync_secret' => 'un-secret-a-generer',
```

`sync_secret` peut aussi être renseigné depuis la page d'édition de la
configuration du wiki (`EditConfigAction`), sans toucher au fichier.

Puis appeler :

```bash
curl -H "secret: un-secret-a-generer" "https://mon-yeswiki.fr/?api/sync"
```

La réponse JSON contient, pour chaque source configurée, le même message que
`./yeswicli importer:sync` (succès avec durée, ou erreur). Sans secret configuré
ou avec un secret invalide, la route répond `401`.

## Idées

- importer Ical minimaliste

## Cdc

Une classe abstraite `Importer` et on implémente le code specifique a chaque
usage les donnees sensibles (token, credentials) sont sauvées de le fichier de
conf pour commencer on lancera la sync en ligne de commande (cli symfony), qui
pourra etre appelée dans un CRON

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

- `authenticate` : pour passer les herses http et/ou ajouter un header, un
  systeme de login
- `parseData` : récuperer les données depuis la source de vérité et les mapper
  pour qu'elles puissent alimenter le modèle de formulaire bazar créé par
  `createFormModel`
  - questions : que faire des images/fichiers ? garder l'url ou importer
- `createFormModel` : générer le formulaire de base de données
- `syncData` : selon la stratégie choisie ajouter/supprimer/modifier les fiches
  du modele de formulaire

## Références

- https://priorites.yeswiki.net/posts/55/pouvoir-consommer-automatiquement-de-la-donnees-externe-via-api
- Doc de nouvelle API YunoHost (TODO Aleks x_x)
- https://lab12.io/wiki/?MonInfrastructureNomade (voir le bazarliste en bas de
  page)
- https://projetclic.cc/modele/?PagePrincipale
- identifiants yunohost créés par champs bazar
  https://forge.mrflos.pw/yeswiki/yeswiki-custom-reseau.s-mart.fr/src/branch/main/fields/YunohostUserField.php
