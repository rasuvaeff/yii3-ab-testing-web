# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | Ready `StickyAssignmentMiddleware` keeping a variant across two requests | No |
| `spa-endpoints.php` | The three endpoints a single-page application needs, and why a signed receipt beats re-resolving | No |

The script simulates two requests with `nyholm/psr7`: the first assigns and stores
a variant in a bounded signed cookie, the second reads the cookie back through
the ready middleware and serves the same variant — no copied middleware glue.

`spa-endpoints.php` covers the one thing a SPA changes: the server no longer
knows whether the visitor saw the variant, only the client does. So the
assignments endpoint must not track exposure — that would count prefetches,
routes never reached and repeat navigations — and exposure becomes a call the
client makes on render. The script also shows a client editing its own receipt
and being rejected.

## Running

```bash
# From package root, after composer install
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/spa-endpoints.php
```
