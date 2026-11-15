"""Offline input-purpose regression; parse actual contact markup, never submit it."""
from html.parser import HTMLParser
from pathlib import Path
import sys
class Fields(HTMLParser):
    def __init__(self):
        super().__init__(); self.inputs=[]; self.labels=[]
    def handle_starttag(self,tag,attrs):
        attrs=dict(attrs)
        if tag=='input': self.inputs.append(attrs)
        if tag=='label': self.labels.append(attrs)
p=Fields(); p.feed(Path(sys.argv[1] if len(sys.argv)>1 else Path(__file__).resolve().parents[1]/'contact.php').read_text())
checks=0
for name,kind in [('name','text'),('email','email')]:
    fields=[f for f in p.inputs if f.get('id')==name]
    assert len(fields)==1; checks+=1
    f=fields[0]
    for key,value in [('name',name),('type',kind),('autocomplete',name)]:
        assert f.get(key)==value,(name,key,f.get(key)); checks+=1
    assert 'required' in f; checks+=1
    assert sum(l.get('for')==name for l in p.labels)==1; checks+=1
print(f'PASS: {checks} actual-markup input-purpose assertions')
