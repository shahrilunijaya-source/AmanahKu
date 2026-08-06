// Knowledge Bank picture picker — create + edit forms. Images-only variant of
// resources/js/ticket-attach.js: no PDF/doc branches, no clipboard-paste hook (not asked
// for here). Adds two things ticket-attach.js doesn't need: a caption per picture, and
// drag-reorder (via SortableJS, same call shape as resources/js/work-board.js) over BOTH
// newly-picked files and, on the edit form, already-persisted attachments together — the
// two lists are kept separate in state (files vs existing) but rendered as one strip.
//
// Non-file fields (captions, removals, reorder) are NOT serialized to JSON here — they're
// plain named <input>s rendered directly in the blade template via x-for/x-model, so the
// browser's native multipart form submission carries them as real PHP arrays and the
// backend's `'captions' => ['array']`-style validation works unmodified. sync() only
// rebuilds the file <input>'s FileList, since File objects can't be set via x-model.

const ACCEPT_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const MAX_TOTAL = 10;
const MAX_BYTES = 8 * 1024 * 1024;

export function registerKnowledgeAttach(Alpine) {
    Alpine.data('kbAttach', (seed = []) => ({
        files: [], // { file, caption, url }
        existing: seed.map((a) => ({ ...a, removed: false })), // { id, url, caption, removed }
        error: '', // '' | 'type' | 'size' | 'max'

        get activeExisting() {
            return this.existing.filter((e) => !e.removed);
        },

        get total() {
            return this.activeExisting.length + this.files.length;
        },

        addFiles(list) {
            for (const f of Array.from(list || [])) {
                if (!this.tryAdd(f)) break;
            }
            this.sync();
        },

        tryAdd(file) {
            this.error = '';
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (!ACCEPT_EXT.includes(ext)) { this.error = 'type'; return false; }
            if (file.size > MAX_BYTES) { this.error = 'size'; return false; }
            if (this.total >= MAX_TOTAL) { this.error = 'max'; return false; }
            this.files.push({ file, caption: '', url: URL.createObjectURL(file) });
            return true;
        },

        remove(i) {
            const f = this.files[i];
            if (f && f.url) URL.revokeObjectURL(f.url);
            this.files.splice(i, 1);
            this.error = '';
            this.sync();
        },

        removeExisting(id) {
            const e = this.existing.find((x) => x.id === id);
            if (e) e.removed = true;
            this.sync();
        },

        restoreExisting(id) {
            const e = this.existing.find((x) => x.id === id);
            if (e) e.removed = false;
            this.sync();
        },

        // Rebuild the hidden <input type=file> FileList so a plain form POST carries the
        // picked files.
        sync() {
            const dt = new DataTransfer();
            this.files.forEach((f) => dt.items.add(f.file));
            this.$refs.input.files = dt.files;
        },

        // Wire SortableJS on the strip once it's in the DOM (x-init="initSortable($el)").
        // Drag reorders `existing` (persisted attachments) and `files` (new picks) as one
        // combined array so the visual order matches what the user dragged; on submit only
        // `existing`'s surviving order needs to travel (via reorder[]) since `files`' order
        // is already correct positionally in the FileList sync() just rebuilt.
        initSortable(el) {
            window.Sortable.create(el, {
                animation: 150,
                draggable: '[data-kb-tile]',
                onEnd: (evt) => {
                    const combined = [...this.activeExisting.map((e) => ({ kind: 'existing', id: e.id })), ...this.files.map((_, i) => ({ kind: 'file', i }))];
                    const [moved] = combined.splice(evt.oldIndex, 1);
                    combined.splice(evt.newIndex, 0, moved);

                    const newExisting = combined.filter((c) => c.kind === 'existing').map((c) => this.existing.find((e) => e.id === c.id));
                    const newFiles = combined.filter((c) => c.kind === 'file').map((c) => this.files[c.i]);
                    this.existing = [...newExisting, ...this.existing.filter((e) => e.removed)];
                    this.files = newFiles;
                    this.sync();
                },
            });
        },
    }));
}
