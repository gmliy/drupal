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
