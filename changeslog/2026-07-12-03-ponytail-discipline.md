# 2026-07-12 — Require ponytail skill for coding and review

## Raw prompt

> hãy update claude cho dự án này để dùng ponytail skill cho coding và review

## Decisions

- Coding uses the `ponytail` skill (lazy = efficient: understand fully, ship the shortest correct
  diff, reuse before writing, no speculative abstractions).
- Review uses the `ponytail-review` skill, layered under the existing security/correctness/
  data-integrity checklist — never simplifying away validation, error handling on registrar/billing
  side effects, auth/ownership scoping, or `esc()` escaping.

## What the agent did

- Added a "Coding & review discipline — ponytail (REQUIRED)" section to CLAUDE.md.
- Wired `backend-coder` and `frontend-coder` to invoke `ponytail`, and `reviewer` to invoke
  `ponytail-review`.
