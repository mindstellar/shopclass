CREATE TABLE IF NOT EXISTS /*TABLE_PREFIX*/t_item_seo (
    fk_i_item_id INT(10) UNSIGNED NOT NULL,
    seo_title VARCHAR(100) NULL,
    seo_desc VARCHAR(100) NULL,

        PRIMARY KEY (fk_i_item_id),
        KEY `dub_title` (`dup_title`),
  		KEY `dup_desc` (`dup_desc`),
        FOREIGN KEY (fk_i_item_id) REFERENCES /*TABLE_PREFIX*/t_item (pk_i_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET 'UTF8' COLLATE 'UTF8_GENERAL_CI' ;
