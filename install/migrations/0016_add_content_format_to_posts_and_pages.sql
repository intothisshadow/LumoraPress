ALTER TABLE {prefix}posts ADD COLUMN content_format VARCHAR(10) NOT NULL DEFAULT 'plain' AFTER content;
ALTER TABLE {prefix}pages ADD COLUMN content_format VARCHAR(10) NOT NULL DEFAULT 'plain' AFTER content;
