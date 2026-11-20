import { test } from 'node:test';
import assert from 'node:assert/strict';
import { voucherPages, voucherDocument, QR_CODES_PER_PAGE, PPV_CODES_PER_PAGE } from '../../resources/js/admin/lib/redemptionPrint.js';

const codes = Array.from({ length: 24 }, (_, index) => ({
    id: index + 1, image: 'data:image/png;base64,fixture', status: 'available', rental_days: 7,
}));
const batch = { batch_id: 'fixture-batch', type: 'subscription', label: 'Community access', target_name: '30-day subscription' };

test('A4 sheets contain 12 distinct cards and preserve their order across pages', () => {
    assert.equal(QR_CODES_PER_PAGE, 12);
    const pages = voucherPages(codes);
    assert.equal(pages.length, 2);
    assert.deepEqual(pages.map(page => page.length), [12, 12]);
    assert.deepEqual(pages.flat().map(code => code.id), codes.map(code => code.id));
    const html = voucherDocument(batch, codes, 'data:image/png;base64,mobile-logo');
    assert.equal((html.match(/<section class="sheet">/g) || []).length, 2);
    assert.equal((html.match(/<article class="voucher /g) || []).length, 24);
    assert.match(html, /size: A4 portrait/);
    assert.match(html, /width: 210mm; height: 297mm/);
    assert.match(html, /30-DAY SUBSCRIPTION/);
    assert.match(html, /Add new or extend/);
    assert.equal((html.match(/class="brand-icon"/g) || []).length, 26);
    assert.equal((html.match(/<b>Zo Stream<\/b>/g) || []).length, 26);
    assert.doesNotMatch(html, />ZO<span>STREAM/);
    assert.match(html, /data:image\/png;base64,mobile-logo/);
});

test('PPV card uses movie details and unavailable codes are visibly marked', () => {
    const html = voucherDocument({ ...batch, type: 'ppv', target_name: 'A Film' }, [{ ...codes[0], status: 'redeemed' }]);
    assert.match(html, /PAY-PER-VIEW/);
    assert.match(html, /A Film/);
    assert.match(html, /7 days of access/);
    assert.match(html, /class="voucher ppv-ticket unavailable"/);
    assert.match(html, /REDEEMED/);
});

test('PPV sheets use two columns with posters and eight cards per page', () => {
    assert.equal(PPV_CODES_PER_PAGE, 8);
    const html = voucherDocument({ ...batch, type: 'ppv', content_type: 'episode', target_name: 'The Beginning',
        series_title: 'Sample Series', scope_label: 'Season 1 · Episode 2 only', poster_url: 'https://media.example.test/poster.jpg' }, codes.slice(0, 16));
    assert.equal((html.match(/<section class="sheet ppv-sheet">/g) || []).length, 2);
    assert.equal((html.match(/class="voucher ppv-ticket /g) || []).length, 16);
    assert.match(html, /grid-template-columns: repeat\(2, 1fr\)/);
    assert.match(html, /Sample Series/);
    assert.match(html, /Season 1 · Episode 2 only/);
    assert.match(html, /The Beginning/);
    assert.match(html, /EPISODE PASS/);
    assert.match(html, /src="https:\/\/media.example.test\/poster.jpg"/);
});

test('untrusted campaign labels and movie titles are escaped in the print document', () => {
    const html = voucherDocument({ ...batch, type: 'ppv', label: '<script>alert(1)</script>', target_name: '<img src=x onerror=alert(1)>' }, codes.slice(0, 1));
    assert.doesNotMatch(html, /<script>/);
    assert.doesNotMatch(html, /<img src=x/);
    assert.match(html, /&lt;script&gt;/);
    assert.match(html, /&lt;img src=x/);
});
