# AGENTS.md

Consignes pour travailler sur Qlip.

## Ce que le projet est

Application PHP + SQLite locale : mini pages de contact, QR codes, badges porte-cles
imprimables en STL/3MF, page d'administration protegee par YubiKey (WebAuthn), inscription
avec email verifie, 10 creations de badge par jour et par compte.

## Contraintes qui ne changent pas

- Pas de Composer, pas de dependance, pas de framework : tout est ecrit dans le projet
  (encodeur QR, encodeur PNG, lecteur CBOR, verificateur WebAuthn, ecrivain ZIP du .3MF).
  Une idee qui demande une librairie doit etre ecrite a la main, ou renoncee.
- Pas de GD, pas d'extension zip : le PNG et le ZIP sont des encodeurs maison.
- PHP 8.5, `declare(strict_types=1)` partout, classes `final`, autoloader PSR-4 simplifie
  dans `src/autoload.php` (`Qlip\Foo\Bar` -> `src/Foo/Bar.php`).
- SQLite local, schema dans `src/Db.php`, requetes preparees avec params nommes.
- Commentaires et README en francais, sans accents. Identifiants en anglais.
- Les fichiers generes (`data/`, `var/`) ne sont pas commites ; les dossiers restent dans
  git avec un `.gitkeep`.
- Licence MIT : ne pas la changer.

## Organisation

```
public/          front controller + serveur de developpement + assets
src/App/         Config, Router, Request, Response, Session, View, conteneur
src/Controllers/ Auth, Pages, Badges, Admin
src/Services/    Users, Badges, Credentials, Mailer
src/Qr/          encodeur QR (Reed-Solomon, masks, penalites) + rendus SVG/PNG/ASCII
src/Stl/         Mesh, Triangulator, Design, Badge, StlWriter, ThreeMfWriter
src/Webauthn/    Cbor, AuthenticatorData, CoseKey, Signature, Verifier
src/Support/     helpers (base64url, echappement, jetons) + ZipWriter
templates/       vues HTML
tests/           suite de tests (PHP + Python) + Containerfile
```

Un nouveau module va dans le dossier qui correspond a son role, avec une classe par
fichier, dans le namespace `Qlip\...`.

## Style

- methodes en `snake_case`, une classe par fichier, controleurs fins : la logique metier
  est dans `src/Services/`, la session et les services sont tenus par le conteneur `App` ;
- le routeur est seulement dans `src/routes.php` (`qlip_routes(App $app)`), les controleurs
  ne le construisent pas ;
- helpers globaux dans `src/Support/helpers.php` (`e()`, `attr()`, `url()`, `json_field()`,
  `base64url_encode()`, `base64url_decode()`, `random_token()`, `now()`, `today()`) : dans
  un namespace, les appeler avec `\` ;
- controleurs qui renvoient `Response` : `Response::html($this->app->view->render(...))`,
  `Response::json()`, `Response::redirect()` ;
- les ceremonies WebAuthn renvoient du JSON, les pages du HTML.

## Securite : ne pas assouplir

- jeton CSRF par session verifie sur tout POST (`Session::checkToken()`) ;
- cookie `HttpOnly` + `SameSite=Strict`, duree 12 h, regeneration a chaque changement
  d'identite ;
- threpse de connexion : 8 tentatives par session, puis verrou de 300 s ;
- email verifie obligatoire : tableau de bord et creation de badge renvoient 403 sinon ;
- `/admin` exige un compte marque administrateur et une session confirmee par une assertion
  (`webauthn_at` en session) ;
- controles WebAuthn : challenge, origine, hash du RP ID, drapeaux UP/AT, compteur
  strictement croissant, signature EC2/P-256 ou RSA. Un controle ne doit pas etre relache
  pour faire passer un test ;
- en-tetes de securite poses dans `Response::send()` (CSP, `nosniff`, `frame-ancestors`).

## Lancer

```bash
php -S 127.0.0.1:8080 public/router.php     # ou : QLIP_PORT=8080 ./bin/serve.sh
```

Configuration par variables d'environnement, documentees dans le README
(`QLIP_DB`, `QLIP_VAR`, `QLIP_BASE_URL`, `QLIP_RP_ID`, `QLIP_ORIGINS`,
`QLIP_ADMIN_EMAILS`, `QLIP_DAILY_LIMIT`, `QLIP_MAIL_TRANSPORT`, `QLIP_SECURE_COOKIES`,
`QLIP_ATTESTATION_ROOTS`).

## Tests

```bash
./tests/run.sh                       # toute la suite dans podman
./tests/run.sh tests/stl_dump.php    # un seul script PHP
```

La suite tourne dans le conteneur `localhost/qlip-test` (image batie depuis
`tests/Containerfile`) : lint, `tests/qr_compare.py`, `tests/qr_fuzz.py`, STL/3MF,
WebAuthn, `tests/app_test.php`, `tests/http_test.py`. Elle doit passer avant de commit,
avec 0 problemes dans chaque section.

Un check est `check(label, ok, detail)` : il imprime OK ou KO, et le script sort a 1 si au
moins un check echoue. Ajouter un check quand on corrige un bug.

Contre-verifications independantes : `tests/qr_compare.py` (reference Python `qrcode` +
decodage `zxing-cpp`), `tests/stl_check.py` (maillages et .3MF valides par un lecteur
tiers). Ne jamais aligner une reference sur l'implementation pour faire passer un test :
une divergence est un bug a comprendre.

## Points connus, ne pas "corriger"

- Le choix du mask QR peut differer de la reference Python (departage de penalite) : le
  harnais force le mask choisi par PHP avant de comparer les matrices.
- Maillages : chaque arete doit apparaitre dans les deux sens. Une arete repetee dans le
  meme sens est un contact (faces confondues des volumes bicolores), signale mais pas
  considere comme une erreur.
- WebAuthn : AuthData = 32 octets de hash du RP ID + drapeaux + compteur, bloc AT a
  l'offset 37 ; labels COSE negatifs ; signature raw `r||s` convertie en DER a la main ;
  tampons echanges en base64url. Formats d'attestation acceptes : `none` et `packed`
  (auto-attestation), la racine est optionnelle.
- `session_status()` peut mentir avec un binaire PHP statique : la logique s'appuie sur
  `session_id() !== ''`, et les constantes `PHP_SESSION_*` y ont des valeurs differentes.
- `FILTER_VALIDATE_EMAIL` refuse `admin@test` : les tests utilisent `admin@qlip.test` et
  `normal@qlip.test`.
- `tests/qr_compare.py` lit `QLIP_QR_JSON` et `QLIP_WEBAUTHN_JSON` : les regenerer avec
  `tests/webauthn_fixture.py` avant de comparer, un JSON stale provoque de faux ecarts.
- `php` n'est pas toujours sur le PATH : lancer dans le conteneur (`QLIP_IMAGE`) ou avec le
  binaire indique par `QLIP_PHP`. Les tests ecrivent dans `QLIP_OUT` (defaut
  `/tmp/opencode/badge`). Podman rootless est utilise ici, pas docker.

## Commit

Message en francais, sans accents : une ligne de resume, puis le detail quand le pourquoi
n'est pas evident. Ne pas commit de fichiers generes.
