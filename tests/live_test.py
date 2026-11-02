#!/usr/bin/env python3
"""Protocol de tests en conditions reelles sur l'instance deployee.

La suite hors ligne (tests/http_test.py) verifie l'application. Ce script verifie ce
qu'elle ne peut pas verifier, parce que cela depend du serveur web : la reecriture, les
variables d'environnement qui arrivent a PHP, le cookie Secure, les assets servis par le
serveur (et non par l'app), les dossiers hors document root, l'origine WebAuthn.

Read-only par defaut : rien n'est ecrit en base. La partie authentifiee (compte de test,
page, QR, STL, 3MF, threpse) ne se lance qu'avec QLIP_LIVE_WRITE=1, parce que l'app n'a
pas de suppression : ce qui est cree pendant le test reste en base.

Un KO n'est pas toujours un bug de l'app : c'est souvent la conf du serveur qu'il faut
corriger (document root, SetEnv, asset manquant, HSTS absent).

Usage :
  QLIP_LIVE_URL=https://qlip.tigro.fr python3 tests/live_test.py
  QLIP_LIVE_EMAIL=... QLIP_LIVE_PASSWORD=... QLIP_LIVE_WRITE=1 python3 tests/live_test.py
"""

import hashlib
import http.cookiejar
import io
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import zxingcpp
from PIL import Image

import stl_check

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.environ.get("QLIP_OUT", "/tmp/opencode/badge")
BASE = os.environ.get("QLIP_LIVE_URL", "https://qlip.tigro.fr").rstrip("/")
HOST = urllib.parse.urlparse(BASE).netloc
EMAIL = os.environ.get("QLIP_LIVE_EMAIL", "")
PASSWORD = os.environ.get("QLIP_LIVE_PASSWORD", "")
WRITE = os.environ.get("QLIP_LIVE_WRITE", "0") == "1"
TIMEOUT = 20
AGENT = "qlip-live-check"

failures = 0


def check(label, ok, detail=""):
    global failures

    if not ok:
        failures += 1

    print("  %s   %s%s" % ("OK" if ok else "KO", label, (" : " + detail) if detail else ""))


def text(body):
    return body.decode(errors="replace")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Les redirections sont observees, pas suivies."""

    def redirect_request(self, req, fp, code, headers, url, info):
        return None


def client(jar):
    return urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(jar))


def lower(headers):
    return {str(key).lower(): value for key, value in dict(headers).items()}


session = client(http.cookiejar.CookieJar())
anonymous = client(http.cookiejar.CookieJar())


def raw(opener, method, path, payload=None, base=None):
    headers = {"Accept": "application/json", "User-Agent": AGENT}
    body = None

    if payload is not None:
        body = json.dumps(payload).encode()
        headers["Content-Type"] = "application/json"

    request = urllib.request.Request((base or BASE) + path, data=body, headers=headers, method=method)

    try:
        response = opener.open(request, timeout=TIMEOUT)

        return response.status, lower(response.headers), response.read()
    except urllib.error.HTTPError as error:
        return error.code, lower(error.headers), error.read()


def get(path, opener=None):
    return raw(opener or session, "GET", path)


def post(path, payload, opener=None):
    return raw(opener or session, "POST", path, payload)


def csrf_of(body):
    match = re.search(rb'name="csrf" value="([^"]+)"', body) or re.search(rb'data-csrf="([^"]+)"', body)

    return match.group(1).decode() if match else ""


def file_hash(path):
    with open(path, "rb") as handle:
        return hashlib.sha256(handle.read()).hexdigest()


def run():
    print("instance : %s" % BASE)

    # 1. Transport et front controller. Une reponse du serveur web n'a pas de CSP : sa
    # presence prouve que la requete est bien arrivee a l'app.
    if BASE.startswith("https://"):
        status, headers, _ = raw(client(http.cookiejar.CookieJar()), "GET", "/", base="http://" + HOST)

        check("http renvoie sur https", status in (301, 308), "%d %s" % (status, headers.get("location", "")))

    status, headers, body = get("/health")

    check("sante en JSON", status == 200 and headers.get("content-type", "").startswith("application/json"), headers.get("content-type", ""))
    check("CSP sur une reponse JSON", "content-security-policy" in headers, headers.get("content-security-policy", "")[:48])
    check("nosniff sur une reponse JSON", headers.get("x-content-type-options") == "nosniff")

    hsts = headers.get("strict-transport-security", "")

    check("HSTS pose", "max-age=" in hsts, hsts or "absent")

    # 2. Variables d'environnement : sans SetEnv (ou fastcgi_param), l'app part de
    # http://localhost:8080 et les liens sortent en chemin relatif.
    status, headers, body = get("/login")
    links = re.findall(rb'href="([^"]+)"', body)

    check("liens en base absolue", status == 200 and links and links[0].decode().startswith(BASE), text(links[0]) if links else "")

    status, _, body = get("/webauthn/challenge")
    ceremony = json.loads(text(body)) if body.startswith(b"{") else {}

    check("rpId = hote de l'instance", ceremony.get("rpId") == HOST, str(ceremony.get("rpId", "")))

    cookie = headers.get("set-cookie", "")

    check(
        "cookie de session",
        "qlip_session" in cookie and "httponly" in cookie.lower() and "samesite=strict" in cookie.lower() and (not BASE.startswith("https") or "secure" in cookie.lower()),
        cookie,
    )

    # 3. Assets : servis par le serveur web. Un 404 de l'app (utf-8 + CSP) veut dire que
    # le dossier n'est pas sur le serveur.
    for name, expected in (("app.css", "text/css"), ("app.js", "javascript")):
        status, headers, body = get("/assets/" + name)
        local = os.path.join(ROOT, "public/assets", name)
        ok = status == 200 and expected in headers.get("content-type", "")

        if ok and os.path.isfile(local):
            ok = hashlib.sha256(body).hexdigest() == file_hash(local)

        check("asset %s" % name, ok, "%d %s" % (status, headers.get("content-type", "")))

    # 4. Ce qui est hors document root ne doit pas etre servi, et jamais du code source.
    for path in ("/src/Db.php", "/templates/layout.php", "/data/qlip.sqlite", "/var/log/mail.log"):
        status, headers, body = get(path)

        check("%s refuse" % path, status in (403, 404) and b"<?php" not in body, "%d %s (%d octets)" % (status, headers.get("content-type", ""), len(body)))

    # 5. Comportement applicatif : reecriture, CSRF, acces anonime, methodes.
    status, headers, _ = get("/une-page-qui-n-existe-pas")

    check("404 produit par l'app", status == 404 and "charset=utf-8" in headers.get("content-type", "") and "content-security-policy" in headers, headers.get("content-type", ""))

    status, headers, _ = get("/dashboard", anonymous)

    check("anonime renvoie a la connexion", status == 302 and headers.get("location", "").endswith("/login"), headers.get("location", ""))

    status, _, body = post("/pages", {"title": "Controle"}, anonymous)

    # Le controle d'identite passe avant celui du jeton : un anonyme est renvoye a la
    # connexion. Le jeton lui-meme est verifie dans la suite hors ligne (tests/http_test.py),
    # et ci-dessous sur une session authentifiee.
    check("POST anonyme refuse", status == 401, text(body)[:60])

    status, _, _ = raw(anonymous, "POST", "/health")

    check("methode refusee", status == 405, str(status))

    if not WRITE:
        print("\npartie authentifiee non lancee : QLIP_LIVE_WRITE=1 avec un compte de test")
        return

    if EMAIL == "" or PASSWORD == "":
        check("compte de test", False, "QLIP_LIVE_EMAIL et QLIP_LIVE_PASSWORD sont requis")
        return

    # 6. Parcours complet, avec un compte de test. Ce qui est cree n'est pas supprime
    # (l'app n'a pas de suppression) : le slug est fixe pour qu'on le retrouve.
    status, headers, body = post("/login", {"email": EMAIL, "password": PASSWORD}, anonymous)

    if status != 302:
        check("connexion du compte de test", False, text(body)[:60])
        return

    csrf = csrf_of(get("/dashboard", anonymous)[2])

    check("connexion du compte de test", status == 302 and csrf != "", headers.get("location", ""))

    status, _, body = post("/pages", {"title": "Jeton"}, anonymous)

    check("jeton CSRF refuse", status == 400, text(body)[:60])

    slug = "livecheck"
    status, headers, _ = post("/pages", {"csrf": csrf, "title": "Controle en conditions reelles", "slug": slug}, anonymous)

    check("creation de page", status == 302 and headers.get("location", "").endswith("/p/" + slug), headers.get("location", ""))

    status, _, body = get("/qr/" + slug + ".png", anonymous)
    codes = zxingcpp.read_barcodes(Image.open(io.BytesIO(body)))

    check("QR decode", len(codes) == 1 and codes[0].text == BASE + "/p/" + slug, codes[0].text if codes else "rien")

    status, _, body = post("/badges", {"csrf": csrf, "slug": slug, "style": "engraved"}, anonymous)
    data = json.loads(text(body)) if body.startswith(b"{") else {}
    badge_id = (data.get("badge") or {}).get("id")

    check("badge cree", status == 201 and badge_id is not None, str(data))

    if badge_id is None:
        return

    status, _, body = get("/badges/%d/stl" % badge_id, anonymous)
    solids = stl_check.parse_ascii_stl(text(body))
    problems, _, volume = stl_check.check_solid(solids[0]) if solids else ["pas de solid"]

    check("STL servi et etanche", status == 200 and solids and not problems and volume > 0.0, problems[0] if problems else "%.1f mm3" % volume)

    status, _, body = get("/badges/%d/3mf", anonymous)

    if not os.path.isdir(OUT):
        os.makedirs(OUT, exist_ok=True)

    path = os.path.join(OUT, "live_check.3mf")

    with open(path, "wb") as handle:
        handle.write(body)

    triangles = len(re.findall(r"<(?:m:)?triangle ", text(body)))
    problems = stl_check.check_3mf(path, [triangles])

    check("3MF servi et valide", status == 200 and problems == [], problems[0] if problems else "%d triangles" % triangles)

    # 7. Threpse, sur une session separee : le verou est par session, pas par compte.
    throttle = client(http.cookiejar.CookieJar())
    last = 0

    for _ in range(9):
        last = raw(throttle, "POST", "/login", {"email": EMAIL, "password": "pas-le-bon-mot"})[0]

    check("verou apres 8 tentatives", last == 429, str(last))


def main():
    global failures

    try:
        run()
    except Exception as error:
        print("instance injoignable : %s" % error)
        failures += 1

    print("----")
    print("problemes : %d" % failures)

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
