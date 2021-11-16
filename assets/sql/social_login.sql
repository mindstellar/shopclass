CREATE TABLE IF NOT EXISTS /*TABLE_PREFIX*/t_tfc_social (
  pk_i_id int(11) NOT NULL AUTO_INCREMENT,
  fk_i_user_id int(11) NOT NULL,
  fk_i_social_id varchar(255) NOT NULL,
  fk_s_authorizer_name varchar(20) NOT NULL,
  fk_s_social_url varchar(300) NOT NULL DEFAULT '',
  fk_s_social_pic varchar(300) NOT NULL DEFAULT '',
  PRIMARY KEY (pk_i_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;