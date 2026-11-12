"""Explicit fixed-target reload for disposable PHP fixtures, never production."""
import hashlib
import json
import urllib.error
import urllib.parse
import urllib.request

CASES=('string_release','string_restore','boolean_release','boolean_restore',
       'storage_outside','storage_outside_link','storage_direct_link','storage_alias','storage_restore')
ROUTES=('catalog','viewer','file','private_preview','private_file')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args):return None

class FixtureConfigReload:
    def __init__(self,out,base):
        parsed=urllib.parse.urlsplit(base)
        assert parsed.scheme=='http' and parsed.hostname=='127.0.0.1' and parsed.port and parsed.username is None and parsed.password is None and parsed.path=='' and not parsed.query and not parsed.fragment
        assert (out/'receipt.json').is_file(),'Synthetic fixture required'
        self.out=out;self.base=base;self.config=out/'site/src/config/config.php';self.marker=out/'config-observation.json'
        self.token=(out/'config-reload-key').read_text().strip();assert len(self.token)==64 and all(c in '0123456789abcdef' for c in self.token)
        self.expected=None;self.case=None;self.method=None;self.pid=None

    def apply(self,expected,case):
        assert case in CASES
        self.expected=expected.encode();self.case=case;self.method=None;self.pid=None
        assert self.config.is_file() and not self.config.is_symlink() and self.config.resolve()==self.config,'Fixture config target must be canonical before write'
        self.config.write_bytes(self.expected)
        assert self.config.read_bytes()==self.expected,'Exact synthetic config write failed'
        data=urllib.parse.urlencode({'token':self.token,'expected_sha256':hashlib.sha256(self.expected).hexdigest()}).encode()
        request=urllib.request.Request(self.base+'/fixture-config-reload.php',data=data,method='POST')
        try:
            with urllib.request.build_opener(NoRedirect).open(request,timeout=10) as response:
                status=response.status;raw=response.read(2049)
        except urllib.error.HTTPError as error:
            print('FAS_CONFIG_RELOAD_RECEIPT '+json.dumps({'case':self.case,'route':'reload','method':'unavailable','http_status':error.code}),flush=True)
            error.close();raise AssertionError('Explicit fixture reload was rejected') from None
        assert status==200 and len(raw)<=2048,'Explicit fixture reload response invalid'
        try:value=json.loads(raw)
        except ValueError:raise AssertionError('Explicit fixture reload response invalid') from None
        assert isinstance(value,dict) and set(value)=={'ok','method','target_verified','pid'} and value['ok'] is True and value['target_verified'] is True and value['method'] in ('no_opcache','forced_invalidation') and type(value['pid']) is int and value['pid']>0,'Explicit fixture invalidation was not verified'
        assert self.config.read_bytes()==self.expected,'Config changed during explicit reload'
        self.method=value['method'];self.pid=value['pid']

    def before_request(self):
        assert self.pid is not None,'Explicit config reload required before endpoint probe'
        self.marker.unlink(missing_ok=True)

    def observe(self,response,flag,storage,route):
        assert route in ROUTES and flag in ('boolean_true','boolean_false','string_true')
        try:
            value=json.loads(self.marker.read_text())
            if not isinstance(value,dict):value={}
        except (OSError,ValueError):value={}
        note={'case':self.case,'route':route,'method':self.method,'http_status':response[0],
              'disk_matches':self.config.read_bytes()==self.expected and value.get('disk_sha256')==hashlib.sha256(self.expected).hexdigest(),
              'flag_matches':value.get('loaded_flag')==flag,
              'storage_matches':value.get('storage_sha256')==hashlib.sha256(str(storage).encode()).hexdigest(),
              'same_server_process':type(value.get('pid')) is int and value['pid']==self.pid}
        print('FAS_CONFIG_RELOAD_RECEIPT '+json.dumps(note,sort_keys=True),flush=True)
        assert all(note[key] for key in ('disk_matches','flag_matches','storage_matches','same_server_process')),'Endpoint did not load the exact explicitly reloaded fixture config'
        return note
