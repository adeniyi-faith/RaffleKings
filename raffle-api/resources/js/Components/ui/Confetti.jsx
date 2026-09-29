import { useEffect, useRef } from 'react';

const COLORS = ['#facc15', '#2563eb', '#22c55e', '#f97316', '#ec4899', '#a855f7'];

// A short confetti burst drawn on a canvas over the whole screen (the
// ticket-purchase success screen, item 46). No library: a few hundred
// paper pieces with gravity, gone after a few seconds. Skipped for anyone
// whose phone asks for less motion.
export default function Confetti({ pieces = 160, duration = 3500 }) {
    const canvasRef = useRef(null);

    useEffect(() => {
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            return undefined;
        }

        const canvas = canvasRef.current;
        const ctx = canvas?.getContext('2d');

        if (! ctx) {
            return undefined;
        }

        const ratio = window.devicePixelRatio || 1;
        const width = window.innerWidth;
        const height = window.innerHeight;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        ctx.scale(ratio, ratio);

        const parts = Array.from({ length: pieces }, () => ({
            x: width / 2 + (Math.random() - 0.5) * width * 0.3,
            y: height * 0.35,
            vx: (Math.random() - 0.5) * 14,
            vy: -Math.random() * 14 - 4,
            size: Math.random() * 6 + 4,
            spin: Math.random() * Math.PI,
            spinSpeed: (Math.random() - 0.5) * 0.3,
            color: COLORS[Math.floor(Math.random() * COLORS.length)],
        }));

        const started = performance.now();
        let frame;

        function draw(now) {
            const elapsed = now - started;
            ctx.clearRect(0, 0, width, height);
            ctx.globalAlpha = Math.max(0, 1 - Math.max(0, elapsed - duration * 0.6) / (duration * 0.4));

            for (const p of parts) {
                p.vy += 0.35;
                p.vx *= 0.99;
                p.x += p.vx;
                p.y += p.vy;
                p.spin += p.spinSpeed;

                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.spin);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
                ctx.restore();
            }

            if (elapsed < duration) {
                frame = requestAnimationFrame(draw);
            } else {
                ctx.clearRect(0, 0, width, height);
            }
        }

        frame = requestAnimationFrame(draw);

        return () => cancelAnimationFrame(frame);
    }, [pieces, duration]);

    return <canvas ref={canvasRef} aria-hidden="true" className="pointer-events-none fixed inset-0 z-[110] h-full w-full" />;
}
