=== ShelfRelay for WooCommerce ===
Tags: woocommerce, onix, books, catalog, import
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Preview and schedule supported ONIX book-feed updates for your existing WooCommerce store using ShelfRelay's paid normalization API.

== Description ==

Keep an existing bookstore current from a supplier feed you are authorized to use. The free connector owns the local queue, source history and product writes. ShelfRelay's external service interprets supported ONIX records, merges source blocks ephemerally and returns a documented, typed product projection.

**Supported storefront:** physical editions, one ISBN per simple WooCommerce product, one source authority and one explicit market/currency/supplier policy. ONIX 3.0 and 3.1, reference and short tags. Up to 5,000 retained source records, including a current feed's records and required date refreshes, and 25 MiB of plain XML per feed. Each product must fit 256 KiB. API calls contain up to 100 records, bounded by total bytes.

**The connector is free; the external service requires a ShelfRelay subscription/key through RapidAPI.** It has no independent local semantic-normalization mode. Check the current listing's hard limits, price, terms and privacy before subscribing. A failed or exhausted service request pauses work without advancing its source checkpoint.

Requests are split using actual JSON-encoded current/prior records, policy and envelope bytes. Each request stays within the API's 8 MiB limit; response JSON is accepted up to 32 MiB. Oversized, incomplete or truncated responses stop the saved batch before any record from that response is applied or checkpointed. A service may already have processed that call, so a retry can consume additional allowance.

Large responses are decoded in PHP memory along with WordPress and other plugins. A verified 60-record preview with 95 KB descriptions produced a 17.3 MB response and reached about 133 MiB of allocated PHP heap on a fixture configured for 512 MiB. A 128 MiB PHP limit cannot support that measured workload. Provide sufficient hosting headroom; 512 MiB is recommended for heavy batches. Actual requirements depend on the feed and other active plugins; this measurement does not qualify every feed at a lower hosting limit.

The plugin provides:

* An initial preview before product changes, including adoption of existing ISBN/SKU matches.
* HTTPS upload/fetch, Basic or Bearer supplier authentication, and scheduled recurring updates.
* Market, currency, ONIX price type and optional supplier selection. No currency or tax calculation.
* Merchant-owned fields on existing products; feed values initialize new products.
* Durable local source blocks, checkpoints, retry receipts and repeat-safe ISBN matching.
* Field-level updates, safe withdrawals, cover downloads and metadata display.

Product withdrawal drafts a linked product; it never deletes a product. A title absent from a delta is never deleted. Out-of-print and temporarily unavailable are different from record withdrawal. Missing stock counts do not become manufactured quantities.

Feed availability is status-based. If an existing product manages numeric Woo stock, mark stock status merchant-owned or disable numeric stock management before applying feed availability. If a selected saleable supply lacks an active compatible price, the service may pause managed availability with a diagnostic while retaining source state for a later dated-price activation. Merchant-owned price/stock policy is respected.

Grouped print/ebook editions, digital delivery, multiple source reconciliation, arbitrary XPath/custom mappings, ONIX 2.1 and a full publisher metadata hub are outside this connector.

== External service and privacy ==

Service listing and current subscription limits: https://rapidapi.com/IsraelDiego/api/shelfrelay-onix

Service terms: https://shelfrelay.flamingoapis.synology.me:8443/terms

Service privacy: https://shelfrelay.flamingoapis.synology.me:8443/privacy

Setup guide: https://shelfrelay.flamingoapis.synology.me:8443/setup

Do not enable the service-data checkbox until you have the right to process and transmit the feed.

When you create a preview or a scheduled job, product XML (including supplied descriptions, contributor information, identifiers and resource URLs), the previous source XML needed for block updates, and your selected policy are sent over HTTPS to the configured ShelfRelay API through RapidAPI. The API key is sent to RapidAPI in the expected request headers. Read both providers' current terms/privacy before enabling this workflow. The service must provide substantive ONIX interpretation; this is not a license-validation-only connector.

Supplier username/password or Bearer credentials stay in your WordPress database and are used only for the configured supplier HTTPS fetch. They are not sent to ShelfRelay. Saved secrets are encrypted with authenticated AES-GCM using a key derived from the site's WordPress salt. A database administrator who also has the site's salts can decrypt them. If salts change, re-enter saved credentials.

Raw downloads are staged in a permission-restricted system temporary directory outside the WordPress web root. Staged files are removed after successful reading. Record-level job receipts are retained locally for seven days, including previews. Finished applied receipts are abbreviated during queue maintenance instead of keeping repeated copies of your catalog; unapplied/retry records retain their XML. Applied source state remains locally to support future deltas. It is part of your store's backup/data responsibility. Deactivating or uninstalling does not destroy source history, products or credentials. Delete them deliberately if you retire the integration.

Covers are fetched from supplied public HTTPS resource URLs by your WordPress host and stored in its Media Library. A changed cover URL triggers a new download. Replacing image content behind the same unchanged URL requires a merchant refresh; this release does not monitor cover content or redownload covers for price/status-only changes. Those resource hosts receive the request. The NAS service is not a cover/media library. No analytics or marketing tracking is added.

The local connector does not include EDItEUR standards assets or a complete licensed subject-classification database. Supplied subject values are preserved as supplied; the plugin does not sell a BISAC/ETIM lookup service or invent labels.

== Installation ==

1. Install and activate WooCommerce. PHP 8.1+, XMLReader, DOM, OpenSSL and normal WordPress image/file support are required.
2. Upload the ShelfRelay ZIP through Plugins → Add New → Upload Plugin, then activate it.
3. Open WooCommerce → ShelfRelay. The official gateway https://shelfrelay-onix.p.rapidapi.com is preset; enter your RapidAPI application key.
4. Enter an optional supplier HTTPS URL/authentication, market/currency/price selection and merchant-owned fields. The currency and entered-price tax convention must match WooCommerce.
5. Read the service disclosure, authorize the data transfer and save. Upload a small representative XML or choose Preview HTTPS feed.
6. Review products, prices, availability, proposed fields and diagnostics in Import receipts. Approve supported records to begin store changes.
7. Verify the resulting products. Enable source checks if desired; recurring application only runs after a manual import has completed successfully.

Use public HTTPS on port 443 or 8443. Supplier redirects are not followed, so enter the final URL. Credentials embedded in a URL and private/reserved network destinations are rejected. Plain XML only; no ZIP, gzip upload, DTD or entity declarations.

== Scheduling and recovery ==

WordPress cron is traffic-driven. For reliable timing on a quiet store, configure your hosting provider's cron to trigger WordPress cron. Jobs are durable and can resume across requests; viewing an active receipt also advances the queue. Pausing page refresh does not cancel the saved queue. Cancel remaining work stops subsequent records; already saved products remain saved.

Each feed is fully staged and syntax-checked before normalization or any application. After preview, individual product failures do not silently advance their source state. Retry failed work after correcting an external problem. If the policy or source changes, create a new preview. Rejected/unsupported source records require a corrected feed or a supported profile; the plugin does not silently repair them.

An initial preview approval is required before existing products can be adopted. Newly encountered existing SKUs in a later scheduled feed require a fresh manual preview rather than automatic adoption.

The service must be called again on recurring runs even if the feed's bytes have not changed: an effective price or status can change with the date. Store writes may be skipped only after a current service decision matches the applied state.

On previews and scheduled runs, retained source records absent from the current feed are also checked if their last successful evaluation was on an earlier UTC date or your selected policy has changed. These rows are labeled **Retained source · date check** and consume real service record/call allowances. This handles dated prices even when a product disappears from later changed-product feeds; omission never means withdrawal. The latest returned service-usage snapshot is shown in the receipt.

Each preview has an explicit UTC price date. If the date changes before application, the records are normalized again. A manual preview requires approval again after that refresh.

== Frequently Asked Questions ==

= Does the plugin infer stock quantities? =
No. It uses supported supplied availability status. Numeric quantity remains merchant-controlled.

= Are merchant edits preserved? =
Mark the fields you own before previewing. Those fields remain untouched on existing products. Unchecked fields are feed-owned and may be updated or explicitly cleared under the documented projection.

= How do I refresh a cover replaced behind the same URL? =
Upload the refreshed cover in WordPress and assign it as the product image. Mark Cover merchant-owned if you want to keep that manual choice even when a later feed supplies a different cover URL. This release refreshes supplied covers on URL changes, not remote content monitoring.

= Can I switch suppliers? =
This release has one source authority. Treat a change of authority as a migration: preserve your backup/history and review a new preview. Multiple suppliers are not automatically merged.

= Is this a Shopify connector? =
No. This release applies to WooCommerce. It does not create a public Shopify app or bypass Shopify distribution requirements.

= Does uninstalling delete my catalog? =
No. Products, cover attachments, source history and saved settings are retained. Export/back up and deliberately remove them when retiring the integration.

== Changelog ==

= 0.1.2 =
* Keep downloaded XML inside the verified private staging directory, with owner-only permissions and explicit path, symlink and server-root checks.
* Use WordPress filesystem and escaping helpers where appropriate while preserving private upload handling and exact credential validation.

= 0.1.1 =
* Match the API's 32 MiB decoded JSON response bound, detect oversized/truncated responses, and preserve unapplied source checkpoints on failure.
* Count actual JSON-encoded records, prior state, policy and envelope when splitting requests; retain the supported record/profile limits.

= 0.1.0 =
* Initial supported ONIX 3.x preview, source-state merge, scheduled update and receipt workflow.
