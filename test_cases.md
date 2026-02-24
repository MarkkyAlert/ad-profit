# QA API Test Cases

- Generated at: 2026-02-24T11:44:36+07:00
- Base URL: `http://localhost/ad-profit`
- Total Cases: 65
- Flaky Criteria: first attempt fails, immediate retry (GET-only) passes

| ID | Category | Title | Expected |
|---|---|---|---|
| HP01 | Happy Path | Register user A | 200 + success=true + data.shop_id > 0 |
| HP02 | Happy Path | Logout user A | 200 + success=true |
| HP03 | Happy Path | Login user A | 200 + success=true |
| HP04 | Happy Path | Create second shop for user A | 200 + success=true + data.shop_id > 0 |
| HP05 | Happy Path | Switch back to default shop for user A | 200 + success=true |
| HP06 | Happy Path | Rename second shop for user A | 200 + success=true |
| HP07 | Happy Path | Upsert monthly goal for user A | 200 + success=true |
| HP08 | Happy Path | Delete monthly goal for user A | 200 + success=true |
| HP09 | Happy Path | Upsert daily record for user A | 200 + success=true |
| HP10 | Happy Path | Update daily record for user A | 200 + success=true |
| HP11 | Happy Path | Delete daily record for user A | 200 + success=true |
| HP12 | Happy Path | Update profile display name for user A | 200 + success=true |
| HP13 | Happy Path | Change email for user A | 200 + success=true |
| HP14 | Happy Path | Login stale session client for user A before password change | 200 + success=true |
| HP15 | Happy Path | Change password for user A | 200 + success=true |
| S06 | Security | Session revocation: stale session should be rejected after password change | 401 + error in {Session expired, Unauthorized} |
| HP16 | Happy Path | Fetch dashboard data | 200 + success=true |
| HP17 | Happy Path | Fetch annual data | 200 + success=true |
| HP18 | Happy Path | Fetch overview data | 200 + success=true |
| HP19 | Happy Path | Export CSV for selected month | 200 + Content-Type contains text/csv |
| HP20 | Happy Path | Register user B | 200 + success=true + data.shop_id > 0 |
| HP21 | Happy Path | Upsert daily record for user B | 200 + success=true |
| V01 | Validation | Register with invalid email format | 422 + error mentions invalid email |
| V02 | Validation | Register with short password | 422 + error mentions minimum password length |
| V03 | Validation | Register with password confirmation mismatch | 422 + error mentions password confirmation mismatch |
| V04 | Validation | Login with missing password | 422 + error for missing email/password |
| V05 | Validation | Create shop with empty name | 422 + error for empty shop name |
| V06 | Validation | Create shop with name length > 100 | 422 + error for shop name too long |
| V07 | Validation | Rename shop with invalid shop_id=0 | 422 + error for invalid shop id |
| V08 | Validation | Upsert goal with non-numeric target_revenue | 422 + format error for non-numeric goal |
| V09 | Validation | Upsert goal with negative target_revenue | 422 + error for negative target_revenue |
| V10 | Validation | Upsert goal with empty revenue and profit | 422 + error when both goal fields are empty |
| V11 | Validation | Upsert record with invalid date format | 422 + error for invalid record date |
| V12 | Validation | Upsert record with negative revenue | 422 + error for negative revenue |
| V13 | Validation | Upsert record with non-numeric ad_cost | 422 + parse error for non-numeric ad_cost |
| V14 | Validation | Update record with record_id=0 | 422 + error for invalid record_id in update |
| V15 | Validation | Delete record with record_id=0 | 422 + error for invalid record_id in delete |
| V16 | Validation | Update profile with empty display_name | 422 + error for empty display_name |
| V17 | Validation | Change email with invalid format | 422 + error for invalid email format |
| V18 | Validation | Change email with wrong current password | 422 + error for wrong current password |
| E01 | Edge Case | Auth endpoint with invalid action | 404 + Invalid action |
| E02 | Edge Case | Shops endpoint with invalid action | 404 + Invalid action |
| E03 | Edge Case | Goals endpoint with invalid action | 404 + Invalid action |
| E04 | Edge Case | Profile endpoint with invalid action | 404 + Invalid action |
| E05 | Edge Case | Records endpoint with invalid action | 404 + Invalid action |
| E06 | Edge Case | Dashboard data with unsupported range falls back to month_this | 200 + success=true + data.range.type=month_this |
| E07 | Edge Case | Dashboard custom range with start_date > end_date | 422 + custom date order validation error |
| E08 | Edge Case | Annual data accepts Buddhist year input | 200 + success=true |
| E09 | Edge Case | Overview data with invalid month falls back to current month | 200 + success=true |
| E10 | Edge Case | Export CSV with invalid month falls back to current month | 200 + Content-Type contains text/csv |
| S01 | Security | Auth login action via GET must be blocked by method guard | 405 + Method Not Allowed |
| S02 | Security | Shops create via GET must be blocked by method guard | 405 + Method Not Allowed |
| S03 | Security | Annual data via POST must be blocked by method guard | 405 + Method Not Allowed |
| S04 | Security | Create shop without CSRF token must be rejected | 403 + Invalid CSRF token |
| S05 | Security | Create shop with invalid CSRF token must be rejected | 403 + Invalid CSRF token |
| S07 | Security | IDOR check: user A cannot rename user B shop | 403 + authorization error |
| S08 | Security | IDOR check: user A cannot switch to user B shop | 403 + authorization error |
| S09 | Security | IDOR check: user A cannot delete record owned by user B | 422 + not found (no cross-tenant delete) |
| S10 | Security | Unauthenticated client cannot access dashboard-data | 401 + Unauthorized |
| S11A | Security | Brute-force-lite profile change_password attempt #1 | 422 with expected brute-force response |
| S11B | Security | Brute-force-lite profile change_password attempt #2 | 422 with expected brute-force response |
| S11C | Security | Brute-force-lite profile change_password attempt #3 | 422 with expected brute-force response |
| S11D | Security | Brute-force-lite profile change_password attempt #4 | 422 with expected brute-force response |
| S11E | Security | Brute-force-lite profile change_password attempt #5 | 422 with expected brute-force response |
| S11F | Security | Brute-force-lite profile change_password attempt #6 | 429 with expected brute-force response |
