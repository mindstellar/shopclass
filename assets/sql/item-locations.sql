CREATE TABLE IF NOT EXISTS /*TABLE_PREFIX*/t_item_cust_location (
    fk_i_item_id INT(10) UNSIGNED NOT NULL,
    tfc_item_latitude decimal(10,6) NULL,
    tfc_item_longitude decimal(10,6) NULL,

        PRIMARY KEY (fk_i_item_id),
        KEY `tfc_item_latitude` (`tfc_item_latitude`),
  		KEY `tfc_item_longitude` (`tfc_item_longitude`),
        FOREIGN KEY (fk_i_item_id) REFERENCES /*TABLE_PREFIX*/t_item (pk_i_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET 'UTF8' COLLATE 'UTF8_GENERAL_CI' ;
