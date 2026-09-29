/** Share a link: the phone's own share sheet when there is one, otherwise WhatsApp. */
export function shareLink(text, url) {
    if (navigator.share) {
        return navigator.share({ text, url }).catch(() => {});
    }

    window.open(`https://wa.me/?text=${encodeURIComponent(`${text} ${url}`)}`, '_blank', 'noopener');

    return Promise.resolve();
}

export function whatsappUrl(text, url) {
    return `https://wa.me/?text=${encodeURIComponent(`${text} ${url}`)}`;
}

/** A Team Up invite the customer opened before buying their ticket (joined after checkout). */
export function rememberTeam(code, raffleId) {
    try {
        sessionStorage.setItem('rk_team', JSON.stringify({ code, raffleId: Number(raffleId) }));
    } catch {
        // private mode: they can still join from the team page
    }
}

export function pendingTeam(raffleId) {
    try {
        const saved = JSON.parse(sessionStorage.getItem('rk_team') || 'null');

        return saved && saved.raffleId === Number(raffleId) ? saved.code : null;
    } catch {
        return null;
    }
}

export function forgetTeam() {
    try {
        sessionStorage.removeItem('rk_team');
    } catch {
        // nothing to forget
    }
}
