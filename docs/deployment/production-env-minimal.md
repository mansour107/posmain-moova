# Production .env reference (Hetzner)

Production inventory and recipe behavior is built into the app defaults. You only need env vars for **connection**, **identity**, **integrations**, and **account mappings**.

## Safe to remove when they only repeat code defaults

- `POSMAIN_RECIPE_MODE`
- `POSMAIN_RECIPE_RESERVATIONS`
- `POSMAIN_RECIPE_CONSUMPTION`
- `POSMAIN_RECIPE_ACCOUNTING`
- `POSMAIN_RECIPE_AVAILABILITY`
- `POSMAIN_RECIPE_SHADOW_LEDGER`
- `POSMAIN_RECIPE_STRICT_STOCK`
- `POSMAIN_RECIPE_COST_PUBLIC_PAYLOADS`
- `POSMAIN_INVENTORY_LEDGER_MODE`
- `POSMAIN_INVENTORY_LEGACY_MIRROR`
- `POSMAIN_INVENTORY_STRICT_STOCK`
- `POSMAIN_INVENTORY_RESERVATIONS`
- `POSMAIN_INVENTORY_ACCOUNTING`
- `POSMAIN_INVENTORY_AVAILABILITY`
- `POSMAIN_INVENTORY_SYNC`
- `POSMAIN_INVENTORY_COST_PUBLIC_PAYLOADS`

## Cloud role note

When `POSMAIN_ROLE=cloud`, recipe Moova sync stays off by default. Do **not** set `POSMAIN_RECIPE_MOOVA_SYNC=1` on the cloud host unless branch sync is fully configured.

## Keep

- Database and router credentials
- `POSMAIN_PUBLIC_BASE_URL`
- Recipe account ID mappings (`POSMAIN_RECIPE_*_ACCOUNT_ID`) when you need accountant-chosen overrides
  (otherwise the app resolves COGS=`42`, inventory=`123`, waste/variance=`41` from `acc_head`)
- Moova/sync worker settings that match your deployment topology

## Hosted Playwright login

When `POSMAIN_ROUTER_ENABLED=1`, use the router alias (e.g. `admin@kody2`), not bare `admin`.
