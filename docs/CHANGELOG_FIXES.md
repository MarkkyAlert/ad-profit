# Changelog (Selected Fixes)

- 2026-02-24: Daily Record Management: `RecordService::upsertRecord()` and `deleteRecord()` now use PDO transactions (when available) and lock target rows with `FOR UPDATE` to serialize concurrent submits; `updateRecord()` no longer commits/rolls back outer transactions.
- 2026-02-24: API Hardening: state-changing endpoints now accept `action` from POST only (no GET fallback).
- 2026-02-24: API Hardening: POST actions now enforce form Content-Type (urlencoded/multipart) via `ensure_form_content_type_or_respond()`; otherwise returns 415.
- 2026-02-24: Security headers: added `Referrer-Policy: same-origin` and `Permissions-Policy` (disable geolocation/microphone/camera).
- 2026-02-24: GoalService: `upsertGoal()` and `deleteGoal()` now use PDO transactions (when available) for consistency with other services.
- 2026-02-24: PasswordResetRepository: `deleteByUserId()` now returns true on successful execute (even if 0 rows affected).
- 2026-02-24: ExportService: `buildMonthlyCsvPayload()` now has exception handling to prevent uncaught exceptions.
- 2026-02-24: DashboardService/AnnualService/OverviewService: Added top-level exception handling in main public methods.
