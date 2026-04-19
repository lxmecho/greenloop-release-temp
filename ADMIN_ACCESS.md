# Admin Access

The admin login page is intentionally hidden behind a custom path.

## How to set the hidden path

In `config.php`, set:

```php
define('ADMIN_LOGIN_PAGE', 'your-random-admin-path');
```

Example:

```php
define('ADMIN_LOGIN_PAGE', 'xmu-greenloop-admin-6f9c2d71');
```

## Admin login URL format

`https://your-domain.com/index.php?page=<ADMIN_LOGIN_PAGE>`

Local example:

`http://127.0.0.1:8000/index.php?page=xmu-greenloop-admin-6f9c2d71`

## Default seeded admin account

- Phone: `18800000000`
- Password: `admin123456`

Change this password immediately after deployment.

