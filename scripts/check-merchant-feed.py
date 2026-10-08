#!/usr/bin/env python3
"""Check a local Merchant RSS feed without changing it or contacting Google."""
import argparse
from collections import Counter
import json
from pathlib import Path
import sys
import xml.etree.ElementTree as ET

GOOGLE = '{http://base.google.com/ns/1.0}'


def inspect_feed(content):
    """Return actionable source issues, not Merchant Center approval status."""
    try:
        root = ET.fromstring(content)
    except ET.ParseError as error:
        raise ValueError('Feed is not valid XML: ' + str(error)) from error
    channel = root.find('channel')
    if root.tag != 'rss' or channel is None:
        raise ValueError('Expected an RSS Merchant feed with a channel element.')

    rows = []
    for index, element in enumerate(channel.findall('item'), start=1):
        rows.append({
            'item_number': index,
            'id': (element.findtext(GOOGLE + 'id') or '').strip(),
            'title': (element.findtext('title') or '').strip(),
            'link': (element.findtext('link') or '').strip(),
            'mpn': (element.findtext(GOOGLE + 'mpn') or '').strip(),
            'description': (element.findtext('description') or '').strip(),
        })

    counts = Counter(row['id'] for row in rows if row['id'])
    issues = []
    for row in rows:
        reference = {key: row[key] for key in ('item_number', 'id', 'title', 'link')}

        def add(code, message, severity='error', **details):
            issues.append(dict(reference, code=code, severity=severity, message=message, **details))

        if not row['id']:
            add('missing_id', 'Each product needs a stable, unique feed ID.')
        elif len(row['id']) > 50:
            add('id_too_long', 'Feed IDs must not exceed 50 characters.', characters=len(row['id']))
        if row['id'] and counts[row['id']] > 1:
            add('duplicate_id', 'Different items share an ID. Review the existing Merchant item before changing IDs.', occurrences=counts[row['id']])
        if len(row['mpn']) > 70:
            add('mpn_too_long', 'MPN exceeds 70 characters. Verify the complete part number; do not truncate or choose one from a list.', characters=len(row['mpn']))
        if 'ebay shipping calculator' in row['description'].lower():
            add('marketplace_shipping_copy', 'Review marketplace shipping wording before using it on the direct storefront.', severity='warning')

    return {
        'item_count': len(rows),
        'unique_id_count': len(counts),
        'error_count': sum(issue['severity'] == 'error' for issue in issues),
        'warning_count': sum(issue['severity'] == 'warning' for issue in issues),
        'merchant_center_status': 'not_checked',
        'issues': issues,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('feed', type=Path, help='Local RSS XML file. No network requests are made.')
    args = parser.parse_args()
    try:
        report = inspect_feed(args.feed.read_bytes())
    except (OSError, ValueError) as error:
        print(str(error), file=sys.stderr)
        return 2
    print(json.dumps(report, indent=2, ensure_ascii=False))
    return 1 if report['error_count'] else 0


if __name__ == '__main__':
    sys.exit(main())
