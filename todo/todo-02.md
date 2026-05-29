# TODO 02 — Replace Caddy with Traefik for automatic Docker service discovery

## Status
Implemented (2026-05-29)

## Implemented

- `get.lintune.xyz/install.sh` — replaced `CADDY_IMAGE` + Caddyfile + caddy.env with `TRAEFIK_IMAGE` + traefik.env; renamed `USE_CADDY` → `USE_TRAEFIK`; replaced caddy service in docker-compose with `traefik:v3` service using Docker label discovery; added Traefik labels to every Lintune service (admin, dash, kuma, headscale, vault)
- `app/Services/SshInstaller.php` — added `$sharedServer` param to `installMailcow()`; `mailcowPortBlock()` injects `HTTP_PORT=8080 / HTTPS_PORT=8443 / SKIP_LETS_ENCRYPT=y` for shared installs; new `mailcowTraefikOverrideBlock()` writes `/opt/mailcow-dockerized/docker-compose.override.yml` (base64 via SSH) that joins `nginx-mailcow` to the `lintune_internal` external network and adds Traefik labels for automatic routing to internal port 80
- `app/Http/Controllers/InstallController.php` — removed `addCaddyReverseProxy()` (Caddy-specific, superseded by SSH-side label injection); updated comment on shared-server path

## Why

Traefik does Docker label routing natively — no Caddyfile editing, no `caddy-docker-proxy` plugin needed. Each container declares its own routing via labels, and Traefik picks it up automatically including TLS via Cloudflare DNS-01.

This eliminates:
- The static `Caddyfile` (replaced entirely by container labels)
- The `addCaddyReverseProxy()` hack that edits the Caddyfile from PHP during installs
- Manual Caddy reloads

## Scope

**In scope:** Replace Caddy for all Lintune-managed services (admin, dash, kuma, headscale, vaultwarden).

**Out of scope:** Multi-server Mailcow/Nextcloud installs — those run on their own hosts, Traefik only sees the local Docker socket. The existing Mailcow port-handling logic (single vs multi-server) stays as-is.

## How Traefik works

```yaml
# docker-compose.yml
services:
  traefik:
    image: traefik:v3
    command:
      - --providers.docker=true
      - --providers.docker.exposedbydefault=false
      - --entrypoints.web.address=:80
      - --entrypoints.websecure.address=:443
      - --certificatesresolvers.cloudflare.acme.dnschallenge=true
      - --certificatesresolvers.cloudflare.acme.dnschallenge.provider=cloudflare
    environment:
      - CF_API_TOKEN=${CF_API_TOKEN}
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - traefik_data:/data

  lintune-admin:
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.admin.rule=Host(`admin.${BASE_DOMAIN}`)"
      - "traefik.http.routers.admin.entrypoints=websecure"
      - "traefik.http.routers.admin.tls.certresolver=cloudflare"
      - "traefik.http.services.admin.loadbalancer.server.port=80"
```

## Migration steps

1. **Replace Caddy image** — swap `ghcr.io/lintune/caddy:latest` for `traefik:v3` in `docker-compose.yml`. Remove the custom Caddy image and its build pipeline (`lintune-project/caddy/`).

2. **Add labels to each service** in `docker-compose.yml`:
   - `lintune-admin` → `admin.${BASE_DOMAIN}`
   - `lintune-dash` → `dash.${BASE_DOMAIN}`
   - `uptime-kuma` → `isitup.${BASE_DOMAIN}` (port 3001)
   - `headscale` → `vpn.${BASE_DOMAIN}` (port 8080)
   - `vaultwarden` → `vault.${BASE_DOMAIN}` (port 80)

3. **Remove the Caddyfile** — no longer needed.

4. **Remove `addCaddyReverseProxy()`** from `InstallController` — no longer needed for single-server Mailcow since Traefik would pick up Mailcow's containers automatically via labels (if on the same host). Add labels to `mailcow-dockerized`'s compose file during install instead.

5. **Single-server Mailcow labels** — in `SshInstaller::installMailcow()`, when `$sharedServer = true`, append Traefik labels to the Mailcow compose override:
   ```yaml
   # /opt/mailcow-dockerized/docker-compose.override.yml
   services:
     nginx-mailcow:
       labels:
         - "traefik.enable=true"
         - "traefik.http.routers.mailcow.rule=Host(`{$hostname}`)"
         - "traefik.http.routers.mailcow.entrypoints=websecure"
         - "traefik.http.routers.mailcow.tls.certresolver=cloudflare"
         - "traefik.http.services.mailcow.loadbalancer.server.port=8443"
         - "traefik.http.services.mailcow.loadbalancer.server.scheme=https"
   ```
   With this, Mailcow still uses alternate ports (8080/8443 to avoid conflict with Traefik on 80/443) but Traefik discovers and routes it automatically — no Caddyfile editing needed.

6. **Update `caddy.env`** → rename to `traefik.env` with just `CF_API_TOKEN`.

7. **Update `docker-compose.yml`** — remove caddy volume binding, add Traefik volume for acme data.

8. **HTTP→HTTPS redirect** — Traefik handles this automatically with entrypoint middleware.

## Note on current Mailcow port behaviour

Single-server installs currently set `HTTP_PORT=8080`, `HTTPS_PORT=8443`, `SKIP_LETS_ENCRYPT=y` in Mailcow's conf (handled correctly in `SshInstaller::installMailcow()` via the `$sharedServer` flag). With Traefik, the port logic stays the same — Traefik still needs Mailcow on non-standard ports to avoid conflict on 80/443. `SKIP_LETS_ENCRYPT=y` also stays — Traefik handles TLS, not Mailcow.
