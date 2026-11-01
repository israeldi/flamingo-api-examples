"""Upload one authorized XLSX through RapidAPI and save verified new artifacts."""
import argparse
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import re
import urllib.error
import urllib.request
import zipfile

# Observed in the actual RapidAPI endpoint request example.
ASSIGNED_HOST = 'calcreceipt.p.rapidapi.com'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_):
        return None


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input', type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--report', required=True, type=Path)
    args = parser.parse_args()
    if not re.fullmatch(r'[a-z0-9-]+\.p\.rapidapi\.com', ASSIGNED_HOST):
        parser.error('This candidate client has no verified assigned gateway yet.')
    paths = [args.output.absolute(), args.report.absolute()]
    if paths[0] == paths[1] or any(p.exists() or p.is_symlink() for p in paths):
        parser.error('Choose two distinct new output paths; existing files are preserved.')
    if not args.input.is_file() or args.input.stat().st_size > 4 * 1024 * 1024:
        parser.error('Choose a local XLSX no larger than4MiB.')
    source = args.input.read_bytes()
    if not source or len(source) > 4 * 1024 * 1024:
        parser.error('Input must be nonempty and no larger than4MiB.')
    key = os.environ.get('RAPIDAPI_KEY', '')
    if not key:
        parser.error('Configure your own subscribed RAPIDAPI_KEY privately in the environment.')
    request = urllib.request.Request('https://' + ASSIGNED_HOST + '/v1/recalculate', data=source,
                                     headers={'X-RapidAPI-Key': key, 'X-RapidAPI-Host': ASSIGNED_HOST,
                                              'Content-Type': 'application/octet-stream'}, method='POST')
    try:
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=90) as response:
            body = response.read(24 * 1024 * 1024 + 1)
    except urllib.error.HTTPError as exc:
        print(json.dumps({'http_status': exc.code, 'retry_after': exc.headers.get('Retry-After'),
                          'message': 'Request failed; inspect your allowance and supported input. No automatic retry or output files.'}))
        return 1
    except (OSError, TimeoutError):
        print('HTTPS request did not complete; no files saved. A retry may consume another request.')
        return 1
    if len(body) > 24 * 1024 * 1024:
        raise ValueError('Response exceeds the published bound')
    result = json.loads(body)
    report = result['report']
    output = base64.b64decode(result['workbook_base64'], validate=True)
    if (report['input_sha256'] != hashlib.sha256(source).hexdigest()
            or report['output_sha256'] != hashlib.sha256(output).hexdigest()
            or report['input_bytes'] != len(source) or report['output_bytes'] != len(output)):
        raise ValueError('Delivered artifact integrity check failed')
    with zipfile.ZipFile(io.BytesIO(output)) as archive:
        if (len(archive.infolist()) > 1024
                or sum(item.file_size for item in archive.infolist()) > 32 * 1024 * 1024
                or archive.testzip() is not None):
            raise ValueError('Delivered workbook archive is invalid')
    encoded_report = (json.dumps(report, indent=2, ensure_ascii=True, allow_nan=False) + '\n').encode()
    created = []
    try:
        for path, data in zip(paths, (output, encoded_report)):
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), 0o600)
            created.append(path)
            with os.fdopen(fd, 'wb') as handle:
                handle.write(data)
                handle.flush()
                os.fsync(handle.fileno())
    except BaseException:
        for path in created:
            path.unlink(missing_ok=True)
        raise
    print(json.dumps({'output': str(paths[0]), 'report': str(paths[1]),
                      'review_required': report.get('review_required'),
                      'message': 'Artifacts saved; inspect formula errors and unrefreshed features before delivery.'}))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
