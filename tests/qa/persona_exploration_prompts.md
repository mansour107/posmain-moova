# Persona QA Exploration Prompts

Guided checklists for Phase 3 AI exploratory testing. Each persona session should:

1. Log in with demo credentials from `tests/qa/campaign_config.local.json` (`p6_*` / `P6demo123!`).
2. Cross-check scripted failures in `var/qa/{run_id}/{env}/non_gui.json` and Playwright JSON/HTML.
3. Cite screenshot paths or log lines for every bug claim.
4. Compare against **Foodics** for a **mid-scale café** (not enterprise franchise).
5. Tag each finding with roadmap bucket: **Now** / **Next** / **Defer**.

Reference matrix: `docs/production/phase6_e2e_local_command.md`.

---

## shared — Security & session friction

**Voice:** IT-minded floor supervisor who cares about lock screens and wrong-role access.

**Local + hosted checklist**

- [ ] Login with wrong password (lockout messaging, Arabic copy)
- [ ] Session timeout / POS unlock after idle
- [ ] Cashier cannot open owner admin URLs directly
- [ ] Logout clears POS state
- [ ] HTTPS / cookie flags on hosted (if applicable)

**Foodics lens:** Is forced unlock faster or clearer than Foodics branch login?

**Output sections:** Arabic first-person + English summary (see campaign plan template).

---

## cashier — أحمد (12 سنة POS)

**Voice:** Rush-hour cashier; speed, payment modal clarity, Arabic labels.

**Checklist**

- [ ] Takeaway: add items, modifiers, pay cash/card/wallet
- [ ] Double-tap pay / race conditions
- [ ] Delivery modal open/close without losing cart
- [ ] Split pay (manual — note if UI missing)
- [ ] Receipt / customer display readability
- [ ] Shift open (if exposed) — note gaps
- [ ] Keyboard / barcode path if available

**Foodics lens:** Payment flow steps vs Foodics cashier at peak hour.

**Priority tags:** Payment bugs → **Now**; missing split pay automation → **Next**.

---

## waiter — Table service without payment noise

**Voice:** Waiter who only saves tables and sends KOT; hates payment dialogs.

**Checklist**

- [ ] Table map load, area filter
- [ ] Open table, add items, save without pay
- [ ] Transfer table / merge (if available)
- [ ] KOT print URL or kitchen signal
- [ ] No accidental payment prompts on table save

**Foodics lens:** Table flow vs Foodics dine-in mode.

---

## manager — Approvals, shift close, reversals

**Voice:** Shift manager responsible for Z-close and voids.

**Checklist**

- [ ] Manager approval for void/discount
- [ ] Shift close / fund count (manual paths)
- [ ] Refund / reversal (click-through if UI exists)
- [ ] Reports accessible to manager role only
- [ ] Audit trail visibility

**Foodics lens:** End-of-day close vs Foodics shift reports.

---

## owner — Back-office vs Foodics

**Voice:** Owner comparing menu, recipe, inventory to Foodics back-office.

**Checklist**

- [ ] Menu categories, items, modifiers CRUD surfaces
- [ ] Recipe / cost linkage
- [ ] Inventory balances and movements (read)
- [ ] Staff users and roles
- [ ] Settings / payment methods
- [ ] Moova integration surface (if seeded)

**Foodics lens:** What owner tasks still require spreadsheet workaround?

**Defer:** Enterprise franchise analytics, multi-region — tag **Defer**.

---

## sync_ops — Outbox, offline, hosted parity

**Voice:** Ops engineer verifying branch ↔ cloud sync.

**Checklist**

- [ ] `branch_worker_status` — stuck outbox, failed logs
- [ ] Catalog push local → hosted (item counts match)
- [ ] Order created locally appears on hosted (or vice versa if bidirectional)
- [ ] Offline mock scenario behavior (`e2e_mock_online_offline_sync`)
- [ ] Pairing metadata in campaign config matches UI/settings
- [ ] Hosted Playwright login still works after sync

**Foodics lens:** Not applicable — compare to expected branch/cloud POS sync SLA.

**Priority:** Data loss → **Now**; worker polish → **Next**.

---

## Session output template

```markdown
## {Persona} — {Environment} — {Arabic persona name}

### ما الذي اختبرته
### ما أعجبني
### ما أزعجني
### أخطاء / مشاكوك فيها
### مقارنة بفودكس (كافيه متوسط)
### أولوياتي لهذا الدور

---
## {Persona} — {Environment} — English summary
...
```

Save to: `var/qa/{run_id}/narratives/{persona}/{env}.md`
