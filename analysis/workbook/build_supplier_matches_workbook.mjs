import fs from "node:fs/promises";
import path from "node:path";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const root = path.resolve("../..");
const outDir = path.join(root, "storage/outputs/supplier-matches");
const dataDir = path.join(root, "analysis/output");
const outputPath = path.join(outDir, "supplier_exact_matches.xlsx");

function colName(n) {
  let s = "";
  while (n > 0) {
    const m = (n - 1) % 26;
    s = String.fromCharCode(65 + m) + s;
    n = Math.floor((n - 1) / 26);
  }
  return s;
}

function clean(v) {
  if (v === null || v === undefined) return "";
  if (typeof v === "number") return v;
  return String(v);
}

function rowsToMatrix(headers, rows) {
  return [headers, ...rows.map((row) => headers.map((h) => clean(row[h])))];
}

function setSheetValues(sheet, headers, rows) {
  const matrix = rowsToMatrix(headers, rows);
  const end = `${colName(headers.length)}${matrix.length}`;
  sheet.getRange(`A1:${end}`).values = matrix;
}

function groupRows(raw) {
  return raw.map((row) => ({
    "Группа": Number(row.group_id),
    "Уверенность": "точное совпадение",
    "Поставщиков": Number(row.supplier_count),
    "Товаров в группе": Number(row.product_count),
    "Средний score": Number(row.score_avg),
    "Минимальный score": Number(row.score_min),
    "Бренд": row.canonical_brand,
    "Тип": row.category,
    "Совпавшие признаки": row.shared_specs,
    "Почему объединено": row.reason,
    "FlyDepo": row.FlyDepo,
    "FPVmatrix": row.FPVmatrix,
    "Mydrone": row.Mydrone,
    "СпортХобби": row["СпортХобби"],
  }));
}

function candidateRows(raw) {
  return raw
    .filter((row) => row.confidence === "medium")
    .map((row) => ({
      "Уровень": "кандидат на ручную проверку",
      "Score": Number(row.score),
      "Поставщик A": row.a_supplier,
      "Артикул A": row.a_article,
      "Название A": row.a_name,
      "Бренд A": row.a_brand,
      "Тип A": row.a_category,
      "Цена A": clean(row.a_price),
      "Поставщик B": row.b_supplier,
      "Артикул B": row.b_article,
      "Название B": row.b_name,
      "Бренд B": row.b_brand,
      "Тип B": row.b_category,
      "Цена B": clean(row.b_price),
      "Почему кандидат": row.reason,
    }));
}

function productRows(raw) {
  return raw.map((row) => ({
    "ID": row.id,
    "Поставщик": row.supplier_name,
    "Код поставщика": row.supplier_code,
    "Артикул": row.vendor_code || row.offer_id,
    "Название": row.name,
    "Бренд исходный": row.brand_raw,
    "Бренд норм.": row.brand,
    "Тип": row.category,
    "Категория": row.category_path,
    "Цена": clean(row.price_original),
    "Остаток": row.stock_qty,
    "URL": row.url,
    "Признаки": JSON.stringify(row.specs || {}),
  }));
}

const [groupsRaw, candidatesRaw, productsRaw, analysisSummary, extractionSummary] = await Promise.all([
  fs.readFile(path.join(dataDir, "matched_groups.json"), "utf8").then(JSON.parse),
  fs.readFile(path.join(dataDir, "candidate_pairs.json"), "utf8").then(JSON.parse),
  fs.readFile(path.join(dataDir, "normalized_products.json"), "utf8").then(JSON.parse),
  fs.readFile(path.join(dataDir, "analysis_summary.json"), "utf8").then(JSON.parse),
  fs.readFile(path.join(dataDir, "extraction_summary.json"), "utf8").then(JSON.parse),
]);

const exact = groupRows(groupsRaw);
const candidates = candidateRows(candidatesRaw);
const products = productRows(productsRaw);

const workbook = Workbook.create();

const summary = workbook.worksheets.add("Сводка");
setSheetValues(summary, ["Показатель", "Значение"], [
  { "Показатель": "Источник данных", "Значение": extractionSummary.dump },
  { "Показатель": "Товаров проанализировано", "Значение": analysisSummary.product_count },
  { "Показатель": "Точных групп в основном листе", "Значение": analysisSummary.high_cluster_count },
  { "Показатель": "Товаров в точных группах", "Значение": analysisSummary.high_cluster_products },
  { "Показатель": "Средних кандидатов на проверку", "Значение": analysisSummary.medium_pair_count },
  { "Показатель": "FlyDepo товаров", "Значение": analysisSummary.counts_by_supplier.FlyDepo || 0 },
  { "Показатель": "FPVmatrix товаров", "Значение": analysisSummary.counts_by_supplier.FPVmatrix || 0 },
  { "Показатель": "Mydrone товаров", "Значение": analysisSummary.counts_by_supplier.Mydrone || 0 },
  { "Показатель": "СпортХобби товаров", "Значение": analysisSummary.counts_by_supplier["СпортХобби"] || 0 },
  { "Показатель": "Правило основного листа", "Значение": "Только взаимные лучшие совпадения без найденных критических различий по модели/версии/характеристикам." },
]);

const exactSheet = workbook.worksheets.add("Точные совпадения");
setSheetValues(exactSheet, Object.keys(exact[0] || { "Группа": "" }), exact);

const candidateSheet = workbook.worksheets.add("Кандидаты");
setSheetValues(candidateSheet, Object.keys(candidates[0] || { "Уровень": "" }), candidates);

const productsSheet = workbook.worksheets.add("Нормализованные товары");
setSheetValues(productsSheet, Object.keys(products[0] || { "ID": "" }), products);

await fs.mkdir(outDir, { recursive: true });
const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(outputPath);
console.log(outputPath);
