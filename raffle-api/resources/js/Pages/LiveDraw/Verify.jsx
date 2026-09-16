import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Copy, HelpCircle, Lock, ShieldCheck, Unlock, XCircle } from 'lucide-react';

// The real, user-facing "verify this draw yourself" view item 27 asks
// for, built on the item 14 provably-fair engine
// (App\Services\ProvablyFairDrawService / GET /api/raffles/{id}/draw).
// Legacy livedraw.php and winners.php both showed a "verification hash"
// with a green checkmark and no real explanation of what it meant — see
// HallOfFameController's docblock for why that hash proved nothing.
// This page replaces it with something a non-technical person can
// actually follow, while staying technically accurate: what a "seed" is,
// why it was hidden until now, and exactly what matching means.
export default function LiveDrawVerify({ raffle }) {
    const [data, setData] = useState(null);
    const [notFound, setNotFound] = useState(false);
    const [copied, setCopied] = useState(null);

    useEffect(() => {
        fetch(`/api/raffles/${raffle.id}/draw`)
            .then((res) => {
                if (res.status === 404) {
                    setNotFound(true);
                    return null;
                }
                return res.json();
            })
            .then((json) => json && setData(json))
            .catch(() => setNotFound(true));
    }, [raffle.id]);

    function copy(label, value) {
        navigator.clipboard?.writeText(value).then(() => {
            setCopied(label);
            setTimeout(() => setCopied(null), 1500);
        });
    }

    return (
        <>
            <Head title={`Verify Draw – ${raffle.title}`} />
            <div className="min-h-screen bg-gray-50 pb-24 dark:bg-dark-bg">
                <div className="sticky top-0 z-10 border-b border-gray-100 bg-white px-5 pb-4 pt-4 dark:border-dark-border dark:bg-dark-bg">
                    <h2 className="text-xl font-bold text-gray-900 dark:text-white">Verify This Draw</h2>
                    <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{raffle.title}</p>
                </div>

                <div className="mx-auto max-w-lg space-y-5 p-5">
                    {notFound && (
                        <div className="rounded-xl border border-gray-100 bg-white p-6 text-center text-sm text-gray-500 dark:border-dark-border dark:bg-dark-card dark:text-gray-400">
                            No draw has been committed for this raffle yet.
                        </div>
                    )}

                    {! notFound && ! data && (
                        <div className="flex justify-center py-10">
                            <div className="h-8 w-8 animate-spin rounded-full border-b-2 border-app-primary" />
                        </div>
                    )}

                    {data && (
                        <>
                            {/* Plain-English explainer first — the technical proof follows below. */}
                            <div className="rounded-2xl border border-blue-100 bg-blue-50 p-5 dark:border-blue-900/30 dark:bg-blue-900/10">
                                <div className="mb-2 flex items-center gap-2 font-bold text-blue-900 dark:text-blue-200">
                                    <HelpCircle className="h-5 w-5" /> How this proves the draw was fair
                                </div>
                                <ol className="list-decimal space-y-2 pl-5 text-sm leading-relaxed text-blue-900/90 dark:text-blue-200/80">
                                    <li>
                                        <strong>Before</strong> ticket sales for this raffle closed, we generated a secret random
                                        code (a "seed") and published only its fingerprint — a "hash" that's impossible to
                                        reverse. That's the <em>commitment</em> below. It proves the code was already fixed
                                        before the draw ran, so nobody — including us — could pick winners after the fact.
                                    </li>
                                    <li>
                                        When the draw ran, that secret code was combined with a second code built entirely
                                        from the real list of ticket holders — so neither we nor any buyer could have
                                        predicted or influenced the result in advance.
                                    </li>
                                    <li>
                                        Now that the draw is done, we <strong>reveal</strong> the secret code. Anyone — you,
                                        a developer, anyone — can run the exact same math with the revealed code and the
                                        same ticket list, and check it produces the exact same winners published on the{' '}
                                        <Link href="/hall-of-fame" className="underline">
                                            Hall of Fame
                                        </Link>
                                        .
                                    </li>
                                </ol>
                            </div>

                            <ProofRow
                                icon={<Lock className="h-4 w-4" />}
                                label="Commitment (published before the draw ran)"
                                value={data.server_seed_hash}
                                onCopy={() => copy('hash', data.server_seed_hash)}
                                copied={copied === 'hash'}
                            />

                            <ProofRow
                                label="Committed at"
                                value={data.committed_at}
                                plain
                            />

                            {! data.has_run && (
                                <div className="rounded-xl border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-900/30 dark:bg-yellow-900/20 dark:text-yellow-300">
                                    This draw hasn't run yet — the secret seed stays hidden until it does, which is the
                                    whole point: revealing it early would let someone work out the result in advance.
                                </div>
                            )}

                            {data.has_run && data.verification && (
                                <>
                                    <ProofRow
                                        icon={<Unlock className="h-4 w-4" />}
                                        label="Revealed seed (secret code — now public)"
                                        value={data.verification.server_seed}
                                        onCopy={() => copy('seed', data.verification.server_seed)}
                                        copied={copied === 'seed'}
                                    />
                                    <ProofRow
                                        label="Ticket-pool code (built from the real ticket list)"
                                        value={data.verification.client_seed}
                                        onCopy={() => copy('client', data.verification.client_seed)}
                                        copied={copied === 'client'}
                                    />

                                    <div className="rounded-2xl border border-gray-100 bg-white p-5 dark:border-dark-border dark:bg-dark-card">
                                        <h3 className="mb-3 flex items-center gap-2 font-bold text-gray-900 dark:text-white">
                                            <ShieldCheck className="h-5 w-5 text-green-500" /> Independent recomputation result
                                        </h3>
                                        <CheckLine
                                            ok={data.verification.seed_hash_matches}
                                            text="The revealed seed matches the commitment published before the draw"
                                        />
                                        <CheckLine
                                            ok={data.verification.client_seed_matches}
                                            text="The ticket-pool code matches the real, final list of eligible tickets"
                                        />
                                        <CheckLine
                                            ok={data.verification.winners_match}
                                            text="Re-running the draw with these codes produces the exact published winners"
                                        />
                                    </div>
                                </>
                            )}
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

function ProofRow({ icon, label, value, onCopy, copied, plain }) {
    return (
        <div className="rounded-2xl border border-gray-100 bg-white p-4 dark:border-dark-border dark:bg-dark-card">
            <p className="mb-1 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                {icon} {label}
            </p>
            <div className="flex items-center justify-between gap-2">
                <p className={`min-w-0 flex-1 break-all font-mono text-xs text-gray-800 dark:text-gray-200 ${plain ? 'font-sans' : ''}`}>
                    {value}
                </p>
                {! plain && (
                    <button onClick={onCopy} className="shrink-0 text-gray-400 hover:text-app-primary">
                        {copied ? <CheckCircle2 className="h-4 w-4 text-green-500" /> : <Copy className="h-4 w-4" />}
                    </button>
                )}
            </div>
        </div>
    );
}

function CheckLine({ ok, text }) {
    return (
        <div className="flex items-start gap-2 py-1.5 text-sm">
            {ok ? (
                <CheckCircle2 className="mt-0.5 h-4 w-4 flex-shrink-0 text-green-500" />
            ) : (
                <XCircle className="mt-0.5 h-4 w-4 flex-shrink-0 text-red-500" />
            )}
            <span className={ok ? 'text-gray-700 dark:text-gray-300' : 'font-bold text-red-600 dark:text-red-400'}>{text}</span>
        </div>
    );
}
