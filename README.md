# Importer YesWiki extension

Pouvoir injecter des données dans Bazar depuis une API externe, qui dans un
premier temps sera la source de vérité.

## Use cases

- Flux RSS `RssImporter`
- json custom Odoo events `OdooEventsImporter`
- caldav/cardcard
- peertube en embed
- mastodon activityPub
- YesWiki to YesWiki (liste de fiches `id_fiche`/`bf_titre`) `YesWikiListImporter`
- YesWiki to YesWiki (fiches Bazar complètes) `YesWikiToYesWikiImporter`
- Données géographiques de l'état
- yunohost listes d'apps/utilisateurices publiques/privées : voir l'extension
  `yeswiki-extension-yunohost` (`YunohostCLIAppImporter`/`YunohostCLIUserImporter`),
  qui fournit ses propres importers compatibles avec cette extension

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

### `YesWikiToYesWiki`

Importe les fiches Bazar (tous les champs) d'un formulaire d'un autre YesWiki
vers un formulaire local, en s'authentifiant sur le wiki distant avec un
compte admin.

L'`url` à renseigner est celle de l'api json des fiches du formulaire distant,
de la forme `https://mon-wiki-distant.fr/?api/forms/12/entries/json` : elle
contient déjà l'identifiant du formulaire distant, il n'y a donc pas à le
saisir séparément. Les paramètres ajoutés à cette url sont conservés et
renvoyés à chaque synchronisation, ce qui permet de n'importer qu'une partie
des fiches (par exemple
`…/entries/json&query=bf_ville%3DMarseille`).

Deux modes de synchronisation :

- **`source_of_truth`** : miroir complet et continu. Le formulaire local, les
  listes utilisées par ses champs et les fiches sont systématiquement
  synchronisés pour être identiques au wiki distant, y compris les
  suppressions (fiches ou valeurs de liste disparues côté distant sont aussi
  supprimées localement).
- **`allow_local`** : synchronisation souple. Le formulaire local peut être
  différent (une correspondance de champs est demandée depuis
  `{{adminimporters}}` dès que le formulaire local existe déjà) ; les listes
  sont fusionnées (les valeurs distantes sont ajoutées, les valeurs
  locales sont toujours conservées, rien n'est jamais supprimé) ; les fiches
  sont créées/mises à jour mais jamais supprimées, et une fiche modifiée
  localement depuis la dernière synchro n'est plus resynchronisée (pour ne
  pas écraser une modification locale).

Si le formulaire local (`formId`) n'existe pas encore, il est créé avec
exactement les mêmes champs (mêmes identifiants) que le formulaire distant,
quel que soit le mode.

Les champs `image` et `fichier` contiennent un nom de fichier relatif au
dossier des fichiers du wiki **distant** : recopié tel quel il ne pointerait
sur rien localement. Deux modes (`filesMode`) :

- **`download`** (par défaut) : les images et fichiers sont téléchargés dans
  le dossier des fichiers du wiki local (`attach_config['upload_path']`, par
  défaut `files/`), en conservant leur nom d'origine — un fichier déjà
  présent n'est pas retéléchargé, les synchros suivantes ne coûtent donc
  rien. Les extensions autorisées sont les mêmes que pour un envoi de fichier
  classique dans une fiche.
- **`url`** : les fiches locales gardent une url absolue vers le fichier resté
  sur le wiki source. Plus léger, mais dépend de la disponibilité du wiki
  source ; nécessite une version de YesWiki dont les champs `image`/`fichier`
  acceptent une url comme valeur (les versions plus anciennes videraient le
  champ).

Le mode `download` suppose le dossier de fichiers « à plat » (comportement par
défaut de YesWiki) : si le wiki local a activé `no_safe_mode` (un
sous-dossier de fichiers par page), utiliser le mode `url`.

```php
 'dataSources' => [
        'mon-autre-yeswiki' => [
            'importer' => 'YesWikiToYesWiki',
            'url' => 'https://distant.example.org/?api/forms/12/entries/json',
            'auth' => ['user' => 'admin', 'password' => '...'],
            'formId' => '30',
            'localAdminUser' => 'admin', // voir note ACL ci-dessous
            'syncMode' => 'source_of_truth', // ou 'allow_local'
            'filesMode' => 'download', // ou 'url'
            // 'fieldsMapping' => ['bf_titre_distant' => 'bf_titre_local', ...], // requis en allow_local si formId existe déjà
            // 'noSSLCheck' => false,
            // 'remoteFilesPath' => 'files', // si le wiki distant n'utilise pas "files/"
        ]
    ],
```

L'url est découpée à la lecture de la configuration en `url` (url de base du
wiki distant), `remoteFormId` et `entriesQuery` : une configuration écrite à
la main avec ces trois clés séparées (comme avant l'ajout de ce découpage)
reste donc valable.

L'identité fiche distante ↔ fiche locale et la fusion/mise à jour ne
reposent pas sur des champs cachés ajoutés au formulaire : elles utilisent le
triple `source_url` existant (même mécanisme que les autres importers de
cette extension) et un triple dédié pour la date de dernière synchro.

Une fiche locale n'est réécrite que si au moins un des champs synchronisés
diffère réellement de ce qui est déjà enregistré : les synchros répétées ne
créent donc pas de révision ni de changement de `date_maj_fiche` inutiles, et
le journal liste, pour chaque fiche mise à jour, les champs qui ont changé
(les fiches identiques sont juste comptées en fin de synchro).

**`localAdminUser`** : `EntryManager::update()` vérifie toujours les ACL en
écriture de la fiche visée par rapport à l'utilisateur·ice actuellement
connecté·e (contrairement à la création, qui les ignore) ; or une synchro
CLI/cron ne connecte personne. Sans `localAdminUser` renseigné (nom
d'utilisateur·ice d'un compte admin local), la mise à jour de fiches déjà
existantes échouera par manque de droits dès la 2ᵉ synchro (message
"Vous n'avez pas les permissions pour éditer ce fichier" dans le journal). La
création de nouvelles fiches n'est pas concernée.

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

### Automatiquement, sans cron (`syncOnMaintenance`)

Toute source, quel que soit son importer, peut se synchroniser toute seule au
rythme de la maintenance de YesWiki : cocher « Synchroniser automatiquement
lors de la maintenance de YesWiki » sur la page d'admin, ou ajouter à la main
dans `wakka.config.php` :

```php
 'dataSources' => [
        'korben-rss' => [
            'importer' => 'Rss',
            // ...
            'syncOnMaintenance' => true,
            // 'syncIntervalInMin' => 1440, // optionnel : jamais plus d'une fois par jour
        ]
    ],
```

YesWiki fait son ménage périodique (purge des référents, des vieilles
révisions de pages, etc.) à l'occasion d'une visite du wiki, sans cron. Les
sources cochées sont synchronisées au même rythme : au plus une fois toutes
les 30 minutes, et **après** l'envoi de la page au visiteur (la connexion est
même refermée avant, en php-fpm), pour que personne n'attende la fin d'un
import. `syncIntervalInMin` ajoute, si besoin, un intervalle minimum propre à
la source, pour une source trop lourde pour être importée aussi souvent.

La date de la dernière synchronisation automatique de chaque source est
affichée sur la page d'admin des importers ; cliquer dessus déplie son
journal (le même que celui d'une synchro lancée à la main).

Utile de le savoir : rien ne se synchronise sur un wiki que personne ne
visite, puisque c'est une visite qui déclenche la maintenance. Pour un rythme
garanti, ou plus rapide que 30 minutes, passer par un cron externe
(ci-dessous).

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

## Importers fournis par d'autres extensions

N'importe quelle extension active peut fournir son propre importer : il
suffit d'y créer une classe `services/XxxImporter.php` (namespace libre) qui
étend `YesWiki\Importer\Service\Importer`, exactement comme les importers de
cette extension. `ImporterManager::getAvailableImporters()` les découvre tous
automatiquement (recherche de tout service dont le nom se termine par
`Importer`), quelle que soit l'extension qui les déclare, donc `./yeswicli
importer:sync` et la route `/api/sync` les prennent en charge sans rien de
plus à faire.

Pour que la page d'admin (`{{adminimporters}}`) sache aussi proposer/éditer
leur configuration, l'importer peut déclarer ses champs en surchargeant deux
méthodes statiques de `Importer` :

```php
public static function getAdminFields(): array
{
    return [
        // 'clé' => ['type' => 'text'|'url'|'password'|'number'|'checkbox'|'select',
        //           'required' => bool,
        //           'options' => ['valeur' => 'CLE_DE_TRADUCTION'], // type select uniquement
        //           'label' => 'CLE_DE_TRADUCTION',  // par défaut IMPORTER_FIELD_{CLÉ}
        //           'help' => 'CLE_DE_TRADUCTION']   // aide affichée sous le champ
        'lang' => ['type' => 'text', 'required' => true],
    ];
}

public static function needsBazarForm(): bool
{
    // false si l'importer ne crée pas de fiches Bazar (comme YesWikiListImporter)
    return true;
}
```

Trois autres méthodes statiques, optionnelles, complètent ce contrat :

- `hasRemoteFieldMapping()` : `true` si le tableau de correspondance des
  champs doit être construit en allant chercher en direct les champs d'un
  formulaire distant (comme `YesWikiToYesWiki`) plutôt qu'à partir de la liste
  fixe `getOwnFields()`.
- `normalizeAdminOptions()` / `denormalizeAdminOptions()` : pour un importer
  dont la configuration ne se déduit pas champ par champ (par exemple une
  seule url saisie qui porte plusieurs clés de config, comme l'url d'api de
  `YesWikiToYesWiki`). La première transforme ce qui a été saisi avant
  enregistrement, la seconde reconstruit ce qui doit être réaffiché dans le
  formulaire d'édition.

Sans surcharge, `getAdminFields()` renvoie `[]` et `needsBazarForm()` renvoie
`true` (valeurs par défaut de la classe abstraite) : l'importer reste
utilisable en CLI/API, seule la page d'admin n'aura pas de champ dédié pour
lui (à ajouter à la main dans `wakka.config.php`).

Rien à déclarer en revanche pour la synchronisation automatique : les champs
`syncOnMaintenance` et `syncIntervalInMin` (voir plus haut) sont ajoutés par
`ImporterManager::commonAdminFields()` aux champs de tous les importers, quelle
que soit l'extension qui les fournit.

C'est ce que fait `yeswiki-extension-yunohost` pour ses importers
`YunohostCLIAppImporter`/`YunohostCLIUserImporter`.

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
