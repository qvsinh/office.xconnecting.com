# 2026-07-12 — Drop CMS from scope; English for all saved artifacts

## Raw prompt

> chức năng chính là proxy cho CNR, JPRS, users, System, api vì thế CMS ko cần thiết, do tôi chưa
> xoá từ trước. hày ghi nhớ luôn code, comment, lên plan ... lưu bằng English dù tôi prompt bằng
> ngôn ngữ khác

## Decisions

- Core pillars: **Proxy (CNR/JPRS), API, Users, System**. The legacy **CMS is out of scope** — it
  only exists in the legacy repo because it hasn't been removed yet. Do not port it.
- With CMS dropped, auth zones reduce to `global` (admin) / `client` (end user); legacy `cms` zone
  is out of scope.
- All saved artifacts (code, comments, plans, commits) are written in **English** regardless of
  the prompt language.

## What the agent did

- Reframed the CLAUDE.md feature summary around the four pillars and marked CMS out of scope.
- Updated `backend-coder` and `reviewer` (zones `global`/`client`) accordingly.
- Saved the English-artifacts preference to project memory.
