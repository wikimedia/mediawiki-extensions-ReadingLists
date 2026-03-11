# ADR 0002) Bloom Filter for Bookmark Lookup

Date: 2026-04

## Status

Accepted

## Context

The ReadingLists bookmark button is rendered on every page view. To determine whether it should be displayed as "Save page" or "Unsave", we need to check if the current page is in a user's reading list. This currently requires a database query against the `reading_list_entry` table, which is not sustainable to do on every page view as ReadingLists scales to more users.

*Refer to [T400361](https://phabricator.wikimedia.org/T400361) for details.*

*Related: [ADR 0003 — Title Normalization](0003-title-normalization.md) addresses the lack of title normalization on write, which currently requires the bloom filter to normalize titles to underscore-form during filter build and use a dual-lookup workaround for DB queries.*

## Considered actions

### Bloom filter

Use a **bloom filter**, which is a probabilistic data structure that can definitively say "this page is NOT bookmarked" but can produce false positives for "this page is probably bookmarked."

Since most pages a user visits are not in their reading list, the filter returns a definitive "not bookmarked" for the vast majority of page views, avoiding the database query entirely.

For bookmarked pages and the rare false positive, a database query still happens to confirm the result, but the volume of these queries is much smaller than querying on every page view.

#### Advantages

- Avoids a database query on the vast majority of page views.
- Very compact data structure — ~16 KB for a JSON-serialized filter covering 10,000 titles, compared to ~500 KB+ for a full title set.
- Well within memcached's per-key size limits.
- Low false positive rate (1%) means very few unnecessary DB queries.

#### Downsides

- More complexity, with async process via a job to rebuild the bloom filter.
- False positives still require a DB query to confirm.
- Users exceeding the configured max items limit fall back to direct DB queries.

### Store bookmarked page titles or page IDs in memcached

Cache the full set of bookmarked titles (or page IDs) in memcached and check membership directly. This would give exact results with no false positives.

#### Advantages

- Exact results with no false positives.
- Simpler conceptual model — direct set membership check.

#### Downsides

- For users with a very large amount bookmarked pages, the cached data set could approach storage limits of Memcached. MediaWiki's `MemcachedBagOStuff` sets a segmentation threshold at ~896 KB (917,504 bytes); values exceeding this are split across multiple memcached keys and reassembled on read, adding overhead and fragility since any segment being evicted or expiring breaks the whole value.
- Larger payload increases network transfer time and deserialization cost on every page view.

## Decision

We decided to use a bloom filter, implemented in `BookmarkEntryLookupService` using the `pleonasm/bloom-filter` PHP library. This library has previously been used by Wikimedia for the common passwords library, though has since been removed since the bloom filter solution was non-optimal for that use case.

### Lookup process

1. Read the user's bloom filter from WANObjectCache using a stable key plus a WANObjectCache check key.
2. If the cache entry is missing, stale, or incompatible, queue an async rebuild and fall back to the DB lookup for this request.
3. If the bloom filter is available, check if the current page's prefixed DB key (`Title::getPrefixedDBkey()`, e.g. `Talk:United_Arab_Emirates`) exists in the filter, with two possible outcomes:
  - **Definitely not in reading list**: return null immediately, no DB query needed.
  - **Probably in the reading list**: query the database to confirm it is not a (rare) false positive.

This is intentionally implemented as a cache-aside pattern using `WANObjectCache::get()` plus manual validation, rather than `getWithSetCallback()` on the page-view path.

### Building the bloom filter

The filter is rebuilt asynchronously by `BuildBloomFilterJob`, rather than synchronously on cache miss during a page view. The rebuild queries all bookmarked page titles for the user on the local project, adds each to a new `BloomFilter`, serializes it with `buildBookmarkedPagesBloomFilter`, and stores it in WANObjectCache with a TTL of one week.

The async rebuild reads from `READ_LATEST` and then calls `WANObjectCache::set()`. This follows MediaWiki's backend performance guidance to keep cache-miss work cheap on the request path and move more expensive refresh work to jobs when possible.

### Cache invalidation

Invalidation uses WANObjectCache's **check key** mechanism. When a user adds or removes a bookmark (with either Action API or REST), `invalidateBookmarkBloomFilter` touches a check key and queues `BuildBloomFilterJob`.

On the next page view, `WANObjectCache::get()` treats cache entries older than the check key as stale. In that case, ReadingLists falls back to the exact DB lookup for the current page and relies on the queued job to repopulate the cache.

### Configuration

- `$wgReadingListsBloomFilterMaxItems` - Maximum number of titles to include in the filter. (default is 10k) Users exceeding this limit skip the bloom filter and fall back to direct DB queries. When a user exceeds this limit, the result is cached for one week so we don't re-run the query on every page view; it is invalidated via the same check key when bookmarks change. Transient bloom filter build failures (e.g. database errors) are cached for five minutes before retrying.
- The false positive rate is a class constant (1%), since it is unlikely we need to configure this and do not need to over-complicate extension configuration.

### Limitations

Users with more than `$wgReadingListsBloomFilterMaxItems` bookmarked pages do not benefit from the bloom filter and always query the database. The configured limit (default 10,000) covers Wikimedia's per-list limit of 5,000 entries across up to 100 lists with deduplication, so in practice very few users should exceed it.

## Consequences

The bloom filter eliminates the need for a database query on most page views, allowing ReadingLists to scale to more users without proportionally increasing load on the `x1` database cluster. The bloom filter data is small enough (~16 KB) to store efficiently in memcached, and async rebuilds via the job queue keep the page-view path lightweight.

As the feature scales, we may need to revisit the max items limit or the false positive rate if usage patterns change significantly.

### References

- [Memcached default 1 MB item size limit](https://docs.memcached.org/serverguide/configuring/) - memcached's `-I` flag controls the maximum item size; the default is 1 MB.
- `MemcachedBagOStuff` [segmentation threshold (917,504 bytes)](https://gerrit.wikimedia.org/g/mediawiki/core/+/master/includes/libs/ObjectCache/MemcachedBagOStuff.php) - MediaWiki sets the segmentation size just under 1 MiB.
- [Memcached for MediaWiki (Wikitech)](https://wikitech.wikimedia.org/wiki/Memcached_for_MediaWiki) - Wikimedia production memcached infrastructure and WANObjectCache documentation.
- [Backend performance practices](https://wikitech.wikimedia.org/wiki/MediaWiki_Engineering/Guides/Backend_performance_practices#Persistence_layer) - Guidelines recommend avoiding multi-megabyte data in memcached.

