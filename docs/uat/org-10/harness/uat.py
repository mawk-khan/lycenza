"""ORG.10 integrated local UAT harness core (ADR 0076 §78).

Real HTTPS against the local DDEV site (never staging/production): session
login with the TOTP challenge, Inertia page props, form posts with the
XSRF token, and a JSONL evidence register. Sessions are persisted per persona
(login is throttled at 6/min/IP). TOTP steps are tracked per account across
processes because the server refuses a replayed step.

Local state (gitignored): apps/platform/storage/app/org10-uat/
"""
import base64, hashlib, hmac, http.cookiejar, json, os, re, ssl, struct, time, urllib.error, urllib.parse, urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..', '..'))
STATE = os.path.join(ROOT, 'apps', 'platform', 'storage', 'app', 'org10-uat')
BASE = os.environ.get('UAT_BASE', 'https://lycenza.ddev.site')
REGISTER = os.environ.get('UAT_REGISTER', os.path.join(STATE, 'register.jsonl'))
PASSWORD = 'Demo1234!'
assert BASE.startswith('https://lycenza.ddev.site'), 'ORG.10 UAT runs against the local DDEV site only'

_ctx = ssl.create_default_context()
_ctx.check_hostname = False
_ctx.verify_mode = ssl.CERT_NONE
_last_login = [0.0]


def fixture():
    with open(os.path.join(STATE, 'fixture.json')) as f:
        return json.load(f)


def _totp_state():
    p = os.path.join(STATE, 'totp-state.json')
    return json.load(open(p)) if os.path.exists(p) else {}


def totp(email):
    """A fresh (never-used) TOTP code for the account; waits for the next step if needed."""
    acc = fixture()['accounts'][email]
    key = base64.b32decode(acc['secret'] + '=' * (-len(acc['secret']) % 8))
    st = _totp_state()
    last = max(st.get(email, 0), acc.get('enrolledStep', 0))
    while int(time.time() // 30) <= last:
        time.sleep(1)
    step = int(time.time() // 30)
    h = hmac.new(key, struct.pack('>Q', step), hashlib.sha1).digest()
    o = h[-1] & 0x0F
    code = '%06d' % ((struct.unpack('>I', h[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)
    st[email] = step
    json.dump(st, open(os.path.join(STATE, 'totp-state.json'), 'w'))
    return code


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Resp:
    def __init__(self, status, body, headers):
        self.status, self.body, self.headers = status, body, headers
        self.location = headers.get('Location') if headers else None
        self._page = None

    @property
    def page(self):
        if self._page is None:
            m = re.search(r'<script data-page="app" type="application/json">(.*?)</script>', self.body or '', re.S)
            self._page = json.loads(m.group(1)) if m else {}
        return self._page

    @property
    def props(self):
        return self.page.get('props', {})

    def json(self):
        return json.loads(self.body)


class Session:
    def __init__(self, email, school=None):
        self.email = email
        os.makedirs(os.path.join(STATE, 'jars'), exist_ok=True)
        self.jar_path = os.path.join(STATE, 'jars', email + '.txt')
        self.jar = http.cookiejar.LWPCookieJar(self.jar_path)
        if os.path.exists(self.jar_path):
            self.jar.load(ignore_discard=True, ignore_expires=True)
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), urllib.request.HTTPSHandler(context=_ctx), NoRedirect)
        self.school = school

    def _xsrf(self):
        return next((urllib.parse.unquote(c.value) for c in self.jar if c.name == 'XSRF-TOKEN'), '')

    def request(self, method, path, data=None, as_json=False, files=None, headers=None):
        h = {'X-XSRF-TOKEN': self._xsrf(), 'Accept': 'text/html,application/json'}
        body = None
        if files:
            boundary = 'uat' + str(time.time_ns())
            parts = []
            for k, v in (data or {}).items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
            for k, (fname, content, ctype) in files.items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{fname}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + content + b'\r\n')
            parts.append(f'--{boundary}--\r\n'.encode())
            body = b''.join(parts)
            h['Content-Type'] = f'multipart/form-data; boundary={boundary}'
        elif data is not None and as_json:
            body = json.dumps(data).encode()
            h['Content-Type'] = 'application/json'
            h['Accept'] = 'application/json'
        elif data is not None:
            body = urllib.parse.urlencode(_flatten(data), doseq=True).encode()
        h.update(headers or {})
        path = urllib.parse.quote(path, safe="/?=&:%,;+@[]-_.~")
        r = urllib.request.Request(BASE + path, data=body, method=method, headers=h)
        try:
            resp = self.op.open(r, timeout=120)
            out = Resp(resp.status, resp.read().decode('utf-8', 'replace'), resp.headers)
        except urllib.error.HTTPError as e:
            out = Resp(e.code, e.read().decode('utf-8', 'replace'), e.headers)
        self.jar.save(ignore_discard=True, ignore_expires=True)
        return out

    def get(self, path, **kw):
        return self.request('GET', path, **kw)

    def post(self, path, data=None, **kw):
        return self.request('POST', path, data if data is not None else {}, **kw)

    def put(self, path, data=None, **kw):
        return self.request('PUT', path, data if data is not None else {}, **kw)

    def follow(self, r):
        """GET the redirect target of a write and return it (flash/errors live there)."""
        if r.status in (301, 302, 303) and r.location:
            return self.get(r.location.replace(BASE, ''))
        return r

    def errors(self, r):
        return self.follow(r).props.get('errors') or {}

    def logged_in(self):
        r = self.get('/app')
        return r.status == 200

    def login(self, force=False):
        if not force and os.path.exists(self.jar_path) and self.logged_in():
            return self.activate()
        self.jar.clear()
        wait = 11 - (time.time() - _last_login[0])
        if wait > 0:
            time.sleep(wait)
        _last_login[0] = time.time()
        self.get('/login')
        r = self.post('/login', {'email': self.email, 'password': PASSWORD})
        if r.status == 429:
            time.sleep(61)
            r = self.post('/login', {'email': self.email, 'password': PASSWORD})
        if r.location and '/login/mfa' in r.location:
            r = self.post('/login/mfa', {'code': totp(self.email)})
        assert r.status in (302, 303) and '/login' not in (r.location or ''), f'login failed for {self.email}: {r.status} {r.location}'
        return self.activate()

    def activate(self, school=None):
        school = school or self.school
        if school:
            self.post(f'/app/schools/{school}/activate')
        return self

    def mfa(self):
        return totp(self.email)


def _flatten(data, prefix=''):
    out = []
    if isinstance(data, dict):
        for k, v in data.items():
            out += _flatten(v, f'{prefix}[{k}]' if prefix else str(k))
    elif isinstance(data, (list, tuple)):
        for i, v in enumerate(data):
            out += _flatten(v, f'{prefix}[{i}]')
    elif data is None:
        out.append((prefix, ''))
    elif isinstance(data, bool):
        out.append((prefix, '1' if data else '0'))
    else:
        out.append((prefix, str(data)))
    return out


def nav_keys(props):
    keys = []

    def walk(x):
        if isinstance(x, dict):
            if 'key' in x and ('href' in x or 'route' in x):
                keys.append(x['key'])
            for v in x.values():
                walk(v)
        elif isinstance(x, list):
            for v in x:
                walk(v)
    walk(props.get('navigation'))
    return keys


class Register:
    """Append-only JSONL evidence register (one line per scenario execution)."""

    def __init__(self, checkpoint):
        self.checkpoint = checkpoint
        os.makedirs(STATE, exist_ok=True)

    def record(self, sid, domain, persona, school, scope, pre, steps, expected, actual, ok, defect=None, kind='PASS/FAIL'):
        status = ok if isinstance(ok, str) else ('PASS' if ok else 'FAIL')
        row = dict(id=sid, checkpoint=self.checkpoint, domain=domain, persona=persona, school=school, scope=scope,
                   preconditions=pre, steps=steps, expected=expected, actual=str(actual)[:600], status=status,
                   defect=defect, at=time.strftime('%Y-%m-%dT%H:%M:%S'))
        with open(REGISTER, 'a') as f:
            f.write(json.dumps(row) + '\n')
        print(f'{status:<11} {sid:<14} {expected[:70]:<70} | {str(actual)[:90]}', flush=True)
        return status == 'PASS'


def once(key):
    """True the first time `key` is seen across runs (re-runnable checkpoints)."""
    p = os.path.join(STATE, 'done.json')
    done = json.load(open(p)) if os.path.exists(p) else {}
    if key in done:
        return False
    done[key] = time.strftime('%Y-%m-%dT%H:%M:%S')
    json.dump(done, open(p, 'w'))
    return True


def state(key, value=None):
    """A small persisted key/value store shared between checkpoint scripts."""
    p = os.path.join(STATE, 'state.json')
    data = json.load(open(p)) if os.path.exists(p) else {}
    if value is not None:
        data[key] = value
        json.dump(data, open(p, 'w'), indent=1)
    return data.get(key)
