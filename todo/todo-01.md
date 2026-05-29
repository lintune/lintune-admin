# TODO 01 — Fix HEADSCALE_API_KEY not persisting between install retries

## Status
In progress — workaround applied, proper fix pending

## Workaround (applied 2026-05-29)
Added `HEADSCALE_API_KEY` and `HEADSCALE_URL` manually to `/opt/lintune/admin.env` and written into the running container's `.env` inode directly. This bypasses all three bugs temporarily.

**Important:** when editing admin.env, always use `cat tmp > file` (write into existing inode), never `mv tmp file` — `mv` replaces the inode and breaks the Docker bind mount. The container will silently keep reading the old file even though the host path looks updated.

## Symptom
During the headscale install stage the log repeatedly shows:

```
→ Configuring VPN mesh (Headscale)...
  HEADSCALE_API_KEY missing from .env — generating now...

--- Retrying VPN Mesh ---

→ Configuring VPN mesh (Headscale)...
  HEADSCALE_API_KEY missing from .env — generating now...
```

Even after a "successful" generation the key keeps disappearing, and after a retry that wipes headscale the saved key silently becomes invalid.

---

## Root cause — three separate bugs

### Bug 1 — `generateHeadscaleApiKey()` appends instead of replacing (`SshInstaller.php:682`)

```bash
printf '\nHEADSCALE_API_KEY=%s\nHEADSCALE_URL=%s\n' "$KEY" "..." >> /opt/lintune/admin.env
```

`>>` always **appends** a new `HEADSCALE_API_KEY=` line. After multiple retries the file contains many copies. `preg_match()` in `runHeadscaleStage()` finds the **first** match — which is the oldest, potentially from a wiped headscale instance, and therefore invalid.

### Bug 2 — `runHeadscaleStage()` ignores the Settings DB and reads only the `.env` file (`InstallController.php:578–604`)

`Setting::set('headscale.api_key', $apiKey, true)` is called on successful generation, so the key IS saved to the DB. But on every entry to `runHeadscaleStage()` the code reads from `file_get_contents(base_path('.env'))` instead of `Setting::get('headscale.api_key')`. If the `.env` file gets regenerated without the key line (e.g., fresh admin.env template), the DB value is silently ignored and a new key is generated.

### Bug 3 — Retry cleans headscale but leaves the stale key in `.env`

When the user retries with `?retry=1`, the headscale container is torn down and recreated. All previously issued API keys in headscale's internal store are gone. But `admin.env` still contains the old `HEADSCALE_API_KEY=hskey-api-...`. The code finds it, skips generation, then tries to use it — headscale rejects it as invalid. This is the "gets fixed and then doesn't work anymore" pattern.

### Bug 4 — `HEADSCALE_URL` stays empty when the API key is already present (`InstallController.php:580–597`)

The fallback that constructs `https://vpn.{BASE_DOMAIN}` only runs inside the `if (!$apiKey)` block:

```php
if (!$apiKey) {
    $hsUrl = 'https://vpn.' . env('BASE_DOMAIN', '');
    $apiKey = $ssh->generateHeadscaleApiKey($hsUrl);
    // ... $headscaleUrl = trim($urlMatch[1] ?? $hsUrl);  ← only set here
}
```

If the API key IS found in `.env` but `HEADSCALE_URL` is not, `$headscaleUrl` remains `""`. This empty string is then saved to `Setting::set('headscale.url', '')` and passed to `joinHeadscaleNetwork("")`, causing:

```
backend error: invalid key: unable to validate API key
```

Tailscale can't validate the pre-auth key because it's connecting to an empty/wrong login server URL.

**Confirmed:** headscale v0.28.0, API and pre-auth key creation both work correctly. The join itself fails only because the URL is empty.

### Bug 5 — Docker bind mount breaks silently when admin.env is replaced with `mv`

When anything writes to admin.env using `mv tmp admin.env` (creates a new inode), the Docker bind mount still points to the old (now unlinked) inode. The container reads stale `.env` content even though the host file looks correct. Symptoms: `Links: 0` in `stat` output, container .env and host admin.env have different inode numbers.

Always use `cat tmp > /opt/lintune/admin.env` to write into the existing inode.

---

## Fix

### 1. Replace `>>` append with PHP-side `writeEnv()` in `runHeadscaleStage()`

After `generateHeadscaleApiKey()` captures the key via `CAPTURE:`, call `writeEnv()` in PHP instead of appending in bash:

```php
// In runHeadscaleStage(), after $apiKey = $ssh->generateHeadscaleApiKey($hsUrl):
if ($apiKey) {
    $this->writeEnv(['HEADSCALE_API_KEY' => $apiKey, 'HEADSCALE_URL' => $hsUrl]);
    $headscaleUrl = $hsUrl;
}
```

Remove the `printf '...' >> /opt/lintune/admin.env` line from the bash script in `generateHeadscaleApiKey()` — just emit `CAPTURE:headscale_api_key:$KEY` and let PHP handle the write.

`writeEnv()` already does replace-or-append correctly (`InstallController.php:1044`).

### 2. Check `Setting::get()` first, fall back to `.env`

```php
// Top of runHeadscaleStage():
$apiKey       = Setting::get('headscale.api_key') ?: '';
$headscaleUrl = Setting::get('headscale.url') ?: '';

// Only read .env as fallback if Settings is empty
if (!$apiKey) {
    $envContent = file_get_contents(base_path('.env'));
    preg_match('/^HEADSCALE_API_KEY=(.+)$/m', $envContent, $keyMatch);
    preg_match('/^HEADSCALE_URL=(.+)$/m',     $envContent, $urlMatch);
    $apiKey       = trim($keyMatch[1] ?? '');
    $headscaleUrl = trim($urlMatch[1] ?? '');
}
```

### 3. Clear stale key on retry

When `?retry=1` is in the stream request for the headscale stage, clear the stored key before regenerating so the stale `.env` value can't be picked up:

```php
// In stream() before calling runHeadscaleStage() when retry:
if ($request->boolean('retry') && $stage === 'headscale') {
    Setting::where('key', 'headscale.api_key')->delete();
    $this->writeEnv(['HEADSCALE_API_KEY' => '', 'HEADSCALE_URL' => '']);
}
```

Or simpler — in `runHeadscaleStage()`, always validate the found key against the live headscale instance before trusting it, and regenerate if invalid.

---

## Files to change

| File | Change |
|---|---|
| `app/Http/Controllers/InstallController.php` | `runHeadscaleStage()`: check Settings first, clear on retry |
| `app/Services/SshInstaller.php` | `generateHeadscaleApiKey()`: remove `>>` append, only emit CAPTURE |

---

## Testing
1. Run headscale install stage fresh → key generated, saved to Settings and .env via `writeEnv()`
2. Hit Retry → old key cleared, new key generated from fresh headscale instance
3. Container restart → key still in `.env` (written properly, not appended) AND in Settings
4. Multiple retries → only one `HEADSCALE_API_KEY=` line in admin.env at all times
