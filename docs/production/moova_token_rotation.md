# POSMAIN Moova Device Token Rotation

Generated: 2026-05-13

## Policy

Moova device tokens remain visible to authorized Moova managers because operators need to verify the POS bridge setup. They are still secrets.

Only users with `moova.manage` compatibility permissions may view or replace the full token. Cashiers use the token indirectly through the local widget/proxy and should not need to reveal it.

## Current Controls

- `moova_integration.php` displays the full token only when the user can manage Moova integration.
- Viewing the full token records `security_audit_log.event_type = moova_device_token_viewed`.
- Saving or disconnecting the link records dedicated Moova integration audit events.
- `moova_pos_shop_links` stores `moova_device_token_hash` and `moova_device_token_last4` for lookup and audit metadata.

## At-Rest Risk

The current Phase 5 slice does not encrypt `moova_device_token` at rest. Until encryption is implemented, restrict DB users, backups, logs, and support exports as if the token column is secret material.

Do not commit:

- real Moova device tokens
- copied `.env` files
- SQL dumps containing `moova_pos_shop_links`
- screenshots or reports showing full production tokens

## Rotation Steps

1. Generate or copy the new Moova device token from the trusted Moova admin surface.
2. Log in to POSMAIN as an authorized manager.
3. Open `moova_integration.php`.
4. Replace the token and save.
5. Confirm the save audit event exists with the new token last4 only.
6. Open the POS screen and verify the widget receives orders with the new token.
7. Disable or revoke the old token in Moova, if Moova supports revocation.
8. Record the rotation in the private operator log, not in this repository.

## Incident Rotation

Rotate immediately if a token appears in a chat, screenshot, repository file, SQL dump, log file, or support artifact.

After incident rotation:

1. Search private logs/backups for the leaked token value.
2. Confirm `moova_pos_shop_links.moova_device_token_hash` no longer matches the leaked token.
3. Run Moova accept/decline smoke tests.
4. Keep queued worker apply disabled until the branch passes cashier acceptance again.
