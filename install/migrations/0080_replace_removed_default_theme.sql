INSERT INTO {prefix}options (option_name, option_value)
SELECT 'theme_options_lumora-classic', src.option_value
FROM (SELECT option_value FROM {prefix}options WHERE option_name = 'theme_options_default') AS src
WHERE EXISTS (SELECT 1 FROM (SELECT option_value FROM {prefix}options WHERE option_name = 'active_theme') AS active WHERE active.option_value = 'default')
  AND NOT EXISTS (SELECT 1 FROM (SELECT option_name FROM {prefix}options WHERE option_name = 'theme_options_lumora-classic') AS existing);
UPDATE {prefix}options SET option_value = 'lumora-classic' WHERE option_name = 'active_theme' AND option_value = 'default';
