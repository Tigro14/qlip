#!/usr/bin/env python3
"""Sonde de base de signature : quelle base a signe la cle pour une assertion.

Le verificateur PHP controle l assertion sur hash(authData) + hash(clientDataJSON), la base que
la spec WebAuthn definit. Une cle U2F seule ne signe pas cette base : la base qu elle signe est
application parameter (le hash du RP ID) + octet de presence utilisateur + counter + challenge
parameter (le hash des donnees client), soit AuthData sans hash suivi du hash des donnees client.
La base est une mesure, pas une supposition : la seule facon de la connaitre est de partir des
octets recus d une ceremonie reelle.

La sonde ne fait que verifier. Elle ne touche ni la base de donnees, ni la cle. Elle prend les
octets d une tentative ratee, tels qu ils sont envoyes au serveur (corps du POST
/webauthn/assert, relu dans l'onglet Network du navigateur), et elle essaie les bases candidates
avec la reference Python, a partir de la cle COSE stockee en base.

Le CBOR est lu par un lecteur ecrit ici, pas par celui du projet : une lecture alignee sur
l'implementation ne pourrait pas voir une erreur.

  python3 tests/assert_base.py /tmp/opencode/assert.json

Le JSON attendu (valeurs en base64url, comme elles arrivent au serveur) :

  {
    "credential_id": "...",
    "auth_data": "...",
    "client_data": "...",
    "signature": "...",
    "cose": "..."
  }

La sonde peut aussi etre lancee contre un cas connu : le harnais produit une signature faite sur
la base WebAuthn, donc la sonde doit nommer cette base. C est le test de la sonde elle-meme :
une sonde qui accepte toutes les bases ne mesure rien.
"""

import base64
import hashlib
import json
import os
import sys

from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.asymmetric import ec

failures = 0


def check(label, ok, detail=''):
    global failures

    if not ok:
        failures += 1

    print('  %s   %s%s' % ('OK' if ok else 'KO', label, ' : ' + detail if detail else ''))


def b64url(value):
    return base64.urlsafe_b64decode(value + '=' * (-len(value) % 4))


def read_cbor(data, pos):
    """Lecteur CBOR minimal, ecrit ici : map, octets, entiers."""
    head = data[pos]
    major = head >> 5
    info = head & 31
    # Additional info 24, 25, 26, 27 : 1, 2, 4, 8 octets de longueur suivent, pas info - 24.
    extra = 0 if info < 24 else 1 << (info - 24)

    if major == 5:
        if info < 24:
            count = info
            pos += 1
        else:
            count = int.from_bytes(data[pos + 1:pos + 1 + extra], 'big')
            pos += 1 + extra

        entries = {}

        for _ in range(count):
            key, pos = read_cbor(data, pos)
            value, pos = read_cbor(data, pos)
            entries[key] = value

        return entries, pos

    if major == 2:
        length = info if info < 24 else int.from_bytes(data[pos + 1:pos + 1 + extra], 'big')
        offset = pos + 1 + extra

        return data[offset:offset + length], offset + length

    if major in (0, 1):
        value = int.from_bytes(data[pos + 1:pos + 1 + extra], 'big') if extra else info

        if major == 1:
            value = -(value + 1)

        return value, pos + 1 + extra

    raise ValueError('type CBOR non lu par la sonde : major %d' % major)


def coordinates(cose):
    """x, y lus avec les trois numerotations de labels (registre, JWK, heritee)."""
    for labels in ((-2, -3), (5, 6), (-4, -5)):
        x, y = (cose.get(label) for label in labels)

        if isinstance(x, bytes) and isinstance(y, bytes) and len(x) == 32 and len(y) == 32:
            return x, y

    return None


def der_from_raw(raw, size):
    """r || s -> DER SEQUENCE { INTEGER r, INTEGER s }, fait ici aussi, pour tester une signature
    recue sous la forme brute."""
    def integer(value):
        value = value.lstrip(b'\x00') or b'\x00'

        return (b'\x00' + value) if value[0] >= 0x80 else value

    def length(value):
        if value < 128:
            return bytes([value])

        raw_length = value.to_bytes((value.bit_length() + 7) // 8, 'big')

        return bytes([0x80 + len(raw_length)]) + raw_length

    r, s = integer(raw[:size]), integer(raw[size:])

    return b'\x30' + length(len(r) + len(s) + 4) + b'\x02' + length(len(r)) + r + b'\x02' + length(len(s)) + s


def bases(credential_id, auth_data, client_data, x, y):
    """Bases candidates, chacune ecrite depuis la spec qui la definit.

    FIDO U2F, reponse d authentification : application parameter (le hash du RP ID, ou de l AppID
    quand l extension appid est utilisee), octet de presence utilisateur, counter, challenge
    parameter (le hash des donnees client). C est exactement AuthData, sans hash, suivi du hash des
    donnees client : la difference avec la base WebAuthn est que AuthData n est pas soumis au hash.

    WebAuthn, etape de verification de l assertion (Level 1 et Level 2) : hash(AuthData) suivi du
    hash des donnees client.
    """
    client_hash = hashlib.sha256(client_data).digest()
    point = b'\x04' + x + y

    return {
        'webauthn : hash(authData) || hash(clientDataJSON)':
            hashlib.sha256(auth_data).digest() + client_hash,

        # u2f : applicationParam || userPresence || counter || challengeParam
        'u2f : authData (sans hash) || hash(clientDataJSON)':
            auth_data + client_hash,

        # Base d attestation fido-u2f : elle ne peut pas servir a une assertion
        'u2f attestation : 0x00 || rpIdHash || clientDataHash || credentialId || publicKeyU2F':
            b'\x00' + auth_data[0:32] + client_hash + credential_id + point,
    }


def main():
    path = sys.argv[1] if len(sys.argv) > 1 else os.environ.get('QLIP_ASSERT_JSON', '')

    if not path or not os.path.isfile(path):
        print('sonde lancee sans octets : copie le corps du POST /webauthn/assert dans un JSON '
              '(credential_id, auth_data, client_data, signature, cose) puis relance')
        return 1

    data = json.load(open(path))

    credential_id = b64url(data['credential_id'])
    auth_data = b64url(data['auth_data'])
    client_data = b64url(data['client_data'])
    signature = b64url(data['signature'])
    cose, _ = read_cbor(b64url(data['cose']), 0)

    coords = coordinates(cose)

    if coords is None:
        print('cle COSE sans coordonnees : la sonde ne peut pas reconstituer la cle')
        return 1

    x, y = coords

    # La forme n est pas ce qui est mesure ici : la signature est remise telle quelle, et la
    # forme brute est convertie par la sonde elle meme pour pouvoir comparer les bases.
    der = signature if len(signature) != 64 else der_from_raw(signature, 32)
    public = ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), b'\x04' + x + y)

    print('cle COSE (reference Python) : x=%s' % x.hex())
    print('                            y=%s' % y.hex())
    print('counter lu dans authData     : %d' % int.from_bytes(auth_data[33:37], 'big'))
    print('forme recue                  : %d octets (%s)' % (
        len(signature), 'brute r || s' if len(signature) == 64 else 'DER'))
    print()

    matched = []

    for label, message in bases(credential_id, auth_data, client_data, x, y).items():
        try:
            public.verify(der, message, ec.ECDSA(hashes.SHA256()))
            check(label, True)
            matched.append(label)
        except Exception as error:
            check(label, False, str(error)[:60])

    print()

    if len(matched) == 1:
        print('base de la cle : %s' % matched[0])
        print('problemes : 0')
        return 0

    print('problemes : 1 (aucune base, ou plusieurs : la mesure ne tranche pas)')
    return 1


if __name__ == '__main__':
    sys.exit(main())
