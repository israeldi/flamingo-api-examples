"""Upload one authorized XLSX through RapidAPI and save verified new artifacts."""
import argparse
import base64
import hashlib
import io
import json
import os
from pathlib import Path
import re
import stat
import urllib.error
import urllib.request
import zipfile

# Observed in the actual RapidAPI endpoint request example.
ASSIGNED_HOST = 'calcreceipt.p.rapidapi.com'
MAX_KEY_BYTES = 4096


class KeyFileError(ValueError):
    pass


def read_key_file(path):
    """Read only an owned private file through non-symlink directory handles."""
    message = ('Use an owned private POSIX key file (mode 600 or 400) in an owned '
               '700 directory, without links, containing one ASCII key line.')
    directory = descriptor = None
    try:
        if os.name != 'posix' or not all(hasattr(os, name) for name in ('O_NOFOLLOW', 'O_NONBLOCK', 'O_DIRECTORY', 'geteuid')):
            raise KeyFileError(message)
        absolute = Path(os.path.abspath(Path(path).expanduser()))
        directory_flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_NONBLOCK
        directory = os.open(absolute.anchor, directory_flags)
        for part in absolute.parts[1:-1]:
            child = os.open(part, directory_flags, dir_fd=directory)
            os.close(directory)
            directory = child
        parent = os.fstat(directory)
        if parent.st_uid != os.geteuid() or stat.S_IMODE(parent.st_mode) != 0o700:
            raise KeyFileError(message)
        descriptor = os.open(absolute.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
        before = os.fstat(descriptor)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != os.geteuid()
                or stat.S_IMODE(before.st_mode) not in (0o600, 0o400)
                or before.st_nlink != 1 or not 0 < before.st_size <= MAX_KEY_BYTES):
            raise KeyFileError(message)
        raw = os.read(descriptor, MAX_KEY_BYTES + 1)
        signature = lambda info: (info.st_dev, info.st_ino, info.st_size, info.st_mode,
                                  info.st_uid, info.st_nlink, info.st_mtime_ns, info.st_ctime_ns)
        if (len(raw) != before.st_size or len(raw) > MAX_KEY_BYTES
                or signature(before) != signature(os.fstat(descriptor))
                or signature(before) != signature(os.stat(absolute.name, dir_fd=directory, follow_symlinks=False))):
            raise KeyFileError(message)
        raw = raw[:-2] if raw.endswith(b'\r\n') else raw[:-1] if raw.endswith(b'\n') else raw
        if not raw or any(value < 33 or value > 126 for value in raw):
            raise KeyFileError(message)
        return raw.decode('ascii')
    except (OSError, TypeError, ValueError) as exc:
        if isinstance(exc, KeyFileError):
            raise
        raise KeyFileError(message) from None
    finally:
        if descriptor is not None:
            os.close(descriptor)
        if directory is not None:
            os.close(directory)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_):
        return None


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('input', type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--report', required=True, type=Path)
    parser.add_argument('--key-file', type=Path, help='Optional private key file; otherwise use RAPIDAPI_KEY.')
    args = parser.parse_args(argv)
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
    try:
        key = read_key_file(args.key_file) if args.key_file is not None else os.environ.get('RAPIDAPI_KEY', '')
    except KeyFileError as exc:
        parser.error(str(exc))
    if not key:
        parser.error('Configure your own subscribed RAPIDAPI_KEY privately in the environment.')
    if not isinstance(key, str) or not 16 <= len(key) <= 512 or any(ord(value) < 33 or ord(value) > 126 for value in key):
        parser.error('Configure a valid private RapidAPI application key: 16 to 512 printable ASCII characters without whitespace.')
    if key.encode('ascii') in source:
        parser.error('The selected input contains the literal configured credential bytes; choose an input without credentials.')
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
