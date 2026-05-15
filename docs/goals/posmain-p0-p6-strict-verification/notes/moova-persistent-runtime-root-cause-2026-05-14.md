# Moova Persistent Runtime Root Cause - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T11:32:21Z

## Question

Is persistent Moova `/readyz` degraded because Redis itself is unavailable, or because the persistent LaunchAgent starts Moova with the wrong runtime environment?

## Evidence

LaunchAgent command:

```text
cd /Users/Shared/cofe_order_runtime && OUTBOX_WORKER_V2_ENABLED=0 START_WORKER=false WEB_PUSH_ENABLED=0 PUSH_ENABLED=0 PUSH_FIREBASE_ENABLED=0 /Users/ab.mansour1agmail.com/.nvm/versions/node/v24.13.1/bin/node server.js
```

The command does not set `NODE_ENV`.

Relevant runtime env files:

- `/Users/Shared/cofe_order_runtime/.env` has `NODE_ENV=production` and blank `REDIS_URL`.
- `/Users/Shared/cofe_order_runtime/.env.local` has `NODE_ENV=test` and `REDIS_URL=redis://localhost:6379`.

Config behavior:

- `config/index.js` checks `process.env.NODE_ENV === 'production'` before loading dotenv files.
- When LaunchAgent does not set `NODE_ENV`, `.env` is loaded and then `.env.local` is loaded with `override: true`.
- That lets `.env.local` force `NODE_ENV=test`.
- `lib/redis.js` returns no Redis client when `config.env === 'test'`.
- `server.js` `/readyz` still requires Redis when `config.redisUrl` is set.

Runtime probes:

- `docker exec cofe_redis redis-cli ping` returned `PONG`, so Redis itself is reachable.
- `curl http://127.0.0.1:3001/readyz` returned HTTP `503` with `{"ok":false,"database":true,"redis":false}`.
- LaunchAgent logs include `readyz redis check failed: REDIS_URL is not configured.` This is the observed message when Redis is disabled/unavailable to the app readiness check.

## Classification

Persistent Moova readiness is blocked by runtime environment configuration, not by the Redis container being down.

The temporary foreground Moova E2E passed because that test window explicitly started Moova with `NODE_ENV=production` and Redis enabled. The persistent LaunchAgent does not preserve that runtime mode.

## Safe Fix Direction

If approved, update the persistent LaunchAgent/runtime command so local Moova starts with the same intentional mode used by the successful foreground E2E:

- set `NODE_ENV=production`;
- keep worker/push side effects disabled for local POS testing unless explicitly needed;
- restart the LaunchAgent;
- verify persistent `/readyz` returns `ok=true,database=true,redis=true`.

## Verification After Fix

```bash
launchctl bootout gui/$(id -u) ~/Library/LaunchAgents/com.codex.cofe-order-3001.plist
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.codex.cofe-order-3001.plist
curl -sS http://127.0.0.1:3001/readyz
php tools/moova_local_topology_check.php
```

Then rerun live Moova create/confirm, decline, edit, cancel-after-edit, stale guard, and unreachable/recovery E2E from the post-fix checklist.
