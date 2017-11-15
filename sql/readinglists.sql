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
    -- List color as 3x2 hex digits.
    rl_color VARBINARY(6) DEFAULT NULL,
    -- List image as file name to pass to wfFindFile() or the like.
    rl_image VARBINARY(255) DEFAULT NULL,
    -- List icon.
    rl_icon VARBINARY(32) DEFAULT NULL,
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
-- For syncing lists that changed since a given date.
CREATE INDEX /*i*/rl_user_updated ON /*_*/reading_list (rl_user_id, rl_date_updated);
-- For getting all non-deleted items.
CREATE INDEX /*i*/rl_user_deleted ON /*_*/reading_list (rl_user_id, rl_deleted);
-- TODO date_updated + deleted for cleanup?

-- List items.
CREATE TABLE /*_*/reading_list_entry (
    rle_id INTEGER UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    -- Reference to reading_list.rl_id.
    rle_rl_id INTEGER UNSIGNED NOT NULL,
    -- Central ID of user, denormalized for the benefit of the /pages/ route.
    rle_user_id INTEGER UNSIGNED NOT NULL,
    -- Wiki project domain.
    rle_project VARCHAR(255) BINARY NOT NULL,
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
-- For getting all entries in a list and for syncing list entries that changed since a given date.
CREATE INDEX /*i*/rle_list_updated ON /*_*/reading_list_entry (rle_rl_id, rle_date_updated);
-- For getting all lists of a given user which contain a specified page.
CREATE INDEX /*i*/rle_user_project_title ON /*_*/reading_list_entry (rle_user_id, rle_project, rle_title);
-- For ensuring there are no duplicate pages on a single list.
CREATE UNIQUE INDEX /*i*/rle_list_project_title ON /*_*/reading_list_entry (rle_rl_id, rle_project, rle_title);

-- TODO use lookup table to deduplicate domains
-- -- Table for storing domains efficiently.
-- CREATE TABLE /*_*/reading_list_domain (
--     rld_id INTEGER UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
--     rld_domain VARBINARY(255) NOT NULL
-- ) /*$wgDBTableOptions*/;
-- CREATE UNIQUE INDEX /*i*/rld_domain ON /*_*/reading_list_domain (rld_domain);
