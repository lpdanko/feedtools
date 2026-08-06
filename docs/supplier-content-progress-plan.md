# План: контентная оценка поставщиков

Дата фиксации: 2026-05-17

Актуальный scope: оцениваем только загрузку и качество контента по товарам поставщиков. Не считаем экономический потенциал, маржу, ROI, capacity planning, OKR и сложное управление задачами.

## 1. Цель первой версии

Нужно сделать страницу, где по каждому поставщику понятно:

1. Сколько товаров есть в базе поставщика.
2. Сколько товаров сейчас есть в наличии и поэтому являются целью выгрузки.
3. Сколько товаров без остатка и поэтому не должны портить прогресс загрузки.
4. Сколько товаров уже загружено на маркетплейсы.
5. Сколько товаров еще не загружено из тех, что есть в наличии.
6. Сколько товаров реально продается.
7. Сколько товаров не продается и почему.
8. Где есть исправимые ошибки в карточках.
9. Насколько качественно заполнены карточки.
10. Как изменилось состояние за период.
11. Кто из пользователей внес вклад в контентный прогресс.

Это именно content progress dashboard: состояние карточек, загрузка, модерация, продаваемость и качество контента.

## 2. Что не входит в первую версию

Не делаем сейчас:

1. Экономический потенциал поставщика.
2. Маржу, выручку, ROI задач.
3. OKR/targets.
4. Capacity planning команды.
5. Сложные work plans.
6. SLA задач.
7. Бенчмарки по рынку.
8. AI assistant внутри дашборда.
9. Уведомления.
10. Сложную систему ролей.

Эти идеи можно оставить на будущее, но они не должны усложнять первую реализацию.

## 3. Главные сущности

### 3.1. Поставщик

Источник: `feedtools_suppliers`.

Для поставщика считаем:

1. `products_total`: всего товаров поставщика в FeedTools.
2. `target_products_total`: товары с остатком, которые сейчас имеет смысл выгружать.
3. `out_of_stock_total`: товары без остатка, которые не считаются текущим провалом загрузки.
4. `ozon_uploaded_total`: товаров с остатком найдено/заведено на Ozon.
5. `wb_uploaded_total`: товаров с остатком найдено/заведено на Wildberries.
6. `uploaded_any_total`: товаров с остатком загружено хотя бы на один маркетплейс.
7. `uploaded_all_total`: товаров с остатком загружено на все целевые маркетплейсы.
8. `not_uploaded_total`: товаров с остатком не загружено никуда.
9. `sellable_total`: товаров с остатком реально продается хотя бы на одном маркетплейсе.
10. `content_errors_total`: товаров с остатком с ошибками карточек.
11. `fixable_errors_total`: исправимых ошибок.
12. `quality_score`: среднее качество карточек по товарам с остатком.
13. `content_progress_score`: общий контентный прогресс от 0 до 100.

### 3.2. Товар поставщика

Источник: `feedtools_supplier_products`.

Главные поля:

1. `id`
2. `supplier_id`
3. `offer_id`
4. `vendor_code`
5. `name`
6. `brand`
7. `description_html`
8. `ozon_category`
9. `wb_category`
10. `price_original`
11. `stock_qty`
12. `count_qty`
13. `pictures_json`
14. `params_json`

### 3.3. Статус товара на маркетплейсе

Источники:

1. Ozon: `feedtools_ozon_products`.
2. WB: `feedtools_wb_products`.

Нормализованные статусы:

1. `not_uploaded`: товара нет на маркетплейсе.
2. `uploaded`: товар есть на маркетплейсе, но не обязательно готов.
3. `ready`: карточка принята/активна.
4. `sellable`: карточка готова и есть цена/остаток.
5. `error`: ошибка карточки или валидации.
6. `revision`: карточка на доработке/модерации.
7. `archived`: товар в архиве.

Важно: `uploaded`, `ready` и `sellable` считать отдельно. Товар может быть загружен, но не продаваться.

## 4. Основные метрики

### 4.1. Загрузка контента

Метрики:

1. `products_total`
2. `target_products_total`
3. `out_of_stock_total`
4. `uploaded_ozon_total`
5. `uploaded_wb_total`
6. `uploaded_any_total`
7. `uploaded_all_total`
8. `not_uploaded_total`
9. `upload_coverage_percent`
10. `ozon_coverage_percent`
11. `wb_coverage_percent`

Формулы:

1. `target_products_total = товары с эффективным остатком > 0`, где эффективный остаток равен максимуму из `stock_qty` и `count_qty`.
2. `out_of_stock_total = products_total - target_products_total`
3. `upload_coverage_percent = uploaded_any_total / target_products_total * 100`
4. `ozon_coverage_percent = uploaded_ozon_total / target_products_total * 100`
5. `wb_coverage_percent = uploaded_wb_total / target_products_total * 100`

### 4.2. Продаваемость

Метрики:

1. `ready_ozon_total`
2. `ready_wb_total`
3. `sellable_ozon_total`
4. `sellable_wb_total`
5. `sellable_any_total`
6. `not_sellable_total`
7. `sellable_percent`

Формула:

`sellable_percent = sellable_any_total / products_total * 100`

Критерий `sellable`:

1. товар есть на маркетплейсе;
2. статус не error/revision/archive;
3. карточка готова или активна;
4. у товара есть цена;
5. у товара есть остаток.

На первом этапе price/stock можно брать из `feedtools_supplier_products`, если нет более точного marketplace-specific источника.

### 4.3. Ошибки карточек

Группы ошибок:

1. `not_uploaded`: товар не загружен.
2. `missing_ozon_category`: нет категории Ozon.
3. `missing_wb_category`: нет категории WB.
4. `missing_brand`: нет бренда.
5. `missing_title`: нет названия.
6. `weak_title`: название слишком короткое или слабое.
7. `missing_description`: нет описания.
8. `weak_description`: описание слишком короткое.
9. `missing_images`: нет фото.
10. `low_images_count`: мало фото.
11. `missing_price`: нет цены.
12. `missing_stock`: нет остатка.
13. `ozon_error`: ошибка Ozon.
14. `wb_error`: ошибка WB.
15. `ozon_revision`: Ozon просит доработку.
16. `wb_revision`: WB просит доработку.
17. `archived`: товар в архиве.

Для каждой ошибки:

1. `severity`: `critical`, `major`, `minor`.
2. `fixability`: `auto`, `semi_auto`, `manual`, `external`.
3. `marketplace`: `ozon`, `wb`, `all`.

### 4.4. Качество карточки

`card_quality_score` от 0 до 100 для товара.

Предлагаемые веса:

1. Название: 15.
2. Бренд: 10.
3. Описание: 15.
4. Фото: 20.
5. Категории маркетплейсов: 20.
6. Характеристики/параметры: 10.
7. Ozon/WB quality signals: 10.

На первом этапе:

1. если есть `content_rating` Ozon, использовать как часть оценки;
2. если есть `quality_score` WB, использовать как часть оценки;
3. если marketplace score нет, считать локальную полноту по данным FeedTools.

### 4.5. Правильная модель расчета

Простой score `загружено / не загружено` будет слишком плоским. Нужно считать не только факт наличия товара на маркетплейсе, а глубину прохождения по контентной воронке.

Главный принцип:

1. Фактические количества показываем отдельно.
2. Score считаем отдельно.
3. Не смешиваем Ozon и WB в одну размытую цифру без детализации.
4. Не считаем товар успешным только потому, что он где-то найден.
5. Ошибки маркетплейсов должны снижать score даже если товар формально загружен.
6. Sellable считаем отдельно от content quality, но включаем в общий progress, потому что это финальная проверка, что карточка не просто есть, а дошла до рабочего состояния.

### 4.6. Деноминаторы: от чего считаем проценты

Чтобы расчет был честным, нужно явно хранить несколько знаменателей:

1. `products_total`: все товары поставщика в FeedTools.
2. `target_products_total`: товары, которые должны быть заведены на маркетплейсы сейчас; в текущей версии это товары с эффективным остатком больше нуля.
3. `marketplace_target_total`: `target_products_total * количество_целевых_маркетплейсов`.
4. `uploaded_any_total`: товар есть хотя бы на одном маркетплейсе.
5. `uploaded_all_total`: товар есть на всех целевых маркетплейсах.
6. `sellable_any_total`: товар продается хотя бы на одном маркетплейсе.
7. `sellable_all_total`: товар продается на всех целевых маркетплейсах.

Почему это важно:

1. `uploaded_any_total` показывает минимальное покрытие.
2. `uploaded_all_total` показывает полноту размещения.
3. Ozon и WB нельзя усреднять без понимания, что один поставщик может быть полностью заведен на Ozon и почти отсутствовать на WB.

### 4.7. Marketplace-level лестница состояния товара

Для каждой пары `product + marketplace` считаем `marketplace_content_stage_score` от 0 до 100.

Лестница:

1. `not_uploaded`: 0
   - товара нет на маркетплейсе.

2. `uploaded_archived`: 15
   - товар найден, но в архиве.
   - это не ноль, потому что связь с маркетплейсом есть, но пользы почти нет.

3. `uploaded_error`: 25
   - товар загружен, но есть ошибка.
   - факт загрузки есть, но карточка заблокирована.

4. `uploaded_revision`: 40
   - товар на доработке/модерации.
   - карточка ближе к готовности, чем error, но еще не работает.

5. `uploaded_not_ready`: 55
   - товар есть, явной ошибки нет, но он не подтвержден как ready.

6. `ready_not_sellable`: 75
   - карточка готова/активна, но не продается из-за цены, остатка или другого нефатального условия.

7. `sellable`: 100
   - товар готов и реально продается.

Для поставщика по маркетплейсу:

`marketplace_completion_score = average(marketplace_content_stage_score по всем target products)`

Отдельно показывать:

1. `ozon_completion_score`
2. `wb_completion_score`
3. `marketplace_completion_score` как среднее по целевым маркетплейсам.

### 4.8. Card quality score товара

`card_quality_score` от 0 до 100 считается отдельно от статуса маркетплейса.

Базовые веса:

1. Идентификация товара: 15
   - название есть и не слишком короткое: 8
   - бренд заполнен: 7

2. Категории: 20
   - Ozon категория заполнена, если Ozon целевой: 10
   - WB категория заполнена, если WB целевой: 10
   - если целевой только один marketplace, его категория получает все 20

3. Описание: 15
   - описание есть: 8
   - описание достаточно содержательное: 7

4. Фото: 20
   - есть хотя бы одно фото: 10
   - 3+ фото: 6
   - 5+ фото или хорошее покрытие: 4

5. Характеристики и параметры: 20
   - есть параметры/характеристики: 8
   - есть габариты/вес, где применимо: 6
   - заполнены marketplace-specific поля: 6

6. Сигналы маркетплейса: 10
   - Ozon `content_rating`, если есть.
   - WB `quality_score`, если есть.
   - если сигналов нет, эта часть распределяется между локальными блоками пропорционально.

На первом этапе обязательные характеристики можно считать грубо по наличию параметров. Потом заменить на точную проверку по taxonomy.

### 4.9. Штрафы за ошибки карточки

После базового `card_quality_score` применяются штрафы.

Штрафы:

1. Нет названия: score не выше 50.
2. Нет категории целевого маркетплейса: score не выше 70.
3. Нет фото: score не выше 65.
4. Нет бренда: минус 8.
5. Нет описания: минус 10.
6. Ошибка маркетплейса: минус 15 для соответствующего marketplace.
7. Доработка/модерация: минус 8 для соответствующего marketplace.
8. Архив: минус 20 для соответствующего marketplace.

Правило caps важнее простых минусов:

1. Карточка без фото не должна получать высокий score только из-за заполненных параметров.
2. Карточка без категории не должна выглядеть почти готовой.
3. Карточка с marketplace error не должна считаться качественной, даже если локально заполнена хорошо.

### 4.10. Error health score

`error_health_score` показывает, насколько поставщик чистый от контентных проблем.

Формула:

`error_health_score = 100 - normalized_issue_penalty`

Вес ошибок:

1. Critical: 8 points за долю товара с такой ошибкой.
2. Major: 4 points.
3. Minor: 1.5 points.

Пример:

1. 20% товаров с critical error дают большой штраф.
2. 20% товаров с minor issue дают небольшой штраф.

Чтобы score не уходил ниже нуля:

`error_health_score = max(0, min(100, error_health_score))`

### 4.11. Sellable score

`sellable_score` должен отвечать на простой вопрос: какая доля товаров реально дошла до рабочего состояния.

Считаем две метрики:

1. `sellable_any_score = sellable_any_total / target_products_total * 100`
2. `sellable_all_score = sellable_all_total / target_products_total * 100`

Для общего score используем:

`sellable_score = sellable_any_score * 0.7 + sellable_all_score * 0.3`

Почему так:

1. Для первой версии важнее, что товар продается хотя бы где-то.
2. Но полное покрытие Ozon + WB тоже должно давать дополнительный вес.

### 4.12. Upload score

`upload_score` тоже лучше считать не только как `uploaded_any`.

Формула:

`upload_score = uploaded_any_percent * 0.55 + uploaded_all_percent * 0.25 + marketplace_completion_score * 0.20`

Где:

1. `uploaded_any_percent`: товары есть хотя бы на одном маркетплейсе.
2. `uploaded_all_percent`: товары есть на всех целевых маркетплейсах.
3. `marketplace_completion_score`: средняя глубина прохождения по marketplace ladder.

Так поставщик, у которого товары просто заведены с ошибками, не будет выглядеть так же хорошо, как поставщик, у которого товары ready/sellable.

### 4.13. Итоговый content progress score

Итоговый `content_progress_score`:

`content_progress_score = upload_score * 0.35 + sellable_score * 0.25 + avg_card_quality_score * 0.25 + error_health_score * 0.15`

Но с дополнительными ограничениями:

1. Если `products_total = 0`, score = 0 и статус `no_products`.
2. Если `uploaded_any_percent < 10`, итоговый score не выше 35.
3. Если `sellable_any_percent = 0`, итоговый score не выше 70.
4. Если `critical_issues_percent > 30`, итоговый score не выше 65.
5. Если данные маркетплейса устарели, score показываем с пометкой low confidence, но не пересчитываем как плохой контент.

Эти ограничения нужны, чтобы поставщик с красивыми локальными карточками, но почти без загрузки, не получал высокий общий progress.

### 4.14. Data confidence

`data_confidence_score` не является частью content score, но обязательно показывается рядом.

Факторы:

1. Свежесть импорта поставщика.
2. Свежесть Ozon sync.
3. Свежесть WB sync.
4. Есть ли marketplace connection.
5. Нормально ли сопоставляются `offer_id` / `vendor_code`.

Уровни:

1. `high`: данные свежие.
2. `medium`: часть данных устарела.
3. `low`: score может быть неверным из-за устаревшей синхронизации или плохого matching.

Почему это важно:

1. Если Ozon не синхронизировали неделю, нельзя уверенно говорить, что товары не загружены.
2. Если vendor_code не совпадает, WB coverage может быть занижен.

### 4.15. Объяснение score

Для каждого поставщика сохранять `score_breakdown_json`.

Пример структуры:

```json
{
  "content_progress_score": 58.4,
  "upload_score": 63.2,
  "sellable_score": 41.8,
  "avg_card_quality_score": 72.5,
  "error_health_score": 54.0,
  "caps_applied": ["sellable_any_percent_zero"],
  "main_reasons": [
    "312 товаров не загружено на WB",
    "84 товара с ошибками Ozon",
    "146 товаров без фото",
    "sellable только 28% ассортимента"
  ],
  "data_confidence": "medium"
}
```

На странице поставщика нужно показывать не только число, но и причины:

1. что сильнее всего снижает score;
2. сколько товаров затронуто;
3. какой marketplace проблемный;
4. какие ошибки исправимы.

### 4.16. Управленческий приоритет поставщика

Общий список поставщиков должен отвечать не только на вопрос "у кого ниже score", но и на вопрос "с кем работать первым". Поэтому поверх `content_progress_score` считается отдельный `management_priority`.

Приоритет считается только по контентному состоянию и не учитывает экономический потенциал:

1. Разрыв до 100 по `content_progress_score`.
2. Доля товаров с остатком, которые не загружены ни на один marketplace.
3. Доля товаров с ошибками или доработками.
4. Количество базовых пробелов: категория, бренд, название, описание, фото, цена.
5. Разрыв между "загружено" и "реально продается".
6. Среднее качество карточек.
7. Отрицательная динамика за выбранный период.
8. Масштаб контентной работы по количеству проблемных товаров, без привязки к деньгам.

Результат:

1. `Срочно`
2. `Высокий`
3. `Средний`
4. `Поддерживать`
5. `Выгрузка не нужна`, если у поставщика нет товаров с остатком.

Вместе с приоритетом показываем главный фокус: `Загрузка`, `Ошибки и доработка`, `Базовые данные`, `Продаваемость`, `Качество карточек`, `Поддержка`. Это не задачи по товарам, а агрегированный ориентир для пакетной работы с группами товаров.

Общий дашборд должен поддерживать рабочие срезы:

1. фильтр по приоритету;
2. фильтр по главному фокусу;
3. фильтр по движению за период: улучшились, просели, есть изменение, нет базы сравнения;
4. поиск по поставщику или коду;
5. агрегаты периода прямо в списке: сколько поставщиков улучшились или просели, как изменились загрузка, продаваемость, ошибки и средний progress.

## 5. Сравнение периодов

Для мониторинга прогресса нужны исторические слепки.

### 5.1. Что сравниваем

За выбранный период:

1. Сколько товаров стало в источнике.
2. Сколько товаров добавлено на Ozon.
3. Сколько товаров добавлено на WB.
4. Сколько товаров стало ready.
5. Сколько товаров стало sellable.
6. Сколько ошибок появилось.
7. Сколько ошибок исправлено.
8. Как изменился `card_quality_score`.
9. Как изменился `content_progress_score`.

### 5.2. Вклад пользователя

Пользовательский вклад считаем по операциям и изменениям состояния:

1. товары загружены на маркетплейс после операции пользователя;
2. товары стали ready;
3. товары стали sellable;
4. исправлены ошибки карточек;
5. улучшено качество карточек;
6. заполнены категории;
7. заполнен бренд;
8. улучшены названия/описания/фото.

Не считаем экономический эффект. Только контентный прогресс.

## 6. Модель данных

### 6.1. Текущая оценка товара

Таблица:

`feedtools_supplier_content_assessments`

Поля:

1. `id`
2. `supplier_id`
3. `product_id`
4. `offer_id`
5. `vendor_code`
6. `marketplace`
7. `connection_id`
8. `upload_status`
9. `normalized_status`
10. `is_uploaded`
11. `is_ready`
12. `is_sellable`
13. `marketplace_stage_score`
14. `card_quality_score`
15. `issue_penalty`
16. `issues_json`
17. `quality_breakdown_json`
18. `metrics_json`
19. `assessed_at`
20. `created_at`
21. `updated_at`

Индексы:

1. `(supplier_id, marketplace, product_id)`
2. `(supplier_id, marketplace, normalized_status)`
3. `(supplier_id, card_quality_score)`

### 6.2. Слепок поставщика

Таблица:

`feedtools_supplier_content_snapshots`

Поля:

1. `id`
2. `captured_at`
3. `capture_date`
4. `supplier_id`
5. `marketplace`
6. `connection_id`
7. `products_total`
8. `uploaded_total`
9. `not_uploaded_total`
10. `ready_total`
11. `sellable_total`
12. `error_total`
13. `revision_total`
14. `archived_total`
15. `critical_issues_total`
16. `fixable_issues_total`
17. `avg_card_quality_score`
18. `upload_score`
19. `sellable_score`
20. `error_health_score`
21. `marketplace_completion_score`
22. `content_progress_score`
23. `data_confidence_level`
24. `data_confidence_score`
25. `metrics_json`
26. `issue_breakdown_json`
27. `score_breakdown_json`
28. `data_warnings_json`
29. `created_at`

Индексы:

1. `(supplier_id, marketplace, connection_id, captured_at)`
2. `(capture_date, supplier_id)`
3. `(content_progress_score, captured_at)`

### 6.3. События контентного прогресса

Таблица:

`feedtools_supplier_content_events`

Поля:

1. `id`
2. `created_at`
3. `period_date`
4. `actor_user`
5. `actor_kind`: `human`, `system`, `automation`
6. `supplier_id`
7. `product_id`
8. `offer_id`
9. `marketplace`
10. `connection_id`
11. `op_id`
12. `op_type`
13. `event_type`
14. `from_value`
15. `to_value`
16. `issue_code`
17. `content_points`
18. `details_json`

Типы событий:

1. `product_uploaded`
2. `status_became_ready`
3. `status_became_sellable`
4. `status_regressed`
5. `issue_created`
6. `issue_resolved`
7. `category_assigned`
8. `brand_assigned`
9. `title_improved`
10. `description_improved`
11. `images_improved`
12. `quality_score_improved`

`content_points` нужны только для сортировки вклада. Это не деньги и не KPI зарплаты.

## 7. Модуль приложения

Создать:

`app/supplier_content_progress.php`

Функции:

1. `supplier_content_progress_tables_ensure()`
2. `supplier_content_progress_capture_snapshot(int $supplierId, array $cfg = []): array`
3. `supplier_content_progress_assess_product(array $product, string $marketplace, ?array $marketplaceRow, array $connections): array`
4. `supplier_content_progress_normalize_marketplace_status(string $marketplace, ?array $row, array $product): array`
5. `supplier_content_progress_stage_score(string $normalizedStatus): float`
6. `supplier_content_progress_detect_issues(array $product, string $marketplace, array $state): array`
7. `supplier_content_progress_calculate_card_quality(array $product, string $marketplace, ?array $row, array $state): array`
8. `supplier_content_progress_calculate_summary(int $supplierId, array $products, array $assessments, array $connections, array $cfg = []): array`
9. `supplier_content_progress_calculate_data_confidence(int $supplierId, array $metrics, array $connections, array $cfg = []): array`
10. `supplier_content_progress_fetch_portfolio(array $cfg, array $filters = []): array`
11. `supplier_content_progress_fetch_supplier(int $supplierId, array $cfg, array $filters = []): array`
12. `supplier_content_progress_fetch_supplier_analytics(int $supplierId, array $cfg = []): array`
13. `supplier_content_progress_snapshot_delta(int $supplierId, array $period): array`
14. `supplier_content_progress_deep_delta(int $supplierId, array $period): array`
15. `supplier_content_progress_fetch_contributions(array $period, array $cfg = []): array`
16. `supplier_content_progress_fetch_supplier_contributions(int $supplierId, array $period, array $cfg = []): array`
17. `supplier_content_progress_fetch_monitoring(array $cfg, array $filters = []): array`

CLI:

1. `bin/run_supplier_content_snapshots.php`: ежедневный расчет snapshots по всем активным поставщикам.
2. Основной cron-вызов: `cd /var/www/feedtools && /usr/bin/php bin/run_supplier_content_snapshots.php --include_inactive=0 >> storage/logs/content-snapshots-cron.log 2>&1`.

## 8. Страницы

### 8.1. Портфель поставщиков

Страница:

`public/supplier_content_progress.php`

Блоки:

1. Общие KPI:
   - поставщиков;
   - товаров всего;
   - загружено на Ozon;
   - загружено на WB;
   - не загружено;
   - продается;
   - с ошибками;
   - средний content progress.

2. Фильтры:
   - период;
   - маркетплейс;
   - поставщик;
   - статус загрузки;
   - тип ошибки.

3. Таблица поставщиков:
   - поставщик;
   - товаров всего;
   - Ozon uploaded / ready / sellable / errors;
   - WB uploaded / ready / sellable / errors;
   - не загружено;
   - среднее качество карточки;
   - content progress;
   - изменение за период;
   - главные ошибки.

### 8.2. Страница поставщика

Страница:

`public/supplier_content_progress_supplier.php?supplier_id=...`

Блоки:

1. Верхняя score-панель.
2. Воронка:
   - всего товаров;
   - загружено;
   - готово;
   - продается;
3. Детальная аналитика поставщика:
   - товарные позиции в цели и строки Ozon/WB;
   - срез состояния по агрегированным группам проблем;
   - статусы отдельно по каждому маркетплейсу;
   - распределение качества карточек;
   - базовые пробелы и главные причины;
   - интерпретация текущего состояния и главный фокус.
4. Вклад пользователей по поставщику:
   - кто работал с поставщиком за период;
   - вклад в content points;
   - типы работ: выгрузка, качество, каталог;
   - последние контентные операции.

### 8.3. Мониторинг периода

Страница:

`public/supplier_content_progress_monitoring.php`

Блоки:

1. Изменение портфеля за период:
   - сколько поставщиков улучшились;
   - средний прирост content progress;
   - прирост загруженных товаров;
   - прирост товаров в продаже;
   - изменение среднего качества карточек;
   - изменение ошибок и доработок.

2. Вклад пользователей:
   - пользователь;
   - тип actor: пользователь, автоматизация, система, не указан;
   - количество завершенных контентных операций;
   - обработанные товары;
   - улучшения карточек;
   - отправки на маркетплейсы;
   - относительные content points.

3. Поставщики за период:
   - текущий progress;
   - изменение progress;
   - изменение uploaded/ready/sellable/errors;
   - вклад операций по поставщику;
   - ведущий actor по поставщику.

### 8.4. Что намеренно не делаем сейчас

Отдельную страницу задач и очереди работ отключаем. Текущий интерфейс должен отвечать на вопрос "в каком состоянии поставщик и что изменилось за период", а не создавать тысячи ручных задач.

Вместо задач показываем агрегированные срезы:

1. не загружено;
2. ошибки и доработки маркетплейсов;
3. загружено, но не продается;
4. качество ниже порога;
5. нет базовых данных.

Каждый срез открывает существующую страницу товаров поставщика с `content_filter`, где можно выбрать всю найденную группу и применять уже существующие массовые инструменты: назначение категорий, заполнение полей, импорт из фида, GPT-операции, выгрузку на маркетплейсы. Эта подсистема не ставит задачи; она фиксирует состояние, дает вход в пакетную правку и измеряет эффект после изменений.

## 9. Визуальная логика

Страница должна быть рабочей и плотной:

1. Без маркетингового hero.
2. Без сложной декоративности.
3. KPI сверху, дальше таблица и фильтры.
4. Цвета только для статусов:
   - зеленый: продается/готово;
   - синий: загружено;
   - желтый: на доработке;
   - красный: ошибка;
   - серый: не загружено/архив.
5. Таблица должна быстро отвечать: где контент загружен, где нет, где карточки плохие.

## 10. Этапы реализации

### Этап 1. Базовый расчет

1. Создать `app/supplier_content_progress.php`.
2. Добавить таблицы assessments/snapshots/events.
3. Реализовать оценку товара по Ozon/WB.
4. Реализовать базовые ошибки карточек.
5. Реализовать marketplace ladder score.
6. Реализовать качество карточки с breakdown и caps.
7. Реализовать upload/sellable/error health/component scores.
8. Реализовать data confidence.
9. Реализовать content progress score.
10. Реализовать snapshot поставщика.

### Этап 2. Портфельный дашборд

1. Создать `public/supplier_content_progress.php`.
2. Добавить ссылку в главную навигацию.
3. Показать KPI по всем поставщикам.
4. Показать таблицу поставщиков.
5. Добавить фильтры.

### Этап 3. Дашборд поставщика

1. Создать `public/supplier_content_progress_supplier.php`.
2. Показать воронку загрузки.
3. Показать Ozon/WB статусы.
4. Показать качество карточек.
5. Показать ошибки по типам.
6. Показать проблемные товары.

### Этап 4. Периоды

1. Добавить сравнение snapshots за период.
2. Показать прирост uploaded/ready/sellable.
3. Показать исправленные и новые ошибки.
4. Показать изменение качества карточек.

### Этап 5. Пользователи

1. Связать content events с `feedtools_operations.created_by`.
2. Учитывать root operation для pipeline.
3. Показать вклад пользователей за период.
4. Показать вклад пользователей по поставщику.
5. До полноценной авторизации использовать операторскую cookie как явный actor для ручных операций.
6. После включения аккаунтов главным источником actor становится авторизованный пользователь, операторская cookie остается fallback.

## 11. MVP

Первая рабочая версия должна дать:

1. Понимание, сколько товаров поставщика загружено/не загружено.
2. Понимание, сколько товаров реально продается.
3. Понимание, где ошибки карточек.
4. Понимание, насколько карточки качественно заполнены.
5. Сравнение состояния за период.
6. Базовый вклад пользователей в контентный прогресс.

Это достаточно сильный, но не раздутый scope.
