// Feedback modal attachments. Lets a reporter paste a screenshot straight into the
// Details area, or attach up to six images/PDFs/documents, with live thumbnail previews
// and per-file removal. The real <input type="file" name="attachments[]"> stays hidden;
// we keep its FileList in sync from our own array via a DataTransfer so the plain form
// POST carries exactly what the previews show. Client checks mirror the server rules
// (mimes + 8 MB each + max 6) — the server remains the source of truth.

const ACCEPT_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv'];
const MAX_FILES = 6;
const MAX_BYTES = 8 * 1024 * 1024;

export function registerFeedbackAttach(Alpine) {
    Alpine.data('feedbackAttach', () => ({
        files: [],      // { file, isImage, url }
        error: '',      // '' | 'type' | 'size' | 'max' — blade renders the bilingual message

        // File picker (change) and drop both land here.
        addFiles(list) {
            for (const f of Array.from(list || [])) {
                if (!this.tryAdd(f)) break;
            }
            this.sync();
        },

        // Clipboard paste inside the Details textarea: pull image blobs out and attach them.
        // Non-image pastes (plain text) fall through untouched so typing still works.
        onPaste(e) {
            const items = (e.clipboardData && e.clipboardData.items) || [];
            let added = false;
            for (const it of items) {
                if (it.kind !== 'file' || !it.type.startsWith('image/')) continue;
                const blob = it.getAsFile();
                if (!blob) continue;
                const ext = (blob.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                const named = new File([blob], `screenshot-${Date.now()}.${ext}`, { type: blob.type });
                if (this.tryAdd(named)) added = true;
            }
            if (added) {
                e.preventDefault();  // don't dump the image's binary into the textarea
                this.sync();
            }
        },

        tryAdd(file) {
            this.error = '';
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (!ACCEPT_EXT.includes(ext)) { this.error = 'type'; return false; }
            if (file.size > MAX_BYTES) { this.error = 'size'; return false; }
            if (this.files.length >= MAX_FILES) { this.error = 'max'; return false; }
            const isImage = file.type.startsWith('image/');
            this.files.push({ file, isImage, url: isImage ? URL.createObjectURL(file) : '' });
            return true;
        },

        remove(i) {
            const f = this.files[i];
            if (f && f.url) URL.revokeObjectURL(f.url);
            this.files.splice(i, 1);
            this.error = '';
            this.sync();
        },

        // Rebuild the hidden input's FileList from our array so the form submits it verbatim.
        sync() {
            const dt = new DataTransfer();
            this.files.forEach((f) => dt.items.add(f.file));
            this.$refs.input.files = dt.files;
        },

        ext(name) {
            return (name.split('.').pop() || '').toUpperCase();
        },
    }));
}
