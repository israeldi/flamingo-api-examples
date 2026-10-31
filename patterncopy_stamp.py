"""Stamp one local PDF through PatternCopy PDF on RapidAPI (Python 3 stdlib).

Set RAPIDAPI_KEY to your subscribed consumer key. One call; no retries.
Inspect each source revision separately before sending real customer copies.
"""
import argparse
import base64
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import ssl

HOST = 'patterncopy-pdf.p.rapidapi.com'
MAX_INPUT = 20 * 1048576
MAX_OUTPUT = 24 * 1048576


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


def stamp(source, options, key, connection_factory=None):
    if not isinstance(key, str) or not 16 <= len(key) <= 512 or any(ord(c) < 33 or ord(c) > 126 for c in key):
        raise ClientError('Set RAPIDAPI_KEY to your own subscribed consumer key.')
    if not 8 <= len(source) <= MAX_INPUT or not source.startswith(b'%PDF-'):
        raise ClientError('Input must be a PDF of at most 20 MiB.')
    if not 1 <= len(options['text']) <= 160 or any(c in options['text'] for c in '\r\n\x00'):
        raise ClientError('Use 1 to 160 characters on one line for --text.')
    if not 6 <= options['font_size'] <= 14 or not 6 <= options['margin'] <= 72:
        raise ClientError('Use font size 6 to 14 pt and margin 6 to 72 pt.')
    encoded = base64.b64encode(json.dumps(options, ensure_ascii=True).encode('utf-8')).decode('ascii')
    factory = connection_factory or http.client.HTTPSConnection
    connection = factory(HOST, timeout=110, context=ssl.create_default_context())
    try:
        connection.request('POST', '/v1/stamp', source, {
            'X-RapidAPI-Key': key, 'X-RapidAPI-Host': HOST,
            'Content-Type': 'application/pdf', 'Accept': 'application/pdf',
            'Accept-Encoding': 'identity', 'X-PatternCopy-Options': encoded})
        response = connection.getresponse()
        if 300 <= response.status < 400:
            raise ClientError('Unexpected redirect refused. No credentials were forwarded.')
        if response.status != 200:
            hints = {401: 'Check your key and subscription.', 403: 'Check your key, host and subscription.',
                     413: 'Check file size.', 422: 'Check PDF compatibility and stamp options.',
                     429: 'Check gateway and backend quotas before a deliberate retry.',
                     503: 'The worker is busy; check Retry-After before a deliberate retry.'}
            raise ClientError('Request failed: HTTP %s. %s' % (response.status, hints.get(response.status, 'Check the API documentation.')))
        if (response.getheader('Content-Encoding') or 'identity').lower() != 'identity':
            raise ClientError('Unsupported compressed response.')
        if (response.getheader('Content-Type') or '').split(';')[0].strip().lower() != 'application/pdf':
            raise ClientError('Response is not application/pdf.')
        output = response.read(MAX_OUTPUT + 1)
        expected = response.getheader('X-PatternCopy-SHA256') or ''
        if not 8 <= len(output) <= MAX_OUTPUT or not output.startswith(b'%PDF-'):
            raise ClientError('Returned PDF failed format or size checks.')
        if not re.fullmatch('[a-f0-9]{64}', expected) or hashlib.sha256(output).hexdigest() != expected:
            raise ClientError('Returned PDF is missing its required digest or failed SHA-256 verification.')
        return output, expected
    except ClientError:
        raise
    except (OSError, http.client.HTTPException):
        raise ClientError('HTTPS request did not complete. Processing may have consumed quota; no automatic retry was made.') from None
    finally:
        connection.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('pdf', type=Path)
    parser.add_argument('--text', required=True)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--font-size', default=6, type=int)
    parser.add_argument('--margin', default=12, type=int)
    parser.add_argument('--position', choices=('bottom-left', 'bottom-right', 'top-left', 'top-right'), default='bottom-left')
    args = parser.parse_args()
    try:
        if args.output.exists() or args.output.is_symlink():
            raise ClientError('Choose a new output path; existing files are preserved.')
        if not args.pdf.is_file() or not 8 <= args.pdf.stat().st_size <= MAX_INPUT:
            raise ClientError('Input must be a local PDF of at most 20 MiB.')
        with args.pdf.open('rb') as source:
            pdf = source.read(MAX_INPUT + 1)
        options = {'text': args.text, 'font_size': args.font_size, 'margin': args.margin, 'position': args.position}
        output, digest = stamp(pdf, options, os.environ.get('RAPIDAPI_KEY', ''))
        save_new(args.output, output)
        print(json.dumps({'output_bytes': len(output), 'output_sha256': digest}))
        print('Review stamp placement, size layers and print scale before delivery.')
    except (ClientError, OSError) as exc:
        parser.exit(1, (str(exc) if isinstance(exc, ClientError) else 'Unable to read the local PDF.') + '\n')


if __name__ == '__main__':
    main()
