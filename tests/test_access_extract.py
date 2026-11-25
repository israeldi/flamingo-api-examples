"""Offline regressions for Access CSV response validation; no API calls."""
import csv
import io
import json
import unittest
from unittest.mock import Mock
import zipfile

import access_extract as client


def csv_archive(field_characters, *, declared_rows=1):
    """Write bounded chunks so the size-limit case needs no large fixture."""
    manifest = {
        'schema_version': '1.0', 'complete': True, 'format': 'csv',
        'total_rows': declared_rows,
        'tables': [{
            'id': 'table-0', 'name': 'Notes', 'rows': declared_rows,
            'columns': [{'name': 'Memo'}], 'csv_member': 'tables/0.csv',
        }],
    }
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, 'w', zipfile.ZIP_DEFLATED) as archive:
        archive.writestr('manifest.json', json.dumps(manifest))
        with archive.open('tables/0.csv', 'w') as table:
            table.write(b'Memo\r\n')
            chunk = b'a' * 65536
            remaining = field_characters
            while remaining:
                amount = min(remaining, len(chunk))
                table.write(chunk[:amount])
                remaining -= amount
            table.write(b'\r\n')
    return buffer.getvalue(), manifest


class AccessCsvResponseTests(unittest.TestCase):
    def setUp(self):
        original = csv.field_size_limit()
        self.addCleanup(csv.field_size_limit, original)
        csv.field_size_limit(131072)

    def test_accepts_long_fields_within_output_budget_and_restores_parser_limit(self):
        for length in (131072, 131073, 200000):
            with self.subTest(field_characters=length):
                payload, expected = csv_archive(length)
                self.assertEqual(client.verify_output(payload, 'csv'), expected)
                self.assertEqual(csv.field_size_limit(), 131072)

    def test_restores_parser_limit_when_manifest_validation_fails(self):
        payload, _ = csv_archive(200000, declared_rows=2)
        csv.field_size_limit(4096)
        with self.assertRaisesRegex(client.ClientError, 'row count does not match'):
            client.verify_output(payload, 'csv')
        self.assertEqual(csv.field_size_limit(), 4096)

    def test_rejects_csv_member_beyond_32_mib_expanded_limit(self):
        self.assertEqual(client.MAX_OUTPUT, 32 * 1048576)
        payload, _ = csv_archive(32 * 1048576)
        with self.assertRaisesRegex(client.ClientError, 'Expanded ZIP exceeds'):
            client.verify_output(payload, 'csv')
        self.assertEqual(csv.field_size_limit(), 131072)

    def test_rejects_http_response_beyond_32_mib_before_parsing(self):
        response = Mock(status=200)
        response.getheader.side_effect = lambda name: {
            'Content-Encoding': 'identity', 'Content-Type': 'application/zip',
        }.get(name)
        response.read.side_effect = lambda maximum: b'x' * min(maximum, 32 * 1048576 + 1)
        connection = Mock()
        connection.getresponse.return_value = response
        with self.assertRaisesRegex(client.ClientError, '32 MiB client limit'):
            client.extract(b'fictional database', 'csv', 'fictional-test-key',
                           connection_factory=lambda *args, **kwargs: connection)
        connection.close.assert_called_once()


if __name__ == '__main__':
    unittest.main()
