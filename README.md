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
| `QLIP_MAIL_TRANSPORT` | `outbox` | `outbox`, `log`, `null`, `smtp` ou `local` |
| `QLIP_SMTP_HOST` | vide | serveur SMTP, en TLS implicite |
| `QLIP_SMTP_PORT` | `465` | port SMTP (465 = TLS implicite) |
| `QLIP_SMTP_USER` | vide | identifiant (la boite MAIL, chez la plupart des hebergeurs) |
| `QLIP_SMTP_PASS` | vide | mot de passe, lu seulement dans l'environnement |
| `QLIP_SMTP_FROM` | `QLIP_SMTP_USER` | adresse d'envoi, aussi utilisee dans l'enveloppe |
| `QLIP_SMTP_CAFILE` | vide | liste de racines, sinon celle du systeme |
| `QLIP_SMTP_HELLO` | `localhost` | nom donne a `EHLO` |
| `QLIP_SENDMAIL` | `/usr/sbin/sendmail` | chemin du MTA local (transport `local`) |
| `QLIP_SENDMAIL_FROM` | `QLIP_SMTP_FROM` | adresse d'enveloppe du MTA local |
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

Cinq transports, choisis par `QLIP_MAIL_TRANSPORT` :

- `outbox` (defaut) : le message est ecrit en `.eml` dans `var/outbox`. C'est le seul qui
  permet de lire le lien sans serveur mail, donc le seul utilise par les tests ;
- `log` : journalise seulement ;
- `null` : ne fait rien ;
- `smtp` : envoi reel, par le client ecrit a la main dans `src/Support/Smtp.php`. Pas de
  dependance, donc pas de PHPMailer : `EHLO`, `AUTH LOGIN`, `MAIL FROM`, `RCPT TO`, `DATA`,
  `QUIT`, ecrits en CRLF, avec le point de debut de ligne double (sinon le serveur croirait
  que le corps est termine).
- `local` : remise au MTA de la machine, par `src/Support/Sendmail.php` (`/usr/sbin/sendmail
  -t -i -f <adresse>`). Sans dependance, sans authentification et sans TLS : la confiance
  vient de la machine elle-meme. Les arguments sont donnes en liste, jamais dans une chaine
  de commande, et le message est ecrit sur stdin.

En SMTP, la connexion est en TLS implicite (port 465) et la verification du certificat est
toujours active : sans elle, le mot de passe partirait vers un serveur non identifie. Le mot
de passe n'est jamais ecrit dans le journal, et la page d'inscription ne montre plus le lien
de verification pour les transports reels (`smtp` et `local`) : il est seulement dans le
message envoye. Aucune copie `.eml` n'est ecrite pour ces transports, le journal
(`var/log/mail.log`) suffit.

Le transport `local` ne corrige pas la deliverability : si la machine n'est pas le MX du
domaine, le SPF du domaine ne valide pas son IP, et le message peut etre refuse ou finir en
spam. Le check ne verifie que la remise au MTA, pas la reception.

L'inscription exige un email verifie : sans verification, le tableau de bord et la
creation de badge renvoient 403. Les tentatives de connexion sont limitees a 8 par
session, puis verrou de 300 secondes.

## Mot de passe oublie

`/password` envoie un lien de reinitialisation a l'adresse du compte, et `GET
/password/reset/{token}` presente le formulaire du nouveau mot de passe. Le lien est usage
unique et valable 24 h.

- la page ne dit pas si l'adresse a un compte : la reponse est la meme dans les deux cas,
  sinon le formulaire servirait a dresser la liste des comptes. Avec le transport `outbox`
  elle le montre quand meme (le lien est affiche pour les transports de developpement) ;
- 5 demandes maximum par heure et par session, comptees sur la demande : une adresse sans
  compte consomme la meme part, sinon on pourrait tester les adresses sans limite ;
- le POST du nouveau mot de passe exige le jeton CSRF, et la taille minimale (10 caracteres)
  est controlee cote serveur. Un refus ne consomme pas le lien : le formulaire est repris ;
- quand un lien sert, les autres liens du meme compte sont fermes : un lien demande la semaine
  precedente ne doit plus pouvoir changer le mot de passe ;
- utiliser un lien envoye a la boite prouve le controle de cette boite, ce qui vaut la
  verification d'email : un compte encore non active est active par la reinitialisation, sinon
  il n'aurait plus aucun moyen d'entrer (le lien de verification est usage unique lui aussi) ;
- la session est regeneree et pointe sur le compte, comme pour le lien de verification.

Traces dans `audit_log` : `reset_request` pour la demande (avec l'adresse dans le detail quand
il n'y a pas de compte, parce que `user_id` reste NULL), et `reset` pour le changement. Ces
actions ne polluent pas le quota, qui ne porte que sur `action = 'badge'`.

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
   (auto-attestation acceptee). La cle COSE est stockee en base, lue avec les deux
   numerotations de labels : celle du registre COSE (kty = 1, alg = 3, courbe = -1, x = -2,
   y = -3) et la numerotation heritee que Chrome emet encore (-1, -2, -3, -4, -5). Une cle
   qui n'est coherent avec aucune des deux est refusee.
3. `POST /webauthn/assert` : drapeau UP, origine, hash du RP ID, challenge, compteur
   strictement croissant, signature EC2/P-256 (`ES256`) ou RSA (`RS256`). La forme brute
   `r || s` est convertie en DER a la main, et la forme DER est acceptee telle quelle : une
   cle U2F seule passe par la couche de compatibilite CTAP1, qui transmet le DER meme pour une
   assertion. Ce qui est controle est la base de signature et la cle, pas l'encodage ; une forme
   qui n'est ni `r || s` ni un DER lisible est refusee.

La page `/admin` n'est accessible qu'avec une session confirmee par une assertion
(`webauthn_at` en session), sur un compte marque administrateur.

### Deux profils de ceremonie

La politique de verification utilisateur (`userVerification`) est choisie par le serveur,
par profil de ceremonie, jamais par le navigateur : sinon un client pourrait demander
`discouraged` et obtenir une assertion sur une credential reglee en `required`. Le profil
est pose par la page qui rend la ceremonie, et la politique est stockee par credential au
moment de l'enregistrement, donc elle ne change plus.

| Profil | Niveau | Ou | Pourquoi |
| --- | --- | --- | --- |
| `presence` | `discouraged` | second facteur, enrolement depuis le tableau de bord | le mot de passe a deja prouve l'utilisateur, la cle prouve la possession |
| `passkey` | `required` | page d'administration | la cle est le seul moyen d'identifier l'utilisateur |

A l'assertion, la politique effective est la plus forte des deux : la politique du profil
et celle stockee par la credential. Une credential enregistree sous `required` ne peut donc
pas etre utilisee sous le profil `presence`, et `preferred` continue d'exiger la
verification des que la cle l'a faite une fois (`uv_seen`) : le niveau laisse le choix a la
cle une fois, pas a chaque fois quand elle est capable de la faire.

Consequence pour une cle U2F seule (YubiKey NEO, `authenticatorGetInfo` sans le drapeau
CBOR, capacites `0x01` = clin d'oeil seulement) : elle ne sait pas faire la verification
utilisateur, donc elle ne peut satisfaire que `discouraged`. Elle ouvre la session par mot de
passe + possession, elle ne peut pas ouvrir `/admin` par une assertion seule. Une cle capable
de la faire (YubiKey 5 en FIDO2) doit etre enregistree depuis la page d'administration.

### Second facteur avec mot de passe

Quand le compte a une credential, le mot de passe ne suffit plus :

1. `POST /login` : si le mot de passe est valide et le compte a une credential, la session est
   mise en attente (`pending_user_id`) et redirige vers `GET /login/2fa`. Cette session ne
   donne aucun acces.
2. `POST /login/2fa` : la ceremony. La credential doit appartenir au compte en attente, et le
   verrou est le meme que celui du mot de passe (8 tentatives par session, puis 300 s) : sinon
   une ceremony refusee servirait a tester les credentials sans limite.
3. L'assertion acceptee confirme la session (`webauthn_at`) et la marque `login_2fa` dans
   `audit_log` ; l'evenement du mot de passe est `login_pending`, donc le journal distingue les
   deux etapes par compte. La session garde la credential qui a fait la confirmation, donc
   supprimer cette cle retire la confirmation (voir Suppressions) : la page d administration
   redemande une assertion.

Une cle U2F ne stocke pas la credential en resident : la liste `allowCredentials` vient du
serveur, la cle ne peut pas retrouver son identifiant seule. Elle n'a pas d'AAGUID non plus :
l'identite de l'appareil vient du numero de serie du certificat `x5c`, donc aucune decision
d'administration ne peut se prendre sur la cle.

### Format d'attestation fido-u2f

Une cle U2F seule ne produit que ce format : elle ne sait pas faire CBOR, donc elle n'emmet
ni `none` ni `packed`. Le navigateur convertit la reponse de la cle en objet d'attestation
avec les memes cles `fmt`, `attStmt`, `authData`. La base de signature est particuliere :

```
0x00 || rpIdHash || clientDataHash || credentialId || publicKeyU2F
```

avec `publicKeyU2F = 0x04 || x || y`, le point COSE de la credential. Ce n'est pas le message
WebAuthn `hash(authData) + hash(clientData)` : le premier octet est un contre-signal fixe
(`0x00` pour l'attestation, `0x01` pour l'assertion). `x5c` est obligatoire ici : sans
certificat il n'y a rien a verifier, ce format n'a pas d'auto-attestation. Le harnais
verifie la base avec le cas `bad_base` : une signature calculee sur l'autre base est refusee.

Le format enregistre avec la credential porte la base de l'assertion. Pour une credential
fido-u2f, la base est `authData` sans hash suivi du hash des donnees client (le contre-signal y
est l'octet de presence utilisateur), et pour une credential enregistree sans attestation elle
reste `hash(authData) + hash(clientDataJSON)`. Le harnais produit les deux bases et controle les
deux directions : la base WebAuthn est refusee pour une credential fido-u2f, la base U2F est
refusee pour une autre credential.

La forme de la signature suit le transport. Une cle EC2 qui signe au niveau WebAuthn remet
`r || s` ; une cle U2F seule passe par la couche de compatibilite CTAP1, qui remet le DER
`SEQUENCE { INTEGER r, INTEGER s }` y compris pour une assertion. Les deux formes sont acceptees,
parce que le controle porte sur la base de signature et sur la cle, pas sur l'encodage. Le
harnais verifie les deux formes et qu'une forme malformee est refusee : accepter le DER ne veut
pas dire accepter n'importe quels octets.

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
   compte sans mot de passe ensuite. Depuis le tableau de bord le profil est `presence`
   (`discouraged`) : c'est la que la cle d'un second facteur est enregistree, et c'est le
   seul profil qu'une cle U2F seule peut satisfaire.

4. La ceremony `navigator.credentials.create()` envoie l'attestation en base64url a
   `POST /webauthn/register` : les formats acceptes sont `none`, `packed` et `fido-u2f`
   (une reponse `android-key` ou `apple` est refusee). La cle COSE, l'ID de
   credential et le compteur sont stockes en base. Avec `QLIP_ATTESTATION_ROOTS` (PEM
   d'une racine, par exemple le certificat racine de Yubico), la chaine `x5c` est remontee
   jusqu'a cette racine ; sans elle, `packed` avec `x5c` est accepte comme une declaration.

5. Puis `Presente la YubiKey` (`POST /webauthn/assert`) : la session est marquee
   `webauthn_at`, et `/admin` devient accessible. Tant que cette marque n'existe pas,
   `/admin` renvoie a la page d'enrolement. Le compteur de signature doit etre
   strictement croissant, et la meme assertion n'est pas acceptee deux fois.

### Si la ceremonie echoue

Le message par defaut du navigateur (`NotAllowedError`) ne dit pas pourquoi : il couvre
l'annulation, le delai depasse, un authenticateur ecarte par les criteres de selection, et une
origine qui ne correspond pas au `rpId`. Les causes, dans l'ordre ou on les ecarte :

1. `rpId` : il doit etre l'hote de l'origine qui appelle `navigator.credentials`. Si
   `QLIP_BASE_URL` est laisse a son defaut (`http://localhost:8080`) derriere un nom de domaine,
   le navigateur refuse avant de parler au token. `tests/live.sh` verifie ce point.
2. `userVerification` : la clef doit pouvoir verifier l'utilisateur (PIN) quand la politique
   est `required`, ce qui est le profil `passkey` de la page d'administration. Une clef en mode
   U2F (CTAP1) ne le peut pas et est ecartee par le critere sur ce profil ; elle est acceptee
   sur le profil `presence` (`discouraged`), ou l'authentifiant est choisi par le mot de passe.
   Avec une YubiKey 5 en FIDO2, saisis le PIN (defaut `1234` sur une clef neuve).
3. Transports : aucune liste n'est envoyee, le navigateur choisit. Une clef vue comme carte a
   puce (`smart-card`) ou par NFC serait ecartee par une liste fermee.
4. `NotSupportedError` avec `CredentialsContainer request is not supported` : la requete ne nomme
   pas le type de credential. Le membre `publicKey` est obligatoire sur `create()` comme sur
   `get()` ; la forme plate heritee de WebAuthn Level 1 (options au premier niveau) est refusee par
   Firefox, qui rejette la requete avant de parler a l'authenticateur.
5. Forme et base de la signature : une cle U2F seule remet la signature d'assertion en DER, la
   forme de la couche de compatibilite CTAP1, pas en `r || s`. Les deux formes sont acceptees, une
   forme malformee est refusee, donc la forme n'est pas ce qui fait passer une signature. La base,
   en revanche, est choisie par le format enregistre avec la credential (`fmt`) : la base U2F pour
   une credential fido-u2f, la base WebAuthn `hash(authData) + hash(clientDataJSON)` pour les
   autres. Elle n'est pas essayee dans les deux sens au moment de verifier, sinon le controle ne
   porterait plus sur ce sur quoi la signature est faite.

   Mesure sur la YubiKey NEO reelle (`tests/assert_base.py`, et `--self-test` contre un cas connu
   pour montrer que la sonde ne valide pas toutes les bases) : la cle signe
   `application parameter || presence || counter || challenge parameter`, soit AuthData sans hash
   suivi du hash des donnees client. Une credential enregistree avant la colonne `fmt` porte une
   valeur par defaut, pas un fait : son format n'etait pas stocke, donc la cle doit etre
   enregistree de nouveau, ou la ligne reglee a partir de la mesure.
6. Le nom de l'exception est affiche sur la page (`NotAllowedError`, `TypeError`,
   `NotSupportedError`) : un echec immediat, avant tout prompt, vient des options ou de
   l'origine ; un echec apres le prompt vient d'une annulation ou du delai depasse. Une erreur
   ecrite par le serveur (`Signature invalide`, `Verification utilisateur exigee`, `Compteur de
   signature non croissant`) vient apres la reponse de la cle : la cle a repondu, c'est le controle
   qui a refuse.

En resume : inscription avec l'adresse d'administration, verification d'email, connexion
par mot de passe, enregistrement de la cle, assertion, `/admin`. Le mot de passe ne sert
plus ensuite qu'a enregistrer une autre cle (bouton `Enregistrer une YubiKey` sur la page
d'administration) ou a reprendre la main si la cle est perdue.

Pour un compte ordinaire avec une cle enregistree depuis le tableau de bord, le parcours est
le meme dans l'ordre inverse : mot de passe, puis possession de la cle (`/login/2fa`). La cle
ne remplace pas le mot de passe, elle s'y ajoute.

## Badges STL

Un badge est une plaque arrondie avec un trou, et le QR code en relief ou grave.

- Monochrome : un seul solide, `engraved` (grave) ou `raised` (relief).
- Bicolore : deux volumes (base + encre), chacun etanche, ecrits dans un .3MF avec les
  groupes de couleurs pour les slicers multicolores.
- Sorties : STL ASCII (`/badges/{id}/stl`) et .3MF (`/badges/{id}/3mf`).

Les maillages sont verifies : chaque arete doit apparaitre dans les deux sens. Les aretes
utilisees deux fois dans le meme sens sont des contacts (faces confondues des volumes
bicolores), signalees mais pas considerees comme des erreurs.

## Suppressions

Le tableau de bord permet de supprimer une page (et les badges qui la portent), un badge,
une cle de second facteur, ou le compte. Suppression definitive : ce qui est detruit ne revient
pas.

- POST seulement : un GET ne doit pas detruire, un lien ne suffit pas ;
- jeton CSRF obligatoire, et une confirmation explicite (case a cocher) ;
- le mot de passe est demande pour le compte, parce qu une session laissee ouverte sur un
  poste partage ne doit pas permettre de detruire un compte ;
- le mot de passe est demande aussi pour supprimer la cle 2FA : sans lui, une session laissee
  ouverte permettrait d enlever le second facteur et de laisser le compte accessible par le seul
  mot de passe ;
- le proprietaire supprime ce qui est a lui, l administrateur peut supprimer ce qu il peut
  lire ;
- une ligne est ecrite dans `audit_log` (`page_supprimee`, `badge_supprime`,
  `compte_supprime`, `credential_supprimee`) avec qui et quoi. Pour un compte, la ligne est
  ecrite apres le DELETE et garde l adresse dans le detail : `user_id` devient NULL
  (`ON DELETE SET NULL`), donc la trace ne peut pas s appuyer sur le compte.

Supprimer la cle 2FA remet le compte au seul mot de passe, et emporte la confirmation de la
session que cette cle avait apportee (`webauthn_at`) : la page d administration redemande une
assertion. Supprimer une autre cle du meme compte ne change pas la confirmation, la session
garde la credential qui l a faite.

### Journal : pagination et effacement de plus de 2 mois

La page d administration lit ses listes par page de 50. Le nombre de pages vient d un COUNT,
pas des lignes lues : sinon la derniere page, quand elle n est pas pleine, ne serait jamais
proposee. Le numero est un element de l URL, un par liste, donc une liste ne deplace pas les
autres ; un numero demande hors plage est ramene a ce que la liste peut donner, parce qu un
offset negatif n est pas une page.

L effacement des lignes plus vieilles de 2 mois (`Journal::RETENTION_MONTHS`) est une
suppression definitive, dans le meme style que les autres : POST seulement, jeton CSRF,
confirmation explicite, mot de passe du compte, et une session confirmee par une assertion comme
le reste de la page. Elle n est pas automatique : rien ne part sans qu on le demande, et ce qui
est efface ne revient pas.

Deux choses que l effacement ne doit pas casser :

- la trace est ecrite apres le DELETE, avec le nombre de lignes parties (`journal_efface`) : la
  ligne qui consigne l effacement ne peut pas s effacer elle-meme ;
- le quota porte sur les evenements de creation du jour, pas sur les lignes qui restent : effacer
  un journal de 2 mois ne rembourse rien.

### Effacer la cle quand la ceremony ne marche plus

Une cle enregistree ferme le compte au mot de passe seul : `POST /login` met la session en
attente, et seule la ceremony `POST /login/2fa` l ouvre. Si la ceremony echoue, aucune session ne
peut etre ouverte, donc aucune route de suppression ne peut etre appelee : elle est POST seulement,
exige le jeton CSRF de la session, la confirmation explicite et le mot de passe, et `/admin` exige
une assertion, ce qui est justement ce qui echoue. Ce blocage n est pas une exception a ces regles :
la regle "POST seulement, jeton, mot de passe, appartenance" est plus importante que la
possibilite de detruire sans s etre authentifie.

Deux cas, dans l ordre :

1. La cle refonctionne (la ceremony echouait du cote du navigateur, ou la cle etait seulement
   ecartee par la politique) : `Presente la cle` depuis le tableau de bord, puis supprimer la cle
   par le formulaire. C est la seule facon d effacer la cle depuis l application, et la trace est
   ecrite par elle.

2. La cle est perdue, ou la ceremony echoue cote serveur : la ligne doit etre retiree hors
   application, et la ligne d audit doit etre ecrite a la main, parce que le journal ne peut pas
   savoir ce qu une action hors application a detruit. La ligne est lue avant d etre supprimee, le
   `credential_id` est reporte dans le detail, et les deux ecritures tiennent dans la meme
   transaction.

```bash
DB=/opt/WebSites/qlip.tigro.fr/data/qlip.sqlite

# Ce que la ligne porte, avant de la retirer : le detail de la trace doit reprendre l identifiant
sqlite3 "file:$DB?mode=ro" \
    "select id, user_id, credential_id, policy, uv_seen, sign_count from webauthn_credentials;"

# Retirer la cle et garder la trace. Le nom de l action est celui de l application, et le detail
# dit que la ligne est ecrite hors application : sinon le journal laisse croire que la route a ete
# appelee, et on ne peut plus distinguer une action d un geste manuel.
sqlite3 "$DB" "BEGIN;
DELETE FROM webauthn_credentials WHERE id = 1;
INSERT INTO audit_log (user_id, action, detail, created_at)
VALUES (1, 'credential_supprimee',
        'cle retiree hors application : la ceremony echouait, aucune session possible',
        datetime('now'));
COMMIT;"
```

- le compte redevient accessible par le seul mot de passe : `POST /login` ne trouve plus de
  credential, donc la session n est plus mise en attente ;
- la confirmation de la session (`webauthn_at`) ne vient pas de la base, elle est dans la session :
  se deconnecter est necessaire, sinon une session laissee ouverte garde la confirmation d une cle
  qui n existe plus ;
- le nom de l action reste `credential_supprimee` : le quota du jour compte `action = 'badge'`, donc
  une suppression n entre pas dans le comptage, qu elle soit faite par la route ou a la main ;
- la cle est ensuite a re-enregistrer depuis le tableau de bord (profil `presence`), et une cle qui
  doit ouvrir `/admin` doit etre enregistree depuis la page d administration (profil `passkey`), ce
  qu une cle U2F seule ne peut pas satisfaire ;
- une cle enregistree avant la colonne `fmt` porte la valeur par defaut `none`, donc son assertion
  est controlee sur la base WebAuthn et echoue de nouveau pour une cle U2F seule : la cle est a
  re-enregistrer, ou la ligne est reglee a partir de la mesure faite sur les octets recus
  (`fmt = 'fido-u2f'`), ce qui est une mesure et non une supposition. Une cle enregistree par
  l application apres la colonne porte son format, et le format choisi a l enregistrement est celui
  que la cle a pu emettre : une cle U2F seule ne peut emettre que `fido-u2f`.

Le quota de 10 creations par jour porte sur les evenements de creation (`action = 'badge'`
dans `audit_log`), pas sur les lignes qui restent : supprimer un badge ne rembourse pas le
quota. Sinon on pourrait creer, supprimer, creer sans limite.

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
   la cle COSE est ecrite avec les deux numerotations de labels (celle du registre et la
   numerotation heritee), et le PEM construit par PHP est compare au DER produit par la
   reference Python ; un map melangeant les deux doit etre refuse ; les deux formes de
   signature (`r || s` et DER) sont verifiees sur la meme base, et une forme malformee doit
   etre refusee ; la base est celle du format enregistre avec la credential, et l'autre base doit
   etre refusee pour chaque credential, dans les deux sens ;
6. `tests/app_test.php` : routes sans HTTP (schema, inscription, verification, threpse,
   reinitialisation de mot de passe : lien usage unique, 24 h, adresse sans compte, verou de
   5 demandes, jetons du compte fermes, pages, quotas par compte, exports etancheite, CSRF,
   ceremony WebAuthn par la route, transports smtp et local : lien masque et echec d envoi
   signale, suppressions avec confirmation, cascade, quota non rembourse, trace d audit, journal :
   numero de page ramene, et effacement seulement de ce qui a plus de 2 mois avec sa trace) ;
7. `tests/smtp_mock.py` + `tests/smtp_test.php` : le client SMTP ecrit a la main parle a un
   serveur mock TLS. Le mock verifie ce qu'il recoit (EHLO, base64 de `AUTH LOGIN`,
   `MAIL FROM`, `RCPT TO`, corps en CRLF, point double, QUIT), le script PHP verifie comment
   le client reagite : certificat refuse sans liste de confiance, arret apres un 535, refus
   quand `AUTH` n'est pas annonce ;
8. `tests/sendmail_test.php` : le transport local parle a un faux MTA qui enregistre les
   arguments recus et ce qu'il lit sur stdin. Verifie que les arguments sont une liste
   (`-t -i -f <adresse>`, jamais une chaine de commande), que le message arrive en CRLF tel
   quel, qu'une adresse avec un retour a la ligne est refusee avant de lancer le MTA, et
   qu'un code nonzero du MTA est signale comme un echec ;
9. `tests/http_test.py` : front controller relance sur un serveur PHP integre, y compris
   le PNG decode par zxing, la ceremony WebAuthn par HTTP, la reinitialisation de mot de
   passe (reponse identique pour une adresse sans compte, lien usage unique, verou), et les
   suppressions (jeton, confirmation, compte, GET refuse en 405) ;
10. `tests/live_test.py` (lance par `tests/live.sh`) : protocole sur l instance deployee,
   voir la section suivante.

Les tests ecrivent dans `QLIP_OUT` (defaut `/tmp/opencode/badge`) et utilisent
`QLIP_PHP` pour le binaire PHP, `QLIP_IMAGE` pour l'image podman.

## Tests en conditions reelles

```bash
./tests/live.sh                                                     # lecture seule
QLIP_LIVE_TRANSPORT=local ./tests/live.sh                           # idem, transport MTA local
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

La partie authentifiee est facultative et ne se lance qu avec `QLIP_LIVE_WRITE=1`, avec un
compte de test (jamais le compte administrateur) : les identifiants viennent seulement des
variables d environnement, et le mot de passe n est jamais affiche. Le slug cree est
`livecheck`, et ce qui est cree est supprime a la fin du test, donc la partie ecriture est
repeitable. Elle verifie par HTTP, sur le vrai serveur : jeton CSRF refuse, confirmation
obligatoire, POST seulement (un GET sur une route de suppression rend 405), suppression du
badge puis de la page, lignes disparues, quota non rembourse, threpse de connexion. Le
compte de test n est pas supprime : le test ne lit pas la poste, donc il ne peut pas se
reinscrire.

## Organisation

```
public/          front controller + serveur de developpement + assets
src/App/         Config, Router, Request, Response, Session, View, conteneur
src/Controllers/ Auth, Pages, Badges, Admin
src/Services/    Users, Badges, Credentials, Journal, Mailer
src/Qr/          encodeur QR (Reed-Solomon, masks, penalites) + rendus SVG/PNG/ASCII
src/Stl/         Mesh, Triangulator, Design, Badge, StlWriter, ThreeMfWriter
src/Webauthn/    Cbor, AuthenticatorData, CoseKey, Signature, Policy, Verifier
src/Support/     helpers (base64url, echappement, jetons), Pager, ZipWriter, SMTP, Sendmail
templates/       vues HTML
tests/           suite de tests (PHP + Python)
```

Le routeur est extrait dans `src/routes.php` (`qlip_routes(App $app)`) pour que les tests
puissent dispatcher sans serveur HTTP.

## Licence

MIT, voir `LICENSE`.
