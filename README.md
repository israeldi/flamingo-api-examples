# File-processing API examples and WooCommerce integrations

Five runnable Python examples and two free WooCommerce connectors for six focused APIs. The Python clients use the standard library, a local input file, and your own subscribed RapidAPI app key. The PatternCopy connector personalizes authorized PDF downloads; the ShelfRelay connector previews supported ONIX book-feed updates before changing store products.

Read the [Flamingo API guides](https://flamingo-api-guides.flatemu1.chatgpt.site/) for the full workflow and compatibility notes. The [machine-readable API index](apis.json) links each listing, customer gateway and workflow resource.

| Task | API | Example or integration |
| --- | --- | --- |
| Export MDB/ACCDB local tables to a CSV ZIP or typed JSON | [Access File Extractor](https://rapidapi.com/IsraelDiego/api/access-file-extractor) | [Python example](access_extract.py) · [Full guide](https://flamingo-api-guides.flatemu1.chatgpt.site/access-to-csv-json/) |
| Optimize PNG/JPEG assets inside an HTML5 advertising ZIP | [CreativePack](https://rapidapi.com/IsraelDiego/api/creativepack) | [Python example](creativepack_optimize.py) · [Full guide](https://flamingo-api-guides.flatemu1.chatgpt.site/html5-ad-zip-optimization/) |
| Add a visible buyer stamp while retaining supported PDF size layers | [PatternCopy PDF](https://rapidapi.com/IsraelDiego/api/patterncopy-pdf) | [Python example](patterncopy_stamp.py) · [WooCommerce connector](integrations/patterncopy-woocommerce/) · [Full guide](https://flamingo-api-guides.flatemu1.chatgpt.site/layered-pdf-personalization/) |
| Preview and apply supported ONIX physical-book updates in WooCommerce | [ShelfRelay ONIX](https://rapidapi.com/IsraelDiego/api/shelfrelay-onix) | [Connector, source and installation](integrations/woocommerce/) · [Full guide](https://flamingo-api-guides.flatemu1.chatgpt.site/onix-to-woocommerce/) |
| Recalculate supported XLSX formula caches and inspect preservation findings | [CalcReceipt](https://rapidapi.com/IsraelDiego/api/calcreceipt) | [Standalone client, sample and contract](calcreceipt/) |
| Check an explicit PDF/A profile and compare before/after failing rules | [ArchiveProof](https://rapidapi.com/IsraelDiego/api/archiveproof) | [Client, original samples, receipts and XML reports](archiveproof/) |

The publisher of these APIs maintains this repository. Free plans are available; check current marketplace quotas and terms. Requests have hard monthly caps, and separate platform bandwidth fees may apply. A validation failure or deliberate retry can consume allowance. The Python examples make one request, refuse redirects, and do not automatically retry. Connector previews, scheduled checks and deliberate retries also consume allowance.

## Check PowerPoint chart data with a standalone skill

[ChartReceipt's quickstart and original fictional example](chartreceipt/) show how to compare supported native PowerPoint chart caches with their embedded XLSX stored values and receive HTML/JSON reports. ChartReceipt is a separate **$9 one-time Agensi skill**. It uses Python 3.10+ in your authorized file-capable agent workspace and needs no RapidAPI key, API subscription, Office installation or pip package. Your agent/provider's permissions, data handling and any execution charges still apply.

## Inspect an ONIX and EPUB delivery example

[EditionReceipt's original fictional example](editionreceipt/) pairs an exported ONIX product with its delivery EPUB and shows the exact HTML/JSON comparison result. The submitted Agensi skill is **awaiting review**, with a proposed **$15 one-time** price. The public example demonstrates a creator-local metadata check and explains its requirements, uncertainty and limits.

## Try one API in Postman

Choose a collection in the [public Postman workspace](https://www.postman.com/idiego612-5459945/flamingo-apis-quickstarts/overview), or download one of the four [collection JSON files](postman/) and import it into your own workspace. Keep your subscribed app key as a local value, leave shared values blank, and send one chosen request. Access and PatternCopy need local selection of the linked synthetic file; CreativePack and ShelfRelay embed their demonstration input.

All four collections were imported into Postman and the public workspace was verified while signed out. The response scripts also passed checks in the official Runtime against synthetic responses. See the [setup and verification notes](postman/README.md) before your first request.

## Use the APIs from your agent

The free [Flamingo File Tools skill on Agensi](https://www.agensi.io/skills/flamingo-file-tools-access-pdf-zip-onix) now includes instructions and self-contained temporary Python clients for Access exports, CreativePack ZIP optimization, PatternCopy inspection/stamping and ShelfRelay previews.

Ask your Agensi-connected agent for the job, for example: “Use Flamingo File Tools to export this authorized Access snapshot to a CSV ZIP. Preserve the original and give me the output and table counts.” The agent loads the selected client through Agensi, runs the authorized operation in your chosen private workspace, delivers the result files and removes the temporary script.

The direct route requires macOS/Linux, Python 3.10+ with a working HTTPS trust store, file/shell/network access, your privately configured `RAPIDAPI_KEY` or explicit private key file, and a separate active subscription for each API you choose. It needs no additional Flamingo MCP server, pip install or separate client download. The skill is free; hosted processing is separately metered, and no API allowance is included with the skill. Reuse existing keys and authorized setup. The included optional setup helper accepts hidden input in your own private Terminal on the same host and OS user as the agent; a laptop key file does not configure a remote agent. Never paste a key into chat. The agent must explain missing prerequisites and obtain authorization for the selected file upload before processing.

For clients that already use local stdio MCP, [Flamingo File Tools MCP](https://github.com/israeldi/flamingo-file-tools-mcp) remains an alternative. Its [version 0.1.0 bundle](https://github.com/israeldi/flamingo-file-tools-mcp/releases/download/v0.1.0/flamingo-file-tools.mcpb) and manual Node.js 20+ setup are unchanged. Start with `capabilities` for a no-key, no-upload check; see the bridge's [compatibility and data-handling notes](https://github.com/israeldi/flamingo-file-tools-mcp#readme).

Both routes use separately subscribed RapidAPI access and the same bounded service contracts. PatternCopy inspection and stamping are separate requests. ShelfRelay produces a projection for review rather than making store changes. Neither route automatically retries a processing call.

## Set up the Python examples

Use Python 3.10 or newer with a configured HTTPS trust store. Download or clone this repository, open a Bash or zsh terminal in its directory, and subscribe to the relevant API on RapidAPI. Enter your own app key without putting it in a command argument:

```sh
printf 'RapidAPI key: '
read -rs RAPIDAPI_KEY
export RAPIDAPI_KEY
printf '\n'
```

The key remains in your shell environment. Keep it out of browser code, source control, screenshots, and shared history. The source file is uploaded through RapidAPI when you run a script. Use the included synthetic samples for your first request; read the relevant listing’s processing and privacy information before uploading sensitive files.

If Python reports a certificate error, configure its trusted certificate store. Keep HTTPS certificate verification enabled.

<a id="choose-your-workflow"></a>

## Export Access tables to CSV or JSON

```sh
python3 access_extract.py sample-access.accdb --format csv --output tables.zip
```

To choose JSON instead:

```sh
python3 access_extract.py sample-access.accdb --format json --output tables.json
```

The CSV ZIP includes `manifest.json` mapping original table names to numbered CSV members. JSON includes a manifest and ordered row arrays aligned with column definitions. Exact decimal, currency, and large-integer values use strings; null and empty text stay distinct in JSON. Local date/time values have no invented timezone.

The example checks output structure and manifest consistency without extracting ZIP members. Input is limited to 40 MiB; serialized output to 32 MiB. The service handles supported local tables from a healthy snapshot, not forms, reports, macros, saved-query execution, linked-data resolution, or application migration. Encryption and several complex column types are unsupported; consult the listing before choosing a source.

The owned `sample-access.accdb` is an ACCDB V2010 file containing one `DemoInventory` table with two artificial inventory rows. It contains no personal or customer records.

## Reduce an HTML5 advertising ZIP’s image payload

```sh
python3 creativepack_optimize.py sample-creative.zip --budget-bytes 150000 --mode lossless --output optimized.zip --report report.json
```

Set the byte target to your actual delivery destination’s limit. `150000` is an example, not a universal advertising requirement. The script verifies returned archive and member hashes, byte counts, paths, and the report before saving two new files.

`lossless` checks eligible PNG/JPEG changes against decoded pixels. Explicit `--mode balanced` permits image-color changes; review the artwork before shipping. Nonimage member bytes, dimensions, formats, and paths are preserved. A whole-ZIP result never grows but may remain `over_budget`; already-optimized assets, fonts, or code can make the target unattainable. A size result is not ad-network approval or malware certification.

Input limits are 8 MiB compressed, 32 MiB expanded, and 256 members, with additional image limits. The owned `sample-creative.zip` contains original demonstration HTML, CSS, JavaScript, PNG, and JPEG assets.

For a build pipeline, use the [CreativePack GitHub Action](https://github.com/israeldi/creativepack-optimize-action). It makes one processing request, saves a verified ZIP and report, and can fail the build when the complete archive remains over budget. Its setup instructions pin the reviewed runtime commit and show how to supply your subscribed key through a GitHub Actions secret. Find the Action on [GitHub Marketplace](https://github.com/marketplace/actions/creativepack-html5-zip-optimizer).

## Personalize a layered sewing-pattern PDF

Watch the [88-second PatternCopy demo](https://www.youtube.com/watch?v=d_EdOoZVCAw) to see a buyer stamp with selectable S/M/L size layers. It uses original fictional artwork and buyer details, with actual locally processed PDF output.

```sh
python3 patterncopy_stamp.py sample-pattern.pdf --text "Licensed to Alex Example - Order 1042" --output personalized.pdf
```

The example sends raw PDF bytes and base64-encoded JSON stamp options in a request header. It verifies the response’s required SHA-256 before saving a new PDF. Optional flags include `--position bottom-left`, `--font-size 9`, and `--margin 12`.

The service retains supported optional-content layers and original page geometry. Open the result and check size toggles, stamp placement, and print scale for your source. A visible stamp is editable traceability, not DRM or a guarantee against copying.

Limits include 20 MiB/100 pages of source, 24 MiB output, and up to 160 characters that fit the page. Supported text is unshaped left-to-right glyphs in the included DejaVu Sans font. Encryption, signatures, attachments, scripts, XFA, unsafe actions, and other unsupported features are rejected. The owned `sample-pattern.pdf` is a synthetic layered demonstration, not a redistributed commercial pattern.

For supported simple WooCommerce downloadable products, use the free [PatternCopy connector 0.1.1 and installation guide](integrations/patterncopy-woocommerce/). The unchanged GPLv2-or-later source and installable ZIP are available here. Its scope is one to five local PDFs; source-directory protection remains your store’s responsibility. [Service onboarding](https://flamingoapis.synology.me:8443/) is also available.

## Preview ONIX book updates in WooCommerce

The [55-second ShelfRelay demo](https://www.youtube.com/watch?v=FAHl9yr5u_o) shows a recorded local result for one fictional book and a diagram of preview, review and application.

The free [ShelfRelay connector 0.1.2](integrations/woocommerce/downloads/shelfrelay-for-woocommerce-0.1.2.zip) and its [GPLv2-or-later source](integrations/woocommerce/shelfrelay-for-woocommerce/) are available directly from this repository. Follow the [installation instructions](integrations/woocommerce/README.md) and [preview-first walkthrough](https://rapidapi.com/IsraelDiego/api/shelfrelay-onix/tutorials/import-onix-into-woocommerce%3A-a-preview-first-walkthrough).

The connector requires WooCommerce, PHP 8.1+ with the documented extensions, and a separate subscribed RapidAPI application key. Start on staging with the fictional sample and inspect the preview before approving changes. It supports ONIX 3.0/3.1 physical editions, one ISBN per simple product and one source authority. It does not include a supplier catalogue, convert currency/tax, infer stock quantities or reconcile multiple suppliers.

Version 0.1.2 fixes private staging of feed downloads and includes the local regression qualification described in its release provenance. WordPress.org received connector 0.1.2 on October 31, 2026. Its automated scan passed and its status is **Awaiting Review**; manual approval and directory publication remain pending. The GitHub download is available during review. The legacy NAS download URL redirects to the checksum-verified 0.1.2 ZIP; the versioned GitHub ZIP above also provides 0.1.2. No claim of WordPress.org approval, universal feed compatibility or customer adoption is made.

## Python output handling and verification

All output paths must be new. These scripts refuse to overwrite an existing file or symlink. A successful integrity check verifies the delivered container and supported response fields; compare exported values and inspect PDF/artwork behavior before production use.

The examples were checked offline against released contracts and synthetic responses. Access’s sample also passed local CSV and JSON extraction through the released engine. CreativePack and PatternCopy samples were retrieved from their public fixed-sample routes over verified HTTPS. This offline record does not claim authenticated end-to-end gateway testing of those three Python examples or evidence of customer adoption. CalcReceipt has a separate [actual standalone-client gateway demonstration and quota-stop record](calcreceipt/), using an original fictional workbook. ArchiveProof also has a [complete actual gateway receipt, original XML reports and free-plan quota-stop record](archiveproof/), using original fictional PDFs.

Run `python3 SCRIPT.py --help` for each CLI. Marketplace requests and backend resource caps can differ; refer to the current API listing for the full contract and support route.
