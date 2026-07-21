# POSMAIN Moova Device Token Rotation

Generated: 2026-05-13

## Policy

Moova device tokens are write-only secrets in POSMAIN. Managers can replace a token but cannot reveal the stored value.

Only users with `moova.manage` compatibility permissions may replace the token. Cashiers use it indirectly through the bridge.

## Current Controls

- `moova_integration.php` displays only the last four characters.
- Saving or disconnecting the link records dedicated Moova integration audit events.
- `moova_pos_shop_links` stores the token encrypted at rest, plus a one-way hash and last four characters for lookup and audit metadata.

## At-Rest Protection

New pairings require `POSMAIN_CONFIG_ENCRYPTION_KEY` (or its key file) and never store the raw token. Legacy plaintext rows are read only for compatibility and are replaced by encrypted storage on the next successful pairing.

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
