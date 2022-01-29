CREATE TABLE /*_*/reading_list_project (
    rlp_id INTEGER UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
    rlp_project VARBINARY(255) NOT NULL
) /*$wgDBTableOptions*/;
CREATE UNIQUE INDEX /*i*/rlp_project ON /*_*/reading_list_project (rlp_project);

-- migration step 1: create new column, drop old indexes (can't create new ones yet b/c uniqueness)
ALTER TABLE /*_*/reading_list_entry
    ADD COLUMN rle_rlp_id INTEGER UNSIGNED NOT NULL;
DROP INDEX /*i*/rle_user_project_title ON /*_*/reading_list_entry;
DROP INDEX /*i*/rle_list_project_title ON /*_*/reading_list_entry;

-- migration step 2: copy old column data to new table, write ids into new column
-- add new indexes as we are unique now
INSERT IGNORE INTO /*_*/reading_list_project (rlp_project)
    SELECT rle_project FROM /*_*/reading_list_entry;
UPDATE /*_*/reading_list_entry
    JOIN /*_*/reading_list_project ON rle_project = rlp_project
    SET rle_rlp_id = rlp_id;
CREATE INDEX /*i*/rle_user_project_title ON /*_*/reading_list_entry (rle_user_id, rle_rlp_id, rle_title);
CREATE UNIQUE INDEX /*i*/rle_list_project_title ON /*_*/reading_list_entry (rle_rl_id, rle_rlp_id, rle_title);

-- migration step 3: drop old data
ALTER TABLE /*_*/reading_list_entry
    DROP COLUMN rle_project;

