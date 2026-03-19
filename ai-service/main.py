from fastapi import FastAPI
from models import RecommendRequest, EventRequest
from database import get_db
from recommender import calculate_score
from event_handler import update_user_profile

app = FastAPI()

# ------------------------
# GET USER PROFILE
# ------------------------
def get_user_profile(user_id):
    db = get_db()
    cursor = db.cursor(dictionary=True)

    cursor.execute("SELECT * FROM user_profiles WHERE user_id = %s", (user_id,))
    row = cursor.fetchone()

    if not row:
        return {
            "category_weights": {},
            "creator_affinity": {}
        }

    import json
    return {
        "category_weights": json.loads(row["category_weights"] or "{}"),
        "creator_affinity": json.loads(row["creator_affinity"] or "{}")
    }


# ------------------------
# GET REELS
# ------------------------
def get_reels(reel_ids):
    db = get_db()
    cursor = db.cursor(dictionary=True)

    format_strings = ','.join(['%s'] * len(reel_ids))
    cursor.execute(f"SELECT * FROM reels WHERE id IN ({format_strings})", tuple(reel_ids))

    return cursor.fetchall()


# ------------------------
# RECOMMEND API
# ------------------------
@app.post("/recommend")
def recommend(req: RecommendRequest):

    user_profile = get_user_profile(req.user_id)
    reels = get_reels(req.reel_ids)

    scored = []

    for reel in reels:
        score = calculate_score(user_profile, reel)

        scored.append({
            "id": reel["id"],
            "score": score
        })

    scored.sort(key=lambda x: x["score"], reverse=True)

    return {"sorted": scored}


# ------------------------
# EVENT API
# ------------------------
@app.post("/event")
def event(req: EventRequest):

    # Fetch reel info
    db = get_db()
    cursor = db.cursor(dictionary=True)

    cursor.execute("SELECT category, user_id FROM reels WHERE id = %s", (req.reel_id,))
    reel = cursor.fetchone()

    cursor.close()
    db.close()

    update_user_profile(
        user_id=req.user_id,
        completion_rate=req.completion_rate,
        category=reel["category"] if reel else None,
        creator_id=reel["user_id"] if reel else None
    )

    return {"status": "ok"}