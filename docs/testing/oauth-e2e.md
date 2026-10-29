# OAuth destinations — manual end-to-end check

Run before every release that touches `includes/Storage/Destinations/OAuth`, `GoogleDrive`, `Dropbox`, `OneDrive`, `Rest/OAuth*` or `src/destinations`. Use the team's test accounts at Google, Dropbox and Microsoft (a personal and a work account). The app credentials and the account sign-ins come from the team's password manager; this file names no account, reference or value, and none is ever written into the repository, the release notes or a screenshot.

Site: an https test site with pretty permalinks and a backup of at least 300 MB (several chunks for every provider). Ask the team before deploying over an active plugin on a shared test site. Record the date, the plugin commit and the result of each line in the release notes of the PR.

The automated tests never reach the providers: every provider answer below is taken from the providers' documentation and is checked here for the first time. A line that fails is a bug to fix before the release; a line under "Decisions" that fails names the switch to flip.

## For each provider (Google Drive, Dropbox, OneDrive)

- [ ] The setup steps on the screen match the provider's console today (rename a step if the console changed).
- [ ] The redirect address copies with the button and is accepted by the console as is.
- [ ] *Save and connect* (*Mentés és összekapcsolás*) sends the browser to the provider; consenting brings it back to *Destinations* (*Célhelyek*) with the green notice; the card shows "Connected as …" (*Összekapcsolva: …*).
- [ ] Refusing consent brings it back with the "not allowed" notice; nothing else changed.
- [ ] Reloading the callback address (browser back + forward) shows "already used"; the destination stays connected.
- [ ] *Check connection* (*Kapcsolat ellenőrzése*) passes (test file written, read, deleted — check in the provider's web UI that nothing is left).
- [ ] A manual backup uploads in several chunks; the file appears in the provider's web UI (Google: "LW Migration/<site>"; Dropbox: Apps/<app name>/<site>; OneDrive: Apps/<app name>/<site>; <site> is the folder the card shows) with the exact size.
- [ ] Stop PHP during the upload (restart the PHP-FPM service on the test server, or close the admin page mid-run and kill that one PHP process by its PID): the next tick resumes and the final file is byte-identical (download it into the backups list from *Backups there* (*Mentések ott*) and compare `sha256sum`).
- [ ] *Backups there* (*Mentések ott*) lists it; *Restore* (*Visszaállítás*) restores it into a staging copy.
- [ ] A schedule with this destination and retention 2 prunes only this site's old backups; a hand-copied `.wpress` in the same folder survives (Google Drive does not even list it: the app sees only its own files).
- [ ] Remove the app's access in the account's security settings; run the schedule (cron): the destination turns *Credentials needed* (*Új hitelesítés kell*), the other destinations of the schedule still get the backup, the local copy stays, exactly one reconnect mail arrives; a second run sends no second reconnect mail. *Reconnect* (*Újrakapcsolás*) fixes it.
- [ ] Change the Client ID on the form: the client secret field asks for the new app's secret, saving shows "not connected"; Connect works with the new app.
- [ ] `wp lw-migration destination connect <id> --user=<admin>` prints a link; it works once in the admin's browser within 10 minutes.

## Through the LW app (once the apps are registered and auth.lwplugins.com is live)

The automated E2E (`bin/test-cloud.sh`, service `relay`) runs against a fake relay and a fake Dropbox. Repeat with the real service and every provider; run it again with the Go relay's image in the E2E as soon as it exists (plan M7d, ruling R11).

- [ ] A new destination shows the "Use your own app" switch off and no Client ID, secret, redirect address or steps; *Save and connect* (*Mentés és összekapcsolás*) passes through auth.lwplugins.com to the provider, whose consent screen names the LW app; consenting comes back connected, "Connected as …" (*Összekapcsolva: …*) on the card.
- [ ] Before the provider's sign-in, the service's confirmation page names the site being connected: check that it shows your site's host, and that only *Continue* goes on to the provider.
- [ ] *Cancel* on that confirmation page returns to the site and leaves the destination unconnected; nothing else changed.
- [ ] Refusing consent comes back with the "not allowed" notice; nothing else changed.
- [ ] *Check connection* passes; a manual backup uploads in several chunks; after the access token's lifetime (Google: about an hour) a scheduled run refreshes through the service without a visit.
- [ ] A site on plain permalinks (`?rest_route=`) connects through the LW app for all three providers, OneDrive included (Microsoft sees only the service's address).
- [ ] A site on http, or with the REST API moved, shows our refusal in the panel and keeps *Save and connect* off; nothing is sent to the service.
- [ ] Switching "Use your own app" on, and later off again, on a connected destination shows the disconnect warning; after saving it is not connected; Connect works in the new mode, and the old tokens are gone (the vault holds none of the old grant).
- [ ] OneDrive through the LW app with a work account: note whether the tenant allows the app (publisher verification); a personal account works.
- [ ] Remove the LW app's access in the account's security settings, run the schedule: the destination turns *Credentials needed* (*Új hitelesítés kell*), exactly one reconnect mail; *Reconnect* fixes it.
- [ ] `wp lw-migration destination add dropbox --name=Box` asks nothing; `destination connect <id> --user=<admin>` prints a link that starts with `https://auth.lwplugins.com/v1/authorize`; it works once within 10 minutes.
- [ ] "Use your own app" never touches the service: with it on, the browser goes straight to the provider and the token requests go to the provider's own token address (watch the requests in the browser's network panel and the site's outgoing requests).

## The screen in a real browser

The React tests do not render the destination form in a browser; check by eye (desktop and a phone-width window, light admin colour scheme and one dark one):

- [ ] The redirect address wraps at phone width without pushing the form sideways, and stays selectable.
- [ ] *Copy* (*Másolás*) shows its "Copied" feedback and the clipboard holds the exact address.
- [ ] The callback notice (connected, denied, expired, failed, refused) sits above the destination list, can be dismissed, and is gone after a reload (the two `lwm-…` parameters are removed from the address).
- [ ] An edit that disconnects the account (new Client ID; OneDrive tenant or permission) shows the warning above the buttons, and the primary button reads *Save and connect*.
- [ ] A site address the provider refuses (switch a copy to plain permalinks for OneDrive with a personal-account app) shows the refusal in the panel and keeps Connect disabled.

## Decisions this run settles (plan M7c, UNVERIFIED items)

Google Drive:

- [ ] Google accepts PKCE (`code_challenge`/`code_verifier`) for a Web application client (E4). If not: set `pkce` false in `Provider::google()` and note it.
- [ ] Google chunk PUTs without `Authorization` succeed (E19). If 401/403 (the upload starts over once, then fails as a gone session): set `GoogleDriveDestination::CHUNK_AUTH` true.
- [ ] A Google app with only `drive.file` can be set "In production" without verification, and its refresh token outlives 7 days (connect, wait 8 days, run a backup).
- [ ] The final chunk's 200/201 answer carries the file `id`.
- [ ] A ranged download (`alt=media`) is served by www.googleapis.com itself (no redirect) and answers `206` with `Content-Range: bytes a-b/total`.
- [ ] Delete the site's folder inside "LW Migration" for good (trash, then empty the trash), then run a backup: listing answers 404 or an empty list, the upload finds or creates a new folder (E12: the initiate's 404 for a gone parent), and the backup lands there.
- [ ] Move the site's folder inside "LW Migration" to the trash only (not emptied), then run a backup: note whether the backup lands in a new folder (expected) or in the trashed one (then file a fix).

Dropbox:

- [ ] `users/get_current_account` and the other argument-less calls answer with `null` as the JSON body.
- [ ] Dropbox accepts the redirect address with `?rest_route=` (switch the site to plain permalinks once), and note whether it accepts a bare-IP https host.
- [ ] A ranged download answers `206` with `Content-Range: bytes a-b/total`.
- [ ] Listing the site's folder when it does not exist yet (a fresh app, before the first upload) answers 409 `path/not_found` (the list is empty, not an error).
- [ ] Deleting a file already deleted in the web UI answers `path_lookup/not_found` (the delete counts as done).
- [ ] `get_metadata` returns a `content_hash` for an uploaded backup that equals the one this site computed (the lost-final-answer check accepts it).
- [ ] An `append_v2` with `content_hash` is accepted; a deliberately wrong hash (debugger or a patched copy on the test site only) answers `content_hash_mismatch`.
- [ ] The `error_summary` shapes `lookup_failed/closed/…`, `lookup_failed/not_found/…`, `path/conflict/file/…` and `incorrect_offset/…` look as the code reads them (provoke a conflict by uploading a file of the backup's name by hand first: the upload stops with "another file has this name", the hand-made file is untouched).
- [ ] An `id:` reference in the path works for `delete_v2` and the download.

OneDrive:

- [ ] OneDrive with a **work** account: `Files.ReadWrite.AppFolder` is consented and `/special/approot` works; if not, `Files.ReadWrite` (*Whole OneDrive permission*) works (E13).
- [ ] OneDrive personal and work: the `id_token` carries `preferred_username` (the card shows an address, not "Microsoft account").
- [ ] An expired Microsoft client secret is refused with `invalid_client` (the card says to renew the secret; no reconnect mail).
- [ ] An upload session survives a 30-minute pause between fragments (rolling expiry; note the `expirationDateTime` you saw).
- [ ] A name already taken in the site's folder: note whether `createUploadSession` answers 409 at once or only the last fragment does; either way the upload stops with "another file has this name" and the existing file is untouched.
- [ ] `GET …/special/approot:/<site>/<name>` returns `file.hashes.quickXorHash` right after an upload, on a personal and on a work account (without it a lost final answer fails safe instead of being accepted).
- [ ] `GET <uploadUrl>` after the last fragment completed answers 404/410, or 200 without `nextExpectedRanges` (both lead to the file check).
- [ ] A fragment sent twice (stop PHP right after a fragment, before its answer is read) answers 416 or a status whose `nextExpectedRanges` names the next range; the upload goes on without a gap.
- [ ] The `/content` 302 `Location` is a DNS name on port 443, and it answers a `Range` with `206` and `Content-Range: bytes a-b/total`.
- [ ] An expired download address answers 401/403 (taken as passing: the next read asks for a new one), not 404/410.
- [ ] Item ids with `!` (personal accounts) work unencoded in `/items/{id}` and `/items/{id}/content`.
- [ ] A given-up upload (kill PHP twice while the same fragment is being sent: the cut-off gives the upload up) sends `DELETE <uploadUrl>`, answered `204`, and the unfinished upload is gone (E23); after a lost final answer proved this site's own file, the best-effort `DELETE` of the old `uploadUrl` does no harm (Task 10 m3).
- [ ] Whether a `Files.ReadWrite.AppFolder` token may read `/me/drive` (note it: a Graph drive-owner label would need it; today the label comes from the sign-in).
- [ ] Note the hosts of `uploadUrl` and of the download `Location` for a personal and a work account (any DNS name on 443 is accepted today).
- [ ] When the quota is full or Graph throttles, note the status and error code (`quotaLimitReached` 507, `activityLimitReached` 429/509).

Token endpoints:

- [ ] A revoked or expired refresh token answers `invalid_grant` at Google, Dropbox and Microsoft (the destination turns *Credentials needed*), and a wrong client secret answers `invalid_client` (the card asks to check the secret, no reconnect mail).
- [ ] Note the token-endpoint error codes Google and Dropbox actually send besides `invalid_grant` and `invalid_client` (a wrong redirect address, a wrong or reused code, a disabled app): each should end as our own text on the card, never the provider's.
- [ ] Dropbox's token answer carries `expires_in` and `token_type` `bearer`.

Site setup:

- [ ] With a persistent object cache (a Redis drop-in) on the test site: two browsers answering one callback at once (copy the callback address from the first before it loads) connect at most once; the second shows "already used".
- [ ] wordpress.org accepts the `== External services ==` section of `readme.txt` with its links (plugin review or the readme validator).
