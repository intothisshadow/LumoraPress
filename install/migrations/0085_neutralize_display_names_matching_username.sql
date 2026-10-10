UPDATE {prefix}users SET display_name = CONCAT('Member ', id) WHERE LOWER(TRIM(display_name)) = LOWER(TRIM(username));
