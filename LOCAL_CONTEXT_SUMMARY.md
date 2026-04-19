# Local Project Context Summary

Generated at: 2026-04-15 16:16:38

## Snapshot

- Users: 8
- Items: 7
- Applications: 2
- Redemptions: 2
- Published donations: 1
- Pending review: 0
- Dropoff ready: 0
- Completed: 6
- Active announcement: 关于物品兑换

## Runtime URLs

- Home: `/index.php`
- Public listings: `/index.php?page=listings`
- Submit item: `/index.php?page=submit`
- Points: `/index.php?page=points`
- User auth: `/index.php?page=login`
- Hidden admin login: `/index.php?page=xmu-greenloop-admin-6f9c2d71`
- Admin console: `/index.php?page=admin` (after admin login)

## Current Product Rules

- Disposal types: donation + fixed pickup point only.
- Account key: phone number.
- Points: +5 per approved submission.
- Admin entry is hidden behind `ADMIN_LOGIN_PAGE`.

## Quick Continuation Checklist

- Verify homepage announcement content is up to date.
- Verify register/login flow and one-phone-input UX.
- Verify listing card image uses full-display mode (`object-fit: contain`).
- Verify admin can edit announcements and review flows.

## Note about compact errors

- If remote compact fails, keep using this file as local continuation context.
- Regenerate by running: `php scripts/local_context_compact.php`
