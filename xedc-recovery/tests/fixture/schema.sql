CREATE TABLE page_content (
  id INT PRIMARY KEY,
  body LONGTEXT,
  packed LONGTEXT
);
INSERT INTO page_content (id, body, packed) VALUES (
  1,
  'Visit https://test1.local/models',
  'a:2:{s:4:"link";s:26:"https://test1.local/models";s:5:"plain";s:15:"see test1.local";}'
);

CREATE TABLE cache_page (
  cid varchar(255) NOT NULL,
  data longblob,
  PRIMARY KEY (cid)
);
INSERT INTO cache_page (cid, data) VALUES ('warm', 'should-not-be-in-backup');

CREATE TABLE cachetags (
  tag varchar(255) NOT NULL,
  invalidations int NOT NULL DEFAULT 0,
  PRIMARY KEY (tag)
);
INSERT INTO cachetags (tag, invalidations) VALUES ('node_list', 3);

CREATE TABLE sessions (
  sid varchar(128) NOT NULL,
  uid int NOT NULL DEFAULT 0,
  PRIMARY KEY (sid)
);
INSERT INTO sessions (sid, uid) VALUES ('sess1', 1);

CREATE TABLE watchdog (
  wid int NOT NULL AUTO_INCREMENT,
  message text,
  PRIMARY KEY (wid)
);
INSERT INTO watchdog (message) VALUES ('noise');

CREATE TABLE flood (
  fid int NOT NULL AUTO_INCREMENT,
  event varchar(64),
  PRIMARY KEY (fid)
);
INSERT INTO flood (event) VALUES ('user.failed_login');

CREATE TABLE semaphore (
  name varchar(255) NOT NULL,
  value varchar(255),
  PRIMARY KEY (name)
);
INSERT INTO semaphore (name, value) VALUES ('lock', '1');

CREATE TABLE queue (
  item_id int NOT NULL AUTO_INCREMENT,
  name varchar(255),
  data longblob,
  PRIMARY KEY (item_id)
);
INSERT INTO queue (name, data) VALUES ('cron', 'keep-me');

CREATE TABLE key_value (
  collection varchar(128) NOT NULL,
  name varchar(128) NOT NULL,
  value longblob,
  PRIMARY KEY (collection, name)
);
INSERT INTO key_value (collection, name, value) VALUES ('state', 'system.cron_last', '1');
