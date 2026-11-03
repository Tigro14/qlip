// WebAuthn/FIDO2 cote navigateur : enregistrement d'une credential (YubiKey) et
// assertion. Les tampons sont envoyes en base64url, comme le verificateur PHP.

const toBase64Url = (value) => {
    const bytes = value instanceof ArrayBuffer ? new Uint8Array(value) : new Uint8Array(value);
    let text = '';

    for (const byte of bytes) {
        text += String.fromCharCode(byte);
    }

    return btoa(text).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

const fromBase64Url = (value) => {
    const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
    const binary = atob(padded);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes;
};

const params = (element) => ({
    csrf: element.dataset.csrf,
    rpId: element.dataset.rp,
    base: element.dataset.base,
});

async function json(url, options) {
    const response = await fetch(url, options);
    const data = await response.json().catch(() => ({ error: 'reponse illisible' }));

    if (!response.ok || data.error) {
        throw new Error(data.error || 'erreur HTTP ' + response.status);
    }

    return data;
}

async function getCeremony(element) {
    const { base } = params(element);

    return json(base + 'webauthn/challenge', { headers: { Accept: 'application/json' } });
}

function report(element, message, level) {
    const box = element.querySelector('.result');
    box.textContent = message;
    box.className = 'result flash flash-' + (level || 'ok');
}

// Le nom de la DOMException est utile : NotAllowedError est le message generique de la
// ceremonie qui ne s est pas terminee (annulation, timeout, authentifiant choisi puis ecarte,
// origine qui ne correspond pas au RP ID), TypeError veut dire que les options sont mal
// formees avant meme de parler a l authenticateur.
function describe(error) {
    return (error.name || 'erreur') + ' : ' + error.message;
}

async function registerCredential(element) {
    const { csrf, base } = params(element);
    const ceremony = await getCeremony(element);

    // Le champ id de PublicKeyCredentialUserEntity est un tampon, pas une chaine : l'ID du
    // compte arrive en base64url et se decode ici. Passe tel quel en JSON, il reste une
    // chaine et le navigateur refuse la ceremony.
    const user = element.dataset.user ? JSON.parse(element.dataset.user) : null;
    const entity = user ? { id: fromBase64Url(user.id), name: user.name, displayName: user.displayName } : null;

    const credential = await navigator.credentials.create({
        publicKey: {
            rp: { id: ceremony.rpId, name: 'Qlip' },
            user: entity || { id: new Uint8Array(16), name: 'qlip', displayName: 'qlip' },
            challenge: fromBase64Url(ceremony.challenge),
            pubKeyCredParams: [
                { type: 'public-key', alg: -7 },   // ES256
                { type: 'public-key', alg: -257 }, // RS256
                { type: 'public-key', alg: -8 },   // EdDSA
            ],
            timeout: 60000,
            attestation: 'none',
            authenticatorSelection: {
                residentKey: 'preferred',
                userVerification: 'required',
                // Pas de liste de transports : le hint est optionnel, et une YubiKey lue par
                // le lecteur de cartes (smart-card) ou par NFC serait ecartee par une liste
                // fermee. Le navigateur choisit le transport, et il doit ignorer les valeurs
                // qu il ne connait pas.
            },
        },
    });

    if (!credential || !credential.response) {
        throw new Error('Ceremonie interrompue.');
    }

    const data = await json(base + 'webauthn/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf: csrf,
            credential_id: toBase64Url(credential.rawId),
            // getAttestation() renvoie l objet CBOR brut (fmt, attStmt, authData) tel que le
            // navigateur l a recu de l authenticateur. Il n y a pas de methode attestationBytes.
            attestation: toBase64Url(credential.response.getAttestation()),
            client_data: toBase64Url(credential.response.clientDataJSON),
        }),
    });

    report(element, 'Credential enregistree : ' + data.credential.credential_id);
    return data;
}

async function assertCredential(element, allowCredentials) {
    const { csrf, base } = params(element);
    const ceremony = await getCeremony(element);

    const assertion = await navigator.credentials.get({
        rpId: ceremony.rpId,
        challenge: fromBase64Url(ceremony.challenge),
        timeout: 60000,
        userVerification: 'required',
        allowCredentials: (allowCredentials && allowCredentials.length > 0)
            ? allowCredentials.map((entry) => ({ id: fromBase64Url(entry.id), type: 'public-key' }))
            : [],
    });

    if (!assertion || !assertion.response) {
        throw new Error('Ceremonie interrompue.');
    }

    const data = await json(base + 'webauthn/assert', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf: csrf,
            credential_id: toBase64Url(assertion.rawId),
            auth_data: toBase64Url(assertion.response.authenticatorData),
            client_data: toBase64Url(assertion.response.clientDataJSON),
            signature: toBase64Url(assertion.response.signature),
        }),
    });

    report(element, 'YubiKey acceptee.');
    return data;
}

function bind(element) {
    const registerButton = element.querySelector('[data-action="register"]');
    const loginButton = element.querySelector('[data-action="assert"]');

    if (registerButton) {
        registerButton.addEventListener('click', () => {
            registerButton.disabled = true;
            registerCredential(element)
                .catch((error) => report(element, describe(error), 'err'))
                .finally(() => { registerButton.disabled = false; });
        });
    }

    if (loginButton) {
        loginButton.addEventListener('click', () => {
            loginButton.disabled = true;

            assertCredential(element, [])
                .then((data) => { if (data.redirect) { window.location.href = data.redirect; } })
                .catch((error) => report(element, describe(error), 'err'))
                .finally(() => { loginButton.disabled = false; });
        });
    }
}

document.querySelectorAll('[data-webauthn]').forEach(bind);
