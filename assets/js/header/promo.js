const RKPromo = (function() {
        const SEGMENTS = [
            { label: "₦500k", color: "#9333ea" },
            { label: "50% OFF", color: "#dc2626" },
            { label: "IPHONE", color: "#3b82f6" },
            { label: "X2 TIX", color: "#f97316" },
            { label: "₦300", color: "#22c55e" },
            { label: "MYSTERY", color: "#eab308" }
        ];

        const TARGET_INDEX = 4;
        const START_INDEX = 5;
        const DURATION = 4000;
        let canvas, ctx, size = 300, isSpinning = false;
        const arc = (2 * Math.PI) / SEGMENTS.length;
        const offset = -Math.PI / 2;
        let rotation = offset - (START_INDEX * arc) - (arc / 2);

        function easeOutQuart(x) { return 1 - Math.pow(1 - x, 4); }

        function initCanvas() {
            canvas = document.getElementById('rk-wheel-canvas');
            if(!canvas) return;
            const dpr = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            size = rect.width;
            canvas.width = rect.width * dpr;
            canvas.height = rect.height * dpr;
            ctx = canvas.getContext('2d');
            ctx.scale(dpr, dpr);
            drawWheel(rotation);
        }

        function drawWheel(currentRotation) {
            if(!ctx) return;
            const cx = size / 2, cy = size / 2, radius = size / 2 - 10, arc = (2 * Math.PI) / SEGMENTS.length;
            ctx.clearRect(0, 0, size, size);
            ctx.save();
            ctx.translate(cx, cy); ctx.rotate(currentRotation); ctx.translate(-cx, -cy);

            SEGMENTS.forEach((seg, i) => {
                const angle = i * arc;
                ctx.beginPath();
                ctx.moveTo(cx, cy); ctx.arc(cx, cy, radius, angle, angle + arc);
                ctx.fillStyle = seg.color; ctx.fill(); ctx.stroke();
                ctx.save();
                ctx.translate(cx, cy); ctx.rotate(angle + arc / 2);
                ctx.textAlign = "right"; ctx.fillStyle = "#fff"; ctx.font = "bold 14px Inter, sans-serif";
                ctx.fillText(seg.label, radius - 20, 5);
                ctx.restore();
            });
            ctx.restore();
        }

        function spin() {
            if(isSpinning) return;
            isSpinning = true;
            document.getElementById('rk-close-btn').style.display = 'none';
            document.getElementById('rk-spin-btn').innerText = "Good Luck...";
            document.getElementById('rk-spin-btn').classList.add('opacity-50', 'cursor-not-allowed');

            const segmentAngle = (2 * Math.PI) / SEGMENTS.length;
            const offset = -Math.PI / 2;
            const targetAngle = offset - (TARGET_INDEX * segmentAngle) - (segmentAngle / 2);
            const spins = 5 * 2 * Math.PI;
            const startRot = rotation % (2 * Math.PI);
            const totalRot = spins + (targetAngle - startRot);
            const startTime = performance.now();

            function animate(time) {
                const elapsed = time - startTime;
                if (elapsed < DURATION) {
                    const t = elapsed / DURATION;
                    rotation = startRot + (totalRot * easeOutQuart(t));
                    drawWheel(rotation);
                    requestAnimationFrame(animate);
                } else {
                    rotation = startRot + totalRot;
                    drawWheel(rotation);
                    finishSpin();
                }
            }
            requestAnimationFrame(animate);
        }

        function finishSpin() {
            isSpinning = false;
            document.getElementById('rk-spin-btn').style.display = 'none';
            document.getElementById('rk-claim-area').classList.remove('hidden');
            document.getElementById('rk-claim-area').classList.add('fade-in');
            document.getElementById('rk-win-label').innerText = "You Won ₦300!";
            startConfetti();
        }

        function startConfetti() {
            const c = document.getElementById('rk-confetti');
            const x = c.getContext('2d');
            c.width = window.innerWidth; c.height = window.innerHeight;
            const particles = Array.from({length: 100}, () => ({
                x: c.width/2, y: c.height/2, vx: (Math.random()-0.5)*20, vy: (Math.random()-0.5)*20,
                color: `hsl(${Math.random()*360}, 70%, 50%)`, life: 100
            }));
            function loop() {
                x.clearRect(0,0,c.width,c.height);
                let active = false;
                particles.forEach(p => {
                    if(p.life > 0) {
                        active = true; p.x += p.vx; p.y += p.vy; p.vy += 0.5; p.life--;
                        x.fillStyle = p.color; x.beginPath(); x.arc(p.x, p.y, 5, 0, Math.PI*2); x.fill();
                    }
                });
                if(active) requestAnimationFrame(loop); else c.style.display = 'none';
            }
            loop();
        }

        function init() { initCanvas(); checkStatus(); }
        function checkStatus() {
            if(!localStorage.getItem('rk_wheel_spun')) {
                setTimeout(() => {
                    document.getElementById('rk-promo-modal').classList.add('open');
                    if(typeof lucide !== 'undefined') lucide.createIcons();
                }, 2000);
            }
        }
        function claim() {
            localStorage.setItem('rk_wheel_spun', 'true');
            localStorage.setItem('rk_promo_active', 'true');
            localStorage.setItem('rk_promo_expiry', Date.now() + (10 * 60 * 1000));
            window.location.href = 'raffles.php';
        }
        function closeModal() {
            localStorage.setItem('rk_wheel_spun', 'true');
            document.getElementById('rk-promo-modal').classList.remove('open');
        }

        return { init, spin, claim, closeModal };
    })();
    window.addEventListener('load', RKPromo.init);
