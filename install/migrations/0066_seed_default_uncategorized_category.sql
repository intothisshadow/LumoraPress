INSERT INTO {prefix}categories (name, slug, description, parent_id, menu_order, created_at, updated_at)
SELECT 'Uncategorized', 'uncategorized', '', NULL, 0, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM {prefix}categories WHERE slug = 'uncategorized');

SET @lp_default_category_id = (SELECT id FROM {prefix}categories WHERE slug = 'uncategorized' LIMIT 1);

INSERT INTO {prefix}options (option_name, option_value)
VALUES ('default_category_id', @lp_default_category_id)
ON DUPLICATE KEY UPDATE option_value = VALUES(option_value);

INSERT INTO {prefix}post_categories (post_id, category_id)
SELECT p.id, @lp_default_category_id
  FROM {prefix}posts p
 WHERE NOT EXISTS (SELECT 1 FROM {prefix}post_categories pc WHERE pc.post_id = p.id);
