# Staging demo baseline — 12 November 2026

Snapshot of the local fictional Fineworth dataset requested for staging: six
users, 29 portal entries, related typed rows and 15 referenced demo PDF paths
(some share identical bytes). Files are copied into private storage, never the
public web root. Do not use these fixtures with real client data.

Login password hashes, remember tokens, sessions, cache, reset tokens, queued
jobs, environment credentials and private database backups are excluded.
New accounts receive an undisclosed random password; use the application's
account administration to set access. Existing staging passwords are preserved.

The development-server workflow clears cached config, enables staging demo
seeding, initialises the two original migrations when needed, seeds missing
users before legacy backfill, runs pending migrations, runs `db:seed --force`,
and verifies relational records. No `migrate:fresh`, rollback, truncate, table
drop or foreign-key disabling runs on the server. No new migration is required
to repair missing source users.

Existing rows are preserved. Matching IDs must have matching ownership and
domain identities; ID/email collisions or different private file bytes stop
deployment for explicit reconciliation. This is an additive baseline, not a
database synchronisation tool that overwrites staging changes. Repeated runs
do not duplicate data or reset onboarding, signing or release state.

Each seed saves current relevant database rows (including legacy rows and
migration history) under private `backups/staging-seed/` as JSON. These are
recovery material, not a substitute for the host's full database/file backup.
Back up staging before deployment. On failure the workflow leaves maintenance
mode enabled; investigate the reported conflict, then rerun the workflow.

`scripts/export-staging-demo.php` creates a snapshot only when its destination
does not yet exist, from a local connection, and refuses non-demo users or live
signing references. Review any future replacement explicitly; never export
real client files into Git.
