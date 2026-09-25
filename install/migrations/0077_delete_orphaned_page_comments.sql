DELETE FROM {prefix}comments WHERE page_id IS NOT NULL AND page_id NOT IN (SELECT id FROM {prefix}pages);
DELETE FROM {prefix}comment_meta WHERE comment_id NOT IN (SELECT id FROM {prefix}comments);
DELETE FROM {prefix}comment_reactions WHERE comment_id NOT IN (SELECT id FROM {prefix}comments);
DELETE FROM {prefix}comment_reports WHERE comment_id NOT IN (SELECT id FROM {prefix}comments);
DELETE FROM {prefix}comment_subscriptions WHERE (post_id IS NOT NULL AND post_id NOT IN (SELECT id FROM {prefix}posts)) OR (page_id IS NOT NULL AND page_id NOT IN (SELECT id FROM {prefix}pages));
