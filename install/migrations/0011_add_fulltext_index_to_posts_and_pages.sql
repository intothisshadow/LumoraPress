ALTER TABLE {prefix}posts ADD FULLTEXT KEY idx_fulltext_title_content (title, content);
ALTER TABLE {prefix}posts ADD FULLTEXT KEY idx_fulltext_title (title);
ALTER TABLE {prefix}pages ADD FULLTEXT KEY idx_fulltext_title_content (title, content);
ALTER TABLE {prefix}pages ADD FULLTEXT KEY idx_fulltext_title (title);
