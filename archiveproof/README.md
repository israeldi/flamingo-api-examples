# ArchiveProof client and original demo

Validate an authorized local PDF against an explicit PDF/A profile, or compare original and transformed files using the same pinned veraPDF1.30.3 engine. This client requires only Python 3.10+ with a configured HTTPS trust store. It does not require local Java.

This directory is a prepared release candidate. The assigned host is archiveproof.p.rapidapi.com and private plans are saved. Public processing is not yet enabled; the gateway demonstration and Agensi listing remain pending.

After the hosted release is verified, configure your subscribed consumer application key privately as `RAPIDAPI_KEY`, then run:

```sh
python3 client.py validate archive-before.pdf --profile 2b --output-dir new-validation
python3 client.py compare archive-before.pdf archive-after.pdf --profile 2b --output-dir new-comparison
```

Use a new output directory. The client verifies exact source and raw-report hashes, refuses redirects and preserves existing output. It never retries automatically. A completed noncompliant report is HTTP200 and can consume a request. Protect the generated reports as potentially sensitive evidence.

The original fictional before PDF passes PDF/A-2b under veraPDF1.30.3; the after PDF deliberately omits its XMP metadata and fails rule6.6.2.1/test1. Both rendered demo pages match. The hosted gateway demonstration will be recorded separately and must not be inferred from local testing.

Profiles:1b,2b,2u,3b,3u,4. Limit4MiB perPDF,12MiBJSON,75seconds perjob. Read [the complete contract](CONTRACT.md). One comparison is a single request containing two validations.

This checks selected machine-verifiable PDF/A requirements. It does not convert, repair, compare visible content, certify accessibility or guarantee acceptance by any archive.

These are the creator's independent client and fictional demonstration materials, supplied for downloading, saving and running with ArchiveProof. Agensi instruction delivery is separate. The veraPDF engine retains its own licence and is not included in this client directory.
