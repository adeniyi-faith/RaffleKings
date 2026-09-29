// Tiny sound effects for the Spin & Win game, made in the browser with the
// Web Audio API (no audio files to download). Muting is remembered on
// this device. Every call is safe to make when sound is unavailable.
const KEY = 'rk_game_sound';
let ctx = null;
let lastTick = 0;

export function soundOn() {
    try {
        return localStorage.getItem(KEY) !== 'off';
    } catch {
        return true;
    }
}

export function setSoundOn(on) {
    try {
        localStorage.setItem(KEY, on ? 'on' : 'off');
    } catch {
        // private mode: just not remembered
    }
}

// Browsers only allow sound after a tap, so this is called from one.
export function unlockSound() {
    try {
        ctx ??= new (window.AudioContext || window.webkitAudioContext)();
        if (ctx.state === 'suspended') ctx.resume();
    } catch {
        ctx = null;
    }
}

function tone(freq, start, length, { type = 'sine', gain = 0.15, slideTo = null } = {}) {
    if (! ctx || ! soundOn()) return;

    const t = ctx.currentTime + start;
    const osc = ctx.createOscillator();
    const amp = ctx.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, t);
    if (slideTo) osc.frequency.exponentialRampToValueAtTime(slideTo, t + length);
    amp.gain.setValueAtTime(gain, t);
    amp.gain.exponentialRampToValueAtTime(0.0001, t + length);
    osc.connect(amp).connect(ctx.destination);
    osc.start(t);
    osc.stop(t + length + 0.02);
}

/** The pointer clicking past a slice. */
export function tick() {
    const now = performance.now();
    if (now - lastTick < 35) return; // very fast spins: don't buzz
    lastTick = now;
    tone(1400, 0, 0.03, { type: 'square', gain: 0.05 });
    try {
        navigator.vibrate?.(6);
    } catch {
        // not supported
    }
}

export function winSound(big = false) {
    const notes = big ? [523, 659, 784, 1047, 1319, 1568] : [523, 659, 784, 1047];
    notes.forEach((f, i) => tone(f, i * 0.09, 0.25, { type: 'triangle', gain: 0.18 }));
    if (big) tone(2093, notes.length * 0.09, 0.6, { type: 'triangle', gain: 0.12 });
    try {
        navigator.vibrate?.(big ? [60, 40, 60, 40, 120] : [40, 30, 60]);
    } catch {
        // not supported
    }
}

export function evenSound() {
    tone(660, 0, 0.18, { type: 'triangle' });
    tone(880, 0.12, 0.22, { type: 'triangle' });
}

export function loseSound() {
    tone(392, 0, 0.35, { type: 'sine', gain: 0.12, slideTo: 262 });
}
