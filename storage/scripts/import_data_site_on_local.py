import pandas as pd
import pymysql

# CONFIG
EXCEL_FILE = "export_site_data.xlsx"

DB_HOST = "127.0.0.1"
DB_PORT = 3308
DB_USER = "root"
DB_PASSWORD = "root"
DB_NAME = "elchat"

# Connexion MySQL
connection = pymysql.connect(
    host=DB_HOST,
    port=DB_PORT,
    user=DB_USER,
    password=DB_PASSWORD,
    database=DB_NAME,
    autocommit=False
)

cursor = connection.cursor()

# Désactiver les FK temporairement
cursor.execute("SET FOREIGN_KEY_CHECKS=0;")

def insert_dataframe(table_name, df):
    if df.empty:
        print(f"⚠️ {table_name} vide, ignoré")
        return

    columns = list(df.columns)
    placeholders = ", ".join(["%s"] * len(columns))
    cols_formatted = ", ".join(columns)

    update_clause = ", ".join([
        f"{col}=VALUES({col})" for col in columns if col != "id"
    ])

    sql = f"""
    INSERT INTO {table_name} ({cols_formatted})
    VALUES ({placeholders})
    ON DUPLICATE KEY UPDATE {update_clause}
    """

    for _, row in df.iterrows():
        values = [None if pd.isna(x) else x for x in row]
        cursor.execute(sql, values)

    print(f"✅ {table_name} inséré ({len(df)} lignes)")

try:
    # Lecture Excel
    xls = pd.ExcelFile(EXCEL_FILE)

    df_account = pd.read_excel(xls, "account")
    df_site = pd.read_excel(xls, "site")
    df_site_users = pd.read_excel(xls, "site_users")
    df_pages = pd.read_excel(xls, "pages")
    df_chunks = pd.read_excel(xls, "chunks")

    # Insertion dans le bon ordre
    print("⏭️ accounts ignoré (déjà existant)")
    insert_dataframe("sites", df_site)
    insert_dataframe("site_user", df_site_users)
    insert_dataframe("pages", df_pages)
    insert_dataframe("chunks", df_chunks)

    connection.commit()
    print("🎉 Import terminé avec succès")

except Exception as e:
    connection.rollback()
    print("❌ Erreur :", e)

finally:
    cursor.execute("SET FOREIGN_KEY_CHECKS=1;")
    cursor.close()
    connection.close()