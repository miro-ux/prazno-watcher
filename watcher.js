import mysql from "mysql2/promise";
import dotenv from "dotenv";
import { ConvexHttpClient } from "convex/browser";
import { api } from "./convex/_generated/api.js";

dotenv.config({ path: ".env.local" });

const CONVEX_URL =
  process.env.CONVEX_URL ?? "https://youthful-cobra-798.convex.cloud";
const POLL_INTERVAL_MS = Number(process.env.POLL_INTERVAL_MS) || 3000;

const mysqlSsl =
  process.env.MYSQL_SSL === "1" || process.env.MYSQL_SSL === "true"
    ? { rejectUnauthorized: process.env.MYSQL_SSL_REJECT_UNAUTHORIZED !== "0" }
    : undefined;

const db = await mysql.createConnection({
  host: process.env.MYSQL_HOST ?? "192.168.0.21",
  user: process.env.MYSQL_USER ?? "ivan",
  password: process.env.MYSQL_PASSWORD ?? "22coldy22",
  database: process.env.MYSQL_DATABASE ?? "prazno",
  ssl: mysqlSsl,
});

const convex = new ConvexHttpClient(CONVEX_URL);

// Track last seen ID for new rows, or use updated_at if you have it
let lastMaxId = 0;

// Init: get current max ID so we don't replay history on startup
const [[init]] = await db.execute("SELECT MAX(ID) as maxId FROM prod_activ");
lastMaxId = init.maxId ?? 0;
console.log(`[init] Starting from ID > ${lastMaxId}`);

setInterval(async () => {
  try {
    const [rows] = await db.execute(
      "SELECT * FROM prod_activ WHERE ID > ?",
      [lastMaxId]
    );

    if (rows.length === 0) return;

    console.log(`[sync] ${rows.length} new row(s) found`);

    for (const row of rows) {
      const payload = {
        mysql_id: row.ID,
        Stoka: row.Stoka ?? undefined,
        Br: row.Br != null ? Number(row.Br) : undefined,
        Cena: row.Cena != null ? Number(row.Cena) : undefined,
        masa: row.masa != null ? Number(row.masa) : undefined,
        Miasto: String(row.Miasto ?? ""),
        Servitior: row.Servitior ?? undefined,
        SmetkaN: row.SmetkaN != null ? Number(row.SmetkaN) : undefined,
        Data: row.Data ?? undefined,
        Time: row.Time ?? undefined,
        Time2: row.Time2 ?? undefined,
        Zaiavka: row.Zaiavka ?? undefined,
        Status: row.Status != null ? Number(row.Status) : undefined,
        Platena: row.Platena != null ? Number(row.Platena) : undefined,
        Otchetena: row.Otchetena != null ? Number(row.Otchetena) : undefined,
        Porcent: row.Porcent != null ? Number(row.Porcent) : undefined,
        IDNap: row.IDNap != null ? Number(row.IDNap) : undefined,
        ID_Stoca: row.ID_Stoca != null ? Number(row.ID_Stoca) : undefined,
        Suplimente: row.Suplimente != null ? Number(row.Suplimente) : undefined,
      };

      await convex.mutation(api.prod_activ.upsert, payload);
      console.log(`  → upserted ID ${row.ID}`);
      if (row.ID > lastMaxId) lastMaxId = row.ID;
    }
  } catch (err) {
    console.error("[error]", err.message);
  }
}, POLL_INTERVAL_MS);
