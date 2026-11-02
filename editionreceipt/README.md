# EditionReceipt public example

**Awaiting Agensi review. Proposed price: USD $15, one-time. The skill is not currently available to purchase.** This example documents submitted version 1.0.0. [Creator profile](https://www.agensi.io/creators/israel-diego).

EditionReceipt compares supported metadata in a selected ONIX ebook product with the metadata inside the exact delivery EPUB. This small, fictional example includes both original inputs and the actual HTML and JSON reports from the final recorded creator-local run.

## See the result

| File | Purpose |
|---|---|
| [feed.xml](feed.xml) | Original fictional ONIX feed; includes a print record and the selected ebook record |
| [delivery-difference.epub](delivery-difference.epub) | Original fictional delivery EPUB with a deliberately different main title |
| [report.html](report.html) | Readable report with expandable source locations and interpretation |
| [report.json](report.json) | Complete structured receipt, including input hashes and comparison findings |
| [provenance.json](provenance.json) | File sizes, SHA-256 hashes, source paths and recorded execution boundaries |
| [README.md](README.md) | This first-use guide |

To view the report, download `report.html` and open the downloaded file in your local browser. GitHub’s file view shows the HTML source; it does not host this file as a webpage. Use its raw/download control to save the file. The supplied HTML is self-contained and needs no report server.

## First request and agent workflow

When the submitted skill becomes available, use an Agensi-connected, file-capable agent that is permitted to read the supplied files, create reports and run Python 3.10 or later. The checker uses Python’s standard library, needs no pip packages, and makes no network calls. Your agent/provider’s data handling and execution charges still apply.

Attach the ONIX feed and delivery EPUB, then give the explicit ebook ISBN. For these fictional files, a natural request is:

> Use EditionReceipt to compare ebook ISBN 9780000000019 in this ONIX feed with this delivery EPUB before our distributor handoff. Leave both originals unchanged and give me the HTML and JSON reports, including any disagreement or uncertainty.

The submitted agent workflow is:

1. Read the skill instructions and scope reference delivered through Agensi’s agent connection. These accompany three self-contained Python scripts.
2. Run the three unchanged scripts from separate temporary files: inspect the ONIX, inspect the EPUB, then compare the fresh inspection data for the explicitly selected ebook ISBN. An inspection snapshot alone is not a completed comparison.
3. Return the static HTML report and JSON receipt. Preserve the original inputs and retain private inspection data in the authorized workspace; remove the temporary paid helper code afterward.
4. Review the findings. If a correction is authorized, make it separately in the appropriate source system or ebook, then perform a fresh comparison.

The recorded example is an actual creator-local run using three unchanged temporary scripts. It demonstrates the submitted checker’s output on fictional inputs; buyer delivery through Agensi remains unverified. Use the skill through Agensi when it becomes available to compare other files.

## What this run found

EditionReceipt 1.0.0 returned **DIFFERENCE** for ebook ISBN `9780000000019`. It selected ONIX RecordReference `ER-FICTIONAL-001` and EPUB package part `EPUB/content.opf`.

| Field | ONIX export | Delivery EPUB | Finding |
|---|---|---|---|
| Main title | The Quiet Lantern | The Orchard Handbook — Earlier Draft | DIFFERENCE |

ISBN, subtitle, declared text language, supported contributor, publisher and EPUB format matched. The optional `layout` and `version` checks were `NOT_DECLARED`. No unresolved field remained in this deliberately small example, and `comparison_complete` is `true`. Completeness applies to the supported metadata comparison, not to the whole publication.

The EPUB’s primary package identifier is a UUID. Its separate `ebook-isbn` identifier supplies `9780000000019`, so the UUID does not create an identity mismatch. The EPUB also declares print ISBN `9780000000026` in `dc:source`; that source reference is not ebook identity. The explicit ebook ISBN selects the ebook record rather than the first, print record in the feed. These fictional ISBNs are checked for syntax and check digit, not registration or ownership.

The fictional EPUB was independently generated with EbookLib and deliberately given the different main title. The final recorded run left both original input files unchanged. The four input/report files here are byte-for-byte copies from that run’s source artifacts; they have not been regenerated or edited for this guide. Their hashes and sizes are recorded in [provenance.json](provenance.json).

## Scope and privacy

The report retains raw metadata values, source locations, the selected ONIX RecordReference, the EPUB package part and input hashes. Hashes identify the checked bytes; they are not authenticity signatures. Findings distinguish agreement, differences, review needs and unavailable inputs rather than treating uncertainty as agreement.

This is a paired-file metadata check. It does not validate the full ONIX or EPUB schema, compare manuscript text, inspect covers or rendered pages, assess accessibility or rights, verify ISBN registration, repair files, publish a release or guarantee distributor or retailer acceptance. A matching pair can share the same wrong metadata.

Only fictional fixture data is supplied here. In real work, input files, inspection data and reports can reveal unpublished metadata. Keep them in the authorized workspace and share them deliberately under the relevant agent/provider’s data handling rules. The checker’s lack of network calls does not change how the agent/provider handles uploaded files.
