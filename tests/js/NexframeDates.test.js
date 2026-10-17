import assert from 'node:assert/strict';
import test from 'node:test';
import { formatDate, formatDateTime, formatDateRange } from '../../resources/js/components/nexframe/dates.js';

test('calendar dates use readable day, month and year without changing the date', () => {
    assert.equal(formatDate('2026-10-14'), '14 Oct 2026');
    assert.equal(formatDate('2026-01-01'), '1 Jan 2026');
    assert.equal(formatDate('2026-10-14', { short: true }), '14 Oct');
});

test('report ranges show both years and collapse single-day ranges', () => {
    assert.equal(formatDateRange('2026-10-14', '2026-10-17'), '14 Oct 2026 – 17 Oct 2026');
    assert.equal(formatDateRange('2026-12-31', '2027-01-01'), '31 Dec 2026 – 1 Jan 2027');
    assert.equal(formatDateRange('2026-10-14', '2026-10-14'), '14 Oct 2026');
});

test('sync and post timestamps consistently use India reporting time', () => {
    const value = formatDateTime('2026-10-14T23:30:00Z');
    assert.match(value, /15 Oct 2026/);
    assert.match(value, /5:00/);
    assert.match(value, /IST|GMT\+5:30/);
});

test('missing or invalid dates have a safe placeholder', () => {
    for (const value of [null, undefined, '', 'not-a-date']) {
        assert.equal(formatDate(value), '—');
        assert.equal(formatDateTime(value), '—');
    }
});
