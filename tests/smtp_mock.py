#!/usr/bin/env python3
"""Serveur SMTP mock (TLS) pour verifier le client ecrit a la main.

Lance par tests/run.sh en tache de fond, pendant que tests/smtp_test.php parle avec lui.
Le certificat est auto signe (CN = 127.0.0.1) : le client doit le refuser quand il n'est
pas dans sa liste de confiance, et l'accepter quand on la lui donne.

Cinq connexions, dans l'ordre ou le script PHP les fait :

1. handshake refuse (client sans liste de confiance) ;
2. envoi complet : EHLO, AUTH LOGIN, MAIL FROM, RCPT TO, DATA, QUIT ;
3. mot de passe errone : 535, et le client ne doit pas envoyer de DATA ;
4. serveur qui n'annonce pas AUTH : le client doit refuser avant de s'authentifier ;
5. envoi complet par le service Mailer.

Le message attendu est lu dans le fichier que le script PHP ecrit avant d'envoyer.
"""

import base64
import os
import socket
import ssl
import sys
from datetime import datetime, timedelta, timezone

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec
from cryptography.x509.oid import NameOID

OUT = os.environ.get("QLIP_OUT", "/tmp/opencode/badge")
PORT = int(os.environ.get("QLIP_SMTP_MOCK_PORT", "2525"))
HOST = "127.0.0.1"
USER = "app@qlip.test"
PASS = "mot-de-passe"
RECIPIENT = "test@qlip.test"

failures = 0


def check(label, ok, detail=""):
    global failures

    if not ok:
        failures += 1

    print("  %s   %s%s" % ("OK" if ok else "KO", label, (" : " + detail) if detail else ""))


def show(line):
    return line.decode(errors="replace") if line else "rien"


def make_cert():
    key = ec.generate_private_key(ec.SECP256R1())
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, HOST)])
    cert = (
        x509.CertificateBuilder()
        .subject_name(name)
        .issuer_name(name)
        .public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(datetime.now(timezone.utc) - timedelta(days=1))
        .not_valid_after(datetime.now(timezone.utc) + timedelta(days=2))
        .add_extension(x509.SubjectAlternativeName([x509.DNSName(HOST)]), critical=False)
        .sign(key, hashes.SHA256())
    )

    cert_path = os.path.join(OUT, "smtp_mock_cert.pem")
    key_path = os.path.join(OUT, "smtp_mock_key.pem")

    with open(cert_path, "wb") as handle:
        handle.write(cert.public_bytes(serialization.Encoding.PEM))

    with open(key_path, "wb") as handle:
        handle.write(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))

    return cert_path, key_path


def expected():
    path = os.path.join(OUT, "smtp_expected.txt")

    if not os.path.isfile(path):
        return None

    with open(path, "rb") as handle:
        return handle.read()


def session(conn, index):
    stream = conn.makefile("rb")

    def send(line):
        conn.sendall((line + "\r\n").encode())

    def read():
        line = stream.readline()

        return line.rstrip(b"\r\n") if line else None

    send("220 mock ESMTP Qlip")

    line = read()

    check("EHLO recu (session %d)" % index, line is not None and line.startswith(b"EHLO "), show(line))

    if line is None:
        return

    if index == 4:
        # Pas d'AUTH dans les capacites : le client doit refuser avant de s'authentifier.
        send("250-mock")
        send("250 HELP")

        line = read()

        check("refuse quand AUTH n est pas annonce", line is None or line == b"QUIT", show(line))

        return

    send("250-mock")
    send("250 AUTH LOGIN PLAIN")

    line = read()

    check("AUTH LOGIN demande (session %d)" % index, line == b"AUTH LOGIN", show(line))

    send("334 " + base64.b64encode(b"Username:").decode())

    user = read()

    send("334 " + base64.b64encode(b"Password:").decode())

    password = read()

    check("identifiant en base64", user is not None and base64.b64decode(user).decode() == USER, show(user))

    wanted = PASS if index != 3 else "mauvais"

    check("mot de passe en base64", password is not None and base64.b64decode(password).decode() == wanted, show(password))

    if index == 3:
        send("535 5.7.8 Authentication failed")

        line = read()

        check("pas de DATA apres un echec d authentification", line is None or line == b"QUIT", show(line))

        return

    send("235 2.7.0 Authentication successful")

    line = read()

    check("MAIL FROM (session %d)" % index, line == b"MAIL FROM:<%s>" % USER.encode(), show(line))
    send("250 2.1.5 Sender OK")

    line = read()

    check("RCPT TO (session %d)" % index, line == b"RCPT TO:<%s>" % RECIPIENT.encode(), show(line))
    send("250 2.1.5 Recipient OK")

    line = read()

    check("DATA demande (session %d)" % index, line == b"DATA", show(line))
    send("354 Send it")

    lines = []

    while True:
        line = stream.readline()

        if not line or line == b".\r\n":
            break

        lines.append(line)

    check("corps recu en CRLF", bool(lines) and all(line.endswith(b"\r\n") for line in lines), "%d lignes" % len(lines))

    doubled = [line for line in lines if line.startswith(b"..")]

    check("point en debut de ligne double", bool(doubled), show(doubled[0]) if doubled else "")

    body = b"".join(line[1:] if line.startswith(b"..") else line for line in lines)
    reference = expected()

    # La date n'est pas comparee : elle est gene a l envoi par le script PHP. Le corps recu
    # garde son retour a la ligne final, pas le fichier de reference.
    def strip_date(text):
        return b"\r\n".join(line for line in text.split(b"\r\n") if not line.startswith(b"Date:"))

    check("message recu identique a celui construit", reference is not None and strip_date(body).rstrip(b"\r\n") == strip_date(reference).rstrip(b"\r\n"), "%d octets recus" % len(body))

    send("250 2.0.0 Message accepted")

    line = read()

    check("QUIT (session %d)" % index, line == b"QUIT", show(line))
    send("221 Bye")


def main():
    if not os.path.isdir(OUT):
        os.makedirs(OUT, exist_ok=True)

    # Un marker stale ferait courir le script PHP avant que le serveur ecoute : les
    # connections seraient refusees et le mock attendrait des sessions qui ne viennent pas.
    ready_path = os.path.join(OUT, "smtp_ready")

    if os.path.exists(ready_path):
        os.remove(ready_path)

    cert_path, key_path = make_cert()

    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(cert_path, key_path)

    server = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    server.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    server.bind((HOST, PORT))
    server.listen(5)

    with open(os.path.join(OUT, "smtp_ready"), "w") as ready:
        ready.write(str(PORT))

    for index in range(1, 6):
        conn, _ = server.accept()

        try:
            # Session 1 : le client n'a pas de liste de confiance, le handshake doit echouer
            # avant toute commande.
            tls = context.wrap_socket(conn, server_side=True)

            try:
                session(tls, index)
            finally:
                tls.close()
        except ssl.SSLError as error:
            check("handshake TLS refuse sans liste de confiance", index == 1, "%s : %s" % (index, error))
        finally:
            conn.close()

    server.close()

    print("----")
    print("problemes : %d" % failures)

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
