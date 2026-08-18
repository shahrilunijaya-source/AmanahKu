export function registerPasskeyManager(Alpine) {
    Alpine.data('passkeyManager', () => ({
        name: '', busy: false, msg: '', ok: false,
        async add() {
            const en = (Alpine.store('ui')?.lang ?? 'en') === 'en';
            this.msg = '';
            if (!window.Passkey || !window.Passkey.supported()) { this.ok = false; this.msg = en ? 'This browser does not support passkeys.' : 'Pelayar ini tidak menyokong passkey.'; return; }
            if (!this.name.trim()) { this.ok = false; this.msg = en ? 'Give the passkey a name first.' : 'Beri passkey ini nama dahulu.'; return; }
            this.busy = true;
            try {
                const csrf = document.querySelector('meta[name=csrf-token]').content;
                await window.Passkey.register(this.name.trim(), csrf);
                this.ok = true; this.msg = en ? 'Passkey added.' : 'Passkey ditambah.';
                setTimeout(() => window.location.reload(), 700);
            } catch (e) {
                this.ok = false; this.msg = e.message || (en ? 'Could not add the passkey.' : 'Tidak dapat menambah passkey.');
            } finally {
                this.busy = false;
            }
        },
    }));
}
