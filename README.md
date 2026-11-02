# Qlip

Application PHP (SQLite) pour des QR codes pointant vers des mini pages de contact
(objet trouve, affichage perdu, contact utile), avec des badges porte-cles imprimables
en STL, et une page d'administration protegee par une YubiKey.

Aucune dependance : pas de Composer, pas de GD. L'encodeur QR, l'encodeur PNG, le
lecteur CBOR, le verificateur WebAuthn et le petit ecrivain ZIP du .3MF sont
ecrits dans le projet.

## Lancer

Qlip ne tourne pas en standalone : il est servi par Apache ou nginx avec PHP-FPM, le
document root etant `public/`, et tout ce qui n'est pas un fichier ou un dossier reel est
envoye a `public/index.php` (le front controller). Exemples de conf : `deploy/apache.conf`
et `deploy/nginx.conf`.

Le serveur PHP integre est un outil de developpement local (c'est aussi celui qu'utilise
`tests/http_test.py`) :

```bash
php -S 127.0.0.1:8080 public/router.php     # ou : QLIP_PORT=8080 ./bin/serve.sh
```

Dans un conteneur (podman, rootless) :

```bash
podman run --rm -p 8080:8080 -v "$PWD:/app:ro" -w /app php:8.5-cli-alpine \
    php -S 0.0.0.0:8080 public/router.php
```

Configuration par variables d'environnement :

| Variable | Defaut | Role |
| --- | --- | --- |
| `QLIP_DB` | `data/qlip.sqlite` | base SQLite |
| `QLIP_VAR` | `var` | outbox, journaux |
| `QLIP_BASE_URL` | `http://localhost:8080` | URL de l'app (sert au contenu du QR) |
| `QLIP_RP_ID` | hote de `QLIP_BASE_URL` | relying party WebAuthn |
| `QLIP_ORIGINS` | `QLIP_BASE_URL` | origines acceptees (liste separee par des virgules) |
| `QLIP_ADMIN_EMAILS` | vide | comptes administrateurs (liste separee par des virgules) |
| `QLIP_DAILY_LIMIT` | `10` | creations de badge par jour et par compte |
| `QLIP_MAIL_TRANSPORT` | `outbox` | `outbox`, `log` ou `null` |
| `QLIP_SECURE_COOKIES` | `0` | cookie `Secure` (mettre a 1 derriere HTTPS) |
| `QLIP_ATTESTATION_ROOTS` | vide | PEM de racines pour verifier une attestation `packed` |

## Deploiement (Apache ou nginx + PHP-FPM)

Les exemples utilisent `qlip.example.com` (nom reserve a la documentation) et
`/var/www/qlip` : a remplacer par votre nom de domaine et votre chemin reel.

Le document root doit etre `public/` : `src/`, `templates/`, `data/` et `var/` restent hors
du document root, et `data/` et `var/` doivent etre accessibles en ecriture par le processeur
PHP. Le front controller est `public/index.php` : la reecriture doit envoyer tout ce qui
n'est pas un fichier ou un dossier reel vers ce fichier, sinon `/login`, `/register` et
`/p/{slug}` renvoient le 404 du serveur. Les assets (`public/assets/`) sont servis par le
serveur web, pas par l'app : si le dossier n'est pas sur le serveur, la requete part au
front controller et l'app repond 404.

Apache : `SetEnv` dans le vhost ou dans `public/.htaccess`. `PutEnv` n'est plus une directive
Apache avec PHP 8 (et ne fonctionne pas avec PHP-FPM), et `RewriteBase` n'est pas permise
dans un `<VirtualHost>` : la regle s'ecrit sans `RewriteBase`. nginx : pas de `SetEnv`, les
variables se mettent dans le pool PHP-FPM (`clear_env = no`, `env[QLIP_BASE_URL] = ...`) ou
en `fastcgi_param`.

L'app lit les variables dans `getenv()`, `$_SERVER` et `$_ENV`, y compris avec le prefixe
`REDIRECT_` quand la requete est reecrite : c'est ce qui fait fonctionner `SetEnv` sous
Apache et `fastcgi_param` sous nginx.

`QLIP_BASE_URL` doit etre l'URL publique : elle sert au contenu des QR et a l'origine
acceptee par WebAuthn, et `QLIP_SECURE_COOKIES=1` se met derriere HTTPS. WebAuthn exige un
contexte sur : HTTPS, ou `localhost`.

## Emails

Pas de SMTP ici : le transport ecrit un message `.eml` dans `var/outbox` et journalise
chaque envoi dans `var/log/mail.log`. Le lien de verification est donc visible dans la
page d'inscription et dans les fichiers de l'outbox. `QLIP_MAIL_TRANSPORT=log` ne fait
que journaliser, `null` ne fait rien.

L'inscription exige un email verifie : sans verification, le tableau de bord et la
creation de badge renvoient 403. Les tentatives de connexion sont limitees a 8 par
session, puis verrou de 300 secondes.

## Champs des pages

Les champs d une page sont controles cote serveur, pas seulement dans le formulaire :
titre 80 octets, telephone 40, email de contact 120 (et doit rester une adresse valide),
note 500. Le `maxlength` d un formulaire n est pas une protection : on peut poster
directement. Les valeurs sont stockees telles quelles et echappees au rendu par `e()`
(`attr()` dans les attributs HTML). Le slug est limite a 32 caracteres et reduit a
`[a-z0-9]`. Les parametres de badge (dimensions, couleurs) sont bornes dans `Design`.

Les requetes SQL sont toutes en prepare, avec params nommes. Les en-tetes de securite sont
poses sur toutes les reponses, y compris le JSON.

## YubiKey (WebAuthn)

Le navigateur fait la ceremony (`navigator.credentials`), PHP verifie :

1. `GET /webauthn/challenge` : challenge stocke en session + params pour l'appel.
2. `POST /webauthn/register` : decode le CBOR de l'attestation, controle les drapeaux
   AT/UP, l'origine, le hash du RP ID, la signature si le format est `packed`
   (auto-attestation acceptee). La cle COSE est stockee en base.
3. `POST /webauthn/assert` : drapeau UP, origine, hash du RP ID, challenge, compteur
   strictement croissant, signature EC2/P-256 (`ES256`) ou RSA (`RS256`). La conversion
   raw `r||s` en DER est faite a la main.

La page `/admin` n'est accessible qu'avec une session confirmee par une assertion
(`webauthn_at` en session), sur un compte marque administrateur.

### Enrolement initial de la YubiKey

L'enrolement se fait une fois par cle et par origine, dans un navigateur : c'est le
navigateur qui parle au token, PHP ne fait que verifier la reponse. La ceremony
`navigator.credentials` est impossible en ligne de commande.

1. Le compte administrateur est celui dont l'adresse est dans `QLIP_ADMIN_EMAILS` : il
   est cree marque `is_admin = 1` a l'inscription, et l'email doit etre verifie pour que
   la connexion soit acceptee (avec le transport `outbox`, le lien est dans
   `var/outbox/*.eml`).

   ```bash
   QLIP_ADMIN_EMAILS=admin@qlip.example.com QLIP_BASE_URL=https://qlip.example.com \
       QLIP_SECURE_COOKIES=1 php -S 127.0.0.1:8080 public/router.php
   ```

2. Le navigateur doit etre dans un contexte sur : HTTPS, ou `http://localhost`. Une page
   servie par une IP ou par un nom d'hote different fait echouer la ceremony. Le RP ID
   doit correspondre a l'origine : par defaut l'hote de `QLIP_BASE_URL` (`QLIP_RP_ID`),
   liste acceptee dans `QLIP_ORIGINS`. Une cle enregistree sur `localhost` n'est pas
   recevable sur un nom de domaine, il faut l'enregistrer a nouveau.

3. Connecte-toi avec le mot de passe (`/login`), puis ouvre `/admin/yubikey` : le bouton
   `Enregistrer la YubiKey` est affiche pour un compte administrateur. Touche le token
   (ou donne son PIN) quand le navigateur le demande : `userVerification` est `required`
   et `residentKey` est `preferred`, donc la cle est decouvrable et pourra retrouver le
   compte sans mot de passe ensuite.

4. La ceremony `navigator.credentials.create()` envoie l'attestation en base64url a
   `POST /webauthn/register` : les formats acceptes sont `none` et `packed` (une
   reponse `fido-u2f`, `android-key` ou `apple` est refusee). La cle COSE, l'ID de
   credential et le compteur sont stockes en base. Avec `QLIP_ATTESTATION_ROOTS` (PEM
   d'une racine, par exemple le certificat racine de Yubico), la chaine `x5c` est remontee
   jusqu'a cette racine ; sans elle, `packed` avec `x5c` est accepte comme une declaration.

5. Puis `Presente la YubiKey` (`POST /webauthn/assert`) : la session est marquee
   `webauthn_at`, et `/admin` devient accessible. Tant que cette marque n'existe pas,
   `/admin` renvoie a la page d'enrolement. Le compteur de signature doit etre
   strictement croissant, et la meme assertion n'est pas acceptee deux fois.

En resume : inscription avec l'adresse d'administration, verification d'email, connexion
par mot de passe, enregistrement de la cle, assertion, `/admin`. Le mot de passe ne sert
plus ensuite qu'a enregistrer une autre cle (bouton `Enregistrer une YubiKey` sur la page
d'administration) ou a reprendre la main si la cle est perdue.

## Badges STL

Un badge est une plaque arrondie avec un trou, et le QR code en relief ou grave.

- Monochrome : un seul solide, `engraved` (grave) ou `raised` (relief).
- Bicolore : deux volumes (base + encre), chacun etanche, ecrits dans un .3MF avec les
  groupes de couleurs pour les slicers multicolores.
- Sorties : STL ASCII (`/badges/{id}/stl`) et .3MF (`/badges/{id}/3mf`).

Les maillages sont verifies : chaque arete doit apparaitre dans les deux sens. Les aretes
utilisees deux fois dans le meme sens sont des contacts (faces confondues des volumes
bicolores), signalees mais pas considerees comme des erreurs.

## Tests

```bash
./tests/run.sh                      # toute la suite (podman)
./tests/run.sh tests/stl_dump.php   # un seul script PHP
```

La suite lance, dans le conteneur `localhost/qlip-test` :

1. lint de tous les fichiers PHP ;
2. `tests/qr_compare.py` : contre-verification de l'encodeur QR avec la reference Python
   `qrcode` + decodage `zxing-cpp` (36 cas structures) ;
3. `tests/qr_fuzz.py` : 220 textes aleatoires, memes matrices et memes decodages ;
4. `tests/stl_dump.php` + `tests/stl_check.py` : cinq scenarios de badge, validation
   independante des solides et des .3MF ;
5. `tests/webauthn_fixture.py` + `tests/webauthn_check.php` : faux authenticateur
   (EC2/P-256 et RSA), rejoue, mauvaise origine, mauvaise signature, challenge different ;
6. `tests/app_test.php` : routes sans HTTP (schema, inscription, verification, threpse,
   pages, quotas par compte, exports etancheite, CSRF, ceremony WebAuthn par la route) ;
7. `tests/http_test.py` : front controller relance sur un serveur PHP integre, y compris
   le PNG decode par zxing et la ceremony WebAuthn par HTTP.

Les tests ecrivent dans `QLIP_OUT` (defaut `/tmp/opencode/badge`) et utilisent
`QLIP_PHP` pour le binaire PHP, `QLIP_IMAGE` pour l'image podman.

## Tests en conditions reelles

```bash
./tests/live.sh                                                     # lecture seule
QLIP_LIVE_EMAIL=... QLIP_LIVE_PASSWORD=... QLIP_LIVE_WRITE=1 ./tests/live.sh
```

`tests/live_test.py` ne remplace pas la suite : il verifie ce qu'elle ne peut pas
verifier parce que cela depend du serveur web (reecriture, variables d'environnement
arrivees a PHP, cookie `Secure`, assets servis par le serveur et non par l'app,
dossiers hors document root, origine WebAuthn, HSTS). Le test s'appuie sur un signe
distinctif : une reponse de l'app est en utf-8 avec la CSP, une reponse du serveur web
n'a pas de CSP. Chaque ligne indique laquelle des deux a repondu, et la sonde
`/.htaccess` (fichier reel du depot, a la racine du document root) permet de savoir si
le dossier servi est bien le depot : si l'app repond en 404, le fichier manque.

La partie authentifiee est facultative et ecrite en base (l'app n'a pas de suppression,
le slug cree est `livecheck`) : a lancer avec un compte de test, pas avec le compte
administrateur.

## Organisation

```
public/          front controller + serveur de developpement + assets
src/App/         Config, Router, Request, Response, Session, View, conteneur
src/Controllers/ Auth, Pages, Badges, Admin
src/Services/    Users, Badges, Credentials, Mailer
src/Qr/          encodeur QR (Reed-Solomon, masks, penalites) + rendus SVG/PNG/ASCII
src/Stl/         Mesh, Triangulator, Design, Badge, StlWriter, ThreeMfWriter
src/Webauthn/    Cbor, AuthenticatorData, CoseKey, Signature, Verifier
src/Support/     helpers (base64url, echappement, jetons)
templates/       vues HTML
tests/           suite de tests (PHP + Python)
```

Le routeur est extrait dans `src/routes.php` (`qlip_routes(App $app)`) pour que les tests
puissent dispatcher sans serveur HTTP.

## Licence

MIT, voir `LICENSE`.
