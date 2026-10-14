"""Guard the shared footer's quiet, accessible website credit."""
from html.parser import HTMLParser
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'includes/footer.php').read_text()


class FooterParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.stack = []
        self.links = []

    def handle_starttag(self, tag, attrs):
        node = {'tag': tag, 'attrs': dict(attrs), 'text': '', 'parents': list(self.stack)}
        if tag == 'a':
            self.links.append(node)
        if tag not in {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'}:
            self.stack.append(node)

    def handle_endtag(self, tag):
        for index in range(len(self.stack) - 1, -1, -1):
            if self.stack[index]['tag'] == tag:
                del self.stack[index:]
                break

    def handle_data(self, data):
        for node in self.stack:
            node['text'] += data


class FooterCreditTest(unittest.TestCase):
    def setUp(self):
        parser = FooterParser()
        parser.feed(re.sub(r'<\?php.*?\?>', '', SOURCE, flags=re.S))
        self.links = parser.links
        self.credits = [link for link in self.links if link['attrs'].get('href') == 'https://stage4000.com']

    def credit(self):
        self.assertEqual(len(self.credits), 1, 'One Stage4000 credit link should be in the shared footer')
        return self.credits[0]

    def test_credit_has_clear_visible_link_text(self):
        self.assertEqual(self.credit()['text'], 'Website by Stage4000')

    def test_credit_is_small_and_inside_footer(self):
        parents = self.credit()['parents']
        self.assertTrue(any(node['tag'] == 'footer' for node in parents))
        self.assertEqual(parents[-1]['tag'], 'p')
        self.assertTrue({'small', 'mt-2', 'mb-0'}.issubset(set(parents[-1]['attrs'].get('class', '').split())))

    def test_credit_uses_existing_muted_footer_color(self):
        self.assertIn('text-white-50', self.credit()['attrs'].get('class', '').split())

    def test_credit_keeps_native_keyboard_and_same_tab_navigation(self):
        attributes = self.credit()['attrs']
        for attr in ('target', 'tabindex', 'hidden', 'aria-hidden', 'onclick'):
            self.assertNotIn(attr, attributes)

    def test_existing_copyright_remains_before_credit(self):
        self.credit()
        copyright = '&copy; <?php echo date(\'Y\'); ?> Flip and Strip. All rights reserved.'
        self.assertIn(copyright, SOURCE)
        self.assertLess(SOURCE.index(copyright), SOURCE.index('https://stage4000.com'))

    def test_existing_shopping_and_contact_links_remain(self):
        destinations = {link['attrs'].get('href') for link in self.links}
        self.assertTrue({'/products', '/products/motorcycle', '/products/atv', '/products/boat', '/about', '/contact', '/cart'}.issubset(destinations))
        self.assertIn('mailto:FLIPANDSTRIPCYCLES@GMAIL.COM', destinations)
        self.assertIn('sms:+14073085294', destinations)


if __name__ == '__main__':
    unittest.main()
