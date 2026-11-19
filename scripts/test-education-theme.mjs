import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../public/education-ui/theme.js', import.meta.url), 'utf8');
function page({ stored = null, dark = false, blocked = false } = {}) {
  const window = new EventTarget();
  window.Event = Event;
  window.StorageEvent = class extends Event {
    constructor(type, values) { super(type); Object.assign(this, values); }
  };
  const button = new EventTarget();
  button.click = () => button.dispatchEvent(new Event('click'));
  const attributes = new Map();
  button.setAttribute = (key, value) => attributes.set(key, value);
  button.getAttribute = key => attributes.get(key);
  window.document = new EventTarget();
  window.document.documentElement = { dataset: {}, style: {} };
  window.document.querySelectorAll = () => [button];
  const values = new Map(stored ? [['education-theme', stored]] : []);
  const storage = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
  Object.defineProperty(window, 'localStorage', { get() {
    if (blocked) throw new Error('Storage disabled');
    return storage;
  } });
  const system = new EventTarget();
  system.matches = dark;
  window.matchMedia = () => system;
  const context = { window, document: window.document };
  Object.defineProperty(context, 'localStorage', { get: () => window.localStorage });
  runInNewContext(source, context);
  window.document.dispatchEvent(new Event('DOMContentLoaded'));
  const theme = () => window.document.documentElement.style.colorScheme;
  return { window, system, button, theme };
}

const automatic = page({ dark: true });
assert.equal(automatic.theme(), 'dark');
assert.equal(automatic.button.getAttribute('aria-checked'), 'true');
automatic.system.matches = false;
automatic.system.dispatchEvent(new automatic.window.Event('change'));
assert.equal(automatic.theme(), 'light');
automatic.button.click();
assert.equal(automatic.theme(), 'dark');
assert.equal(automatic.window.localStorage.getItem('education-theme'), 'dark');
automatic.system.dispatchEvent(new automatic.window.Event('change'));
assert.equal(automatic.theme(), 'dark', 'System changes must not replace an explicit choice');
assert.equal(page({ stored: 'light', dark: true }).theme(), 'light');
assert.equal(page({ stored: 'dark', dark: false }).theme(), 'dark');
const restricted = page({ blocked: true });
restricted.button.click();
assert.equal(restricted.theme(), 'dark', 'Switch still works when storage is unavailable');
automatic.window.dispatchEvent(new automatic.window.StorageEvent('storage', { key: 'education-theme', newValue: 'light' }));
assert.equal(automatic.theme(), 'light', 'Other tabs share the choice');
automatic.system.matches = true;
automatic.window.dispatchEvent(new automatic.window.StorageEvent('storage', { key: null }));
assert.equal(automatic.theme(), 'dark', 'Clearing storage returns to the system preference');
console.log('Education theme: system default, explicit choice, storage, cross-tab sync and restricted storage passed.');
