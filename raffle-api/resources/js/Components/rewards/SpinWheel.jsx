import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef } from 'react';
import { Star } from 'lucide-react';

const SIZE = 260;
const COLORS = {
    loss: { fill: '#64748b', text: '#ffffff' },
    tie: { fill: '#8b5cf6', text: '#ffffff' },
    win: { fill: '#22c55e', text: '#ffffff' },
    jackpot: { fill: '#facc15', text: '#713f12' },
};
const SPARE = [
    { fill: '#3b82f6', text: '#ffffff' },
    { fill: '#f97316', text: '#ffffff' },
    { fill: '#ec4899', text: '#ffffff' },
];

const easeOutQuart = (x) => 1 - (1 - x) ** 4;

// The Spin & Win wheel (item 47), rebuilt from the old games.php canvas
// wheel. Each slice is sized by its REAL chance (the same odds printed
// under it), not equal quarters. The server decides the prize first;
// spinTo(index) only animates the wheel onto the slice it already chose,
// so the animation can never change what anyone wins.
const SpinWheel = forwardRef(function SpinWheel({ odds, spinning }, ref) {
    const canvasRef = useRef(null);
    const rotation = useRef(0);

    const slices = useMemo(() => {
        const total = odds.reduce((sum, o) => sum + o.probability, 0) || 1;
        let cursor = 0;

        return odds.map((o, i) => {
            const arc = (o.probability / total) * Math.PI * 2;
            const slice = { ...o, start: cursor, arc, colors: COLORS[o.outcome] ?? SPARE[i % SPARE.length] };
            cursor += arc;

            return slice;
        });
    }, [odds]);

    function draw(angle) {
        const canvas = canvasRef.current;
        const ctx = canvas?.getContext('2d');

        if (! ctx) {
            return;
        }

        const ratio = window.devicePixelRatio || 1;

        if (canvas.width !== SIZE * ratio) {
            canvas.width = SIZE * ratio;
            canvas.height = SIZE * ratio;
        }

        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        const c = SIZE / 2;
        const radius = c - 4;

        ctx.clearRect(0, 0, SIZE, SIZE);
        ctx.save();
        ctx.translate(c, c);
        ctx.rotate(angle);

        for (const s of slices) {
            ctx.beginPath();
            ctx.moveTo(0, 0);
            ctx.arc(0, 0, radius, s.start, s.start + s.arc);
            ctx.closePath();
            ctx.fillStyle = s.colors.fill;
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
            ctx.font = `900 ${s.arc < 0.35 ? 12 : 16}px Inter, system-ui, sans-serif`;
            ctx.fillText(`${s.payout} pts`, radius - 12, 0);
            ctx.restore();
        }

        ctx.restore();

        // Rim
        ctx.beginPath();
        ctx.arc(c, c, radius, 0, Math.PI * 2);
        ctx.strokeStyle = 'rgba(255,255,255,0.9)';
        ctx.lineWidth = 5;
        ctx.stroke();
    }

    useEffect(() => {
        draw(rotation.current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [slices]);

    useImperativeHandle(ref, () => ({
        /** Spin onto slice `index` (from the server's visual_index); resolves when it stops. */
        spinTo(index) {
            return new Promise((resolve) => {
                const slice = slices[index];

                if (! slice || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
                    if (slice) {
                        rotation.current = -Math.PI / 2 - (slice.start + slice.arc / 2);
                        draw(rotation.current);
                    }
                    resolve();
                    return;
                }

                // Land somewhere inside the slice (not always dead centre),
                // under the pointer at the top, after five full turns.
                const target = -Math.PI / 2 - (slice.start + slice.arc / 2) + (Math.random() - 0.5) * slice.arc * 0.7;
                const current = rotation.current;
                const full = Math.PI * 2;
                const delta = (((target - current) % full) + full) % full;
                const totalTurn = full * 5 + delta;
                const duration = 4200;
                const started = performance.now();

                function frame(now) {
                    const t = Math.min(1, (now - started) / duration);
                    rotation.current = current + totalTurn * easeOutQuart(t);
                    draw(rotation.current);

                    if (t < 1) {
                        requestAnimationFrame(frame);
                    } else {
                        resolve();
                    }
                }

                requestAnimationFrame(frame);
            });
        },
    }));

    return (
        <div className="relative mx-auto flex h-[290px] w-[290px] items-center justify-center">
            <div
                aria-hidden="true"
                className={`absolute inset-0 rounded-full bg-[radial-gradient(circle,rgba(250,204,21,0.55)_0%,rgba(250,204,21,0)_70%)] ${spinning ? 'scale-110 opacity-100' : 'animate-pulse opacity-70'} transition-all duration-500`}
            />
            <div className="relative drop-shadow-2xl">
                <svg aria-hidden="true" width="34" height="40" viewBox="0 0 24 24" className="absolute -top-5 left-1/2 z-20 -translate-x-1/2 text-red-600 drop-shadow-md">
                    <path fill="currentColor" d="M12 22L7 12H17L12 22ZM12 2C13.5 2 14.5 3 14.5 4.5V10H9.5V4.5C9.5 3 10.5 2 12 2Z" />
                </svg>
                <canvas
                    ref={canvasRef}
                    role="img"
                    aria-label={`Prize wheel: ${odds.map((o) => `${o.payout} points, ${Math.round(o.probability * 100)}% chance`).join('; ')}`}
                    style={{ width: SIZE, height: SIZE }}
                />
                <div className="absolute left-1/2 top-1/2 z-10 flex h-14 w-14 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-4 border-gray-100 bg-white shadow-[0_0_15px_rgba(0,0,0,0.25)] dark:border-gray-800 dark:bg-dark-card">
                    <Star className="h-6 w-6 animate-pulse fill-current text-yellow-500" />
                </div>
            </div>
        </div>
    );
});

export default SpinWheel;
