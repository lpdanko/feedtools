<?php

/**
 * scan_xml($path, $sampleN)
 * Возвращает:
 * - offers_count
 * - samples: массив из N offers (id, name, vendorCode, url)
 * - warnings: счётчики проблем
 */
function scan_xml(string $path, int $sampleN = 3): array {
  $reader = new XMLReader();
  if (!$reader->open($path, null, LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
    throw new RuntimeException('Cannot open XML file');
  }

  $offersCount = 0;
  $samples = [];
  $warnings = [
    'offers_missing_url' => 0,
    'offers_missing_product_id' => 0,
  ];

  while ($reader->read()) {
    if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'offer') {
      $offersCount++;

      // Для предупреждений и примеров считываем только нужные под-теги offer
      $offerId = (string)$reader->getAttribute('id');
      $name = '';
      $vendorCode = '';
      $url = '';

      $depth = $reader->depth;

      // читаем внутрь <offer> до его закрытия
      while ($reader->read()) {
        if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'offer' && $reader->depth === $depth) {
          break;
        }

        if ($reader->nodeType === XMLReader::ELEMENT) {
          $tag = $reader->name;

          if (in_array($tag, ['name', 'vendorCode', 'url'], true)) {
            $value = $reader->readString();
            $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'));

            if ($tag === 'name') $name = $value;
            if ($tag === 'vendorCode') $vendorCode = $value;
            if ($tag === 'url') $url = $value;
          }
        }
      }

      if ($url === '') {
        $warnings['offers_missing_url']++;
      } else {
        // product_id=123 (учитываем &amp; в XML)
        if (!preg_match('/(?:\?|&|&amp;)product_id=(\d+)/', $url)) {
          $warnings['offers_missing_product_id']++;
        }
      }

      if (count($samples) < $sampleN) {
        $samples[] = [
          'id' => $offerId,
          'name' => $name,
          'vendorCode' => $vendorCode,
          'url' => $url,
        ];
      }
    }
  }

  $reader->close();

  return [
    'offers_count' => $offersCount,
    'samples' => $samples,
    'warnings' => $warnings,
  ];
}
