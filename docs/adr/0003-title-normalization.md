# ADR 0003) Title Normalization for ReadingLists

Date: 2026-03

## Status

Accepted

## Context

ReadingLists stores page titles in the database, without any normalization, in whatever format provided by the API user.

Some page titles are stored with spaces (e.g. "United Arab Emirates") and sometimes with underscores (e.g. "United_Arab_Emirates") or can be stored both ways, resulting in duplicate entries..

Clients send titles in different formats:


| Client  | API           | Title Format                                    | Example              |
| ------- | ------------- | ----------------------------------------------- | -------------------- |
| Web UI  | Action API    | Underscores (`wgPageName`)                      | United_Arab_Emirates |
| Android | REST API      | Underscores (`prefixedText` + `addUnderscores`) | United_Arab_Emirates |
| iOS     | REST API      | Spaces (`wmf_normalizedPageTitle` replaces `_`) | United Arab Emirates |
| API     | REST + Action | Any, since the API is open to bots and tools    |                      |


A user who bookmarks a page from iOS (spaces) and then visits the same page on web will not see the bookmark icon filled, and clicking it creates a second entry for the same page.

Beyond the three known clients, the APIs are also open to bots, tools, and gadgets, which can submit titles in any format. Relying on all current and future callers to agree on a canonical form is not realistic.

DB unique constraint on `(rle_rl_id, rle_rlp_id, rle_title)` only prevents exact-string duplicates, not semantically identical pages stored under different string representations.

*Refer to [T419466](https://phabricator.wikimedia.org/T419466) for details.*

*Related: [ADR 0002 — Bloom Filter for Bookmark Lookup](0002-bloom-filter-for-bookmark-lookup.md) uses a bloom filter to avoid DB queries on page views. The bloom filter already normalizes titles to underscore-form internally, but the dual-lookup workaround in `BookmarkEntryLookupService` can be removed once title normalization is implemented.*

### Impact

1. **Duplicate entries**: The same page can appear multiple times in a list when one client saves the title with spaces and another saves it with underscores.
2. **Broken title-based lookups**: `ReadingListRepository::getListsByPage()` does an exact string match on `rle_title`, so a page saved with the title formatted differently will not be found.
3. **Bloom filter and bookmark lookup workarounds**:
  1. In the bookmark lookup implementation, `BookmarkEntryLookupService` applies a workaround with checking both `getPrefixedDBkey()` (underscores) and `getPrefixedText()` (spaces). This has some performance impact, though acceptable as a short-term workaround.
  2. The bloom filter uses canonical underscore-form title keys. During filter build, titles are normalized to underscores before insertion, and bloom filter lookups also use the underscore-form key.
4. **Inflated list size**: `rl_size` gets incremented for each entry, so duplicate pages inflate the count.

### Where normalization is missing

Both API entry points validate titles with `Title::newFromText()` but do not use the result for normalization.

The raw client-supplied string is passed through to `ReadingListRepository::addListEntry()`, which stores it as-is:

- `ApiReadingListsCreateEntry::execute()` (Action API)
- `ReadingListsHandlerTrait::createListEntry()` (REST API)
- `ReadingListRepository::addListEntry()` — the `@param` doc even says "treated as a plain string with no normalization"

Normalization is also missing on exact title-based lookup paths:

- `ReadingListRepository::getListsByPage()` compares `rle_title` using exact string equality
- `ListsPagesHandler` passes the raw request path title through to `getListsByPage()`

ReadingLists needs a canonical DB representation and canonical lookup behavior.

### Normalization dimensions

There are several ways a title string can vary for the same page:


| Variation             | Example                                         | Safe to normalize server-side?         |
| --------------------- | ----------------------------------------------- | -------------------------------------- |
| Spaces vs underscores | `United Arab Emirates` / `United_Arab_Emirates` | Yes — locale-independent, lossless     |
| First-letter case     | `formula one` / `Formula one`                   | Yes for `@local`, risky for cross-wiki |
| Namespace aliases     | `WP:Foo` / `Wikipedia:Foo`                      | Yes for `@local`, risky for cross-wiki |
| Unicode normalization | different codepoints, same glyph                | MediaWiki's `Title` class handles NFC  |


**Cross-wiki limitations:**

ReadingLists are cross-wiki by design, allowing pages to be saved across wikis and stored in one place.

Titles from other wikis cannot be fully normalized on the backend, because the server does not have configuration for a different wiki's title configuraiton.

Rules such as first-letter capitalization, namespace names and aliases, legal interwiki prefixes, and some parsing behavior are wiki-specific. Applying the local wiki's `Title` rules to a title from some other wiki could incorrectly rewrite the title, fail to recognize an alias that is valid on the source wiki, or treat two distinct foreign titles as equivalent when they are not.

The original code comment acknowledges this:

> "We do not normalize, that would contain too much local logic (e.g. title case), and clients are expected to submit already normalized titles (that they got from the API) anyway."

## Considered actions

### Option A: Use underscore-form for DB storage and exact-match lookups, return space-form in API responses

Convert spaces to underscores in the page titles when storing in the database and when doing exact-match lookups, and convert to spaces in API responses.

#### Advantages

- Locale-independent, safe for cross-wiki titles
- API response format remains presentation-friendly
- No mobile app changes needed — iOS already expects spaces, Android handles both formats
- DB format is consistent with MediaWiki's `page_title` convention

#### Downsides

- Does not fix capitalization or namespace-alias duplicates for cross-wiki entries; for those cases, the system still relies on clients to provide correctly normalized titles. For `@local` entries, stronger server-side normalization via `Title::newFromText()` remains an open option that is not chosen in this ADR.
- Existing data needs a migration or cleanup script
- Requires touching both write and lookup paths, not just insertion
- Needs one conversion on write, one normalization step for exact-match lookups, and conversion in API response builders

### Option B: Local-only normalization with `Title::newFromText()` for `@local and Option A for non-local ReadingLists entries.`

For reading list entries that belong to the local wiki (including the `@local` shorthand), use `Title::newFromText( $title )->getPrefixedDBkey()` to normalize. This is safe for `@local` entries because the server has the correct title configuration (first-letter capitalization, namespace names and aliases) for its own wiki. The API already calls `Title::newFromText()` to validate incoming titles. This approach would additionally use the parsed result to normalize before storage.

For cross-wiki entries, continue using the safer spaces-to-underscores normalization from Option A, since the server does not have title configuration for other wikis.

#### Advantages

- Fixes spaces, first-letter case, namespace aliases, and Unicode for local titles
- Matches what MediaWiki would do if you pasted the title into the URL bar for `@local` entries
- Builds on the recommended baseline instead of replacing it

#### Downsides

- Two normalization paths (local vs cross-wiki) adds complexity
- Requires detecting whether a project is local, which `ReadingListRepository` can already do via `getProjectId('@local')` resolving to `getLocalProject()`
- Applies local wiki title rules, which are not safe to assume for arbitrary cross-wiki entries
- `Title::newFromText()` returns null for invalid inputs (e.g. titles containing `<`, `#`, etc.). The API handlers already reject these before reaching the repository, so in practice the parse always succeeds by the time we'd normalize — but the ordering dependency (validate first, then normalize) needs to be maintained
- Capitalization normalization means a client sending `formula one` gets back `Formula_one` — which is correct but might surprise some callers
- If Option B is adopted at a later time after Option A, it may require a follow-up deduplication pass for existing `@local` rows that become newly equivalent under stronger normalization.

## Decision

We decided on Option B, which extends the normalization approach outlined in Option A to include full `Title` normalization for local project titles.

For local projects (`@local`), apply stronger normalization using `Title::newFromText()`:

- **Normalize titles on write using `Title::newFromText($title)->getPrefixedDBkey()`** — this handles spaces/underscores, first-letter capitalization, namespace aliases, and Unicode normalization, using the local wiki's title configuration
- **Apply the same normalization on exact-match lookups** — `getListsByPage()` and `ListsPagesHandler` normalize the query title before querying the DB
- **Return titles with spaces in the API response**
- **The API handlers already call `Title::newFromText()` for validation** and rejects invalid titles before reaching the repository, so the parse is guaranteed to succeed at normalization time

For non-local (cross-wiki) projects, apply Option A's space-to-underscore normalization:

- **Store titles with underscores in the database** — The `page` table stores titles with underscores (`page_title` uses `getDBkey()` / `getPrefixedDBkey()`)
- **Apply the same space-to-underscore canonicalization to exact title-based lookups before querying** — repository lookups should compare canonical DB-form titles, not raw client strings
- **Return titles with spaces in the API response** — use a display-oriented form on read, so clients continue receiving human-readable titles

This proposal separates storage normalization from API presentation, and applies the same canonical form consistently to both writes and exact-match lookups.

### Why this approach

- **No mobile app changes needed.**
  - Android formats page titles for display, applying space-to-underscore transformation for the page titles coming from the API.
  - iOS is directly displays the page titles, as they come from the API, and having the page titles formatted with spaces would work.
- **Consistent internal representation.** Database writes and reading list entry lookup use the same canonical form.
- **Consistent with common MediaWiki patterns.** A common MediaWiki pattern is to use underscore-form titles for canonical internal keys and storage, and space-form titles for user-facing API output.
- **Avoid duplicate entries.** The database unique constraint will prevent duplicates if the page titles use a consistent format.
- **Workaround-specific logic can be removed.** The dual-lookup hack in `BookmarkEntryLookupService` becomes unnecessary once storage and lookup are canonical. The bloom filter should continue to use canonical underscore-form keys, but that keying will no longer be compensating for mixed stored data.
- **Server-side normalization is strictly more reliable** than depending on all current and future clients to agree on a format.

### Implementation

#### 1. Normalize on write

In `ReadingListRepository::addListEntry()`, before the DB write:

```php
$title = strtr( $title, ' ', '_' );
```

#### 2. Normalize on lookup

In exact title-based lookup paths, normalize the input title to the same canonical DB form before querying. At minimum this includes:

- `ReadingListRepository::getListsByPage()`
- the REST `/lists/pages/{project}/{title}` flow via `ListsPagesHandler`

#### 3. Format on read

In `ReadingListsHandlerTrait.php and ApiTrait.php`:

```php
// Before:
'title' => $row->rle_title,
// After:
'title' => strtr( $row->rle_title, '_', ' ' ),
```

#### 4. Remove workaround-specific logic

After this change, the following workarounds can be removed:

- `BookmarkEntryLookupService::getBookmarkEntry()` dual lookup (lines 40-47)
- For building the bloom filter, we should still use canonical underscore-form keys for both build and lookup

#### 5. Migration

Existing data in the DB has mixed formats. A maintenance script should:

1. Identify entries where `rle_title` contains spaces
2. Check if an underscore-form duplicate already exists for the same `(rle_rl_id, rle_rlp_id)`
3. If duplicate exists: soft-delete the space-form entry and adjust `rl_size`
4. If no duplicate: update `rle_title` in place with underscores

This should run as a batched update to avoid locking issues on large tables, following MediaWiki's maintenance script patterns.

**The migration should use `strtr($title, ' ', '_')` only, not `Title::newFromText()`.**

Applying full title normalization for existing saves pages is not appropriate for several reasons:

- **Deleted and moved pages**: The script would be doing too much with too many potential issues with trying to apply full Title normalization. Users may have bookmarked pages that have since been deleted, moved, or had their namespace configuration changed. `Title::newFromText()` can return null for titles that were valid when originally saved. The migration should not silently drop or skip these entries.
- **We can run the script once from any wiki**: This approach would not rely on wiki-specific configs and can run the script from testwiki, and fix normalization for all saved pages.

Option B's stronger normalization (`Title::newFromText()` for `@local` entries) applies to new writes going forward. The lookup path also normalizes queries, so old `@local` rows with correct underscores but non-canonical capitalization (e.g. `formula_one` vs `Formula_one`) will still be found by lookups. A follow-up cleanup script for `@local` capitalization deduplication can be considered later if the data shows it matters, but is not required for functional correctness.

### Mobile app impact

With this approach, no changes are needed in the mobile apps:

Today, the mobile clients do not send the same title format:

- iOS sends space-form titles to the ReadingLists API.
- Android sends underscore-form titles to the ReadingLists API.
- The backend stores the raw incoming title string, which is why mixed-format rows exist today.

Under Option A, the server would normalize both input forms to underscore-form storage and return space-form titles in API responses. The mobile impact is therefore about compatibility with that server-side change, not about changing current client behavior.

**iOS**

- iOS already sends space-form titles, so server-side normalization would accept its current requests without app changes.
- If the API returns space-form titles, that remains compatible with iOS's current behavior.
  - Sync identity remains stable because iOS derives article keys from a canonical URL / database-key form.
  - For display, iOS largely uses the API-provided title as-is, so space-form output continues to work. A more defensive client-side display normalization, similar to Android, could still be added later, but is not required for the ReadingLists API and DB changes in Option A.

**Android**

- Android already sends underscore-form titles, so server-side normalization would accept its current requests without app changes.
- If the API returns space-form titles, Android's title handling still normalizes them for internal matching and display, so no app change should be needed.

## Consequences

- The DB unique constraint on `(rle_rl_id, rle_rlp_id, rle_title)` will prevent duplicate entries for the same page, since all titles are stored in a consistent format.
- The dual-lookup workaround in `BookmarkEntryLookupService` can be removed, simplifying the bookmark lookup path.
- Existing data with mixed formats requires a one-time migration script to normalize stored titles and deduplicate entries.
- No mobile app changes are needed — both iOS and Android are compatible with the server-side normalization and space-form API responses.
- Capitalization and namespace-alias duplicates remain possible for cross-wiki entries. If this becomes a problem for `@local` entries, Option B can be adopted as a follow-up.

