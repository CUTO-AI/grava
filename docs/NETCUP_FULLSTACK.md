# Workbook: GravelExplorer Full-Stack auf netcup RS (Debian 12) — sicher

Ziel: **eine** Maschine (netcup RS 12000 G12, Standort NUE) betreibt alles:
PHP-App (grava.world) + MySQL + Valhalla EU/US. Kein Cloudflare-Tunnel mehr,
echtes Cron, voller Zugriff. **Sicherheit hat Vorrang** (kein Server-Takeover).

> Für Einsteiger geschrieben: jeden Block **einzeln** ausführen und die Ausgabe
> prüfen, bevor du weitergehst. Platzhalter in `<spitzen Klammern>` ersetzen.
> Geheimnisse (Passwörter/Tokens) werden auf dem Server erzeugt — **nie** in Git.

Legende: 🟢 = ungefährlich · 🟡 = aufpassen · 🔴 = kann aussperren/Daten ändern.

---

## Warum das sicher ist (Kurzfassung gegen Takeover)
Die häufigsten Übernahme-Ursachen und wie wir sie schließen:
1. **SSH mit Passwort / root-Login** → wir erlauben **nur SSH-Key**, **kein** root-Login, **kein** Passwort-Login.
2. **Offene Dienste** (MySQL 3306, Valhalla 8002) → **Firewall** lässt nur 22/80/443; DB & Valhalla lauschen **nur auf localhost**.
3. **Veraltete Pakete** → **automatische Sicherheitsupdates**.
4. **Brute-Force** → **fail2ban** + Key-only macht Rateraten wirkungslos.
5. **Geheimnisse im Klartext/Git** → `.env` mit Rechten `600`, Secrets per `openssl rand`.
6. **Kein Backup** → tägliches DB-Backup + Offsite.

---

## Phase 0 — Voraussetzungen & Überblick
- Zugänge: netcup **SCP** (Server Control Panel), dort Server-IPv4/IPv6 + Rescue/VNC-Konsole.
- DNS für `grava.world`: bei **united-domains** (nicht netcup). Records ändern wir erst am Ende (Cutover).
- Du brauchst auf deinem Mac ein Terminal. Ich (Claude) kann per SSH mitfahren, sobald mein Key drauf ist (Phase 1).
- **Wir fassen die alte Shared-Hosting-Seite nicht an, bis der neue Server steht** — erst testen, dann DNS umlegen. Rückweg bleibt jederzeit möglich.

Merke dir zwei Werte für später:
- `SERVER_IP` = deine netcup-IPv4
- `DEIN_USER` = dein künftiger Login-Name (z. B. `armin`)

---

## Phase 1 — Erstzugang & Admin-Benutzer
netcup liefert dir ein **root-Passwort** (SCP). Erstlogin damit, dann sofort einen
normalen Benutzer mit sudo anlegen und auf SSH-Key umstellen.

**1a) SSH-Key erzeugen (auf deinem Mac, einmalig) 🟢**
```bash
ssh-keygen -t ed25519 -C "grava-netcup" -f ~/.ssh/grava_netcup
# erzeugt ~/.ssh/grava_netcup (privat) + ~/.ssh/grava_netcup.pub (öffentlich)
cat ~/.ssh/grava_netcup.pub    # DAS ist der öffentliche Key (kommt auf den Server)
```

**1b) Erstlogin als root 🟡**
```bash
ssh root@<SERVER_IP>       # root-Passwort aus netcup SCP
```

**1c) Admin-Benutzer anlegen + Key hinterlegen (auf dem Server, als root) 🟡**
```bash
adduser <DEIN_USER>                 # Passwort vergeben (Notfall-Sudo)
usermod -aG sudo <DEIN_USER>
mkdir -p /home/<DEIN_USER>/.ssh && chmod 700 /home/<DEIN_USER>/.ssh
# Öffentlichen Key eintragen (Inhalt von grava_netcup.pub einfügen):
echo "ssh-ed25519 AAAA... grava-netcup" > /home/<DEIN_USER>/.ssh/authorized_keys
chmod 600 /home/<DEIN_USER>/.ssh/authorized_keys
chown -R <DEIN_USER>:<DEIN_USER> /home/<DEIN_USER>/.ssh
```

**1d) NEUES Terminal — Key-Login testen (NICHT das root-Fenster schließen!) 🔴**
```bash
ssh -i ~/.ssh/grava_netcup <DEIN_USER>@<SERVER_IP>
sudo whoami       # muss "root" ausgeben → sudo funktioniert
```
> Erst weiter, wenn der Key-Login klappt. Sonst sperrst du dich in Phase 2 aus.

---

## Phase 2 — SSH härten (Key-only, kein root) 🔴
Als `<DEIN_USER>` auf dem Server:
```bash
sudo tee /etc/ssh/sshd_config.d/99-grava.conf >/dev/null <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
X11Forwarding no
MaxAuthTries 3
AllowUsers DEIN_USER
EOF
sudo sed -i 's/^AllowUsers DEIN_USER/AllowUsers <DEIN_USER>/' /etc/ssh/sshd_config.d/99-grava.conf
sudo sshd -t && sudo systemctl restart ssh    # -t prüft die Config VOR dem Neustart
```
Test in einem **neuen** Fenster: `ssh -i ~/.ssh/grava_netcup <DEIN_USER>@<SERVER_IP>` muss gehen; `ssh root@<SERVER_IP>` muss **abgelehnt** werden.

---

## Phase 3 — Firewall, Auto-Updates, fail2ban 🟡
```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install ufw fail2ban unattended-upgrades

# Firewall: alles rein verbieten, nur SSH/HTTP/HTTPS erlauben
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status verbose

# Automatische Sicherheitsupdates
sudo dpkg-reconfigure -plow unattended-upgrades   # "Ja"

# fail2ban für SSH
sudo tee /etc/fail2ban/jail.d/sshd.local >/dev/null <<'EOF'
[sshd]
enabled = true
maxretry = 4
bantime = 1h
findtime = 10m
EOF
sudo systemctl enable --now fail2ban
sudo fail2ban-client status sshd
```
> Wichtig: **8002/8003 (Valhalla) und 3306 (MySQL) werden NIE per ufw geöffnet** — sie lauschen nur auf localhost.

---

## Phase 4 — Software-Stack installieren 🟢
```bash
sudo apt -y install \
  docker.io docker-compose-plugin git rsync curl unzip \
  php8.2-fpm php8.2-mysql php8.2-curl php8.2-mbstring php8.2-xml \
  php8.2-zip php8.2-gd php8.2-bcmath php8.2-intl \
  mariadb-server osmium-tool jq
sudo systemctl enable --now docker php8.2-fpm

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Caddy (Reverse-Proxy mit automatischem HTTPS)
sudo apt -y install debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt -y install caddy
```
> Hinweis: `mariadb-server` ist MySQL-kompatibel und unter Debian der Standard. Falls du echtes Oracle-MySQL 8 willst, sag Bescheid — der Rest bleibt gleich.

---

## Phase 5 — Datenbank absichern & anlegen 🟡
```bash
sudo mysql_secure_installation      # root-Passwort setzen, anonyme User weg, remote-root weg, Test-DB weg

# DB + App-User mit Minimalrechten anlegen (Passwort erzeugen lassen):
DBPASS=$(openssl rand -base64 24)
echo "APP-DB-PASSWORT (notieren, kommt in .env): $DBPASS"
sudo mysql <<SQL
CREATE DATABASE gravelexplorer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'grava'@'127.0.0.1' IDENTIFIED BY '${DBPASS}';
GRANT ALL PRIVILEGES ON gravelexplorer.* TO 'grava'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# MySQL nur auf localhost lauschen lassen (Standard bei MariaDB, prüfen):
sudo sed -i 's/^bind-address.*/bind-address = 127.0.0.1/' /etc/mysql/mariadb.conf.d/50-server.cnf
sudo systemctl restart mariadb
sudo ss -tlnp | grep 3306      # muss 127.0.0.1:3306 zeigen, NICHT 0.0.0.0
```

---

## Phase 6 — Bestehende Datenbank migrieren 🟡
Auf dem **alten** Hosting (United Domains) einen Dump ziehen (phpMyAdmin-Export
oder mysqldump, falls Zugang) → Datei `grava_dump.sql`. Dann auf den Server:
```bash
# vom Mac:
rsync -avP grava_dump.sql <DEIN_USER>@<SERVER_IP>:/home/<DEIN_USER>/
# auf dem Server:
mysql -u grava -p gravelexplorer < /home/<DEIN_USER>/grava_dump.sql
mysql -u grava -p gravelexplorer -e "SHOW TABLES; SELECT COUNT(*) FROM game_region;"
```
> Alternativ die aktuelle DB aus deiner lokalen Dev-Instanz spiegeln — je nachdem, was der „Master" sein soll. Klären wir vor dem Schritt.

---

## Phase 7 — App deployen 🟡
```bash
sudo mkdir -p /var/www && sudo chown <DEIN_USER>:<DEIN_USER> /var/www
cd /var/www
git clone https://github.com/Convanic/grava.git app
cd app
composer install --no-dev --optimize-autoloader

# .env aus Vorlage, dann Werte setzen (localhost!):
cp .env.example .env 2>/dev/null || true
nano .env
```
In der `.env` (Auszug):
```dotenv
DB_HOST=127.0.0.1
DB_NAME=gravelexplorer
DB_USER=grava
DB_PASS=<DBPASS aus Phase 5>
APP_URL=https://grava.world
INTERNAL_TOKEN=<openssl rand -hex 24>
# Valhalla lokal — KEIN Tunnel mehr:
VALHALLA_URL_EU=http://127.0.0.1:8002
VALHALLA_URL_US=http://127.0.0.1:8003
# ... übrige Keys (APNs, Strava, Mail) aus der alten .env übernehmen
```
Rechte hart setzen:
```bash
chmod 600 .env
# Schreibbare Verzeichnisse dem PHP-FPM-User (www-data) geben:
sudo chown -R <DEIN_USER>:www-data storage public/uploads 2>/dev/null || true
sudo find storage -type d -exec chmod 2775 {} \; 2>/dev/null || true
```

---

## Phase 8 — Valhalla EU/US (nur localhost) 🟡
Tiles hast du lokal schon gebaut → rüberkopieren (spart Stunden):
```bash
# vom Mac:
ssh <DEIN_USER>@<SERVER_IP> 'mkdir -p /var/www/valhalla/eu /var/www/valhalla/us'
rsync -avP ~/Sites/gravelexplorer/docker/valhalla/custom_files/valhalla_tiles.tar    <DEIN_USER>@<SERVER_IP>:/var/www/valhalla/eu/
rsync -avP ~/Sites/gravelexplorer/docker/valhalla/custom_files_us/valhalla_tiles.tar <DEIN_USER>@<SERVER_IP>:/var/www/valhalla/us/
```
Compose (liefert I dir als `docker-compose.prod-dual.yml` fertig) startet beide
Container **nur auf 127.0.0.1** gebunden:
```yaml
    ports: ["127.0.0.1:8002:8002"]   # EU  (US: 127.0.0.1:8003:8002)
```
```bash
cd /var/www/app/docker/valhalla
docker compose -f docker-compose.prod-dual.yml up -d
curl -s http://127.0.0.1:8002/status && curl -s http://127.0.0.1:8003/status
```

---

## Phase 9 — Caddy + TLS + DNS-Cutover 🔴
`/etc/caddy/Caddyfile`:
```
grava.world, www.grava.world {
    root * /var/www/app/public
    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
    encode gzip
    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        Referrer-Policy "strict-origin-when-cross-origin"
    }
}
```
```bash
sudo systemctl restart caddy
```
**DNS umlegen** (united-domains): `A grava.world → <SERVER_IP>` (+ AAAA). Sobald
propagiert (`dig +short grava.world`), holt Caddy automatisch das TLS-Zertifikat.
> Erst testen, indem du in `/etc/hosts` auf deinem Mac `<SERVER_IP> grava.world`
> setzt und die Seite prüfst — dann erst den echten DNS-Record ändern.

---

## Phase 10 — Cron-Jobs & Backups 🟢
Echtes Cron (das, was United Domains nicht konnte):
```bash
crontab -e
```
```cron
*/10 * * * * php /var/www/app/public/index.php game:notify-dispatch   >/dev/null 2>&1
15   3 * * * php /var/www/app/public/index.php game:snapshot-daily     >/dev/null 2>&1
30   3 * * * php /var/www/app/public/index.php regions:ownership-refresh>/dev/null 2>&1
*/15 * * * * php /var/www/app/public/index.php regions:backfill         >/dev/null 2>&1
0    4 * * * /var/www/app/scripts/backup_db.sh                          >/dev/null 2>&1
```
Backup-Script (`scripts/backup_db.sh`, lege ich dir an): täglicher `mysqldump`,
7–14 Tage Rotation, **zusätzlich offsite** (z. B. netcup Storage-Box/S3 — sonst ist
das Backup bei einem Server-Verlust auch weg).

---

## Phase 11 — Abschluss-Sicherheitscheck ✅
```bash
sudo ufw status verbose                 # nur 22/80/443
sudo ss -tlnp                           # 3306 & 8002/8003 NUR auf 127.0.0.1
ssh root@<SERVER_IP>                     # muss ABGELEHNT werden
sudo fail2ban-client status sshd
curl -sS https://grava.world/healthz?check=valhalla | jq   # eu+us reachable
```
Zusätzlich: netcup-Konto mit **2FA** sichern; SCP-Rescue-Passwort sicher ablegen;
`unattended-upgrades` läuft; regelmäßig `sudo apt update && sudo apt upgrade`.

---

## Rollback / Notfall
- Solange der DNS-Record noch aufs alte Hosting zeigt, ist die alte Seite live —
  neuer Server wird per `/etc/hosts` getestet, ohne Nutzer zu betreffen.
- Aussperren verhindert: root-Fenster offen lassen, bis Key-Login sicher steht.
- DB-Restore: `mysql … < letztes_backup.sql`.
