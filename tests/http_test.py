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
    """Une YubiKey : cle EC2/P-256, ceremonies preparees pour un challenge donne."""

    def __init__(self, credential_id):
        self.key = ec.generate_private_key(ec.SECP256R1())
        self.cose = wf.cose_ec(self.key)
        self.credential_id = credential_id

    def digest(self, auth_data, client_data):
        return hashlib.sha256(auth_data).digest() + hashlib.sha256(client_data).digest()

    def register(self, challenge, fmt="none"):
        auth_data = wf.auth_data(0x45, 1, self.credential_id, self.cose, True, RP_ID)
        client_data = wf.client_data("webauthn.create", ORIGIN, challenge)

        statement = {}

        if fmt == "packed":
            statement = {"alg": -7, "sig": wf.sign(self.key, self.digest(auth_data, client_data), "ES256")}

        return {
            "credential_id": wf.b64url(self.credential_id),
            "attestation": wf.b64url(wf.cbor({"fmt": fmt, "attAuthData": auth_data, "attStmt": statement})),
            "client_data": wf.b64url(client_data),
        }

    def assert_(self, challenge, sign_count):
        auth_data = wf.auth_data(0x05, sign_count, self.credential_id, self.cose, False, RP_ID)
        client_data = wf.client_data("webauthn.get", ORIGIN, challenge)

        return {
            "credential_id": wf.b64url(self.credential_id),
            "auth_data": wf.b64url(auth_data),
            "client_data": wf.b64url(client_data),
            "signature": wf.b64url(wf.sign(self.key, self.digest(auth_data, client_data), "ES256")),
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

    csrf = csrf_of(body)

    status, headers, _ = get("/admin")

    check("admin sans YubiKey", status == 302, headers.get("location", ""))

    yubikey = FakeAuthenticator(b"claie-de-test")

    status, ceremony = get_json("/webauthn/challenge")

    check("challenge", status == 200 and ceremony.get("rpId") == RP_ID, ceremony.get("challenge", ""))

    status, data = post_json("/webauthn/register", dict(csrf=csrf, **yubikey.register(b64url_decode(ceremony["challenge"]))))

    check("enregistrement none", status == 201, data.get("error") or data.get("credential", {}).get("credential_id", ""))

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
