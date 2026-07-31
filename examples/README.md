# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | Ready `StickyAssignmentMiddleware` keeping a variant across two requests | No |

The script simulates two requests with `nyholm/psr7`: the first assigns and stores
a variant in a bounded signed cookie, the second reads the cookie back through
the ready middleware and serves the same variant — no copied middleware glue.

## Running

```bash
# From package root, after composer install
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
```
