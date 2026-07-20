# 04 — Projects & Files

**Goal:** Projects live inside a workspace; members manage them; each project has
a file manager (upload/list/download/delete). Ships tested.

**Depends on:** 01, 03.

## Files
- Create `app/Controllers/Projects.php`, `app/Controllers/ProjectFiles.php`.
- Views: `app/Views/projects/show.php` (project overview shell with tabs:
  Overview / Board / Files — Board is feature 05), `projects/files.php` (or a
  Files tab section).
- Modify `Routes.php`, and workspace `show.php` to list/link projects + a
  "New project" action.
- Tests: `tests/feature/Web/ProjectsTest.php`, `ProjectFilesTest.php`.

## Routes (authFilter group)
```
$routes->post('workspaces/(:num)/projects/create', 'Projects::create/$1', ['filter'=>'csrf']);
$routes->get('projects/(:num)', 'Projects::show/$1');
$routes->post('projects/update/(:num)', 'Projects::update/$1', ['filter'=>'csrf']);
$routes->post('projects/delete/(:num)', 'Projects::delete/$1', ['filter'=>'csrf']);
$routes->post('projects/(:num)/members/add', 'Projects::addMember/$1', ['filter'=>'csrf']);
$routes->post('projects/(:num)/members/remove', 'Projects::removeMember/$1', ['filter'=>'csrf']);
// files
$routes->get('projects/(:num)/files', 'ProjectFiles::index/$1');            // JSON list
$routes->post('projects/(:num)/files/upload', 'ProjectFiles::upload/$1', ['filter'=>'csrf']);
$routes->get('projects/files/download/(:num)', 'ProjectFiles::download/$1');
$routes->post('projects/files/delete/(:num)', 'ProjectFiles::delete/$1', ['filter'=>'csrf']);
```

## Behaviour + access
- `Projects::create($workspaceId)` — require `can_manage_workspace`; validate
  name; generate slug unique within workspace; creator + inherits workspace
  membership; seed default task columns (`TaskColumnModel::seedDefaults`). Owner
  role=owner in project_members.
- `Projects::show($id)` — require `project_role($id) !== null`; render shell.
- `update/delete` — require `can_edit_project` / owner respectively.
- members add/remove — require project owner/admin.

### Files (security-critical — do not simplify validation)
- `upload($projectId)` — require `can_edit_project`. Use CI4 file validation:
  `uploaded[file]`, `max_size[file,10240]` (10 MB), block dangerous extensions
  via an allowlist (`mime_in` + `ext_in` for common docs/images/archives:
  jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip). Reject
  php/phtml/exe/sh etc. Store with `$file->getRandomName()` under
  `writable/uploads/projects/{projectId}/`; persist row (original_name, stored_name,
  mime, size, uploaded_by). Return JSON incl. the new row.
- `download($fileId)` — look up file, require `project_role` on its project,
  stream with `$this->response->download($absPath, null)->setFileName(original)`.
  Never build the path from user input — always from the stored row.
- `delete($fileId)` — require `can_edit_project`; soft-delete row; unlink file.
- `index($projectId)` — JSON list (require access).

## UI (TailAdmin, mobile-first)
- Project shell: header (name, workspace breadcrumb, status badge) + Alpine tabs.
- Files tab: drag-or-click upload zone (vanilla `fetch` with FormData + CSRF —
  note: file upload needs `FormData`, not `postForm`; include the CSRF field
  name/token from `window.csrf`), a responsive file list (icon, name, size,
  uploader, date, download + delete). Empty state. Progress/disabled while
  uploading.

## Tests
- create gated to workspace managers; slug unique per workspace; default columns
  seeded. show gated to members (IDOR: non-member 403/redirect). File upload
  rejects a `.php` and oversize; accepts a png; download streams for members and
  is denied to non-members; delete soft-deletes + unlinks.
