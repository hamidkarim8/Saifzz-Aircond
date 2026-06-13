# Deployment Guide — Saifzz Aircond

**Target:** Google Cloud VM → `saifzz.mktechnologies.my`
**Stack:** Docker Compose (PHP 8.3-FPM · PostgreSQL 16 · Redis) · Nginx (host, SSL) · Node.js (build only)
**Strategy:** Docker Compose on server. GitHub Actions CI/CD.

---

## 1. VM Spec

### Current (GCP Free Trial)
| Resource | Spec |
|---|---|
| Machine | `e2-medium` (2 vCPU, 4 GB RAM) |
| Region | `us-central1` / `us-east1` / `us-west1` |
| OS | Ubuntu 22.04 LTS |
| Boot disk | 30 GB standard HDD |
| Network | 1 static external IP |

### Recommended for production (ipserverone / next GCP account)
| Resource | Spec |
|---|---|
| vCPU | 2 |
| RAM | 4 GB |
| Disk | 40 GB SSD |
| Bandwidth | 100 GB/month sufficient |

---

## 2. DNS Setup

At your DNS provider for `mktechnologies.my`:

```
Type  Name    Value
A     saifzz  <GCP_EXTERNAL_IP>
```

Propagation takes 5–60 min. Verify: `nslookup saifzz.mktechnologies.my`

---

## 3. Add production Docker files to repo

Commit these files before first deploy.

### `Dockerfile.prod`

```dockerfile
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    git curl libpq-dev libzip-dev zip unzip icu-dev oniguruma-dev

RUN docker-php-ext-install \
    pdo_pgsql pgsql zip bcmath intl mbstring opcache \
    && pecl install redis && docker-php-ext-enable redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/Saifzz-Aircond

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --optimize-autoloader

COPY . .
RUN composer run-script post-autoload-dump 2>/dev/null || true

RUN chown -R www-data:www-data /var/www/Saifzz-Aircond \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
```

### `docker-compose.prod.yml`

```yaml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile.prod
    ports:
      - "127.0.0.1:9000:9000"
    volumes:
      - ./storage:/var/www/Saifzz-Aircond/storage
      - ./.env:/var/www/Saifzz-Aircond/.env:ro
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_started
    restart: unless-stopped

  worker:
    build:
      context: .
      dockerfile: Dockerfile.prod
    command: php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
    volumes:
      - ./storage:/var/www/Saifzz-Aircond/storage
      - ./.env:/var/www/Saifzz-Aircond/.env:ro
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_started
    restart: unless-stopped

  postgres:
    image: postgres:16-alpine
    ports:
      - "127.0.0.1:5432:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data
    environment:
      POSTGRES_DB: ${DB_DATABASE}
      POSTGRES_USER: ${DB_USERNAME}
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME}"]
      interval: 5s
      timeout: 5s
      retries: 5
    restart: unless-stopped

  redis:
    image: redis:7-alpine
    command: redis-server --maxmemory 128mb --maxmemory-policy allkeys-lru
    ports:
      - "127.0.0.1:6379:6379"
    restart: unless-stopped

volumes:
  pgdata:
```

Commit and push both to `main` before proceeding.

---

## 4. Initial Server Setup

SSH into the VM (GCP Console → SSH button, or add your SSH key at VM creation).

### 4.1 System packages

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y git curl nginx certbot python3-certbot-nginx ufw fail2ban
```

### 4.2 Docker

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
newgrp docker
docker --version
```

### 4.3 Node.js 20 LTS (build only — for Vite assets)

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

### 4.4 Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

---

## 5. App Directory

```bash
sudo mkdir -p /var/www/Saifzz-Aircond
sudo chown -R $USER:www-data /var/www/Saifzz-Aircond
sudo chmod -R 775 /var/www/Saifzz-Aircond
```

---

## 6. Deploy User & SSH Key for GitHub Actions

```bash
sudo useradd -m -s /bin/bash deploy
sudo usermod -aG www-data deploy
sudo usermod -aG docker deploy

# Generate SSH key
sudo -u deploy ssh-keygen -t ed25519 -C "github-actions-deploy" -f /home/deploy/.ssh/id_ed25519 -N ""

# Authorize it
sudo -u deploy cp /home/deploy/.ssh/id_ed25519.pub /home/deploy/.ssh/authorized_keys
sudo chmod 600 /home/deploy/.ssh/authorized_keys

# Sudo for nginx reload only
echo 'deploy ALL=(ALL) NOPASSWD: /bin/systemctl reload nginx' \
  | sudo tee /etc/sudoers.d/deploy
```

Copy private key for GitHub secret:

```bash
sudo cat /home/deploy/.ssh/id_ed25519
```

Save as GitHub secret `DEPLOY_SSH_KEY`.

---

## 7. Nginx Config

Nginx runs on the host for SSL termination and static file serving. PHP requests proxy to the PHP-FPM container on port 9000.

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

Enable:

```bash
sudo ln -s /etc/nginx/sites-available/saifzz /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 8. SSL (Let's Encrypt)

```bash
sudo certbot --nginx -d saifzz.mktechnologies.my --non-interactive --agree-tos -m hamidkarim2002@gmail.com
sudo systemctl enable certbot.timer
```

---

## 9. First Manual Deploy (bootstrap)

```bash
sudo -u deploy bash
cd /var/www/Saifzz-Aircond

# Clone repo
git clone git@github.com:YOUR_ORG/Saifzz-Aircond.git .

# .env — see Section 10
cp .env.example .env
nano .env

# Build frontend assets on host (nginx serves these as static files)
npm ci && npm run build && rm -rf node_modules

# Create storage symlink for nginx (PHP-FPM is in Docker, can't run artisan here)
ln -sfn /var/www/Saifzz-Aircond/storage/app/public /var/www/Saifzz-Aircond/public/storage

# Build and start containers
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d

# Bootstrap Laravel
docker compose -f docker-compose.prod.yml exec -T app php artisan key:generate
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec -T app php artisan db:seed --force
docker compose -f docker-compose.prod.yml exec -T app php artisan optimize

# Fix storage permissions
sudo chown -R deploy:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

---

## 10. Production `.env` Values

```dotenv
APP_NAME="Saifzz Aircond"
APP_ENV=production
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
# fill in SMTP credentials

LOG_CHANNEL=daily
LOG_LEVEL=error
```

> `DB_HOST=postgres` and `REDIS_HOST=redis` — Docker Compose service names, not `127.0.0.1`.

---

## 11. GitHub Actions CI/CD

### Repository Secrets (Settings → Secrets → Actions)

| Secret | Value |
|---|---|
| `DEPLOY_SSH_KEY` | Private key from Section 6 |
| `DEPLOY_HOST` | GCP external IP |
| `DEPLOY_USER` | `deploy` |

### `.github/workflows/ci.yml`

```yaml
name: CI

on:
  push:
    branches: [dev]
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      postgres:
        image: postgres:16-alpine
        env:
          POSTGRES_DB: saifzz_testing
          POSTGRES_USER: saifzz
          POSTGRES_PASSWORD: secret
        ports: ['5432:5432']
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pgsql, pdo_pgsql, redis, mbstring, xml, curl, zip, bcmath
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

      - name: Copy .env
        run: |
          cp .env.example .env
          sed -i 's/DB_CONNECTION=sqlite/DB_CONNECTION=pgsql/' .env
          echo "DB_HOST=127.0.0.1" >> .env
          echo "DB_PORT=5432" >> .env
          echo "DB_DATABASE=saifzz_testing" >> .env
          echo "DB_USERNAME=saifzz" >> .env
          echo "DB_PASSWORD=secret" >> .env

      - name: Generate key
        run: php artisan key:generate

      - name: Run tests
        run: php artisan test --parallel
```

### `.github/workflows/deploy.yml`

```yaml
name: Deploy

on:
  push:
    branches: [main]

jobs:
  test:
    uses: ./.github/workflows/ci.yml

  deploy:
    needs: test
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Setup SSH
        uses: webfactory/ssh-agent@v0.9.0
        with:
          ssh-private-key: ${{ secrets.DEPLOY_SSH_KEY }}

      - name: Add host key
        run: ssh-keyscan -H ${{ secrets.DEPLOY_HOST }} >> ~/.ssh/known_hosts

      - name: Deploy
        run: |
          ssh ${{ secrets.DEPLOY_USER }}@${{ secrets.DEPLOY_HOST }} 'bash -s' << 'ENDSSH'
            set -e
            cd /var/www/Saifzz-Aircond

            git fetch origin main
            git reset --hard origin/main

            npm ci && npm run build && rm -rf node_modules

            docker compose -f docker-compose.prod.yml build app worker
            docker compose -f docker-compose.prod.yml up -d --no-deps app worker

            docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
            docker compose -f docker-compose.prod.yml exec -T app php artisan optimize
          ENDSSH

      - name: Notify success
        if: success()
        run: echo "Deployed to https://saifzz.mktechnologies.my"
```

> **Why `git reset --hard`** — atomic, no merge conflicts. Server tracks `origin/main` exactly.

---

## 12. Dev Workflow

```bash
# Daily dev loop
git checkout dev
# make changes, test locally
git push origin dev        # triggers CI tests only

# Ready to release
git checkout main
git merge dev
git push origin main       # triggers CI + CD → auto deploys
```

---

## 13. Database Backup (deferred — set up before migrating to ipserverone)

```bash
docker compose -f docker-compose.prod.yml exec -T postgres \
  pg_dump -U saifzz saifzz_prod | gzip > /backups/saifzz_$(date +%Y%m%d_%H%M).sql.gz
```

Will add: daily cron + GCS bucket upload + 7-day retention.

---

## 14. Post-Deploy Checklist

- [ ] `https://saifzz.mktechnologies.my` loads (green padlock)
- [ ] Login works
- [ ] Create a service record end-to-end
- [ ] Containers running: `docker compose -f docker-compose.prod.yml ps`
- [ ] Logs clean: `docker compose -f docker-compose.prod.yml logs -f app`

### Laravel Scheduler (cron)

```bash
sudo crontab -u deploy -e
# Add:
* * * * * docker compose -f /var/www/Saifzz-Aircond/docker-compose.prod.yml exec -T app php artisan schedule:run >> /dev/null 2>&1
```

---

## 15. Quick Reference

```bash
# Redeploy manually
ssh deploy@<IP> 'cd /var/www/Saifzz-Aircond && git pull && npm ci && npm run build && rm -rf node_modules && docker compose -f docker-compose.prod.yml build && docker compose -f docker-compose.prod.yml up -d && docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force && docker compose -f docker-compose.prod.yml exec -T app php artisan optimize'

# Tail app logs
ssh deploy@<IP> 'docker compose -f /var/www/Saifzz-Aircond/docker-compose.prod.yml logs -f app'

# Clear all caches
ssh deploy@<IP> 'docker compose -f /var/www/Saifzz-Aircond/docker-compose.prod.yml exec -T app php artisan optimize:clear'

# Check containers
ssh deploy@<IP> 'docker compose -f /var/www/Saifzz-Aircond/docker-compose.prod.yml ps'

# psql shell
ssh deploy@<IP> 'docker compose -f /var/www/Saifzz-Aircond/docker-compose.prod.yml exec postgres psql -U saifzz saifzz_prod'
```
