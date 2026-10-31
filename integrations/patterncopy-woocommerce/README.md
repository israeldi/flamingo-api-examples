# PatternCopy PDF for WooCommerce

Add a visible buyer/order stamp to a supported PDF after WooCommerce authorizes its download. The connector returns a separate personalized copy and keeps supported size layers and original page geometry. It requires a subscribed [PatternCopy PDF RapidAPI app](https://rapidapi.com/IsraelDiego/api/patterncopy-pdf); the connector itself is free.

[Download connector 0.1.1](https://raw.githubusercontent.com/israeldi/flamingo-api-examples/main/integrations/patterncopy-woocommerce/downloads/patterncopy-pdf-0.1.1.zip) · [Browse GPL source](patterncopy-pdf/) · [Layered PDF guide](https://flamingo-api-guides.flatemu1.chatgpt.site/layered-pdf-personalization/) · [Try the Python example](../../patterncopy_stamp.py)

## Check your store's fit

- PHP 8.1+ on a POSIX host, WordPress 6.7+ and WooCommerce 10.3+.
- Simple downloadable products with one to five PDFs in the site's local uploads directory. Remote storage, ZIP bundles and product variations are outside this version.
- Each source is at most 20 MiB and 100 pages; each output is at most 24 MiB. The final stamp is 1–160 characters that fit the page.
- A writable private cache outside every public web root. The default is a dedicated site-specific directory under PHP's system temporary directory.

Visible personalization is editable traceability, not DRM. Check your own PDF's size toggles, print scale and stamp placement before using it for fulfillment.

## Install and make a staging trial

1. Download the [installable ZIP](https://raw.githubusercontent.com/israeldi/flamingo-api-examples/main/integrations/patterncopy-woocommerce/downloads/patterncopy-pdf-0.1.1.zip). In WordPress choose **Plugins → Add New → Upload Plugin**, upload it and activate it with WooCommerce active. Upload this ZIP rather than the entire examples repository.
2. Subscribe to the API plan appropriate for your trial. In **WooCommerce → PatternCopy PDF**, keep RapidAPI authentication selected and configure:

   | Setting | Value |
   | --- | --- |
   | Base URL | `https://patterncopy-pdf.p.rapidapi.com` |
   | RapidAPI host | `patterncopy-pdf.p.rapidapi.com` |
   | API key | Your subscribed RapidAPI app key |

3. Authorize external processing of the source PDF and selected buyer/order text. Review [PatternCopy privacy](https://flamingoapis.synology.me:8443/privacy), [service terms](https://flamingoapis.synology.me:8443/terms) and [RapidAPI privacy](https://rapidapi.com/page/privacy), and include this processing in your buyer information.
4. Edit a simple downloadable product with a local PDF. Choose a literal stamp template using `{name}`, `{email}`, `{order}` or `{quantity}`. Select **Run compatibility checks**, enable **Require a personalized PDF**, and save. Confirm that the check succeeded for the current source and connection.
5. Place a staging test order and use its ordinary authorized WooCommerce download link. Inspect the returned stamp, layer controls and printed scale before offering the product to customers.

Protect original PDFs using your store's download-directory/web-server controls. The connector cannot hide an original file that already has a public uploads URL. Use an empty dedicated directory if configuring `PATTERNCOPY_PRIVATE_DIR`; it must be outside every public web root. See the [plugin readme](patterncopy-pdf/readme.txt) for cache ownership and configuration details.

## Processing and delivery

Compatibility inspection and stamping are separate processing calls. Check the current API listing for request, processing-resource and bandwidth limits. A response or network failure can consume API resources even when WooCommerce delivery is blocked. There are no automatic API retries.

Personalization occurs after WooCommerce's download authorization. Invalid links and failed preparation block delivery before the WooCommerce allowance is decremented; the original is not used as a fallback. Later network failures after a prepared stream follow normal WooCommerce tracking behavior. Changing a source, connection, credentials or connector version requires a new compatibility check. Deactivating the plugin restores ordinary WooCommerce delivery.

Copies are cached privately for seven days with a default 256 MiB cap. Expired or evicted copies need processing again. Scheduled cleanup depends on WordPress cron running. The cache is specific to source, buyer/order identity and options.

## Version and license

This is an unchanged mirror of the existing public connector **0.1.1**, including its original installable ZIP and six production files. The [release record](release-0.1.1.json) lists their SHA-256 hashes and the scope of existing checks. No additional compatibility testing is claimed by this mirror; test your store and documents on staging.

The connector is [GPL-2.0-or-later](patterncopy-pdf/LICENSE). This license applies to this plugin; it does not change licenses elsewhere in the repository.
