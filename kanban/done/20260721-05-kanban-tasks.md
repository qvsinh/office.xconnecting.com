# 05 — Kanban Board & Tasks

**Goal:** Each project has a kanban board (columns + cards) and a list view;
full task CRUD; drag-and-drop reorder/move; assignee, priority, due date. Tested.

**Depends on:** 01, 04.

## Files
- Create `app/Controllers/Board.php` (task + column endpoints).
- Views: `app/Views/projects/board.php` (board tab content), reused inside
  `projects/show.php` shell. Optional `projects/tasks_list.php` list view.
- Modify `Routes.php`.
- Tests: `tests/feature/Web/BoardTest.php`, `tests/database/TaskModelTest.php`
  (reorder) — the latter may already exist from 01; extend it.

## Routes (authFilter group)
```
$routes->get('projects/(:num)/board-data', 'Board::data/$1');                // JSON: columns+tasks+members
$routes->post('projects/(:num)/tasks/create', 'Board::createTask/$1', ['filter'=>'csrf']);
$routes->post('tasks/update/(:num)', 'Board::updateTask/$1', ['filter'=>'csrf']);
$routes->post('tasks/move/(:num)', 'Board::moveTask/$1', ['filter'=>'csrf']);   // {column_id, position}
$routes->post('tasks/delete/(:num)', 'Board::deleteTask/$1', ['filter'=>'csrf']);
$routes->post('projects/(:num)/columns/create', 'Board::createColumn/$1', ['filter'=>'csrf']);
$routes->post('columns/rename/(:num)', 'Board::renameColumn/$1', ['filter'=>'csrf']);
$routes->post('columns/delete/(:num)', 'Board::deleteColumn/$1', ['filter'=>'csrf']);
```

## Behaviour + access
- Every endpoint resolves the owning project and requires `project_role() !== null`
  to read, `can_edit_project()` to mutate. Task endpoints take a task id → load
  task → derive project → check access (IDOR guard).
- `data($projectId)` — returns `{columns:[{id,name,position,tasks:[...]}],
  members:[{id,name}]}`. Task fields: id, title, description, priority,
  assignee_id, assignee_name, due_date, position, column_id.
- `createTask` — validate title required; default column = first; position = end.
- `updateTask` — title/description/priority/assignee_id/due_date. assignee must
  be a project member (validate) or null.
- `moveTask` — set column_id + position; renumber siblings (simple: set position
  to provided integer; on read, order by position then id). `TaskModel::reorder`.
- columns create/rename/delete — delete blocks if it holds tasks (or moves them
  to the first column — pick: move tasks to first remaining column, then delete).

## UI (TailAdmin, mobile-first) — board.php, Alpine `projectBoard()`
- Desktop: horizontal columns (`flex gap-4 overflow-x-auto`), each a droppable
  list of cards. Mobile: columns stack vertically (still scannable), drag still
  works via pointer events.
- Drag-and-drop: use the **native HTML5 drag events** (`draggable="true"`,
  `@dragstart/@dragover.prevent/@drop`) — no external DnD library (ponytail: the
  browser already does this). On drop, POST `tasks/move` then optimistic update.
  ponytail: HTML5 DnD is weak on touch; add a fallback "move" menu on each card
  (a small ▾ that lists other columns) so mobile users can move without dragging.
- Card modal: click a card → detail modal (title, description, priority pills,
  assignee select from members, due date `<input type=date>`, save/delete).
- "Add task" input at the bottom of each column; "+ Column" affordance.
- Priority pill colours: low=gray, normal=blue, high=amber, urgent=red.

## Tests
- create/update/move/delete task through routes; access gating (non-member 403);
  assignee-must-be-member validation; move updates column_id+position;
  column delete relocates tasks. `TaskModel::forProject` ordering.
