import { useCallback, useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ChevronRight,
    Clock,
    Eye,
    Gift,
    Lock,
    Megaphone,
    ShieldCheck,
    Star,
    Ticket,
    Trophy,
    Zap,
} from 'lucide-react';
import { Card } from '../../Components/ui/Card';
import BottomSheet from '../../Components/ui/BottomSheet';
import TicketPickerSheet from '../../Components/raffles/TicketPickerSheet';
import RaffleOdds from '../../Components/raffles/RaffleOdds';
import { useCountdown } from '../../hooks/useCountdown';
import { seedRaffleOdds } from '../../hooks/useRaffleOdds';
import { useLiveRaffle } from '../../hooks/useLiveRaffle';
import { useTicketPriceQuotes } from '../../hooks/useTicketPriceQuotes';
import { useTicketPriceQuote } from '../../hooks/useTicketPriceQuote';
import { formatNaira } from '../../lib/format';
import { track } from '../../lib/analytics';
import { isOn, useSite } from '../../lib/site';
import PausedNotice from '../../Components/layout/PausedNotice';
import BoostPanel from '../../Components/social/BoostPanel';
import DailyDropPanel from '../../Components/raffles/DailyDropPanel';
import AdSlot from '../../Components/ads/AdSlot';

const DEFAULT_QUANTITIES = [1, 2, 3, 5, 10];
// The most a single order can hold when neither the raffle nor the site sets a
// limit: a sanity ceiling, so the amount box can't be given a silly number.
const SANE_CEILING = 1000;

const TONES = {
    indigo: 'bg-indigo-50 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-300',
    emerald: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-900/30 dark:text-amber-300',
    hot: 'bg-white/25 text-white',
};

/**
 * The raffle page: one prize card, one big button to choose tickets, and three
 * folded rows (prizes and chances, how the draw works, boost and share) that
 * open as sheets. Choosing tickets keeps everything the old page had: the
 * bundle buttons with their discounts, and any amount up to the limit.
 */
export default function RaffleShow({ raffle, drawInfo = null, odds = null, dailyDrop = null }) {
    const { auth } = usePage().props;
    // Put the chances that came with the page where the odds boxes can find them, before they draw.
    useMemo(() => seedRaffleOdds(raffle.id, odds), [raffle.id, odds]);
    const site = useSite();
    const FIXED_QUANTITIES = site.ticket_bundles?.length ? site.ticket_bundles : DEFAULT_QUANTITIES;
    // Raffle Rules Engine: members-only / new-players-only raffles say so up front.
    const notEligible = drawInfo?.not_eligible ?? null;
    const salesPaused = ! isOn(site, 'ticket_sales') || !! notEligible;
    const [selectedQty, setSelectedQty] = useState(FIXED_QUANTITIES.includes(3) ? 3 : FIXED_QUANTITIES[Math.min(1, FIXED_QUANTITIES.length - 1)]);
    const [sheet, setSheet] = useState(null); // null | 'tickets' | 'prizes' | 'how' | 'drop' | 'boost'
    const closeSheet = useCallback(() => setSheet(null), []);

    // Counts down to the exact moment sales stop (end of the expiry day, Lagos time).
    const timeLeft = useCountdown(raffle.ends_at);

    // A live sold/remaining count and an honest "N viewing" number, sourced the
    // instant anyone, anywhere, actually buys a ticket for this raffle.
    const { soldTickets, remainingTickets, isClosed: liveClosed, viewerCount } = useLiveRaffle(raffle.id, raffle, !! auth.user);
    // Closes on screen the moment the countdown runs out or the last ticket sells.
    const endedWhileWatching = timeLeft === 'Closed';
    const isClosed = liveClosed || endedWhileWatching;
    const closedReason = raffle.is_closed
        ? raffle.closed_reason
        : endedWhileWatching
          ? 'ended'
          : liveClosed
            ? 'sold_out'
            : null;
    const progressPct = raffle.max_tickets > 0 ? Math.min(100, Math.round((soldTickets / raffle.max_tickets) * 100)) : 0;

    // The most one order can hold: the tickets left, and the admin's order limit if there is one.
    const orderLimit = raffle.max_per_order ?? null;
    const maxAllowed = Math.max(1, Math.min(remainingTickets, orderLimit ?? SANE_CEILING, SANE_CEILING));
    const bundles = useMemo(() => FIXED_QUANTITIES.filter((q) => q <= maxAllowed), [FIXED_QUANTITIES.join(','), maxAllowed]);

    // If a limit or a sale changes what is allowed, bring the choice back inside it.
    useEffect(() => {
        if (selectedQty > maxAllowed) {
            setSelectedQty(maxAllowed);
        }
    }, [maxAllowed, selectedQty]);

    const { quotes } = useTicketPriceQuotes(raffle.id, bundles);
    const { quote: customQuote } = useTicketPriceQuote(raffle.id, selectedQty);
    const activeQuote = quotes[selectedQty] ?? customQuote;

    // Someone opened this raffle.
    useEffect(() => {
        track('raffle_viewed', { raffle_id: raffle.id, title: raffle.title });
    }, [raffle.id]);

    function handleProceed() {
        if (salesPaused) {
            return;
        }

        track('buy_tickets_clicked', { raffle_id: raffle.id, quantity: selectedQty, logged_in: Boolean(auth.user) });
        const params = new URLSearchParams({ raffle_id: raffle.id, qty: String(selectedQty) });

        // Guests go straight to the numbers too: they pick first and are asked to
        // sign in at checkout, where their numbers are held for them.
        router.visit(`/raffles/${raffle.id}/numbers?${params}`);
    }

    const closedTitle = closedReason === 'sold_out' ? 'Sold out' : closedReason === 'ended' ? 'Raffle ended' : closedReason === 'cancelled' ? 'Raffle cancelled' : 'Raffle closed';
    const hasRules = drawInfo?.rules?.length > 0;
    const bonus = drawInfo && (drawInfo.bonus_entries > 0 || drawInfo.tier_bonus > 0);

    return (
        <>
            <Head title={raffle.title} />
            <div className="min-h-screen bg-app-bg pb-10 dark:bg-dark-bg">
                <div className="sticky top-0 z-40 flex items-center gap-3 border-b border-gray-100 bg-white/95 px-5 py-4 backdrop-blur-md dark:border-dark-border dark:bg-dark-bg/95">
                    <Link href="/raffles" className="-ml-1 p-1 text-gray-400 hover:text-gray-600 dark:hover:text-white" aria-label="Back to raffles">
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <h2 className="truncate pr-4 text-lg font-bold text-gray-900 dark:text-white">{raffle.title}</h2>
                </div>

                <main className="mx-auto max-w-lg space-y-3 px-4 pt-4">
                    {! isClosed && <PausedNotice feature="ticket_sales" />}
                    {! isClosed && notEligible && (
                        <div className="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/40 dark:bg-amber-900/20">
                            <Lock className="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-600 dark:text-amber-400" />
                            <p className="text-xs leading-relaxed text-amber-800 dark:text-amber-300">{notEligible}</p>
                        </div>
                    )}

                    {/* The prize */}
                    <section
                        className={[
                            'relative overflow-hidden rounded-3xl p-6 text-white shadow-xl',
                            isClosed
                                ? 'bg-gradient-to-br from-gray-600 to-gray-900 shadow-gray-900/20'
                                : 'bg-gradient-to-br from-emerald-600 via-emerald-700 to-teal-800 shadow-emerald-900/25',
                        ].join(' ')}
                    >
                        <div className="pointer-events-none absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-2xl" aria-hidden="true" />
                        <div className="pointer-events-none absolute -bottom-16 -left-10 h-40 w-40 rounded-full bg-teal-300/10 blur-2xl" aria-hidden="true" />

                        <div className="relative flex flex-wrap items-center gap-2">
                            {isClosed && (
                                <span className="rounded-full bg-red-500/90 px-3 py-1 text-[11px] font-bold">{closedTitle}</span>
                            )}
                            {raffle.is_flash && ! isClosed && (
                                <span className="flex items-center gap-1 rounded-full bg-fuchsia-500/90 px-3 py-1 text-[11px] font-bold">
                                    <Zap className="h-3 w-3 fill-current" /> Flash raffle
                                </span>
                            )}
                            {! isClosed && timeLeft && (
                                <span className="flex items-center gap-1 rounded-full bg-black/25 px-3 py-1 text-[11px] font-bold backdrop-blur-sm">
                                    <Clock className="h-3 w-3" /> {timeLeft}
                                </span>
                            )}
                            {viewerCount !== null && ! isClosed && (
                                <span className="flex items-center gap-1 rounded-full bg-black/25 px-3 py-1 text-[11px] font-bold backdrop-blur-sm">
                                    <Eye className="h-3 w-3" /> {viewerCount} viewing
                                </span>
                            )}
                        </div>

                        <div className="relative mt-5 flex items-center gap-3">
                            <span className="flex h-11 w-11 flex-none items-center justify-center rounded-2xl bg-white/15 backdrop-blur-sm">
                                <Trophy className="h-6 w-6 text-amber-300" aria-hidden="true" />
                            </span>
                            <p className="text-xs font-semibold uppercase tracking-widest text-emerald-100">Grand prize</p>
                        </div>
                        <h1 className="relative mt-2 text-3xl font-extrabold leading-tight tracking-tight">{raffle.grand_prize}</h1>
                        <p className="relative mt-1 text-sm font-medium text-emerald-100">{formatNaira(raffle.price)} a ticket</p>

                        <div className="relative mt-5">
                            <div className="h-2 w-full overflow-hidden rounded-full bg-black/25" role="progressbar" aria-valuenow={progressPct} aria-valuemin={0} aria-valuemax={100} aria-label="Tickets sold">
                                <div className="h-full rounded-full bg-amber-300 transition-all duration-500" style={{ width: `${progressPct}%` }} />
                            </div>
                            <div className="mt-1.5 flex justify-between text-[11px] font-medium tabular-nums text-emerald-100">
                                <span>{soldTickets.toLocaleString()} sold</span>
                                <span>{remainingTickets.toLocaleString()} left</span>
                            </div>
                        </div>
                    </section>

                    {/* The one thing to do */}
                    {isClosed ? (
                        <section className="rounded-3xl border border-gray-100 bg-white p-6 text-center dark:border-gray-800 dark:bg-dark-card">
                            <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                                <Lock className="h-7 w-7 text-gray-400 dark:text-gray-500" />
                            </div>
                            <h3 className="mb-1 text-xl font-black text-gray-900 dark:text-white">{closedTitle}</h3>
                            <p className="mx-auto mb-5 max-w-xs text-sm text-gray-500 dark:text-gray-400">
                                {closedReason === 'sold_out'
                                    ? 'Every ticket for this raffle has been claimed.'
                                    : closedReason === 'ended'
                                      ? 'Ticket sales for this raffle have finished.'
                                      : closedReason === 'cancelled'
                                        ? 'This raffle was cancelled. Everyone who bought tickets got their money back in full, where they paid from.'
                                        : 'This raffle is no longer taking entries.'}{' '}
                                {closedReason !== 'cancelled' && 'Winners appear in the Hall of Fame once the draw is done.'}
                            </p>
                            <div className="mx-auto flex max-w-xs flex-col gap-3">
                                <Link href="/hall-of-fame" className="rounded-xl bg-app-primary py-3.5 text-sm font-bold text-white shadow-lg shadow-blue-500/30 transition-transform active:scale-[0.98]">
                                    See the winners
                                </Link>
                                <Link href="/raffles" className="rounded-xl border border-gray-200 py-3.5 text-sm font-bold text-gray-700 transition-transform active:scale-[0.98] dark:border-gray-700 dark:text-gray-200">
                                    Browse open raffles
                                </Link>
                            </div>
                        </section>
                    ) : (
                        <button
                            type="button"
                            onClick={() => setSheet('tickets')}
                            disabled={salesPaused}
                            className="rk-shine relative flex w-full items-center justify-center gap-2.5 overflow-hidden rounded-2xl bg-gradient-to-r from-amber-400 via-orange-500 to-rose-500 py-4 text-lg font-black text-white shadow-lg shadow-orange-500/40 transition-transform active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <Ticket className="h-6 w-6" aria-hidden="true" /> Get tickets <ArrowRight className="h-5 w-5" aria-hidden="true" />
                        </button>
                    )}

                    {/* The details, one tap away */}
                    <InfoRow
                        icon={Trophy}
                        tone="indigo"
                        title="Prizes and your chances"
                        subtitle={raffle.winner_count > 1 ? `${raffle.winner_count} prizes to win` : 'The grand prize'}
                        onClick={() => setSheet('prizes')}
                    />
                    {hasRules && (
                        <InfoRow
                            icon={ShieldCheck}
                            tone="emerald"
                            title="How this draw works"
                            subtitle="Random and checkable"
                            cta={bonus ? 'Bonus' : null}
                            onClick={() => setSheet('how')}
                        />
                    )}
                    {dailyDrop && (dailyDrop.active || dailyDrop.recent.length > 0) && (
                        <InfoRow
                            icon={Gift}
                            tone="amber"
                            title="Daily Drop"
                            subtitle={
                                dailyDrop.active
                                    ? `${formatNaira(dailyDrop.pot_so_far)} so far, dropping at ${dailyDrop.drop_time} to ${dailyDrop.winners_per_day} ticket ${dailyDrop.winners_per_day === 1 ? 'holder' : 'holders'}`
                                    : `${formatNaira(dailyDrop.total_paid)} dropped to ticket holders`
                            }
                            cta={dailyDrop.active ? 'Every day' : null}
                            onClick={() => setSheet('drop')}
                        />
                    )}
                    {auth.user && ! isClosed && (
                        <InfoRow icon={Megaphone} tone="hot" title="Boost and share" subtitle="Invite friends, earn free bonus entries" cta="Free" onClick={() => setSheet('boost')} />
                    )}
                    {/* Ad spot (admin: Site → Ads → "A raffle's own page"). */}
                    <AdSlot placement="raffle_page" />
                </main>
            </div>

            <BottomSheet open={sheet === 'tickets'} onClose={closeSheet} title="Choose your tickets">
                <TicketPickerSheet
                    raffleId={raffle.id}
                    bundles={bundles}
                    quotes={quotes}
                    selectedQty={selectedQty}
                    onSelect={setSelectedQty}
                    customQuote={customQuote}
                    maxAllowed={maxAllowed}
                    orderLimit={orderLimit}
                    remaining={remainingTickets}
                    total={activeQuote ? activeQuote.discounted : null}
                    disabled={salesPaused || ! activeQuote}
                    onProceed={handleProceed}
                />
            </BottomSheet>

            <BottomSheet open={sheet === 'prizes'} onClose={closeSheet} title="Prizes and your chances">
                <div className="space-y-4">
                    <div className="flex items-center gap-3 rounded-2xl border border-amber-100 bg-amber-50 p-3 dark:border-amber-900/30 dark:bg-amber-900/15">
                        <span className="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300">
                            <Trophy className="h-5 w-5" />
                        </span>
                        <div>
                            <p className="text-sm font-bold text-gray-900 dark:text-white">{raffle.grand_prize}</p>
                            <p className="text-xs text-gray-500 dark:text-gray-400">The grand prize</p>
                        </div>
                    </div>

                    {/* While the chances list is showing it already lists every prize level, so the plain list is only for finished raffles and one-prize raffles. */}
                    {raffle.prize_list.length > 0 && (isClosed || raffle.winner_count <= 1) && (
                        <ul className="space-y-2.5">
                            {raffle.prize_list.map((prize, i) => (
                                <li key={i} className="flex items-center gap-3">
                                    <span className="flex h-8 w-8 flex-none items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                        <Gift className="h-4 w-4" />
                                    </span>
                                    <span className="text-sm text-gray-700 dark:text-gray-300">{prize}</span>
                                </li>
                            ))}
                        </ul>
                    )}

                    {! isClosed && raffle.max_tickets > 0 && (
                        <>
                            <div>
                                <p className="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">See your chances with</p>
                                <div className="flex flex-wrap gap-2" role="group" aria-label="Number of tickets">
                                    {bundles.map((q) => (
                                        <button
                                            key={q}
                                            type="button"
                                            onClick={() => setSelectedQty(q)}
                                            aria-pressed={selectedQty === q}
                                            className={[
                                                'min-w-[3rem] rounded-xl border px-3 py-2 text-sm font-bold tabular-nums transition-colors',
                                                selectedQty === q ? 'border-amber-400 bg-amber-100 text-amber-950 dark:bg-amber-900/30 dark:text-amber-100' : 'border-gray-200 text-gray-700 dark:border-gray-700 dark:text-gray-200',
                                            ].join(' ')}
                                        >
                                            {q}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <RaffleOdds raffleId={raffle.id} quantity={selectedQty} defaultOpen />
                        </>
                    )}
                </div>
            </BottomSheet>

            <BottomSheet open={sheet === 'how'} onClose={closeSheet} title="How this draw works">
                {drawInfo && <DrawRules drawInfo={drawInfo} />}
            </BottomSheet>

            <BottomSheet open={sheet === 'drop'} onClose={closeSheet} title="Daily Drop">
                {dailyDrop && <DailyDropPanel drop={dailyDrop} />}
            </BottomSheet>

            <BottomSheet open={sheet === 'boost'} onClose={closeSheet} title="Boost and share" keepMounted>
                {auth.user && ! isClosed && <BoostPanel raffleId={raffle.id} raffleTitle={raffle.title} />}
            </BottomSheet>
        </>
    );
}

function InfoRow({ icon: Icon, tone, title, subtitle, cta = null, onClick }) {
    const hot = tone === 'hot';

    return (
        <button
            type="button"
            onClick={onClick}
            className={[
                'flex w-full items-center gap-3 rounded-2xl p-3.5 text-left transition-transform active:scale-[0.99]',
                hot
                    ? 'bg-gradient-to-r from-fuchsia-600 via-purple-600 to-indigo-600 text-white shadow-lg shadow-purple-500/30'
                    : 'border border-gray-100 bg-white hover:border-gray-200 dark:border-gray-800 dark:bg-dark-card dark:hover:border-gray-700',
            ].join(' ')}
        >
            <span className={`flex h-10 w-10 flex-none items-center justify-center rounded-xl ${TONES[tone]}`}>
                <Icon className="h-5 w-5" aria-hidden="true" />
            </span>
            <span className="min-w-0 flex-1">
                <span className={`block text-sm font-bold ${hot ? 'text-white' : 'text-gray-900 dark:text-white'}`}>{title}</span>
                <span className={`block text-xs ${hot ? 'text-white/85' : 'truncate text-gray-500 dark:text-gray-400'}`}>{subtitle}</span>
            </span>
            {cta && (
                <span className={`flex-none whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-extrabold ${hot ? 'bg-yellow-300 text-purple-900' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'}`}>
                    {cta}
                </span>
            )}
            <ChevronRight className={`h-5 w-5 flex-none ${hot ? 'text-white/80' : 'text-gray-300 dark:text-gray-600'}`} aria-hidden="true" />
        </button>
    );
}

// "How this draw works": the raffle's published draw rules, plus the
// customer's own loyalty bonus entries when the raffle gives them.
function DrawRules({ drawInfo }) {
    return (
        <div className="space-y-3">
            {(drawInfo.bonus_entries > 0 || drawInfo.tier_bonus > 0) && (
                <div className="flex items-start gap-2 rounded-xl border border-yellow-200 bg-yellow-50 p-3 text-xs text-yellow-900 dark:border-yellow-800/50 dark:bg-yellow-900/20 dark:text-yellow-200">
                    <Star className="mt-0.5 h-4 w-4 flex-shrink-0 fill-current text-yellow-500" />
                    <p>
                        {drawInfo.bonus_entries > 0
                            ? `You have ${drawInfo.bonus_entries} free bonus ${drawInfo.bonus_entries === 1 ? 'entry' : 'entries'} in this draw as a ${drawInfo.tier?.name} member.`
                            : `As a ${drawInfo.tier?.name} member you get ${drawInfo.tier_bonus} free bonus ${drawInfo.tier_bonus === 1 ? 'entry' : 'entries'} when you buy a ticket here.`}
                    </p>
                </div>
            )}
            <Card className="!p-4">
                <ul className="list-disc space-y-1.5 pl-4 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    {drawInfo.rules.map((line, i) => (
                        <li key={i}>{line}</li>
                    ))}
                </ul>
            </Card>
            <p className="text-[11px] text-gray-400">These rules are locked in before the draw and are part of what the Verify page checks.</p>
        </div>
    );
}
