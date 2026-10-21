# Tasks module architecture

The Tasks module is the first fully migrated feature behind the central authenticated router.

## Request flow

`/tasks` routes through `router.php` and `CompatibilityRouter` to `TaskController`. The controller handles HTTP concerns, `TaskService` enforces permissions and transactions, and `TaskRepository` owns parameterized database access. Templates contain presentation only. Legacy GET endpoints redirect to the clean routes while their POST compatibility paths remain available during the transition.

## Authorization

`PermissionMatrix` defines module/action permissions. Repository queries also enforce record scope:

- Admin and Quality Coordinator can access all tasks.
- Other authorized roles can access tasks they created, tasks assigned to them, or tasks belonging to a program listed in `user_program_access`.
- Faculty may update workflow fields on accessible tasks but cannot reassign or redefine them.
- Destructive actions require both permission and a valid CSRF-protected POST request.

## Data integrity and performance

Migration `20260827_006_task_authorization` adds task ownership/program foreign keys and indexes plus the user/program access table. Task writes and their status/audit records run in one database transaction. Lists are paginated, search input is bounded, and lookup queries have fixed limits.

## Verification

`TaskAuthorizationTest` covers role permissions and cross-user/cross-program record isolation. `http_e2e.php` verifies Admin and Reviewer access through Apache. The production browser check covers login labels, Tasks landmarks and table semantics, form labels, and a 390-by-844 responsive viewport.

