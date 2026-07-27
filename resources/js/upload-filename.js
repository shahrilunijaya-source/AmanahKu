// Hostinger's WAF (ModSecurity) inspects the `filename=` part of multipart uploads and
// blocks any filename containing a single quote, treating it as SQL injection. The block
// happens at the web-server layer before PHP runs, so Laravel never sees the request and
// cannot log it. The only fix is to rewrite the filename in the browser before it is sent.
// This runs globally: one delegated listener covers every file input, present now or
// added later, without each feature having to remember to sanitise its own.

// Pure so it is unit-testable without a DOM. Keeps only safe ASCII, folds everything else
// to `-`, and preserves the extension so uploads keep the type the user expects.
export function safeUploadName(name) {
    const lastDot = name.lastIndexOf('.');
    const hasExt = lastDot > 0 && lastDot < name.length - 1;
    const stem = hasExt ? name.slice(0, lastDot) : name;
    const ext = hasExt ? name.slice(lastDot + 1) : '';

    const clean = (part) => part
        .replace(/[^A-Za-z0-9._-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^[-.]+|[-.]+$/g, '');

    let cleanStem = clean(stem).slice(0, 100);
    if (!cleanStem) cleanStem = 'upload';

    if (!hasExt) return cleanStem;

    const cleanExt = clean(ext);
    return cleanExt ? `${cleanStem}.${cleanExt}` : cleanStem;
}

// One delegated `change` listener for the whole document, so file inputs added after
// page load (Alpine components, modals, etc.) are covered without extra wiring. Registered
// on the capture phase — see the comment on addEventListener below for why that matters.
export function registerUploadFilenameSanitizer() {
    // capture: true is load-bearing, not stylistic. Some inputs (e.g. the feedback
    // attach widget) bind their own `change` handler directly on the element and copy
    // the FileList into their own state during the bubble phase. If this listener also
    // ran on the bubble phase, it would fire AFTER that copy, so the element's handler
    // would grab the original (unsafe) names and the rewrite here would be too late.
    // Capture runs document-first, before any element-level handler, so downstream code
    // always sees already-sanitized files. Do not "simplify" this back to bubbling.
    document.addEventListener('change', (e) => {
        const input = e.target;
        if (!input.matches || !input.matches('input[type="file"]') || !input.files || !input.files.length) return;

        try {
            const files = Array.from(input.files);
            const renamed = files.map((f) => {
                const safe = safeUploadName(f.name);
                return safe === f.name ? f : new File([f], safe, { type: f.type, lastModified: f.lastModified });
            });
            if (renamed.every((f, i) => f === files[i])) return; // already safe, don't touch it

            const dt = new DataTransfer();
            renamed.forEach((f) => dt.items.add(f));
            input.files = dt.files;
        } catch {
            // Fail open: if DataTransfer/File construction misbehaves, leave the input
            // untouched rather than risk blocking the submit.
        }
    }, { capture: true });
}
