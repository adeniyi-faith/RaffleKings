import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react';
import { Star } from 'lucide-react';

const COLORS = {
    loss: { fill: '#475569', text: '#ffffff' },
    tie: { fill: '#8b5cf6', text: '#ffffff' },
    win: { fill: '#16a34a', text: '#ffffff' },
    jackpot: { fill: '#facc15', text: '#713f12' },
};
const SPARE = [
    { fill: '#2563eb', text: '#ffffff' },
    { fill: '#f97316', text: '#ffffff' },
    { fill: '#db2777', text: '#ffffff' },
];
const FULL = Math.PI * 2;
const FREE_SPEED = FULL * 1.6; // radians a second while waiting for the result
const BULBS = 24;

const easeOutCubic = (t) => 1 - (1 - t) ** 3;

// The Spin & Win wheel (items 47 and the Spin & Win game page). Each slice
// is sized by its REAL chance, the same odds printed with it. The server
// decides the prize: start() spins freely the moment the player taps, and
// landOn(index) then slows down onto the slice the server already chose,
// so the animation can never change what anyone wins. onTick fires each
// time a slice edge passes the pointer (for the click sound).
const SpinWheel = forwardRef(function SpinWheel({ odds, size = 260, onTick, lights = false }, ref) {
    const canvasRef = useRef(null);
    const rotation = useRef(0);
    const frame = useRef(null);
    const lastSlice = useRef(null);
    const [pointerKick, setPointerKick] = useState(0);
    const [mode, setMode] = useState('idle'); // idle | spinning | done
    const reduceMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

    const slices = useMemo(() => {
        const total = odds.reduce((sum, o) => sum + o.probability, 0) || 1;
        let cursor = 0;

        return odds.map((o, i) => {
            const arc = (o.probability / total) * FULL;
            const slice = { ...o, start: cursor, arc, colors: COLORS[o.outcome] ?? SPARE[i % SPARE.length] };
            cursor += arc;

            return slice;
        });
    }, [odds]);

    function sliceUnderPointer(angle) {
        // The pointer sits at the top (-90°); find which slice is there.
        const at = ((((-Math.PI / 2 - angle) % FULL) + FULL) % FULL);

        return slices.findIndex((s) => at >= s.start && at < s.start + s.arc);
    }

    function draw(angle) {
        const canvas = canvasRef.current;
        const ctx = canvas?.getContext('2d');

        if (! ctx) return;

        const ratio = window.devicePixelRatio || 1;

        if (canvas.width !== Math.round(size * ratio)) {
            canvas.width = Math.round(size * ratio);
            canvas.height = Math.round(size * ratio);
        }

        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        const c = size / 2;
        const radius = c - 4;
        const font = Math.max(12, Math.round(size / 16));

        ctx.clearRect(0, 0, size, size);
        ctx.save();
        ctx.translate(c, c);
        ctx.rotate(angle);

        for (const s of slices) {
            ctx.beginPath();
            ctx.moveTo(0, 0);
            ctx.arc(0, 0, radius, s.start, s.start + s.arc);
            ctx.closePath();
            const shade = ctx.createRadialGradient(0, 0, radius * 0.2, 0, 0, radius);
            shade.addColorStop(0, s.colors.fill);
            shade.addColorStop(1, `${s.colors.fill}cc`);
            ctx.fillStyle = shade;
            ctx.fill();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 3;
            ctx.stroke();

            // Labels run along the radius, so even a thin slice (a rare
            // jackpot) still shows its prize.
            ctx.save();
            ctx.rotate(s.start + s.arc / 2);
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = s.colors.text;
            ctx.font = `900 ${s.arc < 0.35 ? Math.round(font * 0.75) : font}px Inter, system-ui, sans-serif`;
            ctx.shadowColor = 'rgba(0,0,0,0.25)';
            ctx.shadowBlur = 3;
            ctx.fillText(`${s.payout} pts`, radius - 14, 0);
            ctx.restore();
        }

        ctx.restore();

        ctx.beginPath();
        ctx.arc(c, c, radius, 0, FULL);
        ctx.strokeStyle = 'rgba(255,255,255,0.95)';
        ctx.lineWidth = 5;
        ctx.stroke();

        const under = sliceUnderPointer(angle);

        if (lastSlice.current !== null && under !== lastSlice.current) {
            onTick?.();
            setPointerKick((k) => k + 1);
        }

        lastSlice.current = under;
    }

    useEffect(() => {
        draw(rotation.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [slices, size]);

    useEffect(() => () => cancelAnimationFrame(frame.current), []);

    useImperativeHandle(ref, () => ({
        /** Start spinning straight away, while the server decides. */
        start() {
            cancelAnimationFrame(frame.current);
            setMode('spinning');

            if (reduceMotion) return;

            let last = performance.now();
            const began = last;

            function run(now) {
                // Wind up over the first half second.
                const speed = FREE_SPEED * Math.min(1, (now - began) / 500);
                rotation.current += speed * ((now - last) / 1000);
                last = now;
                draw(rotation.current);
                frame.current = requestAnimationFrame(run);
            }

            frame.current = requestAnimationFrame(run);
        },

        /** Slow down onto slice `index` (the server's visual_index). Resolves when it stops. */
        landOn(index) {
            cancelAnimationFrame(frame.current);

            return new Promise((resolve) => {
                const slice = slices[index];
                const finish = () => {
                    setMode('done');
                    resolve();
                };

                if (! slice) {
                    finish();
                    return;
                }

                // Somewhere inside the slice (not always dead centre), under the pointer.
                const target = -Math.PI / 2 - (slice.start + slice.arc / 2) + (Math.random() - 0.5) * slice.arc * 0.7;

                if (reduceMotion) {
                    rotation.current = target;
                    draw(target);
                    finish();
                    return;
                }

                const from = rotation.current;
                const delta = FULL * 2 + ((((target - from) % FULL) + FULL) % FULL);
                // Ease-out that starts at the free-spin speed, so there's no jolt.
                const duration = Math.min(6000, Math.max(2500, (3 * delta * 1000) / FREE_SPEED));
                const began = performance.now();

                function run(now) {
                    const t = Math.min(1, (now - began) / duration);
                    rotation.current = from + delta * easeOutCubic(t);
                    draw(rotation.current);

                    if (t < 1) {
                        frame.current = requestAnimationFrame(run);
                    } else {
                        finish();
                    }
                }

                frame.current = requestAnimationFrame(run);
            });
        },

        /** The spin failed: coast to a stop. */
        stop() {
            cancelAnimationFrame(frame.current);
            const from = rotation.current;
            const began = performance.now();

            function run(now) {
                const t = Math.min(1, (now - began) / 1200);
                rotation.current = from + FULL * 0.5 * easeOutCubic(t);
                draw(rotation.current);
                if (t < 1) frame.current = requestAnimationFrame(run);
                else setMode('idle');
            }

            frame.current = requestAnimationFrame(run);
        },

        /** Old one-call form: spin onto a result that's already known. */
        spinTo(index) {
            this.start();

            return this.landOn(index);
        },
    }));

    const ring = size + 34;

    return (
        <div className="relative mx-auto flex items-center justify-center" style={{ width: ring, height: ring }}>
            <div
                aria-hidden="true"
                className={`absolute -inset-6 rounded-full bg-[radial-gradient(circle,rgba(250,204,21,0.55)_0%,rgba(250,204,21,0)_70%)] transition-all duration-500 ${
                    mode === 'spinning' ? 'scale-110 opacity-100' : 'animate-pulse opacity-70'
                }`}
            />

            {lights && (
                <div aria-hidden="true" className="absolute inset-0 rounded-full bg-gradient-to-b from-amber-500 via-yellow-600 to-amber-700 shadow-[0_0_40px_rgba(250,204,21,0.45)]">
                    {Array.from({ length: BULBS }, (_, i) => {
                        const angle = (i / BULBS) * FULL;
                        const r = ring / 2 - 9;

                        return (
                            <span
                                key={i}
                                className={`absolute h-2.5 w-2.5 rounded-full bg-yellow-100 shadow-[0_0_8px_3px_rgba(254,240,138,0.9)] ${
                                    mode === 'spinning' ? 'animate-bulb-chase' : 'animate-bulb-blink'
                                }`}
                                style={{
                                    left: ring / 2 + r * Math.cos(angle) - 5,
                                    top: ring / 2 + r * Math.sin(angle) - 5,
                                    animationDelay: mode === 'spinning' ? `${(i % 6) * 0.08}s` : `${(i % 2) * 0.6}s`,
                                }}
                            />
                        );
                    })}
                </div>
            )}

            <div className="relative drop-shadow-2xl">
                <svg
                    key={pointerKick}
                    aria-hidden="true"
                    width={size / 7.5}
                    height={size / 6.5}
                    viewBox="0 0 24 24"
                    className="absolute left-1/2 z-20 origin-top -translate-x-1/2 animate-pointer-kick text-red-600 drop-shadow-md"
                    style={{ top: -size / 14 }}
                >
                    <path fill="currentColor" d="M12 22L7 12H17L12 22ZM12 2C13.5 2 14.5 3 14.5 4.5V10H9.5V4.5C9.5 3 10.5 2 12 2Z" />
                </svg>
                <canvas
                    ref={canvasRef}
                    role="img"
                    aria-label={`Prize wheel: ${odds.map((o) => `${o.payout} points, ${Math.round(o.probability * 100)}% chance`).join('; ')}`}
                    style={{ width: size, height: size }}
                />
                <div
                    className="absolute left-1/2 top-1/2 z-10 flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-4 border-amber-200 bg-white shadow-[0_0_15px_rgba(0,0,0,0.3)]"
                    style={{ width: size / 4.6, height: size / 4.6 }}
                >
                    <Star className="h-1/2 w-1/2 animate-pulse fill-current text-yellow-500" />
                </div>
            </div>
        </div>
    );
});

export default SpinWheel;
