# QA Run Report

- Started At: 2026-02-24T11:44:36+07:00
- Finished At: 2026-02-24T11:44:38+07:00
- Base URL: `http://localhost/ad-profit`
- Tooling: PHP cURL-based API runner (`qa_runner.php`)
- Flaky Criteria: first attempt fails, immediate retry (GET-only) passes

## Summary

- Total: 65
- Passed: 63
- Failed: 2
- Flaky: 0

### By Category

| Category | Total | Passed | Failed | Flaky |
|---|---:|---:|---:|---:|
| Edge Case | 10 | 10 | 0 | 0 |
| Happy Path | 21 | 21 | 0 | 0 |
| Security | 16 | 14 | 2 | 0 |
| Validation | 18 | 18 | 0 | 0 |

## Failed / Flaky Details

### S01 - Auth login action via GET must be blocked by method guard

- Status: **FAILED**
- Expected: 405 + Method Not Allowed
- Observed: Expected status 405 with error containing "Method Not Allowed", got status 404 and error "Invalid action"
- Reproducible Steps:
  1. Send `GET` to `http://localhost/ad-profit/api/auth.php?action=login`
  2. Observe mismatch against expected assertion
- Root Cause (inferred): Likely in auth API action flow @api/auth.php#21-155
- Fix Recommendation:
  - Add/adjust guard or validation branch to return expected status+payload.
  - Add regression check in runner case `S01` after fix.

### S02 - Shops create via GET must be blocked by method guard

- Status: **FAILED**
- Expected: 405 + Method Not Allowed
- Observed: Expected status 405 with error containing "Method Not Allowed", got status 404 and error "Invalid action"
- Reproducible Steps:
  1. Send `GET` to `http://localhost/ad-profit/api/shops.php?action=create`
  2. Observe mismatch against expected assertion
- Root Cause (inferred): Likely in shops API action flow @api/shops.php#28-193
- Fix Recommendation:
  - Add/adjust guard or validation branch to return expected status+payload.
  - Add regression check in runner case `S02` after fix.


## Artifacts

- A) `test_cases.md`
- B) `run_log.jsonl`
- C) `report.md`
