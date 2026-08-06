import path from "node:path";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const outputPath = process.env.FEEDTOOLS_MATCH_WORKBOOK
  ?? path.resolve("../../storage/outputs/supplier-matches/supplier_exact_matches.xlsx");
const input = await FileBlob.load(outputPath);
const workbook = await SpreadsheetFile.importXlsx(input);

const exact = await workbook.inspect({
  kind: "table",
  range: "Точные совпадения!A1:N12",
  include: "values",
  tableMaxRows: 12,
  tableMaxCols: 14,
});
console.log(exact.ndjson);

const summary = await workbook.inspect({
  kind: "table",
  range: "Сводка!A1:B12",
  include: "values",
  tableMaxRows: 12,
  tableMaxCols: 2,
});
console.log(summary.ndjson);

const errors = await workbook.inspect({
  kind: "match",
  searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A",
  options: { useRegex: true, maxResults: 20 },
  summary: "formula error scan",
});
console.log(errors.ndjson);

await workbook.render({ sheetName: "Точные совпадения", range: "A1:N20", scale: 1 });
await workbook.render({ sheetName: "Сводка", range: "A1:B12", scale: 1 });
console.log("verified");
