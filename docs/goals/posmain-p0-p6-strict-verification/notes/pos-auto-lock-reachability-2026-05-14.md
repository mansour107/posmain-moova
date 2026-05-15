# POS Auto-Lock Script Reachability - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T11:27:28Z

## Question

Does the `js/pos_auto_lock.js` syntax failure affect the currently loaded POS browser pages, or is it a first-party script-quality blocker with lower observed GUI blast radius?

## Evidence

Search commands:

- `rg -n "pos_auto_lock|Auto-lock|posPasswordOverlay|pos_authenticated|posPasswordInput|posPasswordError" . -g '!backup/**' -g '!docs/goals/posmain-p0-p6-strict-verification/**' -g '!*.sql'`
- `rg -n "<script|pos_auto_lock|pos_password|posPasswordOverlay|sessionStorage" pos_barcode.php includes/pos_content.php elements -S`
- `curl -sS http://127.0.0.1:8010/pos_barcode.php | rg -n "pos_auto_lock|posPasswordOverlay|pos_authenticated|<script"`

Findings:

- `js/pos_auto_lock.js` is the only source hit for `pos_auto_lock`, `posPasswordOverlay`, `posPasswordInput`, and `posPasswordError`.
- `pos_barcode.php` and `includes/pos_content.php` load `js/pos_config_loader.js`, `js/pos_offline_adapter.js`, and `js/pos_barcode.js`, but not `js/pos_auto_lock.js`.
- Current POS authentication state is handled through PHP session keys such as `$_SESSION['pos_authenticated']` and central guards such as `require_pos_authenticated()`.
- The unauthenticated `curl` probe did not show `pos_auto_lock` or `posPasswordOverlay` in the returned page.

## Classification

The syntax failure remains real:

- `node --check js/pos_auto_lock.js` fails at line `116` with `SyntaxError: Unexpected token '}'`.

But current source search did not prove that this broken file is loaded by the live POS cashier page. Therefore:

- It remains a first-party JS syntax blocker for the strict script suite.
- It should not be used by itself as proof that the current GUI page is crashing.
- If the file is intended to be active POS lock behavior, then the blocker is both a syntax failure and a coverage/wiring gap because the expected overlay IDs are not present in the current POS page sources.

## Follow-Up

In a fix tranche, decide whether to:

- remove the duplicate trailing `})();` and wire/verify the auto-lock script intentionally, or
- remove/deprecate the unused script if the PHP session POS auth flow is the intended current lock mechanism.

Either direction should be verified with `node --check js/pos_auto_lock.js`, a first-party JS syntax sweep, and a browser POS auth/unlock smoke.
