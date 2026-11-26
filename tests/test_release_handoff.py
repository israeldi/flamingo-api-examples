"""Exercise the free release handoff checker using inert local fixtures."""

from hashlib import sha256
import json
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
import unittest
import zipfile

SAMPLE = Path(__file__).resolve().parents[1] / "release-handoff"
sys.path.insert(0, str(SAMPLE))
from demo import synthetic_pe_header
import preflight
sys.path.remove(str(SAMPLE))


class ReleaseHandoffTests(unittest.TestCase):
    def setUp(self):
        temporary = TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        self.installer = root / "install.sh"
        self.installer.write_text("#!/bin/bash\n# Inert installer fixture\n")
        self.package = root / "release.zip"

    def check(self, members, *, arch="x64", compare="correct"):
        with zipfile.ZipFile(self.package, "w", compression=zipfile.ZIP_DEFLATED) as archive:
            for name,data in members.items():
                archive.writestr(name, data)
        before = self.package.read_bytes()
        supplied = {"correct": sha256(before).hexdigest(), "incorrect": "a" * 64,
                    "unsupplied": None}[compare]
        report = preflight.inspect(self.installer, self.package, "app.exe", arch, supplied)
        self.assertEqual(self.package.read_bytes(), before)
        return report

    def test_nested_executable_with_case_insensitive_basename(self):
        report = self.check({"release/bin/APP.EXE": synthetic_pe_header()})
        self.assertEqual(report["state"], "ready_for_installer_review")
        self.assertEqual(report["executable_member"], "release/bin/APP.EXE")
        self.assertEqual(report["archive_sha256_comparison"], "matches")

    def test_ambiguous_basename_blocks_handoff(self):
        binary = synthetic_pe_header()
        report = self.check({"a/app.exe": binary, "b/APP.EXE": binary})
        self.assertEqual(report["state"], "blocked")
        self.assertIn("Found 2", report["finding"])

    def test_missing_expected_digest_stays_not_supplied(self):
        report = self.check({"app.exe": synthetic_pe_header(0xAA64)}, arch="arm64", compare="unsupplied")
        self.assertEqual(report["state"], "ready_for_installer_review")
        self.assertEqual(report["archive_sha256_comparison"], "not_supplied")
        self.assertEqual(report["observed_windows_arch"], "arm64")

    def test_wrong_architecture_blocks_handoff(self):
        report = self.check({"app.exe": synthetic_pe_header(0xAA64)})
        self.assertEqual(report["state"], "blocked")
        self.assertEqual(report["observed_windows_arch"], "arm64")

    def test_truncated_pe_header_blocks_handoff(self):
        report = self.check({"app.exe": synthetic_pe_header()[:-5]})
        self.assertEqual(report["state"], "blocked")
        self.assertIn("PE signature", report["finding"])

    def test_digest_mismatch_blocks_handoff(self):
        report = self.check({"app.exe": synthetic_pe_header()}, compare="incorrect")
        self.assertEqual(report["state"], "blocked")
        self.assertEqual(report["archive_sha256_comparison"], "mismatch")

    def test_documented_demo_runs_as_a_command(self):
        run = subprocess.run([sys.executable, "-B", str(SAMPLE / "demo.py")],
                             capture_output=True, text=True, check=True)
        report = json.loads(run.stdout)
        self.assertEqual(report["state"], "ready_for_installer_review")
        self.assertEqual(report["installer_review_and_support_guide"], preflight.BUNDLE)
