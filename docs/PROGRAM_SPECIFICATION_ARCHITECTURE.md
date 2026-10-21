# Program Specifications architecture

The Program Specifications module is served through `/program-specifications` by the central authenticated router.

`ProgramSpecificationController` owns HTTP behavior, `ProgramSpecificationService` enforces permissions and transactions, `ProgramSpecificationRepository` owns scoped and parameterized database access, and the domain validator rejects invalid titles, names, dates, statuses, types, and credit values. The repository uses an explicit whitelist for every editable database column.

Admin and Quality Coordinator roles can view all specifications. Program Leader and Reviewer access is limited to records granted through `user_program_access`. New specifications automatically grant their creator access. Program Leader may create and update granted records; deletion remains limited to Admin and Quality Coordinator and is refused when linked curriculum, PLO, KPI, mapping, staffing, assessment, track, or exit-point data exists.

Legacy list/create/edit/delete endpoints are compatibility redirects into the new routes. The principal profile, dashboard, validation, readiness, export hub, and PLO mapping pages also apply the same record-level access guard.

The list is paginated and bounded to 50 rows per request. The responsive form retains all 45 user-editable inputs, groups them into semantic fieldsets, and synchronizes the legacy `status` field with `approval_status` inside the service.
