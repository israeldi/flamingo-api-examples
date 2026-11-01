"""Validate authorized PDFs and save verified ArchiveProof receipts in a new directory."""
import argparse
import base64
import binascii
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import ssl
import stat
import sys

GATEWAY_HOST = 'archiveproof.p.rapidapi.com'
PROFILES = ('1b', '2b', '2u', '3b', '3u', '4')
MAX_PDF_BYTES = 4 * 1024 * 1024
MAX_REQUEST_BYTES = 12 * 1024 * 1024
MAX_REPORT_BYTES = 2 * 1024 * 1024
MAX_RESPONSE_BYTES = 24 * 1024 * 1024
ENGINE_VERSION = '1.30.3'
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


class ClientError(Exception):
    pass


def validate_key(key):
    if (not isinstance(key, str) or not 16 <= len(key) <= 512
            or any(ord(char) < 33 or ord(char) > 126 for char in key)):
        raise ClientError('Configure a valid subscribed key privately using RAPIDAPI_KEY or --key-file.')


class GatewayError(ClientError):
    def __init__(self, status, code=None, retry_after=None):
        self.status = status
        self.code = code if isinstance(code, str) and re.fullmatch(r'[a-z0-9_\-]{1,64}', code) else None
        self.retry_after = retry_after if isinstance(retry_after, str) and re.fullmatch(r'[0-9]{1,10}', retry_after) else None
        super().__init__('Request did not succeed. Inspect input support and subscription allowance before a deliberate retry.')


def _no_duplicates(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ClientError('Response JSON has duplicate object keys.')
        result[key] = value
    return result


def _bad_constant(_):
    raise ClientError('Response JSON contains a non-finite number.')


def parse_json(data):
    try:
        return json.loads(data.decode('utf-8'), object_pairs_hook=_no_duplicates, parse_constant=_bad_constant)
    except (ValueError, UnicodeError, RecursionError) as exc:
        raise ClientError('Response is not supported JSON.') from exc


def read_pdf(path):
    try:
        descriptor = os.open(path, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_NONBLOCK', 0))
        with os.fdopen(descriptor, 'rb') as handle:
            info = os.fstat(handle.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_size < 1 or info.st_size > MAX_PDF_BYTES:
                raise ClientError('Each source must be a regular PDF file no larger than 4 MiB.')
            data = handle.read(MAX_PDF_BYTES + 1)
    except OSError as exc:
        raise ClientError('Could not read a source PDF; use an authorized regular file, not a symlink.') from exc
    if not data or len(data) > MAX_PDF_BYTES or not data.startswith(b'%PDF-'):
        raise ClientError('Each source must begin with a PDF header and be no larger than 4 MiB.')
    return data


def request_payload(operation, profile, sources):
    if profile not in PROFILES:
        raise ClientError('Choose one supported explicit PDF/A profile.')
    names = ('input',) if operation == 'validate' else ('before', 'after') if operation == 'compare' else ()
    if not names or set(sources) != set(names):
        raise ClientError('Select validate with one source or compare with two sources.')
    payload = {'profile': profile}
    for name in names:
        data = sources[name]
        if not isinstance(data, bytes) or not data or len(data) > MAX_PDF_BYTES:
            raise ClientError('Each source must be nonempty and no larger than 4 MiB.')
        payload['pdf_base64' if name == 'input' else name + '_base64'] = base64.b64encode(data).decode('ascii')
    encoded = json.dumps(payload, separators=(',', ':'), allow_nan=False).encode('utf-8')
    if len(encoded) > MAX_REQUEST_BYTES:
        raise ClientError('Request exceeds the 12 MiB JSON bound.')
    return encoded


def post(operation, payload, key, local_port=None):
    if operation not in ('validate', 'compare'):
        raise ClientError('Unsupported operation.')
    validate_key(key)
    if local_port is None:
        connection = http.client.HTTPSConnection(GATEWAY_HOST, timeout=120, context=ssl.create_default_context())
    else:
        if type(local_port) is not int or not 1024 <= local_port <= 65535:
            raise ClientError('Local diagnostics accept only a port from 1024 to 65535 on 127.0.0.1.')
        connection = http.client.HTTPConnection('127.0.0.1', local_port, timeout=120)
    headers = {'Content-Type': 'application/json', 'Accept': 'application/json',
               'X-RapidAPI-Key': key, 'X-RapidAPI-Host': GATEWAY_HOST}
    try:
        connection.request('POST', '/v1/' + operation, body=payload, headers=headers)
        response = connection.getresponse()
        body = response.read(MAX_RESPONSE_BYTES + 1)
        if len(body) > MAX_RESPONSE_BYTES:
            raise ClientError('Response exceeds the 24 MiB delivery bound.')
        if response.status != 200:
            code = None
            try:
                result = parse_json(body)
                error = result.get('error', {}) if isinstance(result, dict) else {}
                code = error.get('code') if isinstance(error, dict) else None
            except ClientError:
                pass
            raise GatewayError(response.status, code, response.getheader('Retry-After'))
        if response.getheader('Content-Type', '').split(';', 1)[0].strip().lower() != 'application/json':
            raise ClientError('Gateway did not return application/json.')
        return parse_json(body)
    except (OSError, http.client.HTTPException) as exc:
        raise ClientError('Request did not complete. No automatic retry; another call may consume another request.') from exc
    finally:
        connection.close()


def verified_artifacts(receipt, operation, profile, sources):
    try:
        if not isinstance(receipt, dict) or receipt.get('schema_version') != 'archiveproof.receipt.v1':
            raise ClientError('Unrecognized receipt schema.')
        if receipt.get('operation') != operation or receipt.get('profile') != profile:
            raise ClientError('Receipt operation or selected profile does not match the request.')
        engine = receipt['engine']
        if engine.get('name') != 'veraPDF' or engine.get('version') != ENGINE_VERSION:
            raise ClientError('Receipt engine differs from this client release; obtain the matching client before proceeding.')
        documents = receipt['documents']
        if not isinstance(documents, dict) or set(documents) != set(sources):
            raise ClientError('Receipt source set does not match the request.')
        artifacts = {}
        for name, source in sources.items():
            document = documents[name]
            if (type(document.get('input_bytes')) is not int or document['input_bytes'] != len(source)
                    or document.get('input_sha256') != hashlib.sha256(source).hexdigest()):
                raise ClientError('Receipt source integrity check failed.')
            compliant = document['compliant']
            if type(compliant) is not bool or document.get('status') != ('compliant' if compliant else 'noncompliant'):
                raise ClientError('Receipt result is incomplete or inconsistent.')
            report = document['raw_report']
            if report.get('format') != 'xml' or type(report.get('bytes')) is not int or not 0 < report['bytes'] <= MAX_REPORT_BYTES:
                raise ClientError('Raw report metadata is unsupported.')
            encoded = report['base64']
            if not isinstance(encoded, str) or len(encoded) > 4 * ((MAX_REPORT_BYTES + 2) // 3):
                raise ClientError('Raw report encoding exceeds its bound.')
            raw = base64.b64decode(encoded, validate=True)
            if len(raw) != report['bytes'] or hashlib.sha256(raw).hexdigest() != report.get('sha256'):
                raise ClientError('Raw report integrity check failed.')
            artifacts[name + '-verapdf.xml'] = raw
        artifacts['receipt.json'] = (json.dumps(receipt, indent=2, ensure_ascii=True, allow_nan=False) + '\n').encode('utf-8')
        return artifacts
    except (KeyError, TypeError, AttributeError, ValueError, binascii.Error, RecursionError) as exc:
        raise ClientError('Receipt is incomplete or failed an integrity check.') from exc


def save_new_directory(directory, artifacts):
    directory = Path(directory)
    try:
        directory.mkdir(mode=0o700)
    except FileExistsError as exc:
        raise ClientError('Output directory already exists; choose a new directory. Existing work is preserved.') from exc
    except OSError as exc:
        raise ClientError('Could not create the output directory; its parent must already exist.') from exc
    created = []
    try:
        for name, data in artifacts.items():
            if name not in ('receipt.json', 'input-verapdf.xml', 'before-verapdf.xml', 'after-verapdf.xml'):
                raise ClientError('Refusing an unexpected artifact name.')
            path = directory / name
            descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), 0o600)
            created.append(path)
            with os.fdopen(descriptor, 'wb') as handle:
                handle.write(data)
                handle.flush()
                os.fsync(handle.fileno())
    except BaseException:
        for path in created:
            path.unlink(missing_ok=True)
        directory.rmdir()
        raise


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='operation', required=True)
    for operation in ('validate', 'compare'):
        command = commands.add_parser(operation)
        if operation == 'validate':
            command.add_argument('input', type=Path)
        else:
            command.add_argument('before', type=Path)
            command.add_argument('after', type=Path)
        command.add_argument('--profile', required=True, choices=PROFILES)
        command.add_argument('--output-dir', required=True, type=Path)
        command.add_argument('--key-file', type=Path, help='Optional private key file; otherwise use RAPIDAPI_KEY.')
        command.add_argument('--local-port', type=int, help='Diagnostic use only: HTTP port on fixed 127.0.0.1, never another host.')
    args = parser.parse_args(argv)
    try:
        if args.output_dir.exists() or args.output_dir.is_symlink():
            raise ClientError('Output directory already exists; choose a new directory. Existing work is preserved.')
        names = ('input',) if args.operation == 'validate' else ('before', 'after')
        sources = {name: read_pdf(getattr(args, name)) for name in names}
        key = read_key_file(args.key_file) if args.key_file is not None else os.environ.get('RAPIDAPI_KEY', '')
        validate_key(key)
        if key and any(key.encode('utf-8') in source for source in sources.values()):
            raise ClientError('A selected input contains the literal configured credential bytes; choose inputs without credentials.')
        payload = request_payload(args.operation, args.profile, sources)
        receipt = post(args.operation, payload, key, args.local_port)
        artifacts = verified_artifacts(receipt, args.operation, args.profile, sources)
        save_new_directory(args.output_dir, artifacts)
        print(json.dumps({'output_directory': str(args.output_dir), 'profile': args.profile,
                          'results': {name: document['status'] for name, document in receipt['documents'].items()},
                          'message': 'Receipt and raw reports saved. Review scope and rule findings before delivery.'}))
        return 0
    except (ClientError, KeyFileError, OSError) as exc:
        result = {'message': str(exc), 'automatic_retry': False}
        if isinstance(exc, GatewayError):
            result.update({'http_status': exc.status, 'error_code': exc.code, 'retry_after': exc.retry_after})
        print(json.dumps(result), file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
