import { useEffect, useRef, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Coins, Gift, Info, Sparkles, Volume2, VolumeX, X } from 'lucide-react';
import SpinWheel from '../../Components/rewards/SpinWheel';
import { setBalances } from '../../lib/balances';
import Confetti from '../../Components/ui/Confetti';
import { isOn, useSite } from '../../lib/site';
import { evenSound, loseSound, setSoundOn, soundOn, tick, unlockSound, winSound } from '../../lib/gameSound';

// Spin & Win as its own full-screen game (linked from the Rewards page).
// The prize is always the server's (POST /api/rewards/spin, a secure
// random draw on the real odds shown here): the wheel starts turning the
// moment the player taps or flicks it, then slows onto the slice the
// server already picked. Nothing on this page can change a result.

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Animates a number towards its new value (the points counter, the win amount).
function useCountUp(value, duration = 700) {
    const [shown, setShown] = useState(value);
    const from = useRef(value);

    useEffect(() => {
        if (value === null || value === undefined) {
            setShown(value);
            return undefined;
        }

        const start = from.current ?? value;
        const began = performance.now();
        let frame;

        function run(now) {
            const t = Math.min(1, (now - began) / duration);
            setShown(Math.round(start + (value - start) * (1 - (1 - t) ** 3)));
            if (t < 1) frame = requestAnimationFrame(run);
            else from.current = value;
        }

        frame = requestAnimationFrame(run);

        return () => cancelAnimationFrame(frame);
    }, [value, duration]);

    return shown;
}

function useWheelSize() {
    const pick = () => Math.max(220, Math.min(340, (typeof window === 'undefined' ? 390 : window.innerWidth) - 90));
    const [size, setSize] = useState(pick);

    useEffect(() => {
        const onResize = () => setSize(pick());
        window.addEventListener('resize', onResize);

        return () => window.removeEventListener('resize', onResize);
    }, []);

    return size;
}

const CHIP = {
    loss: 'bg-slate-500 text-white',
    tie: 'bg-violet-500 text-white',
    win: 'bg-green-600 text-white',
    jackpot: 'bg-yellow-400 text-yellow-900',
};

export default function Spin({ cost, odds, points: initialPoints, freeSpins: initialFree = 0 }) {
    const { auth } = usePage().props;
    const site = useSite();
    const isGuest = ! auth?.user;
    const paused = ! isOn(site, 'spin');
    const size = useWheelSize();
    const wheelRef = useRef(null);

    const [points, setPoints] = useState(initialPoints);
    // Phase 11: free spins (birthday, milestones, Season Pass…) are used first.
    const [freeSpins, setFreeSpins] = useState(initialFree);
    const [phase, setPhase] = useState('ready'); // ready | spinning | result
    const [result, setResult] = useState(null);
    const [error, setError] = useState(null);
    const [history, setHistory] = useState([]); // this visit only: { payout, outcome }
    const [showOdds, setShowOdds] = useState(false);
    const [sound, setSound] = useState(soundOn);
    const shownPoints = useCountUp(points);

    const canAfford = freeSpins > 0 || (points !== null && points >= cost);
    const spinsWon = history.reduce((sum, h) => sum + h.payout, 0);
    const net = spinsWon - history.length * cost;
    const averageBack = Math.round(odds.reduce((sum, o) => sum + o.payout * o.probability, 0));

    async function spin() {
        if (phase === 'spinning' || isGuest || paused || ! canAfford) {
            return;
        }

        unlockSound();
        setError(null);
        setResult(null);
        setPhase('spinning');
        const free = freeSpins > 0;
        if (! free) setPoints((p) => p - cost);
        wheelRef.current?.start();

        try {
            // A short wind-up even when the server answers instantly.
            const [response] = await Promise.all([
                fetch('/api/rewards/spin', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                    body: JSON.stringify({ free }),
                }),
                sleep(700),
            ]);
            const data = await response.json().catch(() => ({}));

            if (! response.ok) {
                throw new Error(data.message || 'The spin did not go through. No points were taken.');
            }

            await wheelRef.current?.landOn(data.visual_index);

            // A free spin that lands on nothing is a miss, not "points back".
            const profit = data.free ? (data.payout > 0 ? data.payout : -1) : data.payout - cost;
            setFreeSpins(data.free_spins_left ?? 0);
            if (profit > 0) winSound(data.outcome === 'jackpot');
            else if (profit === 0) evenSound();
            else loseSound();

            setPoints(data.new_balance);
            setBalances({ points: data.new_balance });
            setHistory((h) => [{ payout: data.payout, outcome: data.outcome }, ...h].slice(0, 12));
            setResult({ ...data, profit });
            setPhase('result');
        } catch (err) {
            wheelRef.current?.stop();
            setError(err.message);
            setPhase('ready');
            // Put the real balance back.
            fetch('/api/rewards/state', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then((res) => (res.ok ? res.json() : null))
                .then((state) => state && setPoints(state.points))
                .catch(() => {});
        }
    }

    // Flick the wheel to spin it: a quick drag around the centre counts.
    const drag = useRef(null);

    function angleAt(e) {
        const box = e.currentTarget.getBoundingClientRect();

        return Math.atan2(e.clientY - (box.top + box.height / 2), e.clientX - (box.left + box.width / 2));
    }

    function onPointerDown(e) {
        drag.current = { angle: angleAt(e), time: performance.now(), moved: 0 };
    }

    function onPointerMove(e) {
        if (! drag.current) return;
        const a = angleAt(e);
        let d = a - drag.current.angle;
        if (d > Math.PI) d -= Math.PI * 2;
        if (d < -Math.PI) d += Math.PI * 2;
        drag.current.moved += Math.abs(d);
        drag.current.angle = a;
    }

    function onPointerUp() {
        const flick = drag.current;
        drag.current = null;

        if (flick && flick.moved > 0.6 && performance.now() - flick.time < 900) {
            spin();
        }
    }

    function toggleSound() {
        unlockSound();
        setSoundOn(! sound);
        setSound(! sound);
    }

    const loginUrl = `/login?redirect=${encodeURIComponent('/rewards/spin')}`;

    return (
        <>
            <Head title="Spin & Win" />
            <div className="relative min-h-screen overflow-hidden bg-[#0b0620] text-white">
                {/* Arcade backdrop */}
                <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                    <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top,#4c1d95_0%,#1e1b4b_45%,#0b0620_80%)]" />
                    <div className="absolute -left-24 top-1/3 h-72 w-72 rounded-full bg-fuchsia-600/25 blur-3xl" />
                    <div className="absolute -right-24 top-2/3 h-72 w-72 rounded-full bg-blue-600/25 blur-3xl" />
                    {Array.from({ length: 28 }, (_, i) => (
                        <span
                            key={i}
                            className="absolute h-1 w-1 animate-pulse rounded-full bg-white/70"
                            style={{ left: `${(i * 37) % 100}%`, top: `${(i * 53) % 100}%`, animationDelay: `${(i % 7) * 0.4}s` }}
                        />
                    ))}
                </div>

                <div className={`relative mx-auto flex min-h-screen max-w-md flex-col px-5 pb-8 pt-4 ${result?.outcome === 'jackpot' && phase === 'result' ? 'animate-shake' : ''}`}>
                    {/* Top bar */}
                    <header className="flex items-center justify-between">
                        <Link href="/rewards" className="-ml-1 flex h-10 w-10 items-center justify-center rounded-full bg-white/10 backdrop-blur" aria-label="Back to Rewards">
                            <ArrowLeft className="h-5 w-5" />
                        </Link>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={toggleSound}
                                className="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 backdrop-blur"
                                aria-label={sound ? 'Mute sound' : 'Turn sound on'}
                            >
                                {sound ? <Volume2 className="h-5 w-5" /> : <VolumeX className="h-5 w-5 text-white/60" />}
                            </button>
                            {! isGuest && (
                                <div className="flex items-center gap-2 rounded-full border border-yellow-300/40 bg-black/30 px-3 py-2 backdrop-blur" aria-live="polite">
                                    <Coins className="h-4 w-4 fill-current text-yellow-400" />
                                    <span className="font-mono text-sm font-bold tabular-nums">{shownPoints ?? '…'}</span>
                                    <span className="text-[10px] font-bold uppercase text-white/60">pts</span>
                                </div>
                            )}
                        </div>
                    </header>

                    <div className="mt-4 text-center">
                        <h1 className="text-4xl font-black italic uppercase tracking-tighter drop-shadow-[0_2px_10px_rgba(250,204,21,0.45)]">
                            Spin <span className="bg-gradient-to-r from-yellow-300 to-amber-500 bg-clip-text pr-2 text-transparent">&amp; Win</span>
                        </h1>
                        <p className="mt-1 text-[11px] font-bold uppercase tracking-[0.25em] text-white/50">
                            {phase === 'spinning' ? 'Good luck…' : 'Tap spin or flick the wheel'}
                        </p>
                    </div>

                    {/* Wheel */}
                    <div
                        className="mt-6 touch-none select-none"
                        onPointerDown={onPointerDown}
                        onPointerMove={onPointerMove}
                        onPointerUp={onPointerUp}
                        onPointerCancel={() => (drag.current = null)}
                    >
                        <SpinWheel ref={wheelRef} odds={odds} size={size} lights onTick={tick} />
                    </div>

                    {/* Action */}
                    <div className="mt-8">
                        {paused ? (
                            <p className="rounded-2xl border border-amber-300/40 bg-amber-400/10 px-4 py-3 text-center text-sm text-amber-100">
                                {site.paused_message || 'Spin & Win is paused for a short while. Please try again soon.'}
                            </p>
                        ) : isGuest ? (
                            <Link
                                href={loginUrl}
                                className="flex w-full items-center justify-center rounded-2xl bg-gradient-to-b from-yellow-300 to-amber-500 py-4 text-xl font-black uppercase text-amber-950 shadow-[0_6px_0_#b45309,0_12px_30px_rgba(250,204,21,0.35)] active:translate-y-1 active:shadow-[0_2px_0_#b45309]"
                            >
                                Log in to play
                            </Link>
                        ) : (
                            <button
                                type="button"
                                onClick={spin}
                                disabled={phase === 'spinning' || ! canAfford}
                                className="flex w-full items-center justify-center gap-3 rounded-2xl bg-gradient-to-b from-yellow-300 to-amber-500 py-4 text-xl font-black uppercase text-amber-950 shadow-[0_6px_0_#b45309,0_12px_30px_rgba(250,204,21,0.35)] transition-transform active:translate-y-1 active:shadow-[0_2px_0_#b45309] disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <Sparkles className="h-5 w-5" />
                                {phase === 'spinning' ? 'Spinning…' : freeSpins > 0 ? 'Free spin' : history.length ? 'Spin again' : 'Spin'}
                                <span className="rounded-lg bg-white/40 px-2 py-0.5 text-xs font-bold normal-case">
                                    {freeSpins > 0 ? `${freeSpins} free left` : `−${cost} pts`}
                                </span>
                            </button>
                        )}

                        {! isGuest && ! paused && points !== null && ! canAfford && phase !== 'spinning' && (
                            <p className="mt-3 text-center text-sm text-white/80">
                                You need {cost - points} more point{cost - points === 1 ? '' : 's'} to spin.{' '}
                                <Link href="/rewards" className="font-bold text-yellow-300 underline">
                                    Earn points
                                </Link>
                            </p>
                        )}

                        {error && (
                            <p role="alert" className="mt-3 rounded-xl bg-red-500/20 px-3 py-2 text-center text-sm text-red-100">
                                {error}
                            </p>
                        )}
                    </div>

                    {/* This visit */}
                    {history.length > 0 && (
                        <section className="mt-6 rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur">
                            <div className="mb-3 grid grid-cols-3 text-center">
                                <div>
                                    <p className="text-lg font-black">{history.length}</p>
                                    <p className="text-[10px] uppercase tracking-wider text-white/50">Spins</p>
                                </div>
                                <div>
                                    <p className="text-lg font-black">{spinsWon}</p>
                                    <p className="text-[10px] uppercase tracking-wider text-white/50">Points won</p>
                                </div>
                                <div>
                                    <p className={`text-lg font-black ${net >= 0 ? 'text-green-400' : 'text-red-300'}`}>
                                        {net > 0 ? '+' : ''}
                                        {net}
                                    </p>
                                    <p className="text-[10px] uppercase tracking-wider text-white/50">After costs</p>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {history.map((h, i) => (
                                    <span key={history.length - i} className={`rounded-md px-2 py-0.5 text-[11px] font-bold ${CHIP[h.outcome] ?? 'bg-white/20'}`}>
                                        {h.payout}
                                    </span>
                                ))}
                            </div>
                        </section>
                    )}

                    <button
                        type="button"
                        onClick={() => setShowOdds(true)}
                        className="mx-auto mt-6 flex items-center gap-1.5 text-xs font-bold text-white/70 underline-offset-4 hover:underline"
                    >
                        <Info className="h-3.5 w-3.5" /> Odds &amp; how it works
                    </button>
                </div>

                {showOdds && <OddsSheet odds={odds} cost={cost} averageBack={averageBack} onClose={() => setShowOdds(false)} />}

                {phase === 'result' && result && (
                    <ResultOverlay
                        result={result}
                        cost={cost}
                        canSpinAgain={isOn(site, 'spin') && (freeSpins > 0 || points >= cost)}
                        onAgain={() => {
                            setPhase('ready');
                            spin();
                        }}
                        onClose={() => setPhase('ready')}
                    />
                )}
            </div>
        </>
    );
}

function ResultOverlay({ result, cost, canSpinAgain, onAgain, onClose }) {
    const amount = useCountUp(result.payout, 900);
    const jackpot = result.outcome === 'jackpot';
    const title = result.profit > 0 ? (jackpot ? 'JACKPOT!' : 'YOU WON!') : result.profit === 0 ? 'POINTS BACK' : 'SO CLOSE!';
    const line =
        result.profit > 0
            ? `That's ${result.profit} more than the spin cost.`
            : result.profit === 0
              ? 'You got your points back. Go again?'
              : `You won ${result.payout} points back. The next one could be big.`;

    return (
        <>
            {result.profit > 0 && <Confetti pieces={jackpot ? 320 : 170} duration={jackpot ? 5000 : 3500} />}
            <div className="fixed inset-0 z-[100] flex items-end justify-center bg-black/70 p-4 backdrop-blur-sm sm:items-center" onClick={onClose}>
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-label={title}
                    onClick={(e) => e.stopPropagation()}
                    className={`relative w-full max-w-sm animate-winner-flash overflow-hidden rounded-[2rem] border p-7 text-center shadow-2xl ${
                        result.profit > 0 ? 'border-yellow-300/60 bg-gradient-to-b from-amber-400 to-orange-600' : 'border-white/15 bg-gradient-to-b from-indigo-800 to-violet-950'
                    }`}
                >
                    <div className="mx-auto mb-3 flex h-20 w-20 items-center justify-center rounded-full bg-white/20 ring-4 ring-white/30">
                        <Gift className="h-10 w-10 text-white" />
                    </div>
                    <h2 className="text-4xl font-black italic tracking-tight text-white drop-shadow">{title}</h2>
                    <p className="mt-2 font-mono text-6xl font-black tabular-nums text-white drop-shadow-lg">+{amount}</p>
                    <p className="text-xs font-bold uppercase tracking-widest text-white/80">points</p>
                    <p className="mt-3 text-sm text-white/90">{line}</p>
                    <p className="mt-1 text-xs text-white/70">New balance: {result.new_balance} pts</p>

                    <div className="mt-6 space-y-2">
                        {canSpinAgain && (
                            <button
                                type="button"
                                onClick={onAgain}
                                className="w-full rounded-2xl bg-white py-3.5 text-base font-black uppercase text-gray-900 shadow-lg active:scale-95"
                            >
                                Spin again <span className="text-xs font-bold text-gray-500">−{cost} pts</span>
                            </button>
                        )}
                        <button type="button" onClick={onClose} className="w-full py-2 text-sm font-bold text-white/85">
                            {canSpinAgain ? 'Done' : 'Close'}
                        </button>
                        {! canSpinAgain && (
                            <Link href="/rewards" className="block text-sm font-bold text-white underline">
                                Earn more points
                            </Link>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

function OddsSheet({ odds, cost, averageBack, onClose }) {
    return (
        <div className="fixed inset-0 z-[90] flex items-end justify-center bg-black/60 backdrop-blur-sm sm:items-center" onClick={onClose}>
            <div
                role="dialog"
                aria-modal="true"
                aria-label="Odds and how it works"
                onClick={(e) => e.stopPropagation()}
                className="w-full max-w-md rounded-t-3xl bg-white p-6 text-gray-900 sm:rounded-3xl dark:bg-dark-card dark:text-white"
            >
                <div className="mb-4 flex items-center justify-between">
                    <h2 className="text-lg font-black">Odds &amp; how it works</h2>
                    <button type="button" onClick={onClose} aria-label="Close" className="text-gray-400">
                        <X className="h-5 w-5" />
                    </button>
                </div>
                <table className="mb-4 w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase text-gray-400">
                            <th className="pb-2">Prize</th>
                            <th className="pb-2 text-right">Chance</th>
                        </tr>
                    </thead>
                    <tbody>
                        {odds.map((o) => (
                            <tr key={o.outcome} className="border-t border-gray-100 dark:border-gray-800">
                                <td className="py-2 font-bold">
                                    <span className={`mr-2 inline-block h-2.5 w-2.5 rounded-full ${(CHIP[o.outcome] ?? 'bg-gray-400').split(' ')[0]}`} />
                                    {o.payout} points
                                </td>
                                <td className="py-2 text-right tabular-nums">{(o.probability * 100).toFixed(o.probability < 0.1 ? 1 : 0)}%</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                <ul className="space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <li>Each spin costs {cost} points. The slices on the wheel are sized by these real chances.</li>
                    <li>The prize is picked by our server with a secure random draw before the wheel moves. The wheel only shows the result; tapping or flicking it doesn't change anything.</li>
                    <li>On average a spin pays back about {averageBack} points. Play for fun.</li>
                </ul>
                <button type="button" onClick={onClose} className="mt-5 w-full rounded-xl bg-gray-900 py-3 text-sm font-bold text-white dark:bg-white dark:text-gray-900">
                    Got it
                </button>
            </div>
        </div>
    );
}
