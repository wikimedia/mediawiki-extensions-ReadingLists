-- On the application level, column length limits are enforced via
-- ReadingListRepository::$fieldLength which should be kept in sync.

-- Lists.
CREATE TABLE /*_*/reading_list (
    rl_id INTEGER UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    -- Central ID of user.
    rl_user_id INTEGER UNSIGNED NOT NULL,
    -- Flag to tell apart the initial list from the rest, for UX purposes and to forbid deleting it.
    -- Users with more than zero lists always have exactly one default list.
    rl_is_default TINYINT NOT NULL DEFAULT 0,
    -- Human-readable non-unique name of the list.
    rl_name VARCHAR(255) BINARY NOT NULL,
    -- Description of the list.
    rl_description VARBINARY(767) NOT NULL DEFAULT '',
    -- Creation timestamp.
    rl_date_created BINARY(14) NOT NULL default '19700101000000',
    -- Last modification timestamp.
    -- This only reflects modifications to the reading_list record, not
    -- modifications/additions/deletions of child entries.
    rl_date_updated BINARY(14) NOT NULL default '19700101000000',
    -- Deleted flag.
    -- Lists will be hard-deleted eventually but kept around for a while for sync.
    rl_deleted TINYINT NOT NULL DEFAULT 0
) /*$wgDBTableOptions*/;
-- For isSetupForUser() which is called a lot and used for row locks. Will only ever be used
-- with rl_is_default = 1 so effectively a unique index.
CREATE INDEX /*i*/rl_user_default ON /*_*/reading_list (rl_user_id, rl_is_default);
-- For querying lists of a user by name (and then id as a tiebreaker). Covers getAllLists() with
-- SORT_BY_NAME.
CREATE UNIQUE INDEX /*i*/rl_user_deleted_name_id ON /*_*/reading_list (rl_user_id, rl_deleted, rl_name, rl_id);
-- For querying lists of a user by last updated timestamp (and then id as a tiebreaker). Covers
-- getAllLists() with SORT_BY_UPDATED.
CREATE UNIQUE INDEX /*i*/rl_user_deleted_updated_id ON /*_*/reading_list (rl_user_id, rl_deleted, rl_date_updated, rl_id);
-- Like the previous two, except for getListsByDateUpdated() which does not have rl_deleted=0.
-- TODO this is the lazy option, the previous indexes could be made to work if the sort matched the condition.
CREATE UNIQUE INDEX /*i*/rl_user_name_id ON /*_*/reading_list (rl_user_id, rl_name, rl_id);
CREATE UNIQUE INDEX /*i*/rl_user_updated_id ON /*_*/reading_list (rl_user_id, rl_deleted, rl_date_updated, rl_id);
-- For getting all deleted items older than a given date. Covers purgeOldDeleted().
CREATE INDEX /*i*/rl_deleted_updated ON /*_*/reading_list (rl_deleted, rl_date_updated);

-- List items.
CREATE TABLE /*_*/reading_list_entry (
    rle_id INTEGER UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    -- Reference to reading_list.rl_id.
    rle_rl_id INTEGER UNSIGNED NOT NULL,
    -- Central ID of user, denormalized for the benefit of the /pages/ route.
    rle_user_id INTEGER UNSIGNED NOT NULL,
    -- Reference to reading_list_project.rlp_id.
    rle_rlp_id INTEGER UNSIGNED NOT NULL,
    -- Page title.
    -- We can't easily use page ids due to the cross-wiki nature of the project;
    -- also, page ids don't age well when content is deleted/moved.
    rle_title VARCHAR(255) BINARY NOT NULL,
    -- Creation timestamp.
    rle_date_created BINARY(14) NOT NULL default '19700101000000',
    -- Last modification timestamp.
    rle_date_updated BINARY(14) NOT NULL default '19700101000000',
    -- Deleted flag.
    -- Entries will be hard-deleted eventually but kept around for a while for sync.
    rle_deleted TINYINT NOT NULL DEFAULT 0
) /*$wgDBTableOptions*/;
-- For ensuring there are no duplicate pages on a single list.
-- (What we actually need is "no duplicate non-deleted items" but there is no way to turn that
-- into an index condition so the software will work around it by repurposing deleted items as needed.)
CREATE UNIQUE INDEX /*i*/rle_list_project_title ON /*_*/reading_list_entry (rle_rl_id, rle_rlp_id, rle_title);
-- For querying list entries in a given list by title (and then id as a tiebreaker). Covers
-- getListEntries() with SORT_BY_NAME.
CREATE UNIQUE INDEX /*i*/rle_list_deleted_title_id ON /*_*/reading_list_entry (rle_rl_id, rle_deleted, rle_title, rle_id);
-- For querying list entries in a given list by last updated timestamp (and then id as a tiebreaker).
-- Covers getListEntries() with SORT_BY_UPDATED.
CREATE UNIQUE INDEX /*i*/rle_list_deleted_updated_id ON /*_*/reading_list_entry (rle_rl_id, rle_deleted, rle_date_updated, rle_id);
-- For querying all list entries of a given user by last updated timestamp (and then id as a
-- tiebreaker). Covers getListEntriesByDateUpdated() (almost; the results still have to be
-- filtered on rl_deleted).
CREATE UNIQUE INDEX /*i*/rle_user_updated_id ON /*_*/reading_list_entry (rle_user_id, rle_date_updated, rle_id);
-- For getting all lists of a given user which contain a specified page. Covers getListsByPage().
-- rle_rl_id is included to ensure consistent sorting within a fully covered query (the assumption
-- being that result sets for this query will typically be small enough that we don't care about
-- server-side sorting by name etc; if we do end up with an anomalously huge resultset, performance
-- is preferred over returning the results in order.)
CREATE UNIQUE INDEX /*i*/rle_user_project_title ON /*_*/reading_list_entry (rle_user_id, rle_rlp_id, rle_title, rle_rl_id);
-- For getting all deleted items older than a given date. Covers purgeOldDeleted().
CREATE INDEX /*i*/rle_deleted_updated ON /*_*/reading_list_entry (rle_deleted, rle_date_updated);

-- Table for storing projects (domains) efficiently.
CREATE TABLE /*_*/reading_list_project (
    rlp_id INTEGER UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    -- Wiki project domain.
    rlp_project VARCHAR(255) BINARY NOT NULL
) /*$wgDBTableOptions*/;
CREATE UNIQUE INDEX /*i*/rlp_project ON /*_*/reading_list_project (rlp_project);
