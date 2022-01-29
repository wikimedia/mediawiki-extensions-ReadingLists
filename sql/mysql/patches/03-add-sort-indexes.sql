DROP INDEX /*i*/rl_user_updated ON /*_*/reading_list;
DROP INDEX /*i*/rl_user_deleted ON /*_*/reading_list;
DROP INDEX /*i*/rle_list_updated ON /*_*/reading_list_entry;
DROP INDEX /*i*/rle_user_project_title ON /*_*/reading_list_entry;;

CREATE INDEX /*i*/rl_user_default ON /*_*/reading_list (rl_user_id, rl_is_default);
CREATE UNIQUE INDEX /*i*/rl_user_deleted_name_id ON /*_*/reading_list (rl_user_id, rl_deleted, rl_name, rl_id);
CREATE UNIQUE INDEX /*i*/rl_user_deleted_updated_id ON /*_*/reading_list (rl_user_id, rl_deleted, rl_date_updated, rl_id);
CREATE UNIQUE INDEX /*i*/rl_user_name_id ON /*_*/reading_list (rl_user_id, rl_name, rl_id);
CREATE UNIQUE INDEX /*i*/rl_user_updated_id ON /*_*/reading_list (rl_user_id, rl_deleted, rl_date_updated, rl_id);
CREATE INDEX /*i*/rl_deleted_updated ON /*_*/reading_list (rl_deleted, rl_date_updated);

CREATE UNIQUE INDEX /*i*/rle_list_deleted_title_id ON /*_*/reading_list_entry (rle_rl_id, rle_deleted, rle_title, rle_id);
CREATE UNIQUE INDEX /*i*/rle_list_deleted_updated_id ON /*_*/reading_list_entry (rle_rl_id, rle_deleted, rle_date_updated, rle_id);
CREATE UNIQUE INDEX /*i*/rle_user_updated_id ON /*_*/reading_list_entry (rle_user_id, rle_date_updated, rle_id);
CREATE UNIQUE INDEX /*i*/rle_user_project_title ON /*_*/reading_list_entry (rle_user_id, rle_rlp_id, rle_title, rle_rl_id);
CREATE INDEX /*i*/rle_deleted_updated ON /*_*/reading_list_entry (rle_deleted, rle_date_updated);
