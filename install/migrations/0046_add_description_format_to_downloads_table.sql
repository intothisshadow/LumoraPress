ALTER TABLE {prefix}downloads ADD COLUMN description_format VARCHAR(10) NOT NULL DEFAULT 'plain' AFTER description;
