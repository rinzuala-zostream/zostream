import time

def calculate_score(user_profile, reel):

    score = 0.0

    # Category match
    category_score = user_profile["category_weights"].get(reel["category"], 0)
    score += 0.25 * category_score

    # Creator affinity
    creator_score = user_profile["creator_affinity"].get(str(reel["user_id"]), 0)
    score += 0.20 * creator_score

    # Completion rate
    score += 0.15 * (reel["completion_rate"] or 0)

    # Engagement
    views = reel["view_count"] or 1
    engagement = (reel["like_count"] + reel["comment_count"]) / views
    score += 0.10 * engagement

    # Freshness
    hours = (time.time() - reel["created_at"].timestamp()) / 3600
    freshness = max(0, 1 - (hours / 72))
    score += 0.10 * freshness

    return score