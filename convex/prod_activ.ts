import { mutation, query } from "./_generated/server";
import { v } from "convex/values";

const rowValidator = {
  mysql_id: v.number(),
  Stoka: v.optional(v.string()),
  Br: v.optional(v.number()),
  Cena: v.optional(v.number()),
  masa: v.optional(v.number()),
  Miasto: v.optional(v.string()),
  Servitior: v.optional(v.string()),
  SmetkaN: v.optional(v.number()),
  Data: v.optional(v.string()),
  Time: v.optional(v.string()),
  Time2: v.optional(v.string()),
  Zaiavka: v.optional(v.string()),
  Status: v.optional(v.number()),
  Platena: v.optional(v.number()),
  Otchetena: v.optional(v.number()),
  Porcent: v.optional(v.number()),
  IDNap: v.optional(v.number()),
  ID_Stoca: v.optional(v.number()),
  Suplimente: v.optional(v.number()),
};

/**
 * Atomic mirror-sync: upsert every row from MySQL, archive anything
 * in Convex whose mysql_id is NOT in the incoming set.
 * One call = Convex becomes an exact mirror of prod_activ.
 */
export const fullSync = mutation({
  args: { rows: v.array(v.object(rowValidator)) },
  handler: async (ctx, { rows }) => {
    const incomingIds = new Set(rows.map((r) => r.mysql_id));

    // Upsert all incoming rows
    let inserted = 0;
    let updated = 0;
    for (const row of rows) {
      const existing = await ctx.db
        .query("prod_activ")
        .withIndex("by_mysql_id", (q) => q.eq("mysql_id", row.mysql_id))
        .unique();

      if (existing) {
        await ctx.db.patch(existing._id, {
          ...row,
          archived: false,
          archivedAt: undefined,
        });
        updated++;
      } else {
        await ctx.db.insert("prod_activ", { ...row, archived: false });
        inserted++;
      }
    }

    // Archive anything in Convex that's NOT in the incoming MySQL set
    let archived = 0;
    const all = await ctx.db.query("prod_activ").collect();
    for (const doc of all) {
      if (!incomingIds.has(doc.mysql_id) && doc.archived !== true) {
        await ctx.db.patch(doc._id, {
          archived: true,
          archivedAt: Date.now(),
        });
        archived++;
      }
    }

    return { inserted, updated, archived };
  },
});

/** Single-row upsert (kept for backward compat) */
export const upsert = mutation({
  args: rowValidator,
  handler: async (ctx, args) => {
    const existing = await ctx.db
      .query("prod_activ")
      .withIndex("by_mysql_id", (q) => q.eq("mysql_id", args.mysql_id))
      .unique();

    if (existing) {
      await ctx.db.patch(existing._id, {
        ...args,
        archived: false,
        archivedAt: undefined,
      });
    } else {
      await ctx.db.insert("prod_activ", { ...args, archived: false });
    }
  },
});

export const listActiveMysqlIds = query({
  args: {},
  handler: async (ctx) => {
    const all = await ctx.db.query("prod_activ").collect();
    return all
      .filter((r) => r.archived !== true)
      .map((r) => r.mysql_id);
  },
});

/** Returns only active (non-archived) rows */
export const listActive = query({
  args: {},
  handler: async (ctx) => {
    const all = await ctx.db.query("prod_activ").collect();
    return all.filter((r) => r.archived !== true);
  },
});

/** Debug: total, active, archived counts */
export const debugInfo = query({
  args: {},
  handler: async (ctx) => {
    const all = await ctx.db.query("prod_activ").collect();
    const active = all.filter((r) => r.archived !== true);
    const archived = all.filter((r) => r.archived === true);
    return {
      total: all.length,
      active: active.length,
      archived: archived.length,
      activeIds: active.map((r) => r.mysql_id),
    };
  },
});

/** Hard-delete ALL documents from the table (for cleanup) */
export const purgeAll = mutation({
  args: {},
  handler: async (ctx) => {
    const all = await ctx.db.query("prod_activ").collect();
    for (const doc of all) {
      await ctx.db.delete(doc._id);
    }
    return { deleted: all.length };
  },
});

export const softDelete = mutation({
  args: { mysql_id: v.number() },
  handler: async (ctx, args) => {
    const existing = await ctx.db
      .query("prod_activ")
      .withIndex("by_mysql_id", (q) => q.eq("mysql_id", args.mysql_id))
      .unique();

    if (existing && existing.archived !== true) {
      await ctx.db.patch(existing._id, {
        archived: true,
        archivedAt: Date.now(),
      });
      return true;
    }
    return false;
  },
});

export const batchSoftDelete = mutation({
  args: { mysql_ids: v.array(v.number()) },
  handler: async (ctx, { mysql_ids }) => {
    let count = 0;
    for (const mysql_id of mysql_ids) {
      const existing = await ctx.db
        .query("prod_activ")
        .withIndex("by_mysql_id", (q) => q.eq("mysql_id", mysql_id))
        .unique();

      if (existing && existing.archived !== true) {
        await ctx.db.patch(existing._id, {
          archived: true,
          archivedAt: Date.now(),
        });
        count++;
      }
    }
    return { archived: count };
  },
});
