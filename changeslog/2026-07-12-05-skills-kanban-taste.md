# 2026-07-12 — CI version note, frontend UI/UX skills, reviewer, ponytail, kanban, taste-skill

## Raw prompt

> Let note The latest stable version of CodeIgniter is CodeIgniter 4.7.4 to CLAUDE and project.
> frontend-coder must know how to use confirm modal, toast, alert for UI/UX, let load UI/UX skill
> to use and note all skills to this sub-agent.
>
> update reviewer sub-agent, to review data integrity, data owner, auth session use correctly, use
> correctly session userWorking, userAuthenticated
>
> có một số skills ponytail, coding theo đúng yêu cầu, sạch sẽ, chuẩn chỉ, hãy cài đặt để chuẩn bị
> cho dự án
>
> hãy chuẩn bị thư mục kanban chứa các sub dir: plan, processing, done để khi lập plan sẽ lưu vào
> plan, khi code lưu vào processing, xong thì sẽ lưu vào done. hãy ghi nhớ điều này nhé
>
> hãy sử dụng npx skills add Leonxlnx/taste-skill cho frontend sub agent, ghi vào nhé

## Decisions

- CodeIgniter latest stable **4.7.4** is the target (already stated in CLAUDE.md — confirmed).
- `frontend-coder` keeps the full UI/UX skill table + the toast/confirm/alert Alpine contract;
  design/taste skills are now installed project-locally.
- `reviewer` already covers data integrity, data ownership (IDOR), and correct
  `userAuthenticated` (role/identity) vs `userWorking` (data scope) usage — confirmed in place.
- Ponytail skill set (`ponytail`, `ponytail-review`, `ponytail-audit`, `ponytail-debt`,
  `ponytail-gain`, `ponytail-help`) is available via the plugin.
- Task lifecycle uses a `kanban/` board: `plan/` → `processing/` → `done/`, one file moved
  (not copied) across stages. Planner writes to `kanban/plan/` (replaces `tasks/{id}/plan.md`).

## What the agent did

- Ran `npx skills add Leonxlnx/taste-skill` — installed the taste/design skill bundle into
  `.agents/skills/`, symlinked into `.claude/skills/`.
- Created `kanban/{plan,processing,done}/` with a README documenting the lifecycle.
- Updated CLAUDE.md: ponytail skill set list, kanban task-board section, project-local UI/UX skill
  list under Frontend conventions.
- Updated agents: planner (saves to `kanban/plan/`, lifecycle), backend-coder/frontend-coder
  (move plan to `kanban/processing/` on start), reviewer (move to `kanban/done/` on sign-off),
  tester (read plan from board), agents README (flow diagram + examples).
- Saved kanban lifecycle to project memory.
