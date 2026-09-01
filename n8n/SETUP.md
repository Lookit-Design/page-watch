# Lookit Page Watch — capture service setup

The plugin does not take screenshots. It posts a URL to n8n and stores the image that comes back. There are two ways to produce that image.

## Option A — mShots (no server setup, start here)

`lookit-page-watch-capture-v2.json` ships set to this. It calls WordPress.com's public mShots renderer, so there is nothing to install.

1. Import `lookit-page-watch-capture-v2.json` into n8n
2. Generate a token with `openssl rand -hex 32`, then set `shared_token` in the **Config** node
3. Set `allowed_hosts` to the exact hosts Page Watch may capture, separated by commas. Use `*.example.com` only when all subdomains are trusted
4. Leave `provider` as `mshots`
5. Save, activate, copy the production webhook URL
6. In WordPress, paste that URL and the same `shared_token`, save, then Test the capture service

The workflow refuses to run while either configuration placeholder remains.

Limits worth knowing: JPEG rather than PNG, and **it cannot capture a full page at all**. Every mShots image is capped at 1280x960 and shows only the top of the page, so the plugin's whole-page setting has no effect on this provider. The workflow now requests that maximum size rather than a smaller default crop, and the plugin records which provider produced each capture so this is visible rather than mysterious. mShots also caches aggressively per URL, which meant edits made after the first capture were invisible; the workflow now appends a unique `pw=` query argument on every run to force a fresh render. Both mShots requests in a run must use the same busted URL, which is why it is built once in the Config node. The first request for a genuinely new URL can still come back as "still rendering" — run the capture again a minute later.

Good enough to prove the workflow end to end and to demo the email. Not what you ship to clients.

## Option B — Browserless (production)

Full-page PNG, no third-party dependency, no caching.

Browserless must not have network access to loopback, private, link-local or
cloud metadata addresses. The workflow checks the requested hostname against
`allowed_hosts`, but a permitted public site can still redirect its browser.
Enforce the private-network block at the container or host firewall as well.

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

Failure returns `{ "ok": false, "error": "..." }`:

| Status | Meaning |
| --- | --- |
| 401 | The shared token is missing, wrong, or still the placeholder |
| 403 | The token was accepted but the requested hostname is not in `allowed_hosts`, `allowed_hosts` is unset, or the URL is not http/https |
| 502 | The token and hostname were fine but the render failed |

Nothing about `allowed_hosts` is reported until the token has been accepted, so the allowlist cannot be probed by an unauthenticated caller. Provider errors are replaced with fixed messages so Browserless credentials cannot be returned to WordPress.

## Connection test

**Test the capture service** in WordPress sends `{ "mode": "ping", "source": "https://mysite.com/" }`. The workflow checks the token and answers `{ "ok": true, "ping": true, "provider": "..." }` without rendering anything.

A ping deliberately skips the host allowlist. Confirming the endpoint and token should not require this site's own address to be a host you have chosen to capture. Rendering is proved by running a real capture from the watchlist.

## Notes for Vadim

- **Auth.** Shared token in `X-Lookit-Token`, checked before rendering. The body is not accepted as an alternative credential. Replace the placeholder and configure `allowed_hosts` before activation.
- **mShots is prototype only.** Third-party, uncontracted, rate limits undocumented. If it stays past the demo it needs an `== External Services ==` entry naming WordPress.com with their terms and privacy links, since page URLs are sent to Automattic.
- **Image format.** `LPW_Diff` loads via `imagecreatefromstring`, so PNG, JPEG and WebP all compare correctly. `LPW_Media::extension_for()` picks the extension from the returned mime. Do not reintroduce a PNG assumption.
- **Binary reads in n8n.** The Build payload node uses `this.helpers.getBinaryDataBuffer()`, not `binary.data.data`. The latter returns a reference rather than the image when `N8N_DEFAULT_BINARY_DATA_MODE=filesystem`, which caused real screenshots to be misread as placeholders.
- **Media Library storage.** Captures default to real attachments created with the same `wp_insert_attachment` plus `wp_generate_attachment_metadata` pattern Media Master uses. Each carries `_lpw_page_id` and `_lpw_kind` meta; `LPW_Media::remove()` refuses to delete anything without `_lpw_kind`, so it can never touch a client's own media. New DB columns `captures.attachment_id` and `pages.baseline_attachment_id` are added by dbDelta on version change.
- **The 5 second pause** in the mShots branch is a Code node `setTimeout`, deliberately not a Wait node, to avoid execution resume interacting with `responseMode: responseNode`. Plugin timeout is 40s per attempt, workflow cap 180s.
- **Cache busting changes the requested URL.** The `pw=` argument means the rendered page is technically a different URL each run. Harmless on WordPress, and it usefully bypasses page caching too, but worth knowing if a site ever behaves differently with an unrecognised query string.
- **Regional diff.** `LPW_Diff::compare()` returns overall, region and height percentages. The region figure is the worst single cell of a 20px grid over a 200x800 sample, and it is what catches single-block content edits: a hidden paragraph measured 1.69% overall and 60% regional in testing. New column `captures.region_pct`.
- **Prefixing.** Global functions and uninstall variables use the full `lookit_page_watch_` prefix because Plugin Check derives the allowed prefix from the plugin slug and rejects `lpw_`. Class names, constants, hook names, nonce actions, AJAX actions, meta keys and the CSS prefix all still use `LPW_` / `lpw-` and must not be renamed, since those are on the do-not-rename list and live installs depend on them.
- **Nonce checks are inlined** in each `admin_post_` handler rather than sitting behind a shared guard method, because the sniff only recognises a check within the same function scope. Capability checks are inline for the same reason.
- **Idle connection length.** The mShots branch pauses to let the render finish, and the webhook connection back to WordPress is idle for the whole of it. That produced `cURL error 56: unexpected eof` on the WordPress side while the workflow itself reported success. The pause is down to 5 seconds and the plugin now retries transport failures and not-ready responses, up to three attempts with a 3 second gap. Responses carry a `retry` flag so the plugin knows which failures are worth repeating.
- **Uninstall is conditional.** `uninstall.php` returns early when `preserve_on_uninstall` is set, which it is by default. Scheduled events are always cleared. Anything added to the teardown must go below that early return, or it will run when it should not.
- **SSRF.** The workflow requires each requested hostname to match `allowed_hosts`. Browserless also requires a network-level block on private and metadata ranges because a permitted site can redirect Chromium.
- **Refusal reasons are separated.** `Validate request` reports `token`, `url`, `hosts_unset` or `host`. `Token rejected?` routes the first to `Respond unauthorised` (401) and the rest to `Respond refused` (403). Previously every refusal was a 401 and the plugin told people to check their token even when the real cause was the allowlist. The reason is only computed once the token has passed, so keep that ordering if the node is edited.
- **Status codes are static.** Both refusal nodes carry a fixed `responseCode` rather than an expression, which is why there are two of them instead of one.
- **Resource limits.** Width is clamped to 320–1920px and images over 8 MB are rejected by both the workflow and plugin.
- **WP-Cron.** Hourly capture drifts on quiet sites. Document `wp cron event run --due-now` from server cron.
