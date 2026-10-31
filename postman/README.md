# Flamingo APIs: Postman quickstarts

Four independent collections help a developer make one deliberate processing request through the subscribed RapidAPI customer gateway.

| Collection | First-use workflow | Request body |
| --- | --- | --- |
| [Access File Extractor](https://www.postman.com/idiego612-5459945/flamingo-apis-quickstarts/collection/59374683-c2d7281c-feb9-4174-8f15-6eb363991901) · [Download JSON](https://raw.githubusercontent.com/israeldi/flamingo-api-examples/main/postman/access-file-extractor.postman_collection.json) | Export a synthetic ACCDB to typed JSON and check its table manifest | Download the sample; select it under **Body → binary** |
| [CreativePack](https://www.postman.com/idiego612-5459945/flamingo-apis-quickstarts/collection/59374683-69cac163-66d2-4226-8982-95298156fed1) · [Download JSON](https://raw.githubusercontent.com/israeldi/flamingo-api-examples/main/postman/creativepack.postman_collection.json) | Optimize an embedded synthetic HTML5 ad ZIP and inspect its budget report | Embedded base64 ZIP in **raw JSON** |
| [PatternCopy PDF](https://www.postman.com/idiego612-5459945/flamingo-apis-quickstarts/collection/59374683-7b8ca820-a0b6-4591-ba23-09cb38b3b551) · [Download JSON](https://raw.githubusercontent.com/israeldi/flamingo-api-examples/main/postman/patterncopy-pdf.postman_collection.json) | Inspect a source revision, then optionally stamp a fictional buyer copy | Download the PDF; select it separately under **Body → binary** for each request |
| [ShelfRelay ONIX](https://www.postman.com/idiego612-5459945/flamingo-apis-quickstarts/collection/59374683-a30ca0ae-494b-4d7d-92a5-7f32635f7487) · [Download JSON](https://raw.githubusercontent.com/israeldi/flamingo-api-examples/main/postman/shelfrelay-onix.postman_collection.json) | Preview one fictional ONIX record and verify its accepted title, price and availability | Embedded original sample envelope in **raw JSON** |

The [Flamingo APIs quickstarts workspace](https://www.postman.com/idiego612-5459945/flamingo-apis-quickstarts/overview) is set to **Public** in Postman's authenticated UI, and all four collection imports succeeded in the web UI. The exact scripts also passed the real-runtime fixture qualification described below. The overview and all four collection names also rendered in a signed-out browser; CreativePack’s public setup instructions and empty shared key value were checked there. Consumer fork execution, authenticated gateway requests and customer adoption are not established by these results.

## Use one collection

1. Choose the product you need and review its supported formats, current plan limits and data handling on its listing:
   [Access File Extractor](https://rapidapi.com/IsraelDiego/api/access-file-extractor),
   [CreativePack](https://rapidapi.com/IsraelDiego/api/creativepack),
   [PatternCopy PDF](https://rapidapi.com/IsraelDiego/api/patterncopy-pdf),
   [ShelfRelay ONIX](https://rapidapi.com/IsraelDiego/api/shelfrelay-onix).
   Subscribe to that product's plan; its free Basic plan is intended for an initial compatibility check. Each API has a separate subscription and resource allowance.
2. Open the chosen Postman collection using the table above and fork it into your own workspace, or download its JSON and import it. Follow the collection's setup steps before sending a request.
3. In the collection's **Variables** tab, set `rapidapi_key` as a **local value only**. Leave its shared value blank. Obtain your own existing subscribed key through **RapidAPI Developer Dashboard → Apps → your application → Authorization**. [RapidAPI's instructions](https://docs.rapidapi.com/docs/keys-and-key-rotation).
   Alternatively, store the value in **Postman Local Vault** and change this collection's API Key authorization value to your local `{{vault:rapidapi_key}}` reference. The collection contains no script that reads the Vault or exports its contents.
4. Read the collection's Start here section. Access and PatternCopy require local file selection; their published `file.src` is deliberately `null`. Public workspaces cannot distribute team-uploaded test files. Select a downloaded owned synthetic sample, or your own compatible authorized file.
5. Use **Send** on one chosen request. Review the returned data and test results. Inspect and stamp are separate PatternCopy calls. Do not use a cross-product Run All, schedule, monitor or loop for this initial check.

Collection API Key authorization supplies `X-RapidAPI-Key`; each request supplies the exact product's `X-RapidAPI-Host`. Processing requests target only its fixed `*.p.rapidapi.com` host. Sample downloads are documentation links, so the collection never forwards the subscribed key to GitHub or the NAS documentation origin.

No real key, account email or local file path is embedded. Never publish a filled environment or credential. A variable being hidden in the UI is not a reason to share its value.

## Limits and result review

Every processing request can consume plan allowances, including an inspection, an unsuccessful gateway attempt or a deliberate retry. The backend counters and RapidAPI's Requests/billing cycles are distinct. Check the live listing before sending. Redirect following is disabled in the collections; there are no pre-request scripts, package downloads, chained requests, automatic retries or loops. Keep any runtime-provided automatic retry feature off.

| Product | Relevant processing bounds | What to inspect after processing |
| --- | --- | --- |
| Access | 40 MiB input; 32 MiB serialized output | Complete manifest, expected tables/rows and source values |
| CreativePack | 8 MiB ZIP; 32 MiB expanded; 256 members; budget 1,024–8,388,608 bytes | `within_budget` or `over_budget`, decoded ZIP, report hashes, member contents and visual output |
| PatternCopy | 20 MiB / 100 pages input; 24 MiB output | Actual stamped PDF, buyer text, size-layer behavior, page geometry and printed scale |
| ShelfRelay | 100 records / 8 MiB JSON per call; each current/prior XML ≤256 KiB | Each record's status and diagnostics, explicit `apply_fields`, identity and retained `next_xml` |

CreativePack's collection embeds the existing **809,389-byte** ZIP as **1,079,188 base64 characters**. Its collection is about 1.1 MB. The default 150,000-byte lossless budget may remain unmet: `over_budget` is a valid processed result. This endpoint takes JSON, not a raw ZIP upload. Decode `archive_base64` to a new output file; the [published Python client](https://github.com/israeldi/flamingo-api-examples/blob/main/creativepack_optimize.py) performs additional ZIP member/path and preserved-file checks.

The ShelfRelay sample is the original fictional US/USD/01 excluding-tax record, ISBN `9781234567897`: **The Orchard Notebook (sample)**, USD **18.50**, `instock`, `upsert`. HTTP 200 alone is insufficient; the embedded test requires that record to be accepted. The API call does not write a WooCommerce product. Use the [free connector's reviewed application workflow](https://github.com/israeldi/flamingo-api-examples/tree/main/integrations/woocommerce) for store changes.

Post-response scripts make no network calls and do not print response bodies, file contents or credentials. Current Postman Web Crypto supplies SHA-256 for CreativePack's decoded archive and PatternCopy's returned PDF. The PatternCopy binary test uses the Postman Collection SDK response stream. The exact scripts also completed a real Postman Runtime/sandbox qualification: 18 assertions across 8 loopback HTTP requests matched the expected outcomes, including intentional failures for corrupted digest fixtures. This exercised the actual runtime APIs without authenticated gateway processing. Live Postman web Send, consumer fork execution and customer adoption remain unestablished.

## Verification scope

The accompanying `validation.json` records four official Collection v2.1 schema validations, eleven request-contract/source checks and twenty-four positive/negative executions of the exact response-test scripts. `source-binding.json` identifies the public synthetic samples and distinguishes constructed verification fixtures from real service results.

The source checks are supplemented by official Postman Runtime 7.56.3, sandbox 6.8.0 and Collection SDK 5.3.1 executing the exact published scripts under Node 24.22.1 against loopback fixtures. All 18 assertions across 8 received requests matched their expected outcomes; two corrupted-digest cases intentionally failed their digest assertion. No runtime globals or response APIs were shimmed. The authenticated Postman web UI separately confirmed all four imports and the Public workspace setting. No authenticated gateway call, live web Send or customer activation is inferred.

## Public Network status

The [Public workspace](https://www.postman.com/idiego612-5459945/flamingo-apis-quickstarts/overview) and the four actual collection links above are available for discovery and forking. Their Public setting and successful imports were verified in the authenticated UI. The overview, all four collection names, subscription links and trial instructions also rendered in a signed-out browser. Consumer fork execution is not claimed here. The raw JSON downloads remain available from the existing public examples repository. No generated Run in Postman button is advertised. Domain verification is a separate optional badge process.

Primary Postman references: [public collection preparation](https://learning.postman.com/docs/postman-api-network/showcase/prepare/public-collections/), [public workspace publishing](https://learning.postman.com/docs/postman-api-network/showcase/publish/overview/), [binary body handling](https://learning.postman.com/docs/use/send-requests/create-requests/parameters/), [public test-file restriction](https://learning.postman.com/latest-v-12/docs/use/send-requests/create-requests/test-data), [local/shared variable values](https://learning.postman.com/docs/use/send-requests/variables/variables/), [current sandbox globals and Web Crypto](https://learning.postman.com/docs/tests-and-scripts/write-scripts/postman-sandbox-reference/pm-require).
