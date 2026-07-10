import requests
import pymysql
from typing import Set, List

# =========================
# CONFIG
# =========================
MYSQL_CONFIG = {
    "host": "127.0.0.1",
    "port": 3308,  # 🔥 ajoute ça
    "user": "root",
    "password": "root",
    "database": "elchat",
    "cursorclass": pymysql.cursors.DictCursor
}

QDRANT_URL = "https://29bac2ec-240c-42b3-a828-0dead351a8e4.eu-west-2-0.aws.cloud.qdrant.io"
QDRANT_API_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJhY2Nlc3MiOiJtIn0.pG2z5jG0bpfVGOv0udTCwiarUdJbSsM21CDksizxnYI"

SITE_ID = "0cc78a8e-f60b-420c-b412-6b953d578a84"
COLLECTION = f"chunks_{SITE_ID}"

# =========================
# MYSQL
# =========================
def get_mysql_chunk_ids(site_id: str) -> Set[str]:
    connection = pymysql.connect(**MYSQL_CONFIG)

    try:
        with connection.cursor() as cursor:
            sql = """
                SELECT id
                FROM chunks
                WHERE site_id = %s
            """
            cursor.execute(sql, (site_id,))
            rows = cursor.fetchall()

            return {row["id"] for row in rows}

    finally:
        connection.close()


# =========================
# QDRANT
# =========================
def scroll_qdrant_ids(collection: str) -> Set[str]:
    url = f"{QDRANT_URL}/collections/{collection}/points/scroll"

    headers = {
        "api-key": QDRANT_API_KEY,
        "Content-Type": "application/json"
    }

    all_ids = set()
    next_page_offset = None

    while True:
        body = {
            "limit": 1000,
            "with_payload": False,
            "with_vector": False
        }

        if next_page_offset:
            body["offset"] = next_page_offset

        response = requests.post(url, json=body, headers=headers)
        response.raise_for_status()

        data = response.json()["result"]

        points = data["points"]

        for point in points:
            all_ids.add(point["id"])

        next_page_offset = data.get("next_page_offset")

        print(f"Fetched {len(all_ids)} points so far...")

        if not next_page_offset:
            break

    return all_ids


# =========================
# DELETE FROM QDRANT
# =========================
def delete_qdrant_points(collection: str, ids: List[str]):
    if not ids:
        return

    url = f"{QDRANT_URL}/collections/{collection}/points/delete"

    headers = {
        "api-key": QDRANT_API_KEY,
        "Content-Type": "application/json"
    }

    BATCH_SIZE = 500

    for i in range(0, len(ids), BATCH_SIZE):
        batch = ids[i:i + BATCH_SIZE]

        response = requests.post(
            url,
            json={"points": batch},
            headers=headers
        )

        response.raise_for_status()

        print(f"Deleted batch {i} → {i + len(batch)}")


# =========================
# MAIN
# =========================
def main():
    print("🔍 Fetch MySQL IDs...")
    mysql_ids = get_mysql_chunk_ids(SITE_ID)
    print(f"MySQL count: {len(mysql_ids)}")

    print("🔍 Fetch Qdrant IDs...")
    qdrant_ids = scroll_qdrant_ids(COLLECTION)
    print(f"Qdrant count: {len(qdrant_ids)}")

    # =========================
    # DIFF
    # =========================
    to_delete = qdrant_ids - mysql_ids
    missing_in_qdrant = mysql_ids - qdrant_ids

    print(f"\n❌ To delete from Qdrant: {len(to_delete)}")
    print(f"⚠️ Missing in Qdrant: {len(missing_in_qdrant)}")

    # DEBUG OPTIONNEL
    if missing_in_qdrant:
        print("Example missing:", list(missing_in_qdrant)[:5])

    # =========================
    # DELETE
    # =========================
    confirm = input("\nType 'yes' to delete orphan points: ")

    if confirm.lower() == "yes":
        delete_qdrant_points(COLLECTION, list(to_delete))
        print("✅ Cleanup done")
    else:
        print("❌ Cancelled")


if __name__ == "__main__":
    main()