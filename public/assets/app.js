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
    // La cible de l assertion depend de la page : la ceremonie d administration va a
    // webauthn/assert, celle du second facteur a login/2fa. Le serveur la choisit, le
    // script ne fait que lire ce que la page a pose.
    assert: element.dataset.assert,
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

    // La politique de verification utilisateur vient du serveur, par profil de ceremonie :
    // le script ne peut pas choisir la sienne, sinon un client pourrait demander
    // 'discouraged' sur une credential reglee en 'required'.
    return json(base + 'webauthn/challenge', { headers: { Accept: 'application/json' } });
}

// L objet d attestation (fmt, attStmt, authData) est la propriete attestationObject dans la
// version courante de la spec. getAttestation() etait la forme de Level 1 : elle n'existe pas
// partout, donc un seul des deux noms fait echouer la ceremonie avec un TypeError dans l'autre
// navigateur. Les deux formes sont acceptees, et l'erreur dit ce qui manque.
function attestationBytes(response) {
    const bytes = response.attestationObject !== undefined
        ? response.attestationObject
        : (typeof response.getAttestation === 'function' ? response.getAttestation() : null);

    if (!(bytes instanceof ArrayBuffer) || bytes.byteLength === 0) {
        throw new Error(
            'Objet d attestation indisponible : ni la propriete attestationObject ni la methode '
            + 'getAttestation. Le navigateur a renvoye une ceremonie sans objet CBOR.'
        );
    }

    return bytes;
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
                // Politique du serveur (Policy) : 'required' pour une passkey, 'discouraged'
                // pour une cle U2F utilisee comme second facteur. 'preferred' ne se
                // transforme en 'required' que si la cle a deja fait la verification.
                userVerification: ceremony.policy || 'required',
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
            // L objet CBOR brut (fmt, attStmt, authData) tel que le navigateur l'a recu de
            // l'authenticateur. Pour une cle U2F seule c'est le format fido-u2f : le navigateur
            // construit le map, il n'y a pas de methode attestationBytes.
            attestation: toBase64Url(attestationBytes(credential.response)),
            client_data: toBase64Url(credential.response.clientDataJSON),
        }),
    });

    report(element, 'Credential enregistree : ' + data.credential.credential_id);
    return data;
}

async function assertCredential(element) {
    const { csrf, base, assert } = params(element);
    const ceremony = await getCeremony(element);
    const target = assert || base + 'webauthn/assert';

    // La liste des credentials du compte vient du serveur : une cle qui ne stocke pas la
    // credential en resident (non discoverable) ne peut pas retrouver son identifiant seule,
    // et l assertion echoue si on lui passe une liste vide.
    const allow = (ceremony.allowCredentials || []).map((entry) => ({ id: fromBase64Url(entry.id), type: 'public-key' }));

    // Le container nomme le type de credential par un membre : ici c'est publicKey, comme pour
    // create(). La forme plate hereditee de WebAuthn Level 1 (les options au premier niveau) ne
    // nomme aucun type, et le container la rejette avec NotSupportedError avant meme de parler a
    // l authenticateur.
    const assertion = await navigator.credentials.get({
        publicKey: {
            challenge: fromBase64Url(ceremony.challenge),
            rpId: ceremony.rpId,
            timeout: 60000,
            userVerification: ceremony.policy || 'required',
            allowCredentials: allow,
        },
    });

    if (!assertion || !assertion.response) {
        throw new Error('Ceremonie interrompue.');
    }

    const data = await json(target, {
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

    report(element, 'Cle acceptee.');
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

            assertCredential(element)
                .then((data) => { if (data.redirect) { window.location.href = data.redirect; } })
                .catch((error) => report(element, describe(error), 'err'))
                .finally(() => { loginButton.disabled = false; });
        });
    }
}

document.querySelectorAll('[data-webauthn]').forEach(bind);
