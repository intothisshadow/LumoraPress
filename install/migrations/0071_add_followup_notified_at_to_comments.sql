ALTER TABLE {prefix}comments ADD COLUMN followup_notified_at DATETIME NULL AFTER updated_at;
UPDATE {prefix}comments SET followup_notified_at = updated_at WHERE status = 'approved';
