# importer extension

Injects data into Bazar from an external API, which is treated as the source of truth.

## Use cases

- RSS feeds, `RssImporter`
- custom Odoo events JSON, `OdooEventsImporter`
- caldav/carddav
- embedded peertube
- mastodon activityPub
- YesWiki to YesWiki, list of `id_fiche`/`bf_titre` pairs, `YesWikiListImporter`
- YesWiki to YesWiki, complete Bazar entries, `YesWikiToYesWikiImporter`
- government geographic data
- yunohost lists of public and private apps and users: see the
  `yeswiki-extension-yunohost` extension (`YunohostCLIAppImporter` and
  `YunohostCLIUserImporter`), which ships its own importers compatible with this one

## Configuration

Two ways to add a source:

- by hand, adding entries to the `dataSources` array in `wakka.config.php`
- through the importer admin page (`{{adminimporters}}`, `AdminImportersAction`), which
  writes and removes sources in `wakka.config.php` directly

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

Fetches entries from another YesWiki's Bazar API and builds or updates a local list
(`ListManager`) from the returned `id_fiche`/`bf_titre` pairs. Only those two fields are
used, even when the URL returns more, which is handy for filtering through `query`. No
Bazar entry or form is created: the result is only a list, usable afterwards as the
options of a `checkbox`, `select` or `radio` field in a Bazar form.

```php
 'dataSources' => [
        'hpf-accompagnement' => [
            'importer' => 'YesWikiList',
            'url' => 'https://www.habitatparticipatif-france.fr/?api/forms/16/entries&fields=id_fiche,bf_titre,url,checkboxListeActivitesProposees&query=checkboxListeActivitesProposees=accompagnement',
            'listId' => 'ListeAccompagnateurutricesHPF',
            // 'title' => 'Accompagnateur·ices HPF', // optional, defaults to listId
        ]
    ],
```

On every synchronisation the local `listId` list is entirely replaced by the current
content of the remote URL: keys and labels that vanished on the source side vanish
locally too.

### `YesWikiToYesWiki`

Imports the Bazar entries of a form on another YesWiki, with all their fields, into a
local form, authenticating on the remote wiki with an admin account.

The `url` to fill in is the JSON API of the remote form's entries, shaped like
`https://my-remote-wiki.org/?api/forms/12/entries/json`: it already carries the remote
form id, so there is nothing else to enter. Parameters appended to that URL are kept and
sent on every synchronisation, which lets you import only part of the entries, for
instance `…/entries/json&query=bf_ville%3DMarseille`.

Two synchronisation modes:

- **`source_of_truth`**: a complete, continuous mirror. The local form, the lists its
  fields use and the entries are always synchronised to match the remote wiki, deletions
  included. Entries or list values that disappear remotely are deleted locally too.
- **`allow_local`**: loose synchronisation. The local form may differ, and a field
  mapping is then asked for in `{{adminimporters}}` as soon as the local form already
  exists. Lists are merged: remote values are added, local values are always kept,
  nothing is ever deleted. Entries are created and updated but never deleted, and edits
  made here and on the remote wiki are merged field by field (see "Merging edits"
  below).

When the local form (`formId`) does not exist yet, it is created with exactly the same
fields, and the same field ids, as the remote form, in either mode.

`image` and `fichier` fields hold a filename relative to the **remote** wiki's files
folder: copied as is it would point at nothing locally. Two modes, through `filesMode`:

- **`download`**, the default: images and files are downloaded into the local wiki's
  files folder (`attach_config['upload_path']`, `files/` by default), keeping their
  original name. A file already present is not downloaded again, so later
  synchronisations cost nothing. Allowed extensions are the same as for a regular file
  upload in an entry.
- **`url`**: local entries keep an absolute URL to the file left on the source wiki.
  Lighter, but it depends on the source wiki staying up, and it needs a YesWiki version
  whose `image` and `fichier` fields accept a URL as a value. Older versions would empty
  the field.

`download` mode assumes a flat files folder, which is YesWiki's default. When the local
wiki has `no_safe_mode` enabled, giving one files subfolder per page, use `url` mode.

```php
 'dataSources' => [
        'mon-autre-yeswiki' => [
            'importer' => 'YesWikiToYesWiki',
            'url' => 'https://distant.example.org/?api/forms/12/entries/json',
            'auth' => ['user' => 'admin', 'password' => '...'],
            'formId' => '30',
            'localAdminUser' => 'admin', // see the ACL note below
            'syncMode' => 'source_of_truth', // or 'allow_local'
            'filesMode' => 'download', // or 'url'
            // 'conflictPolicy' => 'local', // or 'remote', see "Merging edits" below
            // 'keepRemoteUpdateDate' => false, // see "Entry dates" below
            // 'fieldsMapping' => ['bf_titre_distant' => 'bf_titre_local', ...], // required in allow_local when formId already exists
            // 'noSSLCheck' => false,
            // 'remoteFilesPath' => 'files', // when the remote wiki does not use "files/"
        ]
    ],
```

#### Entry dates

An imported entry's creation date is always the one it has on the source wiki. Without
that, an imported entry claims to have been created on the day of the import, which
shows as soon as a list sorts or filters by creation date.

The modification date is only carried over when `keepRemoteUpdateDate` is on. It still
has one job: in `allow_local` mode, spotting entries imported before field-by-field
merging and edited here since (see below). Only turn it on when those entries are not
edited locally.

#### Merging edits

In `allow_local` mode, each imported entry keeps, for each field, a fingerprint of the
value received from the remote wiki and of the value stored here at the last import (the
`sync_base_<source>` triple). On the next sync, each field is compared with them:

- changed only on the remote wiki: the remote value is taken;
- changed only here: the local value is kept;
- changed on both sides to the same value: nothing to do;
- changed on both sides to different values: a conflict. `conflictPolicy` decides:
  `local` (the default) keeps this wiki's value, `remote` takes the remote one. The sync
  log records the conflict with both values, once: as long as the remote wiki does not
  change that field again, it is not reported again.

Only fingerprints are stored, not content, so the log is the only place the value a
conflict set aside can still be seen.

An entry imported before merging existed has no fingerprint. If it was edited here since
the last sync, it is left alone one more time, and its differences with the remote wiki
then count as local edits. Otherwise it takes the remote version. Either way its
fingerprints are recorded and later syncs merge.

#### Two-way imports

Wikis can import from each other, loops included (A ↔ B, or A → B → C → A), without
entries coming back to where they started.

Each imported entry keeps the URL it was first written at: its source URL for a direct
import, or the origin URL the remote wiki passed on (`origin_url` in the API) for an
import of an import. The importer asks the remote wiki not to return the entries whose
origin is the local wiki (the API's `excludeSource` parameter, given the local
`base_url`). Entries from other wikis are still imported.

The same origin is used to remove duplicates. An entry can reach this wiki by two
paths: this wiki imports A and B, and B itself imported A, or two sources copied the same
entry. It is then only created once. The first source that imported it owns it: only
that source updates it, and in `source_of_truth` mode only that source can delete it.
Other sources skip it and say so in the log. If the owning source deletes it, the next
one creates it again and becomes its owner.

Every wiki in the chain needs an up-to-date YesWiki: one that does not know
`excludeSource` ignores it and returns everything, and one that does not expose
`origin_url` breaks the chain of origins.

In `source_of_truth` mode, importing into a form that has entries of its own deletes
them on the first sync, since the source doesn't have them. For a two-way import into the
same form, use `allow_local`.

The URL is split when the configuration is read, into `url` (the remote wiki's base
URL), `remoteFormId` and `entriesQuery`. A configuration written by hand with those
three separate keys, as before that splitting was added, therefore still works.

Matching a remote entry to a local one, and merging or updating it, does not rely on
hidden fields added to the form: it uses the existing `source_url` triple, the same
mechanism as this extension's other importers, plus a dedicated triple for the last sync
date.

A local entry is only rewritten when at least one synchronised field genuinely differs
from what is already stored. Repeated synchronisations therefore create no needless
revision and no needless `date_maj_fiche` change, and the log lists, for each updated
entry, the fields that changed. Unchanged entries are simply counted at the end of the
run.

**`localAdminUser`**: `EntryManager::update()` always checks the target entry's write
ACL against the currently logged-in user, unlike creation, which ignores them. A CLI or
cron sync logs nobody in. Without `localAdminUser` set to the username of a local admin
account, updating already existing entries fails for lack of rights from the second sync
onwards, with "Vous n'avez pas les permissions pour éditer ce fichier" in the log.
Creating new entries is not affected.

## Usage

**From the YesWiki root directory.**

Import everything:

```bash
./yeswicli importer:sync
```

Import the korben-rss source:

```bash
./yeswicli importer:sync -s korben-rss
```

More options:

```bash
./yeswicli importer:sync -h
```

### Automatically, without cron (`syncOnMaintenance`)

Any source, whatever its importer, can synchronise itself at the pace of YesWiki's
maintenance: tick "Synchroniser automatiquement lors de la maintenance de YesWiki" on
the admin page, or add by hand in `wakka.config.php`:

```php
 'dataSources' => [
        'korben-rss' => [
            'importer' => 'Rss',
            // ...
            'syncOnMaintenance' => true,
            // 'syncIntervalInMin' => 1440, // optional: never more than once a day
        ]
    ],
```

YesWiki does its periodic housekeeping (purging referrers, old page revisions and so on)
on the occasion of a visit, without cron. Ticked sources are synchronised at the same
pace: at most once every 30 minutes, and **after** the page has been sent to the visitor
(with php-fpm the connection is even closed first), so that nobody waits for an import to
finish. `syncIntervalInMin` adds, where needed, a minimum interval of its own for a
source too heavy to import that often.

Each source's last automatic sync date is shown on the importer admin page; clicking it
unfolds its log, the same one as a manually triggered sync.

Worth knowing: nothing synchronises on a wiki nobody visits, since a visit is what
triggers maintenance. For a guaranteed pace, or one faster than 30 minutes, go through an
external cron, below.

### From an external webhook or cron

A `GET /api/sync` route triggers the synchronisation of every source, the equivalent of
`./yeswicli importer:sync`, protected by a shared secret rather than by the wiki ACLs,
which suits an external cron or a webhook.

Add to `wakka.config.php`:

```php
'sync_secret' => 'a-secret-to-generate',
```

`sync_secret` can also be set from the wiki's configuration editing page
(`EditConfigAction`), without touching the file.

Then call:

```bash
curl -H "secret: a-secret-to-generate" "https://my-yeswiki.org/?api/sync"
```

The JSON answer holds, for each configured source, the same message as
`./yeswicli importer:sync`, either a success with its duration or an error. With no
secret configured, or an invalid one, the route answers `401`.

## Importers provided by other extensions

Any active extension can provide its own importer: create a `services/XxxImporter.php`
class in it, in any namespace, extending `YesWiki\Importer\Service\Importer`, exactly like
this extension's own importers. `ImporterManager::getAvailableImporters()` discovers them
all automatically, by looking for every service whose name ends in `Importer`, whichever
extension declares them, so `./yeswicli importer:sync` and the `/api/sync` route handle
them with nothing else to do.

For the admin page (`{{adminimporters}}`) to offer and edit their configuration too, an
importer can declare its fields by overriding two static methods of `Importer`:

```php
public static function getAdminFields(): array
{
    return [
        // 'key' => ['type' => 'text'|'url'|'password'|'number'|'checkbox'|'select',
        //           'required' => bool,
        //           'options' => ['value' => 'TRANSLATION_KEY'], // select type only
        //           'label' => 'TRANSLATION_KEY',  // defaults to IMPORTER_FIELD_{KEY}
        //           'help' => 'TRANSLATION_KEY']   // help shown under the field
        'lang' => ['type' => 'text', 'required' => true],
    ];
}

public static function needsBazarForm(): bool
{
    // false when the importer creates no Bazar entry, like YesWikiListImporter
    return true;
}
```

Three other static methods, all optional, complete that contract:

- `hasRemoteFieldMapping()`: `true` when the field mapping table has to be built by
  fetching a remote form's fields live, as `YesWikiToYesWiki` does, rather than from the
  fixed `getOwnFields()` list.
- `normalizeAdminOptions()` and `denormalizeAdminOptions()`: for an importer whose
  configuration cannot be derived field by field, for instance a single entered URL
  carrying several config keys, like the `YesWikiToYesWiki` API URL. The first transforms
  what was entered before saving, the second rebuilds what has to be shown again in the
  editing form.

Without an override, `getAdminFields()` returns `[]` and `needsBazarForm()` returns
`true`, the abstract class defaults: the importer stays usable from the CLI and the API,
only the admin page has no dedicated field for it, to be added by hand in
`wakka.config.php`.

Nothing has to be declared for automatic synchronisation, on the other hand: the
`syncOnMaintenance` and `syncIntervalInMin` fields, above, are added by
`ImporterManager::commonAdminFields()` to every importer's fields, whichever extension
provides them.

That is what `yeswiki-extension-yunohost` does for its `YunohostCLIAppImporter` and
`YunohostCLIUserImporter`.

## Ideas

- a minimal Ical importer

## Specification

An abstract `Importer` class, with the code specific to each use implemented on top.
Sensitive data, tokens and credentials, live in the configuration file. To begin with,
the sync runs on the command line, through Symfony console, which a CRON can call.

For each import use:

- an access URL is given
- synchronisation choices:
  - [ ] brute force, wipe everything and start over
  - [ ] finer grained
    - [ ] add new entries
    - [ ] delete entries that disappeared
    - [ ] update changed entries
    - [ ] keep manually created entries
    - [ ] keep added custom fields

The following methods are defined:

- `authenticate`: to get past HTTP gates, add a header, or log in
- `parseData`: fetch the data from the source of truth and map it so that it can feed the
  bazar form model created by `createFormModel`
  - open question: what to do with images and files, keep the URL or import them
- `createFormModel`: generate the database form
- `syncData`: following the chosen strategy, add, delete or change the entries of the form
  model

## References

- https://priorites.yeswiki.net/posts/55/pouvoir-consommer-automatiquement-de-la-donnees-externe-via-api
- New YunoHost API documentation (TODO Aleks x_x)
- https://lab12.io/wiki/?MonInfrastructureNomade (see the bazarliste at the bottom of the page)
- https://projetclic.cc/modele/?PagePrincipale
- yunohost accounts created by bazar fields
  https://forge.mrflos.pw/yeswiki/yeswiki-custom-reseau.s-mart.fr/src/branch/main/fields/YunohostUserField.php
