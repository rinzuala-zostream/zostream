import mysql.connector

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
    print(titles)
    cursor.close()
    db.close()
except Exception as e:
    print("DB error:", e)
