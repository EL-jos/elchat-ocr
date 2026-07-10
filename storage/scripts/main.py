import sys
sys.stdout.reconfigure(encoding='utf-8')

import json
import uuid
import mysql.connector
from datetime import datetime

# -----------------------------
# CONFIGURATION DB
# -----------------------------
DB_CONFIG = {
    "host": "localhost",
    "port": 3308,
    "user": "root",
    "password": "root",
    "database": "elchat"
}

# -----------------------------
# DÉTECTION SIMPLE DE LANGUE
# -----------------------------
EN_KEYWORDS = {
    "product", "name", "type", "reference", "ref", "sku",
    "code", "model", "category", "article", "item"
}

def detect_language(text: str) -> str:
    words = set(text.lower().split())
    return "en" if words & EN_KEYWORDS else "fr"

# -----------------------------
# CONNEXION DB
# -----------------------------
def connect_db():
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        print("✅ Connexion MySQL réussie")
        return conn
    except mysql.connector.Error as e:
        print(f"❌ Connexion MySQL échouée : {e}")
        return None

# -----------------------------
# IMPORT JSON → MYSQL
# -----------------------------
def import_synonyms(json_path: str):
    conn = connect_db()
    if not conn:
        return

    cursor = conn.cursor()

    with open(json_path, "r", encoding="utf-8") as f:
        data = json.load(f)

    insert_query = """
        INSERT INTO field_synonyms (
            id,
            field_key,
            synonym,
            language,
            created_at,
            updated_at
        )
        VALUES (%s, %s, %s, %s, %s, %s)
    """

    now = datetime.utcnow()
    rows_inserted = 0

    for field_key, synonyms in data.items():
        for synonym in synonyms:
            lang = detect_language(synonym)

            values = (
                str(uuid.uuid4()),
                field_key,
                synonym.strip().lower(),  # normalisation IA
                lang,
                now,
                now
            )

            cursor.execute(insert_query, values)
            rows_inserted += 1

    conn.commit()
    cursor.close()
    conn.close()

    print(f"🎉 {rows_inserted} synonymes insérés avec succès")

# -----------------------------
# MAIN
# -----------------------------
if __name__ == "__main__":
    import_synonyms("synonyms.json")
