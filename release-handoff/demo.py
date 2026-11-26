#!/usr/bin/env python3
"""Run the release handoff preflight against original synthetic metadata."""

from hashlib import sha256
from pathlib import Path
import struct
from tempfile import TemporaryDirectory
import zipfile

import preflight


def synthetic_pe_header(machine=0x8664):
    """Return a diagnostic header fixture with no executable body."""
    data = bytearray(70)
    data[:2] = b"MZ"
    struct.pack_into("<I", data, 60, 64)
    data[64:68] = b"PE\0\0"
    struct.pack_into("<H", data, 68, machine)
    return bytes(data)


def main():
    with TemporaryDirectory() as directory:
        root = Path(directory)
        installer = root / "install.sh"
        installer.write_bytes(b"#!/usr/bin/env bash\n# Synthetic installer review input.\n")
        package = root / "cedar-notes-windows.zip"
        with zipfile.ZipFile(package, "w") as archive:
            archive.writestr(zipfile.ZipInfo("cedar-notes/CedarNotes.exe"), synthetic_pe_header())
        digest = sha256(package.read_bytes()).hexdigest()
        return preflight.main([str(installer), str(package), "--exe", "cedarnotes.exe",
                               "--arch", "x64", "--sha256", digest])


if __name__ == "__main__":
    raise SystemExit(main())
