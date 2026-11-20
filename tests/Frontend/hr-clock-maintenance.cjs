const assert = require('node:assert/strict');
const { mkdtempSync, writeFileSync, readFileSync, rmSync } = require('node:fs');
const { tmpdir } = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const scratch = mkdtempSync(path.join(tmpdir(), 'hr-clock-cron-'));
try {
    const fixture = path.join(scratch, 'cron');
    const unrelated = '0 2 * * * /usr/bin/other-job # keep-other-job\n30 * * * * /usr/bin/media-cleanup # luvep-media-drive-cleanup\n';
    writeFileSync(fixture, unrelated + '* * * * * obsolete # luvep-hr-auto-clock-out\n');
    writeFileSync(path.join(scratch, 'crontab'), '#!/bin/sh\nif [ "$1" = "-l" ]; then cat "$HR_CRON_FIXTURE"; else cat > "$HR_CRON_FIXTURE"; fi\n', { mode: 0o700 });
    const env = { ...process.env, PATH: `${scratch}:${process.env.PATH}`, HR_CRON_FIXTURE: fixture };
    const script = path.resolve('scripts/install-hr-clock-maintenance.sh');
    for (let run = 0; run < 2; run++) {
        const result = spawnSync('bash', ['-c', 'pwd() { printf "%s\\n" /var/www/epathways-production; }; export -f pwd; bash "$1"', '_', script], { env, encoding: 'utf8' });
        assert.equal(result.status, 0, result.stderr);
        const cron = readFileSync(fixture, 'utf8');
        assert.ok(cron.startsWith(unrelated), 'Unrelated maintenance must be retained');
        assert.equal(cron.match(/# luvep-hr-auto-clock-out/g).length, 1, 'Redeployment must retain exactly one clock job');
        assert.match(cron, /^\* \* \* \* \* .*artisan hr:auto-clock-out/m);
        assert.doesNotMatch(cron, /schedule:run|obsolete/, 'Install only the dedicated HR clock command');
    }
    const before = readFileSync(fixture, 'utf8');
    const rejected = spawnSync('bash', [script], { env, encoding: 'utf8' });
    assert.notEqual(rejected.status, 0, 'Local checkouts must not install a production cron');
    assert.equal(readFileSync(fixture, 'utf8'), before);
    console.log('HR clock cron installation: idempotence, unrelated entries and production path guard passed.');
} finally {
    rmSync(scratch, { recursive: true, force: true });
}
