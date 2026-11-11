"""Offline Apache source contract: retire stale file, preserve its URL through dynamic feed.
This does not execute Apache; hosting rewrite acceptance remains a staging check.
"""
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
def validate(root):
    rules=(root/'.htaccess').read_text()
    rule=re.search(r'^\s*RewriteRule\s+\^google-merchant-feed\\\.txt\$\s+google-merchant-feed\.php\s+\[L\]\s*$',rules,re.M)
    assert rule, 'Legacy URL must internally resolve to the dynamic feed'
    assert rule.start()<rules.index('RewriteCond %{REQUEST_FILENAME} -f'), 'Alias must precede existing-file bypass during rollout'
    assert not (root/'google-merchant-feed.txt').exists(), 'Do not retain a stale public static price snapshot'
    dynamic=(root/'google-merchant-feed.php').read_text()
    assert 'getAllVisibleForFeed()' in dynamic and 'buildItems($products)' in dynamic, 'Alias target must use current catalog and shared feed builder'
    return 4

checks=validate(ROOT)
# Negative controls prove the contract catches a missing/late alias and stale content.
import tempfile,shutil
with tempfile.TemporaryDirectory(prefix='fas-feed-route-',dir=ROOT.parent) as d:
    fixture=Path(d)
    shutil.copy2(ROOT/'google-merchant-feed.php',fixture/'google-merchant-feed.php')
    original=(ROOT/'.htaccess').read_text()
    alias='    RewriteRule ^google-merchant-feed\\.txt$ google-merchant-feed.php [L]'
    for rules,stale in [(original.replace(alias,''),False),
                        (original.replace(alias,'')+'\n'+alias,False),(original,True)]:
        (fixture/'.htaccess').write_text(rules)
        if stale:(fixture/'google-merchant-feed.txt').write_text('<g:price>1.00 USD</g:price>')
        try:validate(fixture)
        except AssertionError:checks+=1
        else:raise AssertionError('Negative control escaped route validation')
print(f'PASS {checks} legacy-feed route contract assertions, including 3 negative controls; no Apache or network.')
