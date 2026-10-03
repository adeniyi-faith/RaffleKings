import { Gift, ShieldCheck } from 'lucide-react';
import { Card } from '../ui/Card';
import { formatNaira } from '../../lib/format';

/**
 * The raffle page's "Daily Drop" sheet: how the drop works, today's pot so
 * far, and the last few drops (winning ticket numbers only, never names),
 * with what anyone needs to check each pick was fair.
 */
export default function DailyDropPanel({ drop }) {
    const share = Number(drop.pot_percent).toLocaleString(undefined, { maximumFractionDigits: 2 });
    const people = drop.winners_per_day === 1 ? 'one ticket holder' : `${drop.winners_per_day} ticket holders`;

    return (
        <div className="space-y-4">
            {drop.active && (
                <div className="flex items-center gap-3 rounded-2xl border border-amber-100 bg-amber-50 p-3 dark:border-amber-900/30 dark:bg-amber-900/15">
                    <span className="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300">
                        <Gift className="h-5 w-5" />
                    </span>
                    <div>
                        <p className="text-sm font-bold text-gray-900 dark:text-white">{formatNaira(drop.pot_so_far)} in today's drop so far</p>
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                            Drops at {drop.drop_time} ({drop.timezone.replace('_', ' ')}) to {people}
                        </p>
                    </div>
                </div>
            )}

            <Card className="!p-4">
                <ul className="list-disc space-y-1.5 pl-4 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                    <li>
                        Every day at {drop.drop_time}, {share}% of the ticket money this raffle took since the last drop is shared equally by {people}, picked at random.
                    </li>
                    {drop.daily_cap ? <li>At most {formatNaira(drop.daily_cap)} is dropped in one day.</li> : null}
                    <li>Every ticket is one equal chance. One person can win at most one share a day.</li>
                    <li>Winnings go straight to your winnings balance, and your ticket stays in the main draw.</li>
                </ul>
            </Card>

            {drop.recent.length > 0 && (
                <div>
                    <p className="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Recent drops</p>
                    <ul className="space-y-2">
                        {drop.recent.map((run) => (
                            <li key={run.date} className="rounded-xl border border-gray-100 p-3 text-sm dark:border-gray-800">
                                <div className="flex justify-between font-semibold text-gray-900 dark:text-white">
                                    <span>{new Date(`${run.date}T12:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}</span>
                                    <span>{formatNaira(run.pot)}</span>
                                </div>
                                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Winning tickets: {run.tickets.map((t) => `#${t.ticket_number}`).join(', ')}
                                </p>
                                <details className="mt-1 text-[11px] text-gray-400">
                                    <summary className="cursor-pointer">Check this drop</summary>
                                    <p className="mt-1 break-all">Seed: {run.seed}</p>
                                    <p className="break-all">Seed fingerprint (shown before the drop): {run.seed_hash}</p>
                                    <p className="break-all">Ticket list fingerprint: {run.pool_hash}</p>
                                </details>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {drop.active && drop.next_seed_hash && (
                <p className="flex items-start gap-1.5 text-[11px] text-gray-400">
                    <ShieldCheck className="mt-0.5 h-3.5 w-3.5 flex-none" />
                    <span className="break-all">The next drop is already locked in. Its fingerprint is {drop.next_seed_hash}. The secret behind it is shown after the drop, so the pick can be checked.</span>
                </p>
            )}
        </div>
    );
}
