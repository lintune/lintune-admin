# TODO 03 — Playwright end-to-end test suite for lintune-admin

## Status
In progress — infrastructure done, tests need fixing

## Next session: make the smoke tests pass

Playwright is installed at `lintune-project/` (not inside lintune-admin — it's not a git repo there, that's fine).
Tests are at `lintune-project/tests/e2e/`. Run from `lintune-project/`:

```
npx playwright test --headed        # watch it run
npx playwright test --ui            # interactive explorer
```

**What's broken and needs fixing:**

1. **Run the tests first** against `https://admin.dev.lintune.xyz` and read the actual failures — selectors or page text may not match what the views really render. Use `--headed` to see what's happening.

2. **Installer tests** assume `SETUP_COMPLETE` is NOT set (pre-wizard state). If the dev container already has setup complete, `/install` redirects away. Either:
   - Run installer tests against a fresh/reset container, or
   - Skip them and focus on the post-setup auth + admin tests first

3. **Auth tests** assume `SETUP_COMPLETE` IS set. Confirm the dev container is in post-setup state before running `auth.spec.ts`.

4. **Fix any wrong selectors** — tests were written by reading the Blade templates, not by running against the live site. Text labels, field names, or URL patterns may be slightly off. The `--headed` run will make it obvious.

5. **The configure form submit test** (`submitting form navigates to progress page`) will actually trigger a real install run against `__local__` — either mock it or point it at a throwaway target.

## Why

The installer and admin panel have no automated tests. Playwright is the right tool because:
- The installer is a multi-step web wizard (SSE streams, form submits, redirects) — hard to unit-test, perfect for E2E
- The Filament admin panel has interactive pages (realm table, edit forms, danger zone) that need regression coverage
- Playwright can mock the SSH layer / external APIs so tests don't need a real target server

## Scope

**Phase 1 — Setup + smoke tests (installer flow):**
- Install wizard: welcome → server-type → configure → progress stream → done
- Login page renders and rejects bad credentials
- Post-login: Realms page loads, Create Realm form renders

**Phase 2 — Admin panel CRUD:**
- Realm create/edit/delete (with KC mock)
- Settings page saves and reloads correctly
- Audit log shows entries
- Service Status page loads and renders Kuma cards

**Out of scope:** Real SSH installs, real Keycloak calls — these stay as manual integration tests.

## Where to install

Playwright should live in a new `tests/` directory at `lintune-admin/tests/` (alongside the existing PHPUnit tests in `tests/`). Use Node/Playwright directly (not a Laravel-specific wrapper) so it can test the actual rendered HTML.

```
lintune-admin/
  tests/
    e2e/               ← Playwright tests
      installer.spec.ts
      login.spec.ts
      realms.spec.ts
    playwright.config.ts
  package.json         ← new, for Playwright dev dependency
```

## Setup steps

1. `npm init -y` in `lintune-admin/`
2. `npm install --save-dev @playwright/test`
3. `npx playwright install chromium` (chromium only is enough for CI)
4. Create `playwright.config.ts` pointing at `http://localhost:8889` (dev container)
5. Write the Phase 1 smoke tests
6. Add a `test:e2e` script to `package.json`
7. Document in `CLAUDE.md` under a new "Testing" section

## Notes

- Dev runs at `https://admin.dev.lintune.xyz` — Playwright config points there
- SSE streams can be tested with `page.waitForEvent('request')` + response interception
- Use `page.route()` to mock Kuma API calls so Service Status tests don't need a live Kuma
