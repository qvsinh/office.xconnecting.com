# 02 — Groups (admin)

**Goal:** Admins manage user groups (departments/teams) and their membership.
Ships as: `/admin/groups` list + create/edit/delete + manage members, tested.

**Depends on:** 01 (GroupModel, GroupMemberModel).

## Files
- Create `app/Controllers/Admin/Groups.php` (extends BaseController).
- Create `app/Views/admin/groups/index.php` and `app/Views/admin/groups/members.php`.
- Modify `app/Config/Routes.php` (admin group).
- Modify `app/Views/layouts/app.php` — add "Groups" to the Admin nav group.
- Tests: `tests/feature/Web/AdminGroupsTest.php`.

## Routes (inside the existing `admin` group)
```
$routes->get('groups', 'Admin\Groups::index');
$routes->post('groups/create', 'Admin\Groups::create', ['filter'=>'csrf']);
$routes->get('groups/edit/(:num)', 'Admin\Groups::edit/$1');
$routes->post('groups/update', 'Admin\Groups::update', ['filter'=>'csrf']);
$routes->post('groups/delete', 'Admin\Groups::delete', ['filter'=>'csrf']);
$routes->get('groups/members/(:num)', 'Admin\Groups::members/$1');       // page
$routes->get('groups/(:num)/members-data', 'Admin\Groups::membersData/$1'); // JSON
$routes->post('groups/(:num)/members/add', 'Admin\Groups::addMember/$1', ['filter'=>'csrf']);
$routes->post('groups/(:num)/members/remove', 'Admin\Groups::removeMember/$1', ['filter'=>'csrf']);
```

## Controller behaviour
- `index()` — paginated/searchable list (mirror `Admin\Settings::index`), each row
  shows name, slug, description, member count.
- `create/update` — validate `name` required max 150, unique slug generated;
  `description` permit_empty. JSON envelopes.
- `delete` — soft delete; also delete its `group_members` rows.
- `members($id)` — page listing current members + an "add user" picker
  (searchable select of users by name/email). `addMember`/`removeMember` mutate
  `group_members`, return JSON.

## UI — mirror `admin/settings/index.php` exactly
- List table + create/edit modal (Alpine `groupsAdmin()`), confirm-delete, toast.
- Members page: two-column responsive (current members list with remove buttons;
  add-member search box that queries `membersData`? No — add a user-search
  endpoint). Keep it simple: the add form is a `<select>` populated server-side
  with all active users (small company), Alpine posts the chosen id. Mobile: stack.

## Tests
- Admin can create/edit/delete a group; slug uniqueness; add/remove member;
  member count reflects; non-admin (client) gets redirected by adminFilter;
  guest → /login.
