# Custom Actions

Place your custom post-submit action scripts here.

Each file should be named `{action_type}.php` and will receive
these variables when executed:

- `$action` — the action config from form JSON
- `$submission` — full submission data (`id`, `form`, `data`, `meta`)
- `$config` — BareBonesForms config

## Important

Actions are **server-side trusted extensions**. They run as PHP
`include` with full access to the server environment. They are
**not sandboxed**.

Only place code you trust in this directory. Actions are meant
for the developer/server admin, not for end users.
