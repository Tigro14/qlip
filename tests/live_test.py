#!/usr/bin/env python3
"""Protocol de tests en conditions reelles sur l'instance deployee.

La suite hors ligne (tests/http_test.py) verifie l'application. Ce script verifie ce
qu'elle ne peut pas verifier, parce que cela depend du serveur web : la reecriture, les
variables d'environnement qui arrivent a PHP, le cookie Secure, les assets servis par le
serveur (et non par l'app), les dossiers hors document root, l'origine WebAuthn.

Read-only par defaut : rien n'est ecrit en base. La partie authentifiee (compte de test,
page, QR, STL, 3MF, suppression, threpse) ne se lance qu'avec QLIP_LIVE_WRITE=1. Ce qui est
cree est supprime a la fin du test, donc la partie ecriture est repeitable. Le compte de test
n'est pas supprime : le test ne lit pas la poste, donc il ne peut pas se reinscrire.

Un KO n'est pas toujours un bug de l'app : c'est souvent la conf du serveur qu'il faut
corriger (document root, SetEnv, asset manquant, HSTS absent).

Usage :
  QLIP_LIVE_URL=https://qlip.tigro.fr python3 tests/live_test.py
  QLIP_LIVE_TRANSPORT=local python3 tests/live_test.py
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
TRANSPORT = os.environ.get("QLIP_LIVE_TRANSPORT", "outbox")
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


def json_of(body):
    try:
        return json.loads(body.decode(errors="replace"))
    except ValueError:
        return {}


def post_json(path, payload, opener=None):
    status, _, body = raw(opener or session, "POST", path, payload)

    return status, json_of(body)


def get_json(path, opener=None):
    status, _, body = raw(opener or session, "GET", path)

    return status, json_of(body)


def remaining_of(body):
    """Le quota affiche sur le tableau de bord : il porte sur les creations du jour."""
    match = re.search(rb"Aujourd'hui : (\d+)", body)

    return int(match.group(1)) if match else -1


def file_hash(path):
    with open(path, "rb") as handle:
        return hashlib.sha256(handle.read()).hexdigest()


def answered_by_app(headers):
    """Une reponse de l'app porte la CSP ; une reponse produite par le serveur web ne l'a pas."""
    return "content-security-policy" in headers


def origin(headers):
    return "reponse de l'app" if answered_by_app(headers) else "reponse du serveur"


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

    # L autre cote du drapeau : un cookie Secure n est pas envoye sur http. Sur une instance
    # servie en http, le drapeau pose par QLIP_SECURE_COOKIES est une erreur de conf, et le
    # navigateur refuse le cookie : la session n est jamais reprise, donc tout POST est refuse.
    # Le protocole ne peut pas le mesurer avec son propre bocal, parce que Python envoie un
    # cookie Secure sur http contrairement a un navigateur : ce que le check voit est le
    # drapeau sur la reponse.

    check("cookie Secure sur une instance en http", not BASE.startswith("http://") or "secure" not in cookie.lower(), cookie)

    # 3. Assets : ils sont servis par le serveur web. Un 404 de l'app veut dire que le
    # fichier n'est pas trouve par le serveur : il manque dans le document root (ou le
    # document root n'est pas le depot).
    for name, expected in (("app.css", "text/css"), ("app.js", "javascript"), ("theme.js", "javascript"), ("tabs.js", "javascript"), ("img/key.png", "image/png")):
        status, headers, body = get("/assets/" + name)
        local = os.path.join(ROOT, "public/assets", name)
        ok = status == 200 and expected in headers.get("content-type", "")

        if ok and os.path.isfile(local):
            ok = hashlib.sha256(body).hexdigest() == file_hash(local)

        check("asset %s" % name, ok, "%d %s -> %s" % (status, headers.get("content-type", ""), origin(headers)))

    # Le fichier est servi, encore faut-il que la page le charge : la ceremonie WebAuthn est
    # dans app.js, et le CSP autorise un fichier du site (script-src 'self'), pas une balise
    # inline. Ce qu une extension injecte dans la page est refuse par le CSP : c est le
    # comportement attendu, pas un bug de l app.
    status, headers, body = get("/login", anonymous)

    check("page charge app.js", status == 200 and answered_by_app(headers) and b"assets/app.js" in body, "%d -> %s" % (status, origin(headers)))

    # L illustration du porte-cle est un asset du depot, servi par le serveur web comme la
    # feuille et le script : la page d accueil doit la charger, et le fichier doit etre reel
    # dans le document root. Une reponse de l app sur ce chemin veut dire que le fichier est
    # absent du serveur, pas qu il est refuse.

    status, headers, body = get("/", anonymous)

    check("page d accueil charge l illustration", status == 200 and answered_by_app(headers) and b"assets/img/key.png" in body, "%d -> %s" % (status, origin(headers)))

    # 3b. Sonde : .htaccess est un fichier reel du depot, a la racine du document root.
    # Le serveur doit le refuser par sa propre configuration (403, sans CSP). Une 404 de
    # l'app veut dire que le fichier n'est pas trouve : le document root n'est pas le
    # depot, et c'est la meme cause que les assets manquants.
    status, headers, body = get("/.htaccess")

    check("sonde document root", status in (403, 404) and b"RewriteEngine" not in body, "%d -> %s" % (status, origin(headers)))

    # 4. Ce qui est hors document root ne doit pas etre servi, et jamais du code source.
    for path in ("/src/Db.php", "/templates/layout.php", "/data/qlip.sqlite", "/var/log/mail.log"):
        status, headers, body = get(path)

        check("%s refuse" % path, status in (403, 404) and b"<?php" not in body, "%d %s -> %s" % (status, origin(headers), str(len(body))))

    # 5. Comportement applicatif : reecriture, CSRF, acces anonime, methodes.
    status, headers, _ = get("/une-page-qui-n-existe-pas")

    check("404 produit par l'app", status == 404 and answered_by_app(headers), "%d -> %s" % (status, origin(headers)))

    status, headers, _ = get("/password", anonymous)

    check("page de reinitialisation servie par l'app", status == 200 and answered_by_app(headers), "%d -> %s" % (status, origin(headers)))

    status, headers, _ = get("/dashboard", anonymous)

    check("anonime renvoie a la connexion", status == 302 and headers.get("location", "").endswith("/login"), headers.get("location", ""))

    # Le jeton CSRF vient de la session, donc deux requetes avec le meme cookie doivent
    # donner le meme jeton. Un jeton qui change veut dire que la session n est pas reprise :
    # le cookie est refuse par le navigateur (Secure sur une instance servie en http, ou
    # derriere un proxy qui termine TLS et que PHP voit en http), ou le chemin de sauvegarde
    # n est pas accessible en ecriture au pool. Dans les deux cas tout POST est refuse avec le
    # meme message. La suite ne peut pas le voir : en CLI la session est en memoire, et dans
    # le conteneur le chemin de sauvegarde est accessible.
    first = csrf_of(get("/login", anonymous)[2])
    second = csrf_of(get("/login", anonymous)[2])

    check("session reprise entre deux requetes", first != "" and first == second, "%s vs %s" % (first[:12], second[:12]))

    status, _, body = post("/pages", {"title": "Controle"}, anonymous)

    # Le controle d'identite passe avant celui du jeton : un anonyme est renvoye a la
    # connexion. Le jeton lui-meme est verifie dans la suite hors ligne (tests/http_test.py),
    # et ci-dessous sur une session authentifiee.
    check("POST anonyme refuse", status == 401, text(body)[:60])

    status, _, _ = raw(anonymous, "POST", "/health")

    check("methode refusee", status == 405, str(status))

    # Le transport affiche sur la page d inscription est celui que PHP lit vraiment :
    # c'est la seule facon de savoir si SetEnv arrive jusqu'a l'app sans ecrire en base.
    status, _, body = get("/register", anonymous)
    shown = re.search(rb"transport est\s*<code>([^<]+)</code>", body)

    check("transport email lu par PHP", status == 200 and shown is not None and shown.group(1).decode() == TRANSPORT, shown.group(1).decode() if shown else text(body)[:60])

    if not WRITE:
        print("\npartie authentifiee non lancee : QLIP_LIVE_WRITE=1 avec un compte de test")
        return

    if EMAIL == "" or PASSWORD == "":
        check("compte de test", False, "QLIP_LIVE_EMAIL et QLIP_LIVE_PASSWORD sont requis")
        return

    # 6. Parcours complet, avec un compte de test. Ce qui est cree est supprime a la fin,
    # donc la partie ecriture est repeitable. Le slug est fixe pour qu'on le retrouve dans
    # l'administration si une etape echoue avant la fin.
    # Le mot de passe seul ne suffit pas : POST /login exige le jeton CSRF de la page.
    status, headers, body = post("/login", {"csrf": csrf_of(get("/login", anonymous)[2]), "email": EMAIL, "password": PASSWORD}, anonymous)

    if status != 302:
        check("connexion du compte de test", False, text(body)[:60])
        return

    # Un compte qui a une credential est mis en attente : la ceremonie ouvre seule la session,
    # et cette partie ne peut pas la faire (pas de cle ici). C est pour cela que le protocole
    # d ecriture demande un compte de test sans second facteur, jamais le compte administrateur.
    if "/login/2fa" in headers.get("location", ""):
        check("compte de test sans second facteur", False, "%s (second facteur demande)" % headers.get("location", ""))
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

    status, _, body = get("/badges/%d/3mf" % badge_id, anonymous)

    if not os.path.isdir(OUT):
        os.makedirs(OUT, exist_ok=True)

    path = os.path.join(OUT, "live_check.3mf")

    with open(path, "wb") as handle:
        handle.write(body)

    triangles = len(re.findall(r"<(?:m:)?triangle ", text(body)))
    problems = stl_check.check_3mf(path, [triangles])

    check("3MF servi et valide", status == 200 and problems == [], problems[0] if problems else "%d triangles" % triangles)

    remaining = remaining_of(get("/dashboard", anonymous)[2])

    # 7. Suppression, par le serveur web : jeton CSRF, confirmation explicite, POST seulement.
    status, data = post_json("/badges/%d/delete" % badge_id, {"csrf": "mauvais-jeton", "confirm": "1"}, anonymous)

    check("suppression refusee sans jeton", status == 400, str(data)[:60])

    status, data = post_json("/badges/%d/delete" % badge_id, {"csrf": csrf, "confirm": "0"}, anonymous)

    check("suppression refusee sans confirmation", status == 400, str(data)[:60])

    # GET sur une route de suppression : refusee par le routeur. Pour un badge, le mot
    # delete est lu comme un format par le patron /badges/{id}/{format}, donc la liste
    # fermee (stl, 3mf) repond 404. La route de page, elle, n'est pas concuure : 405.
    status, data = get_json("/badges/%d/delete" % badge_id, anonymous)

    check("GET sur une route de suppression, format refuse", status == 404, str(data)[:60])

    status, _, body = get("/p/" + slug + "/delete", anonymous)

    check("GET sur une route de suppression", status == 405, text(body)[:60])

    status, data = post_json("/badges/%d/delete" % badge_id, {"csrf": csrf, "confirm": "1"}, anonymous)

    check("badge supprime", status == 200 and data.get("deleted") is True, str(data)[:60])
    check("badge disparu", get("/badges/%d/stl" % badge_id, anonymous)[0] == 404)

    # Le quota porte sur les creations, pas sur les lignes qui restent : supprimer ne doit
    # pas le rembourser.
    check("quota non rembourse", remaining >= 0 and remaining_of(get("/dashboard", anonymous)[2]) == remaining, str(remaining))

    status, data = post_json("/p/" + slug + "/delete", {"csrf": csrf, "confirm": "1"}, anonymous)

    check("page supprimee", status == 200 and data.get("deleted") is True, str(data)[:60])
    check("page disparue", get("/p/" + slug, anonymous)[0] == 404)
    check("QR de la page disparu", get("/qr/" + slug + ".png", anonymous)[0] == 404)

    # 8. Threpse, sur une session separee : le verou est par session, pas par compte.
    # Le jeton CSRF est verifie avant le compteur : sans lui chaque tentative repond 400
    # et le verou ne se declenche jamais, ce qui masquerait le controle.
    throttle = client(http.cookiejar.CookieJar())
    throttle_csrf = csrf_of(get("/login", throttle)[2])
    last = 0

    for _ in range(9):
        last = raw(throttle, "POST", "/login", {"csrf": throttle_csrf, "email": EMAIL, "password": "pas-le-bon-mot"})[0]

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
