# Troubleshooting

## Setup: "Invalid Keycloak credentials" when accessing Keycloak over HTTP

**Symptom:** The setup page returns *Invalid Keycloak credentials* even though the same credentials work when logging into the Keycloak admin UI directly.

**Cause:** Keycloak's default SSL requirement is set to `external requests`, which blocks token requests coming from non-HTTPS origins — even for internal/local connections.

**Fix:** In Keycloak, go to the master realm → **Realm Settings** → **General** → set **Require SSL** to `none`.

> This should only be done as a temporary measure when Keycloak is accessed directly over HTTP. The recommended approach — even for dev — is to place Keycloak behind an HTTPS reverse proxy. This keeps dev and prod behaviour identical and avoids having to change Keycloak's SSL setting at all.

---

## Setup: "Request path not normalized" error from Keycloak

**Symptom:** Setup fails with a Keycloak error `missingNormalization: Request path not normalized`.

**Cause:** `KEYCLOAK_BASE_URL` has a trailing slash (e.g. `http://keycloak:8080/`), which produces double-slash paths like `//realms/master/...` that Keycloak rejects.

**Fix:** Remove the trailing slash from `KEYCLOAK_BASE_URL` in `.env`:

```
KEYCLOAK_BASE_URL=http://keycloak:8080
```
