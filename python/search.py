from fastapi import FastAPI, Query, HTTPException
from rapidfuzz import fuzz, process
import mysql.connector
import logging

app = FastAPI()

logging.basicConfig(level=logging.INFO)

def get_movie_titles():
    try:
        db = mysql.connector.connect(
            host="127.0.0.1",
            port=3307,
            user="root",
            password="",
            database="zo_stream_api"
        )

        cursor = db.cursor()
        cursor.execute("SELECT title FROM movie")
        titles = [row[0] for row in cursor.fetchall()]
        cursor.close()
        db.close()
        logging.info(f"Fetched {len(titles)} movie titles from DB.")
        return titles
    except Exception as e:
        logging.error(f"DB error: {e}")
        return []

@app.on_event("startup")
def startup_event():
    global movie_titles
    movie_titles = get_movie_titles()

@app.get("/search")
def fuzzy_match(
    title: str = Query(..., description="Movie title to fuzzy match"),
    limit: int = Query(5, description="Max number of results"),
    threshold: int = Query(70, description="Minimum match score")
):
    if not movie_titles:
        raise HTTPException(status_code=500, detail="Movie titles list is empty or DB connection failed")

    try:
        matches = process.extract(title, movie_titles, scorer=fuzz.ratio, limit=limit)
        filtered_matches = [{"title": m[0], "score": m[1]} for m in matches if m[1] >= threshold]

        return {"results": filtered_matches}
    except Exception as e:
        logging.error(f"Matching error: {e}")
        raise HTTPException(status_code=500, detail="Internal matching error.")
