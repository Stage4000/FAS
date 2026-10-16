"""Cache only the public, versioned presentation assets used by the real header/footer.

Run before the isolated browser job. No storefront or business API is contacted.
The Google Fonts response is frozen for the run and shared by both comparisons.
"""
from pathlib import Path
import argparse, hashlib, json, re, urllib.parse, urllib.request

URLS = [
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
    'https://unpkg.com/aos@2.3.1/dist/aos.css',
    'https://unpkg.com/aos@2.3.1/dist/aos.js',
    'https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap',
]
HOSTS = {'cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'unpkg.com',
         'fonts.googleapis.com', 'fonts.gstatic.com'}
UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36'


class RestrictedRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, newurl):
        validate(newurl)
        return super().redirect_request(request, fp, code, msg, headers, newurl)


def validate(url):
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != 'https' or parsed.hostname not in HOSTS or parsed.username or parsed.port:
        raise ValueError('Unexpected presentation asset URL: ' + url)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', type=Path, required=True)
    output = parser.parse_args().output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    client = urllib.request.build_opener(RestrictedRedirect())
    manifest = {}
    queue = URLS.copy()
    for url in queue:
        if url in manifest:
            continue
        validate(url)
        request = urllib.request.Request(url, headers={'User-Agent': UA})
        with client.open(request, timeout=45) as response:
            validate(response.url)
            content = response.read(10_000_001)
            assert len(content) <= 10_000_000, 'Oversized presentation asset'
            content_type = response.headers.get_content_type()
        filename = hashlib.sha256(url.encode()).hexdigest()
        (output / filename).write_bytes(content)
        manifest[url] = {'file': filename, 'content_type': content_type,
                         'sha256': hashlib.sha256(content).hexdigest()}
        if content_type == 'text/css':
            for match in re.findall(r'url\(\s*[\'\"]?([^\s\)\'\"]+)', content.decode()):
                if match.startswith('data:'):
                    continue
                asset = urllib.parse.urldefrag(urllib.parse.urljoin(url, match))[0]
                validate(asset)
                if asset not in queue:
                    queue.append(asset)
    (output / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print(f'Cached {len(manifest)} public presentation resources; browser will use local bytes only.')


if __name__ == '__main__':
    main()
