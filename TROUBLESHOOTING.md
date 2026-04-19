# Troubleshooting Guide

## Error: `Error running remote compact task: stream disconnected before completion`

This error is from the remote context-compaction request, not from your website code.
It usually means the long-lived HTTPS stream to:

`https://chatgpt.com/backend-api/codex/responses/compact`

was interrupted by network/proxy/timeout.

### Quick fix checklist

1. Turn off global proxy / VPN first, then retry once.
2. If you must use a proxy, switch to a stable node and avoid frequent node hopping.
3. Use a clean network (mobile hotspot is a good A/B test).
4. Retry after 1-2 minutes (transient upstream disconnects can self-recover).

### Local workflow fallback (recommended when unstable)

If remote compact keeps failing, continue development with local context files:

1. Keep your own running notes in `演示流程说明.md`.
2. Keep business/data assumptions in `展示数据清单.md`.
3. Continue coding directly without waiting for remote compact to succeed.

### Verify your website is still healthy

Run:

```powershell
php -l index.php
php -S 127.0.0.1:8000
```

Open:

`http://127.0.0.1:8000/index.php`

If this works, your project is fine. The compact error is only a tooling/network issue.

