import {
    mkdirSync,
    readFileSync,
    writeFileSync,
    existsSync,
    mkdtempSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { resolve } from "node:path";
import { randomBytes } from "node:crypto";
import { spawn, execFileSync } from "node:child_process";
import https from "node:https";
import http from "node:http";

const root = process.cwd();
// Remote acceptance uses an SSH tunnel on the same loopback port; no public test listener.
const remote = process.env.HR_REMOTE_BROWSER === "1";
const scratch = resolve(".superpowers/sdd/2026-10-19-hr-release-1/browser");
mkdirSync(scratch, { recursive: true });
const temp = mkdtempSync(`${tmpdir()}/luvep-hr-e2e-`);
const php = process.env.HR_PHP || "/opt/homebrew/opt/php@8.3/bin/php";
const env = {
    ...process.env,
    HR_E2E: "1",
    APP_ENV: "local",
    APP_DEBUG: "false",
    APP_KEY: `base64:${randomBytes(32).toString("base64")}`,
    APP_URL: "https://luvep.test:8443",
    DB_CONNECTION: "sqlite",
    DB_DATABASE: `${temp}/database.sqlite`,
    CACHE_STORE: "array",
    QUEUE_CONNECTION: "sync",
    MAIL_MAILER: "log",
    SESSION_DRIVER: "file",
    HR_ENABLED: "true",
    HR_SHARED_SESSION: "true",
    HR_HOST: "hr.luvep.test",
    HR_URL: "https://hr.luvep.test:8443",
    HR_MAIN_URL: "https://luvep.test:8443",
    HR_SESSION_HOSTS: "luvep.test,hr.luvep.test",
    HR_SESSION_DOMAIN: ".luvep.test",
    HR_NOTIFICATIONS_ENABLED: "false",
    HR_FIXTURE_FILE: `${scratch}/fixture.json`,
};
if (!remote) {
    writeFileSync(env.DB_DATABASE, "");
    execFileSync(php, ["tests/Fixtures/hr-browser.php"], {
        cwd: root,
        env,
        stdio: "inherit",
    });
}
if (!existsSync(`${scratch}/cert.pem`))
    execFileSync(
        "openssl",
        [
            "req",
            "-x509",
            "-newkey",
            "rsa:2048",
            "-nodes",
            "-keyout",
            `${scratch}/key.pem`,
            "-out",
            `${scratch}/cert.pem`,
            "-days",
            "3",
            "-subj",
            "/CN=luvep.test",
            "-addext",
            "subjectAltName=DNS:luvep.test,DNS:hr.luvep.test,DNS:localhost",
        ],
        { stdio: "ignore" },
    );
const backend = remote
    ? null
    : spawn(
          php,
          [
              "-d",
              "default_socket_timeout=2",
              "-d",
              "memory_limit=512M",
              "-S",
              "127.0.0.1:8107",
              "-t",
              "public",
              "tests/Fixtures/hr-router.php",
          ],
          { cwd: root, env, stdio: "ignore" },
      );
const proxy = https.createServer(
    {
        key: readFileSync(`${scratch}/key.pem`),
        cert: readFileSync(`${scratch}/cert.pem`),
    },
    (req, res) => {
        const upstream = http.request(
            {
                hostname: "127.0.0.1",
                port: 8107,
                path: req.url,
                method: req.method,
                headers: req.headers,
            },
            (reply) => {
                res.writeHead(reply.statusCode, reply.headers);
                reply.pipe(res);
            },
        );
        upstream.on("error", () => {
            res.writeHead(503);
            res.end("Starting");
        });
        req.pipe(upstream);
    },
);
proxy.listen(8443, "127.0.0.1");
const close = () => {
    proxy.close();
    backend?.kill();
    process.exit(0);
};
process.on("SIGINT", close);
process.on("SIGTERM", close);
