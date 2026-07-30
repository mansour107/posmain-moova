# Certification receipts

Production capability activation is bound to a signed
`posmain.certification-receipt.v1` document. The legacy
`POSMAIN_INVENTORY_CUTOVER_CERTIFIED` and
`POSMAIN_RECIPE_ROLLOUT_CERTIFIED` settings remain readable for compatibility,
but they are attestations only and cannot activate a certified capability.

The receipt subject binds:

- the extracted release manifest hash and source commit;
- the applied migration-ledger checksum;
- the database schema fingerprint;
- branch UUID, tenant ID and branch ID.

The receipt also carries versioned gate results. Financial and sync gates are
required before an inventory gate can activate quantity capability. The recipe
gate additionally requires the inventory gate. A shop can still request basic
POS, stock without recipes, recipes without accounting, or all optional modules
off; certification permits a requested capability and never forces it on.

At runtime, `POSMAIN_CERTIFICATION_RECEIPT_PATH` and
`POSMAIN_RELEASE_MANIFEST_PATH` must be absolute paths.
`POSMAIN_CERTIFICATION_RECEIPT_KEY` must be supplied from the process
environment and must contain at least 32 bytes. The release manifest is checked
against every packaged file, and the configured branch database is queried
read-only to calculate its current migration and schema fingerprints.
The result is cached only inside the current PHP request so repeated config
lookups do not repeat the file and schema scan.

Any missing, malformed, expired, revoked, tampered or mismatched evidence leaves
inventory in the compatibility-safe shadow state and recipes read-only.
Automatic cloud-originated operational writes remain disabled. Tax remains off.
Router/cloud multi-shop certification is intentionally fail-closed until a
per-shop receipt and connection boundary is implemented.

A receipt must only be issued by the release process after the corresponding
fail-closed gate commands have produced reviewed evidence. This code validates
receipts; it does not allow an application operator to self-assert that a gate
passed.
