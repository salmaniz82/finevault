# Fineworth — Laravel 13 prototype

29 October 2026 addition: client dashboard onboarding review activities and an admin-only DocuSign sandbox demonstration are now available. Sample financial figures appear only in the fictional signing demo agreement. See `DOCUSIGN_DEMO.md` for the walkthrough, consent setup and live-verification limitation.

Traditional Blade views, session authentication, plain CSS/JavaScript, MySQL and private file storage. No Inertia, React, Filament, payments, price fields or price history. Installed framework version: Laravel 13.39.0.

## Run locally

From `D:\codex-projects\fineworth-portal\application`:

```powershell
composer install
php artisan migrate
php artisan db:seed
.\start.ps1
```

Open <http://127.0.0.1:8000/login>. Start MySQL in Wamp first. The local `.env` uses the connection supplied in `../db.md`: database `fineworth-portal`, root, blank password, loopback only. For a fresh checkout, copy `.env.example` to `.env`, set that connection and run `php artisan key:generate`. The start script sets the PHP upload limits so the advertised 10 MB files work. `php artisan serve` also works for small uploads, but the installed PHP configuration otherwise limits uploads to 2 MB.

## Sample credentials

All sample accounts use `FineworthDemo!27`:

| Role | Email | Access |
| --- | --- | --- |
| Admin | admin@fineworth.test | All clients, manager assignment and approval |
| Manager | manager@fineworth.test | Oliver Grant; assigned to Sophia |
| Client | client@fineworth.test | Sophia Bennett; 2 casks, 66 wine bottles in 4 wine holdings |
| Other manager | amelia@fineworth.test | Amelia Clarke; assigned to James |
| Other client | james@fineworth.test | James Whitmore; separate cask and vault |

The login page offers buttons to fill the first three sample credentials. These are fictional local demonstration accounts. Initial passwords for accounts created through the admin UI are supplied directly by the admin; verification and email delivery are not implemented.

## Walkthrough

1. Sign in as admin. Open Managers and create a manager; open Clients and create a client with an initial password. Assign the manager immediately or from the client profile.
2. Sign in as manager. Open an assigned client and add a holding with acquisition/storage details. Wine records count bottles; whisky records represent one cask, with optional fill date, type, ABV and bulk litres.
3. Upload a PDF/JPG/PNG with title, category, document date, audience and optional holding. It is pending and unavailable to the client. To demonstrate replacement history, select an approved document under “New version of” and retain its client, holding, category and audience.
4. Sign in as admin. Open Review & approvals, download the pending file and approve it. Returning a submission requires a reason; a corrected submission is uploaded as a new record. Approved records cannot be edited, rejected again or overwritten.
5. Sign in as client. Open the holding and its document history. Earlier approved versions remain available. Staff-only and KYC records are excluded server-side. Client onboarding uploads have their own status list and remain private to staff.
6. Submit an evaluation, regauge, inspection or photograph request. Regauge is available only for whisky. The assigned manager can update status and add a client-visible note; the progress history is retained.
7. A manager can propose a client display-name correction. The name changes only when admin approves it.

## Implementation boundaries

`app/Services/Portal.php` centralises account and record access and approval logic. Server controllers validate role and ownership on every action. Files live in `storage/app/private/documents`, never under the public directory; local signed serving is disabled and download routes check authorisation. Sessions are encrypted in local configuration; login is throttled, forms use CSRF tokens, and authenticated responses are not cached.

The database contains authentication tables and a deliberately generic `portal_records` JSON store, **not a final business schema**. Manager assignments are a provisional array. No ownership fractions, transfers, storage-site entity or legal-owner cardinality has been decided. Admin may approve their own uploads in this demonstration. See `../docs/PROTOTYPE_BUILD.md` and `../docs/OPEN_QUESTIONS.md` for unresolved business decisions. Real-data migration, production infrastructure, mail/password-reset delivery, full audit/compliance retention and production security review remain outside this prototype.

## Verification

```powershell
php artisan test --compact
php artisan view:cache
```

Feature tests use isolated in-memory SQLite and fake private storage; they never reset the demonstration MySQL database. They cover role screens, login/logout, manager reassignment, record isolation, private/KYC files, upload validation, document approval/version history, requests and profile approval. Browser checks also cover the live MySQL application, desktop and narrow layouts, request submission and document review.

The seeder is additive and safe to rerun; it does not reset demonstration work. Do not use `migrate:fresh` on a database containing records you want to keep.
