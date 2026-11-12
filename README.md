# Fineworth Portal — Laravel 13 application

Read the project [AGENTS.md](../AGENTS.md) for requirements, design, architecture and open decisions. Detailed historical guides are preserved in `../references/docs-archive-2026-11-11/`; consult them only when needed.

Run `php artisan test --compact` to verify role access and document workflows. Start the local application with `.\start.ps1`. Do not reset the working database.

The development-server workflow seeds the [local demo baseline](database/seeders/fixtures/staging/README.md) after pending migrations. It restores missing demo users before legacy backfill, preserves existing staging rows/files, and requires explicit staging demo opt-in. Production deployment and database resets are outside this workflow.
