const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/finance/reports' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'), { act } = React, { createRoot } = require('react-dom/client');
const gets = [];
const inertia = { Head: () => null, router: { get: (...args) => gets.push(args) } };
let blob;
URL.createObjectURL = value => { blob = value; return 'blob:test'; };
URL.revokeObjectURL = () => {};
dom.window.HTMLAnchorElement.prototype.click = function () {};
const output = buildSync({ entryPoints: ['resources/js/pages/portal/finance/Reports.jsx'], bundle: true, write: false,
    format: 'cjs', platform: 'node', jsx: 'automatic', loader: { '.css': 'empty' }, alias: { '@': path.resolve('resources/js') },
    external: ['react', 'react/jsx-runtime', 'react-dom', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('finance-analytics.cjs'), module); loaded.filename = path.resolve('finance-analytics.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name); loaded._compile(output, loaded.filename);
const root = createRoot(document.getElementById('root'));
const snapshot = { period_status: {paid_total: 300, not_overdue_total: 200, overdue_total: 500, issued_total: 1000}, total: 500, count: 1, overdue_total: 500, overdue_count: 1, due_soon_total: 0, due_soon_count: 0, undated_count: 0, oldest_days: 32, buckets: { current: 0, '31-60': 500 }, attention: [{ id: 5, ref: 'INV A/1', party: 'Client', due_date: '2026-10-20', days_past_due: 32, balance: 500 }] };
const props = { report: 'analytics', start: '2026-11-01', end: '2026-11-30', currency: 'NZD', currencies: ['NZD', 'USD'], data: {
    received: 1000.01, paid: 100.01, net: 900, receipt_count: 2, payment_count: 1, as_of: '2026-11-23', status_as_of: '2026-11-23',
    previous: { start: '2026-10-02', end: '2026-10-31', received: 0, paid: 0, net: 0 },
    receivables: snapshot, payables: { ...snapshot, attention: [] },
    trends: [{ period: '2026-11-02', received: 1000.01, paid: 100.01 }, { period: '2026-11-09', received: 0, paid: 0 }],
    expense_breakdown: [{ category: '=HYPERLINK("example")', amount: 100.01 }, { category: 'Vendor\r=1+1', amount: 0 }],
} };
const button = text => [...document.querySelectorAll('button')].find(n => n.textContent.trim() === text);
(async () => {
    await act(async () => root.render(React.createElement(loaded.exports.default, props)));
    assert.equal(document.querySelector('[aria-label="Finance reports"] [aria-current="page"]').textContent, 'Analytics');
    assert.deepEqual([...document.querySelectorAll('.tw-metric-value')].map(n => n.textContent), ['NZD 1,000.01', 'NZD 100.01', 'NZD 900.00', 'NZD 500.00']);
    assert.equal(document.querySelectorAll('.tw-week').length, 2);
    assert.ok(document.querySelector('[aria-label="Weekly cash data"]').textContent.includes('NZD 1,000.01'));
    assert.ok(document.querySelector('a.finance-record').href.endsWith('/receivables?q=INV%20A%2F1'));
    assert.ok(!document.body.textContent.includes('Infinity'));
    assert.ok(document.body.textContent.includes('Issued 1 Nov 2026 – 30 Nov 2026'));
    assert.ok(document.body.textContent.includes('Balances as of 23 Nov 2026.'));
    assert.ok(!document.body.textContent.includes('All issued records'));

    for (const ledger of ['Receivables', 'Payables']) {
        const table = document.querySelector(`[aria-label="${ledger} payment status amounts and shares"]`);
        assert.ok(table.textContent.includes('PaidNZD 300.0030.0%'));
        assert.ok(table.textContent.includes('Outstanding (not overdue)NZD 200.0020.0%'));
        assert.ok(table.textContent.includes('OverdueNZD 500.0050.0%'));
        assert.equal(document.querySelectorAll(`[aria-label^="${ledger} payment status pie chart"] path`).length, 3);
    }
    assert.equal(document.querySelectorAll('[aria-label^="Expense category pie chart"] circle').length, 1, 'A single paid category fills the pie');
    assert.ok(document.querySelector('[aria-label="Expense category amounts and shares"]').textContent.includes('100.0%'));
    assert.ok(!document.querySelector('[aria-label="Expense category amounts and shares"]').textContent.includes('Vendor'), 'Zero amounts are not slices');
    await act(async () => document.querySelector('[aria-label="View cash received details"]').click());
    assert.equal(gets.at(-1)[1].report, 'collections');
    await act(async () => {
        const select = document.querySelector('[aria-label="Report currency"]'); select.value = 'USD'; select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    assert.deepEqual(gets.at(-1)[1], { report: 'analytics', currency: 'USD', start: props.start, end: props.end });
    await act(async () => button('Export CSV').click());
    const csv = await blob.text();
    assert.ok(csv.includes('Live balance snapshot,2026-11-23'));
    assert.ok(csv.includes('Net cash movement,900'));
    assert.ok(csv.includes('Payment-status issue date range,2026-11-01,2026-11-30'));
    assert.ok(csv.includes('Payment-status balance cutoff,2026-11-23'));
    assert.ok(csv.includes('receivables,300,200,500,1000'));
    assert.ok(csv.includes('payables,300,200,500,1000'));
    assert.ok(csv.includes('"\'=HYPERLINK(""example"")"'), 'Spreadsheet formula text is neutralised');
    assert.ok(csv.includes('"Vendor\r=1+1",0'), 'Carriage returns stay inside a quoted CSV cell');
    assert.ok(!csv.includes('Net profit'));
    await act(async () => root.render(React.createElement(loaded.exports.default, { ...props, currency: 'USD', data: { ...props.data, expense_breakdown: [{ category: 'Office costs', amount: 75 }, { category: 'Travel', amount: 25 }] } })));
    assert.equal(document.querySelectorAll('[aria-label^="Expense category pie chart"] path').length, 2);
    const pieData = document.querySelector('[aria-label="Expense category amounts and shares"]').textContent;
    assert.ok(pieData.includes('Office costsUSD 75.0075.0%'));
    assert.ok(pieData.includes('TravelUSD 25.0025.0%'));
    assert.ok(document.querySelector('[aria-label^="Expense category pie chart"]').closest('figure').textContent.includes('USD 100.00'));
    assert.ok(document.querySelector('[aria-label^="Expense category pie chart"]').getAttribute('aria-label').includes('USD 100.00'));
    await act(async () => root.render(React.createElement(loaded.exports.default, { ...props, data: { ...props.data, receivables: { period_status: {paid_total: 100, not_overdue_total: 0, overdue_total: 0} } } })));
    assert.equal(document.querySelectorAll('[aria-label^="Receivables payment status pie chart"] circle').length, 1);
    const paidOnly = document.querySelector('[aria-label="Receivables payment status amounts and shares"]').textContent;
    assert.ok(paidOnly.includes('PaidNZD 100.00100.0%'));
    assert.ok(paidOnly.includes('Outstanding (not overdue)NZD 0.000.0%'));
    assert.ok(paidOnly.includes('OverdueNZD 0.000.0%'));
    await act(async () => root.render(React.createElement(loaded.exports.default, { ...props, start: '2026-10-01', end: '2026-10-31', data: {} })));
    assert.equal(document.querySelector('input[type=date]').value, '2026-10-01', 'Date fields track server props after presets');
    assert.ok(document.body.textContent.includes('No cash movements recorded'));
    assert.equal(document.querySelector('.finance-pie'), null);
    assert.ok(document.body.textContent.includes('No supplier payments recorded in this period.'));
    assert.ok(document.body.textContent.includes('No receivables issued in this date range and currency through 31 Oct 2026.'));
    assert.ok(document.body.textContent.includes('No payables issued in this date range and currency through 31 Oct 2026.'));
    assert.ok(!document.body.textContent.includes('NaN'));
    await act(async () => root.unmount());
    console.log('Finance analytics: exact monetary values, accessible chart data, drill-downs, currency filters, CSV and empty states verified.');
})().catch(error => { console.error(error); process.exitCode = 1; });
