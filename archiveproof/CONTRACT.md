# ArchiveProof API contract, version 0.1.0

ArchiveProof checks PDFs against an explicitly selected PDF/A archival profile with veraPDF 1.30.3. It returns source-linked validation evidence. It does not modify, repair, convert, render or certify a PDF.

This document describes a release candidate. The consumer host and private plan configuration have been observed. The authenticated origin and strict TLS are now configured. Actual gateway processing, final source/demo pins and marketplace publication remain separate release gates.

## Requests

Use `POST /v1/validate` with JSON:

```json
{"profile":"2b","pdf_base64":"BASE64_OF_AUTHORIZED_PDF"}
```

Use `POST /v1/compare` with JSON:

```json
{"profile":"2b","before_base64":"BASE64_OF_ORIGINAL_PDF","after_base64":"BASE64_OF_TRANSFORMED_PDF"}
```

Each request chooses exactly one profile from `1b`, `2b`, `2u`, `3b`, `3u`, `4`. These designate PDF/A-1b, PDF/A-2b, PDF/A-2u, PDF/A-3b, PDF/A-3u and PDF/A-4 respectively. The PDF's own conformance declaration does not choose the requested profile or establish a pass. Both documents in a comparison use the same selected profile and engine build. Selection should follow the actual recipient's archival requirement; ArchiveProof does not choose that requirement for you.

Each decoded PDF is nonempty and at most 4 MiB (4,194,304 bytes). JSON request bodies are at most 12 MiB. Send raw base64 data, not a `data:` URL. Remote URL fetching, password input, automatic repair, user-supplied validation profiles, arbitrary engine options and arbitrary filesystem paths are not supported. Invalid or unsupported documents may be refused before validation. Files that exhaust the bounded processing budget do not receive a conformance verdict. PDFs requiring a nonempty password are refused. An encrypted PDF openable without a password may complete as noncompliant under the selected archival profile; the API does not remove its encryption.

The service runs one job at a time, with a 75-second overall job budget. A comparison includes two validations within that job budget. Concurrent work can receive a busy response. This is a bounded synchronous API, not a batch archival repository.

The assigned consumer gateway is `https://archiveproof.p.rapidapi.com`. The standalone client uses only this fixed HTTPS host, performs ordinary certificate verification and does not follow redirects. It uses `RAPIDAPI_KEY` by default, or an explicit private `--key-file`; it sends the selected subscribed application key in `X-RapidAPI-Key` and sets `X-RapidAPI-Host` to the same gateway. A subscribed consumer key is separate from a provider's private origin secret. The client does not accept or require that provider secret.

The optional `--local-port` switch is for operator diagnostics only. It targets HTTP on fixed `127.0.0.1`, permits ports 1024–65535 and cannot select an arbitrary remote host. Public consumer instructions use the HTTPS gateway.

## Private credential setup

Subscribing does not populate the agent execution environment. Reuse an existing private `RAPIDAPI_KEY`, or run the included `setup_key.py` in the user's own private interactive Terminal on the **same host and OS user** as the client. Setup uses a hidden prompt without echo fallback, makes no HTTP/processing request, and exclusively creates `~/.config/flamingo-api/rapidapi.key` with mode600 in an owned mode700 directory outside a Git project. It never overwrites or rotates an existing key and refuses symlinked paths.

Pass that absolute path with `--key-file`. An explicit file takes precedence; failure never falls back to environment authentication. The reader uses directory descriptors and nofollow/nonblock flags, requires an owned regular600/400 file with one link and owned700 immediate parent, rejects symlink path components, bounds the file to4096bytes and checks stable file identity/metadata. The file contains one printable ASCII key line, optionally followed by LF/CRLF. A key value must be16–512 printable ASCII characters without whitespace. This is a credential format bound, not subscription verification.

A laptop file cannot configure a remote host. Use that host's own private Terminal or secure secret settings. Never attach credential files to chat. Setup and key loading require POSIX semantics; the existing environment route remains available. The client refuses selected PDF bytes containing the literal configured key; this is a literal-byte guard, not a general sensitive-content scan.

## Successful receipts

HTTP 200 means validation completed and the receipt was produced. It does not imply the PDF complied. Each document has `status: "compliant"` or `status: "noncompliant"` and the corresponding Boolean `compliant`.

The versioned envelope contains:

- `schema_version`: `archiveproof.receipt.v1`.
- `operation`: `validate` or `compare`.
- `profile`: the explicitly requested profile.
- `engine`: veraPDF's name, version and component versions.
- `documents`: one `input` document for validation, or `before` and `after` for comparison.
- `comparison`: rule-count differences for comparison requests.

Each document includes the exact input's `input_sha256` and `input_bytes`, validation status, counts, `failed_rules`, and `raw_report`. A failed rule identifies its `specification`, `clause`, `test_number` and `failed_checks`; further engine-provided explanation or contexts may be present. Read the report rather than inferring a defect solely from its count.

`raw_report` contains `format: "xml"`, the exact unmodified veraPDF XML in `base64`, and its `bytes` and `sha256`. Each XML report is at most 2 MiB. Total delivered JSON is bounded at 24 MiB. A report exceeding its limit produces a processing failure, not a truncated report presented as complete. The returned report names the engine-generated temporary path ending in `input.pdf`; the input hash binds it to the caller's actual file. Original source filenames are not sent.

The standalone client verifies request operation/profile, expected engine version, document set, source hashes and byte counts, status consistency, and decoded XML report hashes and byte counts. It then creates a new output directory containing:

- `receipt.json`: the complete delivered envelope, including the report base64.
- `input-verapdf.xml`, or `before-verapdf.xml` and `after-verapdf.xml`: the decoded original reports.

The client creates new files with restrictive permissions, preserves existing output directories, and does not save another copy of the source PDFs. Retain authorized originals independently and protect the generated reports as potentially sensitive document evidence. A hash is an integrity link, not a digital signature, independent certification or proof of a provider's truthfulness.

## Interpreting a comparison

Comparison reports changes in the sets and failed-check counts of rules. An introduced rule now fails and did not previously appear as failing; a resolved rule no longer appears as failing; a persisting rule fails in both reports. Read the exact returned comparison fields and the two raw reports.

These are rule-level observations. Different objects can fail the same rule in the two PDFs. Object identifiers or diagnostic contexts can change during conversion. No correspondence between individual defective objects is established. A resolved rule does not demonstrate that the original content was preserved, and a lower total does not prove the transformed PDF is better. Equal counts do not prove identical documents or identical defects.

The API does not compare visible page appearance, reading meaning, text completeness, signatures or legal effect. It does not guarantee acceptance by an archive, court, customer or submission portal. Recipient-specific policies and independent content/visual checks remain separate.

## Errors and retries

Non-200 responses return an `error` object with a code and message when the origin can produce JSON. Gateway-generated errors may have a different body. Malformed requests, unsupported profiles or PDFs, oversized inputs, busy processing, exhausted allowance, timeouts, memory exhaustion and engine/report failures remain separate from completed noncompliance.

An engine failure, timeout or incomplete comparison does not produce a partial successful receipt. Unknown validation is not a failure-of-conformance verdict and is never a pass.

The client does not automatically retry, follow redirects, change plans, buy quota, reset allowance or fall back to a different engine. A request can consume allowance even when the client loses its response. On a 429, inspect `Retry-After` and the RapidAPI dashboard to distinguish the gateway's monthly allowance from an origin busy/rate limit. Retry only after the cause is understood; do not promise that errors are unmetered.

## Boundaries and data handling

This release addresses the selected PDF/A profiles, not PDF/UA, WCAG, accessibility certification, accessibility remediation, malware detection, secure redaction or regulatory/legal advice. No rendering or perceptual comparison is performed. A PDF/A-conforming file can still contain wrong information, and an invalid archival file can still look correct.

The backend is intended for bounded transient processing on the owner's Synology, with no document repository or download-link service. Private NAS qualification verified a credential-free validator container with Docker networking disabled and a dedicated transient tmpfs queue. The validator mounts no household shares or Docker socket. The temporary synthetic test container was retired before preparing public activation. Do not claim universal zero retention, that tmpfs can never swap, that intermediaries retain nothing, or an SLA from these instructions. RapidAPI and the consumer's agent/provider have their own processing and logging behavior. Supply only documents authorized for this processing route.

## Access and pricing

The Agensi instruction skill is intended to be free. Hosted API processing requires a separate RapidAPI account, subscription and consumer key. Saved private-draft monthly request caps are Basic $0 / 5, Pro $7 / 200, Ultra $15 / 750, all hard caps. Public availability and final published pricing remain to be verified. One validation or one comparison is one endpoint request; a comparison validates two source PDFs. Platform bandwidth charges, taxes or other checkout terms may apply separately and must be read on the current pricing page. No income or customer adoption is established by implementation or publication.

Agensi delivers skill instructions through its agent connection; it does not host this backend or imply API execution rights. The skill includes a self-contained Python client for temporary execution by a capable connected agent, with original instructions in SKILL.md; it does not install a persistent local skill directory. The creator also supplies independent public client files for standalone use. The client requires Python 3.10+ and its standard library; users do not need a local Java or veraPDF installation or an additional Flamingo MCP bridge to call the hosted service.

## Primary references

- [veraPDF validation and profile rules](https://docs.verapdf.org/validation/).
- [veraPDF command-line profile selection](https://docs.verapdf.org/cli/validation/).
- [Agensi delivery model](https://www.agensi.io/docs/how-agents-load-skills).
- [Agensi permitted listing formats](https://www.agensi.io/docs/what-you-can-sell).

ArchiveProof is independent of Agensi, veraPDF, the Open Preservation Foundation, Adobe and archival recipients. The validation engine and its upstream materials retain their own licences and notices.
