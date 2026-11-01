# CalcReceipt standalone client and fictional sample

**Candidate API: not public yet.** These original example files are published in advance so the Agensi workflow can use an independently obtained client. Public origin/gateway acceptance, subscription-cap checks and the Agensi listing remain pending. Do not upload customer files or infer availability from this repository alone.

The creator supplies these standalone files for downloading, saving and running with the CalcReceipt API. They are separate from the instructions delivered through Agensi's agent connection. Agensi does not install a local skill directory, run this client or include API access. Do not export Agensi-delivered skill files to obtain a runnable script.

## Files and prerequisites

- `client.py`: Python standard-library client; one request, no redirects, no automatic retries, exclusive output creation.
- `sample.xlsx`: original fictional two-sheet workbook, 1,822 bytes. It has two deliberately stale caches, eight absent caches and ten ordinary formulas, including a deliberate division-by-zero error. No customer data or real financial model is included.
- `generate_sample.py`: reproduces the fictional workbook locally without a request or credentials; refuses an existing output path.
- `checksums.json`: SHA-256 of these client/example files.

Use Python 3.10+ with a configured HTTPS trust store, an authorized local input/output directory, your own separate active CalcReceipt RapidAPI subscription and its application key configured privately as `RAPIDAPI_KEY`. Do not put a key into chat, source, a URL or command arguments. Keep TLS certificate verification enabled. The fixed assigned host is `calcreceipt.p.rapidapi.com`; its public processing path has not yet been accepted in this candidate release.

Download these files from an exact reviewed repository commit, check `checksums.json`, and inspect the original client before running it. Do not pipe a download into a shell. Use an already authorized client/configuration when available rather than installing duplicates. This download is independent of Agensi; no skill-file export is required.

## One processing attempt, after release

From the directory containing the independently obtained client and sample:

```sh
python3 client.py sample.xlsx --output recalculated.xlsx --report calculation-report.json
```

Choose two distinct new output paths in an existing authorized directory. The command uploads the selected workbook through RapidAPI to CalcReceipt and consumes one request if processed. Network failures do not prove whether a request was processed; a deliberate retry can consume another request. The client does not retry automatically.

The saved private plans are Basic $0/3 requests, Pro $9/250 and Ultra $19/1,000 per month, each with a hard request cap. This is candidate configuration, not current public checkout proof. Platform bandwidth fees are separately disclosed by RapidAPI. This repository and any free Agensi instruction skill provide no subscription or hosted-processing allowance.

To reproduce only the fictional source, without an upload:

```sh
python3 generate_sample.py another-fictional-sample.xlsx
```

## Read the complete report

The client verifies source/output hashes, reported byte counts and returned ZIP integrity. It does not extract or execute workbook contents. The service calculates a scratch copy, maps verified cell results, and patches formula caches into the source package rather than returning LibreOffice's rewritten scratch file.

Check `error_cells`, `review_required`, `cache_changes`, missing/changed-cache counts, `unrefreshed_derived_parts` and all three `preservation` findings before delivery. Missing is not zero; an empty-string result is not missing; a string containing `#REF!` is not an error unless its kind is `error`. Numeric strings are engine results, not promises of exact-decimal arithmetic. Dates retain workbook conventions without an invented timezone.

The fictional sample deliberately needs review because `Results!A6` calculates to an error. A successful API response is not certification of Excel compatibility, business logic, financial decisions or rendered appearance. Charts/drawings are preserved but their caches are not refreshed. Unsupported workbooks are refused.

Supported maxima include 4 MiB compressed input, 8 MiB output workbook, 32 MiB expanded package, 32 sheets, 50,000 populated cells and 10,000 ordinary formula cells. Macros, embedded objects, external relationships/data, names, tables/pivots, shared/array/data-table/spill and volatile/network formulas, 1904 dates, iteration and precision-as-displayed are unsupported. Refer to the final API contract before processing a file.

The private NAS runtime has passed the original ten-formula sample and all 10,000 formula results in a 50,000-cell synthetic workbook, plus restart recovery. These checks are private-origin proof, not gateway, customer-use, sustained-load or byte-ceiling proof. The calculator uses a credential-free Docker network-none container and a bounded dedicated tmpfs job queue. Tmpfs can be swapped, and RapidAPI has separate logging/retention policies. Upload only authorized files; this is not end-to-end zero retention.
