# Environment Restoration Audit - 2026-05-14

## Purpose

Verify that the strict verification campaign did not leave disposable databases, foreground test servers, or unexpected local service state behind.

This is documentation only. No app code, tests, runtime configuration, or database data were changed.

## Board State

```text
goal_status=blocked
active_task=null
task_count=38 before adding this receipt
errors=[]
warnings=[]
```

## Disposable Database Cleanup

The cleanup query checked these temporary verification patterns:

```text
posmain_moova_worker_clone_%
posmain_p0p2_%
posmain_phase5_%
posmain_phase4_%
posmain_phase6_%
posmain_security_%
posmain_login_throttle_%
posmain_gui_write_%
posmain_strict_p06_%
```

Result: no rows.

## Runtime State

POS HTTP:

```text
pos_http=200 http://127.0.0.1:8010/index.php
```

Moova persistent `/readyz`:

```text
moova_readyz=503
{"ok":false,"database":true,"redis":false}
```

This is the known persistent runtime blocker, not a new side effect from cleanup.

## Listeners

Port `8010`:

```text
com.docke ... TCP 127.0.0.1:8010 (LISTEN)
```

Port `3001`:

```text
node 87077 ... TCP *:3001 (LISTEN)
```

The `3001` process is owned by the expected LaunchAgent:

```text
gui/501/com.codex.cofe-order-3001
state = running
program = /bin/bash
working directory = /Users/Shared/cofe_order_runtime
pid = 87073
```

Child process:

```text
87077 87073 /Users/ab.mansour1agmail.com/.nvm/versions/node/v24.13.1/bin/node server.js
```

## Docker State

Expected POS and supporting containers are running, including:

```text
posmain-mysql Up ... 127.0.0.1:3307->3306/tcp, 127.0.0.1:8010->8000/tcp
posmain-php Up ...
cofe_postgres Up (healthy)
cofe_redis Up (healthy)
```

Other Supabase and local support containers are also running. This audit did not start, stop, remove, or reset containers.

## Decision

The verification environment is restored enough for the current blocked audit state:

- no disposable verification schemas remain for checked patterns;
- normal POS HTTP remains reachable;
- persistent Moova is running under LaunchAgent but remains unhealthy with `redis=false`;
- no foreground temporary Moova runtime is left behind;
- the board remains blocked pending owner approval for fixes/runtime work.

Do not mark the goal complete from this receipt. It verifies cleanup, not strict readiness.
