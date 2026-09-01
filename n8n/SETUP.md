# Lookit Page Watch — capture service setup

The plugin does not take screenshots. It posts a URL to n8n and stores the image that comes back. There are two ways to produce that image.

## Option A — mShots (no server setup, start here)

`lookit-page-watch-capture-v2.json` ships set to this. It calls WordPress.com's public mShots renderer, so there is nothing to install.

1. Import `lookit-page-watch-capture-v2.json` into n8n
2. Open the **Config** node, set `shared_token` to a long random string
3. Leave `provider` as `mshots`
4. Save, activate, copy the production webhook URL
5. In WordPress, paste that URL and the same `shared_token`, save, then Test the capture service

Limits worth knowing: JPEG rather than PNG, and **it cannot capture a full page at all**. Every mShots image is capped at 1280x960 and shows only the top of the page, so the plugin's whole-page setting has no effect on this provider. The workflow now requests that maximum size rather than a smaller default crop, and the plugin records which provider produced each capture so this is visible rather than mysterious. mShots also caches aggressively per URL, which meant edits made after the first capture were invisible; the workflow now appends a unique `pw=` query argument on every run to force a fresh render. Both mShots requests in a run must use the same busted URL, which is why it is built once in the Config node. The first request for a genuinely new URL can still come back as "still rendering" — run the capture again a minute later.

Good enough to prove the workflow end to end and to demo the email. Not what you ship to clients.

## Option B — Browserless (production)

Full-page PNG, no third-party dependency, no caching.

Run the container on the n8n host:

```
openssl rand -hex 16
```

```
docker run -d --name browserless --restart unless-stopped --shm-size=2g -p 127.0.0.1:3000:3000 -e "TOKEN=PASTE_TOKEN" -e "CONCURRENT=2" -e "TIMEOUT=60000" ghcr.io/browserless/chromium
```

```
curl "http://127.0.0.1:3000/pressure?token=PASTE_TOKEN"
```

Then in the **Config** node set `provider` to `browserless` and `browserless_url` to one of:

- n8n running directly on the host: `http://127.0.0.1:3000/screenshot?token=PASTE_TOKEN`
- n8n running in Docker: put both containers on one network, then `http://browserless:3000/screenshot?token=PASTE_TOKEN`

Browserless v2 requires a token on every REST call, hence the `?token=` on the end. Bind to `127.0.0.1` rather than `0.0.0.0`: the image exposes a `/function` endpoint that runs arbitrary code and must never be reachable from outside the host.

`EAI_AGAIN` in an execution log always means the hostname in `browserless_url` does not resolve from where n8n is running. Nothing else.

## Request and response

Request body sent by the plugin, with header `X-Lookit-Token: <shared token>`:

```json
{ "url": "https://example.com/about/", "width": 1440, "full_page": true, "source": "https://mysite.com/" }
```

Success:

```json
{ "ok": true, "provider": "mshots", "mime": "image/jpeg", "captured_at": "...", "image_base64": "..." }
```

Failure returns `{ "ok": false, "error": "..." }` with 401 (bad token or URL) or 502 (render failed). The plugin now shows that `error` text verbatim in the admin notice rather than a bare status code.

## Notes for Vadim

- **Auth.** Shared token in `X-Lookit-Token`, checked in the IF node. Still needs to become a per-site bearer before production; same blocker as the other plugins. Not sent as `Authorization` because some Apache/CGI configs strip that header.
- **mShots is prototype only.** Third-party, uncontracted, rate limits undocumented. If it stays past the demo it needs an `== External Services ==` entry naming WordPress.com with their terms and privacy links, since page URLs are sent to Automattic.
- **Image format.** `LPW_Diff` loads via `imagecreatefromstring`, so PNG, JPEG and WebP all compare correctly. `LPW_Media::extension_for()` picks the extension from the returned mime. Do not reintroduce a PNG assumption.
- **Binary reads in n8n.** The Build payload node uses `this.helpers.getBinaryDataBuffer()`, not `binary.data.data`. The latter returns a reference rather than the image when `N8N_DEFAULT_BINARY_DATA_MODE=filesystem`, which caused real screenshots to be misread as placeholders.
- **Media Library storage.** Captures default to real attachments created with the same `wp_insert_attachment` plus `wp_generate_attachment_metadata` pattern Media Master uses. Each carries `_lpw_page_id` and `_lpw_kind` meta; `LPW_Media::remove()` refuses to delete anything without `_lpw_kind`, so it can never touch a client's own media. New DB columns `captures.attachment_id` and `pages.baseline_attachment_id` are added by dbDelta on version change.
- **The 9 second pause** in the mShots branch is a Code node `setTimeout`, deliberately not a Wait node, to avoid execution resume interacting with `responseMode: responseNode`. Plugin timeout is 90s, workflow cap 180s.
- **Cache busting changes the requested URL.** The `pw=` argument means the rendered page is technically a different URL each run. Harmless on WordPress, and it usefully bypasses page caching too, but worth knowing if a site ever behaves differently with an unrecognised query string.
- **Regional diff.** `LPW_Diff::compare()` returns overall, region and height percentages. The region figure is the worst single cell of a 20px grid over a 200x800 sample, and it is what catches single-block content edits: a hidden paragraph measured 1.69% overall and 60% regional in testing. New column `captures.region_pct`.
- **Prefixing.** Global functions and uninstall variables use the full `lookit_page_watch_` prefix because Plugin Check derives the allowed prefix from the plugin slug and rejects `lpw_`. Class names, constants, hook names, nonce actions, AJAX actions, meta keys and the CSS prefix all still use `LPW_` / `lpw-` and must not be renamed, since those are on the do-not-rename list and live installs depend on them.
- **Nonce checks are inlined** in each `admin_post_` handler rather than sitting behind a shared guard method, because the sniff only recognises a check within the same function scope. Capability checks are inline for the same reason.
- **Idle connection length.** The mShots branch pauses to let the render finish, and the webhook connection back to WordPress is idle for the whole of it. That produced `cURL error 56: unexpected eof` on the WordPress side while the workflow itself reported success. The pause is down to 5 seconds and the plugin now retries transport failures and not-ready responses, up to three attempts with a 3 second gap. Responses carry a `retry` flag so the plugin knows which failures are worth repeating.
- **Uninstall is conditional.** `uninstall.php` returns early when `preserve_on_uninstall` is set, which it is by default. Scheduled events are always cleared. Anything added to the teardown must go below that early return, or it will run when it should not.
- **SSRF.** The plugin will POST any URL an admin enters. Capability checked and admin only, but n8n should reject private ranges before handing a URL to a renderer.
- **WP-Cron.** Hourly capture drifts on quiet sites. Document `wp cron event run --due-now` from server cron.
