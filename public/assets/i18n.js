(function () {
  'use strict';

  if ((document.documentElement.dataset.ftLang || 'ru') !== 'en') return;

  const exact = Object.assign({}, window.FeedToolsI18nCatalog || {}, {
    'Главная': 'Home',
    'Назад': 'Back',
    'Вперёд': 'Next',
    'Поставщики': 'Suppliers',
    'Поставщик': 'Supplier',
    'Поставщик:': 'Supplier:',
    'Товары поставщиков': 'Supplier products',
    'Товары поставщика': 'Supplier products',
    'Контент': 'Content',
    'Контентный прогресс': 'Content progress',
    'Динамика': 'Trends',
    'Динамика контента': 'Content trends',
    'Аналитика': 'Analytics',
    'Аналитика Ozon': 'Ozon analytics',
    'Аналитика WB': 'WB analytics',
    'Аналитика сотрудников': 'Staff analytics',
    'Подключения': 'Connections',
    'Подключения маркетплейсов': 'Marketplace connections',
    'XML-фиды': 'XML feeds',
    'Парсинг фидов': 'Feed parsing',
    'Бренды': 'Brands',
    'Бренд': 'Brand',
    'Бренды маркетплейсов': 'Marketplace brands',
    'Категории': 'Categories',
    'Категории Ozon': 'Ozon categories',
    'Категории WB': 'WB categories',
    'Замены характеристик': 'Attribute replacements',
    'Журнал GPT': 'GPT log',
    'Журнал GPT-запросов': 'GPT request log',
    'Очередь операций': 'Operation queue',
    'Справочники': 'Reference data',
    'Состояние': 'Status overview',
    'Активные операции': 'Active operations',
    'Операции': 'Operations',
    'Операция': 'Operation',
    'Операция #': 'Operation #',
    'Статус': 'Status',
    'Этап': 'Stage',
    'Прогресс': 'Progress',
    'Время': 'Time',
    'Прошло': 'Elapsed',
    'Старт': 'Started',
    'Финиш': 'Finished',
    'Дата': 'Date',
    'Период': 'Period',
    'Период:': 'Period:',
    'период': 'period',
    'От': 'From',
    'До': 'To',
    'Сегодня': 'Today',
    '7 дней': '7 days',
    '30 дней': '30 days',
    '90 дней': '90 days',
    'последние 90 дней': 'last 90 days',
    'Свой': 'Custom',
    'Дней': 'Days',
    'дней': 'days',
    'Часы': 'Hours',
    'Минуты': 'Minutes',
    'Сейчас': 'Now',
    'Сейчас:': 'Now:',
    'Последнее обновление': 'Last updated',
    'Последний запуск': 'Last run',
    'Последний запуск:': 'Last run:',
    'Последняя операция': 'Last operation',
    'Последняя активность': 'Last activity',
    'Порядок': 'Order',
    'Приоритет': 'Priority',
    'Действия': 'Actions',
    'Главное действие': 'Primary action',
    'Настройки': 'Settings',
    'Настройки профиля': 'Profile settings',
    'Профиль': 'Profile',
    'Название профиля': 'Profile name',
    'Профиль #': 'Profile #',
    'Профили': 'Profiles',
    'Профили остатков': 'Stock profiles',
    'Конвейеры': 'Pipelines',
    'Запуски': 'Runs',
    'Запусков': 'Runs',
    'Текущий запуск': 'Current run',
    'Автоматизация': 'Automation',
    'Добавить автоматизацию': 'Add automation',
    'Периодичность запусков': 'Run frequency',
    'Синхронизация': 'Synchronization',
    'Синхронизировать': 'Synchronize',
    'Запустить синхронизацию': 'Start synchronization',
    'Последние синхронизации': 'Recent synchronizations',
    'Массовая выгрузка': 'Bulk export',
    'Выгрузка': 'Export',
    'Экспорт': 'Export',
    'Импорт': 'Import',
    'Загрузка': 'Upload',
    'Файл': 'File',
    'Шаблон': 'Template',
    'Датасет': 'Dataset',
    'Лист:': 'Sheet:',
    'Источник': 'Source',
    'Источник:': 'Source:',
    'Источник данных': 'Data source',
    'Источник цен': 'Price source',
    'Источник тарифов': 'Tariff source',
    'Источник расходов': 'Expense source',
    'Фид поставщика': 'Supplier feed',
    'Фид по ссылке': 'Feed by URL',
    'Схема:': 'Scheme:',
    'Склад': 'Warehouse',
    'Склад Ozon': 'Ozon warehouse',
    'Склад МойСклад': 'MoySklad warehouse',
    'Текущий кабинет': 'Current account',
    'Выбрать кабинет': 'Select account',
    'Кабинет': 'Account',
    'Маркетплейс': 'Marketplace',
    'Контрагент': 'Counterparty',
    'Сотрудник': 'Employee',
    'Пользователь': 'User',
    'Оператор': 'Operator',
    'Название': 'Name',
    'Наименование': 'Name',
    'Короткое название': 'Short name',
    'Описание': 'Description',
    'Заметки': 'Notes',
    'Тип': 'Type',
    'Группа': 'Group',
    'Модель': 'Model',
    'Цвет': 'Color',
    'Характеристика': 'Attribute',
    'Характеристики': 'Attributes',
    'характеристики': 'Attributes',
    'название': 'Name',
    'описание': 'Description',
    'цвет': 'Color',
    'фишки': 'Key features',
    'обложка': 'Cover',
    'видео': 'Video',
    'модель': 'Model',
    'хештеги': 'Hashtags',
    'доп.фото': 'Extra photos',
    'ТНВЭД': 'HS code',
    'Размеры': 'Dimensions',
    'Длина': 'Length',
    'Ширина': 'Width',
    'Высота': 'Height',
    'Вес': 'Weight',
    'Артикул': 'SKU',
    'Артикул продавца': 'Seller SKU',
    'Партномер': 'Part number',
    'Штрихкод': 'Barcode',
    'Баркод': 'Barcode',
    'Баркоды': 'Barcodes',
    'Категория': 'Category',
    'Категория Ozon': 'Ozon category',
    'Категория WB': 'WB category',
    'Категория продавца': 'Seller category',
    'Категория поставщика': 'Supplier category',
    'Фото': 'Photos',
    'Видео': 'Video',
    'Видеообложка': 'Video cover',
    'Видео-обложка': 'Video cover',
    'Ссылка': 'Link',
    'Ссылка на главное фото': 'Main image URL',
    'Ссылка на главное фото*': 'Main image URL*',
    'Ссылки на дополнительные фото': 'Additional image URLs',
    'Ссылки на фото 360': '360° image URLs',
    'Артикул фото': 'Image SKU',
    'Название товара': 'Product name',
    'Аннотация': 'Annotation',
    'Цена': 'Price',
    'Стоимость': 'Cost',
    'Закупка': 'Purchase cost',
    'Закупочная цена': 'Purchase price',
    'Текущая цена': 'Current price',
    'Обычная цена': 'Regular price',
    'Базовая цена': 'Base price',
    'Базовая цена WB': 'WB base price',
    'Текущая цена Ozon': 'Current Ozon price',
    'Текущая цена WB': 'Current WB price',
    'Расчётная цена': 'Calculated price',
    'Целевая цена': 'Target price',
    'Плановая цена': 'Planned price',
    'Точная цена': 'Exact price',
    'Минимальная цена': 'Minimum price',
    'Цена для акции': 'Promotion price',
    'Цена и скидка': 'Price and discount',
    'Цена до скидки, руб.': 'Price before discount, RUB',
    'Цена, руб.': 'Price, RUB',
    'Цена, руб.*': 'Price, RUB*',
    'Индекс цены': 'Price index',
    'Индекс': 'Index',
    'Индекс в акции': 'Promotion index',
    'Акция': 'Promotion',
    'Акции': 'Promotions',
    'Акция Ozon': 'Ozon promotion',
    'Акция WB': 'WB promotion',
    'Товары в акции': 'Products in promotion',
    'Товары в акции:': 'Products in promotion:',
    'Скидка': 'Discount',
    'Скидка продавца': 'Seller discount',
    'Скидка клуба': 'Club discount',
    'Комиссия': 'Commission',
    'Комиссия WB': 'WB commission',
    'Налоги': 'Taxes',
    'НДС, %': 'VAT, %',
    'НДС, %*': 'VAT, %*',
    'Ставка НДС': 'VAT rate',
    'Налоговый режим': 'Tax regime',
    'Ставка налога, %': 'Tax rate, %',
    'Налог на прибыль, %': 'Profit tax, %',
    'Расходы': 'Expenses',
    'Суммарные расходы': 'Total expenses',
    'Фиксированные расходы, ₽': 'Fixed expenses, RUB',
    'Прочие расходы, %': 'Other expenses, %',
    'Доход': 'Income',
    'Доход, ₽': 'Income, RUB',
    'Доход, %': 'Income, %',
    'Выручка': 'Revenue',
    'Вклад': 'Contribution',
    'Остаток': 'Stock',
    'ост.': 'stock',
    'база ост.:': 'base stock:',
    'закупка:': 'purchase:',
    'Заказы': 'Orders',
    'заказы': 'orders',
    'Заказано, шт': 'Ordered, pcs',
    'Отмены': 'Cancellations',
    'Показы': 'Impressions',
    'показы': 'impressions',
    'Переходы': 'Clicks',
    'переходы': 'visits',
    'Переходы в карточку': 'Product page visits',
    'Корзина': 'Cart',
    'корзина': 'cart',
    'продажи': 'sales',
    'в карточку': 'to product page',
    'в заказ': 'to order',
    'отмены': 'cancellations',
    'видимость': 'visibility',
    'из поиска': 'from search',
    'выкупы': 'buyouts',
    'выкуп': 'buyout',
    'Качество': 'Quality',
    'Улучшения': 'Improvements',
    'Ошибки': 'Errors',
    'Ошибка': 'Error',
    'Ошибка:': 'Error:',
    'Предупреждения': 'Warnings',
    'Предупреждение': 'Warning',
    'Сводка': 'Summary',
    'Итог': 'Result',
    'Рекомендации': 'Recommendations',
    'Тарифы': 'Tariffs',
    'Режим': 'Mode',
    'Режим цены': 'Pricing mode',
    'Модификатор цены': 'Price modifier',
    'Округление': 'Rounding',
    'Все': 'All',
    'Все товары': 'All products',
    'Все поставщики': 'All suppliers',
    'Выбрано': 'Selected',
    'Выбранные товары': 'Selected products',
    'Товар': 'Product',
    'Товары': 'Products',
    'Товаров': 'Products',
    'Строк': 'Rows',
    'строк': 'rows',
    'Фото': 'Photos',
    'фото': 'photos',
    'видео': 'Video',
    'Да': 'Yes',
    'Нет': 'No',
    'нет': 'no',
    'или': 'or',
    'из': 'of',
    'до': 'to',
    'от': 'from',
    'Все': 'All',
    'Не выбрано': 'Not selected',
    'Не выбран': 'Not selected',
    'не выбран': 'not selected',
    'не указан': 'not specified',
    'Не задавать': 'Do not set',
    'Не применять': 'Do not apply',
    'Не учитывать': 'Do not include',
    'Не обновлять': 'Do not update',
    'Не менять статус': 'Do not change status',
    'Не задавать статус': 'Do not set status',
    'Использовать склад по умолчанию': 'Use default warehouse',
    'Без изменений': 'No changes',
    'Без ошибок': 'No errors',
    'Не учитываются': 'Not included',
    '∅ (пусто)': '∅ (empty)',
    'Включено': 'Enabled',
    'Включена': 'Enabled',
    'Выключено': 'Disabled',
    'Выключена': 'Disabled',
    'Готово': 'Done',
    'Готово.': 'Done.',
    'Готов': 'Ready',
    'Готова': 'Ready',
    'Успешно': 'Successful',
    'Выполнено': 'Completed',
    'Выполняется': 'Running',
    'Сейчас выполняется': 'Running now',
    'Обработано': 'Processed',
    'Загружено': 'Uploaded',
    'Не загружено': 'Not uploaded',
    'не добавлен': 'not added',
    'в архиве': 'archived',
    'в автоархиве': 'auto-archived',
    'на доработку': 'needs revision',
    'готов к продаже': 'ready for sale',
    'нет данных WB': 'no WB data',
    'категория не подходит': 'category does not match',
    'категория не выбрана': 'category not selected',
    'ошибка бренда': 'brand error',
    'бренд не существует': 'brand does not exist',
    'Продается': 'Selling',
    'в продаже': 'on sale',
    'снят': 'off sale',
    'не отправляется': 'not sent',
    'Частично': 'Partially',
    'Пауза': 'Paused',
    'Пропущено': 'Skipped',
    'Пропуск': 'Skip',
    'Черновик': 'Draft',
    'Скоро': 'Soon',
    'архив': 'archive',
    'Открыть': 'Open',
    'открыть': 'open',
    'Показать': 'Show',
    'Скрыть': 'Hide',
    'Найти': 'Find',
    'Обновить': 'Refresh',
    'Обновлено': 'Updated',
    'Обновлено сегодня': 'Updated today',
    'Обновлено вчера': 'Updated yesterday',
    'Пересчитать': 'Recalculate',
    'Сохранить': 'Save',
    'Сохранить настройки': 'Save settings',
    'Сохранить профиль': 'Save profile',
    'Редактировать': 'Edit',
    'Добавить': 'Add',
    'Добавить профиль': 'Add profile',
    'Добавить диапазон': 'Add range',
    'Удалить': 'Delete',
    'Удалить профиль': 'Delete profile',
    'Убрать': 'Remove',
    'Заменить': 'Replace',
    'Сбросить': 'Reset',
    'Отмена': 'Cancel',
    'Запустить': 'Run',
    'Импортировать': 'Import',
    'Подготовить выгрузку': 'Prepare export',
    'Показать расчёт': 'Show calculation',
    'Показать статистику': 'Show statistics',
    'Выбрать все': 'Select all',
    'Снять выбор': 'Clear selection',
    'очистить': 'clear',
    'выбрать': 'select',
    'добавить': 'add',
    'скачать': 'download',
    '← предыдущая': '← previous',
    'следующая →': 'next →',
    'Страница': 'Page',
    'Показано:': 'Shown:',
    'Всего:': 'Total:',
    'К датасету': 'Back to dataset',
    'К прогрессу': 'Back to progress',
    'К выгрузке': 'Back to export',
    'Открыть поставщиков': 'Open suppliers',
    'Открыть управление': 'Open management',
    'Открыть прогресс': 'Open progress',
    'Открыть подключения': 'Open connections',
    'Открыть аналитику': 'Open analytics',
    'Открыть аналитику WB': 'Open WB analytics',
    'Открыть FBO': 'Open FBO',
    'Открыть XML-фиды': 'Open XML feeds',
    'Открыть раздел поставщиков': 'Open suppliers section',
    'Открыть датасет': 'Open dataset',
    'Открыть HTML-отчёт': 'Open HTML report',
    'Предпросмотр всего фида': 'Preview entire feed',
    'Тест одного артикула': 'Single SKU test',
    'Артикул / offer_id': 'SKU / offer_id',
    'Текущий статус в МойСклад': 'Current MoySklad status',
    'Статус нового заказа': 'New order status',
    'Статус для обновления': 'Update status',
    'Что ставить при отмене': 'Status to set on cancellation',
    'Пока нет данных.': 'No data yet.',
    'Пока нет.': 'Nothing yet.',
    'Нет данных.': 'No data.',
    'Операций пока нет.': 'No operations yet.',
    'Пока не запускалась': 'Not run yet',
    'Неизвестное действие.': 'Unknown action.',
    'Некорректный id.': 'Invalid ID.',
    'Поставщик не найден.': 'Supplier not found.',
    'Пустой файл.': 'The file is empty.',
    'Файл слишком большой.': 'The file is too large.',
    'Нужен файл .xlsx': 'An .xlsx file is required',
    'Укажи ссылку на фид.': 'Enter the feed URL.',
    'Выбери источник импорта.': 'Select an import source.',
    'Выбери поставщика': 'Select a supplier',
    'Выбери склад': 'Select a warehouse',
    'Выбери текущий статус': 'Select the current status',
    'Выбери целевой статус': 'Select the target status',
    'Сначала выбери аккаунт МойСклад': 'Select a MoySklad account first',
    'Сначала выбери профиль синхронизации для этого маркетплейса.': 'Select a synchronization profile for this marketplace first.',
    'Кабинет пока не выбран. Открой нужное подключение из общего раздела.': 'No account is selected. Open the required connection from the main section.',
    'Настройка автоматизации сохранена.': 'Automation settings saved.',
    'Настройка автоматизации удалена.': 'Automation settings deleted.',
    'Страница загрузилась с ошибкой': 'The page loaded with an error',
    'Рабочая панель сервиса: товары поставщиков, подключения маркетплейсов, цены, остатки, заказы и справочники.': 'Service dashboard: supplier products, marketplace connections, prices, stock, orders, and reference data.',
    'Импорт, таблица offers, контент, характеристики, фото, комплекты и экспорт на маркетплейсы.': 'Import, offers table, content, attributes, photos, bundles, and marketplace export.',
    'Запуск парсинга остатков, пересборка XML и автоматизация обновлений по поставщикам.': 'Run stock parsing, rebuild XML, and automate supplier updates.',
    'Дашборд загрузки товаров на Ozon/WB, продаваемости, ошибок и качества карточек по поставщикам.': 'Dashboard for Ozon/WB uploads, sales status, errors, and listing quality by supplier.',
    'Кабинеты Ozon, Wildberries и Яндекс Маркета, доступ к Price Tool, Stocks Tool и Orders Sync.': 'Ozon, Wildberries, and Yandex Market accounts with access to Price Tool, Stocks Tool, and Orders Sync.',
    'Расчет цен, правила по поставщикам, индекс цены, акции и ручные проверки артикула.': 'Price calculation, supplier rules, price index, promotions, and manual SKU checks.',
    'Остатки по фидам поставщиков, буферы, резервы, комплекты и выгрузка на маркетплейсы.': 'Supplier feed stock, buffers, reserves, bundles, and marketplace export.',
    'Ручная распродажа небольшого остатка: склад Ozon, сниженная цена и возврат к обычному расчету.': 'Manual clearance of low stock: Ozon warehouse, reduced price, and return to regular calculation.',
    'Синхронизация заказов с МойСклад, автоматизации, склады и статусы заказов.': 'Order synchronization with MoySklad, automations, warehouses, and order statuses.',
    'Остатки FBO Ozon, сниженные цены, FBS-обнуление и контроль распродажи склада.': 'Ozon FBO stock, reduced prices, FBS zeroing, and warehouse clearance control.',
    'Показы, переходы, конверсии, продажи, возвраты, отмены и рекламные метрики по товарам.': 'Impressions, clicks, conversions, sales, returns, cancellations, and product advertising metrics.',
    'Переходы в карточку, корзина, заказы, выкупы, отмены и конверсии Wildberries по товарам.': 'Product page visits, carts, orders, completed purchases, cancellations, and Wildberries product conversions.',
    'Сейчас нет операций в очереди или в работе.': 'There are no queued or running operations.',
    'Старая служба загрузки XML вынесена отдельно.': 'The legacy XML upload service is available separately.'
  });

  const patterns = [
    [/^Оператор:\s*указать$/u, 'Operator: set name'],
    [/^Оператор:\s*(.+)$/u, 'Operator: $1'],
    [/^Поставщик:\s*(.+)$/u, 'Supplier: $1'],
    [/^Товары поставщика:\s*(.+)$/u, 'Supplier products: $1'],
    [/^Код поставщика:\s*(.+)$/u, 'Supplier code: $1'],
    [/^Код:\s*(.+)$/u, 'Code: $1'],
    [/^Источник:\s*(.+)$/u, 'Source: $1'],
    [/^Период:\s*(.+)$/u, 'Period: $1'],
    [/^Ошибка:\s*(.+)$/u, 'Error: $1'],
    [/^Ошибка загрузки панели:\s*(.+)$/u, 'Dashboard loading error: $1'],
    [/^база ост\.:\s*(.*?)\s*·\s*закупка:\s*(.+)$/u, 'base stock: $1 · purchase: $2'],
    [/^база ост\.:\s*(.+)$/u, 'base stock: $1'],
    [/^за\s+(.+)$/u, 'for $1'],
    [/^Операция\s+#(\d+)$/u, 'Operation #$1'],
    [/^Открыть операцию\s+#(\d+)$/u, 'Open operation #$1'],
    [/^Профиль\s+#(\d+)$/u, 'Profile #$1'],
    [/^Синхронизация\s+#(\d+)$/u, 'Synchronization #$1'],
    [/^(\d[\d ]*)\s+товаров$/u, '$1 products'],
    [/^(\d[\d ]*)\s+товаров всего$/u, '$1 products total'],
    [/^(\d[\d ]*)\s+товаров в оценке$/u, '$1 products evaluated'],
    [/^(\d[\d ]*)\s+FBO товаров$/u, '$1 FBO products'],
    [/^(\d[\d ]*)\s+дневных строк$/u, '$1 daily rows'],
    [/^(\d[\d ]*)\s+поставщиков\s+·\s+(\d[\d ]*)\s+товаров$/u, '$1 suppliers · $2 products'],
    [/^(\d[\d ]*)\s+поставщиков$/u, '$1 suppliers'],
    [/^(\d[\d ]*)\s+подключений$/u, '$1 connections'],
    [/^(\d[\d ]*)\s+профилей$/u, '$1 profiles'],
    [/^(\d[\d ]*)\s+строк$/u, '$1 rows'],
    [/^Страница\s+(\d+)$/u, 'Page $1'],
    [/^выбрано:\s*(\d+)\s+из\s+(\d+)$/u, 'selected: $1 of $2'],
    [/^выбрано:\s*(\d+)$/u, 'selected: $1'],
    [/^Показано:\s*(.+)$/u, 'Shown: $1'],
    [/^Всего:\s*(.+)$/u, 'Total: $1'],
    [/^Последний запуск:\s*(.+)$/u, 'Last run: $1'],
    [/^Обновлено\s+(.+)$/u, 'Updated $1']
  ];

  const compositeKeys = Object.keys(exact)
    .filter((key) => key.length >= 5 && key.length <= 140 && !/[{}<>\n\r]/u.test(key))
    .sort((a, b) => b.length - a.length);

  const compositeUiSelector = [
    'title', 'button', 'a', 'label', 'th', 'legend', 'summary', 'option',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    '.muted', '.hint', '.help', '.flash', '.error', '.warning', '.notice',
    '.badge', '.status', '.pill', '.chip', '.tab',
    '[class*="title"]', '[class*="label"]', '[class*="status"]',
    '[class*="action"]', '[class*="summary"]', '[class*="meta"]',
    '.supplier-content-readiness', '.supplier-offer-controls',
    '.supplier-category-cell-label', '.supplier-ozon-analytics',
    '.supplier-analytics-empty', '.supplier-brand-status',
    '.supplier-market-status:not(.supplier-market-status--issue)'
  ].join(',');

  const tableUiSelector = [
    '[data-ft-i18n-ui]',
    '.supplier-content-readiness',
    '.supplier-offer-controls',
    '.supplier-category-cell-label',
    '.supplier-ozon-analytics',
    '.supplier-analytics-empty',
    '.supplier-brand-status',
    '.supplier-market-status:not(.supplier-market-status--issue)'
  ].join(',');

  const blockedContainers = [
    '[data-ft-i18n="off"]',
    '[translate="no"]',
    'code', 'pre', 'script', 'style', 'textarea',
    '.product-name', '.product-description', '.product-content',
    '.supplier-data', '.supplier-name', '.supplier-title',
    '.marketplace-data', '.current-connection-title', '.connection-title',
    '.profile-title', '.feed-select-title', '.feed-automation-title',
    '.promo-title', '.status-cell-title', '.offer-name', '.offer-description'
  ].join(',');

  function preserveSpacing(original, translated) {
    const leading = original.match(/^\s*/u)[0];
    const trailing = original.match(/\s*$/u)[0];
    return leading + translated + trailing;
  }

  function translateComposite(normalized) {
    for (const key of compositeKeys) {
      if (normalized.startsWith(key) && normalized.length > key.length) {
        const remainder = normalized.slice(key.length);
        if (/^[\s:;,.·#№()\[\]—–-]/u.test(remainder)) return exact[key] + remainder;
      }
      if (normalized.endsWith(key) && normalized.length > key.length) {
        const prefix = normalized.slice(0, -key.length);
        if (/[\s:;,.·#№()\[\]—–-]$/u.test(prefix)) return prefix + exact[key];
      }
    }
    return normalized;
  }

  function translateString(value, allowComposite) {
    if (typeof value !== 'string' || !/[А-Яа-яЁё]/u.test(value)) return value;
    const normalized = value.trim().replace(/\s+/gu, ' ');
    if (!normalized) return value;
    if (Object.prototype.hasOwnProperty.call(exact, normalized)) {
      return preserveSpacing(value, exact[normalized]);
    }
    const suffixMatch = normalized.match(/^(.*?)(\s+[→←])$/u);
    if (suffixMatch && Object.prototype.hasOwnProperty.call(exact, suffixMatch[1])) {
      return preserveSpacing(value, exact[suffixMatch[1]] + suffixMatch[2]);
    }
    const prefixMatch = normalized.match(/^([→←]\s+)(.+)$/u);
    if (prefixMatch && Object.prototype.hasOwnProperty.call(exact, prefixMatch[2])) {
      return preserveSpacing(value, prefixMatch[1] + exact[prefixMatch[2]]);
    }
    for (const [pattern, replacement] of patterns) {
      if (pattern.test(normalized)) return preserveSpacing(value, normalized.replace(pattern, replacement));
    }
    if (allowComposite) {
      const translated = translateComposite(normalized);
      if (translated !== normalized) return preserveSpacing(value, translated);
    }
    return value;
  }

  function isBlocked(element) {
    return !element || Boolean(element.closest(blockedContainers));
  }

  function isPlainDataCell(element) {
    const cell = element && element.closest('td');
    if (!cell) return false;
    if (element.closest(tableUiSelector)) return false;
    return !element.closest('button,a,label,summary,[role="button"],.badge,.status,.pill,.chip,.tab,.hint,.error,.warning');
  }

  function translateTextNode(node) {
    const parent = node.parentElement;
    if (isBlocked(parent) || isPlainDataCell(parent)) return;
    const translated = translateString(node.nodeValue || '', Boolean(parent.closest(compositeUiSelector)));
    if (translated !== node.nodeValue) node.nodeValue = translated;
  }

  function translateAttributes(element) {
    if (!(element instanceof Element) || isBlocked(element)) return;
    for (const name of ['placeholder', 'title', 'aria-label', 'data-confirm']) {
      if (!element.hasAttribute(name)) continue;
      const value = element.getAttribute(name) || '';
      const translated = translateString(value, true);
      if (translated !== value) element.setAttribute(name, translated);
    }
    if (element instanceof HTMLInputElement && ['button', 'submit', 'reset'].includes(element.type)) {
      const translated = translateString(element.value, true);
      if (translated !== element.value) element.value = translated;
    }
  }

  function translateTree(root) {
    if (!root) return;
    if (root.nodeType === Node.TEXT_NODE) {
      translateTextNode(root);
      return;
    }
    if (!(root instanceof Element || root instanceof Document)) return;
    if (root instanceof Element && isBlocked(root)) return;
    if (root instanceof Element) translateAttributes(root);
    root.querySelectorAll && root.querySelectorAll('*').forEach(translateAttributes);
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(translateTextNode);
  }

  const nativeAlert = window.alert.bind(window);
  const nativeConfirm = window.confirm.bind(window);
  const nativePrompt = window.prompt.bind(window);
  window.alert = function (message) { return nativeAlert(translateString(String(message), true)); };
  window.confirm = function (message) { return nativeConfirm(translateString(String(message), true)); };
  window.prompt = function (message, defaultValue) { return nativePrompt(translateString(String(message), true), defaultValue); };
  window.FeedToolsI18n = { locale: 'en', translate: function (value) { return translateString(value, true); }, refresh: function () { translateTree(document); } };

  function start() {
    translateTree(document);
    const observer = new MutationObserver(function (mutations) {
      for (const mutation of mutations) {
        if (mutation.type === 'characterData') translateTextNode(mutation.target);
        mutation.addedNodes.forEach(translateTree);
      }
    });
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
