import test from 'node:test';
import assert from 'node:assert/strict';
import { watchTime, dataUsage, money, dateLabel, statsQuery, watchLink } from '../../resources/js/lib/customerStats.js';

test('unavailable and zero usage stay distinct', () => {
    assert.equal(watchTime(null), 'Not available');
    assert.equal(watchTime(0), '0 min');
    assert.equal(watchTime(20), 'Less than 1 min');
    assert.equal(watchTime(3660), '1h 1m');
    assert.equal(dataUsage(null), 'Not recorded');
    assert.equal(dataUsage(0), '0 MB');
    assert.equal(dataUsage(0.2), 'Less than 1 MB');
    assert.equal(dataUsage(1536), '1.5 GB');
});

test('period queries omit stale custom dates and safely encode search text', () => {
    const month = new URLSearchParams(statsQuery({ period: 'month', from: '2020-01-01', to: '2020-12-31' }, { q: 'A & B', page: 2 }));
    assert.equal(month.has('from'), false);
    assert.equal(month.get('q'), 'A & B');
    assert.equal(month.get('page'), '2');
    const custom = new URLSearchParams(statsQuery({ period: 'custom', from: '2026-10-01', to: '2026-10-31' }, { status: 'paid', device: 'mobile' }));
    assert.equal(custom.get('from'), '2026-10-01');
    assert.equal(custom.get('to'), '2026-10-31');
    assert.equal(custom.get('status'), 'paid');
});

test('dates and currency do not invent missing payment details', () => {
    assert.equal(dateLabel(null), 'Not recorded');
    assert.equal(dateLabel('not-a-date'), 'Not recorded');
    assert.match(dateLabel('2026-10-01'), /1 Oct 2026/);
    assert.equal(money(null, 'INR'), 'Not recorded');
    assert.equal(money(99, null), '99.00');
    assert.match(money(99, 'INR'), /99\.00/);
    assert.doesNotThrow(() => money(99, 'bad-currency'));
});

test('continue watching links to the title with an encoded content identifier', () => {
    assert.equal(watchLink({ movie: { id: 'film 1' } }), 'https://app.zostream.in/watch/film%201');
    assert.equal(watchLink({ movie_id: 'series-1', episode_id: 'episode-1' }), 'https://app.zostream.in/watch/series-1');
    assert.equal(watchLink({}), null);
});
