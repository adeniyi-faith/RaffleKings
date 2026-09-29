/**
 * Share a message: the phone's own share sheet when there is one,
 * otherwise WhatsApp. When the message already contains its link (the
 * invite texts below), leave `url` out so the link isn't added twice.
 */
export function shareLink(text, url = null) {
    if (navigator.share) {
        return navigator.share(url ? { text, url } : { text }).catch(() => {});
    }

    window.open(whatsappUrl(text, url), '_blank', 'noopener');

    return Promise.resolve();
}

export function whatsappUrl(text, url = null) {
    return `https://wa.me/?text=${encodeURIComponent(url ? `${text} ${url}` : text)}`;
}

// The raffle's prize as people say it: "₦500,000" (a plain number gets
// ₦ and commas), "iPhone 16", or the raffle's name if there's no prize.
function prizeName(prize, fallback) {
    const text = String(prize ?? '').trim();
    if (text === '') return fallback || 'RaffleKings';
    const digits = text.replace(/[₦,\s]/g, '').replace(/^N(?=\d)/i, '');

    return /^\d+(\.\d+)?$/.test(digits) ? `₦${Number(digits).toLocaleString('en-NG')}` : text;
}

/** The Team Up invite message, with its link inside. */
export function teamInviteText({ prize, raffleTitle, url, bonusEntries = 1 }) {
    const name = prizeName(prize, raffleTitle);
    const reward = bonusEntries === 1 ? 'a free bonus entry' : `${bonusEntries} free bonus entries`;

    return [
        `Hi! I’m inviting you to join my RaffleKings team for the ${name} draw.`,
        `Once the team is complete, everyone on the team receives ${reward}.`,
        `Join my team here:\n${url}`,
        `You could be one of the people sharing in the ${name} opportunity.`,
    ].join('\n\n');
}

/** The "help me unlock" message, with its link inside. */
export function unlockInviteText({ prize, raffleTitle, url, points }) {
    const name = prizeName(prize, raffleTitle);

    return [
        `🎁 Get ${points} points and help unlock a FREE ${name} draw entry.`,
        `I’m participating in RaffleKings and I’m currently working towards a free entry into the ${name} draw.`,
        `Tap my link to participate. You get ${points} points, and your participation helps unlock my free entry.`,
        `👉 ${url}`,
    ].join('\n\n');
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
