ALTER TABLE seasons
    ADD COLUMN release_date DATE NULL AFTER release_year;

UPDATE seasons
SET release_date = CASE
    WHEN release_year IS NULL THEN NULL
    WHEN CAST(release_year AS CHAR) REGEXP '^[0-9]{4}$'
        THEN STR_TO_DATE(CONCAT(CAST(release_year AS CHAR), '-01-01'), '%Y-%m-%d')
    WHEN CAST(release_year AS CHAR) REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
        THEN STR_TO_DATE(CAST(release_year AS CHAR), '%Y-%m-%d')
    ELSE NULL
END;

ALTER TABLE seasons
    DROP COLUMN release_year;
