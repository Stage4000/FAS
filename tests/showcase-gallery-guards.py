"""Negative controls for the public/private read-model regression suite.
Each mutation runs in a new synthetic local copy. Original source is never edited.
"""
from pathlib import Path
import hashlib
import os
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
php = shutil.which('php')
assert php
flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
paths = ['src/showcase/ShowcasePhoto.php', 'src/showcase/ShowcaseSubmission.php',
         'src/showcase/ShowcaseRepository.php', 'src/showcase/ShowcaseViews.php',
         'tests/showcase-gallery-test.php']
originals = {p: (root / p).read_bytes() for p in paths}
cases = [
    ('skip queue authorization', 'src/showcase/ShowcaseRepository.php',
     [("if (($this->authorize)($actorId, 'list', []) !== true)", 'if (false)')],
     'Expected rejection: Current admin queue authorization'),
    ('truthy publication', 'src/showcase/ShowcaseRepository.php',
     [("'consent_text_sha256' => $row['consent_text_sha256']]) === true;", "'consent_text_sha256' => $row['consent_text_sha256']]) ? true : false;")],
     'Explicit literal public approval required'),
    ('leaked public email', 'src/showcase/ShowcaseRepository.php',
     [("'caption' => $current['caption']];", "'caption' => $current['caption'], 'contact_email' => 'private@example.invalid'];")],
     'Strict minimal public fields'),
    ('cacheable public photo', 'src/showcase/ShowcaseRepository.php',
     [("'Cache-Control' => 'private, no-store'", "'Cache-Control' => 'public, max-age=3600'")],
     'Safe image response header Cache-Control'),
    ('unfiltered review queue', 'src/showcase/ShowcaseRepository.php',
     [('FROM main.showcase_submissions WHERE status=?\' . $where', 'FROM main.showcase_submissions WHERE ? IS NOT NULL\' . $where')],
     'Queue explicitly filters state'),
    ('unescaped display values', 'src/showcase/ShowcaseViews.php',
     [("return htmlspecialchars($value,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8');", 'return $value;')],
     'Public view excludes unsafe/private content <script>'),
    ('unapproved image serving', 'src/showcase/ShowcaseRepository.php',
     [("if ($row['status'] !== 'approved' || !self::publicationAllowed($publicationPolicy, $row)) {\n            throw", "if (!self::publicationAllowed($publicationPolicy, $row)) {\n            throw"),
      ("WHERE s.id=? AND s.status='approved' AND s.version=?", 'WHERE s.id=? AND s.version=?')],
     'Expected rejection: Unapproved image unavailable'),
]

# Each predicate is independently required, on both response surfaces.
for surface in ['gallery', 'photo']:
    for mutation, predicate in [('version', 's.consent_version'), ('catalog-hash', 'c.text_sha256')]:
        edits = [(f'AND {predicate}=?', 'AND ? IS NOT NULL')]
        cases.append((f'missing {mutation} pin ({surface})', 'src/showcase/ShowcaseRepository.php',
                      edits[:1], f'Final {surface} rejects changed consent {mutation}'))

for label, target, edits, expected in cases:
    with tempfile.TemporaryDirectory(prefix='.showcase-guard-', dir=root / 'tests') as temporary:
        work = Path(temporary).resolve()
        assert work.parent == (root / 'tests').resolve() and work.name.startswith('.showcase-guard-')
        for path, contents in originals.items():
            dest = work / path
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_bytes(contents)
        text = (work / target).read_text(encoding='utf-8')
        for before, after in edits:
            assert text.count(before) == 1, (label, before, text.count(before))
            text = text.replace(before, after)
        (work / target).write_text(text, encoding='utf-8', newline='')
        if label.endswith('(photo)'):
            test = work / 'tests/showcase-gallery-test.php'
            test.write_text(test.read_text().replace("['gallery','photo']", "['photo','gallery']"), encoding='utf-8')
        lint = subprocess.run([php, '-l', target], cwd=work, text=True, capture_output=True, creationflags=flags)
        assert lint.returncode == 0, (label, lint.stderr)
        result = subprocess.run([php, 'tests/showcase-gallery-test.php'], cwd=work, text=True, capture_output=True, timeout=30, creationflags=flags)
        assert result.returncode != 0 and expected in result.stdout + result.stderr, (label, result.returncode, result.stdout, result.stderr)
        print('Detected negative control:', label)
assert all((root / p).read_bytes() == data for p, data in originals.items()), 'Original source changed'
positive = subprocess.run([php, 'tests/showcase-gallery-test.php'], cwd=root, text=True, capture_output=True, timeout=30, creationflags=flags)
assert positive.returncode == 0, positive.stderr
print(positive.stdout.strip())
print(f'Showcase gallery guards: {len(cases)} negative controls detected; original source unchanged.')
