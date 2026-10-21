# Course Reports architecture

Course Reports now use the central `/course-reports` router with controller, transactional service, scoped repository, typed domain input, validator, and responsive templates.

The repository scopes records by creator or by the Program Specification associated through the selected Course Specification and `user_program_access`. Admin and Quality Coordinator retain global access. Reviewer is read-only, Faculty may update accessible reports, and create/delete permissions retain the established role policy.

Migration `20260827_007_course_report_authorization` adds indexed report ownership. Selecting a Course Specification forces canonical course identity and institution fields, preventing mismatched report metadata. All 27 editable fields are explicitly whitelisted. Deletes remove semester-report links and the report in one transaction, with an audit event in the same transaction.

Legacy list/create/edit/delete endpoints are now compatibility redirects. The principal view page applies the same record access guard. Lists are paginated and capped at 50 records; dropdowns are capped at 500 specifications.
