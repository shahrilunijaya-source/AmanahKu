{{-- Inline add without a page reload: intercept forms marked data-ajax, POST them,
     append the server-rendered row to the target list, then reset the form so the
     next entry is one keystroke away. Kills the "add → full refresh → re-scroll →
     re-open" loop that made bulk entry painful.

     Shared by the Projects register and Timesheet Setup — one copy, because two
     copies is how the last one rotted. --}}
<script>
    (function () {
        function bump(sel, by) {
            var el = sel && document.querySelector(sel);
            if (el) { el.textContent = (parseInt(el.textContent, 10) || 0) + by; }
        }
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (! form || ! form.matches || ! form.matches('form[data-ajax]')) { return; }
            e.preventDefault();
            send(form, false);
        });

        // confirmed: the person already said yes to the "similar projects exist" question,
        // so the server lets a close-but-not-identical name through.
        function send(form, confirmed) {
            var target = document.querySelector(form.dataset.target);
            var btn = form.querySelector('[type=submit]');
            if (btn) { btn.disabled = true; }
            var body = new FormData(form);
            if (confirmed) { body.append('confirm_duplicate', '1'); }

            fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: body,
            }).then(function (r) {
                return r.json().then(function (d) { return { ok: r.ok, d: d }; }, function () { return { ok: r.ok, d: {} }; });
            }).then(function (res) {
                if (btn) { btn.disabled = false; }
                var dupe = res.d && res.d.errors && res.d.errors.confirm_duplicate;
                if (! res.ok && dupe && ! confirmed) {
                    if (window.confirm(dupe[0])) { send(form, true); }
                    return;
                }
                if (! res.ok) {
                    alert(res.d && res.d.message ? res.d.message : 'Could not save — check the fields.');
                    return;
                }
                if (target && res.d.html) {
                    var empty = target.querySelector('[data-empty]');
                    if (empty) { empty.remove(); }
                    target.insertAdjacentHTML('beforeend', res.d.html);
                    var added = target.lastElementChild;
                    if (window.Alpine && added) { window.Alpine.initTree(added); }
                }
                bump(res.d.count_sel, 1);
                // Lets a screen react to its own adds, e.g. the Projects "appears in Track" dialog.
                window.dispatchEvent(new CustomEvent('row-added', { detail: res.d }));
                form.reset();
                var first = form.querySelector('input[name=name]');
                if (first) { first.focus(); }
            }).catch(function () {
                if (btn) { btn.disabled = false; }
                alert('Network error — not saved.');
            });
        }
    })();
</script>
