# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | `CookieAssignmentStore` + `StickyAssignmentResolver` keeping a variant across two requests | No |

The script simulates two requests with `nyholm/psr7`: the first assigns and stores
a variant in a signed cookie, the second reads the cookie back and serves the same
variant — demonstrating stickiness without a running server.

## Running

```bash
# From package root, after composer install
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
```
