ALTER TABLE {prefix}media ADD COLUMN poster_media_id INT UNSIGNED NULL AFTER file_hash;
ALTER TABLE {prefix}media ADD COLUMN caption_track_media_id INT UNSIGNED NULL AFTER poster_media_id;
ALTER TABLE {prefix}media ADD KEY idx_poster_media (poster_media_id);
ALTER TABLE {prefix}media ADD KEY idx_caption_track_media (caption_track_media_id);
