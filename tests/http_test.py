#!/usr/bin/env python3
"""Test HTTP du front controller, lance dans le conteneur par tests/run.sh.

Un serveur PHP integre est demarre, puis des requetes HTTP reproduisent le
parcours reel : inscription, lien de verification, connexion, reinitialisation de
mot de passe, pages, QR en PNG (contre-verify par decodage zxing), quota de 10
creations, telechargements STL et 3MF, suppressions (jeton, confirmation, compte),
CSRF, en-tetes de securite, ceremonies WebAuthn avec le faux authenticateur de
tests/webauthn_fixture.py.

Le serveur PHP integre joue le role du serveur web de production (Apache ou nginx
+ PHP-FPM) : les fichiers reels sont servis directement, le reste va au front
controller. C'est la repartition de deploy/apache.conf et deploy/nginx.conf.

Usage : python3 tests/http_test.py
"""

import base64
import hashlib
import http.cookiejar
import io
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request

import zxingcpp
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "tests"))

import stl_check
import webauthn_fixture as wf
from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ec

OUT = os.environ.get("QLIP_OUT", "/tmp/opencode/badge")
PHP = os.environ.get("QLIP_PHP", "php")
PORT = int(os.environ.get("QLIP_PORT", "8199"))
BASE = "http://127.0.0.1:%d" % PORT
RP_ID = "127.0.0.1"
ORIGIN = BASE

ENV = dict(
    os.environ,
    QLIP_DB=os.path.join(OUT, "http_test.sqlite"),
    QLIP_VAR=os.path.join(OUT, "http_test_var"),
    QLIP_BASE_URL=BASE,
    QLIP_ADMIN_EMAILS="admin@qlip.test",
    QLIP_MAIL_TRANSPORT="outbox",
)

failures = 0


def check(label, ok, detail=""):
    global failures

    if not ok:
        failures += 1

    print("  %s   %s%s" % ("OK" if ok else "KO", label, (" : " + detail) if detail else ""))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Les redirections sont observees, pas suivies."""

    def redirect_request(self, req, fp, code, headers, url, info):
        return None


def client(jar):
    return urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(jar))


jar = http.cookiejar.CookieJar()
session = client(jar)
anonymous = client(http.cookiejar.CookieJar())


def lower(headers):
    return {str(key).lower(): value for key, value in dict(headers).items()}


def raw(opener, method, path, payload=None):
    headers = {"Accept": "application/json"}
    body = None

    if payload is not None:
        body = json.dumps(payload).encode()
        headers["Content-Type"] = "application/json"

    request = urllib.request.Request(BASE + path, data=body, headers=headers, method=method)

    try:
        response = opener.open(request, timeout=30)
        return response.status, lower(response.headers), response.read()
    except urllib.error.HTTPError as error:
        return error.code, lower(error.headers), error.read()


def get(path, opener=None):
    return raw(opener or session, "GET", path)


def post(path, payload, opener=None):
    return raw(opener or session, "POST", path, payload)


def as_json(body):
    try:
        return json.loads(body.decode())
    except (ValueError, UnicodeDecodeError):
        return {}


def get_json(path, opener=None):
    status, _, body = raw(opener or session, "GET", path)

    return status, as_json(body)


def post_json(path, payload, opener=None):
    status, _, body = raw(opener or session, "POST", path, payload)

    return status, as_json(body)


def csrf_of(body):
    match = re.search(rb'name="csrf" value="([^"]+)"', body) or re.search(rb'data-csrf="([^"]+)"', body)

    return match.group(1).decode() if match else ""


def link_of(body):
    match = re.search(rb"/verify/([0-9a-f]+)", body)

    return match.group(1).decode() if match else ""


def reset_link_of(body):
    match = re.search(rb"/password/reset/([0-9a-f]+)", body)

    return match.group(1).decode() if match else ""


def b64url_decode(value):
    return base64.urlsafe_b64decode(value + "=" * ((4 - len(value) % 4) % 4))


def start_server():
    for stale in [ENV["QLIP_DB"] + suffix for suffix in ("", "-wal", "-shm")]:
        if os.path.exists(stale):
            os.remove(stale)

    log = open(os.path.join(OUT, "http_server.log"), "w")

    server = subprocess.Popen(
        [PHP, "-S", "127.0.0.1:%d" % PORT, os.path.join(ROOT, "public/router.php")],
        cwd=ROOT,
        env=ENV,
        stdout=log,
        stderr=log,
    )

    for _ in range(80):
        try:
            status, _, _ = get("/health")

            if status == 200:
                return server
        except (urllib.error.URLError, ConnectionError):
            pass

        time.sleep(0.5)

    raise RuntimeError("/health ne repond pas")


class FakeAuthenticator:
    """Une YubiKey : cle EC2/P-256, ceremonies preparees pour un challenge donne.

    `can_verify` = la cle sait faire la verification utilisateur. Une cle U2F seule
    (YubiKey NEO) ne la sait pas faire : le drapeau UV n est pas pose, et elle ne produit
    que le format d attestation fido-u2f.
    """

    def __init__(self, credential_id, can_verify=True):
        self.key = ec.generate_private_key(ec.SECP256R1())
        self.cose = wf.cose_ec(self.key)
        self.credential_id = credential_id
        self.can_verify = can_verify
        # Le format de la ceremonie est enregistre avec la credential : il porte la base de
        # signature de l assertion.
        self.fmt = "none"

    def flags(self, base):
        return base | 0x04 if self.can_verify else base

    def digest(self, auth_data, client_data, fmt=None):
        """La base que la spec definit pour le format de la credential.

        WebAuthn : hash(AuthData) + hash(clientDataJSON). Pour une cle U2F seule, la base de la
        reponse d authentification est application parameter + octet de presence + counter +
        challenge parameter, soit AuthData sans hash suivi du hash des donnees client : c est ce
        que mesure tests/assert_base.py sur une cle reelle.
        """
        client_hash = hashlib.sha256(client_data).digest()

        if (fmt or self.fmt) == "fido-u2f":
            return auth_data + client_hash

        return hashlib.sha256(auth_data).digest() + client_hash

    def register(self, challenge, fmt="none"):
        self.fmt = fmt
        auth_data = wf.auth_data(self.flags(0x41), 1, self.credential_id, self.cose, True, RP_ID)
        client_data = wf.client_data("webauthn.create", ORIGIN, challenge)

        statement = {}

        if fmt == "packed":
            statement = {"alg": -7, "sig": wf.sign(self.key, self.digest(auth_data, client_data), "ES256")}

        return {
            "credential_id": wf.b64url(self.credential_id),
            "attestation": wf.b64url(wf.cbor({"fmt": fmt, "authData": auth_data, "attStmt": statement})),
            "client_data": wf.b64url(client_data),
        }

    def register_u2f(self, challenge):
        """Ceremonie d une cle U2F seule : fmt = fido-u2f, x5c obligatoire, signature en DER.

        La base de signature d attestation est 0x00 || rpIdHash || clientDataHash || credentialId
        || publicKeyU2F, pas hash(authData) + hash(clientData). Le premier octet est le
        contre-signal (0x00 pour l attestation). Une signature faite sur l autre base ne passe
        pas : c est ce que verifie le harnais.
        """
        self.fmt = "fido-u2f"
        auth_data = wf.auth_data(self.flags(0x41), 1, self.credential_id, self.cose, True, RP_ID)
        client_data = wf.client_data("webauthn.create", ORIGIN, challenge)
        point = wf.point(self.cose)
        message = (
            b"\x00"
            + hashlib.sha256(RP_ID.encode()).digest()
            + hashlib.sha256(client_data).digest()
            + self.credential_id
            + point
        )

        statement = {
            "alg": -7,
            "sig": self.key.sign(message, ec.ECDSA(hashes.SHA256())),
            "x5c": [wf.cbor(wf.self_signed(self.key))],
            "counter": 0,
        }

        return {
            "credential_id": wf.b64url(self.credential_id),
            "attestation": wf.b64url(wf.cbor({"fmt": "fido-u2f", "authData": auth_data, "attStmt": statement})),
            "client_data": wf.b64url(client_data),
        }

    def assert_(self, challenge, sign_count, form="raw", other_base=False):
        auth_data = wf.auth_data(self.flags(0x01), sign_count, self.credential_id, self.cose, False, RP_ID)
        client_data = wf.client_data("webauthn.get", ORIGIN, challenge)
        # La base est celle du format enregistre avec la credential. `other_base` produit la base
        # de l autre format, qui doit etre refusee : la base ne se choisit pas au moment de
        # verifier, et le harnais ne doit pas produire la base que l implementation lit.
        message = self.digest(auth_data, client_data, wf.other_fmt(self.fmt) if other_base else None)
        wire = wf.wire_form(self.key, message, "ES256")

        return {
            "credential_id": wf.b64url(self.credential_id),
            "auth_data": wf.b64url(auth_data),
            "client_data": wf.b64url(client_data),
            # La spec definit r || s pour une assertion ; la couche de compatibilite CTAP1
            # d une cle U2F seule transmet le DER. Les deux doivent verifier la meme base.
            "signature": wf.b64url(wire if form == "der" else wf.raw_signature(wire, 32)),
        }


def run():
    # 1. Accueil et en-tetes de securite
    status, headers, body = get("/")

    check("accueil", status == 200 and b"Qlip" in body)
    check("en-tete CSP", "content-security-policy" in headers, headers.get("content-security-policy", "")[:48])
    check("en-tete nosniff", headers.get("x-content-type-options") == "nosniff")
    check("en-tete frame", headers.get("x-frame-options") == "DENY")

    status, headers, _ = get("/health")

    # Un JSON rendu par l app doit avoir les memes en-tetes qu une page : sans nosniff,
    # une reponse JSON mal declaree serait sniffée comme du HTML par le navigateur.
    check("en-tetes sur une reponse JSON", headers.get("x-content-type-options") == "nosniff", str(status))

    # 2. Inscription, email, verification
    status, _, body = post("/register", {"csrf": csrf_of(get("/register")[2]), "email": "admin@qlip.test", "password": "un-mot-de-passe-long"})

    check("inscription", status == 200)

    link = link_of(body)
    status, _, body = get("/verify/" + link)

    check("verification d email", status == 200, link)
    check("outbox", os.listdir(os.path.join(ENV["QLIP_VAR"], "outbox")) != [])

    # 3. Connexion
    status, headers, _ = post("/login", {"csrf": csrf_of(get("/login")[2]), "email": "admin@qlip.test", "password": "un-mot-de-passe-long"})

    check("connexion", status == 302 and headers.get("location", "").endswith("/dashboard"), headers.get("location", ""))

    csrf = csrf_of(get("/dashboard")[2])

    # 4. Pages
    status, headers, _ = post("/pages", {"csrf": csrf, "title": "Cles trouvees", "slug": "cles", "note": "Posees chez le gardien"})

    slug = headers.get("location", "").split("/p/")[-1].strip("/")

    check("creation de page", status == 302 and slug == "cles", "%d %s" % (status, slug))
    check("page publique", b"Cles trouvees" in get("/p/" + slug)[2])
    check("edition", post("/p/" + slug, {"csrf": csrf, "title": "Cles trouvees", "note": "Modifiee"})[0] == 302)
    check("edition enregistree", b"Modifiee" in get("/p/" + slug)[2])

    # 4b. Echappement et controle des champs. Le maxlength des formulaires n est pas une
    # protection : on poste directement. Le rendu doit encoder, la taille limite se
    # applique cote serveur.
    status, _, body = post("/p/" + slug, {"csrf": csrf, "title": "<script>alert(1)</script>", "note": "<img src=x onerror=alert(1)>"})

    check("XSS accepte en base", status == 302)

    body = get("/p/" + slug)[2]

    check("titre echappe au rendu", b"&lt;script&gt;alert(1)&lt;/script&gt;" in body and b"<script>alert(1)" not in body)
    check("note echappee au rendu", b"&lt;img src=x onerror=alert(1)&gt;" in body and b"<img src=x onerror=" not in body)

    status, _, body = post("/p/" + slug, {"csrf": csrf, "title": "x" * 81})

    check("titre trop long refuse", status == 422, as_json(body).get("error", ""))

    status, _, body = post("/p/" + slug, {"csrf": csrf, "email_contact": "pas un email"})

    check("email de contact refuse", status == 422, as_json(body).get("error", ""))

    status, headers, body = post("/pages", {"csrf": csrf, "title": "Long slug", "slug": "s" * 60})

    check("slug tronque", status == 302 and len(headers.get("location", "").split("/p/")[-1].strip("/")) == 32)

    # 5. Le PNG doit se decoder et redonner l URL de la page
    status, headers, body = get("/qr/" + slug + ".png")

    check("PNG", status == 200 and headers.get("content-type") == "image/png", "%d octets" % len(body))

    codes = zxingcpp.read_barcodes(Image.open(io.BytesIO(body)))

    check("QR decode", len(codes) == 1 and codes[0].text == BASE + "/p/" + slug, codes[0].text if codes else "rien")

    # 6. Un compte anonyme ne voit pas le tableau de bord
    status, headers, _ = get("/dashboard", anonymous)

    check("anonyme redirect", status == 302 and headers.get("location", "").endswith("/login"))

    # 7. CSRF et quota
    status, data = post_json("/badges", {"csrf": "mauvais-jeton", "slug": slug})

    check("CSRF refuse", status == 400, data.get("error", ""))

    created = []

    for index in range(10):
        status, data = post_json("/badges", {"csrf": csrf, "slug": slug, "style": "engraved", "bicolor": "1" if index == 4 else "0"})

        if status != 201:
            check("badge %d" % (index + 1), False, data.get("error", ""))
            break

        created.append(data["badge"]["id"])

    check("10 badges", len(created) == 10, "%d" % len(created))

    status, data = post_json("/badges", {"csrf": csrf, "slug": slug, "style": "engraved"})

    check("11e refusee", status == 422, data.get("error", ""))

    # 8. Telechargements STL et 3MF
    status, headers, body = get("/badges/%d/stl" % created[4])

    check("STL", status == 200 and headers.get("content-type") == "model/stl", headers.get("content-disposition", ""))

    text = body.decode()
    solids = stl_check.parse_ascii_stl(text)

    check("STL ASCII", text.startswith("solid") and len(solids) == 2, "%d volumes" % len(solids))

    problems, contacts, volume = stl_check.check_solid(solids[0])

    check("STL etanche", problems == [] and volume > 0.0, problems[0] if problems else "%d triangles" % len(solids[0]["facets"]))

    status, headers, threeMf = get("/badges/%d/3mf" % created[4])

    check("3MF", status == 200 and headers.get("content-type") == "model/3mf")

    path = os.path.join(OUT, "http_test.3mf")
    open(path, "wb").write(threeMf)

    counts = [len(solid["facets"]) for solid in solids]
    problems = stl_check.check_3mf(path, counts)

    check("3MF valide", problems == [], problems[0] if problems else "%d triangles" % sum(counts))

    # 9. YubiKey : enregistrement puis assertion
    status, _, body = get("/admin/yubikey")

    check("page YubiKey", status == 200)

    check("bouton d enrolement pour un admin", b'data-action="register"' in body)
    check("page YubiKey charge app.js", b"assets/app.js" in body, "script externe, jamais inline")

    # Le script n est pas execute par la suite : il est relu pour ce qui ne peut pas etre
    # verifie ici, un nom de methode de l API WebAuthn et les appels de fonctions du fichier.
    script = open(os.path.join(ROOT, "public", "assets", "app.js"), encoding="utf-8").read()
    known = {"Error", "JSON", "Uint8Array", "atob", "btoa", "fetch"}
    keywords = {"if", "for", "while", "switch", "return", "catch", "typeof", "new", "throw", "function", "of", "in"}

    check("app.js : objet d attestation lu par la propriete attestationObject", "attestationObject" in script, "la forme de la spec courante, pas un nom invente")
    check("app.js : l ancienne forme getAttestation() reste acceptee", "typeof response.getAttestation" in script, "elle n existe pas dans tous les navigateurs : un seul nom donne un TypeError")
    check("app.js : liste de credentials prise du challenge", "ceremony.allowCredentials" in script, "une cle non resident ne retrouve pas son identifiant seule")
    check("app.js : politique lue des options du serveur", "ceremony.policy" in script, "le client ne choisit pas sa politique de verification")
    check("app.js : cible d assertion prise de la page", "element.dataset.assert" in script, "la ceremonie du second facteur ne va pas a celle d administration")
    check(
        "app.js : les deux ceremonies nomment le type par le membre publicKey",
        re.search(r"credentials\.create\(\{\s*publicKey", script) is not None
        and re.search(r"credentials\.get\(\{\s*publicKey", script) is not None,
        "une requete sans membre de type est refusee par le container (NotSupportedError)",
    )
    # Tous les scripts du dossier sont relus, pas seulement app.js : le script du theme appelle
    # ses fonctions sans etre execute par la suite.
    for name in sorted(n for n in os.listdir(os.path.join(ROOT, "public", "assets")) if n.endswith(".js")):
        text = open(os.path.join(ROOT, "public", "assets", name), encoding="utf-8").read()
        flat = re.sub(r"/\*.*?\*/", " ", re.sub(r"//[^\n]*", "", text), flags=re.S)
        funcs = set(re.findall(r"function\s+([A-Za-z0-9_$]+)\s*\(", flat)) | set(re.findall(r"const\s+([A-Za-z0-9_$]+)\s*=", flat))
        hit = set(re.findall(r"(?<![\w.$\])}])([A-Za-z0-9_$]+)\s*\(", flat)) - keywords

        check(
            "script %s : aucune fonction appelee sans etre definie" % name,
            hit <= funcs | known,
            ", ".join(sorted(hit - funcs - known)) or "%d appels" % len(hit),
        )

    # Le theme est pose par l attribut data-theme, jamais par une regle dans la page : la CSP
    # (style-src 'self') autorise la feuille, pas une declaration inline. Et le script doit etre
    # execute avant le contenu, sinon la page s affiche en clair puis bascule.
    _, _, home = get("/")

    check("page charge theme.js", b"assets/theme.js" in home, "script externe, jamais inline")
    check(
        "theme.js charge sans defer, dans le head",
        re.search(rb'<head>.*?<script src="[^"]*assets/theme\.js"></script>', home, flags=re.S) is not None,
        "un script defer s execute apres le parse : la page bascule apres le premier paint",
    )
    check("switch de theme dans le nav", b'data-theme-toggle' in home, "le bouton est le dernier element du nav")

    csrf = csrf_of(body)

    status, headers, _ = get("/admin")

    check("admin sans YubiKey", status == 302, headers.get("location", ""))

    yubikey = FakeAuthenticator(b"claie-de-test")

    status, ceremony = get_json("/webauthn/challenge")

    check("challenge", status == 200 and ceremony.get("rpId") == RP_ID, ceremony.get("challenge", ""))

    status, data = post_json("/webauthn/register", dict(csrf=csrf, **yubikey.register(b64url_decode(ceremony["challenge"]))))

    check("enregistrement none", status == 201, data.get("error") or data.get("credential", {}).get("credential_id", ""))
    admin_credential = data.get("credential", {}).get("id")

    # Packed en auto-attestation : la cle de la credential signe les donnees.
    packed = FakeAuthenticator(b"claie-packed")
    status, ceremony = get_json("/webauthn/challenge")
    status, data = post_json("/webauthn/register", dict(csrf=csrf, **packed.register(b64url_decode(ceremony["challenge"]), "packed")))

    check("enregistrement packed", status == 201, data.get("error") or ("atteste" if data.get("credential", {}).get("attested") else "non atteste"))

    status, ceremony = get_json("/webauthn/challenge")
    status, data = post_json("/webauthn/assert", dict(csrf=csrf, **yubikey.assert_(b64url_decode(ceremony["challenge"]), 2)))

    check("assertion", status == 200 and data.get("ok") is True, data.get("error", ""))

    status, _, body = get("/admin")

    check("admin apres YubiKey", status == 200 and b"Administration" in body)

    # Le jeton CSRF a ete regenere par la ceremony : on le relit.
    csrf = csrf_of(get("/admin/yubikey")[2])
    status, ceremony = get_json("/webauthn/challenge")

    status, data = post_json("/webauthn/assert", dict(csrf=csrf, **yubikey.assert_(b"un-autre-challenge", 3)))

    check("challenge refuse", status == 400, data.get("error", ""))

    status, data = post_json(
        "/webauthn/assert",
        dict(csrf=csrf, **dict(yubikey.assert_(b64url_decode(ceremony["challenge"]), 3), credential_id="claie-inconnue")),
    )

    check("credential inconnue", status == 403, data.get("error", ""))

    # Rejoue : le meme compteur n'est pas accepte deux fois.
    status, data = post_json("/webauthn/assert", dict(csrf=csrf, **yubikey.assert_(b64url_decode(ceremony["challenge"]), 2)))

    check("rejoue refuse", status == 400, data.get("error", ""))

    # La forme DER est celle que la couche de compatibilite CTAP1 d une cle U2F seule transmet
    # a l assertion : elle doit verifier la meme base que la forme brute r || s. Une cle separee
    # est utilisee ici, sinon cette assertion consommerait le compteur attendu par les checks
    # suivants.
    der_key = FakeAuthenticator(b"claie-forme")
    status, ceremony = get_json("/webauthn/challenge")
    status, data = post_json("/webauthn/register", dict(csrf=csrf, **der_key.register(b64url_decode(ceremony["challenge"]))))

    check("enregistrement pour la forme DER", status == 201, data.get("error", ""))

    # La ceremony a regenere le jeton CSRF : on le relit.
    csrf = csrf_of(get("/admin/yubikey")[2])
    status, ceremony = get_json("/webauthn/challenge")
    status, data = post_json("/webauthn/assert", dict(csrf=csrf, **der_key.assert_(b64url_decode(ceremony["challenge"]), 2, form="der")))

    check("assertion en forme DER", status == 200 and data.get("ok") is True, data.get("error", ""))

    # La base attendue est celle du format enregistre avec la credential. Cette cle est
    # enregistree sans attestation, donc la base U2F ne la concerne pas : une signature faite
    # sur la base U2F doit etre refusee. Le harnais ne doit pas produire la base que
    # l implementation lit, sinon la base ne se controle pas.
    csrf = csrf_of(get("/admin/yubikey")[2])
    status, ceremony = get_json("/webauthn/challenge")
    status, data = post_json("/webauthn/assert", dict(csrf=csrf, **der_key.assert_(b64url_decode(ceremony["challenge"]), 3, other_base=True)))

    check("base U2F refusee pour une credential non fido-u2f", status == 400 and "Signature" in data.get("error", ""), data.get("error", ""))

    csrf = csrf_of(get("/admin/yubikey")[2])

    # 10. Un compte non administrateur est refuse, meme avec une credential
    status, _, body = post("/logout", {"csrf": "mauvais-jeton"})

    check("deconnexion refusee sans jeton", status == 400, str(as_json(body).get("error", "")))

    status, headers, _ = post("/logout", {"csrf": csrf})

    check("deconnexion", status == 302)

    # Le cookie porte le nom de la session : destroy() doit l expirer, pas seulement
    # vider les donnees cote serveur. PHP remplace une valeur vide par "deleted".
    set_cookie = headers.get("set-cookie", "")

    check("cookie videe a la deconnexion", "qlip_session=" in set_cookie and "1970" in set_cookie, set_cookie)

    status, _, body = post("/register", {"csrf": csrf_of(get("/register")[2]), "email": "normal@qlip.test", "password": "un-mot-de-passe-long"})

    check("inscription normal", status == 200)
    check("verification normal", get("/verify/" + link_of(body))[0] == 200)

    status, headers, _ = post(
        "/login",
        {"csrf": csrf_of(get("/login")[2]), "email": "normal@qlip.test", "password": "un-mot-de-passe-long"},
    )

    check("connexion normal", status == 302)

    status, _, body = get("/admin/yubikey")

    check("pas de bouton d enrolement pour un non admin", status == 200 and b'data-action="register"' not in body)

    csrf = csrf_of(body)
    status, ceremony = get_json("/webauthn/challenge")

    normal = FakeAuthenticator(b"claie-normal")
    status, data = post_json("/webauthn/register", dict(csrf=csrf, **normal.register(b64url_decode(ceremony["challenge"]))))

    check("enregistrement normal", status == 201, data.get("error", ""))

    status, ceremony = get_json("/webauthn/challenge")
    status, data = post_json("/webauthn/assert", dict(csrf=csrf, **normal.assert_(b64url_decode(ceremony["challenge"]), 2)))

    check("non admin refuse", status == 403, data.get("error", ""))

    # 10b. Second facteur avec mot de passe, sur HTTP. Le mot de passe prouve l'utilisateur, la
    # cle prouve sa possession : le profil est 'presence', donc la politique est 'discouraged'.
    # Une cle U2F seule ne sait pas faire la verification utilisateur, donc elle ne peut pas
    # satisfaire une ceremonie reglee en 'required' (le profil passkey de la page d'administration).
    two = client(http.cookiejar.CookieJar())

    status, _, body = post("/register", {"csrf": csrf_of(get("/register", two)[2]), "email": "2fa@qlip.test", "password": "un-mot-de-passe-long"}, two)

    check("inscription 2fa", status == 200)
    check("verification 2fa", get("/verify/" + link_of(body), two)[0] == 200)

    status, headers, _ = post("/login", {"csrf": csrf_of(get("/login", two)[2]), "email": "2fa@qlip.test", "password": "un-mot-de-passe-long"}, two)

    # Pas encore de credential : le mot de passe suffit. Le second facteur ne s applique qu aux
    # comptes qui ont une credential.
    check("connexion 2fa sans credential", status == 302, headers.get("location", ""))

    status, _, body = get("/dashboard", two)

    # C est la page qui rend la ceremonie qui pose le profil : le client ne peut pas choisir sa
    # politique, sinon il aurait une assertion sur une credential reglee en required.
    check("enrolement propose sur le tableau de bord", b'data-action="register"' in body)
    two_csrf = csrf_of(body)

    status, ceremony = get_json("/webauthn/challenge", two)

    check("profil de la ceremonie : presence", status == 200 and ceremony.get("policy") == "discouraged", str(ceremony.get("policy", "")))

    u2f = FakeAuthenticator(b"claie-u2f", can_verify=False)
    status, data = post_json("/webauthn/register", dict(csrf=two_csrf, **u2f.register_u2f(b64url_decode(ceremony["challenge"]))), two)

    check("enrolement d une cle U2F", status == 201, data.get("error", ""))
    check("politique stockee par la credential", data.get("credential", {}).get("policy") == "discouraged", json.dumps(data.get("credential", {})))
    check("format stocke par la credential", data.get("credential", {}).get("fmt") == "fido-u2f", json.dumps(data.get("credential", {})))
    two_credential = data.get("credential", {}).get("id")

    status, headers, _ = post("/logout", {"csrf": two_csrf}, two)

    check("deconnexion 2fa", status == 302)

    status, headers, _ = post("/login", {"csrf": csrf_of(get("/login", two)[2]), "email": "2fa@qlip.test", "password": "un-mot-de-passe-long"}, two)

    check("second facteur demande apres le mot de passe", status == 302 and "/login/2fa" in headers.get("location", ""), headers.get("location", ""))

    status, headers, _ = get("/dashboard", two)

    # La session en attente ne donne aucun acces : le mot de passe seul ne confirme pas la session.
    check("aucun acces avant la ceremonie", status == 302 and "/login" in headers.get("location", ""), headers.get("location", ""))

    status, _, body = get("/login/2fa", two)

    check("page du second facteur", status == 200 and b'data-action="assert"' in body)
    check("la page pose sa cible d assertion", b'data-assert="http://127.0.0.1:%d/login/2fa"' % PORT in body)

    two_csrf = csrf_of(body)
    status, ceremony = get_json("/webauthn/challenge", two)

    check("politique du challenge pour la session en attente", ceremony.get("policy") == "discouraged", str(ceremony.get("policy", "")))
    check("allowCredentials du compte en attente", [entry.get("id") for entry in ceremony.get("allowCredentials", [])] == [wf.b64url(u2f.credential_id)], json.dumps(ceremony.get("allowCredentials", [])))

    assertion = u2f.assert_(b64url_decode(ceremony["challenge"]), 2)
    foreign = dict(assertion, credential_id=wf.b64url(b"claie-normal"))

    status, data = post_json("/login/2fa", dict(csrf=two_csrf, **foreign), two)

    check("credential d un autre compte refusee", status == 403, data.get("error", ""))

    # Le verou est commun au mot de passe et au second facteur : 8 tentatives au total sur la
    # session, la premiere etant la credential d un autre compte. Sans lui, une ceremonie refusee
    # servirait a tester les credentials sans limite.
    for _ in range(7):
        post_json("/login/2fa", dict(csrf=two_csrf, **foreign), two)

    status, data = post_json("/login/2fa", dict(csrf=two_csrf, **assertion), two)

    check("verou applique au second facteur", status == 429, data.get("error", ""))

    # Ceremonie acceptee sur une session neuve : la cle qui prouve la possession confirme la
    # session, et la politique stockee par la credential est gardee.
    two = client(http.cookiejar.CookieJar())

    status, headers, _ = post("/login", {"csrf": csrf_of(get("/login", two)[2]), "email": "2fa@qlip.test", "password": "un-mot-de-passe-long"}, two)

    check("second facteur demande (session neuve)", status == 302, headers.get("location", ""))

    two_csrf = csrf_of(get("/login/2fa", two)[2])
    status, ceremony = get_json("/webauthn/challenge", two)
    status, data = post_json("/login/2fa", dict(csrf=two_csrf, **u2f.assert_(b64url_decode(ceremony["challenge"]), 2)), two)

    check("second facteur accepte", status == 200 and data.get("ok") is True, data.get("error", ""))
    check("la ceremonie renvoie la redirection", str(data.get("redirect", "")).endswith("/dashboard"), json.dumps(data))
    check("tableau de bord apres le second facteur", get("/dashboard", two)[0] == 200)

    # La cle U2F seule signe la base U2F : application parameter (le hash du RP ID) + octet de
    # presence + counter + challenge parameter, ce qui fait AuthData sans hash suivi du hash des
    # donnees client. C est la base mesuree sur la cle reelle (tests/assert_base.py), et elle
    # vient du format enregistre avec la credential : la base WebAuthn doit etre refusee pour
    # cette credential, le serveur ne les essaie pas dans les deux sens.
    # Le jeton CSRF a ete regenere par la ceremonie qui a confirme la session : le vieux jeton
    # ne deconnecte pas, et une session encore confirmee rendrait le check faux. On le relit.
    two_csrf = csrf_of(get("/dashboard", two)[2])
    status, headers, _ = post("/logout", {"csrf": two_csrf}, two)

    check("deconnexion avant la mesure de base", status == 302, headers.get("location", ""))

    status, headers, _ = post("/login", {"csrf": csrf_of(get("/login", two)[2]), "email": "2fa@qlip.test", "password": "un-mot-de-passe-long"}, two)

    check("reconnexion au second facteur", status == 302 and "/login/2fa" in headers.get("location", ""), headers.get("location", ""))

    two_csrf = csrf_of(get("/login/2fa", two)[2])
    status, ceremony = get_json("/webauthn/challenge", two)
    status, data = post_json("/login/2fa", dict(csrf=two_csrf, **u2f.assert_(b64url_decode(ceremony["challenge"]), 3, other_base=True)), two)

    check("base WebAuthn refusee pour une credential fido-u2f", status == 400 and "Signature" in data.get("error", ""), data.get("error", ""))

    status, headers, _ = get("/dashboard", two)

    # La ceremonie echouee ne confirme pas la session : la page du second facteur est une session
    # en attente, sans acces.
    check("la session reste non confirmee", status == 302 and "/login" in headers.get("location", ""), headers.get("location", ""))

    two_csrf = csrf_of(get("/login/2fa", two)[2])
    status, ceremony = get_json("/webauthn/challenge", two)
    status, data = post_json("/login/2fa", dict(csrf=two_csrf, **u2f.assert_(b64url_decode(ceremony["challenge"]), 4)), two)

    check("cle U2F acceptee sur sa base", status == 200 and data.get("ok") is True, data.get("error", ""))

    # La politique ne peut pas etre abaissee par le profil : la page d administration rend le
    # profil passkey, donc la meme cle est refusee sur cette ceremonie. Un compte non
    # administrateur reste refuse, et un administrateur dont la seule cle est une cle U2F entre par
    # mot de passe + possession, pas par une assertion seule.
    admin = client(http.cookiejar.CookieJar())

    status, headers, _ = post("/login", {"csrf": csrf_of(get("/login", admin)[2]), "email": "admin@qlip.test", "password": "un-mot-de-passe-long"}, admin)

    check("second facteur demande pour l admin", status == 302 and "/login/2fa" in headers.get("location", ""), headers.get("location", ""))

    admin_csrf = csrf_of(get("/login/2fa", admin)[2])
    status, ceremony = get_json("/webauthn/challenge", admin)
    status, data = post_json("/login/2fa", dict(csrf=admin_csrf, **yubikey.assert_(b64url_decode(ceremony["challenge"]), 3)), admin)

    check("second facteur accepte pour l admin", status == 200 and data.get("ok") is True, data.get("error", ""))

    status, _, body = get("/dashboard", admin)

    admin_csrf = csrf_of(body)
    status, ceremony = get_json("/webauthn/challenge", admin)
    u2f_admin = FakeAuthenticator(b"claie-u2f-admin", can_verify=False)
    status, data = post_json("/webauthn/register", dict(csrf=admin_csrf, **u2f_admin.register_u2f(b64url_decode(ceremony["challenge"]))), admin)

    check("cle U2F enregistree pour l admin", status == 201, data.get("error", ""))

    status, _, body = get("/admin/yubikey", admin)

    admin_csrf = csrf_of(body)
    status, ceremony = get_json("/webauthn/challenge", admin)

    check("profil de la page d administration : passkey", ceremony.get("policy") == "required", str(ceremony.get("policy", "")))

    status, data = post_json("/webauthn/assert", dict(csrf=admin_csrf, **u2f_admin.assert_(b64url_decode(ceremony["challenge"]), 2)), admin)

    check("la cle U2F ne satisfait pas la ceremonie passkey", status == 400 and "Verification utilisateur" in data.get("error", ""), data.get("error", ""))

    status, _, _ = get("/admin", admin)

    check("administration ouverte par mot de passe + possession", status == 200, str(status))

    # 10c. Journal de la page d administration : paginé par page de 50. Le nombre de pages vient
    # du COUNT, pas des lignes lues, et un numero demande hors plage est ramene : sinon la
    # requete partirait avec un offset negatif, ce qui n est pas une page.
    status, _, body = get("/admin?journal_page=99", admin)

    current = re.search(rb'<span class="current">(\d+)</span>', body)
    links = [int(number) for number in re.findall(rb"journal_page=(\d+)", body)]

    check("journal : la page affiche son numero", re.search(rb"lignes, page \d+ sur \d+", body) is not None, str(status))

    # Les liens sont les autres pages : si le numero demande est ramene a la derniere, il ne reste
    # que les pages qui la precedent. Une page vide ne serait pas une reponse.
    check("journal : numero demande hors plage ramene a la derniere page",
          current is not None and int(current.group(1)) == len(links) + 1,
          current.group(1).decode() if current else "aucun numero")
    check("journal : pas de lien vers une page qui n existe pas", not links or max(links) < (int(current.group(1)) if current else 1), str(links))

    # Effacement : POST seulement, jeton CSRF, confirmation explicite, mot de passe du compte, et
    # la session confirmee par une assertion comme le reste de la page. Rien ne part ici : le
    # journal du conteneur n a rien de plus vieux que 2 mois, et c est ce que le controle de date
    # doit montrer.
    status, _, body = get("/admin", admin)
    admin_csrf = csrf_of(body)

    check("journal : rien de plus vieux que la retention", b"Lignes concernees : 0" in body)

    status, _, _ = raw(admin, "GET", "/admin/journal/purge")

    check("GET sur la route effacer le journal", status == 405, str(status))

    status, data = post_json("/admin/journal/purge", {"csrf": "mauvais-jeton", "confirm": "1", "password": "un-mot-de-passe-long"}, admin)

    check("effacement refuse sans jeton", status == 400, data.get("error", ""))

    status, data = post_json("/admin/journal/purge", {"csrf": admin_csrf, "confirm": "0", "password": "un-mot-de-passe-long"}, admin)

    check("effacement refuse sans confirmation", status == 400, data.get("error", ""))

    status, data = post_json("/admin/journal/purge", {"csrf": admin_csrf, "confirm": "1"}, admin)

    check("effacement refuse sans mot de passe", status == 401, data.get("error", ""))

    status, data = post_json("/admin/journal/purge", {"csrf": admin_csrf, "confirm": "1", "password": "pas-le-bon-mot-de-passe"}, admin)

    check("effacement refuse avec un mauvais mot de passe", status == 401, data.get("error", ""))

    # Un compte dont la session est confirmee par une assertion n est pas administrateur : il ne
    # peut pas effacer le journal, meme avec son mot de passe.
    two_csrf = csrf_of(get("/dashboard", two)[2])

    status, data = post_json("/admin/journal/purge", {"csrf": two_csrf, "confirm": "1", "password": "un-mot-de-passe-long"}, two)

    check("effacement reserve a l administration", status == 403, data.get("error", ""))

    status, data = post_json("/admin/journal/purge", {"csrf": admin_csrf, "confirm": "1", "password": "un-mot-de-passe-long"}, admin)

    check("effacement accepte pour le compte administrateur", status == 200 and data.get("deleted") is True, json.dumps(data))
    check("effacement : la retention ne touche pas les lignes du jour", data.get("rows") == 0, str(data.get("rows")))
    check("trace de l effacement dans le journal", b"effacement du journal" in get("/admin", admin)[2])

    # 10d. Oublier la cle 2FA. POST seulement, jeton CSRF, confirmation explicite, mot de passe
    # du compte, et controle d appartenance. Supprimer la cle qui confirmait la session la rend
    # de nouveau non confirmee : la page d administration redemande une assertion.
    status, _, _ = raw(session, "GET", "/credentials/%s/delete" % two_credential)

    check("GET sur la route oublier la cle", status == 405, str(status))

    status, data = post_json(
        "/credentials/%s/delete" % two_credential,
        {"csrf": "mauvais-jeton", "confirm": "1", "password": "un-mot-de-passe-long"},
    )

    check("oublier la cle refuse sans jeton", status == 400, data.get("error", ""))

    status, data = post_json(
        "/credentials/%s/delete" % two_credential,
        {"csrf": csrf, "confirm": "0", "password": "un-mot-de-passe-long"},
    )

    check("oublier la cle refuse sans confirmation", status == 400, data.get("error", ""))

    status, data = post_json("/credentials/%s/delete" % two_credential, {"csrf": csrf, "confirm": "1"})

    check("oublier la cle refuse sans mot de passe", status == 401, data.get("error", ""))

    status, data = post_json(
        "/credentials/%s/delete" % two_credential,
        {"csrf": csrf, "confirm": "1", "password": "pas-le-bon-mot-de-passe"},
    )

    check("oublier la cle refuse avec un mauvais mot de passe", status == 401, data.get("error", ""))

    # La session courante est un compte non administrateur : elle ne peut pas supprimer la cle
    # d un autre compte, meme avec son propre mot de passe.
    status, data = post_json(
        "/credentials/%s/delete" % two_credential,
        {"csrf": csrf, "confirm": "1", "password": "un-mot-de-passe-long"},
    )

    check("oublier la cle d un autre compte", status == 403, data.get("error", ""))

    # Le tableau de bord du proprietaire presente le formulaire sous sa liste de credentials.
    status, _, body = get("/dashboard", two)

    check("formulaire oublier la cle sous la liste", ("/credentials/%s/delete" % two_credential).encode() in body, str(status))

    status, _, body = get("/admin", admin)
    admin_csrf = csrf_of(body)

    check("administration encore ouverte avant la suppression", status == 200 and b"Administration" in body, str(status))

    # L administrateur peut supprimer ce qu il peut lire : la cle d un autre compte.
    status, data = post_json(
        "/credentials/%s/delete" % two_credential,
        {"csrf": admin_csrf, "confirm": "1", "password": "un-mot-de-passe-long"},
        admin,
    )

    check("administrateur supprime la cle d un autre compte", status == 200 and data.get("deleted") is True, json.dumps(data))

    # La cle qui confirmait la session de l administrateur : la supprimer retire la confirmation,
    # et la page d administration redemande une assertion.
    status, data = post_json(
        "/credentials/%s/delete" % admin_credential,
        {"csrf": admin_csrf, "confirm": "1", "password": "un-mot-de-passe-long"},
        admin,
    )

    check("cle de l administrateur supprimee", status == 200 and data.get("deleted") is True, json.dumps(data))

    status, headers, _ = get("/admin", admin)

    check("administration fermee apres la suppression de la cle confirmee",
          status == 302 and "/admin/yubikey" in headers.get("location", ""),
          headers.get("location", ""))

    # Le compte sans credential revient au seul mot de passe : il n est plus mis en attente.
    status, _, body = get("/dashboard", two)
    two_csrf = csrf_of(body)

    status, headers, _ = post("/logout", {"csrf": two_csrf}, two)
    status, headers, _ = post(
        "/login",
        {"csrf": csrf_of(get("/login", two)[2]), "email": "2fa@qlip.test", "password": "un-mot-de-passe-long"},
        two,
    )

    check("cle supprimee : le mot de passe suffit de nouveau",
          status == 302 and "/login/2fa" not in headers.get("location", ""),
          headers.get("location", ""))

    # 10e. Suppression definitive par l administration : compte et cle 2FA d un autre compte.
    # La cle de l administrateur a ete supprimee en 10d, donc la session doit etre confirmee par
    # une assertion nouvelle pour que les routes d administration s ouvrent.
    status, _, body = get("/admin/yubikey", admin)
    admin_csrf = csrf_of(body)
    status, ceremony = get_json("/webauthn/challenge", admin)
    status, data = post_json("/webauthn/register", dict(csrf=admin_csrf, **yubikey.register(b64url_decode(ceremony["challenge"]))), admin)

    check("cle enregistree de nouveau pour l administrateur", status == 201, data.get("error", ""))

    status, ceremony = get_json("/webauthn/challenge", admin)
    status, data = post_json("/webauthn/assert", dict(csrf=admin_csrf, **yubikey.assert_(b64url_decode(ceremony["challenge"]), 5)), admin)

    check("session de l administrateur confirmee par une assertion", status == 200 and data.get("ok") is True, data.get("error", ""))

    status, _, body = get("/admin", admin)
    admin_csrf = csrf_of(body)

    # Le compte de test, avec une page et une cle : ce que l administrateur doit pouvoir effacer.
    victim = client(http.cookiejar.CookieJar())
    status, _, body = post("/register", {"csrf": csrf_of(get("/register", victim)[2]), "email": "efface@qlip.test", "password": "un-mot-de-passe-long"}, victim)

    check("compte de test a effacer", get("/verify/" + link_of(body), victim)[0] == 200, str(status))

    victim_csrf = csrf_of(get("/dashboard", victim)[2])
    status, headers, _ = post("/pages", {"csrf": victim_csrf, "title": "Page a effacer", "slug": "aeffacer"}, victim)

    status, ceremony = get_json("/webauthn/challenge", victim)
    status, data = post_json("/webauthn/register", dict(csrf=victim_csrf, **normal.register(b64url_decode(ceremony["challenge"]))), victim)

    check("cle 2FA enregistree pour le compte de test", status == 201, data.get("error", ""))

    status, _, body = get("/admin", admin)
    admin_csrf = csrf_of(body)

    # Les formulaires sont sur la page d administration, pour chaque compte et chaque cle.
    rows = re.findall(rb"<tr>.*?</tr>", body, re.S)
    user_row = [line for line in rows if b"efface@qlip.test" in line and b"/admin/users/" in line]
    key_row = [line for line in rows if b"efface@qlip.test" in line and b"/admin/credentials/" in line]
    victim_id = int(re.search(rb"/admin/users/(\d+)/delete", user_row[0]).group(1)) if user_row else 0
    victim_key = int(re.search(rb"/admin/credentials/(\d+)/delete", key_row[0]).group(1)) if key_row else 0

    check("administration : formulaire pour effacer un compte", bool(user_row), str(status))
    check("administration : formulaire pour effacer la cle 2FA d un compte", bool(key_row), str(status))

    status, _, _ = raw(admin, "GET", "/admin/users/%s/delete" % victim_id)

    check("GET sur la route effacer un compte", status == 405, str(status))

    status, _, _ = raw(admin, "GET", "/admin/credentials/%s/delete" % victim_key)

    check("GET sur la route effacer une cle d administration", status == 405, str(status))

    status, data = post_json(
        "/admin/users/%s/delete" % victim_id,
        {"csrf": "mauvais-jeton", "confirm": "1", "password": "un-mot-de-passe-long"},
        admin,
    )

    check("effacer un compte refuse sans jeton", status == 400, data.get("error", ""))

    status, data = post_json(
        "/admin/users/%s/delete" % victim_id,
        {"csrf": admin_csrf, "confirm": "0", "password": "un-mot-de-passe-long"},
        admin,
    )

    check("effacer un compte refuse sans confirmation", status == 400, data.get("error", ""))

    status, data = post_json("/admin/users/%s/delete" % victim_id, {"csrf": admin_csrf, "confirm": "1"}, admin)

    check("effacer un compte refuse sans mot de passe", status == 401, data.get("error", ""))

    status, data = post_json(
        "/admin/users/%s/delete" % victim_id,
        {"csrf": admin_csrf, "confirm": "1", "password": "pas-le-bon-mot-de-passe"},
        admin,
    )

    check("effacer un compte refuse avec un mauvais mot de passe", status == 401, data.get("error", ""))

    # Le compte de test n est pas administrateur : la route d administration lui est fermee,
    # meme pour ce qui lui appartient et avec son propre mot de passe.
    status, data = post_json(
        "/admin/users/%s/delete" % victim_id,
        {"csrf": victim_csrf, "confirm": "1", "password": "un-mot-de-passe-long"},
        victim,
    )

    check("effacer un compte reserve a l administration", status == 403, data.get("error", ""))

    status, data = post_json(
        "/admin/credentials/%s/delete" % victim_key,
        {"csrf": victim_csrf, "confirm": "1", "password": "un-mot-de-passe-long"},
        victim,
    )

    check("effacer une cle reserve a l administration", status == 403, data.get("error", ""))

    # Le mot de passe demande est celui du compte qui agit : ici l administrateur. La cle effacee
    # n est pas celle qui a confirme la session, donc la confirmation reste.
    status, data = post_json(
        "/admin/credentials/%s/delete" % victim_key,
        {"csrf": admin_csrf, "confirm": "1", "password": "un-mot-de-passe-long"},
        admin,
    )

    check("administration efface la cle 2FA d un compte", status == 200 and data.get("deleted") is True, json.dumps(data))
    check("administration encore ouverte", get("/admin", admin)[0] == 200)

    status, data = post_json(
        "/admin/users/%s/delete" % victim_id,
        {"csrf": admin_csrf, "confirm": "1", "password": "un-mot-de-passe-long"},
        admin,
    )

    check("administration efface un compte", status == 200 and data.get("deleted") is True, json.dumps(data))

    status, _, _ = get("/p/aeffacer", victim)

    check("page du compte emportee par la cascade", status == 404, str(status))
    check("trace de l effacement du compte sur le journal", b"compte efface@qlip.test supprime" in get("/admin", admin)[2])
    check("formulaire disparu avec le compte", b"/admin/users/%d/delete" % victim_id not in get("/admin", admin)[2])

    # 11. Suppression : elle est definitive, donc jeton CSRF + confirmation explicite, et le
    # mot de passe pour le compte. Une autre session (un autre compte) ne peut rien detruire,
    # et un GET ne detruit pas.
    status, headers, _ = post("/pages", {"csrf": csrf, "title": "Page a detruire", "slug": "adetruire"})

    doomed = headers.get("location", "").split("/p/")[-1].strip("/")

    check("page a detruire creee", status == 302 and doomed == "adetruire", "%d %s" % (status, doomed))

    status, data = post_json("/badges", {"csrf": csrf, "slug": doomed})

    check("badge a detruire cree", status == 201, data.get("error", ""))
    doomed_badge = data["badge"]["id"]

    def remaining_of(body):
        match = re.search(rb"Aujourd'hui : (\d+)", body)

        return int(match.group(1)) if match else -1

    remaining = remaining_of(get("/dashboard")[2])

    status, data = post_json("/p/" + doomed + "/delete", {"csrf": "mauvais-jeton", "confirm": "1"})

    check("suppression refusee sans jeton", status == 400, data.get("error", ""))

    status, data = post_json("/p/" + doomed + "/delete", {"csrf": csrf, "confirm": "0"})

    check("suppression refusee sans confirmation", status == 400, data.get("error", ""))

    status, _, _ = raw(session, "GET", "/p/" + doomed + "/delete")

    check("GET sur une route de suppression", status == 405, str(status))

    other = client(http.cookiejar.CookieJar())

    status, _, body = post("/register", {"csrf": csrf_of(get("/register", other)[2]), "email": "autre@qlip.test", "password": "un-mot-de-passe-long"}, other)

    check("inscription autre compte", status == 200)
    check("verification autre compte", get("/verify/" + link_of(body), other)[0] == 200)

    status, _, _ = post("/login", {"csrf": csrf_of(get("/login", other)[2]), "email": "autre@qlip.test", "password": "un-mot-de-passe-long"}, other)

    check("connexion autre compte", status == 302)
    check("page d un autre compte visible", get("/p/" + doomed, other)[0] == 200)

    other_csrf = csrf_of(get("/dashboard", other)[2])

    status, data = post_json("/p/" + doomed + "/delete", {"csrf": other_csrf, "confirm": "1"}, other)

    check("suppression d une page d un autre compte", status == 403, data.get("error", ""))

    status, data = post_json("/badges/%d/delete" % doomed_badge, {"csrf": other_csrf, "confirm": "1"}, other)

    check("suppression d un badge d un autre compte", status == 403, data.get("error", ""))

    # Le proprietaire detruit ce qui est a lui.
    status, data = post_json("/badges/%d/delete" % doomed_badge, {"csrf": csrf, "confirm": "1"})

    check("badge supprime", status == 200 and data.get("deleted") is True, str(data))
    check("badge disparu", get("/badges/%d/stl" % doomed_badge)[0] == 404)

    # Supprimer ne rembourse pas le quota : il porte sur les creations, pas sur les lignes qui
    # restent. Sinon on pourrait creer, supprimer, creer sans limite.
    check("quota non rembourse", remaining > 0 and remaining_of(get("/dashboard")[2]) == remaining, str(remaining))

    status, data = post_json("/p/" + doomed + "/delete", {"csrf": csrf, "confirm": "1"})

    check("page supprimee", status == 200 and data.get("deleted") is True, str(data))
    check("page disparue", get("/p/" + doomed)[0] == 404)
    check("QR de la page disparu", get("/qr/" + doomed + ".png")[0] == 404)

    # Le compte : le mot de passe est demande, parce que rien ne revient. Une page est
    # gardee pour voir la cascade (le compte emporte ses pages et ses badges).
    status, headers, _ = post("/pages", {"csrf": csrf, "title": "Cascade", "slug": "cascade"})

    kept = headers.get("location", "").split("/p/")[-1].strip("/")

    status, data = post_json("/account/delete", {"csrf": csrf, "confirm": "1"})

    check("compte sans mot de passe refuse", status == 401, data.get("error", ""))

    status, data = post_json("/account/delete", {"csrf": csrf, "confirm": "1", "password": "pas-le-bon-mot-de-passe"})

    check("compte avec un mauvais mot de passe refuse", status == 401, data.get("error", ""))

    status, data = post_json("/account/delete", {"csrf": csrf, "confirm": "1", "password": "un-mot-de-passe-long"})

    check("compte supprime", status == 200 and data.get("deleted") is True and data.get("pages") == 1, str(data))
    check("page du compte emportee par la cascade", get("/p/" + kept)[0] == 404)

    status, headers, _ = get("/dashboard")

    check("session videe apres la suppression du compte",
          status == 302 and headers.get("location", "").endswith("/login"), headers.get("location", ""))

    # 12. Reinitialisation de mot de passe, sur un compte separe : la page ne dit pas si
    # l'adresse a un compte, le lien est usage unique, et le verou est compte sur la demande.
    forgotten = client(http.cookiejar.CookieJar())

    status, _, body = post(
        "/register",
        {"csrf": csrf_of(get("/register", forgotten)[2]), "email": "oublie@qlip.test", "password": "un-mot-de-passe-long"},
        forgotten,
    )

    check("inscription oublie", status == 200, str(status))
    check("lien de reinitialisation propose sur la page de connexion", b"Mot de passe oublie" in get("/login", forgotten)[2])

    status, _, body = post("/password", {"csrf": csrf_of(get("/password", forgotten)[2]), "email": "oublie@qlip.test"}, forgotten)

    check("demande de reinitialisation", status == 200 and b"Si cette adresse a un compte" in body, str(status))

    link = reset_link_of(body)
    status, _, body = get("/password/reset/" + link, forgotten)

    check("lien de reinitialisation", link != "" and status == 200 and b'name="password"' in body, link[:16])

    form_csrf = csrf_of(body)

    status, _, body = post("/password/reset/" + link, {"csrf": "mauvais-jeton", "password": "un-mot-de-passe-long"}, forgotten)

    check("CSRF refuse sur la reinitialisation", status == 400, str(status))

    status, _, body = post("/password/reset/" + link, {"csrf": form_csrf, "password": "court"}, forgotten)

    check("mot de passe trop court refuse", status == 200 and b"trop court" in body, str(status))
    check("le lien n est pas consomme par un refus", get("/password/reset/" + link, forgotten)[0] == 200)

    status, headers, _ = post("/password/reset/" + link, {"csrf": form_csrf, "password": "nouveau-mot-de-passe-long"}, forgotten)

    check("mot de passe change", status == 302 and headers.get("location", "").endswith("/dashboard"), headers.get("location", ""))

    # La session a ete regeneree par le changement : le jeton du formulaire ne vaut plus, on
    # relit celui de la page pour rejouer le lien.
    current_csrf = csrf_of(get("/password", forgotten)[2])

    check("lien usage unique", post("/password/reset/" + link, {"csrf": current_csrf, "password": "un-mot-de-passe-long"}, forgotten)[0] == 400)

    status, headers, _ = post("/login", {"csrf": current_csrf, "email": "oublie@qlip.test", "password": "nouveau-mot-de-passe-long"}, forgotten)

    check("connexion avec le nouveau mot de passe", status == 302, headers.get("location", ""))

    # La connexion regenere la session : le jeton est relu apres.
    logged_csrf = csrf_of(get("/password", forgotten)[2])

    check("ancien mot de passe refuse", post("/login", {"csrf": logged_csrf, "email": "oublie@qlip.test", "password": "un-mot-de-passe-long"}, forgotten)[0] == 401)

    status, _, body = post("/password", {"csrf": logged_csrf, "email": "sans-compte@qlip.test"}, forgotten)

    check("reponse identique pour une adresse sans compte",
          status == 200 and b"Si cette adresse a un compte" in body and b"/password/reset/" not in body,
          str(body)[:60])

    # Verou : 5 demandes par heure et par navigateur, sur une session separee.
    throttle = client(http.cookiejar.CookieJar())
    last = 0

    for _ in range(6):
        last = post("/password", {"csrf": csrf_of(get("/password", throttle)[2]), "email": "oublie@qlip.test"}, throttle)[0]

    check("verou de reinitialisation apres 5 demandes", last == 429, str(last))


def main():
    server = start_server()

    try:
        run()
    finally:
        server.terminate()
        server.wait()

    print("----")
    print("problemes : %d" % failures)

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
