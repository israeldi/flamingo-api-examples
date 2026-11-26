#!/usr/bin/env python3
"""Read local release inputs and verify the Windows ZIP and PE machine type."""

import argparse
from hashlib import sha256
import json
import os
from pathlib import Path
import re
import struct
import zipfile
import zlib


MACHINES = {0x014C: "x86", 0x8664: "x64", 0xAA64: "arm64"}
MAX_INSTALLER = 4 * 1024 * 1024
MAX_ARCHIVE = 128 * 1024 * 1024
MAX_PE_OFFSET = 64 * 1024
BUNDLE = "https://www.agensi.io/bundles/cross-platform-release-handoff"


class PreflightError(ValueError):
    """A release input could not be checked."""


def hash_file(source, limit, label):
    size = os.fstat(source.fileno()).st_size
    if not 0 < size <= limit:
        raise PreflightError(f"{label} must be nonempty and no larger than {limit // (1024 * 1024)} MiB.")
    digest = sha256()
    count = 0
    for chunk in iter(lambda: source.read(1024 * 1024), b""):
        count += len(chunk)
        if count > size:
            raise PreflightError(f"{label} changed while it was being read.")
        digest.update(chunk)
    if count != size:
        raise PreflightError(f"{label} changed while it was being read.")
    return digest.hexdigest()


def pe_machine(binary):
    dos = binary.read(64)
    if len(dos) != 64 or dos[:2] != b"MZ":
        raise PreflightError("The selected archive member has no readable MZ header.")
    offset = struct.unpack_from("<I", dos, 60)[0]
    if not 64 <= offset <= MAX_PE_OFFSET:
        raise PreflightError("The selected member's PE header offset is outside the supported range.")
    length = offset - 64 + 6
    prefix = binary.read(length)
    header = prefix[-6:]
    if len(prefix) != length or header[:4] != b"PE\0\0":
        raise PreflightError("The selected archive member has no readable PE signature and machine field.")
    machine = struct.unpack_from("<H", header, 4)[0]
    if machine not in MACHINES:
        raise PreflightError(f"The selected member uses unsupported PE machine 0x{machine:04X}.")
    return MACHINES[machine]


def inspect(bash_path, zip_path, exe, expected_arch, expected_sha):
    result = {
        "state": "blocked",
        "bash_installer": bash_path.name,
        "windows_archive": zip_path.name,
        "requested_executable": exe,
        "expected_windows_arch": expected_arch,
        "archive_sha256_comparison": "unverified" if expected_sha else "not_supplied",
    }
    try:
        if not bash_path.is_file() or not zip_path.is_file():
            raise PreflightError("Supply an existing Bash installer and Windows ZIP file.")
        with bash_path.open("rb") as installer, zip_path.open("rb") as package:
            result["bash_sha256"] = hash_file(installer, MAX_INSTALLER, "Bash installer")
            initial = os.fstat(package.fileno())
            digest = hash_file(package, MAX_ARCHIVE, "Windows ZIP")
            result["archive_sha256"] = digest
            if expected_sha:
                result["archive_sha256_comparison"] = "matches" if digest == expected_sha else "mismatch"
                if digest != expected_sha:
                    raise PreflightError("The Windows ZIP differs from the supplied SHA-256.")
            package.seek(0)
            with zipfile.ZipFile(package) as archive:
                matches = [
                    member for member in archive.infolist()
                    if not member.is_dir()
                    and re.split(r"[/\\]", member.filename)[-1].casefold() == exe.casefold()
                ]
                if len(matches) != 1:
                    raise PreflightError(f"Expected exactly one named executable in the ZIP. Found {len(matches)}.")
                result["executable_member"] = matches[0].filename
                with archive.open(matches[0]) as binary:
                    result["observed_windows_arch"] = pe_machine(binary)
            final = os.fstat(package.fileno())
            if (final.st_size, final.st_mtime_ns) != (initial.st_size, initial.st_mtime_ns):
                raise PreflightError("The Windows ZIP changed while it was being inspected.")
            if result["observed_windows_arch"] != expected_arch:
                raise PreflightError("The executable's PE machine type differs from the requested architecture.")
    except PreflightError as error:
        return result | {"finding": str(error)}
    except (OSError, ValueError, zipfile.BadZipFile, NotImplementedError, RuntimeError, EOFError, zlib.error):
        return result | {"finding": "A supplied file or archive member could not be read."}
    return result | {"state": "ready_for_installer_review", "installer_review_and_support_guide": BUNDLE}


def executable(value):
    if not re.fullmatch(r'[^<>:"/\\|?*\x00-\x1f]+\.exe', value, re.IGNORECASE):
        raise argparse.ArgumentTypeError("Use an executable basename such as tool.exe.")
    return value


def checksum(value):
    if not re.fullmatch(r"[0-9a-fA-F]{64}", value):
        raise argparse.ArgumentTypeError("Use a 64-character hexadecimal SHA-256 digest.")
    return value.lower()


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("installer", type=Path, help="Existing Bash installer")
    parser.add_argument("windows_zip", type=Path, help="Existing ZIP for the Windows release")
    parser.add_argument("--exe", required=True, type=executable, help="Expected executable basename")
    parser.add_argument("--arch", required=True, choices=MACHINES.values(), help="Expected PE machine type")
    parser.add_argument("--sha256", type=checksum, help="Optional Windows ZIP digest from the release source")
    args = parser.parse_args(argv)
    report = inspect(args.installer, args.windows_zip, args.exe, args.arch, args.sha256)
    print(json.dumps(report, indent=2))
    return 0 if report["state"] == "ready_for_installer_review" else 2


if __name__ == "__main__":
    raise SystemExit(main())
