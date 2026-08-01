ALTER TABLE {prefix}posts ADD COLUMN featured_image_crop VARCHAR(255) NULL AFTER featured_image_id;
ALTER TABLE {prefix}pages ADD COLUMN featured_image_crop VARCHAR(255) NULL AFTER featured_image_id;
