const surfaces = '.admin-page section';
const enabled = '(hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)';

export function installSectionTilt() {
    const preference = window.matchMedia(enabled);
    const states = new Map();
    let active = null;
    let frame = 0;

    function stateFor(element) {
        if (!states.has(element)) states.set(element, { x: 0, y: 0, targetX: 0, targetY: 0 });
        return states.get(element);
    }

    function animate() {
        frame = 0;
        for (const [element, state] of states) {
            state.x += (state.targetX - state.x) * 0.16;
            state.y += (state.targetY - state.y) * 0.16;
            if (Math.abs(state.targetX - state.x) < 0.015) state.x = state.targetX;
            if (Math.abs(state.targetY - state.y) < 0.015) state.y = state.targetY;
            element.style.setProperty('--section-tilt-x', `${state.x.toFixed(2)}deg`);
            element.style.setProperty('--section-tilt-y', `${state.y.toFixed(2)}deg`);
            if (state.x === 0 && state.y === 0 && state.targetX === 0 && state.targetY === 0) {
                element.style.removeProperty('--section-tilt-x');
                element.style.removeProperty('--section-tilt-y');
                states.delete(element);
            }
        }
        if (states.size) frame = requestAnimationFrame(animate);
    }

    function schedule() {
        if (!frame && states.size) frame = requestAnimationFrame(animate);
    }

    function resetActive() {
        if (!active) return;
        const state = stateFor(active);
        state.targetX = 0;
        state.targetY = 0;
        active = null;
        schedule();
    }

    function move(event) {
        if (event.pointerType !== 'mouse' || !preference.matches) return resetActive();
        const target = event.target.closest?.(surfaces);
        if (!target || event.target.closest('button')) return resetActive();
        if (active !== target) resetActive();
        active = target;

        const bounds = target.getBoundingClientRect();
        if (!bounds.width || !bounds.height) return resetActive();
        const x = Math.max(-1, Math.min(1, ((event.clientX - bounds.left) / bounds.width - 0.5) * 2));
        const y = Math.max(-1, Math.min(1, ((event.clientY - bounds.top) / bounds.height - 0.5) * 2));
        // A curved response keeps the center calm and builds more tilt near the edges.
        const curve = (value) => Math.sign(value) * Math.pow(Math.abs(value), 1.55);
        const state = stateFor(target);
        state.targetX = -curve(y) * 5;
        state.targetY = curve(x) * 5;
        schedule();
    }

    function reset() {
        resetActive();
        for (const state of states.values()) { state.targetX = 0; state.targetY = 0; }
        schedule();
    }

    document.addEventListener('pointermove', move, { passive: true });
    document.addEventListener('pointerleave', resetActive);
    document.addEventListener('scroll', reset, { capture: true, passive: true });
    window.addEventListener('blur', reset);
    preference.addEventListener('change', reset);
    return () => {
        document.removeEventListener('pointermove', move);
        document.removeEventListener('pointerleave', resetActive);
        document.removeEventListener('scroll', reset, true);
        window.removeEventListener('blur', reset);
        preference.removeEventListener('change', reset);
        if (frame) cancelAnimationFrame(frame);
        for (const element of states.keys()) {
            element.style.removeProperty('--section-tilt-x');
            element.style.removeProperty('--section-tilt-y');
        }
        states.clear();
    };
}
