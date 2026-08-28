INSERT INTO {prefix}options (option_name, option_value)
SELECT CONCAT('theme_options_', COALESCE(
        (SELECT option_value FROM {prefix}options WHERE option_name = 'active_theme'),
        'default'
    )),
    option_value
FROM {prefix}options
WHERE option_name = 'theme_options'
ON DUPLICATE KEY UPDATE option_value = VALUES(option_value);
