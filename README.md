<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
SPDX-License-Identifier: MIT
-->

# koh/sylius-poppy

The [Personal Agent Protocol](https://personalagentprotocol.org/docs/0.2/spec)
(Poppy, draft 0.2) for a Sylius shop. A customer's own AI agent finds the shop
through `/.well-known/poppy.json`, starts a Session, browses and uses the Shop
API in it, and — when a task needs the customer's account — asks the customer
to sign in and approve on the shop's own page.

Built on [koh/poppy](https://github.com/ohartwig/poppy), which does the
protocol; this bundle provides the Sylius side: routes, the customer pages,
the security integration, storage in the shop database, and an admin list of
agent feedback. Part of
[koh/sylius-agent-ready](https://github.com/ohartwig/sylius-agent-ready).

## What a shop gets

| Poppy 0.2 | Here |
|---|---|
| Discovery (3) | `/.well-known/poppy.json`, issuer metadata at `/.well-known/oauth-authorization-server/poppy` with `poppy_domains` |
| Sessions (4.2) | `POST /poppy/token`, signed-out Sessions for any Personal Agent |
| Direct Sign-In (4.5) | `/poppy/authorize` — the shop's login, then a consent page |
| Device Sign-In (4.6) | `/poppy/device` — the customer enters the agent's code |
| Sign-out (4.9) | `POST /poppy/revoke`, and *Connected agents* in the customer account |
| Web browsing (5) | `POST /poppy/browser-session`; the agent's browser is signed in and out with its Session |
| APIs (6) | DPoP Session Tokens on the Shop API (`/api/v2/shop`), opt-in |
| Feedback (8) | `POST /poppy/feedback`, listed in the admin under *Agent feedback* |

Mediated Sign-In (the agent sends the customer's password) and conversations
with a Company Agent are not offered, and therefore not declared.

**Each channel is its own Company by default**: issuer
`https://{channel hostname}/poppy`, `poppy_domains` that one host. Channels
that share one customer base can be one Company — set `issuer` and `domains`.

**What a Personal Agent can do, and as whom.** Signed out, it is a guest: it
browses, fills a guest cart and checks out like any visitor. Signed in, it is
the customer, within what the customer approved: `poppy:read` to see orders,
addresses and account data, `poppy:write` to order and change things. The
customer sees every connected agent in their account and can disconnect it;
its access ends on the next request.

## Install

```bash
composer require koh/sylius-poppy
bin/console doctrine:migrations:migrate
```

`config/bundles.php`:

```php
Koh\SyliusPoppy\KohSyliusPoppyBundle::class => ['all' => true],
```

`config/routes/koh_agent.yaml` (before `api_platform.yaml`):

```yaml
koh_poppy:
    resource: '@KohSyliusPoppyBundle/config/routes.php'
```

## Security configuration — installing is not enough

Three changes in `config/packages/security.yaml`. Without them the endpoints
answer, but start sessions and set cookies (the first) or do not see
Personal Agents at all (the other two).

**1. The machine endpoints keep no session.** Above the Sylius firewalls:

```yaml
security:
    firewalls:
        koh_poppy_endpoints:
            pattern: '^/poppy/(token|revoke|device_authorization|feedback|browser-session)$'
            security: false
```

`/.well-known/poppy.json` is already covered by the agent-ready firewall
(`^/(\.well-known/(?!oauth)…`). The issuer metadata under
`/.well-known/oauth-authorization-server/poppy` is public through
`access_control` like the MCP OAuth metadata.

**2. The agent's browser, on the shop firewall:**

```yaml
        shop:
            # … the existing configuration
            custom_authenticators:
                - Koh\SyliusPoppy\Security\BrowserSessionAuthenticator
```

**3. Session Tokens on the Shop API**, if `shop_api.enabled` is on:

```yaml
        api_shop:
            # … the existing configuration
            custom_authenticators:
                - Koh\SyliusPoppy\Security\ShopApiAuthenticator
```

The JWT login keeps the `Bearer` scheme; this authenticator only looks at
`DPoP`.

## Purge

Expired Sessions, tokens and one-time ids are removed by

```bash
bin/console koh:poppy:purge
```

Run it daily. Nothing depends on it for correctness; without it the tables
only grow.

## Configuration

```yaml
# config/packages/koh_poppy.yaml — the defaults
koh_poppy:
    enabled: true             # serve poppy.json at all
    server: true              # false: declare the website alone (web: {})
    organization_name: ~      # default: the channel name
    issuer: ~                 # default: https://{channel hostname}/poppy, one per channel
    domains: []               # with a fixed issuer: every domain that names it
    sign_in:
        direct: ['poppy:read', 'poppy:write']   # [] turns Direct Sign-In off
        device: ['poppy:read']                  # [] turns Device Sign-In off
    browser_session: true
    feedback: true
    shop_api:
        enabled: false
        prefix: /api/v2/shop
        openapi_path: /api/v2/docs.jsonopenapi
    session_token_ttl: 3600
    session_lifetime: 2592000
    account_token_ttl: 7776000  # 0: until the customer disconnects
    clients:
        allow: []             # non-empty: only these agents (client_id URL or host)
        block: []
    dpop_nonces: false
    rate_limits:              # per client_id; device_page per client address
        token: { limit: 60, interval: '1 minute' }
        revoke: { limit: 30, interval: '1 minute' }
        device: { limit: 10, interval: '1 minute' }
        feedback: { limit: 20, interval: '1 hour' }
        device_page: { limit: 10, interval: '10 minutes' }
    table_prefix: koh_poppy_
```

Never change an issuer that agents already use: their User IDs and Account
Tokens are bound to it.

## Templates and translations

The pages extend `@SyliusShop/shared/layout/base.html.twig` through
`@KohSyliusPoppy/shop/_layout.html.twig`; override that one file at
`templates/bundles/KohSyliusPoppyBundle/shop/_layout.html.twig` to match a
theme. Translations ship in German and English under `koh_poppy.*`.

## Security notes

- Consent pages cannot be framed and are never cached; every decision is a
  POST with a CSRF token. Opening a device link approves nothing.
- The agent's name and logo on the consent page are the agent's own claims;
  the page always shows the host its metadata is published on next to them.
- The authorization request is kept server-side while the customer signs in.
  The redirect URI never travels through the customer's browser.
- Agent metadata is fetched over HTTPS only, through Symfony's
  `NoPrivateNetworkHttpClient`, without redirects and with short timeouts.
- The browser cookie carries the Session id with an HMAC under the kernel
  secret, is `Secure`, `HttpOnly`, `SameSite=Lax`, and expires with the
  Session.

## Licence

MIT. See [LICENSE](LICENSE).
