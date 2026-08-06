# Master Mobile: раздельный парсинг и сборка фида

Схема разделена на независимые этапы.

## Веб-интерфейс

Управление вынесено в `public/master_mobile_feed.php`.

Через страницу можно:

1. запустить парсинг остатков и пересборку фида;
2. пересобрать фид из полного XML-источника без повторного парсинга сайта;
3. загрузить новый XLSX/CSV-прайс закупочных цен;
4. выбрать источник цен: прайс или цены из snapshot;
5. включить автоматический запуск.

Фоновые задачи идут через стандартную очередь FeedTools как операция
`master_mobile_feed`. Планировщик автоматизации запускается cron-командой:

```bash
*/5 * * * * cd /var/www/feedtools && /usr/bin/php bin/run_supplier_feed_automations.php >> storage/logs/supplier-feed-automations-cron.log 2>&1
```

1. Долгий парсинг сайта Master Mobile пишет снимок цен и остатков:

```bash
python3 bin/master_mobile_parser.py \
  --source feed-products \
  --article-feed https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml \
  --store-id 2 \
  --workers 12 \
  --delay 0 \
  --no-cache \
  --ignore-robots \
  --insecure \
  -o storage/master_mobile/price_stock_snapshot.yml
```

`store-id=2` выбирает `ТК «Савеловский» Мобильный` через AJAX сайта. Это внутренний ID склада
из ответа `getStores`; публичная страница магазина при этом находится на `/contacts/79/`. В штатном режиме
`--source feed-products` парсер берет список URL из текущего публичного фида,
затем по ID из URL запрашивает легкий AJAX-блок покупки и читает остаток из
`data-store-count`, не открывая полные карточки товаров. Если включен режим
цен, полученных парсингом, карточка товара открывается дополнительно для цены и
актуального артикула.

Для теста можно ограничить объем:

```bash
python3 bin/master_mobile_parser.py \
  --source feed-products \
  --article-feed https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml \
  --store-id 2 \
  --limit 100 \
  --workers 12 \
  --delay 0 \
  --no-cache \
  --ignore-robots \
  --insecure \
  -o storage/master_mobile/price_stock_snapshot_test.yml
```

2. Быстрая сборка публичного фида берет последний готовый снимок и текущий
публичный фид `https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml`.
Во время сборки `offer id`, `vendorCode`, `model` и
`<param name="Артикул стикер">` обновляются из snapshot, затем меняются
`price_original`, `stock` и фото. Если передать прайс через `--purchase-prices`,
закупка из прайса записывается в `price_original` поверх цены из snapshot.
Результат загружается в
`https://lpdankoscr.tmweb.ru/xml/master_mobile_info.xml`:

Ссылка-заглушка Master Mobile
`/upload/dev2fun.imagecompress/webp/local/templates/new/images/noimage-big.webp`
удаляется из `<picture>` при сборке. Если у товара была только такая картинка,
в итоговом фиде товар остается без фото.

```bash
php bin/master_mobile_build_feed.php \
  --snapshot=storage/master_mobile/price_stock_snapshot.yml \
  --image-replacements=storage/master_mobile/clean_images/feed_picture_replacements_new_articles.csv \
  --purchase-prices=storage/master_mobile/pricelists/master_mobile_prices_current.xlsx \
  --min-coverage=0.95
```

`--image-replacements` принимает CSV из веб-парсера чистых фото и заменяет
первую ссылку `<picture>` у совпавших офферов. CSV лежит в
`storage/master_mobile/clean_images/feed_picture_replacements.csv` и содержит
`offer_id`, `vendor_code`, `site_id`, старую ссылку `current_picture_url` и
новую `replacement_picture_url`.

Закупочные цены из `--purchase-prices` сопоставляются только по артикулу
товара (`Артикул стикер`, `vendorCode`, `model`, `offer id` без суффикса
поставщика). ID карточки из URL для закупок не используется, потому что он может
совпасть с чужим артикулом в прайсе.

Карта старых и новых артикулов строится по одинаковому `<url>`:

```bash
python3 bin/master_mobile_article_mapping.py \
  --output=storage/master_mobile/article_mapping_changed.csv \
  --all-output=storage/master_mobile/article_mapping_all.csv \
  --insecure
```

Для проверки без загрузки на хостинг:

```bash
php bin/master_mobile_build_feed.php \
  --snapshot=storage/master_mobile/price_stock_snapshot.yml \
  --image-replacements=storage/master_mobile/clean_images/feed_picture_replacements_new_articles.csv \
  --purchase-prices=storage/master_mobile/pricelists/master_mobile_prices_current.xlsx \
  --output=storage/master_mobile/master_mobile_info_test.xml \
  --no-upload
```

3. Обновление поставщика в базе FeedTools запускается отдельно, когда нужно:

```bash
php bin/master_mobile_sync_supplier.php \
  --feed=storage/master_mobile/master_mobile_info.xml \
  --supplier-code=24 \
  --zero-missing
```

Главное правило: парсер не собирает и не публикует основной фид, а сборщик фида
никогда не ходит на сайт Master Mobile. Поэтому фид можно пересобирать быстро в
любой момент из последнего удачного снимка, даже если новый парсинг еще идет.
