# Qlip

Application PHP (SQLite) pour des QR codes pointant vers des mini pages de contact
(objet trouve, affichage perdu, contact utile), avec des badges porte-cles imprimables
en STL, et une page d'administration protegee par une YubiKey.

Aucune dependance : pas de Composer, pas de GD. L'encodeur QR, l'encodeur PNG, le
lecteur CBOR, le verificateur WebAuthn et le petit ecrivain ZIP du .3MF sont
ecrits dans le projet.

## Lancer

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

## Emails

Pas de SMTP ici : le transport ecrit un message `.eml` dans `var/outbox` et journalise
chaque envoi dans `var/log/mail.log`. Le lien de verification est donc visible dans la
page d'inscription et dans les fichiers de l'outbox. `QLIP_MAIL_TRANSPORT=log` ne fait
que journaliser, `null` ne fait rien.

L'inscription exige un email verifie : sans verification, le tableau de bord et la
creation de badge renvoient 403. Les tentatives de connexion sont limitees a 8 par
session, puis verrou de 300 secondes.

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
