ALTER TABLE {prefix}posts ADD COLUMN meta_title VARCHAR(255) NULL AFTER title;
ALTER TABLE {prefix}posts ADD COLUMN meta_description TEXT NULL AFTER excerpt;
ALTER TABLE {prefix}pages ADD COLUMN meta_title VARCHAR(255) NULL AFTER title;
ALTER TABLE {prefix}pages ADD COLUMN meta_description TEXT NULL AFTER excerpt;
