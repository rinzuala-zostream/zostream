import json
from database import get_db

def update_user_profile(user_id, completion_rate):

    db = get_db()
    cursor = db.cursor()

    # simple learning logic
    if completion_rate > 0.8:
        cursor.execute("""
            INSERT INTO user_profiles (user_id, category_weights)
            VALUES (%s, JSON_OBJECT('default', 0.1))
            ON DUPLICATE KEY UPDATE
            category_weights = JSON_SET(
                COALESCE(category_weights, '{}'),
                '$.default',
                COALESCE(JSON_EXTRACT(category_weights, '$.default'), 0) + 0.1
            )
        """, (user_id,))

    db.commit()