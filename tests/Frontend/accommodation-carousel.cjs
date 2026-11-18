const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/accommodation', pretendToBeVisual: true });
for (const key of ['window', 'document', 'HTMLElement', 'HTMLSlotElement', 'Element', 'Event', 'MouseEvent', 'MutationObserver', 'getComputedStyle']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.requestAnimationFrame = dom.window.requestAnimationFrame.bind(dom.window);
global.cancelAnimationFrame = dom.window.cancelAnimationFrame.bind(dom.window);
window.matchMedia = query => ({ matches: window.innerWidth >= Number(query.match(/\d+/)?.[0] || 0), addEventListener() {}, removeEventListener() {} });
Object.defineProperty(dom.window.HTMLElement.prototype, 'clientWidth', { get: () => 1200 });
Object.defineProperty(dom.window.HTMLElement.prototype, 'offsetWidth', { get: () => 1200 });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const entry = path.resolve('resources/js/pages/accommodation/AccommodationPage.jsx');
const externals = ['react', 'react/jsx-runtime', '@/components/layout/Navbar', '@/components/layout/Footer', '@/components/accommodation/PropertyViewingBooking'];
const output = buildSync({ entryPoints: [entry], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: externals, loader: { '.css': 'empty' } }).outputFiles[0].text;
const loaded = new Module(entry, module); loaded.filename = entry; loaded.paths = module.paths;
loaded.require = name => externals.slice(2).includes(name) ? () => null : module.require(name);
loaded._compile(output, entry);
const Page = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const allProperties = ['Harbour House', 'Central Studio', 'Garden Room', 'Quiet Apartment'].map((name, i) => ({ id: i + 1, name, slug: `qa-property-${i + 1}`, suburb: ['Auckland', 'City', 'Garden', 'Park'][i], room_type: 'Single', bed_type: 'Single', property_type: i === 1 ? 'Studio' : 'House', rental_mode: 'per_room', rent_single: 200 + i * 10, images: [] }));
const properties = allProperties.slice(0, 3);
const click = async name => act(async () => document.querySelector(`button[aria-label="${name}"]`).click());
const finishTransition = async () => act(async () => document.querySelector('.swiper-wrapper').dispatchEvent(new Event('transitionend', { bubbles: true })));
(async () => {
    await act(async () => root.render(React.createElement(Page, { properties })));
    const swiper = document.querySelector('.swiper').swiper;
    assert.ok(swiper.initialized);
    assert.equal(swiper.autoplay.running, true);
    assert.equal(swiper.params.slidesPerView, 4);
    const start = swiper.realIndex;
    await click('Next'); await finishTransition();
    assert.notEqual(swiper.realIndex, start, 'Next advances the real carousel');
    await click('Previous'); await finishTransition();
    assert.equal(swiper.realIndex, start, 'Previous returns to the starting slide');
    assert.ok(document.querySelectorAll('.swiper-slide').length >= 8, 'Few listings still support looping');
    const beforeAutoplay = swiper.realIndex;
    await act(async () => new Promise(resolve => setTimeout(resolve, 2800)));
    assert.notEqual(swiper.realIndex, beforeAutoplay, 'Autoplay advances the carousel');
    await finishTransition();
    assert.ok(document.querySelector('a[href="/accommodation/qa-property-1"]'));
    const search = document.querySelector('input[placeholder="Search by name, location or features…"]');
    await act(async () => {
        Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype, 'value').set.call(search, 'Central Studio');
        search.dispatchEvent(new Event('input', { bubbles: true }));
    });
    const listings = [...document.querySelectorAll('h3')].map(el => el.textContent);
    assert.ok(listings.includes('Central Studio'));
    assert.ok(!listings.includes('Harbour House'));
    for (const count of [1, 2, 4]) {
        await act(async () => root.render(React.createElement(Page, { key: count, properties: allProperties.slice(0, count) })));
        const instance = document.querySelector('.swiper').swiper;
        assert.ok(instance.initialized);
        assert.ok(instance.autoplay.running);
        const initial = instance.realIndex;
        if (count > 1) {
            await click('Next'); await finishTransition();
            assert.notEqual(instance.realIndex, initial, `${count} properties: Next advances`);
            await click('Previous'); await finishTransition();
            assert.equal(instance.realIndex, initial, `${count} properties: Previous returns to the starting slide`);
        } else {
            assert.equal(document.querySelector('button[aria-label="Next"]'), null);
        }
    }
    await act(async () => root.render(React.createElement(Page, { properties: [] })));
    assert.match(document.body.textContent, /No properties to show yet/);
    assert.equal(document.querySelector('.swiper'), null);
    await act(async () => root.unmount());
    dom.window.close();
    console.log('Accommodation: real Swiper initialisation, autoplay, next/previous, small-list looping, property links, filtering and empty state passed');
})().catch(error => { console.error(error); process.exitCode = 1; dom.window.close(); });
