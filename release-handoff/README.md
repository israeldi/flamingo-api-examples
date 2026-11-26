# Check the inputs for a Windows installer handoff

Use the preflight to fingerprint your Bash installer and check that your Windows ZIP contains one expected executable for the intended architecture. It reads both files locally with Python 3.10 or later and the standard library. It reads just the selected member's headers and leaves your files in place.

## Try the fictional release

From the repository root, run the demo with temporary synthetic inputs.

```sh
python3 -B release-handoff/demo.py
```

The JSON should show `ready_for_installer_review`, `matches` for the archive digest, and `x64` for the member `cedar-notes/CedarNotes.exe`. The demo contains a synthetic header with no runnable program.

## Check your own files

```sh
python3 -B release-handoff/preflight.py ./install.sh ./mytool-windows.zip \
  --exe mytool.exe --arch x64
```

Use `--arch arm64` or `--arch x86` for those builds. Add `--sha256 ZIP_DIGEST` with the ZIP's 64-character SHA-256 from a trusted release record if you have it. The report labels an omitted digest `not_supplied`. A mismatch, ambiguous filename, or unreadable header yields `blocked` and exit status 2. The preflight accepts a nonempty installer up to 4 MiB and ZIP up to 128 MiB.

A ready report shows the file fingerprints, selected member, and PE machine type. Executable provenance, installer behavior, and installation tests remain separate review steps.

## Prepare the installer and support guide

Use **InstallTwin** with your Bash installer and Windows release facts to prepare a PowerShell counterpart and project-specific tests. After validating the procedure, use **GuideBranch** to prepare the interactive support guide. The [fictional GuideBranch example](../guidebranch/) shows the finished guide and the support procedure it came from.

[Buy InstallTwin and GuideBranch together on Agensi](https://www.agensi.io/bundles/cross-platform-release-handoff).
