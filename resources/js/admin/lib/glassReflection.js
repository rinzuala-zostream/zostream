// Delegate to the current page so routed, conditional and teleported panels work.
// Painting a background layer keeps controls clickable and text above the glare.
const surfaces = [
    '.admin-sidebar', '.admin-page-header', '.admin-panel',
    '.admin-stat-grid article', '.billing-stats article', '.admin-table-card',
    '.admin-filter-bar', '.admin-list-toolbar', '.analytics-metrics article',
    '.ppv-summary article', '.ppv-detail-card', '.ad-review-stats button',
    '.analytics-filters', '.detail-filter-row', '.analytics-tabs',
    '.admin-multi-options', '.admin-relation-results', '.admin-episode-ppv-dialog',
    '.whatsapp-widget', '.admin-login-card',
].join(',');

export function installGlassReflection() {
    const preference = window.matchMedia('(hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)');
    let current = null;
    let savedSheen = '';
    let savedPriority = '';
    let frame = 0;
    let pointer = null;

    function clearSurface() {
        if (!current) return;
        if (savedSheen) current.style.setProperty('--glass-sheen', savedSheen, savedPriority);
        else current.style.removeProperty('--glass-sheen');
        current.style.removeProperty('--glass-pointer-x');
        current.style.removeProperty('--glass-pointer-y');
        current = null;
    }

    function reset() {
        cancelAnimationFrame(frame);
        frame = 0;
        pointer = null;
        clearSurface();
    }

    function paint() {
        frame = 0;
        if (!pointer || !preference.matches) return reset();
        const target = document.elementFromPoint(pointer.x, pointer.y)?.closest(surfaces);
        if (!target || !target.closest('.admin-root, .admin-login')) return clearSurface();
        if (current !== target) {
            clearSurface();
            current = target;
            savedSheen = target.style.getPropertyValue('--glass-sheen');
            savedPriority = target.style.getPropertyPriority('--glass-sheen');
            const sheen = getComputedStyle(target).getPropertyValue('--glass-sheen').trim();
            target.style.setProperty('--glass-sheen',
                `radial-gradient(280px circle at var(--glass-pointer-x) var(--glass-pointer-y), rgba(224, 235, 255, .15), rgba(164, 184, 255, .055) 42%, transparent 72%), ${sheen || 'linear-gradient(transparent, transparent)'}`);
        }
        const bounds = target.getBoundingClientRect();
        target.style.setProperty('--glass-pointer-x', `${pointer.x - bounds.left}px`);
        target.style.setProperty('--glass-pointer-y', `${pointer.y - bounds.top}px`);
    }

    function move(event) {
        if (event.pointerType !== 'mouse' || !preference.matches) return reset();
        pointer = { x: event.clientX, y: event.clientY };
        if (!frame) frame = requestAnimationFrame(paint);
    }

    document.addEventListener('pointermove', move, { passive: true });
    document.documentElement.addEventListener('pointerleave', reset);
    window.addEventListener('blur', reset);
    // Drop stale coordinates when the page or an inner table scrolls.
    document.addEventListener('scroll', reset, { capture: true, passive: true });
    preference.addEventListener('change', reset);
    return () => {
        reset();
        document.removeEventListener('pointermove', move);
        document.documentElement.removeEventListener('pointerleave', reset);
        window.removeEventListener('blur', reset);
        document.removeEventListener('scroll', reset, true);
        preference.removeEventListener('change', reset);
    };
}
