# ArchiveProof client and original samples

Check authorized PDF bytes against an explicit PDF/A profile, or compare rule-level findings before and after a change. Receive a new receipt and original veraPDF XML reports. Python 3.10+ and a working HTTPS trust store are required; no local Java or additional Flamingo MCP server is needed.

**API published:** [ArchiveProof on RapidAPI](https://rapidapi.com/IsraelDiego/api/archiveproof). The publication setting was verified after reload. The authenticated Synology origin passes strict TLS and denial checks. The published standalone client completed five HTTP 200 requests through the real RapidAPI gateway on the saved Basic five-request plan; the sixth request received an explicit platform monthly HTTP 429. Anonymous listing and published-pricing visibility remain unverified. The complete original-fixture evidence is linked below.

## Set up access once

A subscription does not configure the agent's credentials. Use your own separately subscribed RapidAPI application key on the same host and OS user that runs the client.

If that execution environment already has `RAPIDAPI_KEY`, reuse it. Otherwise review [setup_key.py](setup_key.py) and run it in your own private interactive Terminal:

```sh
python3 setup_key.py
```

Enter the existing application key at the hidden prompt. Setup makes no network or processing request and creates a new `~/.config/flamingo-api/rapidapi.key` with mode600 inside an owned mode700 directory. It refuses an existing file, symlinked paths, a Git project destination, non-Terminal input or echo fallback. It does not rotate or overwrite a key. Never attach the file or paste its contents into chat.

A laptop key file cannot configure a remote agent host. Use that host's own private Terminal or existing secure secret settings for the agent OS user. No restart or shell export is needed for the explicit key-file route on the same host and user.

## Get a first result

Use [archive-before.pdf](archive-before.pdf), the original fictional PDF/A-2b sample. With a configured private file:

```sh
python3 client.py validate archive-before.pdf --profile 2b --key-file "$HOME/.config/flamingo-api/rapidapi.key" --output-dir new-validation
```

Expected: a new directory containing `receipt.json` and `input-verapdf.xml`; the sample is compliant with PDF/A-2b under veraPDF1.30.3. One command is one metered request. When the client already has `RAPIDAPI_KEY` privately configured, omit `--key-file`.

For a separate before/after check:

```sh
python3 client.py compare archive-before.pdf archive-after.pdf --profile 2b --key-file "$HOME/.config/flamingo-api/rapidapi.key" --output-dir new-comparison
```

The after sample intentionally omits XMP metadata and is noncompliant: rule6.6.2.1/test1 is added. The comparison saves the complete receipt plus before/after XML reports. It is another request, not a step the first validation needs. HTTP200 means processing completed, including a noncompliant result.

Choose a new output directory every time. The client checks source/report hashes, refuses redirects and preserves existing work. It never retries automatically; a timeout can still consume allowance. An explicit invalid key file fails locally without environment fallback. Malformed key values are refused before HTTP. Key-file/setup behavior passed offline fake-key checks; the complete recorded hosted-gateway demonstration follows.

Profiles:1b,2b,2u,3b,3u,4. Maximum4MiB perPDF,12MiBJSON,75seconds perjob. One comparison validates two PDFs in one request. Read [the full contract](CONTRACT.md) and current marketplace pricing before a batch. Separate RapidAPI bandwidth fees can apply.

This checks selected machine-verifiable PDF/A requirements. It does not convert, repair, compare visible content, certify accessibility or guarantee archive acceptance. Protect receipts as potentially sensitive document evidence.

These original client/helper/sample materials are supplied for downloading and running with ArchiveProof. The server's veraPDF engine is not included in this directory and retains its own licence.

## Complete recorded gateway demonstration

The original [before PDF](archive-before.pdf) and [after PDF](archive-after.pdf) were compared under PDF/A-2b with veraPDF 1.30.3 through `archiveproof.p.rapidapi.com`. The before document passed 144 rules and 829 checks with no failed rules/checks. The after document passed 143 rules and 789 checks, with one failed rule/check: ISO 19005-2:2011 clause 6.6.2.1/test 1. The receipt reports one added rule, zero resolved rules and zero persistent rules.

| Original fictional source | Bytes | Result | SHA-256 |
| --- | ---: | --- | --- |
| `archive-before.pdf` | 23,523 | Compliant | `5c3eb511e4ea5d13378498cb0a568330a60d28eebc9804970ae7feb7d30cabe6` |
| `archive-after.pdf` | 22,800 | Noncompliant | `d36a7a11971f3e53c9adca6adc93120f48269757c370d5354df40712e1f55390` |

Download the complete [comparison receipt](demo/receipt.json), [original before XML](demo/before-verapdf.xml), [original after XML](demo/after-verapdf.xml), and [gateway verification record](demo/verification.json). The 9,199-byte saved receipt contains both complete XML reports; their exact decoded bytes and hashes are verified by the client and included in [checksums.json](checksums.json).

The November 1 Basic five-request sequence made **six requests: five HTTP 200 responses, followed by one monthly HTTP 429**. It covered regression comparison, separate compliant/noncompliant validations, reversed comparison showing the resolved rule, and one comparison of two exact 4 MiB synthetic PDFs. The gateway's remaining-request headers counted 4, 3, 2, 1, 0 before the explicit platform denial. The verification record identifies the exact standalone client commit and SHA-256, request/response identities, and metadata for all five saved artifact sets. TLS verification stayed enabled; no redirects or automatic retries were permitted.

An [earlier deprecated-plan preflight](demo/deprecated-plan-preflight.json) made one HTTP 200 request, then stopped because it observed the old 500,000-request allowance instead of the intended Basic five-request limit. It is retained separately and is not the current-plan quota qualification. Across those two recorded sequences there were seven actual gateway requests: six completed HTTP 200 calculations and one monthly HTTP 429.

This is evidence for the original fictional PDF/A-2b fixtures and that single per-PDF byte-ceiling case. Other documents, sustained load, all-profile gateway coverage, paid checkout, archive acceptance and Agensi-connected execution were not established by this run. No customer use, sale, income or payout is claimed. The saved monthly hard caps are Basic $0 / 5 requests, Pro $7 / 200 and Ultra $15 / 750; anonymous listing and published-pricing visibility remain unverified. Platform bandwidth fees were not tested or inferred.
