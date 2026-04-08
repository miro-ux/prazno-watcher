import { mutation } from "./_generated/server";
import { v } from "convex/values";

export const upsert = mutation({
  args: {
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
  },
  handler: async (ctx, args) => {
    const existing = await ctx.db
      .query("prod_activ")
      .withIndex("by_mysql_id", (q) => q.eq("mysql_id", args.mysql_id))
      .unique();

    if (existing) {
      await ctx.db.patch(existing._id, args);
    } else {
      await ctx.db.insert("prod_activ", args);
    }
  },
});
