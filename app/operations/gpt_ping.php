<?php

require_once __DIR__ . '/../llm/OpenAIClient.php';
require_once __DIR__ . '/../llm/LLM.php';

function op_gpt_ping(array $cfg, array $datasetRow, int $opId, array $params, callable $log): array
{
    $datasetId = (int)$datasetRow['id'];

    $outDir = op_output_dir($cfg, $datasetId, $opId);
    ensure_dir($outDir);

    $model = LLM::modelForOp($cfg, $params);

    $maxOut = isset($params['max_output_tokens']) ? (int)$params['max_output_tokens'] : 256;
    if ($maxOut < 16) $maxOut = 16;
    if ($maxOut > 2048) $maxOut = 2048;

    $log("gpt_ping: model={$model}, max_output_tokens={$maxOut}\n");

    $client = LLM::client($cfg, $model);

    $res = $client->generateText(
        $model,
        "Say 'pong' and nothing else.",
        "You are a strict assistant.",
        [
            'max_output_tokens' => $maxOut,
        ]
    );

    $text = (string)($res['output_text'] ?? '');
    $txtAbs = $outDir . '/gpt_ping.txt';
    file_put_contents($txtAbs, $text);

    $rawAbs = $outDir . '/openai_raw.json';
    file_put_contents($rawAbs, json_encode($res['raw'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    return [
        'gpt_ping_txt' => rel_to_outputs($cfg, $txtAbs),
        'openai_raw_json' => rel_to_outputs($cfg, $rawAbs),
    ];
}
