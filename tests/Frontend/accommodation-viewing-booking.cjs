const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test' });
for (const key of ['window', 'document', 'HTMLElement', 'Event']) global[key] = dom.window[key];
global.IS_REACT_ACT_ENVIRONMENT = true;
Object.defineProperty(window, 'innerWidth', { value: 500 });

const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const posts = [];
const empty = () => null;
const inertia = {
    Head: empty,
    usePage: () => ({ props: {} }),
    useForm(initial) {
        const [data, setData] = React.useState(initial);
        return {
            data,
            setData: (key, value) => setData((previous) => ({ ...previous, [key]: value })),
            post: (url) => posts.push({ url, data }),
            processing: false,
            errors: {},
        };
    },
    router: { get() {}, patch() {}, post() {} },
};
const mocks = {
    '@inertiajs/react': inertia,
    'lucide-react': new Proxy({}, { get: () => empty }),
    sonner: { toast: { error: (message) => { throw new Error(message); } } },
};

function load(file) {
    const output = buildSync({
        entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
        alias: { '@': path.resolve('resources/js') },
        external: ['react', 'react/jsx-runtime', ...Object.keys(mocks)],
    }).outputFiles[0].text;
    const loaded = new Module(path.resolve('viewing-booking-test.cjs'), module);
    loaded.filename = path.resolve('viewing-booking-test.cjs');
    loaded.paths = module.paths;
    loaded.require = (name) => mocks[name] ?? module.require(name);
    loaded._compile(output, loaded.filename);
    return loaded.exports.default;
}

const PublicBooking = load('resources/js/pages/accommodation/BookViewing.jsx');
const StaffSchedule = load('resources/js/components/accommodation/ViewingBookingSchedule.jsx');
const Calendar = load('resources/js/pages/portal/accommodation/Calendar.jsx');
const slot = { id: 1, date: '2026-11-30', time: '11:00', address: '97 Sample Avenue' };
const bookingUrl = 'https://luvep.test/accommodation/book-viewing/1?signature=sample';
const attendee = { id: 1, name: 'Sample Person', email: 'sample@example.test', mobile: '0211234567', status: 'Confirmed' };
const root = createRoot(document.getElementById('root'));
const render = (Page, props) => act(async () => root.render(React.createElement(Page, props)));
const submit = (form) => act(async () => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));

(async () => {
    await render(PublicBooking, { address: slot.address, slots: [slot], bookingUrl });
    const confirmButton = document.querySelector('button[type=submit]');
    assert.equal(confirmButton.disabled, true);
    assert.equal(document.querySelectorAll('input[required]').length, 3);
    await act(async () => document.querySelector('input[type=radio]').click());
    assert.equal(confirmButton.disabled, false);
    await submit(document.querySelector('form'));
    assert.equal(posts[0].url, bookingUrl);
    assert.equal(posts[0].data.slot_id, 1);
    await render(PublicBooking, { address: slot.address, slots: [], bookingUrl, confirmation: slot });
    assert.match(document.body.textContent, /Your viewing is confirmed/);
    assert.match(document.body.textContent, /11:00 am NZ time/);

    await render(StaffSchedule, { schedule: {
        date: slot.date, today: slot.date, times: ['11:00', '12:00'],
        properties: [{ id: 1, address: slot.address, url: bookingUrl }],
        slots: [{
            id: 1, property_id: 1, property_address: slot.address, start_time: slot.time,
            blocked: false, capacity: 2, attendees: [attendee],
        }],
    } });
    assert.match(document.body.textContent, /1 booking/);
    assert.match(document.body.textContent, /sample@example.test/);
    assert.match(document.body.textContent, /0211234567/);
    const property = document.querySelector('select');
    await act(async () => {
        property.value = '1';
        property.dispatchEvent(new Event('change', { bubbles: true }));
    });
    assert.equal(document.querySelector('input[readonly]').value, bookingUrl);
    assert.match(decodeURIComponent(document.querySelector('a[href^="mailto:"]').getAttribute('href')), /97 Sample Avenue.*\n\nhttps:/);
    const secondSlot = document.querySelectorAll('form')[1];
    await act(async () => secondSlot.querySelector('input[type=checkbox]').click());
    await submit(secondSlot);
    assert.equal(posts[1].url, '/portal/accommodation/viewings/slots');
    assert.equal(posts[1].data.time, '12:00');
    assert.equal(posts[1].data.blocked, true);

    window.axios = { get: async () => ({ data: [{
        id: 'group-viewing-1', source_type: 'group_viewing', title: slot.address, subtitle: '1 booking',
        starts_at: '2026-11-29T22:00:00+00:00', is_all_day: false,
        meta: { viewing_date: slot.date, start_time: slot.time, attendees: [attendee] },
    }] }) };
    await render(Calendar, { eventTypes: [{ key: 'viewing', enabled: true }] });
    const event = [...document.querySelectorAll('button')].find((button) => button.textContent.includes(slot.address));
    assert.match(event.textContent, /11:00 am/);
    await act(async () => event.click());
    assert.match(document.body.textContent, /Monday, 30 November 2026/);
    assert.match(document.body.textContent, /Sample Person/);
    assert.match(document.body.textContent, /sample@example.test/);
    assert.match(document.body.textContent, /0211234567/);
    await act(async () => root.unmount());
    dom.window.close();
    console.log('Accommodation viewing UI passed: property link, slot selection, block control, grouped attendees and NZ calendar time.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
