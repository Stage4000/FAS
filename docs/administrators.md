# Multiple administrators

Open **Admin → Administrators** (`/admin/administrators.php`) as an active administrator. Each person has a separate username, email address, and password. New administrators have full panel access, including account management. No invitation email is sent; share their initial credentials privately.

Confirm your current password before adding an account, editing a name/username/email, resetting another administrator's password, or deactivating/reactivating an account. Confirmation lasts ten minutes. Account mutations require CSRF and use a shared budget of 10 attempts per administrator per 300 seconds. The Security page displays that rule and its activity. Password confirmation uses the existing reauthentication rule. The Account page keeps a 20-entry actor/action audit without passwords or request bodies.

Passwords are hashed. Newly set passwords must be 12–72 bytes; the limit prevents bcrypt truncation. Existing passwords keep working until changed. Usernames remain case-sensitive for sign-in. New or edited usernames and emails are checked for case-insensitive duplicates. An administrator may edit their own name, username, and email, but must use **Change Password** for their own password and cannot deactivate themselves.

Deactivation blocks login and invalidates all existing sessions, including storefront previews and admin-only wallet access. Resetting a password also invalidates existing sessions. Reactivation never restores sessions from before deactivation. The current administrator remains signed in after changing their own password; their other sessions are revoked. Two simultaneous account actions cannot deactivate the last active administrator.

## Deployment

Deploy the account helper, authentication changes, admin page/sidebar, security rule, and schema together. The existing SQLite `admin_users` records are preserved. The helper creates the additive `admin_account_security` and `admin_account_events` tables when the panel starts; PHP needs write access to the SQLite database for that first initialization. Back up the inventory database before deployment. Verify one existing account can log in, then create a second test administrator and check independent login, deactivation, reactivation, and password reset. Existing sessions issued before deployment lack the new account binding and must sign in again. No account is created or modified in production by this local implementation. The new account-change limit is observed until global security enforcement is activated after the checks in [security.md](security.md).

Fresh installations use the CLI-only `php admin/init-admin.php` bootstrap with `FAS_INITIAL_ADMIN_USERNAME`, `FAS_INITIAL_ADMIN_EMAIL`, and `FAS_INITIAL_ADMIN_PASSWORD` set in the server environment. The script no longer creates a known default password or runs from a web request. Existing installations keep their accounts and do not need to rerun it.

Local checks:

    php tests/admin-accounts-test.php
    python tests/admin-accounts-http-test.py
    python tests/product-content-http-test.py

The HTTP fixture uses disposable accounts and disables PHP mail. It does not verify production sessions or email delivery.
