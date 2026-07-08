// WebAuthn passkey ceremonies (register + login) against laravel/passkeys endpoints.
// The server emits / expects the standard webauthn-lib JSON shape (base64url-encoded
// binary fields), so we convert base64url <-> ArrayBuffer by hand — no npm dependency.

const b64urlToBuf = (value) => {
    const pad = value.length % 4 === 0 ? '' : '='.repeat(4 - (value.length % 4));
    const base64 = (value + pad).replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
};

const bufToB64url = (buffer) => {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

const supported = () => typeof window.PublicKeyCredential !== 'undefined' && !!navigator.credentials;

// Password step-up for passkey management. The register/delete routes sit behind Fortify's
// password.confirm middleware. The WebAuthn ceremony is XHR, so a 423/redirect mid-ceremony
// can't be followed by the browser — instead we confirm the password up front via JSON:
//   GET  /user/confirmed-password-status → { confirmed: bool }
//   POST /user/confirm-password { password } → 201/200 on success, 422 on a wrong password.
async function ensurePasswordConfirmed(csrf) {
    const { confirmed } = await getJson('/user/confirmed-password-status');
    if (confirmed) return;

    const password = window.prompt('Confirm your password to manage passkeys:');
    if (password === null || password === '') {
        throw new Error('Password confirmation is required to manage passkeys.');
    }

    try {
        await postJson('/user/confirm-password', { password }, csrf);
    } catch (e) {
        throw new Error('Password confirmation failed. Please check your password and try again.');
    }
}

async function postJson(url, body, csrf) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.message || ('Request failed (' + res.status + ')'));
    }
    return res.status === 204 ? {} : res.json();
}

async function getJson(url) {
    const res = await fetch(url, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!res.ok) throw new Error('Could not start the passkey ceremony (' + res.status + ').');
    return res.json();
}

// Register a new passkey for the signed-in user.
async function register(name, csrf) {
    if (!supported()) throw new Error('This browser does not support passkeys.');

    // Step-up: confirm the password before the protected register route runs.
    await ensurePasswordConfirmed(csrf);

    const { options } = await getJson('/user/passkeys/options');

    const publicKey = {
        ...options,
        challenge: b64urlToBuf(options.challenge),
        user: { ...options.user, id: b64urlToBuf(options.user.id) },
        excludeCredentials: (options.excludeCredentials || []).map((c) => ({ ...c, id: b64urlToBuf(c.id) })),
    };

    const cred = await navigator.credentials.create({ publicKey });

    const credential = {
        id: cred.id,
        rawId: bufToB64url(cred.rawId),
        type: cred.type,
        authenticatorAttachment: cred.authenticatorAttachment || undefined,
        clientExtensionResults: cred.getClientExtensionResults(),
        response: {
            clientDataJSON: bufToB64url(cred.response.clientDataJSON),
            attestationObject: bufToB64url(cred.response.attestationObject),
            transports: cred.response.getTransports ? cred.response.getTransports() : [],
        },
    };

    return postJson('/user/passkeys', { name, credential }, csrf);
}

// Sign in with a passkey (guest).
async function login(csrf) {
    if (!supported()) throw new Error('This browser does not support passkeys.');

    const { options } = await getJson('/passkeys/login/options');

    const publicKey = {
        ...options,
        challenge: b64urlToBuf(options.challenge),
        allowCredentials: (options.allowCredentials || []).map((c) => ({ ...c, id: b64urlToBuf(c.id) })),
    };

    const cred = await navigator.credentials.get({ publicKey });

    const credential = {
        id: cred.id,
        rawId: bufToB64url(cred.rawId),
        type: cred.type,
        authenticatorAttachment: cred.authenticatorAttachment || undefined,
        clientExtensionResults: cred.getClientExtensionResults(),
        response: {
            clientDataJSON: bufToB64url(cred.response.clientDataJSON),
            authenticatorData: bufToB64url(cred.response.authenticatorData),
            signature: bufToB64url(cred.response.signature),
            userHandle: cred.response.userHandle ? bufToB64url(cred.response.userHandle) : null,
        },
    };

    return postJson('/passkeys/login', { credential }, csrf);
}

window.Passkey = { register, login, supported };
