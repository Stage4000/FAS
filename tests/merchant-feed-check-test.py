"""Regression tests for the read-only Merchant feed checker."""
import importlib.util
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest

SCRIPT = pathlib.Path(__file__).resolve().parents[1] / 'scripts' / 'check-merchant-feed.py'
spec = importlib.util.spec_from_file_location('merchant_feed_check', SCRIPT)
checker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checker)


def feed(*items):
    return ('<rss xmlns:g="http://base.google.com/ns/1.0"><channel>'
            + ''.join(items) + '</channel></rss>').encode()


def item(identifier='SKU-1', mpn='PART-1', description='Part description'):
    return ('<item><g:id>' + identifier + '</g:id><title>Fixture part</title>'
            '<link>https://example.invalid/product/1</link><description>'
            + description + '</description><g:mpn>' + mpn + '</g:mpn></item>')


class MerchantFeedCheckTests(unittest.TestCase):
    def test_duplicate_ids_identify_every_affected_item(self):
        report = checker.inspect_feed(feed(item('SHARED'), item('SHARED')))
        duplicates = [x for x in report['issues'] if x['code'] == 'duplicate_id']
        self.assertEqual([x['item_number'] for x in duplicates], [1, 2])
        self.assertTrue(all(x['id'] == 'SHARED' for x in duplicates))

    def test_mpn_length_uses_characters_and_never_rewrites_input(self):
        source = feed(item('FIRST', 'é' * 70), item('SECOND', 'é' * 71))
        before = bytes(source)
        report = checker.inspect_feed(source)
        issues = [x for x in report['issues'] if x['code'] == 'mpn_too_long']
        self.assertEqual([(x['id'], x['characters']) for x in issues], [('SECOND', 71)])
        self.assertEqual(source, before)

    def test_clean_feed_has_no_issues(self):
        report = checker.inspect_feed(feed(item('ONE'), item('TWO', '')))
        self.assertEqual(report['item_count'], 2)
        self.assertEqual(report['issues'], [])

    def test_identifier_limits_and_missing_id(self):
        report = checker.inspect_feed(feed(item(''), item('X' * 51)))
        self.assertEqual([x['code'] for x in report['issues']], ['missing_id', 'id_too_long'])

    def test_marketplace_boilerplate_is_review_warning(self):
        report = checker.inspect_feed(feed(item(description='Use the eBay shipping calculator.')))
        self.assertEqual(len(report['issues']), 1)
        self.assertEqual(report['issues'][0]['code'], 'marketplace_shipping_copy')
        self.assertEqual(report['issues'][0]['severity'], 'warning')

    def test_no_google_approval_claim(self):
        report = checker.inspect_feed(feed(item()))
        self.assertEqual(report['merchant_center_status'], 'not_checked')

    def test_wrong_document_and_malformed_xml_are_rejected(self):
        for value in [b'<html>Login</html>', b'<rss><broken>']:
            with self.assertRaises(ValueError):
                checker.inspect_feed(value)

    def test_cli_is_read_only_and_reports_error_exit(self):
        with tempfile.TemporaryDirectory() as temp:
            path = pathlib.Path(temp) / 'feed.xml'
            source = feed(item('SAME'), item('SAME'))
            path.write_bytes(source)
            result = subprocess.run([sys.executable, str(SCRIPT), str(path)], capture_output=True, text=True)
            self.assertEqual(result.returncode, 1)
            self.assertEqual(json.loads(result.stdout)['item_count'], 2)
            self.assertEqual(path.read_bytes(), source)


if __name__ == '__main__':
    unittest.main()
