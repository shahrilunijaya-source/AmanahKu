// CR-29 Friday sign-off widget: one tap, then swap the prompt for the "done"
// state in place, no page reload (house rule: no full-page reloads).
export function registerFridaySignOff(Alpine) {
    Alpine.data('fridaySignOff', (postUrl) => ({
        mood: null,
        busy: false,
        async signOff() {
            if (this.busy || !this.mood) {
                return;
            }
            this.busy = true;
            const winVal = this.$refs.frWin.value.trim();
            const shareVal = this.$refs.frShare.checked;
            try {
                const res = await fetch(postUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ mood: this.mood, win: winVal || null, share: shareVal }),
                });
                if (!res.ok) {
                    return;
                }
                this.$refs.frPrompt.replaceWith(this.doneMarkup(winVal, shareVal));
            } finally {
                this.busy = false;
            }
        },
        doneMarkup(winVal, shareVal) {
            const done = document.createElement('div');
            done.setAttribute('data-friday-done', '');
            const box = document.createElement('div');
            box.className = 'uj-fr-done';
            box.textContent = 'Signed off. Company mood lands here at 5 PM once five people have answered.';
            done.appendChild(box);
            if (winVal !== '') {
                done.appendChild(this.winRowMarkup(winVal, shareVal));
            }

            return done;
        },
        winRowMarkup(winVal, shareVal) {
            const wins = document.createElement('div');
            wins.className = 'uj-fr-wins';
            const row = document.createElement('div');
            row.className = 'uj-fr-winrow';
            row.setAttribute('data-friday-my-win', '');
            const who = document.createElement('span');
            who.className = 'who';
            who.textContent = 'You';
            const text = document.createElement('span');
            text.textContent = winVal;
            row.append(who, text);
            if (!shareVal) {
                const priv = document.createElement('span');
                priv.className = 'priv';
                priv.textContent = 'private';
                row.appendChild(priv);
            }
            wins.appendChild(row);

            return wins;
        },
    }));
}
