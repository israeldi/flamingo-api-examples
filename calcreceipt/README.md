# CalcReceipt standalone client and fictional sample

**API published:** [CalcReceipt on RapidAPI](https://rapidapi.com/IsraelDiego/api/calcreceipt). The public listing, anonymous pricing and subscription entry were verified on November 1, 2026. An earlier standalone-client revision completed the recorded gateway demonstration, whose free plan stopped the fourth request at its monthly hard cap. The [verification record](demo/verification.json) identifies that exact client and run. Read the [contract](CONTRACT.md) and current [Pricing](https://rapidapi.com/IsraelDiego/api/calcreceipt/pricing) before uploading a file.

The creator supplies these standalone files for downloading, saving and running with the CalcReceipt API. This independent download is also available outside Agensi. The Agensi skill includes its own client for temporary execution by a capable connected agent; it does not install a local skill directory or include API access. Use this repository when you want independently supplied standalone files.

## Files and prerequisites

- [client.py](client.py): Python standard-library client; one request, no redirects, no automatic retries, exclusive output creation. Accepts either the existing `RAPIDAPI_KEY` environment route or an explicit private `--key-file`.
- [setup_key.py](setup_key.py): optional one-time private Terminal setup for a POSIX key file; no network or processing.
- `sample.xlsx`: original fictional two-sheet workbook, 1,822 bytes. It has two deliberately stale caches, eight absent caches and ten ordinary formulas, including a deliberate division-by-zero error. No customer data or real financial model is included.
- `generate_sample.py`: reproduces the fictional workbook locally without a request or credentials; refuses an existing output path.
- `checksums.json`: SHA-256 of these client/example files.

Use Python 3.10+ with a configured HTTPS trust store, an authorized local input/output directory, and your own active CalcReceipt RapidAPI subscription and application key. Find your existing key under [Developer Dashboard → Apps → your app → Authorization](https://rapidapi.com/developer/dashboard). One app key can be used for APIs you subscribe to; each API needs its own plan. Subscribing does not automatically configure a key in an agent's execution environment. Keep TLS certificate verification enabled. The fixed assigned host is `calcreceipt.p.rapidapi.com`; its public processing path was verified with the original fictional workbook.

Download these files from an exact reviewed repository commit, check `checksums.json`, and inspect the original client before running it. Do not pipe a download into a shell. Use an already authorized client/configuration when available rather than installing duplicates. This download is independent of Agensi; no skill-file export is required.

## Configure your key once

For the optional key-file route, run the reviewed [setup helper](setup_key.py) in your own private Terminal on the **same host and OS user** that will run the client:

```sh
python3 setup_key.py
```

Enter the key at the hidden prompt. The helper creates `~/.config/flamingo-api/rapidapi.key` outside a Git project, with a new mode-600 file in an owned mode-700 directory. It refuses non-Terminal input, any echo fallback, symlinked paths and an existing key file; it does not overwrite or rotate credentials or contact a service. If that private file is already configured, reuse it.

A laptop key file does not configure a remote agent host. A remote host needs its own private Terminal setup or existing secure secret settings, available to the same OS user as the agent. Never attach a credential file or paste a key into chat. Keep keys out of source, URLs and command arguments.

Alternatively, keep the existing `RAPIDAPI_KEY` route. In a private Bash or zsh Terminal:

```sh
printf 'RapidAPI key: '
read -rs RAPIDAPI_KEY
export RAPIDAPI_KEY
printf '\n'
```

That environment value is available to this shell and its child processes; it does not configure an unrelated or remote agent session.

## One processing attempt

From the directory containing the independently obtained client and sample, after the key-file setup:

```sh
python3 client.py sample.xlsx --key-file "$HOME/.config/flamingo-api/rapidapi.key" \
  --output recalculated.xlsx --report calculation-report.json
```

Or, when `RAPIDAPI_KEY` is configured privately in the client's own execution environment, omit `--key-file`:

```sh
python3 client.py sample.xlsx --output recalculated.xlsx --report calculation-report.json
```

Choose two distinct new output paths in an existing authorized directory. The command uploads the selected workbook through RapidAPI to CalcReceipt and consumes one request if processed. Network failures do not prove whether a request was processed; a deliberate retry can consume another request. The client does not retry automatically.

An explicit `--key-file` takes precedence over the environment. An invalid file stops locally without falling back to `RAPIDAPI_KEY`. The file must be owned by this OS user, regular, mode 600 or 400, with one bounded ASCII key line and an owned mode-700 parent directory; links and nonregular files are refused. Either route requires a key value of 16–512 printable ASCII characters without whitespace; malformed values are refused locally before HTTP headers are constructed. The client also refuses an input containing the literal configured credential bytes. That check covers literal bytes only.

The key-file/setup changes were checked offline with fake keys and mocked transport. This update made no fresh API requests and establishes no Agensi-connected-session result. The earlier gateway demonstration below retains its original client identity and artifacts.

The published plans are Basic $0/3 requests, Pro $9/250 and Ultra $19/1,000 per month, each with a hard request cap. Anonymous pricing was checked, and the current free-plan counter was observed at 2, 1 and 0 before an explicit platform monthly HTTP 429. Paid checkout was not exercised. Platform bandwidth fees are separately disclosed by RapidAPI. This repository and any free Agensi instruction skill provide no subscription or hosted-processing allowance.

To reproduce only the fictional source, without an upload:

```sh
python3 generate_sample.py another-fictional-sample.xlsx
```

## Read the complete report

The client verifies source/output hashes, reported byte counts and returned ZIP integrity. It does not extract or execute workbook contents. The service calculates a scratch copy, maps verified cell results, and patches formula caches into the source package rather than returning LibreOffice's rewritten scratch file.

Check `error_cells`, `review_required`, `cache_changes`, missing/changed-cache counts, `unrefreshed_derived_parts` and all three `preservation` findings before delivery. Missing is not zero; an empty-string result is not missing; a string containing `#REF!` is not an error unless its kind is `error`. Numeric strings are engine results, not promises of exact-decimal arithmetic. Dates retain workbook conventions without an invented timezone.

The fictional sample deliberately needs review because `Results!A6` calculates to an error. A successful API response is not certification of Excel compatibility, business logic, financial decisions or rendered appearance. Charts/drawings are preserved but their caches are not refreshed. Unsupported workbooks are refused.

Supported maxima include 4 MiB compressed input, 8 MiB output workbook, 32 MiB expanded package, 32 sheets, 50,000 populated cells and 10,000 ordinary formula cells. Macros, embedded objects, external relationships/data, names, tables/pivots, shared/array/data-table/spill and volatile/network formulas, 1904 dates, iteration and precision-as-displayed are unsupported. Read the [API contract](CONTRACT.md) before processing a file.

The private NAS runtime has passed the original ten-formula sample and all 10,000 formula results in a 50,000-cell synthetic workbook, plus restart recovery. These checks are private-origin proof, not gateway, customer-use, sustained-load or byte-ceiling proof. The calculator uses a credential-free Docker network-none container and a bounded dedicated tmpfs job queue. Tmpfs can be swapped, and RapidAPI has separate logging/retention policies. Upload only authorized files; this is not end-to-end zero retention.

## Complete recorded demonstration

The [original source](sample.xlsx) produced [calculated.xlsx](demo/calculated.xlsx) and this [complete JSON report](demo/report.json). The [verification record](demo/verification.json) identifies the actual client, hashes and scope. The result deliberately requires review: `Results!A6` has the typed error `#DIV/0!`; `Results!A9` is ordinary string text `#REF!`. Empty string, missing cache and zero remain distinct. All ten cells are included in the report.

The recorded first request returned HTTP 200, refreshed two stale and eight missing caches, preserved source formulas and inputs, and returned LibreOffice 7.4.7.2 as its engine. The 1,822-byte input and 1,861-byte output have the hashes in the full report. Three original-fixture calculations succeeded; the fourth attempt received the platform's explicit monthly quota denial. This is a real earlier standalone-client/gateway run, not an Agensi-connected-agent run, customer testimonial, paid sale or payout. No retries were made.
