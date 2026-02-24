# W1-W5 Fixes Summary

This document tracks the concrete code changes applied to address W1-W5 from the Risk Map.

## W1) Rename Shop - Atomic/Lock
- File: `app/Services/ShopService.php`
- Change: `renameShop()` now uses transaction + user/shop row locks (when PDO is available) and returns a clearer error on UNIQUE constraint violation.

## W2) Change Email - Atomic/Lock
- File: `app/Services/ProfileService.php`
- Change: `changeEmail()` now optionally uses transaction + user row lock (when PDO is available).
- File: `api/profile.php`
- Change: Pass `$pdo` into `ProfileService`.

## W3) Change Password - Atomic/Lock
- File: `app/Services/ProfileService.php`
- Change: `changePassword()` now optionally uses transaction + user row lock, and updates `password_hash` + `session_version` atomically.

## W4) Delete Record - Better confirmation
- File: `history.php`
- Change: Confirmation message now includes the record date.

## W5) PRG/Double-submit protection
- File: `includes/footer.php`
- Change: Global JS now prevents double-submit by tagging submitted forms and disabling submit buttons; also prevents confirm-modal bypass via double-click.
