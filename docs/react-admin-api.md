# React admin — REST contract

Namespace `lw-migration/v1`. Every route below is guarded by
`Rest\Permission::check` (logged in, the `lw_migration_capability`
capability — `manage_options`, `manage_network_options` on a network — and
the main site's context on a network); none is listed in the REST index.

## Boot data

`window.lwMigration` (inline script of the admin page) carries `version`,
`namespace`, `chunkBytes`, `tab`, `loginUrl`, `adminEmail`, `network` and
`siteFolder`: the slug of this site's address (the backup-name slug rule,
`SiteFolder::name()`), under which each account destination keeps this
site's backups. The form starts a new destination's S3 prefix and
FTP/SFTP/WebDAV folder with it.

## `GET /overview`

The admin home page (Overview section) in one read: `Rest\OverviewApi`.
Read only — nothing is written, no destination or connected site is
contacted, no archive is opened. It carries names, kinds, states and times
only: never a secret, a destination setting, a server path or the backups
folder's random name.

```json
{
  "site": { "host": "example.com", "network": false },
  "last": {
    "name": "example-com-20261016-030000-001-abcd1234.wpress",
    "time": 1760583600,
    "size": 1868000000,
    "files": 18412,
    "dbSize": null,
    "dbTables": 64,
    "cloud": [ { "id": "d447b8f2", "name": "Google Drive", "status": "ok" } ],
    "local": true,
    "outcome": "ok"
  },
  "running": { "id": "0123456789abcdef", "type": "export", "progress": 0.42 },
  "days": { "window": 30, "covered": 13, "of": 14 },
  "history": [
    { "day": "2026-10-16", "backups": [ { "size": 1868000000, "state": "cloud", "manual": false } ] }
  ],
  "attention": [
    { "kind": "reauth", "destination": "bbbb2222", "text": "Dropbox needs a new sign-in: …", "action": "destinations" }
  ],
  "destinations": [
    { "id": "d447b8f2", "name": "Google Drive", "type": "gdrive", "where": "…", "status": "ok", "lastUpload": 1760584320, "count": 7, "preset": null, "mode": null }
  ],
  "schedule": {
    "next": 1760670000,
    "enabled": true,
    "interval": 86400,
    "name": "Nightly",
    "destinations": [ "Google Drive" ],
    "keep": "7 days",
    "runs": [ { "time": 1760584320, "outcome": "ok", "reason": null } ]
  },
  "links": [
    { "id": "cccccccccccccccc", "host": "dev2.example.com", "direction": "pull", "incoming": false, "secure": true, "status": "ready" }
  ],
  "folder": { "path": "", "protected": true, "free": 44200000000 }
}
```

All times are Unix seconds; format them in the site's time zone.

### `site`

- `host`: the host of the site's home address.
- `network`: a multisite network (the backups, destinations and schedules
  are then the network's).

### `last` — the newest backup that still exists, or `null`

Exists = the file is in the backups folder, or a stored destination is
recorded as holding it. `null` when there is none (empty site).

- `name`, `time` (file time; the run's end for a backup kept only in the
  cloud), `size` (bytes; `null` for a backup only a destination holds and
  no run log names).
- `files`, `dbTables`: the file and table counts of the export that made
  it, while its job folder is kept (the daily cleanup removes it after
  about a day); `null` otherwise.
- `dbSize`: always `null` (the dump size is recorded nowhere).
- `cloud`: where it was uploaded, stored destinations only:
  `status` `ok | pending | failed`.
- `local`: the file is in the backups folder.
- `outcome`: `failed` when a schedule run failed after it (the newest
  attempt failed; `last` still describes the newest good backup),
  `partial` when an upload of it failed (the run log says partial, or a
  destination records a failed upload), else `ok`.

### `running` — the unfinished job, or `null`

The same job `GET /jobs/active` returns (lock holder first). `type` is the
job type (`export`, `import`, `cloud`, `move`, `reset`, `push`); `progress`
is the share of the whole job done, 0–1 (two decimals). Link to it with its
`id`.

### `days` and `history`

- `history`: the last 30 days of the site's calendar (site time zone, never
  UTC), oldest first, today last; every day is present, `backups: []` when
  nothing happened. Within a day, oldest first.
- A backup: `size` (bytes, `null` when unknown), `state`
  `cloud` (a destination holds it, or its run log says an upload succeeded)
  `| local | failed`, `manual` (not a schedule's backup).
- A schedule run that failed or never started is `{ "size": 0, "state":
  "failed", "manual": false }`. A failed manual backup is not recorded
  anywhere and does not appear.
- Sources: the backups folder, the upload record, and the schedules' run
  logs (the last 30 runs each), so a scheduled backup pruned since still
  shows on its day.
- `days.covered`: days among the last 14 (today included) with at least
  one backup (not failed); `days.of` is 14, `days.window` 30.

### `attention` — what needs a look, in this order

| `kind` | When | `destination` | `action` |
|---|---|---|---|
| `reauth` | a destination's state is reauth | id | `destinations` |
| `hostKey` | an SFTP server shows a host key to confirm | id | `destinations` |
| `certificate` | an FTPS server shows a certificate to confirm | id | `destinations` |
| `error` | a destination's last check or upload failed otherwise | id | `destinations` |
| `folder` | the chosen backups folder cannot be used (lost protection, missing, …) | `null` | `settings` |
| `space` | less free space in the backups folder than the newest backup's size | `null` | `backups` |
| `failedRun` | a schedule's last run failed or never started (one per schedule) | `null` | `schedules` |

`text` is a short translated sentence (it names the destination or the
schedule). The fingerprint kinds are read from the failure recorded on the
destination: a type with a confirmed fingerprint whose recorded failure
text carries a fingerprint of that type.

### `destinations` — every stored destination

- `type`: the type key (`s3`, `webdav`, `ftp` — FTP and FTPS —, `sftp`,
  `gdrive`, `dropbox`, `onedrive`).
- `where`: the same short description as the Destinations section. For an
  account destination (`gdrive`, `dropbox`, `onedrive`) it names the site's
  folder: `Google Drive · LW Migration/example-com`,
  `Dropbox · example-com`; `Not connected yet` before the sign-in.
- `status`: `ok | reauth | error | pending` (`pending`: never checked, or a
  fingerprint waits for confirmation).
- `lastUpload`: the last successful upload, `null` for never.
- `count`: this site's backups the upload record has there (an integer,
  0 when none).
- `preset`: the S3 provider key (`aws`, `r2`, `digitalocean`…), `null` for
  other types; `mode`: the WebDAV server mode (`auto`, `nextcloud`, `plain`),
  `null` for other types. These two are the only settings in an Overview row;
  they pick the icon and the type name.

### `schedule` — one schedule, or `null` when there is none

The switched-on schedule that runs next; when none is on, the first stored
one (with `next: null`). The default schedule is never created by this
read.

- `next`: its WP-Cron event, `null` when off or not planned.
- `enabled`: it is switched on (`next` can still be `null`: on, but no
  WP-Cron event is planned).
- `interval`: the longest regular gap between two of its runs, in seconds
  (every N hours or days as set, 604800 weekly, 31 days monthly); the page
  calls a backup late when it is older than this plus 12 hours.
- `name`: the shown title; `destinations`: names of its destinations that
  still exist.
- `keep`: its retention as a short phrase to put after "Kept: " — e.g.
  `7 days`, `the last 7 backups`, `20 GB in total`, joined with ", ";
  `every backup` without a limit (translated).
- `runs`: its run log, newest first (at most 30): `outcome`
  `ok | partial | failed` (a run that never started is failed; a cancelled
  run is left out), `reason`: the log's error text (paths and addresses
  already removed), for partial without one `Upload failed: <names>`;
  `null` for ok.

### `links` — the connections, or `null` on a network

Connections exist on single sites only; on a network the block is `null`
(hide it).

- `host`: the other site's host.
- `direction`: what the connection allows: `pull | push | both`.
- `incoming`: `false` — this site typed the code and acts (`pull`: may copy
  that site here, `push`: may send this site there); `true` — the other
  site acts (`pull`: it may copy this site, `push`: it may replace this
  site's content).
- `secure`: https; `status`: `ready | pending | broken`.

### `folder`

- `path`: the chosen backups folder as the Settings section shows it; `""`
  for the default folder (its random name is a protection and is never
  sent — show "the default folder").
- `protected`: no problem is known with the folder (the check the Settings
  section runs for a chosen folder); `false` goes with a `folder`
  attention item.
- `free`: free bytes where the backups are kept, `null` when the host does
  not tell.

## `GET /export/estimate`

How big each part of a backup would be, for the Backup section's
composition bar and the sizes next to its choices: `Rest\EstimateApi`.
The files are measured with the export's own roots, exclusions and
enumerator (`Export\ExportRoots`, `ExportFilter`, `FileEnumerator`, with
every part included), so a figure is what a backup with that choice
archives (uncompressed). It never blocks: one request measures for at
most 2 s itself and then answers what is known; the measuring continues
on the next request (no cron event). While `complete` is `false`, ask
again (every few seconds) to move it on. Only one request measures at a
time (a lease in the `lw_migration_export_estimate` option row, never
autoloaded); a request that finds another one measuring answers at once.
It carries sizes and counts only: never a name, a path or a setting.

```json
{
  "parts": {
    "media":     { "bytes": 1153433600, "files": 4812 },
    "database":  { "bytes": 222298112, "tables": 64, "spamComments": 412, "revisions": 3270 },
    "plugins":   { "bytes": 299892736, "count": 31, "activeBytes": 207618048, "activeCount": 24 },
    "themes":    { "bytes": 100663296, "count": 4, "activeBytes": 42991616, "activeCount": 1 },
    "muPlugins": { "bytes": 419430, "count": 2 },
    "cache":     { "bytes": 39845888 },
    "other":     { "bytes": 96468992 }
  },
  "complete": true,
  "partial": [],
  "measuredAt": 1760600000,
  "freeSpace": 44200000000,
  "scope": "site"
}
```

- `complete`: `true` when `parts` is a finished measuring younger than an
  hour. `false` while a measuring runs: `parts` is then the last finished
  measuring (`measuredAt` its time), or before the first one finished the
  parts measured so far (`measuredAt` `null`). Say "approximate" or "still
  counting" accordingly; a result older than an hour is measured again on
  the next request.
- A restore or a reset (finished, failed or cancelled) forgets the stored
  estimate and the measuring under way, and the row is never carried into
  the restored or reset site: the next request measures the site anew.
- `partial`: the parts that could not be counted, in a finished result
  (`complete` is `true`); `[]` normally. The time budget is checked
  between folders, so one huge flat folder (a few hundred thousand files)
  can make a request run long, and the host's time limit may kill it
  before anything is saved. After two requests in a row that died or failed,
  the measuring is given up instead of retried on every poll: what was
  finished is kept, the unfinished parts are `null` and listed here (the
  file parts — `media`, `plugins`, `themes`, `muPlugins`, `cache`, `other` —
  or every part when it stopped before the file walk). Show those rows as
  "could not be counted", not "still counting". The next measuring is tried
  when the result is an hour old.
- A part is `null` while it is not measured yet: `database` until its
  tables are sized (it goes first, it is quick, and the request that
  finishes it ends there, so its figures are saved before the walk and
  kept when the walk is given up), the file parts until the file walk
  began; during the walk they only grow. Before anything was
  measured every part is `null`. Show a row without its size then.
  `database` stays `null` in a finished result when the plugin cannot
  read the database (a database drop-in without one MySQL connection).
- `media`: the uploads folder (and `blogs.dir`) — the `noMedia` choice.
- `database`: `bytes` is the server's own size figure of the tables a
  backup dumps (data plus indexes; the dump is usually a little smaller or
  larger), `tables` their number (`noDatabase`). `spamComments` and
  `revisions` are row counts for `noSpamComments` and `noPostRevisions`,
  `null` until every table is counted (show the row without a count then);
  what leaving them out saves in bytes is not measured.
- `plugins`, `themes`: `bytes`/`count` is all of them (`noPlugins`,
  `noThemes` leave out `bytes`); `activeBytes`/`activeCount` what stays with
  `noInactivePlugins` / `noInactiveThemes` (the active theme and its
  parent). This plugin and the SQLite database plugin never travel and are
  not counted. On a network "active" means network-active or used by any
  site.
- `muPlugins`: the must-use plugins folder (`noMustUsePlugins`); `count` is
  the PHP files directly in it (the ones WordPress loads).
- `cache`: `wp-content/cache` (`noCache`). `other`: the rest of
  `wp-content` that every backup carries (languages, drop-ins, …).
- The excluded files and tables of the Advanced section are not taken
  into account; neither is compression (the figures are uncompressed).
- `freeSpace`: free bytes where the backup is built (our storage folder),
  `null` when the host does not tell.
- `scope`: `site`, or `network`: on a network the estimate is always for
  the whole network (every site's files and tables), also when the form
  backs up chosen sites only — say so next to the figures.
- A failure (for example an unwritable storage folder) answers the usual
  REST error; the section then works without sizes.

## Job input `confirm` — the check before a restore

`GET /jobs/{id}` of an import waiting at its `confirm` step carries
`input: { kind: "confirm", summary }`. The summary is built by
`Import\ImportSummary` and passed field by field through
`Rest\ConfirmSummaryView` (a whitelist: never a path, a file or plugin
name, or a secret). Everything is read from the archive's `package.json`
and its entry headers (`Import\ArchiveInventory`: each header is read, its
data skipped); no file is decoded or extracted for it. The headers are
read once, before the first question (the job shows the `confirm` step
running, message "Reading the archive's contents..." with its progress, on
a big archive over a few ticks); asking again reuses that walk. An encrypted
archive has its password checked at the `decrypt` step before; the walk
itself needs no password.

```json
{
  "from": "https://dev2.example", "to": "https://this.example",
  "replacesAddress": true,
  "wordpress": { "archive": "7.1", "site": "7.1" },
  "php": { "archive": "8.2.29", "site": "8.4.20" },
  "prefix": { "archive": "wp_", "site": "wp_" },
  "createdAt": 1760307300,
  "archiveSize": 3242719232, "expandedSize": null,
  "encrypted": false, "compression": "gzip",
  "parts": {
    "database":  { "included": true,  "bytes": 222298112, "count": null },
    "media":     { "included": true,  "bytes": 2254857830, "count": 6120 },
    "plugins":   { "included": true,  "bytes": 299892736, "count": 27 },
    "themes":    { "included": false, "bytes": 0, "count": 0 },
    "muPlugins": { "included": true,  "bytes": 419430, "count": 2 }
  },
  "keepsThisPlugin": true,
  "overwrites": { "database": true, "media": true, "plugins": true, "themes": false },
  "subsite": null,
  "safetyBackup": true, "safetyBytes": 1868310528,
  "diskNeeded": 8353748992, "diskFree": 44237948928, "lowDiskSpace": false
}
```

- Unknown values: the version pairs use `''` (an archive whose
  `package.json` lacks `WordPress.Version` / `PHP.Version`, as a minimal
  one made by another .wpress tool may); every other field below uses
  `null`. Never block on an unknown value: show it as unknown.
- `from` / `to`: the archive's home address and this site's (on a network
  the main site's). `replacesAddress`: `true` when they differ (letter case
  and a trailing slash aside) and the restore rewrites the old address to
  the new one, `false` when they are the same, `null` when the archive
  does not name its address. On a network each site's own move is in
  `network.sites`.
- `wordpress`, `php`, `prefix`: the archive's value and this site's.
  `prefix.archive` is `null` when `package.json` has no `Database.Prefix`.
  The restored tables always get this site's prefix.
- `createdAt`: Unix time the archive was made (the time stamp of its
  `package.json` entry), `null` when unknown.
- `archiveSize`: the archive file's bytes. `expandedSize`: the bytes of its
  files as they are, known only for an archive that is neither compressed
  nor encrypted (`null` otherwise).
- `parts`: what the archive holds, part by part — `database`
  (`database.sql`), `media` (uploads and `blogs.dir`), `plugins`, `themes`,
  `muPlugins`. `included` is whether the archive has entries there: a part
  it lacks is not touched on this site (it is "kept"). `bytes` is what the
  part takes in the archive (compressed or encrypted as stored).
  `count`: media files; plugins (a folder, or a PHP file other than
  `index.php`) without this plugin, which is never written; theme folders;
  must-use PHP files directly in the folder; `null` for the database (its
  tables are not counted without reading the dump). When the headers could
  not be read, `bytes` and `count` are `null` and `included` is `false`
  only for a part `package.json` says was left out (`NoDatabase`,
  `NoMedia`, `NoPlugins`, `NoThemes`, `NoMustUsePlugins`), `null` otherwise.
- What gets replaced is never read from `parts` alone: a sites or
  single-site archive restored into a network (`network.kind` other than
  `network`) never writes plugins, themes or must-use plugins (the
  network's stay) and its database and media are only the archive's
  sites, and a single-site restore of a network site (`subsite`) brings
  only that site's media (`src/import/parts.js`).
- `keepsThisPlugin`: always `true` — this plugin's folder is never
  overwritten by a restore.
- `overwrites`: the older, flag-only view of the same parts (from
  `package.json`'s `No*` flags), kept for the existing screens.
- `safetyBackup`, `safetyBytes`, `diskNeeded`, `diskFree`, `lowDiskSpace`:
  the safety-backup switch and the disk estimate (`diskNeeded` is twice
  the archive plus the safety backup when it is on; `diskFree` is `null`
  when the host does not tell).
- `subsite`: the network site id a single-site restore of a network
  archive takes, else `null`. `network`: only on a network (the archive's
  sites, their addresses and refusals), see `src/network/`.

The answer is unchanged: `POST /jobs/{id}/input` with `confirm: true`,
optionally `safetyBackup` (boolean) and, on a network, `sites` (index =>
address); an encrypted archive's `password` is asked by its own input
kind (`password`, reason `encrypted` or `wrong`) before this step.

## `GET /backups` — the Backups tab and the restore picker

One scan of the backups folders (`Rest\BackupsApi::listing`):

```json
{
  "items": [ { "id": "own:example-com-20261016-030012-418-abcd1234.wpress", "kind": "scheduled", "links": 1, "…": "…" } ],
  "storage": {
    "ownBytes": 11704000000,
    "otherBytes": 2147000000,
    "free": 44236000000,
    "keep": [
      { "schedule": null, "name": "", "count": 0, "bytes": 0, "days": 0 },
      { "schedule": "abcd1234", "name": "Daily backup", "count": 0, "bytes": 0, "days": 7 }
    ]
  }
}
```

Each item carries, next to `id`, `name`, `folder` (`own | external`),
`size`, `mtime`, `label`, `safety`, `beforeReset` and `cloud`:

- `cloud`: the copy records (`Storage\Cloud\CloudCopies`), `{id, status,
  at}` per destination. Each time this site reads a destination's whole
  listing (`GET /destinations/{id}/files`; a restore, fetch or delete
  there; the retention step after an upload), an `up` record older than the listing
  whose file the listing no longer shows (removed or trashed by hand
  there) is forgotten; `pending` and `failed` records stay. A browser
  listing, a restore or a fetch also records an `up` copy for each listed
  file that a backup here matches by name and size and that has no record
  at that destination yet (a destination added again over its old folder).
  `GET /destinations/{id}/files` answers `copiesChanged: true` when the
  listing changed a record; the backups lists list every connected
  destination once per page load and load themselves again on that answer.

- `kind`: what made it, from the folder and the name only
  (`Backups\BackupKind`): `scheduled` (a schedule id ends its name),
  `manual` (anything else in our folder, a file copied in by hand too),
  `safetyRestore` (taken before a restore, `pre-import-…`), `safetyReset`
  (taken before a reset, `pre-reset-…`), `other` (anything in the other
  .wpress tool's folder, whatever its name). `safety` / `beforeReset` stay
  as they were (`safety` is true for both safety kinds).
- `links`: how many of its transfer links still open — not expired, and
  bound to the file as it is now (`Backups\LiveLinks`: the link's stored
  Fingerprint matches the file's); a link made for a file since replaced
  under the same name is not counted. The list itself (`GET
  /backups/shares`) is unchanged.

And two fields read from the backup's `package.json` only
(`Backups\BackupOrigin`, the first entry, no data):

- `encrypted`: `true` / `false`, `null` when the file cannot be read.
- `fromSite`: the host (with its port) and folder of the site the backup
  was made on, in lower case, when that is not this site (on a network:
  not the main site); `null` for this site's own backups, for an archive
  that names no address, and for a file that cannot be read. The scheme
  does not count: a site that moved to https is the same site.

`storage` (`Rest\BackupStorage`), from the same scan:

- `ownBytes`: the backups in our folder (a name in both of our folders
  during an unfinished move counts once); `otherBytes`: the other .wpress
  tool's folder.
- `free`: free bytes where our backups are kept, `null` when the host does
  not tell.
- `keep`: the rules that delete backups by themselves. Always first the
  manual backups' rules from Settings (`schedule: null`, `name: ""`), then
  one entry per switched-on schedule (`schedule`: its id, `name`: its shown
  title) with its own rules; a switched-off schedule deletes nothing by
  itself and is left out. `count`: keep the newest N, `days`: keep N days,
  `bytes`: keep N bytes in total; `0` = no such limit, all three `0` =
  never deleted (so with no rules and no schedule: "manual backups are
  never deleted"). A schedule's rules touch only that schedule's backups;
  backups taken before a restore or a reset, files without our time stamp
  and the other tool's folder are never deleted.

## `GET /backups/contents?folder=&name=` — what one backup holds

For the chosen backup only (never per row): `Rest\BackupContentsApi`.
Read only; named like every backup route (folder and file name, nothing
outside the listed folders: `404 lw_migration_backup_missing`, also when
the file vanished since the list was read). The parts come from the
entry headers (`Import\ArchiveInventory`, the restore check's walk:
nothing is decoded or extracted):

```json
{
  "parts": {
    "database":  { "included": true, "bytes": 412000000, "count": null },
    "media":     { "included": true, "bytes": 1180000000, "count": 8412 },
    "plugins":   { "included": true, "bytes": 52000000, "count": 14 },
    "themes":    { "included": true, "bytes": 9000000, "count": 2 },
    "muPlugins": { "included": false, "bytes": 0, "count": 0 }
  },
  "encrypted": false,
  "expandedSize": 1653000000,
  "partial": false
}
```

- `bytes`: what the part takes in the archive (compressed or encrypted as
  stored); `count`: media files, plugins, themes, must-use PHP files
  (`null` for the database: counting tables would mean reading the dump).
  This plugin's own folder is not counted, like in the restore check.
- `encrypted`: from `package.json`. `expandedSize`: the files' own size,
  known only for an archive that stores them as they are (not compressed,
  not encrypted), else `null`.
- `partial`: one request reads headers for at most 2 s. A walk that did
  not reach the end answers `true`: every part not left out by
  `package.json` is `{ included: null, bytes: null, count: null }` and
  `expandedSize` is `null`. Ask again (every few seconds, the client waits 2.5 s) while it is
  `true`: the walk is kept and goes on where it stopped.
- `422 lw_migration_not_readable`: the file is no (readable) .wpress
  archive.
- Cached in the option row `lw_migration_backup_contents` (never
  autoloaded) per folder and name, valid only for the file it was read
  from (its Fingerprint: size, mtime, inode); at most 50 backups are kept,
  the oldest go first. A second look at the same file opens nothing.

## Schedules list — `week` and `history`

`GET /schedules` items carry two more fields (additive; the others keep
their shape):

- `week`: `number[]` — Unix times of every run from now to now + 7 days,
  ascending, at most 200, stepped like `upcoming` (`NextRun::within()`, so
  DST behaves the same). `[]` when the schedule is switched off.
- `history`: `{ status, endedAt, trigger }[]` — the last 14 run-log entries,
  oldest first (`[]` for an empty log). `status` is the log's own value
  (`done`, `partial`, `failed`, `skipped`, `cancelled`; a client shows any
  other value as unknown), `trigger` is `cron` or `manual` ("Started by").

`lastRun` also carries `error`: the newest log entry's error text, already
redacted when the run was recorded (`RunRecorder`), `""` when it ended
without one (the same text the run log shows). Additive.
