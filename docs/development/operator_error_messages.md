# Operator-facing error messages

POSMAIN is used by restaurant staff, so internal exception identifiers are never a user interface.

For every new or changed operator-facing web flow:

1. Keep the stable diagnostic code in logs, durable records, and server-to-server responses.
2. Translate it at the final UI/API boundary into short Arabic that explains what happened and the next safe action.
3. Never render identifiers containing underscores, stack traces, database text, exception class names, host paths, or secrets.
4. Use a safe generic Arabic fallback for unknown failures and log the diagnostic separately.
5. Do not falsely report success. Saving configuration and proving a live device connection are separate outcomes.
6. Financial, order, inventory, and physical-output uncertainty must fail closed and tell the operator what to inspect before retrying.

Printing uses `PrintUserMessageService` as the reference implementation. Tests must assert that every rendered failure is Arabic, contains no internal identifier, and provides a recovery action where the operator can act.
