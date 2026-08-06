// One Knowledge Bank entry. Clicking the row opens a slide-over detail panel
// (the T.O.T.-style .wd drawer) that carries the full lesson, an emoji React
// action, a Helpful toggle (the star), and the discussion — loaded / posted /
// deleted over fetch. Every action returns the same entry state, so there is
// one merge and one failure path rather than several.
export function registerKnowledgeCard(Alpine) {
    Alpine.data('kbCard', (seed) => ({
        ...seed, // id, reactions, mine, stars, starred, commentsCount, emoji
        drawerOpen: false,
        flyout: null,
        thread: null,
        busy: false,
        editing: false,
        editTitle: '',
        editBody: '',
        editFiles: [], // new picks: { file, caption, url }
        editExisting: [], // { id, url, caption, removed }
        editError: '',
        lightboxOpen: false,
        lightboxIndex: 0,

        get reactionTotal() {
            return Object.values(this.reactions).reduce((a, b) => a + b, 0);
        },

        token() {
            return document.querySelector('meta[name=csrf-token]').content;
        },

        // Talks to the server for React / Helpful. Both return the whole entry
        // header (reactions, mine, stars, starred, commentsCount) — merge it.
        async act(url, body = null) {
            if (this.busy) return;
            this.busy = true;
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': this.token(),
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: body ? JSON.stringify(body) : null,
                });
                if (!res.ok) throw new Error(res.status);
                Object.assign(this, await res.json());
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en'
                        ? 'That did not save. Try again.'
                        : 'Tidak berjaya disimpan. Cuba lagi.'
                );
            } finally {
                this.busy = false;
            }
        },

        react(emoji) {
            return this.act(`/app/knowledge-bank/${this.id}/react`, { emoji });
        },

        // The outer icon is the toggle; the flyout is only for choosing. With a
        // reaction already left, pressing the heart takes it back.
        heartPress() {
            if (this.mine.length) {
                this.flyout = null;

                return this.react(this.mine[0]);
            }
            this.flyout = this.flyout === 'react' ? null : 'react';
        },

        helpful() {
            return this.act(`/app/knowledge-bank/${this.id}/star`);
        },

        openLightbox(i) {
            this.lightboxIndex = i;
            this.lightboxOpen = true;
        },

        closeLightbox() {
            this.lightboxOpen = false;
        },

        nextImage() {
            if (this.lightboxIndex < this.attachments.length - 1) this.lightboxIndex++;
        },

        prevImage() {
            if (this.lightboxIndex > 0) this.lightboxIndex--;
        },

        startEdit() {
            this.editTitle = this.title;
            this.editBody = this.body;
            this.editFiles = [];
            this.editExisting = this.attachments.map((a) => ({ ...a, removed: false }));
            this.editError = '';
            this.editing = true;
        },

        addEditFiles(list) {
            for (const f of Array.from(list || [])) {
                const ext = (f.name.split('.').pop() || '').toLowerCase();
                if (!['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) { this.editError = 'type'; break; }
                if (f.size > 8 * 1024 * 1024) { this.editError = 'size'; break; }
                if (this.editExisting.filter((e) => !e.removed).length + this.editFiles.length >= 10) { this.editError = 'max'; break; }
                this.editError = '';
                this.editFiles.push({ file: f, caption: '', url: URL.createObjectURL(f) });
            }
        },

        removeEditFile(i) {
            const f = this.editFiles[i];
            if (f && f.url) URL.revokeObjectURL(f.url);
            this.editFiles.splice(i, 1);
        },

        toggleRemoveExisting(id) {
            const e = this.editExisting.find((x) => x.id === id);
            if (e) e.removed = !e.removed;
        },

        // Switches from a JSON PUT to a multipart POST with `_method=PUT` spoofing —
        // Laravel's Kernel calls enableHttpMethodParameterOverride() unconditionally, so a
        // POST carrying a `_method` field routes as that method (the same mechanism
        // Blade's @method('PUT') directive relies on). PHP only populates $_FILES on a true
        // POST, so a real PUT request can never carry files.
        async saveEdit() {
            if (this.busy || !this.editTitle.trim() || !this.editBody.trim()) return;
            this.busy = true;
            try {
                const fd = new FormData();
                fd.append('_method', 'PUT');
                fd.append('title', this.editTitle);
                fd.append('body', this.editBody);
                this.editFiles.forEach((f, i) => {
                    fd.append('images[]', f.file);
                    fd.append(`captions[${i}]`, f.caption || '');
                });
                this.editExisting.filter((e) => e.removed).forEach((e) => fd.append('remove_images[]', e.id));
                this.editExisting.filter((e) => !e.removed).forEach((e) => {
                    fd.append('reorder[]', e.id);
                    fd.append(`caption_updates[${e.id}]`, e.caption || '');
                });

                const res = await fetch(`/app/knowledge-bank/${this.id}`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.token(), Accept: 'application/json' },
                    body: fd,
                });
                if (!res.ok) throw new Error(res.status);
                const payload = await res.json();
                this.title = payload.title;
                this.body = payload.body;
                this.bodyHtml = document.createElement('div').appendChild(document.createTextNode(payload.body)).parentElement.innerHTML;
                this.attachments = payload.attachments;
                this.editing = false;
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en'
                        ? 'That did not save. Try again.'
                        : 'Tidak berjaya disimpan. Cuba lagi.'
                );
            } finally {
                this.busy = false;
            }
        },

        openDrawer() {
            this.drawerOpen = true;
            if (this.thread === null) {
                this.loadThread();
            }
        },

        async loadThread() {
            try {
                const res = await fetch(`/app/knowledge-bank/${this.id}/comments`, {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok) throw new Error(res.status);
                const payload = await res.json();
                this.thread = payload.comments;
                this.commentsCount = payload.commentsCount;
            } catch (e) {
                this.thread = [];
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en'
                        ? 'Could not load the discussion.'
                        : 'Tidak dapat memuatkan perbincangan.'
                );
            }
        },

        async postComment(body) {
            if (this.busy || !body.trim()) return;
            this.busy = true;
            try {
                const res = await fetch(`/app/knowledge-bank/${this.id}/comments`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': this.token(),
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ body }),
                });
                if (!res.ok) throw new Error(res.status);
                this.commentsCount = (await res.json()).commentsCount;
                await this.loadThread();
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en'
                        ? 'That did not send. Try again.'
                        : 'Tidak berjaya dihantar. Cuba lagi.'
                );
            } finally {
                this.busy = false;
            }
        },

        async removeComment(id) {
            if (this.busy) return;
            this.busy = true;
            try {
                const res = await fetch(`/app/knowledge-bank/comments/${id}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': this.token(), Accept: 'application/json' },
                });
                if (!res.ok) throw new Error(res.status);
                this.commentsCount = (await res.json()).commentsCount;
                this.thread = this.thread.filter((c) => c.id !== id);
            } catch (e) {
                Alpine.store('toast').error(
                    Alpine.store('ui').lang === 'en' ? 'Could not remove that.' : 'Tidak dapat membuang.'
                );
            } finally {
                this.busy = false;
            }
        },
    }));
}
