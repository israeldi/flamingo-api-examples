=== PatternCopy PDF for WooCommerce ===
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: woocommerce, pdf, watermark, sewing patterns, digital downloads

Personalize authorized PDF downloads through a configured processing API, with compatibility checks and private reuse.

== Description ==

PatternCopy PDF adds buyer/order text to supported PDFs through a PatternCopy-compatible service. It integrates with the standard WooCommerce download permission checks and blocks delivery when personalization fails. Simple downloadable products opt in individually after their current source PDFs pass a compatibility check.

This version supports up to five local PDFs per simple product, 20 MiB and 100 pages per PDF. External storage URLs, ZIP bundles and product variations are not supported. The processing service must preserve the PDF features your business needs; inspect and test your own patterns before offering them to customers.

== External service ==

This plugin uses PatternCopy. Publisher: IsraelDiego. API access, subscriptions and billing are handled through RapidAPI. The plugin does not create an account or purchase a plan. Check the listing for current plan availability; this package does not establish that a public subscription path is enabled.

Service website: https://flamingoapis.synology.me:8443/

RapidAPI listing and publisher support: https://rapidapi.com/IsraelDiego/api/patterncopy-pdf

PatternCopy privacy: https://flamingoapis.synology.me:8443/privacy

PatternCopy service terms: https://flamingoapis.synology.me:8443/terms

RapidAPI privacy: https://rapidapi.com/page/privacy

RapidAPI terms: https://rapidapi.com/page/terms

With administrator authorization, raw product PDF bytes are sent to /v1/inspect during explicit compatibility checks. After WooCommerce authorizes a customer download, /v1/stamp receives raw PDF bytes and the chosen stamp text. Text may contain the customer's name/email, order number and purchased quantity, depending on the configured template. The service receives its authentication key in a request header. Redirects are not followed. No product source URL is fetched by this plugin.

The connector continues to send canonical application/pdf requests. The RapidAPI playground uses application/octet-stream for its binary-file upload; the same PDF bytes, authentication, parser and limits apply. Neither is a multipart upload.

RapidAPI may retain headers and request/response bodies in analytics logs; its documentation describes up to 30 days of raw-log access. Exact binary capture here is unverified. See https://docs.rapidapi.com/docs/provider-analytics and https://rapidapi.com/page/privacy .

Original files remain unchanged. Processed copies are cached privately outside the web root, by default for seven days with a 256 MiB limit. WordPress cron and processing requests perform cleanup; cron must run for scheduled deletion. Cache filenames contain no customer identifiers. Stored API keys are never redisplayed. The plugin adds no customer-data or credential logging.

API calls occur only after an explicit capability/nonce-protected admin check or an authorized WooCommerce download. Invalid links and failed personalization do not consume a WooCommerce download allowance. Customer/network interruptions after successful preparation follow WooCommerce's own tracking behavior.

API resources count completed processing before response transmission. A delivery failure can therefore count at the API even when no valid copy reached the store and its WooCommerce allowance stays unchanged. A later request may count again if no valid private copy was cached. There is no automatic API retry. Requests have a bounded 110-second timeout. Successful stamp responses require a lowercase hexadecimal X-PatternCopy-SHA256 matching the received PDF bytes; missing or invalid digests block delivery.

Backend successful-call, page and input-byte caps reset by UTC calendar month and aggregate by gateway user across app keys and plan changes. Inspect and stamp each consume the full input's resources. RapidAPI Requests, its billing cycle and any separate platform bandwidth charges are independent; consult the selected plan and service terms.

== Installation ==

1. Download https://flamingoapis.synology.me:8443/downloads/patterncopy-pdf-0.1.1.zip and upload it through WordPress Plugins > Add New > Upload Plugin. Activate with WooCommerce active.
2. Check the RapidAPI listing for plan availability. In WooCommerce > PatternCopy PDF, set base URL https://patterncopy-pdf.p.rapidapi.com and RapidAPI host patterncopy-pdf.p.rapidapi.com, enter your subscribed RapidAPI app key, and authorize the data processing. Use this gateway URL, not the service website origin. RapidAPI is the default authentication mode.
3. Edit a simple downloadable product, choose a template, run compatibility checks on save, and opt in personalized delivery.
4. Test an actual customer download and confirm required layers/navigation remain functional.

Requires a POSIX PHP host with a dedicated private writable temporary directory. A custom cache directory must be empty on first use; a site/path-bound ownership marker protects unrelated directories from cleanup. Protect original source files with your existing WooCommerce/web-server download-directory configuration. This plugin cannot protect an original PDF already exposed at a public uploads URL.

== Frequently Asked Questions ==

= What happens on a processing error? =
The opted-in download is blocked before tracking/decrement. The original is never used as a fallback. An administrator can explicitly opt out a product to restore ordinary WooCommerce delivery.

= Why does changing a file require a new check? =
Checks are tied to source bytes and the configured connection. An older check cannot establish compatibility for a different document or service.

= Does it support first-time range requests? =
A full GET must first prepare the copy. Later single-range requests can reuse the same cached copy. HEAD and first-time range probes do not initiate processing.

== Changelog ==

= 0.1.1 =
* Require and verify the stamped response digest; reject missing, malformed or mismatched hashes.
* Allow a bounded 110-second request budget and clarify processing-versus-delivery counters; no automatic retries.

= 0.1.0 =
* Initial bounded connector with authorized downloads, source preflight, private cache and direct/RapidAPI transport.
