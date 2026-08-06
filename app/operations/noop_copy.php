<?php

function op_noop_copy(array $cfg, array $datasetRow, int $opId, array $params, callable $log): array {
  $datasetId = (int)$datasetRow['id'];
  $inputPath = $datasetRow['stored_path'];

  if (!$inputPath || !is_file($inputPath)) {
    throw new RuntimeException("Input XML not found: {$inputPath}");
  }

  $outDir = op_output_dir($cfg, $datasetId, $opId);
  ensure_dir($outDir);

  $outXml = $outDir . "/result.xml";
  if (!copy($inputPath, $outXml)) {
    throw new RuntimeException("Cannot copy XML to {$outXml}");
  }

  $report = [
    'op_type' => 'noop_copy',
    'note' => 'Output XML is identical to input XML.',
  ];
  $reportPath = $outDir . "/report.json";
  file_put_contents($reportPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

  return [
    'result_xml' => rel_to_outputs($cfg, $outXml),
    'report_json' => rel_to_outputs($cfg, $reportPath),
  ];
}
