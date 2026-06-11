# Saifzz Aircond — Local Dev Setup (WSL2 + Docker)

> How to run this project on your machine. Written for someone coming from Windows
> who is new to Linux/WSL. Keep this open until the workflow feels natural.

---

## TL;DR

The project lives **inside Ubuntu Linux (WSL2)**, not on `C:\`. You open Ubuntu, go to
the project folder, start Docker, and code. The app is at **http://localhost:8000**.

```bash
wsl -d Ubuntu                 # 1. open the Linux shell
cd ~/Saifzz-Aircond           # 2. go to the project
docker compose up -d          # 3. start the app (first time per reboot)
antigravity .                 # 4. open the IDE on this folder
```

---

## Why WSL2 (the short story)

- Your PC runs **Windows**. **WSL2** runs a real **Ubuntu Linux** inside Windows at the
  same time, sharing the machine. It is fast and built-in, not a heavy virtual machine.
- **Docker** (which runs the app's containers) is a Linux technology. When the code sat on
  `C:\` (a Windows filesystem), Docker had to read every file across a slow Windows↔Linux
  bridge — so every click reloaded hundreds of files slowly.
- Moving the code **onto the Linux side** (Ubuntu's native `ext4` filesystem) removed that
  bridge. Measured result: page loads ~8× faster, asset build ~12× faster, test suite ~7×.
- Nothing about the app or code changed — only **where the files sit**.

---

## Where things live

| | Path |
|---|---|
| **Project (canonical)** | `~/Saifzz-Aircond` inside Ubuntu = `/home/hamid/Saifzz-Aircond` |
| Same, from Windows Explorer | `\\wsl.localhost\Ubuntu\home\hamid\Saifzz-Aircond` |
| Old Windows copy (LEGACY — do not run) | `C:\Saifzz-Aircond` |
| App in the browser | http://localhost:8000 |

- **Ubuntu username:** `hamid`  (`~` is short for `/home/hamid`)
- **Default WSL distro:** `Ubuntu` (so plain `wsl` opens it)

---

## Daily workflow

### 1. Open Ubuntu (the Linux terminal)
Any one of:
- **Start menu** → type `Ubuntu` → open the Ubuntu app, **or**
- **Windows Terminal** → click the `⌄` dropdown → **Ubuntu**, **or**
- Any PowerShell/CMD window → run `wsl` (or `wsl -d Ubuntu`).

You'll land in a Linux shell. The prompt looks like `hamid@MACHINE:~$`.

### 2. Go to the project
```bash
cd ~/Saifzz-Aircond
```

### 3. Start / stop the app (Docker)
```bash
docker compose up -d      # start (web + Postgres + Redis) in the background
docker compose ps         # see what's running
docker compose down       # stop everything (data is kept)
```
After starting, open **http://localhost:8000**.

### 4. Open the IDE (Antigravity) + Claude
From the project folder in the Ubuntu shell:
```bash
antigravity .             # opens the IDE attached to the Linux folder (WSL mode)
```
- If `antigravity` isn't found in the Ubuntu shell yet: open Antigravity on Windows first,
  install its **WSL / Remote** extension, then use the blue **`><`** button (bottom-left)
  → **Connect to WSL → Ubuntu**, then **File → Open Folder →** `/home/hamid/Saifzz-Aircond`.
  After that the `antigravity .` shortcut works from the WSL shell.
- The IDE's integrated terminal is now an **Ubuntu** shell already in the project. Run
  `claude` there to get the fast file access.

> A fresh Claude session starts blank. Tell it: *"Read docs/STATUS.md and
> docs/SESSION-LOG.md, then continue."* — those files hold the current project state.

---

## Command cheat sheet (run from `~/Saifzz-Aircond`)

```bash
# App lifecycle
docker compose up -d
docker compose down
docker compose restart laravel.test

# Laravel (artisan) — note the "exec -T laravel.test" prefix
docker compose exec -T laravel.test php artisan migrate
docker compose exec -T laravel.test php artisan test
docker compose exec -T laravel.test php artisan tinker
docker compose exec -T laravel.test php artisan route:list

# Frontend assets
docker compose exec -T laravel.test npm run build
docker compose exec -T laravel.test npm run dev      # live-reload while coding

# Logs
docker compose logs -f laravel.test
```

Tip: `./vendor/bin/sail <cmd>` is a shorthand for `docker compose exec laravel.test <cmd>`
once `vendor/bin/sail` exists.

---

## ⚠️ Do NOT run the old `C:\Saifzz-Aircond` copy

Both folders share the **same** Docker engine, project name, ports, and database volume.
Starting the old copy's stack (running `docker compose up` while inside `C:\Saifzz-Aircond`)
collides with the WSL one. Rule: **only run Docker commands from `~/Saifzz-Aircond` in WSL.**
You may keep `C:\Saifzz-Aircond` as a backup — just don't start it.

---

## First-time housekeeping

### Change your Ubuntu password
The setup password is temporary. In an Ubuntu shell:
```bash
passwd
```
It asks for the **current** password (the temporary one), then the **new** one twice.
Note: while typing a password the screen shows **nothing** (no dots) — that's normal.

### GitHub from WSL (for push/pull)
The first `git push`/`git pull` from Ubuntu may ask to authenticate. Easiest:
```bash
sudo apt-get install -y gh     # if not present
gh auth login                  # follow the browser prompt, pick HTTPS
```
Then `git pull` / `git push` work normally.

---

## Troubleshooting

- **500 error / `tempnam()` on page load** → storage not writable by the container user:
  ```bash
  docker compose exec -T -u root laravel.test bash -c \
    "chown -R sail:sail storage bootstrap/cache && chmod -R ug+rwX storage bootstrap/cache"
  ```
- **`vendor/autoload.php` not found** (after a fresh copy/clone — `vendor/` isn't in git):
  ```bash
  docker compose exec -T laravel.test composer install
  ```
  (Run it **inside** the container, not via a one-off `docker run`.)
- **`node_modules` missing / Vite errors** → `docker compose exec -T laravel.test npm install`
- **Port 8000 already in use** → something else (maybe the old `C:\` stack) is running:
  `docker compose ls` to see projects; stop the stray one.
- **Browser shows nothing right after `up`** → the web process needs a few seconds, or
  restart it: `docker compose restart laravel.test`.

---

## Ports (host → container)

| Service | Host port | In-container host |
|---|---|---|
| App (web) | **8000** | — (`http://localhost:8000`) |
| Postgres | **5433** | `pgsql:5432` |
| Redis | **6380** | `redis:6379` |
| Vite (dev) | 5173 | — |

Remapped in `.env` (`APP_PORT`, `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`) to avoid clashes.
