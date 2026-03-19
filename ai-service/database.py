import mysql.connector

def get_db():
    return mysql.connector.connect(
        host="127.0.0.1",
        user="root",
        password="",
        database="zo_stream_api"
    )