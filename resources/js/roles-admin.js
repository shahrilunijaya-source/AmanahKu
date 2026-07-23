// Members & access roles screen. The role / data-scope dropdowns and the per-member
// permission-override panel used to submit a full form on every change, which reloaded
// the page (this screen also runs inside the Setup embed iframe) and snapped the scroll
// back to the top after each edit. Here we POST the same form over fetch, keep the page
// in place, and surface a small transient toast instead of a top-of-page flash banner.

export function registerRolesAdmin(Alpine) {
    Alpine.data('rolesAdmin', () => ({
        // Submit any of the row forms (role / scope / permission overrides) without a
        // reload. The CSRF token and field values ride in the FormData, so the same
        // endpoints validate identically whether the post is AJAX or a plain submit.
        // Feedback goes through the shared toast store (resources/js/toast.js).
        async save(form) {
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                    body: new FormData(form),
                });
                const data = res.status === 204 ? {} : await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || 'Could not save. Try again.');
                this.$store.toast.success(data.message || 'Saved.');
            } catch (err) {
                this.$store.toast.error(err.message || 'Could not save. Try again.');
            }
        },
    }));
}
