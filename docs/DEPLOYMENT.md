# Deployment Guide — Saifzz Aircond

Deploy the app to a fresh Ubuntu VM using Docker Compose. Tested end-to-end on GCP.

**Architecture**
- **Nginx** runs on the host — handles SSL and serves static files (`/build`, `/storage`).
- **Docker Compose** runs everything else: PHP 8.5-FPM (`app`), queue `worker`, `postgres`, `redis`.
- Nginx proxies PHP requests to the `app` container on `127.0.0.1:9000`.
- **No PHP, Composer, or Node on the host** — all builds happen inside the Docker image.

**These files are already in the repo** (no need to create them):
`Dockerfile.prod`, `docker-compose.prod.yml`, `.dockerignore`, `.github/workflows/`.

---

## 1. Create the VM

| Setting | Value |
|---|---|
| Machine | `e2-medium` (2 vCPU, 4 GB RAM) — min `e2-small` |
| OS | Ubuntu 22.04 or 24.04 LTS |
| Disk | 30 GB+ |
| Firewall | **Tick "Allow HTTP traffic" and "Allow HTTPS traffic"** |

> The HTTP/HTTPS firewall ticks are critical. Without them, SSL setup (Step 7) fails with a connection timeout. To add later: Compute Engine → VM → Edit → Firewall.

Note the VM's **external IP**.

---

## 2. DNS

At your DNS provider for `mktechnologies.my`:

```
Type  Name    Value
A     saifzz  <VM_EXTERNAL_IP>
```

Verify before continuing (must return the VM IP):

```bash
nslookup saifzz.mktechnologies.my
```

---

## 3. Server Setup

SSH into the VM (GCP Console → SSH button), then:

```bash
# Avoid interactive "restart services?" prompts during upgrades
sudo sed -i "s/#\$nrconf{restart} = 'i';/\$nrconf{restart} = 'a';/" /etc/needrestart/needrestart.conf

sudo apt update && sudo apt upgrade -y
sudo apt install -y git nginx certbot python3-certbot-nginx ufw

# Docker
curl -fsSL https://get.docker.com | sudo sh

# Firewall
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw --force enable
```

---

## 4. Deploy User & App Directory

A dedicated `deploy` user owns the app and runs the containers.

```bash
sudo useradd -m -s /bin/bash deploy
sudo usermod -aG docker deploy

# Generate SSH key (used later by GitHub Actions)
sudo -u deploy ssh-keygen -t ed25519 -C "github-actions-deploy" -f /home/deploy/.ssh/id_ed25519 -N ""
sudo -u deploy cp /home/deploy/.ssh/id_ed25519.pub /home/deploy/.ssh/authorized_keys
sudo chmod 600 /home/deploy/.ssh/authorized_keys

# Allow deploy to reload nginx without a password
echo 'deploy ALL=(ALL) NOPASSWD: /bin/systemctl reload nginx' | sudo tee /etc/sudoers.d/deploy

# App directory
sudo mkdir -p /var/www/Saifzz-Aircond
sudo chown deploy:deploy /var/www/Saifzz-Aircond
```

Save the private key for GitHub Actions (Step 9):

```bash
sudo cat /home/deploy/.ssh/id_ed25519
```

---

## 5. Clone & Configure

```bash
sudo -u deploy git clone https://github.com/hamidkarim8/Saifzz-Aircond.git /var/www/Saifzz-Aircond
cd /var/www/Saifzz-Aircond
sudo -u deploy cp .env.example .env
sudo -u deploy nano .env
```

Set these values in `.env`. **`DB_HOST` and `REDIS_HOST` must be the Docker service names** (`postgres`, `redis`) — not `127.0.0.1`. Leave `APP_KEY` blank; Step 6 fills it.

```dotenv
APP_NAME="Saifzz Aircond"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://saifzz.mktechnologies.my

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=saifzz_prod
DB_USERNAME=saifzz
DB_PASSWORD=CHANGE_THIS_PASSWORD

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379

MAIL_MAILER=smtp
# fill in SMTP credentials when ready

LOG_CHANNEL=daily
LOG_LEVEL=error
```

> In nano: paste with `Ctrl+Shift+V`, save with `Ctrl+X` → `Y` → `Enter`.

---

## 6. Build & Launch

Run these as `deploy` from `/var/www/Saifzz-Aircond`. The first build takes a few minutes (compiles the image). All commands use `-f docker-compose.prod.yml`.

```bash
cd /var/www/Saifzz-Aircond
DC="sudo -u deploy docker compose -f docker-compose.prod.yml"

# 1. Build the image and start all containers
$DC build
$DC up -d

# 2. Generate the app encryption key (writes it into .env)
$DC exec -T app php artisan key:generate

# 3. Let the container's php-fpm user (uid 82) write to mounted storage
sudo chown -R 82:82 storage

# 4. Database: run migrations, then seed once (creates the admin user)
$DC exec -T app php artisan migrate --force
$DC exec -T app php artisan db:seed --force

# 5. Cache config/routes/views (reads the new APP_KEY)
$DC exec -T app php artisan optimize

# 6. Copy the built frontend assets out to the host (nginx serves these)
$DC cp app:/var/www/Saifzz-Aircond/public/build ./public/build

# 7. Storage symlink on the host (for uploaded files)
sudo -u deploy ln -sfn /var/www/Saifzz-Aircond/storage/app/public public/storage

# 8. Confirm all 4 containers are up (postgres should say "healthy")
$DC ps
```

---

## 7. Nginx + SSL

```bash
sudo nano /etc/nginx/sites-available/saifzz
```

Paste:

```nginx
server {
    listen 80;
    server_name saifzz.mktechnologies.my;
    root /var/www/Saifzz-Aircond/public;

    index index.php;
    charset utf-8;
    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME /var/www/Saifzz-Aircond/public$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable it, remove the default site, and add SSL:

```bash
sudo ln -s /etc/nginx/sites-available/saifzz /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx

sudo certbot --nginx -d saifzz.mktechnologies.my --non-interactive --agree-tos -m hamidkarim2002@gmail.com
sudo systemctl enable certbot.timer
```

Certbot rewrites the nginx config to HTTPS automatically.

---

## 8. Verify

```bash
curl -sS -o /dev/null -w "%{http_code}\n" https://saifzz.mktechnologies.my   # expect 200
```

Open `https://saifzz.mktechnologies.my` and log in:
- **Email:** `admin@saifzz.test`
- **Password:** `password` → **change it immediately after first login.**

Confirm the page is styled (proves the `/build` assets were copied). Add the Laravel scheduler to cron:

```bash
sudo crontab -u deploy -e
# Add this line:
* * * * * cd /var/www/Saifzz-Aircond && docker compose -f docker-compose.prod.yml exec -T app php artisan schedule:run >> /dev/null 2>&1
```

Deployment complete.

---

## 9. Automated Deploys (GitHub Actions)

Pushing to `main` runs tests, then deploys automatically. Set these in
**GitHub → Settings → Secrets and variables → Actions**:

| Secret | Value |
|---|---|
| `DEPLOY_SSH_KEY` | The private key printed in Step 4 |
| `DEPLOY_HOST` | VM external IP |
| `DEPLOY_USER` | `deploy` |

The workflow (`.github/workflows/deploy.yml`) pulls latest, rebuilds the
containers, copies assets to the host, and runs migrations.

---

## 10. Common Operations

All commands assume `cd /var/www/Saifzz-Aircond` and use
`docker compose -f docker-compose.prod.yml` (aliased `$DC` below).

```bash
$DC ps                                    # container status
$DC logs -f app                           # tail app logs
$DC exec -T app tail -50 storage/logs/laravel-$(date +%F).log   # Laravel error log
$DC exec -T app php artisan optimize:clear # clear all caches
$DC restart app worker                    # restart app + queue
$DC exec postgres psql -U saifzz saifzz_prod   # database shell
```

Manual redeploy (the GitHub Action does this for you):

```bash
# storage is owned by uid 82 (php-fpm); hand it to deploy so git can reset, then hand it back
docker run --rm -v "$PWD/storage:/s" alpine chown -R "$(id -u):$(id -g)" /s
git fetch origin main && git reset --hard origin/main
$DC build app worker
$DC up -d --no-deps app worker
docker run --rm -v "$PWD/storage:/s" alpine chown -R 82:82 /s
rm -rf ./public/build && $DC cp app:/var/www/Saifzz-Aircond/public/build ./public/build
$DC exec -T app php artisan migrate --force
$DC exec -T app php artisan optimize
```

Database backup:

```bash
$DC exec -T postgres pg_dump -U saifzz saifzz_prod | gzip > saifzz_$(date +%Y%m%d_%H%M).sql.gz
```

---

## 11. Troubleshooting

| Symptom | Cause & Fix |
|---|---|
| `500` and no styling | Asset copy missing. Re-run `$DC cp app:/var/www/Saifzz-Aircond/public/build ./public/build`. |
| `500`, log shows `MissingAppKeyException` | `APP_KEY` empty. `$DC exec -T app php artisan key:generate`, then `$DC exec -T app php artisan optimize`, then `$DC restart app`. |
| `500`, log can't be written / no log file | Storage not writable by container. `sudo chown -R 82:82 storage`. |
| `db:seed` → `undefined function fake()` | You're on old code; faker is dev-only. The current seeder uses `firstOrCreate` — pull latest and rebuild. |
| SSL/certbot connection timeout | HTTP/HTTPS firewall not open. Tick both in GCP VM settings (Step 1), then re-run certbot. |
| `curl localhost` hits wrong site | Default nginx site still enabled. `sudo rm /etc/nginx/sites-enabled/default && sudo systemctl reload nginx`. |
| Changed `.env` but no effect | Config is cached. `$DC exec -T app php artisan optimize:clear && $DC exec -T app php artisan optimize`. |
| Deploy `git reset` → `unlink ... storage/.gitignore: Permission denied` | `storage` is owned by uid 82, deploy can't reset it. Hand it over and back: `docker run --rm -v "$PWD/storage:/s" alpine chown -R "$(id -u):$(id -g)" /s` → git reset → `... chown -R 82:82 /s`. The deploy workflow does this automatically. |

> **Why the `82:82` storage owner?** The PHP-FPM process inside the Alpine image runs as `www-data` = uid 82. The mounted host `storage/` must be owned by that uid so the app can write logs, sessions, and cache.
