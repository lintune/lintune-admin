## Keycloak Proxy Configuration

Keycloak must be explicitly told it is behind a proxy and what its public hostname is to ensure it generates correct redirect URLs and trusts the incoming traffic headers. Update your `keycloak.conf` file:

- **`proxy-headers`**: Set to `xforwarded` so Keycloak respects the `X-Forwarded-*` headers from Cloudflare.
- **`hostname`**: Set this to your public domain (e.g., `keycloak.yourdomain.com`).
- **`http-enabled`**: Set to `true`. This allows the local cloudflared agent to talk to Keycloak over unencrypted HTTP, while Cloudflare handles the public HTTPS encryption.

Example:

```properties
proxy-headers=xforwarded
hostname=keycloak.yourdomain.com
http-enabled=true
```