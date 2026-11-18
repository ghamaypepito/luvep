const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const destination = fs.mkdtempSync(path.join(os.tmpdir(), 'luvep-workspace-install-'));
try {
  fs.mkdirSync(path.join(destination, 'exports'));
  fs.writeFileSync(path.join(destination, 'exports', 'existing-design.png'), 'saved design');
  fs.writeFileSync(path.join(destination, 'ghl-live.js'), 'old runtime');
  const install = spawnSync('bash', ['scripts/install-social-workspace.sh', destination], { encoding: 'utf8' });
  assert.equal(install.status, 0, install.stderr);
  for (const file of ['index.html', 'ghl-live.js', 'brief-references.js', 'marketing-workflow.js']) {
    assert.deepEqual(fs.readFileSync(path.join(destination, file)), fs.readFileSync(path.join('resources/social-workspace', file)));
  }
  assert.equal(fs.readFileSync(path.join(destination, 'exports', 'existing-design.png'), 'utf8'), 'saved design');
  assert.equal(fs.existsSync(path.join(destination, 'build_samples.py')), false);
  console.log('Workspace installation updates runtime and preserves existing exports.');
} finally {
  fs.rmSync(destination, { recursive: true, force: true });
}
