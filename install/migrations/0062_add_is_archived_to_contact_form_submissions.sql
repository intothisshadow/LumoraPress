ALTER TABLE {prefix}contact_form_submissions ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_spam;
