# Team Hub UI

## Objective

Replace fragmented users/roles pages with a single minimalist touch-friendly Team Hub (`team.php`) that preserves all RBAC/PIN/lifecycle functionality while matching the approved dark copper mockups (without "نظامي" badge).

## Goal Kind

`specific`

## Current Tranche

1. Ship `team.php` with Staff + Roles tabs and slide-over panels
2. Wire navbar and legacy page redirects
3. Fix preset role permission save guard if needed
4. Add Playwright coverage and run real browser tests

## Non-Negotiable Constraints

- Preserve existing backend capabilities (PIN, role assignment, permissions, limits, lifecycle)
- Arabic RTL, touch targets ≥ 48px
- No "نظامي" label in UI
- Keep legacy pages redirecting to team hub for bookmarks

## Stop Rule

Stop when Judge audit passes: UI matches mockup intent, e2e tests pass, all use cases verified.

## Canonical Board

`docs/goals/team-hub-ui/state.yaml`

## Run Command

```text
/goal Follow docs/goals/team-hub-ui/goal.md
```
