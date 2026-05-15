# Moova Unreachable Widget Receipt - POSMAIN P0-P6 Strict Verification

Timestamp: 2026-05-14T10:59:00Z

## Scope

This task tested the remaining live unreachable-service behavior against the real POS browser surface. It did not edit implementation files and did not submit POS payment, print, shift-close, table-assignment, or destructive database actions.

## Baseline

Before stopping Moova:

- POS page `http://127.0.0.1:8010/pos_barcode.php` loaded normally.
- Embedded widget source was `http://127.0.0.1:8010/moova_pos_widget.php`.
- Widget text showed `تشغيل صوت الإشعارات`, `الطلبات المعلّقة`, `بانتظار الطلبات`.
- Persistent Moova `/readyz` remained degraded: `{"ok":false,"database":true,"redis":false}`.

## Moova Stopped

Stopped only the persistent LaunchAgent for the verification window:

```text
launchctl bootout gui/$(id -u) ~/Library/LaunchAgents/com.codex.cofe-order-3001.plist
```

Service evidence:

- `curl http://127.0.0.1:3001/readyz` failed to connect.
- No listener remained on TCP port `3001`.

Browser/POS evidence while Moova was unreachable:

- Reloaded `pos_barcode.php`; POS cashier UI still loaded and showed the current order area, totals, item catalog, and payment controls.
- No SQL/fatal/PHP error text appeared in the POS page.
- The local iframe still loaded from `moova_pos_widget.php`.
- Logged-in browser fetch to `/moova_pos_proxy.php?...pending` returned HTTP `502` with JSON code `MOOVA_UNREACHABLE`, `retryable: true`, and connection-refused details.
- Logged-in browser fetch to `/moova_pos_proxy.php?...widget/bootstrap` returned HTTP `502` with JSON code `MOOVA_UNREACHABLE`, `retryable: true`, and connection-refused details.
- Browser console recorded `cofe.widget.error` with Arabic message `تعذر الاتصال بـ Moova. تحقق من تشغيل خدمة Moova والاتصال ثم حاول مرة أخرى.` and code `MOOVA_UNREACHABLE`.

## UX Finding

The backend/proxy error classification is good: it returns explicit `MOOVA_UNREACHABLE` and `retryable: true`.

The visible widget state is weaker: after the transient error event, the bell panel can still show:

```text
لا توجد طلبات بانتظار الموافقة
القائمة فارغة الآن. ستظهر طلبات نقاط البيع الجديدة هنا تلقائياً.
```

That means the cashier can see an empty-queue state even while Moova is unreachable. This is a real UX/readiness gap, not a POS crash.

## Restore Evidence

Restored the LaunchAgent:

```text
launchctl bootstrap gui/$(id -u) ~/Library/LaunchAgents/com.codex.cofe-order-3001.plist
```

After restore:

- TCP port `3001` listened again under a `node` process.
- `/readyz` returned `{"ok":false,"database":true,"redis":false}` again.
- Browser proxy checks for `pending` and `widget/bootstrap` returned HTTP `200`.
- Widget returned to idle text `تشغيل صوت الإشعارات`, `الطلبات المعلّقة`, `بانتظار الطلبات`.

## Decision Impact

This closes the previous untested live unreachable-widget evidence gap. It does not make the goal complete:

- Persistent Moova health remains degraded because `/readyz` is still `redis=false`.
- The visible offline state needs a clearer persistent warning instead of settling into an empty-queue panel.
- Scripted blockers from T003/T999 still remain.
