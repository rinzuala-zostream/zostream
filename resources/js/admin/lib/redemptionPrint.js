export const QR_CODES_PER_PAGE = 12;
export const PPV_CODES_PER_PAGE = 8;

const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));

export function voucherPages(codes, perPage = QR_CODES_PER_PAGE) {
    if (!Number.isInteger(perPage) || perPage < 1) throw new Error('Invalid sheet size.');
    return Array.from({ length: Math.ceil(codes.length / perPage) }, (_, index) => codes.slice(index * perPage, (index + 1) * perPage));
}

export function voucherDocument(batch, codes, logo = '') {
    const subscription = batch.type === 'subscription';
    const pages = voucherPages(codes, subscription ? QR_CODES_PER_PAGE : PPV_CODES_PER_PAGE);
    const title = subscription ? '30-day subscription' : batch.target_name || 'Movie access';
    const brand = (className = '') => `<div class="brand-lockup ${className}">${logo ? `<img class="brand-icon" src="${escape(logo)}" alt="Zo Stream mobile app logo" />` : ''}<div><b>Zo Stream</b><small>ENTERTAINMENT, YOURS.</small></div></div>`;
    const scope = batch.scope_label || 'Movie';
    const poster = batch.poster_url && /^(https?:\/\/|\/(?!\/)|data:image\/)/i.test(batch.poster_url) ? batch.poster_url : '';
    const bottom = code => `${code.expires_at ? `<div class="validity">Claim by ${escape(new Date(code.expires_at).toLocaleDateString())}</div>` : ''}<div class="card-bottom"><span>${code.status === 'available' ? 'SINGLE USE' : escape(code.status).toUpperCase()}</span><span class="scan-hint">Scan in the Zo Stream app</span><b>#${escape(String(code.id).padStart(4, '0'))}</b></div>`;
    const ppvCard = code => `<article class="voucher ppv-ticket ${code.status !== 'available' ? 'unavailable' : ''}">
        <div class="card-top">${brand('card-brand')}<strong>${escape((batch.content_type || 'movie').toUpperCase())} PASS</strong></div>
        <div class="ticket-body"><div class="ticket-poster">${poster ? `<img src="${escape(poster)}" alt="${escape(batch.series_title || title)} poster" />` : '<span>Zo Stream<br>PPV ACCESS</span>'}</div>
            <div class="ticket-copy"><span class="ticket-eyebrow">YOUR NEXT WATCH</span><h2>${escape(batch.series_title || title)}</h2><p class="ticket-scope">${escape(scope)}</p>${batch.series_title ? `<p class="ticket-title">${escape(title)}</p>` : ''}<b class="ticket-duration">${escape(code.rental_days)} days of access</b></div>
            <div class="ticket-scan"><div class="qr-wrap"><img src="${escape(code.image)}" alt="Activation QR ${escape(code.id)}" width="288" height="288" /></div><small>SCAN TO ACTIVATE</small></div>
        </div>${bottom(code)}</article>`;
    const sheets = pages.map((page, index) => `<section class="sheet${subscription ? '' : ' ppv-sheet'}">
        <header class="sheet-header"><div>${brand('sheet-brand')}<h1>${escape(batch.label)}</h1></div><div class="sheet-meta"><span>YOUR ACCESS. YOUR DEVICE.</span><strong>${subscription ? '30-DAY SUBSCRIPTION' : 'PAY-PER-VIEW'}</strong><small>A4 COLLECTION · ${String(index + 1).padStart(2, '0')} / ${String(pages.length).padStart(2, '0')}</small></div></header>
        <main class="cards">${page.map(code => !subscription ? ppvCard(code) : `<article class="voucher ${code.status !== 'available' ? 'unavailable' : ''}">
            <div class="card-top">${brand('card-brand')}<strong>${subscription ? '30 DAYS' : 'PPV PASS'}</strong></div>
            <div class="qr-wrap"><img src="${escape(code.image)}" alt="Activation QR ${escape(code.id)}" width="288" height="288" /></div>
            <div class="card-info"><h2>${escape(title)}</h2><p>${subscription ? 'Add new or extend your subscription' : `${escape(code.rental_days)} days of movie access`}</p></div>
            ${bottom(code)}
        </article>`).join('')}</main>
        <footer><div class="steps"><span><i>1</i> Open Zo Stream</span><span><i>2</i> Scan your card</span><span><i>3</i> Choose your device</span></div><span class="sheet-reference">${escape(String(batch.batch_id).slice(0, 8))} · Sheet ${index + 1}</span></footer>
    </section>`).join('');
    return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>${escape(batch.label)} — Zo Stream QR cards</title><style>
        @page { size: A4 portrait; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #e8edf1; color: #152c41; font-family: Arial, sans-serif; }
        .sheet { width: 210mm; height: 297mm; padding: 10mm; display: grid; grid-template-rows: 23mm 1fr 8mm; gap: 4mm; background: #fff; overflow: hidden; break-after: page; page-break-after: always; }
        .sheet:last-child { break-after: auto; page-break-after: auto; }
        .sheet-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 5mm; border-bottom: .6mm solid #099be7; padding-bottom: 3mm; }
        .brand-lockup { display: flex; align-items: center; gap: 2mm; }
        .brand-icon { width: 12mm; height: 12mm; object-fit: contain; background: #fff; border: .2mm solid #e4edf5; border-radius: 2.5mm; flex-shrink: 0; }
        .brand-lockup b { font-size: 22pt; font-weight: 800; letter-spacing: -.8pt; white-space: nowrap; }
        .brand-lockup small { display: block; margin-top: 1mm; font-size: 5.5pt; font-weight: 700; letter-spacing: 1.5pt; color: #4c7b98; }
        h1 { margin: 2mm 0 0; font-size: 8pt; color: #657a8c; font-weight: 400; max-width: 125mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sheet-meta { text-align: right; padding-top: .5mm; white-space: nowrap; }
        .sheet-meta>span { font-size: 5.8pt; letter-spacing: 1pt; color: #70869b; }
        .sheet-meta strong { display: block; margin-top: 2mm; font-size: 8pt; color: #077dbc; letter-spacing: .7pt; }
        .sheet-meta small { display: block; margin-top: 2.5mm; font-size: 6pt; color: #70869b; letter-spacing: .7pt; }
        .cards { min-height: 0; display: grid; grid-template-columns: repeat(3, 1fr); grid-template-rows: repeat(4, 1fr); gap: 3mm; }
        .voucher { min-width: 0; min-height: 0; border: .25mm dashed #b5cbdc; border-radius: 2.5mm; display: flex; flex-direction: column; align-items: center; position: relative; background: #fff; overflow: hidden; }
        .card-top { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 1mm; padding: 1.5mm 2.5mm 1mm; background: #f2f8fd; border-bottom: .2mm solid #e3eff8; }
        .card-brand { gap: 1.2mm; }.card-brand .brand-icon { width: 6mm; height: 6mm; border-radius: 1.3mm; }
        .card-brand b { font-size: 9pt; letter-spacing: -.2pt; }.card-brand small { display: none; }
        .card-top>strong { font-size: 6pt; border-radius: 1mm; padding: 1.2mm 1.4mm; background: #0d3654; color: #fff; letter-spacing: .3pt; }
        .qr-wrap { width: 30mm; height: 30mm; flex: 0 0 30mm; margin: .8mm 0 .4mm; } .qr-wrap img { width: 100%; height: 100%; display: block; }
        .card-info { width: 100%; padding: 0 2.5mm; text-align: center; } h2 { margin: 0; font-size: 8pt; line-height: 1.15; max-height: 6.5mm; overflow: hidden; }
        .card-info p { margin: 1mm 0; font-size: 5.7pt; color: #657a8c; }
        .card-bottom { display: flex; align-items: center; justify-content: space-between; gap: 1mm; width: 100%; margin-top: auto; padding: 1.4mm 2.5mm; background: #f2f8fd; border-top: .2mm solid #e3eff8; font-size: 5pt; color: #46687f; }
        .card-bottom>span:first-child { font-size: 4.8pt; letter-spacing: .5pt; }.scan-hint { font-size: 5pt; }.card-bottom b { color: #086eac; font-size: 5.8pt; }
        .validity { font-size: 5pt; color: #657a8c; margin-bottom: .5mm; }
        footer { display: flex; align-items: center; justify-content: space-between; gap: 3mm; border-top: .2mm solid #dce6ef; font-size: 6pt; color: #657a8c; }
        .steps { display: flex; gap: 4mm; }.steps span { display: flex; gap: 1.2mm; align-items: center; }.steps i { display: inline-grid; place-items: center; width: 3.5mm; height: 3.5mm; border-radius: 50%; color: #057fc4; background: #edf6fd; font-size: 5.5pt; font-style: normal; font-weight: bold; }
        .sheet-reference { font-size: 5.5pt; letter-spacing: .3pt; }
        .unavailable .qr-wrap { opacity: .15; } .unavailable .card-bottom { color: #b42332; font-weight: bold; }
        .ppv-sheet .sheet-header { border-color: #c59645; }.ppv-sheet .sheet-meta strong { color: #926622; }
        .ppv-sheet .cards { grid-template-columns: repeat(2, 1fr); }
        .ppv-ticket { background: #fffdf9; border-color: #ccbfa7; }
        .ppv-ticket .card-top { background: #172b3e; border-color: #172b3e; padding: 1.5mm 3mm; }
        .ppv-ticket .card-brand b { color: #fff; }.ppv-ticket .card-top>strong { color: #ffe1a0; background: #ffffff12; border: .2mm solid #b68d48; font-size: 6pt; }
        .ticket-body { width: 100%; flex: 1; display: grid; grid-template-columns: 22mm minmax(0, 1fr) 29mm; align-items: center; gap: 2mm; padding: 2mm 3mm; min-height: 0; }
        .ticket-poster { height: 33mm; border-radius: 1.5mm; overflow: hidden; background: #182c40; color: #f6d995; }.ticket-poster img { display: block; width: 100%; height: 100%; object-fit: cover; }.ticket-poster>span { display: grid; place-content: center; height: 100%; text-align: center; font-size: 7pt; line-height: 1.6; }
        .ticket-copy { min-width: 0; }.ticket-eyebrow { display: block; color: #987331; font-size: 4.8pt; letter-spacing: .7pt; margin-bottom: 1.5mm; }
        .ticket-copy h2 { font-size: 10pt; line-height: 1.1; max-height: 11.7mm; color: #142b3e; }
        .ticket-scope { margin: 1.5mm 0 0; color: #865c18; font-size: 6.2pt; font-weight: bold; line-height: 1.4; }
        .ticket-title { margin: 1mm 0 0; font-size: 5.8pt; color: #617080; line-height: 1.35; max-height: 5.7mm; overflow: hidden; }
        .ticket-duration { display: inline-block; margin-top: 2mm; padding: 1mm 1.5mm; border-radius: 1mm; background: #f4ead5; color: #805a20; font-size: 5.8pt; }
        .ticket-scan { align-self: center; text-align: center; }.ppv-ticket .qr-wrap { width: 29mm; height: 29mm; margin: 0; background: #fff; }.ticket-scan small { display: block; font-size: 4.8pt; letter-spacing: .6pt; margin-top: 1mm; color: #637383; }
        .ppv-ticket .card-bottom { background: #f6efdf; border-top: .2mm dashed #d7c49f; color: #806536; padding: 1.5mm 3mm; }.ppv-ticket .card-bottom b { color: #76521b; }
        @media screen { .sheet { margin: 20px auto; box-shadow: 0 3px 20px #10282d22; } }
        @media print { body { background: #fff; } * { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style></head><body>${sheets}</body></html>`;
}

export async function printVoucherDocument(html) {
    const frame = document.createElement('iframe');
    frame.title = 'Print Zo Stream activation cards';
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:210mm;height:297mm;border:0';
    const loaded = new Promise((resolve, reject) => {
        frame.onload = resolve;
        frame.onerror = () => reject(new Error('Could not open the print sheet.'));
    });
    frame.srcdoc = html;
    document.body.appendChild(frame);
    try {
        await loaded;
        await Promise.all(Array.from(frame.contentDocument.images, img => img.decode().catch(() => {
            throw new Error('A poster or QR image could not be loaded. Refresh the sheets and try again.');
        })));
        frame.contentWindow.onafterprint = () => setTimeout(() => frame.remove(), 100);
        frame.contentWindow.focus();
        frame.contentWindow.print();
        setTimeout(() => frame.remove(), 120000);
    } catch (error) { frame.remove(); throw error; }
}
