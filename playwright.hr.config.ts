import { defineConfig } from '@playwright/test';
export default defineConfig({
 testDir:'./e2e', testMatch:/hr-.*\.spec\.ts/, fullyParallel:false, workers:1, timeout:45000,
 reporter:'list', use:{channel:'chrome', ignoreHTTPSErrors:true, trace:'retain-on-failure',
 launchOptions:{args:['--host-resolver-rules=MAP luvep.test 127.0.0.1, MAP hr.luvep.test 127.0.0.1','--no-proxy-server']}},
 webServer:{command:'node scripts/hr-browser-server.mjs',url:'https://localhost:8443/login',ignoreHTTPSErrors:true,timeout:60000,reuseExistingServer:false},
});
