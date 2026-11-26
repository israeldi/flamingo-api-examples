"""Native HTTP uploads through the plugin helper and unmodified WordPress core.

Run a native PHP development server bound to 127.0.0.1 before running this file.
The bootstrap intentionally stubs database/authentication boundaries; this is
upload-component verification, not an authenticated WooCommerce integration test.
"""
import hashlib
import json
import os
from pathlib import Path
import sys
import urllib.request
import uuid

URL = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:18739"

def post_form(data, files):
    boundary = "ShelfRelayTest" + uuid.uuid4().hex
    parts = []
    for name, value in data.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    for name, (filename, body, content_type) in files.items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{filename}"\r\nContent-Type: {content_type}\r\n\r\n'.encode())
        parts.extend([body, b"\r\n"])
    parts.append(f'--{boundary}--\r\n'.encode())
    request = urllib.request.Request(URL, b"".join(parts), {"Content-Type": f"multipart/form-data; boundary={boundary}"})
    with urllib.request.urlopen(request, timeout=30) as response:
        return response.status, response.read().decode()
XML = b'<?xml version="1.0" encoding="UTF-8"?><ONIXMessage release="3.0"><Product><RecordReference>fixture</RecordReference></Product></ONIXMessage>'
BARE = b'<ONIXMessage release="3.1"><Product><RecordReference>fixture</RecordReference></Product></ONIXMessage>'
SHORT = b'<ONIXmessage release="3.0"><product><a001>fixture</a001></product></ONIXmessage>'
DTD = b'<?xml version="1.0"?><!DOCTYPE ONIXMessage [<!ENTITY test SYSTEM "file:///etc/hostname">]><ONIXMessage release="3.0"><Product>&test;</Product></ONIXMessage>'

cases = [
    ("valid", XML, "catalog.xml", True),
    ("declarationless", BARE, "catalog.xml", True),
    ("uppercase", XML, "catalog.XML", True),
    ("short_tags", SHORT, "catalog.xml", True),
    ("wrong_extension", XML, "catalog.php", False),
    ("malformed", XML[:-12], "catalog.xml", False),
    ("dtd", DTD, "catalog.xml", False),
    ("wrong_root", b'<catalog release="3.0"/>', "catalog.xml", False),
    ("wrong_release", b'<ONIXMessage release="2.1"/>', "catalog.xml", False),
    ("empty", b"", "catalog.xml", False),
    ("oversize", b"x" * (25 * 1024 * 1024 + 1), "catalog.xml", False),
    ("forged_tmp", XML, "catalog.xml", False),
    ("fake_size", XML, "catalog.xml", True),
    ("prior_libxml_error", XML, "catalog.xml", True),
    ("php_error", XML, "catalog.xml", False),
    ("prefilter_error", XML, "catalog.xml", False),
    ("throw_before_move", XML, "catalog.xml", False),
    ("short_circuit_move", XML, "catalog.xml", False),
    ("reject_core_type", XML, "catalog.xml", False),
    ("redirect_directory", XML, "catalog.xml", False),
    ("foreign_result", XML, "catalog.xml", False),
    ("nested_upload_before", XML, "catalog.xml", True),
    ("nested_sideload_before", XML, "catalog.xml", True),
    ("nested_upload_after", XML, "catalog.xml", True),
    ("temp_inside_webroot", XML, "catalog.xml", False),
]
results = []
failures = []
for name, payload, filename, expected in cases:
    # Deliberately lie about the browser MIME even for successful cases.
    files = {"feed": (filename, payload, "application/x-untrusted-browser-value")}
    if "nested_upload" in name:
        files["other"] = ("unrelated.txt", b"separate valid upload", "text/plain")
    status, body = post_form({"case": name, "action": "shelfrelay"}, files)
    try:
        result = json.loads(body)
    except ValueError:
        result = {"case": name, "response_status": status, "body": body[:4000]}
        results.append(result)
        failures.append((name, "not JSON"))
        continue
    results.append(result)
    checks = {
        "expected acceptance": result.get("accepted") is expected,
        "genuine HTTP upload": result.get("native_upload") is True,
        "hook cleanup": result.get("hooks_restored") is True,
        "directory restored": result.get("normal_directory_restored") is True,
        "MIME restored": result.get("normal_mimes_restored") is True,
        "unrelated file preserved": result.get("unrelated_file_preserved") is True,
        "only owned file remains": result.get("private_files_remaining") == (1 if expected else 0),
    }
    if expected:
        checks.update({
            "same bytes": result.get("same_bytes") is True,
            "0600 file": result.get("file_mode") == 0o600,
            "0700 directory": result.get("dir_mode") == 0o700,
            "private destination": result.get("private_directory") is True,
            "PHP moved upload": result.get("native_temporary_file_moved") is True,
            "post hook directory restored": result.get("observations", {}).get("post_hook_normal_directory") is True,
            "post hook MIME restored": result.get("observations", {}).get("post_hook_normal_mimes") is True,
        })
    if name in ("nested_upload_before", "nested_sideload_before"):
        checks["nested upload blocked"] = bool(result.get("observations", {}).get("nested_result", {}).get("error"))
    if name == "nested_upload_after":
        nested = result.get("observations", {}).get("nested_result", {})
        checks["later upload succeeds normally"] = not nested.get("error") and "/wp-content/uploads/" in nested.get("file", "")
    bad = [label for label, ok in checks.items() if not ok]
    if bad:
        failures.append((name, bad))

plugin = Path(os.environ["SHELFRELAY_PLUGIN_SOURCE"])
report = {
    "runtime": json.load(urllib.request.urlopen(URL, timeout=5)),
    "scope": "Native multipart PHP and unmodified WordPress upload subsystem; environment/authentication stubbed; no live WooCommerce/database/API integration.",
    "source_sha256": {str(p.relative_to(plugin)): hashlib.sha256(p.read_bytes()).hexdigest() for p in [plugin / "includes/class-shelfrelay.php", plugin / "includes/class-shelfrelay-feed.php"]},
    "passed": len(results) - len(failures),
    "total": len(results),
    "failures": failures,
    "cases": results,
}
out = Path(__file__).with_name("upload-results.json")
out.write_text(json.dumps(report, indent=2) + "\n")
print(json.dumps({k: report[k] for k in ("runtime", "passed", "total", "failures", "source_sha256")}, indent=2))
sys.exit(bool(failures))
