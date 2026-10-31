"""Extract a local MDB/ACCDB snapshot through Access File Extractor on RapidAPI.

Python 3 standard library only. Set RAPIDAPI_KEY to your subscribed consumer key.
One call; no retries. Validation failures can consume your request allowance.
"""
import argparse
import csv
import hashlib
import http.client
import io
import json
import os
from pathlib import Path
import re
import ssl
import zipfile

HOST = 'access-file-extractor.p.rapidapi.com'
MAX_INPUT = 40 * 1048576
MAX_OUTPUT = 32 * 1048576


class ClientError(ValueError):
    pass


def save_new(path, data):
    """Create a private new file; never overwrite a file or symlink."""
    descriptor = None
    created = None
    try:
        descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL |
                             getattr(os, 'O_NOFOLLOW', 0), 0o600)
        created = os.fstat(descriptor)
        view = memoryview(data)
        while view:
            written = os.write(descriptor, view)
            if written <= 0:
                raise OSError()
            view = view[written:]
        os.fsync(descriptor)
    except OSError:
        if created is not None:
            try:
                current = Path(path).stat(follow_symlinks=False)
                if (current.st_dev, current.st_ino) == (created.st_dev, created.st_ino):
                    Path(path).unlink()
            except OSError:
                pass
        raise ClientError('Could not create the new output file. Existing files were preserved.') from None
    finally:
        if descriptor is not None:
            os.close(descriptor)


def verify_output(output, output_format):
    """Check the response container and manifest, without extracting ZIP members."""
    try:
        csv_stats = {}
        if output_format == 'json':
            value = json.loads(output)
            manifest = value['manifest']
            if not isinstance(value['tables'], list):
                raise ClientError('JSON response has no table list.')
        else:
            with zipfile.ZipFile(io.BytesIO(output)) as archive:
                infos = archive.infolist()
                names = [entry.filename for entry in infos]
                if not 1 <= len(infos) <= 101 or len(set(names)) != len(names):
                    raise ClientError('ZIP member list is invalid.')
                expanded = 0
                for entry in infos:
                    if entry.flag_bits & 1 or not re.fullmatch(r'(manifest\.json|tables/[0-9]+\.csv)', entry.filename):
                        raise ClientError('ZIP contains an unexpected member.')
                    expanded += entry.file_size
                    if entry.file_size > MAX_OUTPUT or expanded > MAX_OUTPUT:
                        raise ClientError('Expanded ZIP exceeds the client safety limit.')
                    with archive.open(entry) as member:
                        data = member.read(MAX_OUTPUT + 1)
                    if len(data) != entry.file_size:
                        raise ClientError('ZIP member size mismatch.')
                    if entry.filename.endswith('.csv'):
                        rows = csv.reader(io.StringIO(data.decode('utf-8'), newline=''))
                        header = next(rows)
                        count = 0
                        for row in rows:
                            if len(row) != len(header):
                                raise ClientError('CSV row width does not match its header.')
                            count += 1
                        csv_stats[entry.filename] = (header, count)
                manifest = json.loads(archive.read('manifest.json'))
                if set(names) != {'manifest.json', *(table['csv_member'] for table in manifest['tables'])}:
                    raise ClientError('ZIP members do not match the manifest.')
        if not isinstance(manifest, dict) or manifest.get('schema_version') != '1.0' or manifest.get('complete') is not True or manifest.get('format') != output_format:
            raise ClientError('Response manifest is incomplete or unsupported.')
        tables = manifest['tables']
        if not isinstance(tables, list) or len(tables) > 100 or type(manifest['total_rows']) is not int or not 0 <= manifest['total_rows'] <= 250000:
            raise ClientError('Manifest table or row limits are invalid.')
        if sum(table['rows'] for table in tables) != manifest['total_rows']:
            raise ClientError('Manifest row counts do not agree.')
        if output_format == 'json':
            if len(value['tables']) != len(tables):
                raise ClientError('JSON table count does not match its manifest.')
            for actual, table in zip(value['tables'], tables):
                if actual['id'] != table['id'] or actual['name'] != table['name'] or len(actual['rows']) != table['rows']:
                    raise ClientError('JSON table identity or row count does not match its manifest.')
                if any(not isinstance(row, list) or len(row) != len(table['columns']) for row in actual['rows']):
                    raise ClientError('JSON row width does not match its manifest.')
        else:
            for table in tables:
                if csv_stats[table['csv_member']] != ([column['name'] for column in table['columns']], table['rows']):
                    raise ClientError('CSV header or row count does not match its manifest.')
        return manifest
    except ClientError:
        raise
    except (KeyError, TypeError, ValueError, OSError, RuntimeError, EOFError, StopIteration, csv.Error, zipfile.BadZipFile, zipfile.LargeZipFile, NotImplementedError):
        raise ClientError('Response failed ZIP/JSON integrity or manifest checks.') from None


def extract(source, output_format, key, connection_factory=None):
    if not isinstance(key, str) or not 16 <= len(key) <= 512 or any(ord(c) < 33 or ord(c) > 126 for c in key):
        raise ClientError('Set RAPIDAPI_KEY to your own subscribed consumer key.')
    if not 0 < len(source) <= MAX_INPUT or output_format not in ('csv', 'json'):
        raise ClientError('Use a database up to 40 MiB and csv or json format.')
    factory = connection_factory or http.client.HTTPSConnection
    connection = factory(HOST, timeout=150, context=ssl.create_default_context())
    try:
        content_type = 'application/zip' if output_format == 'csv' else 'application/json'
        connection.request('POST', '/v1/extract?format=' + output_format, source, {
            'X-RapidAPI-Key': key, 'X-RapidAPI-Host': HOST,
            'Content-Type': 'application/octet-stream', 'Accept': content_type, 'Accept-Encoding': 'identity'})
        response = connection.getresponse()
        if 300 <= response.status < 400:
            raise ClientError('Unexpected redirect refused. No credentials were forwarded.')
        if response.status != 200:
            hints = {401: 'Check your key and subscription.', 403: 'Check your key, host and subscription.',
                     413: 'Input or extracted output exceeds a limit.', 422: 'Check the supported database types and file health.',
                     429: 'Check quota and Retry-After before a deliberate retry.',
                     503: 'The worker is busy or unavailable; check Retry-After.'}
            raise ClientError('Request failed: HTTP %s. %s' % (response.status, hints.get(response.status, 'Check the API documentation.')))
        if (response.getheader('Content-Encoding') or 'identity').lower() != 'identity':
            raise ClientError('Unsupported compressed HTTP response.')
        if (response.getheader('Content-Type') or '').split(';')[0].strip().lower() != content_type:
            raise ClientError('Unexpected response Content-Type.')
        output = response.read(MAX_OUTPUT + 1)
        if not 0 < len(output) <= MAX_OUTPUT:
            raise ClientError('Response exceeds the 32 MiB client limit or is empty.')
        return output, verify_output(output, output_format)
    except ClientError:
        raise
    except (OSError, http.client.HTTPException):
        raise ClientError('HTTPS request did not complete. Quota may have been consumed; no automatic retry was made.') from None
    finally:
        connection.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('database', type=Path)
    parser.add_argument('--format', choices=('csv', 'json'), default='csv')
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    try:
        if args.output.exists() or args.output.is_symlink():
            raise ClientError('Choose a new output path; existing files are preserved.')
        if not args.database.is_file() or not 0 < args.database.stat().st_size <= MAX_INPUT:
            raise ClientError('Input must be a local database of at most 40 MiB.')
        with args.database.open('rb') as source:
            database = source.read(MAX_INPUT + 1)
        output, manifest = extract(database, args.format, os.environ.get('RAPIDAPI_KEY', ''))
        save_new(args.output, output)
        print(json.dumps({'output_bytes': len(output), 'local_output_sha256': hashlib.sha256(output).hexdigest(),
                          'table_count': len(manifest['tables']), 'total_rows': manifest['total_rows']}))
        print('Container checks passed. Compare exported values with your source before use.')
    except (ClientError, OSError) as exc:
        parser.exit(1, (str(exc) if isinstance(exc, ClientError) else 'Unable to read the local database.') + '\n')


if __name__ == '__main__':
    main()
