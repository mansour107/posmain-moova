# Order Creation Cutover Runbook

## Enable router-only mode (staging first)

```bash
export POSMAIN_ORDER_API_ROUTER_ONLY=1
export POSMAIN_SIDE_EFFECT_MODE=live   # production only after side-effect parity sign-off
```

## Rollback

```bash
export POSMAIN_ORDER_API_ROUTER_ONLY=0
```

Cashier POS immediately regains access to legacy `do/doadd_invoice.php` form POST (not recommended except emergency rollback).

## Certification pack

```bash
./scripts/run_order_creation_certification.sh
```

## Recovery checks

- `GET ajax/pos_write_recovery_status.php` — stuck idempotency + failed outbox counts
- `GET api/health.php?detail=1&token=$POSMAIN_STATUS_TOKEN` — order creation schema + side-effect mode

## Supported write surface

All cashier writes: `api/pos/index.php?route=<route>` via `POSOrderApi`.

See `docs/production/active_route_map.md` for the full action matrix.

## Troubleshooting

| Symptom | Check |
|--------|--------|
| Save still reloads | Hard-refresh POS; confirm `pos_barcode.js` calls `POSOrderApi.submitFromForm` |
| 403 PERMISSION_DENIED | User role lacks `pos.sell.takeaway` / `pos.table.open` / etc. |
| 400 IDEMPOTENCY_REQUIRED | Frontend must send `idempotency_key` on every write |
| 409 IDEMPOTENCY_CONFLICT | Same key, different payload — use a new key for intentional retry |
| 423 IDEMPOTENCY_PROCESSING | Duplicate in-flight request — safe to retry after short delay |
| Background flash on save | Usually caused by full page reload; API path should show Bootstrap success modal only |
