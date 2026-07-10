import pandas as pd
import pymysql
from sshtunnel import SSHTunnelForwarder

SITE_ID = "fc550d8a-c83d-41b3-8b39-5272ba71e461"

# CONFIG À ADAPTER
SSH_HOST = "76.13.62.90"
SSH_USER = "root"
SSH_PASSWORD = "Elongajosue22@"  # ou utiliser une clé

DB_HOST = "127.0.0.1"
DB_USER = "elchatuser"
DB_PASSWORD = "Elongajosue22@"
DB_NAME = "elchat"



with SSHTunnelForwarder(
    (SSH_HOST, 22),
    ssh_username=SSH_USER,
    ssh_password=SSH_PASSWORD,
    remote_bind_address=(DB_HOST, 3306)
) as tunnel:

    connection = pymysql.connect(
        host="127.0.0.1",
        port=tunnel.local_bind_port,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME
    )

    # -------------------------
    # Requêtes SQL
    # -------------------------

    queries = {
        "site": f"SELECT * FROM sites WHERE id = '{SITE_ID}'",

        "account": f"""
            SELECT a.*
            FROM accounts a
            JOIN sites s ON s.account_id = a.id
            WHERE s.id = '{SITE_ID}'
        """,

        "site_users": f"""
            SELECT su.*
            FROM site_user su
            WHERE su.site_id = '{SITE_ID}'
        """,

        "pages": f"""
            SELECT *
            FROM pages
            WHERE site_id = '{SITE_ID}'
        """,

        "chunks": f"""
            SELECT c.*
            FROM chunks c
            JOIN pages p ON c.page_id = p.id
            WHERE p.site_id = '{SITE_ID}'
        """
    }

    dataframes = {}

    for name, query in queries.items():
        df = pd.read_sql(query, connection)
        dataframes[name] = df

    connection.close()

# -------------------------
# Export Excel
# -------------------------

with pd.ExcelWriter("export_site_data.xlsx", engine="openpyxl") as writer:
    for name, df in dataframes.items():
        df.to_excel(writer, sheet_name=name, index=False)

print("✅ Export terminé : export_site_data.xlsx")