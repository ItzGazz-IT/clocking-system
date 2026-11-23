'use strict';

(() => {
    const notice = document.querySelector('[data-dashboard-refresh]');
    if (!notice) return;
    let refreshing = false;
    const refresh = async () => {
        if (refreshing || document.hidden) return;
        // Preserve keyboard focus while someone is using a report link.
        if (document.activeElement?.closest('[data-dashboard-live]')) return;
        refreshing = true;
        const timeout = new AbortController();
        const timeoutId = setTimeout(() => timeout.abort(), 15000);
        try {
            const response = await fetch(window.location.href, {
                credentials: 'same-origin', cache: 'no-store', signal: timeout.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) throw new Error('refresh_failed');
            const next = new DOMParser().parseFromString(await response.text(), 'text/html');
            const regions = [...document.querySelectorAll('[data-dashboard-live]')];
            if (!next.querySelector('[data-dashboard-refresh]') || regions.some(region => !next.getElementById(region.id))) {
                notice.textContent = 'Session changed. Refresh the page to continue.';
                return;
            }
            // No scripts from fetched HTML run; only the read-only dashboard regions update.
            for (const region of regions) region.replaceWith(next.getElementById(region.id));
            notice.textContent = next.querySelector('[data-dashboard-refresh]').textContent;
            const nextDate = next.querySelector('[data-dashboard-date]');
            if (nextDate) document.querySelector('[data-dashboard-date]').textContent = nextDate.textContent;
        } catch {
            notice.textContent = 'Update unavailable · showing the last loaded figures. Retrying automatically.';
        } finally {
            clearTimeout(timeoutId);
            refreshing = false;
        }
    };
    setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
