# AGENTS.md

Consignes pour travailler sur Qlip.

## Ce que le projet est

Application PHP + SQLite locale : mini pages de contact, QR codes, badges porte-cles
imprimables en STL/3MF, page d'administration protegee par YubiKey (WebAuthn), inscription
avec email verifie, reinitialisation de mot de passe par lien envoye par email (usage unique,
24 h), 10 creations de badge par jour et par compte, suppression definitive
(page, badge, compte, cle 2FA) avec confirmation et trace dans `audit_log`.

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
src/Services/    Users, Badges, Credentials, Journal, Mailer
src/Qr/          encodeur QR (Reed-Solomon, masks, penalites) + rendus SVG/PNG/ASCII
src/Stl/         Mesh, Triangulator, Design, Badge, StlWriter, ThreeMfWriter
src/Webauthn/    Cbor, AuthenticatorData, CoseKey, Signature, Policy, Verifier
src/Support/     helpers (base64url, echappement, jetons, pager) + Pager + ZipWriter + client SMTP (TLS)
                 + remise au MTA local (arguments en liste, jamais une chaine de commande)
templates/       vues HTML
tests/           suite de tests (PHP + Python) + Containerfile
deploy/          conf exemple Apache (vhost + PHP-FPM) et nginx
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
- reinitialisation de mot de passe : la reponse est la meme quand l'adresse a un compte ou
  non (sinon le formulaire dresse la liste des comptes), 5 demandes par heure et par session
  comptees sur la demande, lien usage unique et valable 24 h, jetons du compte fermes quand le
  mot de passe change, POST avec jeton CSRF, taille minimale controlee cote serveur ;
- suppression definitive : POST seulement, jeton CSRF, confirmation explicite, mot de passe
  pour le compte, et controle d appartenance (proprietaire ou administrateur). Une ligne
  d audit est ecrite pour chaque suppression ;
- l administration efface un compte et la cle 2FA d un compte (`POST /admin/users/{id}/delete` et
  `POST /admin/credentials/{id}/delete`) : la garde est la meme pour toutes ces suppressions,
  ecrite une fois dans `AdminController::guard()`. Le mot de passe demande est celui du compte qui
  agit, pas celui du compte efface : on ne peut pas le connaitre, et il prouve que la session n est
  pas laissee ouverte. Un compte non administrateur est refuse sur ces routes, meme pour ce qui lui
  appartient ; la trace de la cle effacee pointe sur le proprietaire, pas sur l administrateur ;
 - l effacement du journal (plus de 2 mois, `Journal::RETENTION_MONTHS`) est une suppression
   definitive dans le meme style : POST seulement, jeton CSRF, confirmation, mot de passe du
   compte, session confirmee par une assertion, et trace ecrite apres le DELETE parce que la ligne
   qui consigne l effacement ne peut pas s effacer elle-meme ;
- supprimer la cle 2FA est une suppression definitive : le mot de passe est demande, parce que
  sans lui une session laissee ouverte permettrait d enlever le second facteur et de rendre le
  compte accessible par le seul mot de passe. La session garde la credential qui l a confirmee
  (`webauthn_credential` en session) : supprimer cette cle emporte `webauthn_at`, supprimer une
  autre cle du meme compte ne la change pas ;
- `/admin` exige un compte marque administrateur et une session confirmee par une assertion
  (`webauthn_at` en session) ;
- controles WebAuthn : challenge, origine, hash du RP ID, drapeaux UP/AT, compteur
  strictement croissant, signature EC2/P-256 ou RSA. Un controle ne doit pas etre relache
  pour faire passer un test ;
- la politique de verification utilisateur est choisie par le serveur, par profil de ceremonie
  (`src/Webauthn/Policy.php`), et stockee par credential a l'enregistrement : le navigateur ne
  la choisit pas. Le profil `presence` (second facteur, mot de passe deja accepte) est
  `discouraged`, le profil `passkey` (`/admin`) reste `required` : une cle U2F seule ne peut pas
  le satisfaire, donc elle ouvre la session par mot de passe + possession, pas l'administration
  par une assertion seule. A l'assertion la politique effective est la plus forte des deux, et
  `preferred` continue d'exiger la verification quand la cle l'a deja faite (`uv_seen`) ;
- second facteur : mot de passe d'abord, ceremony ensuite (`POST /login/2fa`). La session en
  attente ne donne aucun acces, la credential doit appartenir au compte en attente, le verrou est
  commun au mot de passe (8 tentatives par session, puis 300 s), et les actions d'audit sont
  distinctes (`login_pending`, `login_2fa`) ;
- le format d'attestation `fido-u2f` (la seule cle U2F seule peut emettre) signe sur
  `0x00 || rpIdHash || clientDataHash || credentialId || publicKeyU2F`, pas sur le message
  WebAuthn : la base fait partie du controle. `x5c` y est obligatoire, la signature y est deja
  en DER ;
 - la base de signature d une assertion est choisie par le format enregistre avec la credential
   (`fmt`), elle n est pas essayee dans les deux sens au moment de verifier : la base U2F (AuthData
   sans hash + hash des donnees client) pour une credential fido-u2f, la base WebAuthn pour les
   autres. Le harnais produit les deux bases et refuse la mauvaise pour chaque credential, dans les
   deux sens ;
- en-tetes de securite poses dans `Response::send()`, sur toutes les reponses (CSP,
  `nosniff`, `frame-ancestors`) ;
- SQL uniquement en prepare, avec params nommes, sans texte d'une requete dans la requete
  (`PDO::ATTR_EMULATE_PREPARES => false`) ;
- valeurs issues d'une requete : echappees au rendu par `e()` (`attr()` dans les attributs
  HTML), jamais reconstruites dans du HTML ; les controles de forme et de taille se font cote
  serveur (`Badges::cleanFields()`, `Design::fromArray()`) : le `maxlength` d'un formulaire
  n'est pas une protection ;
- un en-tete (`Location`, `Content-Disposition`) ne recoit ni retour a la ligne, ni
  guillemet, ni octet de controle. Le meme filtre s applique aux en-tetes d email et aux
  adresses envoyees a SMTP : une adresse avec un retour a la ligne est une injection dans
  la commande ;
- le transport local (`src/Support/Sendmail.php`) ne lance pas de shell : les arguments sont
  une liste, et le message part sur stdin. Une adresse est validee avant, donc elle ne peut
  pas devenir une option (`-f`) ni une commande. Pas de TLS ici : la confiance vient de la
  machine, et il n'y a pas de mot de passe a journaliser ;
- le client SMTP (`src/Support/Smtp.php`, ecrit a la main, pas de PHPMailer) ne doit jamais
  desactiver la verification du certificat, et le mot de passe ne doit apparaitre ni dans le
  transcript ni dans le journal.

## Lancer

En production, l'app est servie par Apache ou nginx avec PHP-FPM : document root =
`public/`, et tout ce qui n'est pas un fichier ou un dossier reel est envoye a
`public/index.php`. Conf exemple dans `deploy/`. Il n'y a pas de serveur autonome :
le serveur PHP integre est reserve au developpement local et aux tests.

```bash
php -S 127.0.0.1:8080 public/router.php     # ou : QLIP_PORT=8080 ./bin/serve.sh
```

Configuration par variables d'environnement, documentees dans le README
(`QLIP_DB`, `QLIP_VAR`, `QLIP_BASE_URL`, `QLIP_RP_ID`, `QLIP_ORIGINS`,
`QLIP_ADMIN_EMAILS`, `QLIP_DAILY_LIMIT`, `QLIP_MAIL_TRANSPORT`, `QLIP_SMTP_*`,
`QLIP_SENDMAIL`, `QLIP_SECURE_COOKIES`, `QLIP_ATTESTATION_ROOTS`). Elles sont lues par
`env()` dans `src/Support/helpers.php` :
`getenv()`, puis `$_SERVER`, puis `$_ENV`, y compris avec le prefixe `REDIRECT_` quand la
requete est reecrite. Ne pas revenir a `getenv()` seul : sous PHP-FPM, `SetEnv` d'Apache
n'arrive que dans `$_SERVER`.

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
une divergence est un bug a comprendre. Le script du navigateur (`public/assets/app.js`)
n'est pas execute par la suite : `tests/http_test.py` le relit pour les noms de methodes de
l'API WebAuthn et les fonctions appelees sans etre definies. Les deux ceremonies nomment le type
de credential par le membre `publicKey` : la forme plate heritee de WebAuthn Level 1 (options au
premier niveau) ne nomme rien et est refusee par Firefox avec `NotSupportedError`, donc un harnais
qui ne regarde que Chrome ne voit pas l'erreur. `tests/ctap_probe.php` n'est pas
lancee par `run.sh` non plus : elle parle a une cle reelle (page d'usage F1D0, canal CTAPHID,
`authenticatorGetInfo`) pour ce que la suite ne peut pas savoir, une cle n'ayant pas de
verification utilisateur dans le faux authenticateur. Elle n'ecrit rien sur la cle.
Le fixture `fido-u2f` de `tests/webauthn_fixture.py` remet un certificat auto-signé
(`cryptography` est dans le conteneur) : la suite ne peut pas connaitre la chaine de la CA
Yubico, donc le certificat est accepte comme une declaration faute de racine. La base de
signature est une mesure, pas une supposition : le cas `bad_base` montre que le verificateur
ne se contente pas de verifier une signature faite sur n'importe quelle base.
`tests/assert_base.py` n'est pas lancee par `run.sh` non plus : elle mesure la base d une
assertion a partir des octets recus d une ceremonie reelle, ce que la suite ne peut pas savoir.
Pour une cle U2F seule, la base de la reponse d authentification est application parameter + octet
de presence utilisateur + counter + challenge parameter, soit AuthData sans hash suivi du hash des
donnees client, et non la base WebAuthn hash(AuthData) + hash(clientDataJSON). La sonde est teste
contre un cas connu (--self-test) fabrique par elle-meme, avec une cle qu elle genere et un AuthData
qu elle ecrit : elle doit nommer la base WebAuthn et refuser les autres. Une sonde qui accepte
toutes les bases ne mesure rien. Mesure faite sur la cle reelle : la base U2F verifie, la base
WebAuthn est refusee, donc le verificateur choisit la base par le format enregistre et ne les essaie
pas dans les deux sens.
L'identifiant de rapport vient du descripteur (item Report ID, balise 8 en type Global) : la
convention `0x20`/`0x21` ne s'applique pas a un device sans reports numerotes, et hidraw ne met
rien au debut des octets lus dans ce cas. La trame recue est imprimee en octets bruts, parce
qu'une reponse sans echo du nonce ne permet pas de distinguer une cle CTAP1 d'une erreur de
tramage. La taille d'une trame d'initialisation est annoncee sur 2 octets (`BCNTH` + `BCNTL`),
payload a l'offset 7, pour CTAPHID comme pour U2FHID : une implementation qui ecrit 3 octets fait
lire une taille de 0 par la cle, qui repond une erreur de longueur. Cette erreur dit ce que le
script a ecrit, pas ce que la cle sait faire, donc la sonde lit la reponse dans les deux formes et
ne retient que celle dont la taille annoncee tient dans la trame. Ce qui distingue CTAP2 de CTAP1
est le drapeau CBOR de la reponse INIT (`0x04`), pas le handshake : les codes d'erreur du transport
sont les memes pour les deux versions, et la balise recue garde le drapeau d'initialisation
(`0xBF` pour une erreur, `0x3F` sans le drapeau). Une requete U2F est une APDU (`CLA INS P1 P2 LC`
puis les donnees), `CLA` etant pose a zero par le client et la longueur annoncee sur 1 octet (APDU
courte) ou 3 (APDU etendue), alors que la description de `CTAPHID_MSG` fait commencer le payload par
le byte de commande. Ce que l'applet attend n'est pas fixe par la spec : sur la YubiKey NEO la forme
avec CLA (5 octets) repond `U2F_V2` puis `9000`, les formes sans CLA repondent `6E 00` (longueur de
donnees incorrecte), donc la sonde essaie les formes au lieu de deviner. La commande `U2F_VERSION`
(0x03) prouve CTAP1 sans rien ecrire sur la cle.

`tests/live.sh` est un protocole sur l'instance deployee, lecture seule par defaut : il
verifie ce que la suite ne peut pas verifier parce que cela depend du serveur web
(reecriture, variables d'environnement, assets, dossiers hors document root, cookie
`Secure`, origine WebAuthn). Une reponse de l'app se reconnait a la CSP : la sonde
`/.htaccess` sert a distinguer un fichier absent du dossier servi d'un fichier refuse
par le serveur. Il ne remplace pas la suite, et n'ecrit en base qu'avec
`QLIP_LIVE_WRITE=1` (avec un compte de test, jamais le compte administrateur) : ce qui est
cree est supprime a la fin, donc la partie ecriture est repeitable. Le transport verifie
est celui lu par PHP, donc lancer avec `QLIP_LIVE_TRANSPORT=local` sur l'instance qui
utilise le MTA local.

## Points connus, ne pas "corriger"

- Le choix du mask QR peut differer de la reference Python (departage de penalite) : le
  harnais force le mask choisi par PHP avant de comparer les matrices.
- Maillages : chaque arete doit apparaitre dans les deux sens. Une arete repetee dans le
  meme sens est un contact (faces confondues des volumes bicolores), signale mais pas
  considere comme une erreur.
- WebAuthn : AuthData = 32 octets de hash du RP ID + drapeaux + compteur, bloc AT a
  l'offset 37 ; labels COSE lus dans les deux numerotations (RFC 9053 : `kty` = 1, `alg` = 3,
  puis courbe = -1, x = -2, y = -3 pour EC2, modulo = -1 et exposant = -2 pour RSA ; et la
  numerotation heritee de RFC 8152, encore emise par Chrome : -1, -2, -3, -4, -5), avec les
  valeurs du registre (`kty` EC2 = 2, RSA = 3, courbe P-256 = 1) ; une cle n'est retenue que
  si elle est coherent avec une seule numerotation, et `tests/webauthn_check.php` compare le
  PEM de PHP au DER produit par la reference Python : comparer PHP a lui meme ne suffit pas ;
  signature brute `r||s` convertie en DER a la main, et la forme DER acceptee telle quelle : une
  cle U2F seule, qui passe par la couche de compatibilite CTAP1, remet le DER y compris pour une
  assertion ; les deux formes sont lues et une forme malformee est refusee (le harnais porte un cas
  `malformed` et la forme normalisee par PHP est comparee a celle de la reference Python) ; tampons
  echanges en base64url. Formats d'attestation acceptes : `none`, `packed`
  (auto-attestation) et `fido-u2f` (`x5c` obligatoire, pas d'auto-attestation, pas d'AAGUID :
  l'identite de l'appareil vient du numero de serie du certificat), la racine est optionnelle.
  Cles du map CBOR d'attestation : `fmt`, `attStmt`, `authData` ; `attAuthData` n'existe pas
  dans la spec, donc un harnais qui l'ecrit ne peut pas voir l'erreur devant un vrai
  navigateur. Handle d'utilisateur : 16 octets minimum (la spec), donc l'id est ecrit en
  big-endian complete par des zeros.
- Schema : `policy`, `uv_seen` et `fmt` sont ajoutees a `webauthn_credentials` par un ALTER garde
  par `PRAGMA table_info`, la base de production n'est jamais reconstruite. Une credential enregistree
  avant `fmt` porte la valeur par defaut, pas un fait : son format n'etait pas stocke, donc la cle
  doit etre enregistree de nouveau ou la ligne reglee a partir de la mesure.
- Une page porte un seul badge : index unique `badges_page_unique`, et `POST /badges` enregistre
  le badge de la page au lieu d en creer un second. La migration garde le badge le plus recent par
  page et ecrit une ligne d audit par ligne retiree, avec un nom distinct (`badge_doublon_supprime`)
  pour ne pas compter dans le quota, parce qu une destruction faite hors des routes doit se
  distinguer d une action de l application.
- Les onglets du tableau de bord ne sont pas un rendu de la CSS : la CSS ne sait pas associer un
  onglet a son panneau quand le nombre est variable. Le template rend la liste complete des
  panneaux, `tabs.js` ajoute les roles et masque ceux qui ne sont pas choisis. Les roles ne sont
  pas dans le template parce qu ils ne sont vrais que quand un seul panneau est montre.
- `session_status()` peut mentir avec un binaire PHP statique : la logique s'appuie sur
  `session_id() !== ''`, et les constantes `PHP_SESSION_*` y ont des valeurs differentes.
- `FILTER_VALIDATE_EMAIL` refuse `admin@test` : les tests utilisent `admin@qlip.test` et
  `normal@qlip.test`.
- Routes de suppression : POST seulement. `GET /badges/{id}/{format}` (telechargement)
  aurait attrape `delete` comme un format, donc la liste des formats est fermee a `stl`
  et `3mf` : un autre nom rend 404.
- Le lien de reinitialisation active un compte encore non verifie : le jeton est envoye a la
  boite et usage unique, donc le lire prouve le controle de la boite, ce qui vaut la
  verification d'email. Sans lui, un compte non active n'aurait plus aucun moyen d'entrer
  (le lien de verification est usage unique lui aussi). Ce n'est pas une faille a corriger.
- Quota : il porte sur les evenements de creation dans `audit_log` (`action = 'badge'`),
  pas sur les lignes qui restent. Une page porte un seul badge, donc enregistrer le design
  d une page deja badgee est un evenement compte comme la premiere creation. Les actions de
  suppression ont des noms distincts (`badge_supprime`, `page_supprimee`, `compte_supprime`,
  `credential_supprimee`) pour ne pas polluer le comptage.
- Trace de suppression du compte : ecrite apres le DELETE, avec l'adresse dans le detail,
  parce que `audit_log.user_id` devient NULL (`ON DELETE SET NULL`).
- `proc_open` : les descripteurs sont lus du cote de l'enfant. Le stdin de l'enfant est
  `0 => ['pipe', 'r']` ; avec `'w'`, le MTA lit un descripteur invalide (errno 9).
- Conf serveur : `PutEnv` n'est pas une directive Apache avec PHP 8, `RewriteBase` n'est pas
  permise dans un `<VirtualHost>`, et la reecriture doit etre dans le meme contexte que le
  `DocumentRoot` : dans le contexte par serveur, `%{REQUEST_FILENAME}` peut n'etre que l'URI,
  donc le test `!-f` ne voit pas les fichiers reels et les assets partent au front controller.
  Les assets de `public/assets/` sont servis par le serveur web (pas par l'app) : s'ils
  manquent sur le serveur, la requete part au front controller et l'app repond 404.
- `tests/qr_compare.py` lit `QLIP_QR_JSON` et `QLIP_WEBAUTHN_JSON` : les regenerer avec
  `tests/webauthn_fixture.py` avant de comparer, un JSON stale provoque de faux ecarts.
- `php` n'est pas toujours sur le PATH : lancer dans le conteneur (`QLIP_IMAGE`) ou avec le
  binaire indique par `QLIP_PHP`. Les tests ecrivent dans `QLIP_OUT` (defaut
  `/tmp/opencode/badge`). Podman rootless est utilise ici, pas docker.

## Commit

Message en francais, sans accents : une ligne de resume, puis le detail quand le pourquoi
n'est pas evident. Ne pas commit de fichiers generes.
