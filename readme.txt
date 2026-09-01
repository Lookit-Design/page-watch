=== Lookit Page Watch ===
Contributors: lookitdesign
Tags: screenshots, monitoring, visual regression, email report, scheduled
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.8.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scheduled screenshots of chosen pages, compared against a locked baseline and emailed side by side.

== Description ==

Page Watch takes a screenshot of the pages you choose on a schedule you set, and compares each new capture against a saved baseline image. When something looks different you get an email with the baseline and the new capture side by side, so you can judge the change yourself rather than trusting an automated verdict.

The baseline is deliberately sticky. The first successful capture of a page becomes its baseline, and after that it never changes on its own. Scheduled runs never overwrite it and retention cleanup never deletes it. It is replaced only when someone chooses Set as new baseline.

Features:

* Watchlist of any URLs, on this site or elsewhere
* Capture every 1, 6, 12 or 24 hours, anchored to a time you pick
* A locked baseline image per page, replaced only on request
* Side-by-side comparison in wp-admin, plus capture history
* Whole-page and worst-area difference scoring, each with its own threshold
* HTML digest email at a set time, or after every run, scheduled or manual
* Retention window for old captures, with baselines exempt
* Captures stored as Media Library attachments, or in a private folder if preferred

= How capture works =

WordPress cannot render a page to an image, so this plugin does not try. It sends the URL to a capture workflow you host, and stores the PNG that comes back. There are no third-party API keys in wp-admin.

The bundled n8n workflow (`n8n/lookit-page-watch-capture-v2.json`) receives the request, renders the page, and returns the image as base64. It ships with two providers: a public renderer that needs no setup for testing, and a self-hosted headless browser for production. See `n8n/SETUP.md`.

= Email =

The digest is handed to `wp_mail()`. If FluentSMTP is active it routes the message through whichever connection the site already uses, so there is nothing to configure in this plugin. Without FluentSMTP the message falls back to the server mail function.

== External Services ==

This plugin sends data to a screenshot capture service that you configure and host.

**Capture endpoint (self-hosted n8n)**

* What is sent: the URL of each watched page, the requested capture width, a whole-page flag, the address of this site, and a shared token used to authenticate the request.
* When: on each scheduled capture run, and when Run capture now or Capture is used. Test the capture service sends only the address of this site and the shared token, and receives no image.
* What is received: a PNG image of the requested page, encoded as base64.

No page content, user data, or credentials are transmitted. The endpoint address is set by the site owner under Page Watch, Schedule and email. Because the service is self-hosted, the applicable terms and privacy policy are those of the organisation operating it.

If a hosted screenshot provider is used instead of a self-hosted workflow, that provider's terms and privacy policy apply and should be listed here before distribution.

== Updating ==

There is no need to delete the plugin to install a new version. Go to Plugins, Add New, Upload Plugin, choose the new zip, and WordPress will offer to replace the installed copy. Everything is kept.

If the plugin is deleted rather than replaced, the watchlist and settings are still kept unless the option under Storage has been switched off.

== Installation ==

1. Upload and activate the plugin.
2. Import `n8n/lookit-page-watch-capture-v2.json` into n8n and set the shared token in the Config node.
3. Activate the workflow and copy its production webhook URL.
4. In WordPress, go to Page Watch, Schedule and email. Paste the webhook URL and the same shared token, then save.
5. Use Test the capture service to confirm the connection.
6. Add pages to the watchlist and run a capture. The first capture of each page becomes its baseline.

== Frequently Asked Questions ==

= Why does the plugin need an external service? =

PHP cannot render a web page. A screenshot needs a real browser engine, which shared hosting will not run. Keeping capture on the platform also keeps provider credentials out of WordPress.

= Why did a page get flagged when nothing really changed? =

Rotating sliders, ad slots, and randomised content will trip a low threshold. Raise either threshold under Schedule and email. A carousel usually trips the area threshold rather than the whole-page one.

= Why do I only get the top of the page? =

The capture provider decides this, not the plugin. mShots cannot render full pages at all and caps every image at 1280 by 960. Browserless captures the full page. The capture history shows which provider produced each image.

= A capture failed with a cURL error but the workflow shows success =

The renderer stays silent for several seconds while it works, and an idle connection that long is sometimes dropped by a proxy or firewall between the two servers. The image was produced; the reply never arrived. Captures are now retried, so this should resolve itself. If it happens on every attempt, something between WordPress and n8n is closing long-lived connections and needs looking at directly.

= Does updating lose my settings? =

No. Replacing the plugin with a newer zip keeps everything. Deleting the plugin also keeps everything unless the option under Storage has been unticked, which exists so a real removal is still possible.

= I chose after every capture run but no email arrived =

Check the Last digest line under Email digest. It records what happened on the most recent attempt, including the reason when nothing was sent.

= Why was a change missed? =

Two usual causes. Either the capture provider served a cached render, so the screenshot predates the edit, or the edit was small enough that the whole-page figure stayed under its threshold. The area threshold exists for the second case: a single edited paragraph might be one percent of the page and sixty percent of its own grid square.

= Do the screenshots go into the Media Library? =

Yes by default. Each capture becomes a real attachment, so WordPress handles thumbnails and mime types properly and the images can be opened from the Media screen. On a large watchlist with a frequent schedule that adds up quickly, so the option can be switched off under Storage, which writes captures to a randomly named folder inside uploads instead.

= Does WP-Cron run reliably? =

On low-traffic sites, no. WP-Cron only fires when someone visits. For dependable hourly capture, disable WP-Cron and run `wp cron event run --due-now` from a server cron.

== Changelog ==

= 0.8.5 =
* Image comparisons no longer trigger a deprecated GD cleanup call on PHP 8.5.

= 0.8.4 =
* Test the capture service no longer asks for a screenshot of this site, so it works on installs that only watch other people's pages.
* A refused capture now says whether the shared token or the workflow host allowlist was the problem, instead of always blaming the token.

= 0.8.3 =
* Capture endpoints require HTTPS unless they run on localhost.
* Screenshot responses now have byte, dimension and pixel limits.
* The bundled n8n workflow rejects placeholder credentials and hosts outside its allowlist, clamps capture width, and prevents raw provider errors from exposing credentials.
* Removing a watched page cleans all of its captures, including histories longer than 500 entries.

= 0.8.2 =
* Existing settings are migrated out of WordPress autoload while preserving the capture token.

= 0.8.1 =
* Shared capture token is no longer shown in the settings form. Leave the field blank to keep the saved value.
* Capture responses are accepted only when they decode as PNG, JPEG, or WebP.

= 0.8.0 =
* Deleting the plugin no longer erases its data by default. The watchlist, webhook URL, shared token, recipients and baselines are kept, and a reinstall picks them straight back up. The behaviour can be switched off under Storage when a clean removal is genuinely wanted.

= 0.7.0 =
* Capture requests are retried up to three times when the failure is one that a retry can clear: a dropped connection, a timeout, or a render that was not finished yet. Previously a single transport hiccup was recorded as a failed capture even though the workflow had run successfully.
* The connection test uses the same retry path, so a momentary blip no longer reports the service as broken.
* Errors report how many attempts were made.

= 0.6.1 =
* Table names in queries now use the %i identifier placeholder rather than string interpolation, so the query text is escaped by WordPress instead of being exempted by an annotation.

= 0.6.0 =
* Fixed the after-every-run digest, which previously only fired on the scheduled run. Captures started from the watchlist now send it too.
* The settings screen reports the outcome of the last digest attempt, so an email that was never sent can be told apart from one that went missing.
* Removed the whole-page capture control. mShots cannot honour it and the width note now explains the limit instead. Browserless still captures full pages.

= 0.5.0 =
* Plugin Check pass: all four errors and every warning resolved. Global functions and variables now carry the full plugin prefix, nonce verification sits inside each handler where tooling can see it, direct queries on the plugin's own tables carry justified annotations, and uninstall uses WP_Filesystem.
* Whole-page capture is now reported honestly. The provider that produced each capture is recorded and shown, and a notice appears when full-page capture is requested from a provider that cannot do it.

= 0.4.0 =
* Comparisons now score the screenshot in a grid as well as overall, so editing or hiding a single block of content is caught even though it barely moves the whole-page figure.
* Second threshold added for that regional score, alongside the existing whole-page one. Either can flag a page.
* Comparison sample resolution raised, and the worst area percentage shown in the watchlist, the capture history, and the digest email.

= 0.3.0 =
* Captures are stored in the Media Library as real attachments by default, so WordPress generates thumbnails and the images open from the Media screen. This can be switched off under Storage to keep captures in a private uploads folder instead.
* Baselines are copied into their own attachment when promoted, so retention cleanup can never remove one.
* Removing a page, pruning old captures, and uninstalling all clean up their attachments.
* A capture service reporting a problem on a successful HTTP response now shows its own message rather than a status code.

= 0.2.0 =
* Capture images are no longer assumed to be PNG. JPEG and WebP are stored and compared correctly, so providers that do not return PNG now work.
* Failed captures show the error reported by the capture workflow instead of a bare HTTP status code.
* Disk usage counts every stored image format.

= 0.1.0 =
* First working version: watchlist, locked baselines, scheduled capture through n8n, difference detection, side-by-side comparison screen, HTML digest email.

== Upgrade Notice ==

= 0.8.4 =
Re-import the bundled n8n workflow. The connection test and the refusal messages both depend on the updated version.

= 0.8.3 =
Re-import the bundled n8n workflow, then configure its shared token and allowed hosts before activation.

= 0.8.2 =
Existing settings are removed from autoload without changing their values.

= 0.8.1 =
The capture token is no longer displayed after save. Leave the field blank to keep the current token.

= 0.8.0 =
Settings and watchlist survive deleting the plugin. No need to re-enter the webhook URL and token on every new build.

== Trademarks ==

Lookit is a registered trademark of ZENOVA CORP. n8n and FluentSMTP are trademarks of their respective owners; this plugin is an independent integration and is not affiliated with, sponsored by, or endorsed by either.
