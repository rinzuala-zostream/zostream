import json
from database import get_db

def update_user_profile(user_id, completion_rate, category=None, creator_id=None):

    db = get_db()
    cursor = db.cursor()

    try:
        # 🔥 Default category fallback
        category = category or "default"

        # ✅ If user watched long → increase weight
        if completion_rate > 0.8:

            cursor.execute("""
                INSERT INTO user_profiles (user_id, category_weights, creator_affinity)
                VALUES (%s, JSON_OBJECT(%s, 0.1), JSON_OBJECT(%s, 0.1))
                ON DUPLICATE KEY UPDATE

                -- Update category weight
                category_weights = JSON_SET(
                    COALESCE(category_weights, '{}'),
                    CONCAT('$.', %s),
                    COALESCE(JSON_EXTRACT(category_weights, CONCAT('$.', %s)), 0) + 0.1
                ),

                -- Update creator affinity
                creator_affinity = JSON_SET(
                    COALESCE(creator_affinity, '{}'),
                    CONCAT('$.', %s),
                    COALESCE(JSON_EXTRACT(creator_affinity, CONCAT('$.', %s)), 0) + 0.1
                )
            """, (
                user_id,
                category,
                creator_id or "unknown",

                category, category,
                creator_id or "unknown", creator_id or "unknown"
            ))

        # ❌ If skipped → reduce weight
        elif completion_rate < 0.3:

            cursor.execute("""
                UPDATE user_profiles
                SET category_weights = JSON_SET(
                    COALESCE(category_weights, '{}'),
                    CONCAT('$.', %s),
                    GREATEST(
                        COALESCE(JSON_EXTRACT(category_weights, CONCAT('$.', %s)), 0) - 0.05,
                        0
                    )
                )
                WHERE user_id = %s
            """, (category, category, user_id))

        db.commit()

    except Exception as e:
        print("AI update error:", e)

    finally:
        cursor.close()
        db.close()