# kanban — task lifecycle board

Every task moves through three stages, each a directory. Each task is one Markdown file
(`YYYYMMDD-slug.md` or `{task-number}-slug.md`); **move the same file forward** between stages —
do not copy or recreate it, so its history stays in one place.

| Stage | Directory | When | Who |
|-------|-----------|------|-----|
| **Plan** | `kanban/plan/` | A plan has been written, not yet started | `planner` writes here |
| **Processing** | `kanban/processing/` | Implementation in progress | move here when `backend-coder` / `frontend-coder` starts |
| **Done** | `kanban/done/` | Implemented, tested, reviewed, merged | move here after `reviewer` signs off |

Rules:

- **Planner saves plans to `kanban/plan/`.**
- When coding starts, **move** the file to `kanban/processing/`.
- When the task is complete (tested + reviewed), **move** it to `kanban/done/`.
- One file per task, moved (not duplicated) across stages. Written in **English**.
