# Moving the KCK dashboard to the GSV VPS

The dashboard runs on the same VPS as GSV Workspace (187.127.181.40), next to it but separate:
its own containers (`kck-web` = the PHP dashboard, unchanged; `kck-db` = its own MariaDB database), its own data,
its own deploy. Visitors reach it through the Workspace's Caddy, which adds HTTPS and compresses the data
(the dashboard's data downloads shrink to about a tenth).

Everything below is done once. Commands run **on the VPS as root** (Hostinger → VPS → Browser terminal, or SSH).
Lines starting with `#` are explanations, not commands.

---

## 1. Give the VPS read access to this repository

```bash
ssh-keygen -t ed25519 -f /root/.ssh/kck_repo -N "" -C "vps-kck-read"
cat /root/.ssh/kck_repo.pub
```
GitHub → `interngsv-oss/kck-gsv-dashboard` → **Settings → Deploy keys → Add deploy key**: title `GSV VPS`,
paste the line shown, leave *Allow write access* unticked → **Add key**. Then:

```bash
cat >> /root/.ssh/config <<'EOF'
Host github-kck
  HostName github.com
  User git
  IdentityFile /root/.ssh/kck_repo
  IdentitiesOnly yes
EOF
git clone git@github-kck:interngsv-oss/kck-gsv-dashboard.git /opt/kck-dashboard
```

## 2. The private settings (never in git)

```bash
cd /opt/kck-dashboard/deploy/vps
cp env.example .env
nano .env          # set MARIADB_PASSWORD and MARIADB_ROOT_PASSWORD to two long random passwords; Ctrl+O, Enter, Ctrl+X
cp ../../php_backend/config.example.php config.php
nano config.php
```
In `config.php`:
- `'db'`: `'host' => 'kck-db'`, and `name` / `user` / `pass` = `MARIADB_DATABASE` / `MARIADB_USER` /
  `MARIADB_PASSWORD` from `.env`.
- `'auth'` (and `'azure'`, `'session_secret'` if used): copy the values from the **current** `config.php` on Hostinger
  (hPanel → File Manager → the dashboard's folder → `config.php` → open). Same values = same logins, same
  authenticator app for the admin.

```bash
chmod 600 .env config.php
```

## 3. Copy the data from Hostinger

Do this right before step 6 and don't upload new Posist files in between (anything uploaded to the old site after
the export would not be copied).

1. hPanel → **Databases → phpMyAdmin** → open `u878470360_kck_dashboard` → **Export** → Quick, SQL → **Go**.
   A file like `u878470360_kck_dashboard.sql` downloads.
2. Copy it to the VPS (from the PC, in PowerShell; or upload it with WinSCP):
   ```
   scp "$HOME\Downloads\u878470360_kck_dashboard.sql" root@187.127.181.40:/opt/kck-dashboard/kck_dump.sql
   ```
3. On the VPS:
   ```bash
   cd /opt/kck-dashboard/deploy/vps
   docker compose up -d kck-db
   sleep 20
   docker compose exec -T kck-db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < /opt/kck-dashboard/kck_dump.sql
   docker compose exec -T kck-db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT COUNT(*) AS bills FROM bills"'
   rm /opt/kck-dashboard/kck_dump.sql
   ```
   The count should match the number of bills on the old dashboard.

## 4. Start the dashboard

```bash
docker network ls | grep tools_default      # the Workspace's network must be listed
cd /opt/kck-dashboard/deploy/vps
docker compose up -d --build
docker compose ps                            # kck-web and kck-db: "running"
docker compose logs --tail 30 kck-web
# reachable from the Workspace's Caddy?  (prints the start of the sign-in page)
docker exec $(docker ps -qf name=caddy) wget -qO- http://kck-web/ | head -5
```

## 5. Point the Workspace's Caddy at it (GSV Workspace project, `D:\GitHub\tools`)

Add this block at the end of the Workspace's `Caddyfile` (replace the domain if the dashboard's address is not
`kck.gsvassociates.com`), commit and push as usual. The Workspace's `deploy/update.sh` checks the Caddyfile before
restarting Caddy, so a mistake never takes the Workspace down.

```
# KCK Sales Dashboard (separate project: /opt/kck-dashboard, containers kck-web / kck-db)
kck.gsvassociates.com {
	encode zstd gzip
	request_body {
		max_size 512MB
	}
	header {
		Strict-Transport-Security "max-age=31536000"
		-Server
	}
	reverse_proxy kck-web:80 {
		transport http {
			read_timeout 10m
			write_timeout 10m
		}
	}
}
```

## 6. Switch the address (Hostinger → Domains → DNS)

1. A day before: edit the `kck` **A** record's TTL from 14400 to **300** (the switch then takes 5 minutes, not 4 hours).
2. Switch: edit the `kck` A record → points to **187.127.181.40** (was 145.79.58.176).
3. After a few minutes open https://kck.gsvassociates.com — Caddy gets its HTTPS certificate on the first visit.
   Sign in, check the figures and an upload.
4. Keep the Hostinger site untouched for a week as a fallback (switching back = the A record back to
   145.79.58.176). Then turn off its Git auto-deploy in hPanel and, if wanted, the hosting.

## 7. Automatic updates on every push

1. On the VPS, a key for GitHub Actions:
   ```bash
   ssh-keygen -t ed25519 -f /root/.ssh/gh_kck_deploy -N "" -C "github-actions-kck"
   cat /root/.ssh/gh_kck_deploy.pub >> /root/.ssh/authorized_keys
   cat /root/.ssh/gh_kck_deploy          # the private key: copy ALL lines, including BEGIN / END
   ```
2. GitHub → `interngsv-oss/kck-gsv-dashboard` → **Settings → Secrets and variables → Actions → New repository secret**:
   `VPS_HOST` = `187.127.181.40`, `VPS_SSH_KEY` = the private key copied above.
3. From now on every push to `main` runs `.github/workflows/deploy-vps.yml` → `deploy/vps/update.sh` on the VPS.

## Everyday

- Logs: `cd /opt/kck-dashboard/deploy/vps && docker compose logs --tail 50 kck-web`
- Restart: `docker compose restart kck-web`
- Database backup: `docker compose exec -T kck-db sh -c 'mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' > /root/kck_backup_$(date +%F).sql`
