# Implementation for CSV Export of Filtered Contacts

## Goal

Ping CRM's Contacts index (`app/Http/Controllers/ContactsController.php::index()`) paginates 10 contacts per page and supports `search` and `trashed` filters via `Contact::scopeFilter()`. There is currently no way to export contacts. The goal is an "Export as CSV" action that:

- Exports only the contacts matching the **currently applied filters** (search / trashed) — not the entire account's contact list.
- Exports **all matching rows across all pages**, not just the 10 shown on the current pagination page.

This is a greenfield feature: no export/download/CSV code exists anywhere in the app today, and no CSV library (e.g. `league/csv`) is installed. Per the project's minimal-dependency stance, use native PHP's `fputcsv()` streamed via Laravel's `StreamedResponse`, requiring **no new composer package**.

## Required Changes (in order)

### 1. Backend: new `export()` action on `ContactsController`

File: `app/Http/Controllers/ContactsController.php`

Add a new method reusing the exact same tenant-scoping and filter scope as `index()`, but streaming *all* matching rows (no `paginate()`), sorted the same way (`orderByName`):

### 2. Route

File: `routes/web.php`

Add directly under the existing `// Contacts` block, following the same flat, non-grouped convention as every other contacts route.

### 3. Frontend: Export button on the Contacts index page

File: `resources/js/Pages/Contacts/Index.vue`

- Add an "Export" link next to the existing "Create Contact" `Link`, but as a **plain `<a>` tag** (not Inertia's `<Link>` / `$inertia.get`) — the response is a real file download, not an Inertia page visit, and routing it through Inertia's XHR-based navigation would break since the export route never calls `Inertia::render()`.
- Build its `href` from the same filter state already tracked in `form` (`search`, `trashed`), reusing the existing `pickBy` import so the exported CSV always matches what's currently on screen:

### 4. New icon

File: `resources/js/Shared/Icon.vue`

Add a `download` branch (new `v-else-if="name === 'download'"` with an SVG path, `viewBox="0 0 20 20"` to match the existing icons) — none of the current icon set (cheveron-down, cheveron-right, dashboard, office, printer, trash, users) fits an export/download action.

### 5. Tests

File: `tests/Feature/ContactsTest.php` (extend existing file — it already seeds an `Account`, a `User`, an `Organization`, and two contacts in `setUp()`; follow that fixture pattern for new cases)

## Definition of Done

In order for the feature to be considered done, it must pass all the following scenarios (use them as test cases):

1. Guest hitting `contacts/export` is redirected to login (mirrors existing auth-middleware coverage for `contacts`).
2. Authenticated export returns `200` with `Content-Type: text/csv` and a `Content-Disposition: attachment` header containing a `.csv` filename.
3. Exported CSV content matches seeded contacts: header row + expected field values per row.
4. Filter parity: `?search=...` narrows the CSV to matching contacts only; `?trashed=only` includes a soft-deleted contact (and excludes it when no `trashed` param is passed), matching `index()`'s behavior exactly.
5. Tenant isolation: a contact belonging to a different `Account` never appears in the export regardless of filters.
6. Pagination bypass: seed more than 10 matching contacts (the default page size) and assert the CSV contains all of them, not just 10 — proving the export ignores pagination.

## Files touched

- `app/Http/Controllers/ContactsController.php` — add `export()` method
- `routes/web.php` — add `contacts/export` route
- `resources/js/Pages/Contacts/Index.vue` — add Export link + `exportUrl` computed property
- `resources/js/Shared/Icon.vue` — add `download` icon
- `tests/Feature/ContactsTest.php` — add export test cases

## Verification

Automated (via tests):

- `php artisan test --filter ContactsTest` — all existing + new export tests pass.

Manual:
- Check via `php artisan serve` + `npm run dev`: apply a search filter and/or "Only Trashed", click Export, confirm the downloaded CSV's rows match only the filtered set and include contacts beyond the first 10 (seed >10 matching contacts to verify pagination is bypassed).
- Confirm tenant isolation manually: a second account's contacts never appear in the first account's export.
