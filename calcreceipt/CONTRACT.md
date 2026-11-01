# CalcReceipt API contract

CalcReceipt refreshes supported XLSX formula-cell caches with LibreOffice Calc and returns a new workbook plus a typed calculation report. Original formulas, non-formula cells and other package parts are checked for preservation before a successful response. This is bounded calculation and cache repair, not certification of Microsoft Excel parity, business logic, financial correctness or rendered appearance.

The API is available through [RapidAPI](https://rapidapi.com/IsraelDiego/api/calcreceipt). See the [standalone client and example](README.md), [client source](client.py), [fictional input](sample.xlsx), [complete demonstration report](demo/report.json) and [demonstration verification](demo/verification.json). These files can be used independently of any Agensi instructions.

## Request

```http
POST https://calcreceipt.p.rapidapi.com/v1/recalculate
X-RapidAPI-Key: YOUR_PRIVATE_APPLICATION_KEY
X-RapidAPI-Host: calcreceipt.p.rapidapi.com
Content-Type: application/octet-stream
Content-Length: <exact byte count>

<raw XLSX bytes>
```

`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` is also accepted. Send one unencoded binary workbook with its exact `Content-Length`; JSON, base64, multipart, transfer encoding and content encoding are not accepted. There are no query parameters or formula-text options on this public endpoint.

Use your own active CalcReceipt subscription and its application key. Keep the key in private client configuration; never put it in a URL, chat, source file or command arguments. Send it only to the fixed RapidAPI HTTPS host above, with certificate verification enabled. Consumers do not need a provider secret or direct-origin credentials.

The supplied Python 3.10+ standard-library client makes one request, refuses redirects and automatic retries, and creates two distinct new output files without overwriting existing files. Subscribing to RapidAPI does not populate credentials in a local or remote agent process. With `RAPIDAPI_KEY` already configured privately in the client's own execution environment:

```sh
python3 client.py sample.xlsx --output recalculated.xlsx --report calculation-report.json
```

Alternatively, run [setup_key.py](setup_key.py) once in your own private Terminal on the **same host and OS user** as the client. It makes no network or processing request, requires a hidden prompt without echo fallback, and creates only a new `~/.config/flamingo-api/rapidapi.key` file with mode 600 in an owned mode-700 configuration directory outside a Git project. Existing key files and unsafe or symlinked paths are refused; there is no overwrite or rotation option.

```sh
python3 setup_key.py
python3 client.py sample.xlsx --key-file "$HOME/.config/flamingo-api/rapidapi.key" \
  --output recalculated.xlsx --report calculation-report.json
```

The optional `--key-file` route requires POSIX file semantics. An explicit file takes precedence over `RAPIDAPI_KEY`; an invalid explicit file fails locally with no environment fallback. The client accepts only an owned regular file with mode 600 or 400, one link, and an owned mode-700 immediate parent. Symlinks, including ancestor symlinks, and nonregular files are refused. The file contains one nonempty printable ASCII key line, optionally followed by LF or CRLF, and is at most 4,096 bytes including that ending. File identity and metadata must remain stable while read. Either route requires the resulting key value to be 16–512 printable ASCII characters without whitespace; malformed values fail locally before HTTP headers are constructed. The file-size bound remains separate from this key-value validation. These checks and the setup helper run before any processing upload. The existing environment route remains available without `--key-file`.

A laptop key file cannot configure a remote host. Use that host's own private Terminal or existing secure secret settings for its agent OS user. Never attach credential files or paste credentials into chat. The client refuses selected input bytes containing the literal configured key; this check covers literal bytes only.

A timeout or disconnected response does not prove that processing did not occur. A deliberate retry can consume another request. There is no idempotency-key facility.

## Successful response

HTTP `200`, `Content-Type: application/json`:

```json
{
  "workbook_base64": "<base64-encoded output XLSX>",
  "report": { "schema_version": "1.0", "...": "fields described below" }
}
```

Decode `workbook_base64` as bytes and verify `output_bytes` and `output_sha256` before using the file. The client also checks input identity and returned ZIP integrity. The [recorded full report](demo/report.json) contains actual values for every report field; the following tables define their interpretation.

| Report field | Type and meaning |
| --- | --- |
| `schema_version` | String, currently `"1.0"`. |
| `source_sha256`, `input_sha256` | Identical lowercase SHA-256 strings identifying the uploaded bytes. |
| `input_bytes` | Integer input ZIP byte count. |
| `expanded_bytes` | Integer total uncompressed package payload bytes. |
| `members`, `worksheets`, `populated_cells`, `formula_cells` | Integer inspected counts. A populated cell has a formula, cached value or inline string. |
| `original_missing_formula_caches` | Integer count of formula cells whose original cache was absent. |
| `engine` | `"LibreOffice Calc"`. |
| `engine_version` | Runtime version string, or `null` when there are no formulas and calculation was not invoked. The recorded gateway run used `"7.4.7.2"`. |
| `status` | `"calculated"` or `"no_formulas"`. The latter returns the original input bytes after validation. |
| `output_sha256`, `output_bytes` | SHA-256 string and integer byte count of the decoded returned workbook. |
| `source_formulas_sha256` | SHA-256 of compact, ASCII-escaped JSON containing ordered `[worksheet_part, cell_reference, original_formula]` triples. Identifies the original expressions without including them in the report. |
| `formula_text_included` | Boolean, `false` on this endpoint. |
| `cache_changes` | Array with one entry for **every** formula cell, including unchanged caches; entry schema below. |
| `changed_cache_count` | Integer count of entries whose typed original and calculated cache objects differ. This is a lexical comparison, not a numerical tolerance test. |
| `error_cells` | Array of `{ "sheet": string, "cell": string, "error": string }` for calculated caches whose kind is `error`. |
| `review_required` | Boolean, true when a formula error or preserved chart/drawing part needs review. False does not certify workbook correctness. |
| `unrefreshed_derived_parts` | Array of preserved chart/drawing package paths whose derived caches or visual output were not refreshed or verified. |
| `preservation` | Object with boolean `original_formulas`, `non_formula_cells` and `other_package_parts_byte_identical`. All are true for a successful response. |
| `warnings` | Array of explanatory strings; read all entries. |
| `elapsed_seconds` | Number measuring engine-side inspection/calculation/verification time, excluding gateway and upload latency. |
| `network_boundary` | Object describing the calculator's runtime network checks; schema below. |

Each `cache_changes` entry has `sheet` (worksheet name string), `cell` (A1 reference string), `original_cache` and `calculated_cache` (typed objects below), and `cache_changed` (boolean). Formula text is omitted, but sheet names and calculated values can still be sensitive.

| Cache `kind` | `value` and interpretation |
| --- | --- |
| `number` | Numeric lexical **string**, such as `"12"`, `"0.3"` or a date serial. It is not a JSON number or an exact-decimal arithmetic guarantee. |
| `boolean` | String `"1"` or `"0"`, not a JSON boolean. |
| `string` | String, including `""`. Text such as `"#REF!"` remains ordinary text. |
| `error` | Formula error-code string, such as `"#DIV/0!"`. |
| `missing` | `null`; the original cache was absent. Missing is not zero or empty string. |
| `date` | Original OOXML date-cache string, where present. Recalculated dates use numeric results with the preserved workbook date/format convention; no timezone is invented. |

Calculated caches use `number`, `boolean`, `string` or `error`; an omitted calculated result is refused. A calculation that produces an error can still return HTTP 200 with a valid workbook, `error_cells` and `review_required: true`. Inspect these fields before delivery.

`network_boundary` contains:

| Field | Type and meaning |
| --- | --- |
| `mode` | String `"docker_network_none"`. |
| `interface_inventory` | Array of objects with `name` (string), `ifindex` (integer), `flags_hex` (string), `up` and `loopback` (booleans). |
| `non_loopback_interfaces`, `dormant_non_loopback_interfaces`, `active_non_loopback_interfaces` | Arrays of interface-name strings. An inactive tunnel interface may appear without providing a working route. |
| `non_loopback_routes` | Array of detected non-loopback routes; empty in an accepted isolated runtime. |
| `outbound_connect`, `ipv4_tcp_probe`, `ipv4_udp_probe` | Strings reporting outbound probes; `"unreachable"` in the qualified runtime. |
| `uno_local_pipe` | `"permitted"`; local calculation IPC remains available. |

## Supported formulas and workbooks

Only ordinary cell formulas in supported Transitional XLSX packages are accepted. Supported expressions use A1 references, absolute `$` markers, existing local sheets, bounded cell ranges, literals and ordinary arithmetic/comparison/string operators. The exact 46-function allowlist is:

```text
SUM AVERAGE MIN MAX COUNT COUNTA IF IFERROR AND OR NOT
ROUND ROUNDUP ROUNDDOWN ABS SQRT POWER MOD
SUMIF SUMIFS COUNTIF COUNTIFS AVERAGEIF AVERAGEIFS
VLOOKUP HLOOKUP INDEX MATCH
LEFT RIGHT MID LEN LOWER UPPER TRIM CONCATENATE
DATE YEAR MONTH DAY INT
ISBLANK ISNUMBER ISTEXT ISERROR NA
```

Allowlisting a function does not establish every combination's Excel compatibility. Unknown functions, volatile/network functions, names, external references, whole-row/whole-column ranges, 3D references, array/spill expressions and unsupported syntax are refused. Formula-bearing validation, conditional-formatting and chart reference expressions are also inspected.

Unsupported workbooks include XLS/XLSB/XLSM/ODS or encrypted files; macros, embedded objects, ActiveX/OLE, external relationships/data/connections/query tables, tables/pivots, shared/array/data-table or attributed cell formulas, calculation names, 1904 dates, iterative calculation and precision-as-displayed. Limited built-in print/filter names are permitted when their expressions pass validation. Unsafe archives, DTD/entity declarations, non-UTF-8 XML, duplicate/ambiguous package paths and unsupported ZIP compression are refused. Unsupported input fails as a whole; no partially recalculated workbook is returned.

## Bounds

All limits apply together. MiB means 1,048,576 bytes.

| Resource | Maximum |
| --- | --- |
| Compressed request workbook | 4 MiB (4,194,304 bytes), nonempty |
| Returned workbook | 8 MiB (8,388,608 bytes) |
| Expanded package payload | 32 MiB (33,554,432 bytes) |
| ZIP members / worksheets | 1,024 / 32; at least one worksheet |
| Populated cells / formula cells | 50,000 / 10,000 |
| Materialized worksheet cell elements, including blank styled cells | 100,000 |
| Aggregate XML elements / nesting depth | 300,000 / 96 |
| Formula text | 8,192 characters per expression |
| Encoded JSON result | 24 MiB (25,165,824 bytes) |

The service admits one calculation at a time and at most six local admission attempts per identity per minute. These protections are separate from RapidAPI plan limits. Uploads have a 15-second deadline; the calculator job is bounded to 60 seconds, with a 65-second frontend wait deadline. These are processing limits, not latency or availability guarantees. The client uses a 90-second network timeout.

## Preservation and review

The service calculates a scratch copy, verifies its formula mapping, then patches only formula-cache type/value spans into the original worksheet XML. It does **not** deliver LibreOffice's rewritten scratch workbook. Original formula expressions and non-formula cells are checked again, and untouched package payloads must be byte-identical. The ZIP container itself can change, so whole-file byte identity is not promised.

Charts/drawings are retained without refreshing their cached data or checking their rendered appearance. Formulas outside worksheet cells are not delivered with freshly calculated caches. Review `unrefreshed_derived_parts`, `error_cells`, all preservation findings and warnings; use the workbook's intended viewer when appearance matters. The report establishes the described calculation and preservation checks, not whether source assumptions are correct.

## Errors and request accounting

Service errors return JSON shaped as `{"error":{"code":"...","message":"..."}}` without a workbook. RapidAPI-generated errors can have a different schema.

| HTTP status | Typical cause |
| --- | --- |
| `400`, `411`, `417` | Malformed headers/body, missing valid content length, unsupported `Expect`. |
| `401` / platform `403` | Missing or invalid gateway authorization, subscription or key. |
| `404` | Unknown endpoint. |
| `408` | Upload deadline exceeded (`body_timeout`). |
| `413` | Input byte limit (`input_size`). |
| `415` | Wrong media type or encoding (`raw_xlsx_required`, `unsupported_encoding`). |
| `422` | Unsupported/invalid workbook or formula, validation/preservation failure, or bounded-result rejection. Read the returned code and message. |
| `429` | Local `minute_limit` (`Retry-After: 60`) or a separate platform quota/rate denial. A monthly quota denial needs a plan/reset decision, not immediate repetition. |
| `503` | Calculator busy or unavailable; `engine_busy` includes `Retry-After: 5`. |
| `504` | Calculation/worker deadline exceeded. |

Do not automatically retry any response. Inspect the error, quota and any `Retry-After` first. Do not assume failed requests are free; platform accounting is authoritative. HTTP 200 with formula errors is still a processed request.

## Privacy and pricing

Upload only files you are authorized to disclose to both RapidAPI and this service. The calculator runs without network access or API credentials, using a dedicated bounded tmpfs job area with transient cleanup. Tmpfs can be swapped by the host, so this is not a guarantee that bytes never reach storage. The application does not log uploaded bodies or API credentials. RapidAPI has its own request/response logging and retention policies; this is not end-to-end zero retention. Returned reports and output workbooks contain workbook data and should be stored in an authorized location.

The published monthly plans are Basic **$0 / 3 requests**, Pro **$9 / 250 requests** and Ultra **$19 / 1,000 requests**, each with a hard request cap. RapidAPI separately discloses platform bandwidth fees on [Pricing](https://rapidapi.com/IsraelDiego/api/calcreceipt/pricing); a hard request cap does not remove those fees. Check current pricing before subscribing or processing. The standalone examples or an Agensi instruction skill do not include API access or a hosted-processing allowance.

The recorded demonstration is an original fictional fixture processed through the actual gateway with the earlier client identified in [demo/verification.json](demo/verification.json). The optional key-file/setup update was checked offline with fake keys and mocked transport, without fresh API requests or an Agensi-connected-session run. The recorded demonstration is not a customer testimonial, paid sale, revenue result or guarantee of correctness for other workbooks.
