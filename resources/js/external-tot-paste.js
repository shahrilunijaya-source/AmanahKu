/**
 * "Paste & fill" for the External TOT post form — turns a raw forwarded invite
 * (WhatsApp/email text) into prefilled form fields. Regex only, no AI call: this
 * is a fast follow to the plain manual form, scoped to the common Date:/Time:/
 * Venue:-labelled invite shape. Anything it can't confidently read is left
 * blank rather than guessed — a blank field costs nothing, a wrong one costs
 * someone registering for the wrong day. The poster still reviews every field
 * before Post event; this only saves the typing.
 */

const MONTHS = [
    'january', 'february', 'march', 'april', 'may', 'june',
    'july', 'august', 'september', 'october', 'november', 'december',
];

/**
 * "Friday, 28th August 2026" / "August 28, 2026" → "2026-08-28", or '' if the
 * text doesn't contain a day + month-name + year triple. Deliberately not
 * `new Date(raw)`: JS engines disagree on which informal date strings they
 * accept, so this reads day/month/year out with regex instead of trusting a
 * parser that could silently misread one format and land on the wrong date.
 */
export function parseHumanDate(raw) {
    if (!raw) return '';
    const s = raw.replace(/(\d+)(st|nd|rd|th)\b/i, '$1');

    let m = s.match(/(\d{1,2})\s+([A-Za-z]+)\s*,?\s*(\d{4})/);
    let day, monthName, year;
    if (m) {
        [, day, monthName, year] = m;
    } else {
        m = s.match(/([A-Za-z]+)\s+(\d{1,2})\s*,?\s*(\d{4})/);
        if (!m) return '';
        [, monthName, day, year] = m;
    }

    const monthIdx = MONTHS.indexOf(monthName.toLowerCase());
    if (monthIdx === -1) return '';

    return `${year}-${String(monthIdx + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/** A `Label: value` line's value, first match, decorative leading symbols trimmed off. */
function labelValue(text, label) {
    const m = text.match(new RegExp(`^\\s*${label}\\s*:\\s*(.+)$`, 'im'));
    return m ? m[1].trim().replace(/^[^\w(]+/, '').trim() : '';
}

/** forms.gle / docs.google.com/forms / google.com/forms → registration; maps.app.goo.gl / google.com/maps → venue map. */
function classifyUrl(url) {
    let host, path;
    try {
        const u = new URL(url);
        host = u.hostname.replace(/^www\./, '');
        path = u.pathname;
    } catch {
        return null;
    }

    if (host === 'forms.gle') return 'registration_url';
    if (host === 'docs.google.com' && path.startsWith('/forms')) return 'registration_url';
    if (host === 'google.com' && path.startsWith('/forms')) return 'registration_url';
    if (host === 'maps.app.goo.gl') return 'venue_map_url';
    if (host === 'google.com' && path.startsWith('/maps')) return 'venue_map_url';

    return null;
}

/**
 * First non-empty line, or — when that line reads as a greeting ("Hi Partner,") —
 * the "invite you to X," clause from the paragraph between the greeting and an
 * "Event Details"-style marker. Falls back to that paragraph's first line when
 * the "invite you to" phrasing isn't there.
 */
function extractTitle(text) {
    const lines = text.split('\n').map((l) => l.trim()).filter(Boolean);
    if (lines.length === 0) return '';

    const isGreeting = /^(hi|hello|dear|greetings)\b.{0,40}$/i.test(lines[0]);
    if (!isGreeting) return lines[0];

    const detailsIdx = lines.findIndex((l) => /event details/i.test(l));
    const body = lines.slice(1, detailsIdx > 0 ? detailsIdx : lines.length).join(' ');

    const invited = body.match(/invite you to ([^,]+),/i);
    if (invited) return invited[1].trim();

    return lines[1] ?? lines[0];
}

/**
 * Parse a forwarded invite into the External TOT form's field names. Every key
 * is always present (possibly '') so a caller can assign the whole object into
 * the form without checking each one first.
 */
export function parseExternalTotInvite(text) {
    const result = {
        title: extractTitle(text),
        event_date: parseHumanDate(labelValue(text, 'Date')),
        time_label: labelValue(text, 'Time'),
        venue: labelValue(text, 'Venue'),
        venue_map_url: '',
        registration_url: '',
    };

    const urls = text.match(/https?:\/\/[^\s|)\]]+/g) ?? [];
    for (const url of urls) {
        const kind = classifyUrl(url);
        if (kind && !result[kind]) result[kind] = url;
    }

    return result;
}

export function registerExternalTotPaste(Alpine) {
    Alpine.data('extPasteFill', () => ({
        pasteText: '',

        fill() {
            if (!this.pasteText.trim()) return;

            const parsed = parseExternalTotInvite(this.pasteText);
            const form = this.$refs.extForm;
            for (const [name, value] of Object.entries(parsed)) {
                if (!value) continue;
                const field = form.elements.namedItem(name);
                if (field) field.value = value;
            }
        },

        /**
         * Reset the form back to its rendered defaults, then — if editing an existing
         * event — refill it from that event's own fields. Bound to a $watch, not an
         * x-effect: an effect fires immediately on mount too, which would wipe out the
         * old()-value prefill Blade already renders after a failed post.
         */
        sync(event) {
            this.$refs.extForm.reset();
            this.pasteText = '';
            if (!event) return;

            for (const [name, value] of Object.entries(event)) {
                const field = this.$refs.extForm.elements.namedItem(name);
                if (field) field.value = value ?? '';
            }
        },
    }));
}
