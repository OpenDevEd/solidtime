# Harvest API import

## Current implementation

Owners and administrators can fetch a source snapshot, resolve matches, review
field changes, and confirm a transactional import. Harvest requests are GET-only.
Business records change only after confirmation.

Set these server-side variables:

```dotenv
HARVEST_ACCESS_TOKEN=
HARVEST_ACCOUNT_ID=
HARVEST_ORGANIZATION_ID=
```

The organization value must be the destination Solidtime organization's UUID.
Only its owner and administrators can access the configured connection. Other
organizations cannot fetch its source data, including by guessing an import-run
UUID. The token is never sent to the browser. No Harvest OAuth client is needed.

Run migrations and start the dedicated database queue worker:

```sh
php artisan migrate
php artisan queue:work harvest --queue=harvest --timeout=1200 --tries=1 --memory=1024
```

The queue uses the existing jobs table and its own 1,500-second retry window,
longer than the worker's 1,200-second timeout. Restart workers after changing
server configuration or code. The database, private storage and worker must all
belong to the same environment.

Visit Import / Export and choose **Fetch Harvest preview**. The request queues
work and returns immediately. Refreshing the page resumes status display.
Concurrent previews for one organization are rejected. A failed run remains
visible and a new preview can be requested. A queued run requires a worker;
repeatedly clicking the button is not a substitute for starting one.

The worker fetches company information, people including archived accounts,
clients, projects, tasks, user assignments, task assignments, time entries,
expenses and expense categories. It follows cursor links, refuses redirects to
other hosts, and checks page totals and source-ID uniqueness. Identical content
with different source IDs remains distinct. Zero-hour and negative entries are
preserved in the snapshot and counted in the preview.

Each run saves raw JSON pages and a checksum manifest on the configured private
disk. A partial or failed run is not a complete snapshot. The API does not offer
an atomic cross-collection snapshot; the UI explicitly reports that limitation.
Run metadata and summaries are stored in `harvest_import_runs`.

Build the import plan after fetching. Exact email and existing Google mappings
identify accounts, including accounts already belonging to another organization.
Names suggest placeholder matches that require explicit review. The bulk
suggestion button selects only unique placeholder candidates. Duplicate source
identities can explicitly share a person using one canonical Google email.
Creating a placeholder does not verify its email or give it a password. Google
login claims the same record through the existing authentication flow.

## Matching and confirmation

Mappings persist source account, entity type and source ID, plus the last source
field values. Reimports retain target IDs and compare source changes with local
edits. When both sides change a field, review must choose local or source values.
Existing real-user profiles and member roles, including ownership, are preserved.

Existing imported time entries are matched by person, date, duration, project,
task and normalized notes. Matching handles Harvest CSV formula escaping and
preserves distinct identical occurrences. Remaining same-person/day candidates
require explicit review, because an old CSV cannot prove a source entry ID.
Unmatched local entries must be acknowledged and are never deleted.

The plan displays create, link, alias, update and unchanged counts, paginated
before/after changes, and unresolved conflicts. Confirmation requires its exact
hash and acknowledgement of limitations. The worker locks the organization and
target rows, checks administrator access again, rebuilds the plan, and rejects a
changed plan. Business writes, mappings and completion status commit together.
Duplicate confirmation does not enqueue another apply job.
An older snapshot cannot be applied after a newer snapshot has completed.

Project names include the Harvest code as `[code] Project name`, without adding
the same code twice. Reimports preserve manually edited names through the same
field-level comparison used for other project fields.

For first-time adoption of renamed projects, the review suggests same-client
matches using normalized name tokens, bracketed codes, and matching nonzero
time-entry occurrences (person, date, duration, task name and normalized notes).
Codes and similar names are not unique identities. Suggestions always require
explicit review, including when there is only one candidate. Evidence is shown
beside the choices; unrelated-client and already-claimed targets are excluded.
Reviewed renamed-project matches retain the local name while storing the source
name as a comparison baseline. Future imports use the saved source-ID mapping.

Imported fields include project active status, billability and hourly rates;
project tasks and active user assignments; signed time durations, notes,
historical billing/cost rates and source currencies. Zero is not treated as a
missing rate. Date-only entries use midnight UTC. No exchange conversion occurs.
Project/task spent totals are recalculated after import.

Expenses and categories, monetary/monthly budgets, approval/invoice state, and
project metadata without native fields stay in the private source snapshot.
They do not become native Solidtime records. Running timers and inactive user
assignments are skipped; existing local access is not removed. Source absence
never triggers deletion. Hour-based non-monthly project budgets can populate
the native estimated-time field.

## Verification

```sh
php artisan test tests/Feature/HarvestImportTest.php tests/Feature/HarvestApiPreviewTest.php tests/Unit/Endpoint/Api/V1/ImportEndpointTest.php
npm run type-check
npm run build
```

The tests use a separate PostgreSQL database, HTTP fixtures for fetching and
private snapshot fixtures for writing. They cover authorization, pagination,
source multiplicity, incomplete snapshots, adoption, account claims, aliases,
reimport/local edits, stale plans and transaction rollback on database failure.
