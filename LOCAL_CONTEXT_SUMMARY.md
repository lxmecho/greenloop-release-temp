# Local Project Context Summary

Generated at: 2026-04-20 12:54:17

## Snapshot

- Users: 8
- Items: 8
- Applications: 2
- Redemptions: 2
- Pending review: 0
- Dropoff ready: 0
- Pickup scheduled: 0
- Completed: 7
- Active announcement: 关于物品兑换

## Runtime URLs

- Home: `/index.php`
- Submit item: `/index.php?page=submit`
- Points: `/index.php?page=points`
- User auth: `/index.php?page=login`
- Hidden admin login: `/index.php?page=xmu-greenloop-admin-6f9c2d71`
- Admin console: `/index.php?page=admin` (after admin login)

## Current Product Rules

- Disposal types: fixed pickup point + door pickup.
- Account key: phone number.
- Points: determined by final reference price.
- Admin entry is hidden behind `ADMIN_LOGIN_PAGE`.

## Quick Continuation Checklist

- Verify homepage announcement content is up to date.
- Verify register/login flow and one-phone-input UX.
- Verify submit page only exposes recycle + door pickup options.
- Verify admin can edit announcements and review flows.

## Note about compact errors

- If remote compact fails, keep using this file as local continuation context.
- Regenerate by running: `php scripts/local_context_compact.php`
