import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertCircle, ArrowLeft, ArrowRight, Shuffle, X } from 'lucide-react';
import { useTicketPriceQuote } from '../../hooks/useTicketPriceQuote';
import { formatNaira } from '../../lib/format';
import { echoOrNull } from '../../lib/echo';
import { track } from '../../lib/analytics';
import { guestToken, holdNumbers } from '../../lib/numberHolds';

export default function SelectNumbers({ raffle, qty, takenNumbers, heldNumbers = [], maxTickets, preselected = [] }) {
    const [selected, setSelected] = useState(preselected);
    const [taken, setTaken] = useState(takenNumbers);
    // Numbers another player is holding for a few minutes (not sold yet). A guest's
    // own holds are filtered out by the first refresh below, which sends their token.
    const [held, setHeld] = useState(heldNumbers);
    const [confirming, setConfirming] = useState(false);
    const [notice, setNotice] = useState(null);
    const noticeTimer = useRef(null);
    const takenSet = useMemo(() => new Set(taken), [taken]);
    const heldSet = useMemo(() => new Set(held), [held]);
    const { quote } = useTicketPriceQuote(raffle.id, qty);

    const numbers = useMemo(() => Array.from({ length: maxTickets }, (_, i) => i + 1), [maxTickets]);

    function flash(message) {
        setNotice(message);
        clearTimeout(noticeTimer.current);
        noticeTimer.current = setTimeout(() => setNotice(null), 4000);
    }

    useEffect(() => () => clearTimeout(noticeTimer.current), []);

    // Item 46: taken numbers used to be a snapshot from when the page
    // opened, so a number someone else bought a minute ago still looked
    // free until checkout failed. Every purchase now broadcasts the numbers
    // it took; with no live connection, the list is re-read every 10
    // seconds instead. A full re-read every minute catches anything missed.
    useEffect(() => {
        function markTaken(newlyTaken) {
            if (! newlyTaken?.length) {
                return;
            }

            setTaken((prev) => Array.from(new Set([...prev, ...newlyTaken])));
            setSelected((prev) => {
                const lost = prev.filter((n) => newlyTaken.includes(n));

                if (lost.length) {
                    flash(
                        lost.length === 1
                            ? `Number ${lost[0]} was just taken by someone else. Please pick another.`
                            : `Numbers ${lost.join(', ')} were just taken by someone else. Please pick others.`,
                    );
                }

                return prev.filter((n) => ! newlyTaken.includes(n));
            });
        }

        function syncHeld(heldByOthers) {
            const heldNow = (heldByOthers || []).map(Number);
            setHeld(heldNow);
            setSelected((prev) => {
                const lost = prev.filter((n) => heldNow.includes(n));

                if (lost.length) {
                    flash(
                        lost.length === 1
                            ? `Number ${lost[0]} is being held by another player right now. Please pick another.`
                            : `Numbers ${lost.join(', ')} are being held by other players right now. Please pick others.`,
                    );
                }

                return prev.filter((n) => ! heldNow.includes(n));
            });
        }

        function refetch() {
            fetch(`/api/raffles/${raffle.id}/tickets`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Guest-Token': guestToken() },
            })
                .then((res) => (res.ok ? res.json() : null))
                .then((data) => {
                    if (data) {
                        markTaken(data.taken_numbers);
                        syncHeld(data.held_numbers);
                    }
                })
                .catch(() => {});
        }

        refetch();

        const echo = echoOrNull();
        const interval = setInterval(refetch, echo ? 60000 : 10000);

        if (echo) {
            echo.channel(`raffle.${raffle.id}`).listen('.tickets.updated', (payload) => markTaken(payload.taken_numbers));
        }

        return () => {
            clearInterval(interval);
            if (echo) echo.leave(`raffle.${raffle.id}`);
        };
    }, [raffle.id]);

    function toggle(n) {
        if (takenSet.has(n)) {
            flash(`Number ${n} is already taken.`);
            return;
        }

        if (heldSet.has(n)) {
            flash(`Number ${n} is being held by another player right now. It may be free again in a few minutes.`);
            return;
        }

        if (selected.includes(n)) {
            setSelected(selected.filter((x) => x !== n));
            return;
        }

        if (selected.length >= qty) {
            // Item 46: this used to do nothing at all.
            flash(
                `You're buying ${qty} ticket${qty === 1 ? '' : 's'}, so you can pick ${qty} number${qty === 1 ? '' : 's'}. `
                    + 'Tap a yellow number to swap it, or go back to buy more tickets.',
            );
            return;
        }

        setNotice(null);
        setSelected([...selected, n]);
    }

    function quickPick() {
        const available = numbers.filter((n) => ! takenSet.has(n) && ! heldSet.has(n));

        for (let i = available.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [available[i], available[j]] = [available[j], available[i]];
        }

        setSelected(available.slice(0, qty));
    }

    function clearAll() {
        setSelected([]);
    }

    async function confirm() {
        if (confirming) {
            return;
        }

        setConfirming(true);

        // Hold the numbers for a few minutes so nobody else can pay for them while
        // this player signs in and pays. If someone got there first, say so now.
        const result = await holdNumbers(raffle.id, selected);

        if (result.status === 'unavailable') {
            const gone = result.unavailable;
            setTaken((prev) => Array.from(new Set([...prev, ...result.sold])));
            setHeld((prev) => Array.from(new Set([...prev, ...result.held])));
            setSelected((prev) => prev.filter((n) => ! gone.includes(n)));
            flash(
                gone.length === 1
                    ? `Number ${gone[0]} was just taken. Please pick another.`
                    : `Numbers ${gone.join(', ')} were just taken. Please pick others.`,
            );
            setConfirming(false);
            return;
        }

        // (If the server could not be reached we carry on; checkout tries again.)
        const params = new URLSearchParams({
            raffle_id: raffle.id,
            qty: String(qty),
            numbers: selected.join(','),
        });

        track('checkout_started', { raffle_id: raffle.id, ticket_count: selected.length });
        router.visit(`/checkout?${params}`, { onFinish: () => setConfirming(false) });
    }

    const remaining = qty - selected.length;

    return (
        <>
            <Head title={`Pick your numbers: ${raffle.title}`} />
            <div className="min-h-screen bg-app-bg pb-32 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-100 bg-white/95 px-5 py-4 backdrop-blur-md dark:border-dark-border dark:bg-dark-bg/95">
                    <Link href={`/raffles/${raffle.id}`} className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white">
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <div className="flex-1">
                        <h2 className="truncate text-lg font-bold text-gray-900 dark:text-white">{raffle.title}</h2>
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                            {remaining > 0 ? `Pick ${remaining} more number${remaining === 1 ? '' : 's'}` : 'All numbers picked!'}
                        </p>
                    </div>
                    <button
                        onClick={quickPick}
                        className="flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-bold text-gray-700 shadow-sm active:scale-95 dark:border-dark-border dark:bg-dark-card dark:text-gray-200"
                    >
                        <Shuffle className="h-3.5 w-3.5 text-yellow-500" /> Quick Pick
                    </button>
                </div>

                {notice && (
                    <div
                        role="status"
                        className="sticky top-[73px] z-30 mx-4 mt-2 flex items-start gap-2 rounded-xl border border-orange-200 bg-orange-50 px-3 py-2 text-xs font-medium text-orange-800 shadow-sm dark:border-orange-900/40 dark:bg-orange-900/30 dark:text-orange-200"
                    >
                        <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                        <span>{notice}</span>
                    </div>
                )}

                {selected.length > 0 && (
                    <div className="flex justify-end px-5 pt-2">
                        <button
                            onClick={clearAll}
                            className="flex items-center gap-1 text-[10px] font-semibold text-red-500 hover:text-red-600 dark:text-red-400"
                        >
                            <X className="h-3 w-3" /> Unselect All
                        </button>
                    </div>
                )}

                <div className="flex items-center justify-center gap-4 px-5 py-3 text-[11px] text-gray-500 dark:text-gray-400">
                    <span className="flex items-center gap-1.5">
                        <span className="h-3 w-3 rounded border border-gray-300 bg-white dark:border-gray-600 dark:bg-dark-card" /> Available
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-3 w-3 rounded border border-yellow-500 bg-yellow-400" /> Yours
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-3 w-3 rounded border border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-900/20" /> Sold
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-3 w-3 rounded border border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20" /> Held
                    </span>
                </div>

                <div className="mx-auto grid max-w-lg grid-cols-5 gap-2 p-3 pb-32">
                    {numbers.map((n) => {
                        const isTaken = takenSet.has(n);
                        const isHeld = ! isTaken && heldSet.has(n);
                        const isSelected = selected.includes(n);

                        return (
                            <button
                                key={n}
                                onClick={() => toggle(n)}
                                disabled={isTaken || isHeld}
                                className={[
                                    'relative flex h-12 w-full select-none items-center justify-center rounded-xl text-sm font-bold transition-all active:scale-90',
                                    isTaken
                                        ? 'cursor-not-allowed border border-red-200 bg-red-50 text-red-400 dark:border-red-900 dark:bg-red-900/20 dark:text-red-400'
                                        : isHeld
                                          ? 'cursor-not-allowed border border-amber-300 bg-amber-50 text-amber-500 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-400'
                                          : isSelected
                                          ? 'scale-105 transform border-yellow-500 bg-yellow-400 text-lg text-gray-900 shadow-lg shadow-yellow-200/50 ring-2 ring-yellow-400 ring-offset-1'
                                          : 'border border-gray-200 bg-gray-50 text-gray-500 hover:bg-gray-100 dark:border-dark-border dark:bg-dark-card dark:text-gray-400 dark:hover:bg-gray-800',
                                ].join(' ')}
                            >
                                {n}
                                {isTaken && (
                                    <span className="absolute bottom-0.5 left-1/2 -translate-x-1/2 text-[8px] font-extrabold tracking-wide text-red-300">
                                        SOLD
                                    </span>
                                )}
                                {isHeld && (
                                    <span className="absolute bottom-0.5 left-1/2 -translate-x-1/2 text-[8px] font-extrabold tracking-wide text-amber-400">
                                        HELD
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
            </div>

            {remaining === 0 && (
                <div className="fixed bottom-0 left-0 w-full px-5 pb-6">
                    <div className="mx-auto flex max-w-md items-center gap-4 rounded-2xl border border-gray-800 bg-gray-900 p-4 text-white shadow-2xl dark:border-dark-border dark:bg-dark-card">
                        <div className="flex-1">
                            <p className="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                Total Pay
                            </p>
                            <p className="text-xl font-bold leading-none text-white">
                                {quote ? formatNaira(quote.discounted) : '…'}
                            </p>
                        </div>
                        <button
                            onClick={confirm}
                            disabled={confirming}
                            className="flex items-center gap-2 rounded-xl bg-white px-6 py-3 text-sm font-bold text-gray-900 shadow-lg transition-transform active:scale-95 disabled:opacity-60 dark:bg-app-primary dark:text-white"
                        >
                            {confirming ? 'Holding your numbers…' : <>Checkout Now <ArrowRight className="h-4 w-4" /></>}
                        </button>
                    </div>
                </div>
            )}
        </>
    );
}
