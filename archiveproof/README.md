# ArchiveProof client and original samples

Check authorized PDF bytes against an explicit PDF/A profile, or compare rule-level findings before and after a change. Receive a new receipt and original veraPDF XML reports. Python 3.10+ and a working HTTPS trust store are required; no local Java or additional Flamingo MCP server is needed.

**Prepared release:** the authenticated Synology origin now passes strict TLS and denial checks. The RapidAPI listing remains private until the actual gateway qualification finishes. This source release is not a claim that customer processing is already public.

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

Choose a new output directory every time. The client checks source/report hashes, refuses redirects and preserves existing work. It never retries automatically; a timeout can still consume allowance. An explicit invalid key file fails locally without environment fallback. Malformed key values are refused before HTTP. The key-file/setup changes passed offline fake-key checks; the actual hosted gateway demonstration is recorded separately when available.

Profiles:1b,2b,2u,3b,3u,4. Maximum4MiB perPDF,12MiBJSON,75seconds perjob. One comparison validates two PDFs in one request. Read [the full contract](CONTRACT.md) and current marketplace pricing before a batch. Separate RapidAPI bandwidth fees can apply.

This checks selected machine-verifiable PDF/A requirements. It does not convert, repair, compare visible content, certify accessibility or guarantee archive acceptance. Protect receipts as potentially sensitive document evidence.

These original client/helper/sample materials are supplied for downloading and running with ArchiveProof. The server's veraPDF engine is not included in this directory and retains its own licence.
