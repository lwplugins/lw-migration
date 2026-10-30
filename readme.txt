=== LW Migration ===
Contributors: lwplugins
Tags: backup, migration, restore, clone, wpress
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight backup, restore and migration for WordPress, compatible with .wpress archives.

== Description ==

LW Migration backs up, restores and moves WordPress sites. It reads and writes archives in the .wpress archive format, so existing .wpress backups can be restored too. No upsell, no tracking, no external service of ours: everything runs on your own sites, and a backup leaves the server only when you send it somewhere (another site of yours or a cloud destination you add).

* **Back up** the whole site (database, media, plugins, themes, must-use plugins) into one archive. Media, themes, plugins, must-use plugins, the cache folder, spam comments, post revisions, chosen folders and tables or the whole database can be left out. Optional gzip or bzip2 compression, an optional password, and search-and-replace pairs for the exported database.
* **Restore or move** from a file uploaded in the browser, an http(s) address or a backup already on the server. You see a summary before anything is written; addresses and paths are rewritten for the new site, serialized data included (e-mail domains too, when switched on under Advanced); the current database is backed up first by default.
* **Backups:** download, restore, label, browse the files inside and delete; old backups are removed by number, total size or age, never the newest one or the ones taken before a restore or a reset.
* **Scheduled backups:** up to 20 schedules, hourly to monthly in the site's time zone, each with its own retention, a run log and e-mail notifications. A server cron can drive them: `wp lw-migration schedule run --due`.
* **Cloud destinations:** upload backups to Amazon S3 or S3-compatible storage, Google Drive, Dropbox, OneDrive, WebDAV, FTP/FTPS or SFTP from a schedule, the command line or the backups list, with retention per destination; restore, download or delete the backups there.
* **Backups folder, transfer links, multisite networks, connected sites (push and pull) and reset tools**, described below.

Long jobs run in short steps driven by the open admin page (or by WP-CLI), so they finish on hosts with short request time limits and small upload limits. A job whose page was closed is offered again; it is never resumed or cancelled by itself. Requires 64-bit PHP.

The backups can be kept in a folder of your choice (Settings > Backups folder), best one outside the web root. The backups always go into a randomly named subfolder with deny files, made inside the folder you give (for example wp-content itself; other folders below wp-content are refused); a folder chosen in an earlier version is used as it is. Backup files are owner-only (0600); the `lw_migration_backup_file_mode` filter can allow group read (0640). Moving the backups is a resumable job that removes a backup from the old folder only after its copy is checked; scheduled backups wait, and no backup or restore can start until it is done; an unfinished move can be finished or undone. Uninstalling leaves a chosen folder and its backups alone, and a new install does not take it back. Hosts can lock the setting with the `lw_migration_backup_dir_editable` filter; `lw_migration_move_links` turns hard links off for moves. WP-CLI: `wp lw-migration backup-dir show` and `wp lw-migration backup-dir move [<path>] [--default] [--finish] [--undo]`.

A transfer link lets another site restore one backup from its address without a login here: it works for 5 minutes to 7 days, can be revoked, and is shown only once. After a link expires or is revoked, every new request with it is refused; a part already being sent still finishes, so a restore over the link stops at its next part. Security plugins that block anonymous REST API requests block these links as well. WP-CLI: `wp lw-migration backup share <name> [--expires=<duration>]`.

On a multisite network (network-activated, under Network Admin > Settings > Migration) it backs up the whole network or chosen sites, and restores network, site and single-site backups into the network, each site at its own new address. A site backup restored into a network never adds plugin or theme code: only plugins and themes the network already has are used. WP-CLI: `wp lw-migration export --sites=<ids>` and `wp lw-migration import <source> --site-url=<urls>`.

Connected sites (single sites only): pair two of your sites with a one-time code, then send this site to the other one (push) or bring the other one here (pull), directly between the two sites, from Connections or WP-CLI (`wp lw-migration link create-code|add|list|remove`, `wp lw-migration push <id>`, `wp lw-migration pull <id>`). A connection works in one direction, from the site where the code was typed. The site that makes the code chooses what the other site may do: copy it (on by default) and replace its content (only when ticked). Every new connection sends an e-mail to the admins of the site that made the code; its Connections list shows when and from which IP each connection was made.

Reset tools: delete the other plugins, reset the themes to the default one, empty the media library, reset the database to a fresh install, or everything at once. Each tool asks for your password (five wrong tries lock it for 15 minutes), backs up the whole site first unless you switch that off, and never touches LW Migration, must-use plugins, drop-ins, the uploads folder's protection files or the backups. The backup before a reset is labelled and kept until you delete it. A database reset keeps you logged in and keeps LW Migration's connections, schedules, transfer links and settings; connections made by other admins stop working. Tables of another WordPress installation sharing the database are kept; this site's other tables (plugin tables) are removed. The theme reset may download the default theme from wordpress.org; without access a minimal blank theme is written. On a network only plugins, themes and media can be reset in this version. WP-CLI: `wp lw-migration reset <plugins|themes|media|database|full> --user=<admin> [--no-backup] [--yes]`, the password on a terminal or as one line on standard input.

= Cloud storage =

Backups can also be uploaded to S3-compatible storage you configure: Amazon S3, Backblaze B2, Wasabi, Cloudflare R2, DigitalOcean Spaces, Hetzner Object Storage, Scaleway Object Storage, or any S3-compatible server such as MinIO. It can also upload to a WebDAV server (Nextcloud gets backups in resumable parts; other WebDAV servers get each backup in one request, so backups over 200 MiB go there from the command line), to an FTP or FTPS server, and to an SFTP server with a password or a private key. Google Drive, Dropbox and OneDrive connect through the LW app without registering an app: choose Save and connect, confirm your site on the sign-in page and allow the access at the provider. The sign-in and the token refreshes pass through the LW sign-in service (auth.lwplugins.com), which keeps no tokens and no account data; your backups never go there. As an advanced option, "Use your own app" connects through an app you register at the provider yourself: the destination screen lists the steps and the address to register, and you enter the app's Client ID and secret. Nothing is sent anywhere until you add a destination. Then LW Migration sends only your backup archives (and, when you test a destination, a small text file it deletes right after) directly from your server to the storage you chose. Each provider's own terms and privacy policy apply to what you store there; the External services section below lists what each hosted service receives and when.

Add destinations under Destinations (or `wp lw-migration destination add`), then pick them in a schedule, tick them on the Backup form, upload a backup from the Backups list, or run `wp lw-migration export --to=<ids>`. Each destination can keep its own number, total size and age limits; it removes only backups this site made there, never other files, never a backup taken before a restore or a reset. With "Keep the local copy" off, the backup on the server is deleted only after every upload succeeded. A destination's backups can be listed, restored, downloaded into the Backups list or deleted. S3 and WebDAV storage need the PHP cURL and SimpleXML extensions, FTP needs the PHP FTP extension, SFTP works with any PHP; give each site its own folder in a shared storage (the form in the Destinations section starts a new destination's folder as the site's name); on a shared Google Drive, Dropbox or OneDrive account each site has its own folder and the sites do not list or prune each other's backups, but only separate accounts keep a site's administrator away from the other folders. Known limits are listed in the README on GitHub.

= Third-party code =

SFTP support bundles a trimmed copy of phpseclib 3.0.57 (https://github.com/phpseclib/phpseclib) and constant_time_encoding 3.1.3 (https://github.com/paragonie/constant_time_encoding), both under the MIT license, with their namespaces changed so they cannot clash with other plugins. They are in includes/Vendor/ together with their license files.

= Credits =

Icons from Font Awesome Free (https://fontawesome.com), CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/), and the Backblaze, Wasabi and Hetzner marks from Simple Icons (https://simpleicons.org), CC0 1.0. Brand marks belong to their owners.

== Installation ==

1. Upload the plugin through Plugins > Add New > Upload Plugin, or install it with Composer: `composer require lwplugins/lw-migration`.
2. Activate it (network-activate it on a multisite network).
3. Open LW Plugins > Migration (on a network: Network Admin > Settings > Migration).

Backups are stored in a protected folder under wp-content by default; Settings > Backups folder moves them elsewhere.

== Frequently Asked Questions ==

= Can it restore my existing .wpress backups? =

Yes. Upload the file, give its http(s) address, or restore a file on the server with `wp lw-migration import <path>`. When the site still has an older .wpress backups folder, its backups appear in the Backups list too. Encrypted and compressed archives are supported, and the backups LW Migration makes use the same format.

= Does the password make a backup safe to store anywhere? =

It protects confidentiality only. The archive is encrypted in the .wpress reference format, which derives a 128-bit key from the password with a single unsalted SHA-1 pass and has no authentication code, so a short password can be guessed quickly offline, and a changed file is not detected cryptographically (only by a checksum, which guards against damage, not against tampering). Use a long, random password and keep backups on storage you trust.

= Where are the backups stored, and can they be downloaded by anyone? =

In wp-content/lw-migration-<random>/backups/, a folder with its own protection files, or in the folder you choose under Settings. A backup is never served from a plain address: downloads use short-lived links bound to your login, and a transfer link works only until it expires or is revoked.

= How are my cloud credentials stored? =

The secret key, password, private key or passphrase, an app's client secret and a connected account's tokens are encrypted on your site with a key derived from your WordPress security keys, never shown again, never sent to the browser and never written into a backup. If the security keys change, the destination asks for the credentials again.

= Why does SFTP ask me to confirm a key? =

The first connection shows the fingerprint of the server's host key. Compare it on the server (ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub, or the ecdsa or rsa key file when that type is shown) and confirm it; afterwards LW Migration sends your password or key only to a server that shows the same key. If the key changes, uploads stop until you check and confirm it again.

= Is FTPS safe? =

FTPS encrypts the connection and checks the server's certificate and name before the password is sent. A certificate that no certificate authority vouches for (a self-signed one, say) is shown at the first connection check with its SHA-256 fingerprint: compare it on the server (openssl x509 -noout -fingerprint -sha256 -in and the certificate file) and confirm it; afterwards LW Migration sends your password only to a server that shows the same certificate. Plain FTP sends the password and the backups unencrypted.

= What happens if one cloud destination fails? =

The others still get the backup. The run is reported as partly done (in the run log and, if you asked for it, by e-mail), and the backup stays on your server even when "Keep the local copy" is off.

= What happens to the backups when I delete the plugin? =

Deleting the plugin removes its settings and temporary data, never the backups.

= Does deleting the plugin withdraw its access to Google Drive, Dropbox or OneDrive? =

No: uninstalling sends no request anywhere, it only deletes the stored tokens. Delete the cloud destinations first (Destinations > Delete withdraws the site's access at Dropbox, and at Google with your own app; the LW app's Google access is shared with your other sites and OneDrive has no way to do so, so for those you are shown where to remove it), or remove the app's access in your account: https://myaccount.google.com/connections, https://www.dropbox.com/account/connected_apps, https://account.live.com/consent/Manage (personal Microsoft account) or https://myapps.microsoft.com (work or school account).


= Can a reset be undone? =

Only from its backup. Each reset backs up the whole site first unless you switch that off; restore that backup (Backups, marked "before a reset") to get the site back. Without the backup a reset cannot be undone.

= What does a database reset keep? =

You stay logged in with the same user, password and sessions; LW Migration stays active with its settings, connections, schedules and transfer links; the site address and security keys stay. Every other user, all content and your application passwords go, and other plugins stay on disk but inactive. Another installation sharing this site's users table without the CUSTOM_USER_TABLE constants loses its users.

= How do I connect two sites? =

On the site that receives or gives the content, open Connections, choose what the other site may do and make a pairing code. It works for 15 minutes, once, and is shown only once. Type it on the other site under Connections > Pairing code. From then on that site can send itself there or bring that site over, as allowed. Removing the connection on either site stops it; the site that typed the code tells the other one.

= Does a connection work over plain http? =

Yes, but then the whole site travels unencrypted: anyone on the network between the two sites can read it on the way, including its users and their password hashes. Every answer of the other site is signed and a pulled archive is checked against its signed checksum, so a changed answer is refused, but nothing is hidden. Both screens and WP-CLI warn about it. Use https on both sites where you can.

= Why does a connection stop working? =

Security plugins that block anonymous REST API requests also block connections (allow the `lw-migration/v1/link` routes there). The two server clocks must agree within 5 minutes. A connection stops working when the security keys (auth salt) of either site change: pair the sites again. A site behind a proxy or reachable under another name can give its address with the `lw_migration_link_address` filter. A pairing code that names a site by an IP address (such as a local test site) is accepted only with the `lw_migration_allow_ip_link_hosts` filter. When the sending site stops driving a push or pull and the other site makes no progress for an hour, the run is given up. A job left on the other site can be cancelled there, and once the sending site let go of it (or has not moved it for half an hour) that site's screen offers Continue here to finish it.

= Do I need my own Google, Dropbox or Microsoft app? =

No. By default the destination connects through the LW app: choose Connect and sign in at the provider. The account's tokens are stored encrypted on your site, and your backups go directly from your server to your storage. If you would rather not have the sign-in pass through the LW sign-in service, switch on "Use your own app": register an app once (the destination screen lists the steps and the address to register), enter its Client ID and secret, and connect. For Google, publish your own app ("In production"): an app left in Testing loses its connection after 7 days.

== External services ==

LW Migration sends data to an outside service when an administrator adds a cloud destination and uses it, and it reads the list of LW plugins from GitHub for the LW Plugins overview page, as listed below. Other than that it contacts only addresses an administrator gives it (another site of theirs, a backup's http(s) address, their own WebDAV, FTP, SFTP or S3-compatible server) and, for the theme reset, wordpress.org to download the default theme. A Google Drive, Dropbox or OneDrive share link given as a backup's address is downloaded from that provider's download address (drive.google.com, www.dropbox.com, api.onedrive.com); only that link goes there. Apart from the LW sign-in service (below), used only to connect Google Drive, Dropbox or OneDrive through the LW app, nothing is sent to the plugin's authors.

= LW Plugins list (GitHub, Inc.) =

Used to show which LW plugins exist on the LW Plugins overview page in the admin.

* When: when an administrator opens that page or activates a plugin from it, at most once every 12 hours (the list is kept for 12 hours; without an answer within 5 seconds a list bundled with the plugin is shown).
* What: a plain request for one public file (raw.githubusercontent.com/lwplugins/registry/main/plugins.json). Nothing is sent except what WordPress sends with every outgoing request: the site's IP address and a user agent naming the WordPress version and the site's address.
* Terms of service: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* Privacy policy: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

= LW sign-in service (GoBird OÜ) =

Used when a Google Drive, Dropbox or OneDrive destination connects through the LW app (the default; not with "Use your own app"), so no app has to be registered at the provider. The service holds the LW app's client secrets and keeps no tokens and no account data. It is operated by GoBird OÜ (registry code 17311365, Sepapaja tn 6, Lasnamäe linnaosa, 15551 Tallinn, Harju maakond, Estonia; privacy@gobird.io).

* When: when the administrator connects the destination (the browser goes through auth.lwplugins.com, which shows a page naming the site being connected and continues to the provider's sign-in only after the administrator confirms, then back to the site, and the site exchanges the sign-in code there), and whenever the site refreshes the destination's access token while the destination is used.
* What: the provider's name, the site's callback address with its one-time sign-in state, the PKCE challenge and later its verifier, the authorization code and the refresh token (on a refresh). On the sign-in steps the administrator's browser makes the requests, so the service sees the browser's IP address and what a browser sends with every request; on the code exchange and the refreshes it sees the site's IP address. The answers carry the access and refresh tokens, which the site stores encrypted, and for OneDrive also the sign-in (id) token naming the account. Backups and file names never go there. The service keeps no database, no tokens and no account data; its log, kept for 30 days, holds the time, the step, the provider, the outcome, the status, the response time and a keyed hash of the IP address (never the address itself), and a per-address request counter is held in memory for 10 minutes.
* Terms of service: https://auth.lwplugins.com/terms
* Privacy policy: https://auth.lwplugins.com/privacy

= Google Drive (Google LLC) =

Used to store backups in a folder named after the site, inside an "LW Migration" folder of the administrator's own Google Drive, through the LW app or the administrator's own Google app.

* When: when the administrator connects the destination (sign-in at accounts.google.com; with the own app also the token requests to oauth2.googleapis.com, which go through the LW sign-in service for the LW app), and when a backup is uploaded, listed, downloaded or deleted there, or the connection is checked (www.googleapis.com). With the own app, tokens are also refreshed at oauth2.googleapis.com while the destination is used. With the own app, when the destination is deleted, its Client ID or mode changes, or a new connection replaces the old one, the old refresh token is sent to oauth2.googleapis.com/revoke to withdraw the site's access (never for the LW app, whose access the account's other sites share).
* What: OAuth tokens, the backup archives (.wpress) with their names and sizes, and a small test file; with the own app also its Client ID and client secret. The account's e-mail address or name is read back to show it on the destination. With the LW app the provider also receives the LW sign-in service's signed (not encrypted) sign-in state, which contains the site's callback address.
* Terms of service: https://policies.google.com/terms and https://developers.google.com/terms
* Privacy policy: https://policies.google.com/privacy

= Dropbox (Dropbox, Inc.) =

Used to store backups in a folder named after the site, inside the app folder of the LW app or of the administrator's own Dropbox app.

* When: when the administrator connects the destination (sign-in at www.dropbox.com; with the own app also the token requests to api.dropboxapi.com, which go through the LW sign-in service for the LW app), and when a backup is uploaded, listed, downloaded or deleted there, or the connection is checked (api.dropboxapi.com, content.dropboxapi.com). When the destination is deleted, its app key or mode changes, or a new connection replaces the old one, the old access token is withdrawn at api.dropboxapi.com (refreshed first if it expired, through the LW sign-in service for the LW app).
* What: OAuth tokens (with the own app also its app key and secret), the backup archives with their names and sizes, and a small test file. The account's e-mail address is read back to show it on the destination. With the LW app the provider also receives the LW sign-in service's signed (not encrypted) sign-in state, which contains the site's callback address.
* Terms of service: https://www.dropbox.com/terms
* Privacy policy: https://www.dropbox.com/privacy

= OneDrive (Microsoft Corporation) =

Used to store backups in a folder named after the site, inside the app folder of the administrator's OneDrive, through the LW app or the administrator's own Microsoft Entra app.

* When: when the administrator connects the destination (sign-in at login.microsoftonline.com; with the own app also the token requests there, which go through the LW sign-in service for the LW app), and when a backup is uploaded, listed, downloaded or deleted there, or the connection is checked (graph.microsoft.com and the upload and download addresses it hands out).
* What: OAuth tokens (with the own app also its application (client) ID and client secret), the backup archives with their names and sizes, and a small test file. The account name from the sign-in is shown on the destination. With the LW app the provider also receives the LW sign-in service's signed (not encrypted) sign-in state, which contains the site's callback address.
* Terms of service: https://www.microsoft.com/en-us/servicesagreement
* Privacy policy: https://www.microsoft.com/en-us/privacy/privacystatement

= S3 storage providers =

Used when the administrator adds an S3 destination with one of the hosted providers below, to store backups in the administrator's own bucket there.

* When: when a backup is uploaded, listed, downloaded or deleted there, or the connection is checked (the provider's storage address for the chosen region, for example s3.<region>.amazonaws.com).
* What: the access key ID (the secret key only signs the requests and is not sent), the bucket and folder names, the backup archives with their names and sizes, and a small test file.

Amazon S3 (Amazon Web Services, Inc.):

* Terms of service: https://aws.amazon.com/agreement/ and https://aws.amazon.com/service-terms/
* Privacy policy: https://aws.amazon.com/privacy/

Backblaze B2 (Backblaze, Inc.):

* Terms of service: https://www.backblaze.com/company/policy/terms-of-service
* Privacy policy: https://www.backblaze.com/company/policy/privacy

Wasabi (Wasabi Technologies):

* Terms of service: https://wasabi.com/legal
* Privacy policy: https://wasabi.com/legal/privacy-policy

Cloudflare R2 (Cloudflare, Inc.):

* Terms of service: https://www.cloudflare.com/terms/
* Privacy policy: https://www.cloudflare.com/privacypolicy/

DigitalOcean Spaces (DigitalOcean, LLC):

* Terms of service: https://www.digitalocean.com/legal/terms-of-service-agreement
* Privacy policy: https://www.digitalocean.com/legal/privacy-policy

Hetzner Object Storage (Hetzner Online GmbH):

* Terms of service: https://www.hetzner.com/legal/terms-and-conditions
* Privacy policy: https://www.hetzner.com/legal/privacy-policy

Scaleway Object Storage (Scaleway SAS):

* Terms of service: https://www.scaleway.com/en/contracts/
* Privacy policy: https://www.scaleway.com/en/privacy-policy/

== Changelog ==

= 1.0.2 =
* Change: The Backup section shows "Calculating…" instead of a changing total while the sizes are counted.
* Change: The overview's second backup button is called "Custom backup".
* Change: The Settings page shows where the default backups folder is and says when notification e-mails are sent.
* Change: Backups made from the admin keep the e-mail addresses by default; replacing them can be switched on under Advanced.

= 1.0.1 =
* Fix: Backups no longer stop on hosts that disable PHP's link() or ignore_user_abort() function; files are copied instead.
* Fix: A destination's stored password is only used with the server it was saved for, also when saving the destination fails or a backup runs while it is being edited.
* Fix: Command-line output and the job log show control characters from archive contents and connected sites as visible escapes.
* Update: Development dependencies.

= 1.0.0 =
* New: Back up the whole site into one archive in the .wpress archive format, with optional compression, password, search-and-replace pairs and parts left out.
* New: Restore or move a site from an uploaded file, an http(s) address or a backup on the server, after a summary; addresses, paths and e-mail domains are rewritten and the current database is backed up first by default.
* New: Backups list with download, restore, label, browse and delete, and retention rules by number, total size and age.
* New: Resumable jobs in short steps for hosts with short time limits and small upload limits.
* New: Scheduled backups with their own retention, a run log and e-mail notifications; server cron support with `wp lw-migration schedule run --due`.
* New: Backups folder of your choice, moved with a resumable job that can be finished or undone.
* New: Transfer links to one backup, valid for 5 minutes to 7 days, revocable.
* New: Multisite networks: back up the network or chosen sites, restore network, site and single-site backups into a network.
* New: Connected sites: pair two sites with a one-time code, then push this site there or pull that site here, directly between the two sites.
* New: Cloud destinations on the Backup form: tick where a manual backup should go, and choose whether to keep the local copy (it is deleted only after every upload succeeded); the same as `wp lw-migration export --to=… --[no-]keep-local`, and the destination's retention applies afterwards.
* New: Reset tools: delete plugins, reset themes, empty the media library, reset the database or everything, each behind your password and a backup taken first.
* New: Cloud destinations: upload backups to Amazon S3 and S3-compatible storage (Backblaze B2, Wasabi, Cloudflare R2, DigitalOcean Spaces, Hetzner Object Storage, Scaleway, MinIO and others) from a schedule, from `wp lw-migration export --to=…` or with "Upload…" in the backups list, in resumable parts that survive short time limits. Each destination keeps its own retention and prunes only this site's own backups; one failing destination does not stop the others, and the local copy is deleted (when you choose so) only after every upload succeeded. Backups in the cloud can be listed, restored, downloaded back or deleted. Credentials are stored encrypted and never shown again. Also WebDAV (Nextcloud in resumable parts, any other WebDAV server in one request), FTP and FTPS, and SFTP with a password or a private key and passphrase; an SFTP server's host key is confirmed once and checked before every login.
* New: Google Drive, Dropbox and OneDrive destinations: connect through the LW app without registering an app (the LW sign-in service at auth.lwplugins.com passes the sign-in and the token refreshes and keeps no tokens and no account data; the refresh token stays sealed on the site), or with "Use your own app" through your own app at the provider (with PKCE and a single-use state either way); resumable uploads that ask the provider where to go on, restore, retention, and a mail when an account must be connected again; a passing outage of the sign-in service never asks for a new connection. Dropbox and OneDrive never overwrite a file of the same name.
* New: A cloud upload part whose request dies twice stops only that destination, also when a passing failure came between the two deaths; the other destinations still get the backup.
* New: Overview: the admin screen opens on a home page with the newest backup, the days with a backup among the last 14, a note when the last scheduled backup failed, an upload failed, the newest backup is late or a job is running, what needs attention, quick actions (back up now starts the backup on the page), a 30-day history, the cloud destinations, the next scheduled run and the connected sites.
* New: WP-CLI commands for every feature and a Hungarian translation.
* Change: Backup section: a bar shows what the backup is made of and each choice shows its size, measured with the export's own filters; sizes read "at least" while counting and "could not be counted" when it gives up; spam comments and revisions are shown as counts; after a restore or a reset the site is measured anew. Cache folders are left out by default in the web form, the Overview and new schedules; the command line includes them unless `--exclude-cache` is given.
* Change: Restore section: three steps (Source, Check, Restore) with four sources and a check that sets the archive beside this site, read from its headers without extracting anything; facts an archive does not tell are shown as unknown and never block the restore. "What gets replaced" follows network and subsite restores, compressed and encrypted archives show their stored sizes, the safety backup switch says why it cannot be on when the free space is short, the importing administrator's login is kept, and "Restore now" waits for the typed address of the site that gets replaced. The backups list tags encrypted backups and backups made on another site.
* Change: Backups section: a storage strip (this plugin's backups, the other .wpress tool's folder, free space and the retention rules), filter chips with counts and a search, a list grouped by day showing where each backup lives, and a panel for the chosen backup with its actions, facts, label, copies per destination, transfer links (create, expiry, revoke) and contents read from the archive's headers for that backup only.
* Change: Schedules section: a ruler of the next 7 days with one lane per schedule, a WP-Cron box with the server command and a Copy button, cards with the next runs, the last 14 results (with the reason a run failed) and what is backed up and where, progress on a running card, Duplicate in the card's menu, three starters when there is no schedule, and an editor that opens as a sheet with a sentence in plain words and a preview of the next runs.
* Change: Running job card: the kind of job, its title, start and running time; the large percentage beside what is happening now; one segment per phase, the current one moving (still under reduced motion); a note to keep the tab open, and Cancel confirmed inside the card. Past the point of no return Cancel is off and the note says why; a stopped job shows when it last made progress, Continue and Cancel.
* Change: Destinations section: one card per destination with its state, where it keeps the backups and its facts; the backups there, editing, deleting and a host key to confirm open inside the card. New destinations are added inline from a shelf grouped by how you get in, as a draft card with a live line of where the backups will go; saving a server or a storage checks its connection on its new card at once. No dialogs.
* Change: Reset section: the five tools are tiles; choosing one shows the layers of the site with what goes and what stays, and beside it the steps before the start (backup, password, the site address) and the one start button. The password is asked for only after you see what goes; another choice starts the steps over (the backup on, nothing typed). No dialogs.
* Change: Backups and Restore lists: a destination added again over its old folder gets its "Also at" marks back, because its listing records the backups it holds byte for byte.
* Change: Admin menu: the Migration entry shows how many backups there are.
* Change: Destinations section: the places to add are lists, one row per place; Backblaze B2, Wasabi, Hetzner and Scaleway show their own marks.
* Change: Destinations: a new destination's folder starts as the site's name, and a Google Drive, Dropbox or OneDrive account keeps this site's backups in its own folder, shown on the card. On a shared account the sites do not list or prune each other's backups; only separate accounts (or the own-app option) keep a site's administrator away from the other folders.
* Fix: Every answer of a connected site is signed with the connection's key and a pulled archive is checked against its signed SHA-256 before anything is imported, so a network attacker on an http connection cannot swap the pulled site (#1).
* Fix: An http connection is warned about on the screens and in WP-CLI: the whole site travels unencrypted, so anyone on the network between the two sites can read it on the way (#1).
* Fix: Connections and imports from an http(s) address never reach link-local addresses (the cloud metadata service), carrier-grade NAT, benchmark, multicast or reserved ranges, and reach loopback and private addresses only where WordPress allows the host; the checked address is pinned for the request and every redirect of an import is checked the same way. A pairing code that names the other site by an IP address is refused unless the `lw_migration_allow_ip_link_hosts` filter allows it (#2).
* Fix: A password, key or secret saved for a FTP, FTPS, SFTP, WebDAV or S3 destination is forgotten when its server changes (host, port or security; the address's scheme, host or port; the storage address, provider or account), so it is never sent to another server and has to be entered again; saving other settings keeps it (#3).
* Fix: FTPS checks the server's certificate and name before the password is sent; a certificate no certificate authority vouches for (a self-signed one) is shown with its SHA-256 fingerprint at the connection check, confirmed once in the admin or with `wp lw-migration destination test <id> --trust-certificate=<fingerprint>`, and only that certificate is accepted afterwards. A confirmed certificate is forgotten when the server changes (#5).
* Fix: Deleting a Dropbox destination or a Google Drive destination with your own app, changing its Client ID, mode or sign-in settings, or connecting it again withdraws the old sign-in at the provider first, so its tokens no longer work anywhere; when that fails, for Google Drive through the LW app (its access is shared by your other sites with the same account) and for OneDrive (which has no way to do so), you are told where to remove the app's access in your account. A sign-in another destination of the site still uses is kept (#6).
* Fix: Connecting a Google Drive, Dropbox or OneDrive destination records which account it is, asked straight at the provider. Through the LW app every refreshed sign-in is asked again at the provider before it is kept; one for another account, or one whose account the provider refuses to name, is not kept and the destination asks to be connected again, so the LW sign-in service cannot switch a destination to another account; while the provider is unavailable nothing is kept and the refresh is tried again later (#12).
* Fix: `wp lw-migration destination connect` prints a link to the plugin's page that works once, within 10 minutes, only for the administrator given with `--user`; the page starts the sign-in in that browser's login session, so a leaked link cannot be used to connect another account (#12).
* Fix: A transfer link is bound to the file it was made for (size, modification time and inode) and dies when the file is another one under the same name (a backups folder moved to another disk keeps its links); deleting a backup (backups list, `wp lw-migration delete`, retention, the local copy removed after a cloud upload) revokes its links (#8).
* Fix: Published backups are owner-only (0600) and the storage folder and the default backups folder 0700, whatever the umask, so other accounts on a shared server cannot read them; downloads go through PHP. A host that needs group read can return 0640 from the `lw_migration_backup_file_mode` filter (anything wider is cut back to 0640). A backup moved into the folder or fetched back from a cloud destination gets the same mode (#4).
* Fix: The folder you choose for the backups only holds them: the backups always go into a new folder inside it with an unguessable name, also where the folder is not recognised as part of the website; a new folder you type is created together with it, and a folder that holds `index.htm`, `index.shtml`, `default.htm`, `default.html`, `default.php` or `.user.ini` counts as served too (#11).
* Fix: An import grows the disk use by at most the free disk space it found before its first extracted byte, minus 256 MiB or 5 % of it, whichever is more; the restored files and the extracted database file count against one total over every request (every byte counts while it is written; the size of a replaced file is given back once the new one is in place, so each file must fit beside the one it replaces), and the import stops with a message before the limit, so an archive that inflates cannot fill the disk. `wp lw-migration extract` stops the same way. When the free space cannot be read, the limit is 50 GiB (filter `lw_migration_max_extract_bytes`) (#9).
* Fix: The database import checks the connection's character set on both sides (`character_set_client` and `character_set_connection`) and that the session sql_mode has neither NO_BACKSLASH_ESCAPES nor ANSI_QUOTES before any statement of the archive runs, and stops otherwise; a view in the archive that reads another database is skipped with a warning (#14).
* Fix: The pairing code and an archive password need not be typed on the command line, where other users of the server see them in the process list and the shell history: `wp lw-migration link add` without `<code>` asks for the code (a hidden prompt on a terminal, otherwise one line on standard input), and `export`, `import`, `extract` and `cloud restore` take `--password-file=<path>` (size-capped, the path is never printed) or, without a terminal, `--password` with the password as one line on standard input; one trailing line ending is cut, an empty password is refused, and `--password` together with `--password-file` is an error. `<code>` and `--password=<value>` also work, with that warning (#7).
* Fix: A storage server's XML answer in UTF-16 or UTF-32, with a NUL byte, with a DOCTYPE or not starting with a tag is refused before it is parsed, so the DOCTYPE and ENTITY guard cannot be bypassed by an encoding trick (#10).
* Fix: Rewriting addresses inside serialized data that is itself serialized again stops at 32 levels and at four times the value's size, searching the nested parts in place: a deeper or larger part is kept exactly as it was (the import names its table and column once in a warning), so a crafted or runaway value cannot exhaust the memory and stall the import.
* Fix: A CREATE TABLE of an archive runs only as one statement: a `;` outside quoted text, other than the one at its end, refuses it.
* Fix: An INSERT of an archive accepts a hex value only with a lower-case `0x` prefix; MySQL reads `0X…` as a name, not a value, so it is refused.
* Fix: A CREATE TABLE of an archive runs only when every column default is a literal, NULL, the current date or time, UUID() or UUID_TO_BIN(UUID()), and its generated columns and CHECK constraints call only pure string, number and JSON functions; any other table is skipped and named in a warning.
* Fix: The build and release workflows run with read-only repository access (only the release job may write), the third-party actions are pinned to a commit, and the failure artifact of the end-to-end tests includes no backup file (#13).
* Fix: The readme, the README, the password help in the Backup form and in the schedules, and `wp lw-migration export --help` say that the archive password protects confidentiality only: use a long, random password, a changed archive is not detected cryptographically, and backups belong on trusted storage (#15).
