CREATE TABLE IF NOT EXISTS `sph_counter` (
  `counter_id` int(11) NOT NULL,
  `max_doc_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET 'UTF8' COLLATE 'UTF8_GENERAL_CI' ;
INSERT IGNORE INTO `sph_counter` (`counter_id`) VALUES
(1);
ALTER TABLE `sph_counter` ADD UNIQUE (`counter_id`);
