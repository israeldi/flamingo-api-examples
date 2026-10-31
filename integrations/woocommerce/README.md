# ShelfRelay for WooCommerce

Preview supported ONIX book-feed updates before applying them to a WooCommerce catalogue. The connector is free and licensed under GPLv2 or later. Processing requires a separate subscribed RapidAPI application key; a free Basic plan is available for an initial compatibility check.

**[Download the installable connector ZIP — 0.1.2](https://raw.githubusercontent.com/israeldi/flamingo-api-examples/main/integrations/woocommerce/downloads/shelfrelay-for-woocommerce-0.1.2.zip)**

- [Plugin source and license](shelfrelay-for-woocommerce/)
- [Version, checksums and qualification](release-0.1.2.json)
- [Preview-first walkthrough](https://rapidapi.com/IsraelDiego/api/shelfrelay-onix/tutorials/import-onix-into-woocommerce%3A-a-preview-first-walkthrough)
- [API plans and complete contract](https://rapidapi.com/IsraelDiego/api/shelfrelay-onix)
- [Guide hub](https://flamingo-api-guides.flatemu1.chatgpt.site/onix-to-woocommerce/)

The versioned ZIP is 41,316 bytes. Its SHA-256 is:

```text
53facd8d6c206cb7ba0b58c27195cdfd9976b2160936e8b18bc030b53914ef06
```

## Install and preview on staging

Use WordPress 6.6 or newer with WooCommerce active, PHP 8.1 or newer, XMLReader, DOM, OpenSSL and normal WordPress image/file support. Heavy batches need sufficient PHP memory; consult the included plugin readme before using large descriptions or feeds. Keep a store backup before applying imports.

1. Download the versioned connector ZIP above. In your staging store, open **Plugins → Add New → Upload Plugin**, select that ZIP, install and activate it.
2. Open **WooCommerce → ShelfRelay**. The official gateway `https://shelfrelay-onix.p.rapidapi.com` is preset. Enter your subscribed RapidAPI application key privately.
3. For the [fictional one-book sample](https://shelfrelay.flamingoapis.synology.me:8443/sample.xml), use a staging store with market `US`, currency `USD`, and price type `01 · excluding tax`. If your store uses other conventions, keep them and use an authorized matching feed instead. The API does not convert currencies or calculate tax.
4. Leave automatic checks and **Publish new supported titles** off for the sample. Choose merchant-owned fields deliberately; preserve merchant ownership of stock status for existing products with numeric inventory unless you intend to change its management.
5. Read the [service privacy information](https://shelfrelay.flamingoapis.synology.me:8443/privacy) and [terms](https://shelfrelay.flamingoapis.synology.me:8443/terms), authorize the disclosed transfer if appropriate, and save the connection.
6. Upload the sample XML and choose **Preview file**. Inspect the receipt, proposed fields and diagnostics. This preview creates or updates no products. The unchanged sample should show *The Orchard Notebook (sample)*, ISBN `9781234567897`, USD 18.50 and availability `instock` under the sample policy.
7. Apply supported records only after checking existing ISBN matches and approving the preview. Inspect resulting products before enabling recurring checks. Availability does not imply a stock quantity.

## Supported scope and accounting

This release supports ONIX 3.0/3.1 reference or short tags, physical editions as separate ISBN/simple products, and one source authority. It excludes ONIX 2.1, grouped print/ebook editions, digital fulfilment, arbitrary custom mappings and multi-supplier reconciliation. It supplies no catalogue subscription or classification lookup.

Connector feeds are plain XML, up to 25 MiB and 5,000 retained ISBN records. API batches have separate record and byte limits. Inspect record-level diagnostics: an HTTP success can contain rejected records. Missing or unknown data does not become a zero value, and an omitted delta title is not deleted.

Previews, scheduled date refreshes and retries consume allowance. Marketplace Requests and backend record/upload caps have separate accounting periods; platform bandwidth fees may also apply. Review current plans before scheduling a large catalogue.

Supplier credentials stay on the WordPress host. Product XML, required previous source state and policy pass through RapidAPI and ShelfRelay. Keep application keys, supplier credentials and confidential catalogues out of public issues. See the included [plugin readme](shelfrelay-for-woocommerce/readme.txt) for retention, staging, scheduling and recovery details.

## Release and verification status

Version 0.1.2 fixes private staging of downloaded XML, including directory containment, symlink and permission checks. Its source release passed 50 local WooCommerce web workflow checks, 16 private-staging checks, 31 connector contract checks and the official WordPress Plugin Check. The workflow uses an actual local API and WordPress/WooCommerce with owned fixtures. These checks do not establish arbitrary-feed compatibility or customer adoption.

This is direct GitHub distribution. **WordPress.org received 0.1.2 on October 31, 2026. Its status is Awaiting Review; manual approval and directory publication remain pending.** Its automated scan passed. The historical NAS download and API runtime remain at 0.1.1. The connector ZIP and matching source in this directory are 0.1.2; installing them does not change the hosted API runtime.

IsraelDiego publishes the connector and processing service. This repository's introductory documentation was prepared with AI assistance.
