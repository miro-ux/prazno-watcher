import { defineSchema, defineTable } from "convex/server";
import { v } from "convex/values";

export default defineSchema({
  prod_activ: defineTable({
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
    _archived: v.optional(v.boolean()),
    _archivedAt: v.optional(v.number()),
  }).index("by_mysql_id", ["mysql_id"]),
});
