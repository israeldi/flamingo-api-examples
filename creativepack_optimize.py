"""Optimize one local HTML5 creative ZIP through CreativePack on RapidAPI.

Python 3 standard library only. Set RAPIDAPI_KEY to your subscribed consumer key.
One processing call; no automatic retries. Existing output files are preserved.
"""
import argparse
import base64
import binascii
import hashlib
import http.client
import io
import json
import os
import re
import ssl
import zipfile
from pathlib import Path

MAX_ZIP = 8 * 1024 * 1024
MAX_EXPANDED = 32 * 1024 * 1024
MAX_RESPONSE = 16 * 1024 * 1024


class ClientError(ValueError):
    """A sanitized error that never includes credentials or response content."""


def validate_host(host):
    if not isinstance(host, str) or len(host) > 253 or host != host.strip():
        raise ClientError('Set RAPIDAPI_HOST to the exact assigned consumer hostname.')
    host = host.lower()
    if not host.endswith('.p.rapidapi.com') or not all(
            re.fullmatch(r'[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?', label)
            for label in host.split('.')):
        raise ClientError('RAPIDAPI_HOST must be the assigned *.p.rapidapi.com hostname, without scheme, path or port.')
    if host.startswith(('your-', 'example-', 'placeholder-', 'creativepack-unpublished.')):
        raise ClientError('The API hostname is still an unpublished placeholder.')
    return host


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ClientError('The response contains ambiguous JSON fields.')
        result[key] = value
    return result


def _members(data):
    """Read bounded ZIP members for verification; never extract or run files."""
    try:
        with zipfile.ZipFile(io.BytesIO(data)) as archive:
            infos = archive.infolist()
            if not infos or len(infos) > 256 or len({entry.filename for entry in infos}) != len(infos):
                raise ClientError('The response ZIP has an invalid member list.')
            total = 0
            members = {}
            for entry in infos:
                if entry.flag_bits & 1 or entry.file_size > MAX_ZIP or entry.compress_type not in (zipfile.ZIP_STORED, zipfile.ZIP_DEFLATED):
                    raise ClientError('The response ZIP uses unsupported features or limits.')
                with archive.open(entry) as member:
                    value = member.read(MAX_ZIP + 1)
                total += len(value)
                if len(value) != entry.file_size or len(value) > MAX_ZIP or total > MAX_EXPANDED:
                    raise ClientError('The response ZIP exceeds expanded content limits.')
                members[entry.filename] = value
            return members
    except ClientError:
        raise
    except (zipfile.BadZipFile, zipfile.LargeZipFile, RuntimeError, EOFError, OSError, ValueError, NotImplementedError):
        raise ClientError('The response ZIP failed integrity verification.') from None


def verify_result(result, original, budget_bytes, mode):
    try:
        if not isinstance(result, dict) or set(result) != {'archive_base64', 'report'}:
            raise ClientError('The marketplace returned an unsupported response contract.')
        encoded, report = result['archive_base64'], result['report']
        if not isinstance(encoded, str) or not isinstance(report, dict):
            raise ClientError('The marketplace returned an unsupported response contract.')
        output = base64.b64decode(encoded, validate=True)
        if base64.b64encode(output).decode() != encoded or not output or len(output) > MAX_ZIP or len(output) > len(original):
            raise ClientError('The returned archive failed size or encoding verification.')
        expected_status = 'within_budget' if len(output) <= budget_bytes else 'over_budget'
        expected = {'schema_version': '1.0', 'status': expected_status, 'requested_mode': mode,
                    'budget_bytes': budget_bytes, 'input_bytes': len(original), 'output_bytes': len(output),
                    'saved_bytes': len(original) - len(output),
                    'input_sha256': hashlib.sha256(original).hexdigest(),
                    'output_sha256': hashlib.sha256(output).hexdigest()}
        if any(type(report.get(key)) is not type(value) or report[key] != value for key, value in expected.items()):
            raise ClientError('The returned archive does not match its hash, size or request report.')
        before, after = _members(original), _members(output)
        if list(before) != list(after):
            raise ClientError('The returned ZIP member paths changed.')
        file_reports = report.get('files')
        if not isinstance(file_reports, list) or len(file_reports) != len(after):
            raise ClientError('The returned file manifest is incomplete.')
        for name, entry in zip(after, file_reports):
            if not isinstance(entry, dict) or entry.get('path') != name:
                raise ClientError('The returned file manifest does not match ZIP order.')
            expected_file = {'input_bytes': len(before[name]), 'output_bytes': len(after[name]),
                             'input_sha256': hashlib.sha256(before[name]).hexdigest(),
                             'output_sha256': hashlib.sha256(after[name]).hexdigest()}
            if any(entry.get(key) != value for key, value in expected_file.items()):
                raise ClientError('A returned ZIP member does not match its manifest hash or size.')
            if entry.get('kind') in ('non_image', 'directory') and before[name] != after[name]:
                raise ClientError('A nonimage ZIP member changed.')
        return output, report
    except ClientError:
        raise
    except (ValueError, TypeError, KeyError, binascii.Error, UnicodeError):
        raise ClientError('The marketplace returned malformed archive data.') from None


def optimize(archive, budget_bytes, mode, *, key, host, connection_factory=None):
    host = validate_host(host)
    if host != 'creativepack.p.rapidapi.com':
        raise ClientError('This example only calls creativepack.p.rapidapi.com.')
    if not isinstance(key, str) or not 16 <= len(key) <= 512 or any(ord(char) < 33 or ord(char) > 126 for char in key):
        raise ClientError('Set RAPIDAPI_KEY privately to your own consumer application key.')
    if not isinstance(archive, bytes) or not 0 < len(archive) <= MAX_ZIP:
        raise ClientError('Input ZIP must contain at most 8 MiB.')
    if type(budget_bytes) is not int or not 1024 <= budget_bytes <= MAX_ZIP or mode not in ('lossless', 'balanced'):
        raise ClientError('Use a budget of 1024..8388608 bytes and lossless or balanced mode.')
    payload = json.dumps({'archive_base64': base64.b64encode(archive).decode(),
                          'budget_bytes': budget_bytes, 'mode': mode}).encode()
    context = ssl.create_default_context()
    factory = connection_factory or http.client.HTTPSConnection
    connection = factory(host, timeout=50, context=context)
    try:
        connection.request('POST', '/v1/optimize', payload, {
            'Content-Type': 'application/json; charset=utf-8', 'Accept': 'application/json',
            'Accept-Encoding': 'identity', 'X-RapidAPI-Key': key, 'X-RapidAPI-Host': host})
        response = connection.getresponse()
        if 300 <= response.status < 400:
            raise ClientError('The marketplace returned a redirect. Credentials were not forwarded.')
        if (response.getheader('Content-Encoding') or 'identity').lower() != 'identity':
            raise ClientError('The marketplace returned unsupported compressed content.')
        body = response.read(MAX_RESPONSE + 1)
        if len(body) > MAX_RESPONSE:
            raise ClientError('The response exceeds the client limit.')
        try:
            result = json.loads(body.decode('utf-8'), object_pairs_hook=_unique_object)
        except (ValueError, UnicodeError):
            raise ClientError('The marketplace returned an invalid JSON response.') from None
        if response.status != 200:
            # Do not echo server messages: a misconfigured upstream may reflect
            # request data, consumer credentials, filenames or private content.
            code = result.get('error', {}).get('code') if isinstance(result, dict) and isinstance(result.get('error'), dict) else None
            label = ': ' + code if isinstance(code, str) and re.fullmatch(r'[a-z][a-z0-9_]{0,63}', code) else ''
            raise ClientError(f'Marketplace request failed (HTTP {response.status}{label}).')
        return verify_result(result, archive, budget_bytes, mode)
    except ClientError:
        raise
    except (OSError, http.client.HTTPException):
        raise ClientError('The request could not complete over verified HTTPS. No output files were written.') from None
    finally:
        connection.close()


def save_new_outputs(output_path, report_path, output, report):
    output_path, report_path = Path(output_path), Path(report_path)
    if output_path.resolve() == report_path.resolve():
        raise ClientError('Choose two different new output paths.')
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0)
    opened = []
    try:
        for path in (output_path, report_path):
            descriptor = os.open(path, flags, 0o600)
            opened.append((path, descriptor, os.fstat(descriptor)))
        values = (output, (json.dumps(report, indent=2, ensure_ascii=True, allow_nan=False) + '\n').encode())
        for (_, descriptor, _), value in zip(opened, values):
            offset = 0
            while offset < len(value):
                written = os.write(descriptor, value[offset:])
                if written <= 0:
                    raise OSError()
                offset += written
            os.fsync(descriptor)
    except (OSError, ValueError):
        # Remove only the files exclusively created by this call, and only
        # while the path still identifies that exact inode. Never overwrite or
        # unlink a preexisting file or a substituted symlink.
        for path, _, original_stat in opened:
            try:
                current = path.stat(follow_symlinks=False)
                if (current.st_dev, current.st_ino) == (original_stat.st_dev, original_stat.st_ino):
                    path.unlink()
            except OSError:
                pass
        raise ClientError('Could not create both new output files. Existing files were preserved.') from None
    finally:
        for _, descriptor, _ in opened:
            os.close(descriptor)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive', type=Path)
    parser.add_argument('--budget-bytes', type=int, required=True)
    parser.add_argument('--mode', choices=('lossless', 'balanced'), default='lossless')
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--report', type=Path, required=True)
    args = parser.parse_args()
    try:
        for path in (args.output, args.report):
            if path.exists() or path.is_symlink():
                raise ClientError('Output paths must be new; existing files were preserved.')
        if args.output.resolve() == args.report.resolve():
            raise ClientError('Choose two different new output paths.')
        if not args.archive.is_file() or not 0 < args.archive.stat().st_size <= MAX_ZIP:
            raise ClientError('The input ZIP limit is 8 MiB.')
        with args.archive.open('rb') as source:
            original = source.read(MAX_ZIP + 1)
        output, report = optimize(original, args.budget_bytes, args.mode,
                                  key=os.environ.get('RAPIDAPI_KEY', ''),
                                  host='creativepack.p.rapidapi.com')
        save_new_outputs(args.output, args.report, output, report)
        print(json.dumps({'status': report['status'], 'input_bytes': len(original),
                          'output_bytes': len(output), 'output_sha256': report['output_sha256']}))
    except ClientError as exc:
        parser.exit(1, str(exc) + '\n')
    except OSError:
        parser.exit(1, 'Unable to read the input or create the new output files.\n')


if __name__ == '__main__':
    main()
