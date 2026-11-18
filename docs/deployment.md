# ePathways Deployment Guide

Complete deployment documentation for ePathways (Laravel 12 + React 19 + Vite + Inertia) on a single Hostinger KVM 2 VPS, hosting **both staging and production** environments with GitHub Actions CI/CD.

This document captures the full deployment performed end-to-end: server hardening, stack installation, two-environment site setup, automated deploys, DNS cutover, SSL, and the gotchas we hit along the way.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Prerequisites](#prerequisites)
3. [Phase 1: VPS Hardening](#phase-1-vps-hardening)
4. [Phase 2: Stack Installation](#phase-2-stack-installation)
5. [Phase 3: Staging Site Deployment](#phase-3-staging-site-deployment)
6. [Phase 4: Production Site Deployment](#phase-4-production-site-deployment)
7. [CI/CD Workflow](#cicd-workflow)
8. [Maintenance & Operations](#maintenance--operations)
9. [Troubleshooting](#troubleshooting)
10. [Security Notes](#security-notes)
11. [Lessons Learned](#lessons-learned)

---

## Architecture Overview

### What's deployed where

```
┌────────────────────────────────────────────────────────────────────┐
│  Single Hostinger VPS — Ubuntu 24.04 LTS                           │
│  IP: 76.13.193.63 | KVM 2 (2 CPU, 8 GB RAM, 100 GB disk)           │
│                                                                    │
│  ┌──────────────────────────┐    ┌──────────────────────────┐      │
│  │ STAGING                  │    │ PRODUCTION               │      │
│  │ /var/www/                │    │ /var/www/                │      │
│  │   epathways-staging      │    │   epathways-production   │      │
│  │                          │    │                          │      │
│  │ Domain:                  │    │ Domain:                  │      │
│  │   staging.epathways.co.nz│    │   epathways.co.nz        │      │
│  │                          │    │   www.epathways.co.nz    │      │
│  │                          │    │                          │      │
│  │ Branch: staging          │    │ Branch: main             │      │
│  │ DB: epathways_staging    │    │ DB: epathways_production │      │
│  │ Redis DB: 0 (default)    │    │ Redis DB: 1 (cache=2)    │      │
│  │ Worker:                  │    │ Worker:                  │      │
│  │   epathways-staging-worker│   │   epathways-production-  │      │
│  │                          │    │   worker                 │      │
│  └──────────────────────────┘    └──────────────────────────┘      │
│                                                                    │
│  Shared services (one process per service serves both):            │
│   • Nginx (server_name dispatches per-domain)                      │
│   • PHP-FPM 8.3 (one pool for both)                                │
│   • MySQL 8 (separate databases)                                   │
│   • Redis 7 (separate databases via REDIS_DB)                      │
│   • Supervisor (manages both queue workers)                        │
│   • Certbot (separate Let's Encrypt cert per domain)               │
└────────────────────────────────────────────────────────────────────┘

GitHub Actions CI/CD
  staging branch push → deploy-staging.yml → /var/www/epathways-staging
  main branch push    → deploy-production.yml → /var/www/epathways-production
```

### Stack versions

| Component | Version |
|---|---|
| OS | Ubuntu 24.04 LTS |
| Nginx | 1.24 (Ubuntu repo) |
| PHP | 8.3 (Ubuntu repo) |
| MySQL | 8.0 (Ubuntu repo) |
| Redis | 7.0 (Ubuntu repo) |
| Node.js | 20 LTS (NodeSource repo) |
| Composer | 2.x |
| Supervisor | 4.2 |
| Certbot | 2.9 |
| Laravel | 12 |
| React | 19 (via Vite 7) |

### Branch strategy

- **`staging` branch** → auto-deploys to `https://staging.epathways.co.nz`
- **`main` branch** → auto-deploys to `https://epathways.co.nz`
- Workflow: develop on feature branch → merge to `staging` (test) → merge to `main` (production)

---

## Prerequisites

Before starting deployment, confirm you have:

- A Hostinger VPS running Ubuntu 24.04 (any recent VPS plan with ≥ 2 GB RAM)
- Root SSH access via password (will be replaced with key auth)
- Domain registered with DNS access (Hostinger DNS panel in our case)
- GitHub repository with at least **collaborator** access (admin not required — secrets management is enough)
- Local development environment: WSL/macOS/Linux with `git`, `ssh`, `ssh-keygen`, `dig`, `rsync`

### Specifically for our deployment

- **VPS:** `76.13.193.63` (`srv1596231.hstgr.cloud`)
- **GitHub repo:** `unknownhuman563/epathways` (we are a collaborator, not owner)
- **Domain:** `epathways.co.nz` (registered externally, DNS managed via Hostinger)

---

## Phase 1: VPS Hardening

Goal: secure the VPS before installing anything. Disable password root login, key-only SSH, firewall, dedicated deploy user.

### 1.1 — Add staging DNS record

In Hostinger → **Domains → epathways.co.nz → Manage DNS**, add:

| Type | Name | Points to | TTL |
|---|---|---|---|
| A | `staging` | `76.13.193.63` | 300 |

Verify after a few minutes:

```bash
dig @8.8.8.8 staging.epathways.co.nz +short
# Expected: 76.13.193.63
```

> **Note:** if a Hostinger-side "Website" entry exists for the same subdomain (showing a temporary `*.hostingersite.com` URL), it can hijack DNS resolution via Hostinger's nameserver-level routing. **Delete that website entry** in the Hostinger Websites panel if you see staging routing to wrong IPs.

### 1.2 — Generate or locate your local SSH key

In WSL (or macOS/Linux):

```bash
ls -la ~/.ssh/
```

If `id_ed25519` and `id_ed25519.pub` exist, you're set. Otherwise:

```bash
ssh-keygen -t ed25519 -C "your-email@example.com"
```

Press Enter through all prompts (default path, no passphrase keeps CI/CD smooth).

### 1.3 — First SSH connection (root with password)

```bash
ssh root@76.13.193.63
```

Enter Hostinger's auto-generated root password.

### 1.4 — Update the system

```bash
apt update && apt upgrade -y
```

Reboot if a kernel update was applied:

```bash
[ -f /var/run/reboot-required ] && reboot
```

Reconnect after ~30 seconds.

### 1.5 — Create the `deploy` user

```bash
adduser deploy
```

Set a strong password (save it — you'll use it for `sudo`).

```bash
usermod -aG sudo deploy
```

### 1.6 — Authorize your SSH key for `deploy`

```bash
mkdir -p /home/deploy/.ssh
chmod 700 /home/deploy/.ssh
nano /home/deploy/.ssh/authorized_keys
```

Paste your public key (the contents of `~/.ssh/id_ed25519.pub` from your laptop). Save with `Ctrl+O` → Enter → `Ctrl+X`.

```bash
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh
```

### 1.7 — Verify deploy login (DO NOT close root session yet)

In a **new** WSL terminal:

```bash
ssh deploy@76.13.193.63
```

Should log in **without password prompt**. If it does, you're safe to harden SSH. If it doesn't, debug from the still-open root session — don't lock yourself out.

### 1.8 — Disable root password login + password auth

In the **root** session:

```bash
cat > /etc/ssh/sshd_config.d/99-hardening.conf <<'EOF'
PermitRootLogin prohibit-password
PasswordAuthentication no
PubkeyAuthentication yes
EOF

sshd -t && systemctl reload ssh
```

> **Heredoc paste warning:** if pasting via PowerShell SSH, the closing `EOF` may end up indented. If the prompt sits at `>`, type literal `EOF` at column 0. As a fallback, use `printf` with `\n` separators in a single-line command.

After this:
- Root can only log in via SSH key (not password)
- No user can log in via password (key-only auth)

### 1.9 — Configure firewall

```bash
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
ufw status verbose
```

All three ports should show `ALLOW IN`.

### 1.10 — Optional: copy SSH key to Windows PowerShell (so PowerShell can SSH too)

If you want to SSH from PowerShell as well as WSL, copy the key:

In **WSL**:

```bash
WIN_USER=$(cmd.exe /c "echo %USERNAME%" 2>/dev/null | tr -d '\r\n')
mkdir -p "/mnt/c/Users/$WIN_USER/.ssh"
cp ~/.ssh/id_ed25519 "/mnt/c/Users/$WIN_USER/.ssh/"
cp ~/.ssh/id_ed25519.pub "/mnt/c/Users/$WIN_USER/.ssh/"
```

In **PowerShell**:

```powershell
icacls "$env:USERPROFILE\.ssh\id_ed25519" /inheritance:r /grant:r "${env:USERNAME}:F"
```

Now `ssh deploy@76.13.193.63` works from either WSL or PowerShell.

### 1.11 — Add NOPASSWD sudoers rule for deploy user (REQUIRED for CI/CD perm-fix step)

The CI/CD post-deploy step runs `sudo chown` / `sudo find ... -exec chmod` on `storage/` and `bootstrap/cache/` to restore permissions that rsync's `-p` flag wipes (see [Lessons Learned → rsync `-p` wipes storage perms](#rsync--p-wipes-storage-perms-on-every-deploy)). GitHub Actions runs deploys non-interactively, so sudo cannot prompt for a password — it would hang and time out.

We need a narrow sudoers rule allowing **only** the three commands the workflow uses (`chown`, `find`, `chmod`) to run without password.

As **root** on the VPS:

```bash
printf 'deploy ALL=(ALL) NOPASSWD: /usr/bin/chown, /usr/bin/find, /usr/bin/chmod\n' > /etc/sudoers.d/deploy-perm-fix
chmod 440 /etc/sudoers.d/deploy-perm-fix
visudo -c -f /etc/sudoers.d/deploy-perm-fix
```

Expected output of `visudo -c`:
```
/etc/sudoers.d/deploy-perm-fix: parsed OK
```

> **Why `printf` and not heredoc:** PowerShell SSH and many terminal pasters add leading whitespace, which corrupts heredoc-based file creation. `printf` is a single line with explicit `\n`, immune to paste indentation. See [Lessons Learned → PowerShell SSH paste indents pasted text](#powershell-ssh-paste-indents-pasted-text).

Verify deploy can now run sudo without password:

```bash
sudo -u deploy sudo -n true
```

Expected: silent (no output). If you see `sudo: a password is required`, the sudoers file isn't being picked up — check the file exists, is mode `440`, and `visudo -c` reports `parsed OK`.

> **Single VPS hosts both environments**, so this one sudoers file applies to both staging and production deploys. You don't need to repeat this for production setup.

> **Security boundary unchanged:** `deploy` already has full sudo with password (per §1.5: `usermod -aG sudo deploy`). This rule just removes the password prompt for three specific tools used by the deploy script. Anyone who could already become root can still become root.

---

## Phase 2: Stack Installation

All commands run as **root** (or via `sudo -i` from deploy).

### 2.1 — Install core packages

```bash
apt install -y nginx mysql-server redis-server supervisor certbot python3-certbot-nginx git unzip curl ca-certificates lsb-release gnupg
```

### 2.2 — Install PHP 8.3 + extensions Laravel needs

```bash
apt install -y php8.3-fpm php8.3-cli php8.3-common php8.3-mysql php8.3-zip php8.3-gd php8.3-mbstring php8.3-curl php8.3-xml php8.3-bcmath php8.3-intl php8.3-redis php8.3-opcache
```

> **Paste tip:** keep this on **one line**. If your terminal wraps it across multiple lines, bash will only install the first line's packages.

### 2.3 — Install Composer

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

### 2.4 — Install Node.js 20 LTS

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs
```

### 2.5 — Verify all installs

```bash
php -v && composer --version && node -v && npm -v
nginx -v
mysql --version
redis-server --version
```

### 2.6 — Verify all services are enabled and running

```bash
systemctl is-active nginx php8.3-fpm mysql redis-server supervisor
systemctl is-enabled nginx php8.3-fpm mysql redis-server supervisor
```

All five should print `active` and `enabled` respectively.

---

## Phase 3: Staging Site Deployment

### 3.1 — Create staging MySQL database + user

Generate a strong random password and create database:

```bash
DB_PASSWORD=$(openssl rand -base64 24 | tr -d '=+/' | head -c 24)
echo "STAGING DB_PASSWORD=$DB_PASSWORD  ← save this in your password manager"
mysql -e "CREATE DATABASE epathways_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'epathways_staging'@'localhost' IDENTIFIED BY '$DB_PASSWORD';"
mysql -e "GRANT ALL PRIVILEGES ON epathways_staging.* TO 'epathways_staging'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"
```

**Save the printed password.** It goes into `.env` next.

### 3.2 — Create staging directory

```bash
mkdir -p /var/www/epathways-staging
chown -R deploy:www-data /var/www/epathways-staging
chmod 2775 /var/www/epathways-staging
```

The `2775` mode includes the **setgid bit** so new files inherit the `www-data` group automatically.

### 3.3 — Create the staging `.env`

```bash
sudo -u deploy bash -c 'cat > /var/www/epathways-staging/.env'
```

Terminal hangs waiting for input. Paste this content **once** (replace placeholder values first):

```env
APP_NAME=ePathways
APP_ENV=staging
APP_KEY=
APP_DEBUG=false
APP_URL=https://staging.epathways.co.nz

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

PHP_CLI_SERVER_WORKERS=4
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=epathways_staging
DB_USERNAME=epathways_staging
DB_PASSWORD=<paste-staging-db-password>

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log
MAIL_FROM_ADDRESS="hello@epathways.co.nz"
MAIL_FROM_NAME="${APP_NAME}"

VITE_APP_NAME="${APP_NAME}"

CEREBRAS_API_KEY=<your-cerebras-key>
CEREBRAS_MODEL=llama3.3-70b

GEMINI_API_KEY=<your-gemini-key>

CALENDAR_SYNC_TOKEN=epathways_staging_<random-token>

ADMIN_SEED_EMAIL=admin@epathways.co.nz
ADMIN_SEED_PASSWORD=<strong-admin-password-min-20-chars>
```

After pasting, press **Enter** then **Ctrl+D** to close `cat`.

Strip leading whitespace (PowerShell paste indent):

```bash
sudo -u deploy sed -i 's/^[[:space:]]*//' /var/www/epathways-staging/.env
```

### 3.4 — Create Nginx server block

```bash
nano /etc/nginx/sites-available/staging.epathways.co.nz
```

Paste:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name staging.epathways.co.nz;
    root /var/www/epathways-staging/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 512M;
}
```

Save (`Ctrl+O` → Enter → `Ctrl+X`). Enable + test + reload:

```bash
ln -s /etc/nginx/sites-available/staging.epathways.co.nz /etc/nginx/sites-enabled/
rm /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx
```

**Upload size limits.** Standard document endpoints accept up to ten files per request, each capped at **50 MB** in the app
(`App\Support\UploadValidation::DOCUMENT_MAX_KB`). For a large file to reach
Laravel, all three layers must allow it, otherwise the client gets a `413`
(nginx) or a silent "file failed to upload" (PHP) before validation runs:

1. **nginx** — `client_max_body_size 512M;` in each server block (above).
2. **PHP-FPM** — in `/etc/php/8.x/fpm/php.ini` set both:

   ```ini
   upload_max_filesize = 50M
   post_max_size = 512M         ; ten 50 MiB files plus multipart overhead
   ```

   Validate with `nginx -t` and `php-fpm8.x -t` before reloading nginx and PHP-FPM. Check the effective FPM configuration, not only CLI PHP. Preserve configuration backups for rollback. Multipart uploads stream to temporary disk; monitor disk capacity and request concurrency rather than increasing PHP memory to match the request limit. Other upload screens may enforce smaller file or batch limits.

If a client "cannot submit" a big PDF on the tracker, one of these three is
still at the old 20 M — check nginx and PHP first.

### 3.5 — Generate a CI SSH key for GitHub Actions → VPS

Inside the VPS (root):

```bash
sudo -u deploy ssh-keygen -t ed25519 -f /home/deploy/.ssh/github_actions -C "github-actions-ci" -N ""
sudo -u deploy bash -c 'cat /home/deploy/.ssh/github_actions.pub >> /home/deploy/.ssh/authorized_keys'
cat /home/deploy/.ssh/github_actions
```

The last command prints the **private key** — copy it (the entire `-----BEGIN…` to `-----END…` block). **Do not paste it in chat or commits.** It goes only into GitHub Actions secrets.

### 3.6 — Add GitHub Actions secrets

In the GitHub repo: **Settings → Secrets and variables → Actions**. Add three repository secrets:

| Name | Value |
|---|---|
| `VPS_HOST` | `76.13.193.63` |
| `VPS_USER` | `deploy` |
| `VPS_SSH_KEY` | (paste the private key from step 3.5) |

### 3.7 — Create staging branch + workflow file

In your **local repo** (WSL):

```bash
cd /home/<you>/path/to/epathways
git checkout -b staging
git push -u origin staging
```

Create `.github/workflows/deploy-staging.yml`:

```yaml
name: Deploy to Staging

on:
  push:
    branches:
      - staging

jobs:
  deploy:
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP 8.3
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, xml, bcmath, curl, mysql, redis, zip, gd, intl
          coverage: none

      - name: Setup Node 20
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install Composer dependencies
        run: composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

      - name: Install npm dependencies
        run: npm ci

      - name: Build frontend assets
        run: npm run build

      - name: Setup SSH agent
        uses: webfactory/ssh-agent@v0.9.0
        with:
          ssh-private-key: ${{ secrets.VPS_SSH_KEY }}

      - name: Add VPS to known hosts
        run: ssh-keyscan -H ${{ secrets.VPS_HOST }} >> ~/.ssh/known_hosts

      - name: Rsync code to VPS
        run: |
          rsync -rlptvz --delete --no-owner --no-group \
            --exclude='.git' \
            --exclude='.github' \
            --exclude='.env' \
            --exclude='node_modules' \
            --exclude='storage/logs/*' \
            --exclude='storage/framework/cache/*' \
            --exclude='storage/framework/sessions/*' \
            --exclude='storage/framework/views/*' \
            --exclude='storage/app/public/*' \
            --exclude='storage/app/private/*' \
            ./ ${{ secrets.VPS_USER }}@${{ secrets.VPS_HOST }}:/var/www/epathways-staging/

      - name: Run post-deploy commands on VPS
        run: |
          ssh ${{ secrets.VPS_USER }}@${{ secrets.VPS_HOST }} '
            cd /var/www/epathways-staging &&
            sudo chown -R deploy:www-data storage bootstrap/cache &&
            sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \; &&
            sudo find storage bootstrap/cache -type f -exec chmod 664 {} \; &&
            php artisan storage:link &&
            php artisan migrate --force &&
            php artisan config:cache &&
            php artisan view:cache &&
            php artisan queue:restart
          '
```

> **CRITICAL — about the storage excludes:** `storage/app/public/*` and `storage/app/private/*` exclusions are **non-negotiable** for production safety. Without them, the `--delete` flag would attempt to remove user-uploaded files (passport PDFs, etc.) on every deploy because they don't exist in the source repo. We learned this the hard way — see [Lessons Learned](#lessons-learned).

> **CRITICAL — about the post-deploy chown/chmod and storage:link:** rsync's `-p` flag preserves source-side permissions on directories. The source (GitHub Actions runner) has default `755` on `storage/app/public/`, so every deploy wipes the `2775` setgid bit and group-write that PHP-FPM (`www-data`) needs to create upload subdirectories. Symptom: 500 error on first upload after each deploy with `Unable to create a directory at storage/app/public/...`. Similarly, the gitignored `public/storage` symlink is wiped by `--delete` and must be recreated. The post-deploy step **self-heals both** on every deploy: chown + find chmod restore perms, `storage:link` is idempotent and recreates the symlink if rsync deleted it. See [Lessons Learned → rsync `-p` wipes storage perms](#rsync--p-wipes-storage-perms-on-every-deploy). The `sudo` calls require the NOPASSWD sudoers rule from §1.11.

Commit and push:

```bash
git add .github/workflows/deploy-staging.yml
git commit -m "add staging deploy workflow"
git push
```

GitHub Actions starts the first deploy automatically. Watch at:
`https://github.com/<owner>/<repo>/actions`

### 3.8 — First-time post-deploy steps on VPS

After the first Actions run succeeds, SSH to the VPS and run these once:

```bash
cd /var/www/epathways-staging
sudo -u deploy php artisan key:generate
sudo -u deploy php artisan storage:link
sudo -u deploy php artisan config:clear
sudo -u deploy php artisan db:seed --class=AdminSeeder --force
sudo -u deploy php artisan config:cache
```

> **Why both `clear` then `cache`:** `env()` calls outside `config/*.php` return `null` when config is cached. The seeder uses `env('ADMIN_SEED_PASSWORD')` directly, so we have to clear the cache first, run the seeder, then re-cache.

### 3.9 — Set up Supervisor queue worker

```bash
nano /etc/supervisor/conf.d/epathways-staging-worker.conf
```

Paste:

```ini
[program:epathways-staging-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/epathways-staging/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=deploy
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/epathways-staging-worker.log
stopwaitsecs=3600
```

Save, then strip leading whitespace and register:

```bash
sed -i 's/^[[:space:]]*//' /etc/supervisor/conf.d/epathways-staging-worker.conf
supervisorctl reread
supervisorctl update
supervisorctl start epathways-staging-worker:*
supervisorctl status
```

### 3.10 — Set proper permissions

```bash
sudo chown -R deploy:www-data /var/www/epathways-staging/storage /var/www/epathways-staging/bootstrap/cache
sudo find /var/www/epathways-staging/storage -type d -exec chmod 2775 {} \;
sudo find /var/www/epathways-staging/bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find /var/www/epathways-staging/storage -type f -exec chmod 664 {} \;
sudo find /var/www/epathways-staging/bootstrap/cache -type f -exec chmod 664 {} \;
```

### 3.11 — Issue Let's Encrypt SSL

```bash
certbot --nginx -d staging.epathways.co.nz --redirect -m your-email@example.com -n --agree-tos
```

This:
- Validates ownership via HTTP-01 challenge (requires DNS pointing to VPS)
- Issues a cert valid 90 days
- Modifies the Nginx config to add a 443 SSL block
- Adds HTTP→HTTPS redirect
- Schedules auto-renewal via systemd timer

Visit `https://staging.epathways.co.nz` to confirm.

---

## Phase 4: Production Site Deployment

Production mirrors staging with these critical differences:

| Setting | Staging | Production |
|---|---|---|
| DB | `epathways_staging` | `epathways_production` |
| Directory | `/var/www/epathways-staging` | `/var/www/epathways-production` |
| Domain | `staging.epathways.co.nz` | `epathways.co.nz` + `www.epathways.co.nz` |
| Branch | `staging` | `main` |
| Workflow | `deploy-staging.yml` | `deploy-production.yml` |
| Worker | `epathways-staging-worker` | `epathways-production-worker` |
| Redis DB | `0` (default) | `1` |
| Redis Cache DB | `1` (default) | `2` |
| `APP_ENV` | `staging` | `production` |
| `APP_DEBUG` | `false` | `false` |

> **Why separate Redis DBs:** by default Laravel uses Redis DB 0 for queue/sessions and DB 1 for cache. If both environments share these, a staging job could be processed by production's worker and vice-versa. Setting `REDIS_DB=1` and `REDIS_CACHE_DB=2` on production fully isolates them.

### 4.1 — Production MySQL database

```bash
DB_PASSWORD=$(openssl rand -base64 24 | tr -d '=+/' | head -c 24)
echo "PRODUCTION DB_PASSWORD=$DB_PASSWORD  ← save this"
mysql -e "CREATE DATABASE epathways_production CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER 'epathways_production'@'localhost' IDENTIFIED BY '$DB_PASSWORD';"
mysql -e "GRANT ALL PRIVILEGES ON epathways_production.* TO 'epathways_production'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"
```

### 4.2 — Production directory

```bash
mkdir -p /var/www/epathways-production
chown -R deploy:www-data /var/www/epathways-production
chmod 2775 /var/www/epathways-production
```

### 4.3 — Production `.env`

```bash
sudo -u deploy bash -c 'cat > /var/www/epathways-production/.env'
```

Paste (replacing placeholders):

```env
APP_NAME=ePathways
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://epathways.co.nz

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

PHP_CLI_SERVER_WORKERS=4
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=epathways_production
DB_USERNAME=epathways_production
DB_PASSWORD=<paste-production-db-password>

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=1
REDIS_CACHE_DB=2

MAIL_MAILER=log
MAIL_FROM_ADDRESS="hello@epathways.co.nz"
MAIL_FROM_NAME="${APP_NAME}"

VITE_APP_NAME="${APP_NAME}"

CEREBRAS_API_KEY=<your-production-cerebras-key>
CEREBRAS_MODEL=llama3.3-70b

GEMINI_API_KEY=<your-production-gemini-key>

CALENDAR_SYNC_TOKEN=epathways_prod_<random-token>

ADMIN_SEED_EMAIL=admin@epathways.co.nz
ADMIN_SEED_PASSWORD=<strong-production-admin-password-different-from-staging>
```

Press **Enter** then **Ctrl+D**. Then strip whitespace:

```bash
sudo -u deploy sed -i 's/^[[:space:]]*//' /var/www/epathways-production/.env
```

### 4.4 — Production Nginx server block

```bash
nano /etc/nginx/sites-available/epathways.co.nz
```

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name epathways.co.nz www.epathways.co.nz;
    root /var/www/epathways-production/public;

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 512M;
}
```

```bash
ln -s /etc/nginx/sites-available/epathways.co.nz /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx
```

### 4.5 — Create production Supervisor worker

```bash
nano /etc/supervisor/conf.d/epathways-production-worker.conf
```

```ini
[program:epathways-production-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/epathways-production/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=deploy
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/epathways-production-worker.log
stopwaitsecs=3600
```

Save, strip whitespace, register (but **don't start yet** — code isn't deployed):

```bash
sed -i 's/^[[:space:]]*//' /etc/supervisor/conf.d/epathways-production-worker.conf
supervisorctl reread
```

### 4.6 — Create production deploy workflow

In **local repo** (WSL), create `.github/workflows/deploy-production.yml`:

```yaml
name: Deploy to Production

on:
  push:
    branches:
      - main

jobs:
  deploy:
    runs-on: ubuntu-latest

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP 8.3
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, xml, bcmath, curl, mysql, redis, zip, gd, intl
          coverage: none

      - name: Setup Node 20
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Install Composer dependencies
        run: composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

      - name: Install npm dependencies
        run: npm ci

      - name: Build frontend assets
        run: npm run build

      - name: Setup SSH agent
        uses: webfactory/ssh-agent@v0.9.0
        with:
          ssh-private-key: ${{ secrets.VPS_SSH_KEY }}

      - name: Add VPS to known hosts
        run: ssh-keyscan -H ${{ secrets.VPS_HOST }} >> ~/.ssh/known_hosts

      - name: Rsync code to VPS
        run: |
          rsync -rlptvz --delete --no-owner --no-group \
            --exclude='.git' \
            --exclude='.github' \
            --exclude='.env' \
            --exclude='node_modules' \
            --exclude='storage/logs/*' \
            --exclude='storage/framework/cache/*' \
            --exclude='storage/framework/sessions/*' \
            --exclude='storage/framework/views/*' \
            --exclude='storage/app/public/*' \
            --exclude='storage/app/private/*' \
            ./ ${{ secrets.VPS_USER }}@${{ secrets.VPS_HOST }}:/var/www/epathways-production/

      - name: Run post-deploy commands on VPS
        run: |
          ssh ${{ secrets.VPS_USER }}@${{ secrets.VPS_HOST }} '
            cd /var/www/epathways-production &&
            sudo chown -R deploy:www-data storage bootstrap/cache &&
            sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \; &&
            sudo find storage bootstrap/cache -type f -exec chmod 664 {} \; &&
            php artisan storage:link &&
            php artisan migrate --force &&
            php artisan config:cache &&
            php artisan view:cache &&
            php artisan queue:restart
          '
```

> **Note:** the `sudo` calls in the post-deploy step require the NOPASSWD sudoers rule from §1.11. Single VPS hosts both environments, so the rule set up during staging covers production automatically — no additional VPS setup needed for production.

Commit + push to staging first, then merge to main:

```bash
git add .github/workflows/deploy-production.yml
git commit -m "add production deploy workflow for main branch"
git push                          # pushes to staging branch

git checkout main
git pull
git merge staging
git push                          # triggers first production deploy
```

### 4.7 — First-time production post-deploy

After Actions run goes green:

```bash
cd /var/www/epathways-production
sudo -u deploy php artisan key:generate
sudo -u deploy php artisan storage:link
sudo -u deploy php artisan config:clear
sudo -u deploy php artisan db:seed --class=AdminSeeder --force
sudo -u deploy php artisan config:cache
```

Set permissions:

```bash
sudo chown -R deploy:www-data /var/www/epathways-production/storage /var/www/epathways-production/bootstrap/cache
sudo find /var/www/epathways-production/storage -type d -exec chmod 2775 {} \;
sudo find /var/www/epathways-production/bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find /var/www/epathways-production/storage -type f -exec chmod 664 {} \;
sudo find /var/www/epathways-production/bootstrap/cache -type f -exec chmod 664 {} \;
```

Start production worker:

```bash
supervisorctl update
supervisorctl start epathways-production-worker:*
supervisorctl status
```

### 4.8 — Test production locally before DNS cutover

Edit your local Windows hosts file as Administrator (`C:\Windows\System32\drivers\etc\hosts`) and add:

```
76.13.193.63 epathways.co.nz
76.13.193.63 www.epathways.co.nz
```

Visit `http://epathways.co.nz` in a fresh incognito window. Verify the new VPS site renders. **Remove those lines after testing.**

### 4.9 — DNS cutover

In Hostinger DNS:

**Delete** these two records:
- `ALIAS @ → epathways.co.nz.cdn.hstgr.net`
- `CNAME www → www.epathways.co.nz.cdn.hstgr.net`

**Add** these two records:

| Type | Name | Points to | TTL |
|---|---|---|---|
| A | `@` | `76.13.193.63` | 300 |
| A | `www` | `76.13.193.63` | 300 |

Hostinger may show "additional A record" warnings (because `staging` already exists at the same IP) — confirm both.

**Do NOT touch** any MX, TXT, DKIM, or other email-related records — leave those alone so email delivery isn't affected.

Wait 5–10 min, then verify:

```bash
dig @8.8.8.8 epathways.co.nz +short        # should return 76.13.193.63
dig @8.8.8.8 www.epathways.co.nz +short    # should return 76.13.193.63
```

### 4.10 — Issue production SSL

Once DNS resolves to the VPS:

```bash
certbot --nginx -d epathways.co.nz -d www.epathways.co.nz --redirect -m your-email@example.com -n --agree-tos
```

Visit `https://epathways.co.nz` and `https://www.epathways.co.nz` to confirm HTTPS works with auto-redirect from HTTP.

### 4.11 — Cleanup (after 1–2 days of stable production)

Delete the old Hostinger-hosted website entry for `epathways.co.nz` in the **Websites** panel. Keep it as fallback for the first couple of days — if anything goes wrong on the VPS, you can revert DNS back to Hostinger as emergency recovery.

---

## CI/CD Workflow

### How it works

```
Developer
  │
  │ git push to staging branch
  ▼
GitHub Actions (deploy-staging.yml)
  │  1. Checkout code
  │  2. Install PHP 8.3 + Node 20 on runner
  │  3. composer install --no-dev
  │  4. npm ci && npm run build
  │  5. SSH to VPS via VPS_SSH_KEY secret
  │  6. rsync built code → /var/www/epathways-staging/
  │  7. ssh: artisan migrate, config:cache, view:cache, queue:restart
  ▼
https://staging.epathways.co.nz updated within ~1–2 min
```

Same flow for `main` branch → production.

### Why we chose Actions-builds-and-rsyncs (not VPS-pulls-from-git)

- The repo is owned by another GitHub user (`unknownhuman563`) — we are a collaborator without admin access. This means we can't add Deploy Keys or get fine-grained PATs approved.
- This pattern lets the VPS have **zero GitHub credentials** — credentials live in GitHub Actions secrets (encrypted, only injected into ephemeral runners).
- Builds happen on GitHub's runners (4 CPU) instead of the 2-CPU VPS, freeing CPU for serving requests.
- Failed builds don't touch the VPS — atomicity.

### What gets preserved across deploys

The rsync uses these `--exclude` flags:

- `.env` — never overwritten; environment-specific config stays put
- `storage/logs/*`, `storage/framework/cache/*`, `storage/framework/sessions/*`, `storage/framework/views/*` — runtime state preserved
- `storage/app/public/*`, `storage/app/private/*` — **user-uploaded files preserved** (passport PDFs, banner images, etc.). Without these excludes, `--delete` would wipe customer data on every deploy.
- `node_modules`, `.git`, `.github` — not needed on server

### What gets self-healed on every deploy

The post-deploy SSH step automatically restores two things rsync would otherwise damage:

- **Storage and bootstrap/cache permissions** — rsync's `-p` flag preserves source perms (`755 root:root` from the GitHub runner), wiping the `2775 deploy:www-data` setup the web server needs. Auto-restored via `sudo chown ... && sudo find ... -exec chmod 2775/664`.
- **The `public/storage` symlink** — gitignored (so absent from source), `--delete` removes it from destination. Auto-restored via `php artisan storage:link` (idempotent).

These commands run after rsync, before any other artisan commands. If they fail (e.g., NOPASSWD sudoers rule missing per §1.11), the deploy fails loudly in Actions — you'll see exactly which command hung.

### Branch flow

```
feature-branch
   │
   │ test locally
   ▼
staging branch ────────► Actions ────► staging.epathways.co.nz
   │
   │ verify on staging
   ▼
main branch ───────────► Actions ────► epathways.co.nz
```

---

## Maintenance & Operations

### Common commands (on the VPS)

| Task | Command |
|---|---|
| Check workers running | `supervisorctl status` |
| Restart a worker | `supervisorctl restart epathways-staging-worker:*` |
| Tail Laravel log | `tail -f /var/www/epathways-staging/storage/logs/laravel.log` |
| Tail Nginx error log | `tail -f /var/log/nginx/error.log` |
| Tail worker log | `tail -f /var/log/supervisor/epathways-staging-worker.log` |
| Force-reload .env | `cd <site> && sudo -u deploy php artisan config:clear && sudo -u deploy php artisan config:cache` |
| Manually run migrations | `cd <site> && sudo -u deploy php artisan migrate --force` |
| Open Laravel console | `cd <site> && sudo -u deploy php artisan tinker` |

### Inspect databases

```bash
# Show all databases
mysql -e "SHOW DATABASES;"

# Connect to a specific environment
mysql -u epathways_staging -p epathways_staging

# Inside MySQL:
SHOW TABLES;
SELECT id, email, created_at FROM users;
```

For visual browsing, set up an SSH tunnel and connect with DBeaver/TablePlus:

```bash
# In WSL — keep this running
ssh -L 3307:127.0.0.1:3306 deploy@76.13.193.63 -N
```

In your GUI tool: connect to `127.0.0.1:3307` with the staging or production DB credentials.

### SSL certificate renewal

Certbot installs a systemd timer that renews automatically:

```bash
systemctl list-timers | grep certbot
certbot certificates              # see all certs and expiry dates
certbot renew --dry-run           # test the renewal flow
```

### Add a new environment variable

```bash
sudo -u deploy nano /var/www/epathways-staging/.env
# add KEY=value at the bottom, save
cd /var/www/epathways-staging
sudo -u deploy php artisan config:clear
sudo -u deploy php artisan config:cache
sudo -u deploy php artisan queue:restart
```

> If your code reads the new variable via `env()` directly, also add it to `config/services.php` and access via `config('services.foo.key')`. Direct `env()` calls return `null` when config is cached.

### Deploy a code change

```bash
# In local repo
git checkout staging
git add .
git commit -m "your message"
git push                # auto-deploys to staging within ~2 min

# When ready for production
git checkout main
git pull
git merge staging
git push                # auto-deploys to production
```

### Rollback a bad deploy

If a deploy breaks production:

```bash
# In local repo — revert to last good commit
git checkout main
git revert HEAD
git push                # this revert IS a new deploy
```

For DNS-level rollback (if VPS itself is broken), revert the DNS records in Hostinger:
- Delete `A @ → 76.13.193.63`
- Re-add `ALIAS @ → epathways.co.nz.cdn.hstgr.net`

This routes traffic back to Hostinger shared hosting (assuming you didn't delete the old website entry).

### Backup recommendations

Hostinger offers automated daily VPS backups for $6/month. **Strongly recommended for production** — covers DB + uploaded files + code + everything in one rolling backup.

For DB-only backups (free, simple):

```bash
# Add to crontab (sudo crontab -e)
0 3 * * * mysqldump --single-transaction epathways_production > /home/deploy/backups/prod_$(date +\%Y\%m\%d).sql && find /home/deploy/backups -name "prod_*.sql" -mtime +14 -delete
```

---

## Troubleshooting

### Site shows white page or 500 error

Check Laravel log:
```bash
tail -50 /var/www/epathways-production/storage/logs/laravel.log
```

Common causes:
- **`APP_KEY=` empty** → `sudo -u deploy php artisan key:generate` and re-cache
- **Permission denied writing logs** → re-run the permission fix in section 4.7
- **DB connection refused** → verify DB credentials in `.env` match the MySQL user

### Nginx 404 on a working server block

After config changes:
```bash
nginx -t
systemctl reload nginx
```

If the production block doesn't seem to match its server_name, ensure:
- The site is symlinked into `/etc/nginx/sites-enabled/`
- `nginx -T` shows your block in the merged config
- You reloaded Nginx (not just tested with `-t`)

### rsync "Permission denied" / "Operation not permitted"

Symptom in CI logs:
```
chgrp ... failed: Operation not permitted (1)
delete_file: unlink(...) failed: Permission denied (13)
```

Cause: a manual command was run on the VPS as **root** (e.g., `npm run build` while logged in as root from Hostinger's web terminal), creating files owned by `root:root` that the deploy user can't overwrite.

Fix:
```bash
sudo chown -R deploy:www-data /var/www/<site>
sudo find /var/www/<site> -type d -exec chmod 2775 {} \;
sudo find /var/www/<site> -type f -exec chmod 664 {} \;
sudo chmod +x /var/www/<site>/artisan
```

Prevention: **don't run commands directly on the VPS** — let CI/CD handle deployments. Editing `.env` and reading logs is fine; modifying app files is not.

### "Unable to create a directory at storage/app/public/..."

500 error returned to the user, log shows `League\Flysystem\UnableToCreateDirectory` from a controller's `->store('something', 'public')` call.

**Root cause:** rsync's `-p` flag preserved the source's default `755` permissions on `storage/app/public/`, wiping the `2775 deploy:www-data` setup. PHP-FPM (running as `www-data`) can't create subdirectories because group-write is gone.

**Immediate fix on the affected VPS:**

```bash
sudo chown -R deploy:www-data /var/www/<site>/storage /var/www/<site>/bootstrap/cache
sudo find /var/www/<site>/storage /var/www/<site>/bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find /var/www/<site>/storage /var/www/<site>/bootstrap/cache -type f -exec chmod 664 {} \;
```

**Permanent fix:** the post-deploy step in §3.7 / §4.6 already includes these commands. If you're seeing this error, either:

1. The workflow YAML wasn't updated (older deploy ran without the perm-fix). Update the workflow per §3.7 and push.
2. The NOPASSWD sudoers rule from §1.11 isn't in place, so the workflow's sudo calls were rejected. Verify with `sudo -u deploy sudo -n true` on the VPS.

### Image upload succeeds but the image displays as broken (404)

The `image_url` in the database points to `/storage/programs/banners/foo.webp` but the URL returns 404 in the browser.

**Root cause:** `public/storage` symlink is missing. rsync's `--delete` flag removes the symlink because it's gitignored (`/public/storage` in `.gitignore`) and therefore not in the source repo.

**Immediate fix:**

```bash
cd /var/www/<site>
sudo -u deploy php artisan storage:link
```

**Permanent fix:** the post-deploy step from §3.7 / §4.6 includes `php artisan storage:link` (idempotent — safe to run on every deploy). Update the workflow if you don't have it yet.

### AI eligibility analysis returning empty / `{"type": "object"}`

Symptom: `lead.ai_analysis_status = completed` but the analysis JSON is just `{"type":"object"}` — score shows as 0.

Cause: small models like `llama3.1-8b` misinterpret the `response_format: json_object` flag — they sometimes echo the schema description instead of generating actual data conforming to it.

**Two fixes (use whichever fits):**

#### Fix A — Use a larger model (cleanest)

If your Cerebras tier has access:

```bash
sudo -u deploy sed -i 's|^CEREBRAS_MODEL=.*|CEREBRAS_MODEL=llama3.3-70b|' /var/www/<site>/.env
cd /var/www/<site>
sudo -u deploy php artisan config:clear
sudo -u deploy php artisan config:cache
sudo -u deploy php artisan queue:restart
```

Available Cerebras models (as of 2026): `llama3.3-70b`, `gpt-oss-120b`, `qwen-3-32b`, `llama-4-maverick-17b-128e-instruct`. **NOTE:** if a model returns 404, your tier may not have access — list available models with:
```bash
curl -s https://api.cerebras.ai/v1/models -H "Authorization: Bearer <YOUR-CEREBRAS-KEY>"
```

#### Fix B — Make `llama3.1-8b` work (if it's the only model your tier allows)

In `app/Services/CerebrasService.php`:

1. **Drop `'response_format' => ['type' => 'json_object']`** from the `Http::post()` payload. Without this flag, the model follows prompt instructions normally.

2. **Add a JSON extraction helper** that handles cases where the model wraps output in markdown code fences:
   ```php
   private function extractJson(string $content): string
   {
       $trimmed = trim($content);
       if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $trimmed, $m)) {
           return trim($m[1]);
       }
       $start = strpos($trimmed, '{');
       $end = strrpos($trimmed, '}');
       if ($start !== false && $end !== false && $end > $start) {
           return substr($trimmed, $start, $end - $start + 1);
       }
       return $trimmed;
   }
   ```

3. **Validate required fields** after parsing. If the model returned malformed/incomplete JSON, throw an exception so the job's automatic retry can re-attempt:
   ```php
   if (!isset($analysis['overall_score'], $analysis['categories'])) {
       throw new \RuntimeException('Cerebras response missing required fields');
   }
   ```

4. **Add a concrete one-shot example to the system prompt.** Small models pattern-match worked examples far better than abstract schemas. Replace `<placeholder>` syntax with a fully-realized example output the model can imitate.

After committing the code change and pushing through CI/CD, llama3.1-8b becomes reliable. Trade-off: small models will copy phrasing from the example for fields that aren't directly tied to applicant data (top-level summary, generic strengths/concerns). Scores and category-specific summaries are generated fresh from data.

### Vite build fails on case-sensitivity

Symptom: build works locally (Windows/macOS = case-insensitive FS) but fails on GitHub Actions (Linux = case-sensitive) with:
```
[vite:asset] Could not load /resources/Assets/...: ENOENT
```

Cause: code references `/resources/Assets/` (capital A) but actual directory is `/resources/assets/`.

Fix: grep for `/resources/Assets/` in source and replace with `/resources/assets/`. Also check `vite.config.js` aliases.

### DNS not resolving correctly

If `dig` shows different IPs from each resolver (Google, Cloudflare, etc.), and the IPs are random Hostinger ranges:

- A Hostinger "Website" entry for that domain is intercepting DNS at the nameserver level
- Delete that website entry in **Hostinger Websites panel** (not DNS panel)
- Wait 2–5 min, retry `dig`

### GitHub Actions `ssh-keyscan` fails

Transient. Re-run the failed job — it usually works the second time.

If consistently failing, update the workflow step:
```yaml
- name: Add VPS to known hosts
  run: |
    mkdir -p ~/.ssh
    ssh-keyscan -t ed25519,rsa -H ${{ secrets.VPS_HOST }} >> ~/.ssh/known_hosts
```

---

## Security Notes

### What we did right

- ✅ Key-based SSH only (password auth disabled)
- ✅ Root login disabled (`PermitRootLogin prohibit-password`)
- ✅ UFW firewall (only 22, 80, 443 open)
- ✅ Separate `deploy` user (never run app as root)
- ✅ Separate databases per environment
- ✅ Separate Redis DBs per environment
- ✅ `.env` excluded from rsync (never overwritten by deploys)
- ✅ Let's Encrypt SSL with auto-renewal
- ✅ HTTP→HTTPS redirect

### Outstanding security to-dos

- [ ] **Rotate any API keys exposed in chat/logs** (Cerebras, Gemini)
- [ ] **Strengthen weak admin passwords** — production should never use weak passwords like `qweasd123`
- [ ] **Move passport uploads to private disk** (`storage/app/private/`) instead of `storage/app/public/`. Sensitive PDFs should require auth to download:
  ```php
  // controller upload — use 'local', not 'public'
  $path = $request->file('passport_copy')->store('passports', 'local');

  // route — auth-protected
  Route::middleware('auth')->get('/admin/leads/{lead}/passport', function (Lead $lead) {
      abort_unless(auth()->user()->isAdmin(), 403);
      return Storage::disk('local')->download($lead->passport_path);
  });
  ```
- [ ] **Enable Hostinger automated daily backups** ($6/month) for production
- [ ] **Install fail2ban** to block brute-force SSH attempts: `apt install -y fail2ban && systemctl enable --now fail2ban`

### Operational rules for the team

1. **Never run commands directly on the VPS as root** (other than reading logs / inspecting state). All code changes go through CI/CD.
2. **Never push secrets to git.** `.env` is gitignored; per-environment values live only on the VPS or in GitHub Secrets.
3. **Never paste API keys, passwords, or private SSH keys in chat / Slack / commit messages.** They get logged and cached in places you don't expect.
4. **Rotate any leaked credential within 24 hours** of leak.
5. **Use the staging environment** to test changes before they hit production.

---

## Lessons Learned

Things we hit during this deployment that future deployers should know:

### PowerShell SSH paste indents pasted text

Symptom: heredoc paste leaves the closing `EOF` indented, so heredoc never closes. Or `.env` files get 2-space indentation on every line.

Workarounds:
- Use WSL terminal instead of PowerShell when possible
- Use `cat > file` with `Ctrl+D` instead of heredoc (terminator-free)
- After paste, run `sed -i 's/^[[:space:]]*//' file` to strip leading whitespace
- For long single-line commands, type rather than paste

### Hostinger DNS-parking nameservers

Hostinger's `ns1/ns2.dns-parking.com` nameservers will route subdomains to Hostinger's shared hosting load balancer if a corresponding "Website" entry exists in the Hostinger panel — even if you've added an A record for that subdomain. **Delete unwanted Website entries** to free up DNS routing.

### `env()` returns null after `config:cache`

Laravel optimizes `env()` lookups by reading them at config-cache time. Calls to `env()` outside of `config/*.php` files return `null` after `php artisan config:cache`.

Always reference environment variables via `config('services.foo.key')` in application code, not `env('FOO_KEY')`.

If you must use `env()` directly (e.g., in a seeder), clear the cache before running and re-cache after:
```bash
php artisan config:clear
php artisan db:seed --class=YourSeeder
php artisan config:cache
```

### rsync `-a` flag fails when SSH user can't change groups

The `-a` (archive) flag includes `-g` (preserve group) and `-o` (preserve owner). When the deploy user on the VPS doesn't have rights to set arbitrary groups, rsync fails with "Operation not permitted."

Fix in workflow: use `rsync -rlptvz --no-owner --no-group` (preserves permissions/times/symlinks but skips owner/group).

### Setgid bit on directories ensures group inheritance

Without the setgid bit (`chmod 2775` instead of `chmod 775`), new files/directories created inside don't inherit the parent's group. This causes silent permission failures when (e.g.) PHP-FPM creates `storage/app/public/passports/` as `deploy:deploy` instead of `deploy:www-data`.

**Always use `chmod 2775` for directories** that need shared write access between `deploy` and `www-data`.

### Linux is case-sensitive; Windows/macOS often aren't

Code that works locally with `import "/resources/Assets/..."` (capital A) breaks on GitHub Actions Ubuntu runners where the actual filesystem path is `/resources/assets/`.

Be consistent with case in import paths and Vite config aliases. Do a case-sensitive grep before pushing:
```bash
grep -rn "/resources/Assets" .
```

### Small LLM models can fail structured-output prompts

`llama3.1-8b` (Cerebras's smallest model) returned `{"type": "object"}` (a JSON schema fragment) instead of actual data when asked for structured eligibility analysis. Two paths forward:

1. **Larger model** (≥ 32B parameters): `llama3.3-70b`, `gpt-oss-120b`, `qwen-3-32b` handle structured output reliably with `response_format: json_object`.
2. **Adapt to small models**: drop the `response_format` flag, add JSON extraction (handles markdown code fences), add field validation, and put a concrete worked example in the system prompt. Small models copy from examples — give them a good one. See [Troubleshooting → AI eligibility analysis returning empty](#ai-eligibility-analysis-returning-empty--type-object) for the full code change.

### rsync `--delete` will eat user uploads if you don't exclude them

Our original `deploy-staging.yml` and `deploy-production.yml` workflows had this rsync line:

```yaml
rsync -rlptvz --delete --no-owner --no-group \
  --exclude='.git' --exclude='.github' --exclude='.env' --exclude='node_modules' \
  --exclude='storage/logs/*' --exclude='storage/framework/cache/*' \
  --exclude='storage/framework/sessions/*' --exclude='storage/framework/views/*' \
  ./ deploy@vps:/var/www/<site>/
```

We forgot two excludes: `storage/app/public/*` and `storage/app/private/*`. These are where Laravel stores user-uploaded files (passport PDFs in our case).

**What happened on every deploy:**
1. Source repo doesn't have user uploads (they're not in git)
2. rsync `--delete` says: "this exists on dest but not source → delete it"
3. Tries to unlink `storage/app/public/passports/abc123.pdf`
4. ✅ Lucky save: file permissions (`www-data` ownership from PHP-FPM) prevented `deploy` user from deleting → rsync errors but data survives
5. Without that permission "bug", every deploy would silently destroy customer data

**Fix:** add `--exclude='storage/app/public/*'` and `--exclude='storage/app/private/*'` to both workflows.

**Lesson:** when using `rsync --delete`, **explicitly exclude every directory the application writes to**. If unsure, prefer `rsync` without `--delete` (leaves orphaned files but doesn't destroy data).

### One snapshot ≠ a backup strategy

Hostinger's free single snapshot is one point-in-time recovery option. For production with real customer data, enable automated daily backups (`$6/month`) — they roll over so you have multiple recovery points across the past week.

### rsync `-p` wipes storage perms on every deploy

Sister bug to the `--delete` issue above. `rsync -rlptvz --delete` includes `-p` (preserve permissions). It preserves them **from the source side, applied onto the destination**. The source repo on a fresh GitHub Actions runner has `storage/app/public/` with default `755 root:root` (or whatever the runner's umask produces). On every deploy, rsync overwrites the destination's `2775 deploy:www-data` setup with those defaults — wiping the setgid bit and group-write.

**What happens after every deploy until fixed:**

1. PHP-FPM (`www-data`) tries to upload a file → calls `Storage::disk('public')->put()`
2. Laravel attempts to `mkdir storage/app/public/programs/banners/`
3. Parent dir is now `755 root:root` (or `755 deploy:deploy` after rsync) — `www-data` lacks write
4. `League\Flysystem\UnableToCreateDirectory` thrown → 500 to the user

**Sister symptom — symlink wipe:** the gitignored `public/storage` symlink isn't in the source, so `--delete` removes it. After every deploy, the upload-display URL `/storage/...` returns 404 even though the file uploaded successfully (when perms allow).

**Fix (already integrated into §3.7 and §4.6 workflow YAML):**

The post-deploy SSH step now runs:

```bash
sudo chown -R deploy:www-data storage bootstrap/cache &&
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \; &&
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \; &&
php artisan storage:link
```

This auto-restores perms and symlink on every deploy. The `sudo` calls require the NOPASSWD sudoers rule from §1.11.

**Lesson:** when using `rsync -p` against a destination that needs custom perms (web server / setgid / mixed-ownership directories), you have three choices:

1. **Don't preserve permissions** (`--no-perms`) — but then permissions across the whole tree become whatever rsync's umask produces. Often unacceptable.
2. **Carefully match source-side perms to destination needs** — fragile, breaks when anyone touches the source.
3. **Re-apply destination perms after rsync** in a post-deploy step ← what we do.

Option 3 is the only sustainable path for this stack. The post-deploy is idempotent and adds ~1 second to deploys.

---

## Appendix: Quick Reference

### Environment variables that differ between staging and production

```diff
- APP_ENV=staging
+ APP_ENV=production

- APP_URL=https://staging.epathways.co.nz
+ APP_URL=https://epathways.co.nz

- DB_DATABASE=epathways_staging
- DB_USERNAME=epathways_staging
+ DB_DATABASE=epathways_production
+ DB_USERNAME=epathways_production

  # Production also adds Redis isolation
+ REDIS_DB=1
+ REDIS_CACHE_DB=2

  # Different admin passwords
- ADMIN_SEED_PASSWORD=<staging-pw>
+ ADMIN_SEED_PASSWORD=<production-pw>
```

### Useful one-liners

```bash
# Check both queue workers are running
supervisorctl status

# See real-time deploy progress
tail -f /var/log/supervisor/epathways-production-worker.log

# Inspect latest lead
cd /var/www/epathways-production && sudo -u deploy php artisan tinker --execute="\$l = App\Models\Lead::latest()->first(); print_r(\$l->toArray());"

# Re-cache after .env change
cd /var/www/epathways-production && sudo -u deploy php artisan config:clear && sudo -u deploy php artisan config:cache && sudo -u deploy php artisan queue:restart

# Check disk usage
df -h /

# Check VPS uptime + load
uptime
```

### Where logs live

- Laravel app: `/var/www/<site>/storage/logs/laravel.log`
- Nginx access: `/var/log/nginx/access.log`
- Nginx error: `/var/log/nginx/error.log`
- PHP-FPM: `/var/log/php8.3-fpm.log`
- Queue workers: `/var/log/supervisor/epathways-<env>-worker.log`
- Certbot: `/var/log/letsencrypt/letsencrypt.log`
- System: `journalctl -u nginx`, `journalctl -u php8.3-fpm`, etc.

---

*Last updated: April 2026 (added storage perm-fix and symlink self-heal in post-deploy step; added §1.11 sudoers rule)*
