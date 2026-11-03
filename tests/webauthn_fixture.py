#!/usr/bin/env python3
"""Fabrique des fixtures WebAuthn (un faux authenticateur) pour verifier le
verificateur PHP : enregistrement + assertion, en EC2/P-256 et RSA/RS256.

Les valeurs sont des chaines base64url, comme celles que le navigateur envoie
apres `navigator.credentials.create()` et `.get()`.

Usage : python3 tests/webauthn_fixture.py > /tmp/opencode/webauthn.json
"""

import base64
import hashlib
import json
from datetime import datetime, timezone

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec, padding, rsa
from cryptography.x509.oid import NameOID

RP_ID = "qlip.test"
ORIGIN = "https://qlip.test"
CHALLENGE = b"\x01\x02\x03" * 10 + b"\x04"


def b64url(data):
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


def cbor_head(major, length):
    if length < 24:
        return bytes([(major << 5) | length])

    if major == 7:
        return bytes([(major << 5) | length])

    bytes_value = length.to_bytes((length.bit_length() + 7) // 8, "big")
    return bytes([(major << 5) | (24 + len(bytes_value) - 1)]) + bytes_value


def cbor(value):
    if isinstance(value, bool):
        return cbor_head(7, 21 if value else 20)

    if isinstance(value, int):
        if value >= 0:
            return cbor_head(0, value)

        return cbor_head(1, -1 - value)

    if isinstance(value, bytes):
        return cbor_head(2, len(value)) + value

    if isinstance(value, str):
        return cbor_head(3, len(value.encode())) + value.encode()

    if isinstance(value, dict):
        out = cbor_head(5, len(value))

        for key, item in value.items():
            out += cbor(key) + cbor(item)

        return out

    if isinstance(value, list):
        out = cbor_head(4, len(value))

        for item in value:
            out += item

        return out

    raise TypeError("type CBOR non supporte")


def cose_ec(key):
    numbers = key.private_numbers().public_numbers

    return {
        -1: 2,
        -2: -7,
        -3: "P-256",
        -4: numbers.x.to_bytes(32, "big"),
        -5: numbers.y.to_bytes(32, "big"),
    }


def cose_rsa(key):
    numbers = key.private_numbers().public_numbers
    modulus = numbers.n.to_bytes((numbers.n.bit_length() + 7) // 8, "big")
    exponent = numbers.e.to_bytes((numbers.e.bit_length() + 7) // 8, "big")

    return {-1: 1, -2: -257, -3: modulus, -4: exponent}


def read_der_int(signature, pos):
    assert signature[pos] == 0x02
    length = signature[pos + 1]
    return int.from_bytes(signature[pos + 2:pos + 2 + length], "big"), pos + 2 + length


def raw_signature(signature, size):
    """DER SEQUENCE { INTEGER r, INTEGER s } -> r || s (ce que renvoie le navigateur)."""
    assert signature[0] == 0x30
    header_length = 1 if signature[1] < 128 else 1 + (signature[1] & 31)
    r, pos = read_der_int(signature, 1 + header_length)
    s, _ = read_der_int(signature, pos)

    return r.to_bytes(size, "big") + s.to_bytes(size, "big")


def auth_data(flags, sign_count, credential_id, cose, include_at, rp_id=None):
    out = hashlib.sha256((rp_id or RP_ID).encode()).digest() + bytes([flags]) + sign_count.to_bytes(4, "big")

    if include_at:
        out += bytes(16) + len(credential_id).to_bytes(2, "big") + credential_id + cbor(cose)

    return out


def client_data(client_type, origin, challenge=None):
    return json.dumps({
        "type": client_type,
        "challenge": b64url(challenge or CHALLENGE),
        "origin": origin,
        "tokenType": "WebAuthn",
    }, separators=(",", ":")).encode()


def sign(key, message, alg):
    if alg == "ES256":
        return raw_signature(key.sign(message, ec.ECDSA(hashes.SHA256())), 32)

    return key.sign(message, padding.PKCS1v15(), hashes.SHA256())


def fixture(
    name,
    key,
    cose,
    alg,
    credential_id,
    policy="required",
    can_verify=True,
    fmt="none",
    att_stmt=None,
    bad_base=None,
):
    # Enregistrement : AT + UP + UV. Une cle U2F seule ne sait pas faire la verification
    # utilisateur : le drapeau UV est pose seulement quand l'authenticateur peut la faire.
    flags = 0x45 if can_verify else 0x41
    data = auth_data(flags, 1, credential_id, cose, True)
    create_client = client_data("webauthn.create", ORIGIN)
    message = hashlib.sha256(data).digest() + hashlib.sha256(create_client).digest()

    # Cles imposees par la spec (fmt, attStmt, authData) : la reference ne doit pas etre
    # alignee sur l'implementation, sinon un nom de cle errone passe les tests et echoue
    # devant un vrai navigateur.
    attestation = cbor({
        "fmt": fmt,
        "authData": data,
        "attStmt": att_stmt or {},
    })

    register = {
        "attestation": b64url(attestation),
        "client_data": b64url(create_client),
        "credential_id": b64url(credential_id),
        "cose": b64url(cbor(cose)),
        "sign_count": 1,
    }

    # Assertion : compteur 2, plus de drapeau AT.
    assertion_data = auth_data(0x05 if can_verify else 0x01, 2, credential_id, cose, False)
    get_client = client_data("webauthn.get", ORIGIN)
    signature = sign(key, hashlib.sha256(assertion_data).digest() + hashlib.sha256(get_client).digest(), alg)

    assertion = {
        "auth_data": b64url(assertion_data),
        "client_data": b64url(get_client),
        "signature": b64url(signature),
        "sign_count": 2,
    }

    # Rejoue de la meme assertion : le compteur ne doit pas etre accepte deux fois.
    replay = dict(assertion, sign_count=2)

    # Assertion avec une origine differente : compteur 3, seule l'origine est fausse.
    origin_data = auth_data(0x05 if can_verify else 0x01, 3, credential_id, cose, False)
    evil_client = client_data("webauthn.get", "https://evil.example")
    signature_evil = sign(key, hashlib.sha256(origin_data).digest() + hashlib.sha256(evil_client).digest(), alg)
    bad_origin = dict(
        assertion,
        auth_data=b64url(origin_data),
        client_data=b64url(evil_client),
        signature=b64url(signature_evil),
        sign_count=3,
    )

    # Signature calculee avec une autre cle : compteur 3, seule la signature est fausse.
    other = ec.generate_private_key(ec.SECP256R1()) if alg == "ES256" else rsa.generate_private_key(public_exponent=65537, key_size=2048)
    other_data = auth_data(0x05 if can_verify else 0x01, 3, credential_id, cose, False)
    bad_signature = sign(other, hashlib.sha256(other_data).digest() + hashlib.sha256(get_client).digest(), alg)
    bad_signature_case = dict(
        assertion,
        auth_data=b64url(other_data),
        signature=b64url(bad_signature),
        sign_count=3,
    )

    return {
        "name": name,
        "policy": policy,
        "user_verified": can_verify,
        "register": register,
        "assert": assertion,
        "replay": replay,
        "bad_origin": bad_origin,
        "bad_signature": bad_signature_case,
        "bad_base": bad_base,
    }


def self_signed(key):
    """Certificat d attestation auto-signe, en DER.

    x5c est obligatoire dans le format fido-u2f : sans certificat il n'y a rien a
    verifier, et ce format n'a pas d'auto-attestation. Une cle reelle remet un
    certificat emis par la CA Yubico ; ici la chaine racine n'est pas fournie, donc le
    verificateur PHP accepte le certificat comme une declaration, comme pour 'packed'.
    L'identite de l'appareil vient du numero de serie : le format U2F n'a pas d'AAGUID.
    """
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "qlip test attestation")])
    now = datetime(2024, 1, 1, tzinfo=timezone.utc)

    builder = x509.CertificateBuilder().subject_name(name).issuer_name(name)
    builder = builder.serial_number(12024).not_valid_before(now).not_valid_after(now)
    builder = builder.public_key(key.public_key())

    return builder.sign(key, hashes.SHA256()).public_bytes(serialization.Encoding.DER)


def u2f_case(key, cose, credential_id, data, create_client, cert):
    """Signature faite sur le message WebAuthn, pas sur la base U2F.

    La base U2F est 0x00 || rpIdHash || clientDataHash || credentialId || publicKeyU2F.
    Le premier octet est un contre-signal (0x00 pour l'attestation, 0x01 pour l'assertion) :
    une signature calculee sur hash(authData) + hash(clientData) doit etre refusee. C'est la
    seule facon de montrer que le verificateur ne se contente pas de verifier une signature
    sur n'importe quelle base.
    """
    message = hashlib.sha256(data).digest() + hashlib.sha256(create_client).digest()
    statement = {"sig": key.sign(message, ec.ECDSA(hashes.SHA256())), "x5c": [cbor(cert)], "counter": 0}

    return {
        "attestation": b64url(cbor({"fmt": "fido-u2f", "authData": data, "attStmt": statement})),
        "client_data": b64url(create_client),
    }


def main():
    ec_key = ec.generate_private_key(ec.SECP256R1())
    rsa_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)

    # Cle U2F seule (YubiKey NEO) : fmt = fido-u2f, pas de CBOR, pas de verification
    # utilisateur. Le harnais produit ce que le navigateur transmet au serveur apres
    # conversion de la reponse de la cle.
    u2f_key = ec.generate_private_key(ec.SECP256R1())
    u2f_cose = cose_ec(u2f_key)
    u2f_id = b"cred-u2f"
    u2f_data = auth_data(0x41, 1, u2f_id, u2f_cose, True)
    u2f_client = client_data("webauthn.create", ORIGIN)
    u2f_cert = self_signed(u2f_key)
    point = b"\x04" + u2f_cose[-4] + u2f_cose[-5]
    u2f_message = (
        b"\x00"
        + hashlib.sha256(RP_ID.encode()).digest()
        + hashlib.sha256(u2f_client).digest()
        + u2f_id
        + point
    )
    u2f_stmt = {"sig": u2f_key.sign(u2f_message, ec.ECDSA(hashes.SHA256())), "x5c": [cbor(u2f_cert)], "counter": 0}

    fixtures = [
        fixture("ec2_p256", ec_key, cose_ec(ec_key), "ES256", b"cred-ec2"),
        fixture("rsa2048", rsa_key, cose_rsa(rsa_key), "RS256", b"cred-rsa"),
        fixture(
            "fido_u2f",
            u2f_key,
            u2f_cose,
            "ES256",
            u2f_id,
            policy="discouraged",
            can_verify=False,
            fmt="fido-u2f",
            att_stmt=u2f_stmt,
            bad_base=u2f_case(u2f_key, u2f_cose, u2f_id, u2f_data, u2f_client, u2f_cert),
        ),
    ]

    print(json.dumps({"rp_id": RP_ID, "origin": ORIGIN, "challenge": b64url(CHALLENGE), "fixtures": fixtures}))


if __name__ == "__main__":
    main()
