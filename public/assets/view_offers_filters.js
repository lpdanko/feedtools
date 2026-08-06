(function() {
  if (window.__ftOffersFiltersInit) return;
  window.__ftOffersFiltersInit = true;

  const cfg = (window.ftOffersFiltersConfig && typeof window.ftOffersFiltersConfig === 'object') ? window.ftOffersFiltersConfig : {};
  const datasetId = String(cfg.datasetId || '');
  const facets = (cfg.facets && typeof cfg.facets === 'object') ? cfg.facets : {};
  const selected = normalizeFilterState({ selected: cfg.selected || {} }).selected;
  const selectedParams = normalizeFilterState({ selectedParams: cfg.selectedParams || {} }).selectedParams;
  const filtersDraftStorageKey = 'feedtools_offers_filters_' + datasetId;
  const filtersSyncGuardKey = 'feedtools_offers_filters_sync_' + datasetId;
  const appliedFiltersState = normalizeFilterState(cfg.appliedFiltersState || {});
  let appliedSearchValue = String(appliedFiltersState.q_name || '').trim();
  let appliedFilterStateSnapshot = normalizeFilterState(appliedFiltersState);
  let filterRequestRunning = false;
  let deferredDropdownFiltersDirty = false;
  let autoSubmitTimer = null;
  let submitSeq = 0;

  Object.keys(selectedParams).forEach((k) => {
    if (!Array.isArray(selectedParams[k])) selectedParams[k] = [selectedParams[k]];
  });

  function normalizeFilterState(state) {
    const src = (state && typeof state === 'object') ? state : {};
    const srcSelected = (src.selected && typeof src.selected === 'object') ? src.selected : {};
    const srcSelectedParams = (src.selectedParams && typeof src.selectedParams === 'object') ? src.selectedParams : {};

    const normalizeList = (values) => {
      if (!Array.isArray(values)) return [];
      return Array.from(new Set(values.map((value) => String(value)).filter((value) => value !== ''))).sort();
    };
    const normalizeStatusList = (values) => {
      const list = normalizeList(values).filter((value) => value !== 'no_errors');
      return list.includes('state:error') ? list.filter((value) => !String(value).startsWith('issue:')) : list;
    };
    const normalizeReadinessLists = () => {
      let ready = normalizeList(src.f_ready_ready);
      let missing = normalizeList(src.f_ready_missing);
      if (!ready.length && !missing.length) {
        const legacy = normalizeList(src.f_ready);
        if (String(src.f_ready_mode || '') === 'ready') {
          ready = legacy;
        } else {
          missing = legacy;
        }
      }
      const missingSet = new Set(missing);
      ready = ready.filter((value) => !missingSet.has(value));
      return { ready, missing, all: normalizeList(ready.concat(missing)) };
    };
    const readiness = normalizeReadinessLists();

    const normalizedParams = {};
    Object.keys(srcSelectedParams).sort().forEach((key) => {
      const values = normalizeList(srcSelectedParams[key]);
      if (values.length) normalizedParams[String(key)] = values;
    });

    return {
      q_name: String(src.q_name || '').trim(),
      f_instock: !!src.f_instock,
      f_not_in_ozon: !!src.f_not_in_ozon,
      f_not_in_ozon_archive: !!src.f_not_in_ozon_archive,
      f_not_in_wb: !!src.f_not_in_wb,
      f_has_picture: !!src.f_has_picture,
      f_not_bulky_ozon: !!src.f_not_bulky_ozon,
      f_selected_only: !!src.f_selected_only,
      f_price_min: String(src.f_price_min || '').trim(),
      f_price_max: String(src.f_price_max || '').trim(),
      f_stock_min: String(src.f_stock_min || '').trim(),
      f_stock_max: String(src.f_stock_max || '').trim(),
      f_ozon_hits_min: String(src.f_ozon_hits_min || '').trim(),
      f_ozon_hits_max: String(src.f_ozon_hits_max || '').trim(),
      f_ozon_sales_min: String(src.f_ozon_sales_min || '').trim(),
      f_ozon_sales_max: String(src.f_ozon_sales_max || '').trim(),
      f_ozon_view_card_min: String(src.f_ozon_view_card_min || '').trim(),
      f_ozon_view_card_max: String(src.f_ozon_view_card_max || '').trim(),
      f_ozon_card_order_min: String(src.f_ozon_card_order_min || '').trim(),
      f_ozon_card_order_max: String(src.f_ozon_card_order_max || '').trim(),
      f_ready_mode: readiness.ready.length && !readiness.missing.length ? 'ready' : 'missing',
      f_ready: readiness.all,
      f_ready_ready: readiness.ready,
      f_ready_missing: readiness.missing,
      f_quality_hint: normalizeList(src.f_quality_hint),
      f_ozon_marking_error: !!src.f_ozon_marking_error,
      selected: {
        catpath: normalizeList(srcSelected.catpath),
        ozoncat: normalizeList(srcSelected.ozoncat),
        wbcat: normalizeList(srcSelected.wbcat),
        brand: normalizeList(srcSelected.brand),
        brand_ozon: normalizeList(srcSelected.brand_ozon),
        brand_wb: normalizeList(srcSelected.brand_wb),
        model: normalizeList(srcSelected.model),
        brand_status_ozon: normalizeList(srcSelected.brand_status_ozon),
        brand_status_wb: normalizeList(srcSelected.brand_status_wb),
        status_ozon: normalizeStatusList(srcSelected.status_ozon),
        status_wb: normalizeStatusList(srcSelected.status_wb),
        hashtag: normalizeList(srcSelected.hashtag),
      },
      selectedParams: normalizedParams,
    };
  }

  function getCurrentFilterState() {
    const readinessStates = currentReadinessStateLists();
    return normalizeFilterState({
      q_name: nameInput ? String(nameInput.value || '') : '',
      f_instock: !!(inStockOnly && inStockOnly.checked),
      f_not_in_ozon: !!(notInOzonOnly && notInOzonOnly.checked),
      f_not_in_ozon_archive: !!(notInOzonArchiveOnly && notInOzonArchiveOnly.checked),
      f_not_in_wb: !!(notInWbOnly && notInWbOnly.checked),
      f_has_picture: !!(hasPictureOnly && hasPictureOnly.checked),
      f_not_bulky_ozon: !!(notBulkyOzonOnly && notBulkyOzonOnly.checked),
      f_selected_only: !!(selectedOnly && selectedOnly.checked),
      f_price_min: priceMinInput ? String(priceMinInput.value || '') : '',
      f_price_max: priceMaxInput ? String(priceMaxInput.value || '') : '',
      f_stock_min: stockMinInput ? String(stockMinInput.value || '') : '',
      f_stock_max: stockMaxInput ? String(stockMaxInput.value || '') : '',
      f_ozon_hits_min: analyticsRangeFilterValue('ozonHits', 'min'),
      f_ozon_hits_max: analyticsRangeFilterValue('ozonHits', 'max'),
      f_ozon_sales_min: analyticsRangeFilterValue('ozonSales', 'min'),
      f_ozon_sales_max: analyticsRangeFilterValue('ozonSales', 'max'),
      f_ozon_view_card_min: analyticsRangeFilterValue('ozonViewCard', 'min'),
      f_ozon_view_card_max: analyticsRangeFilterValue('ozonViewCard', 'max'),
      f_ozon_card_order_min: analyticsRangeFilterValue('ozonCardOrder', 'min'),
      f_ozon_card_order_max: analyticsRangeFilterValue('ozonCardOrder', 'max'),
      f_ready_mode: readinessModeValue(),
      f_ready: readinessStates.all,
      f_ready_ready: readinessStates.ready,
      f_ready_missing: readinessStates.missing,
      f_quality_hint: currentQualityHintKeys(),
      f_ozon_marking_error: !!(ozonMarkingErrorOnly && ozonMarkingErrorOnly.checked),
      selected: {
        catpath: Array.isArray(selected.catpath) ? selected.catpath.slice() : [],
        ozoncat: Array.isArray(selected.ozoncat) ? selected.ozoncat.slice() : [],
        wbcat: Array.isArray(selected.wbcat) ? selected.wbcat.slice() : [],
        brand: Array.isArray(selected.brand) ? selected.brand.slice() : [],
        brand_ozon: Array.isArray(selected.brand_ozon) ? selected.brand_ozon.slice() : [],
        brand_wb: Array.isArray(selected.brand_wb) ? selected.brand_wb.slice() : [],
        model: Array.isArray(selected.model) ? selected.model.slice() : [],
        brand_status_ozon: Array.isArray(selected.brand_status_ozon) ? selected.brand_status_ozon.slice() : [],
        brand_status_wb: Array.isArray(selected.brand_status_wb) ? selected.brand_status_wb.slice() : [],
        status_ozon: Array.isArray(selected.status_ozon) ? selected.status_ozon.slice() : [],
        status_wb: Array.isArray(selected.status_wb) ? selected.status_wb.slice() : [],
        hashtag: Array.isArray(selected.hashtag) ? selected.hashtag.slice() : [],
      },
      selectedParams: Object.fromEntries(
        Object.entries(selectedParams || {}).map(([key, values]) => [key, Array.isArray(values) ? values.slice() : []])
      ),
    });
  }

  function filterStatesEqual(a, b) {
    return JSON.stringify(normalizeFilterState(a)) === JSON.stringify(normalizeFilterState(b));
  }

  function readFiltersSyncGuard() {
    try {
      return sessionStorage.getItem(filtersSyncGuardKey) || '';
    } catch (e) {
      return '';
    }
  }

  function writeFiltersSyncGuard(state) {
    try {
      sessionStorage.setItem(filtersSyncGuardKey, JSON.stringify(normalizeFilterState(state)));
    } catch (e) {}
  }

  function clearFiltersSyncGuard() {
    try {
      sessionStorage.removeItem(filtersSyncGuardKey);
    } catch (e) {}
  }

  function readSavedFiltersDraft() {
    try {
      const raw = localStorage.getItem(filtersDraftStorageKey);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (e) {
      return null;
    }
  }

  function saveFiltersDraft() {
    try {
      localStorage.setItem(filtersDraftStorageKey, JSON.stringify(getCurrentFilterState()));
    } catch (e) {}
  }

  function clearFiltersDraft() {
    try {
      localStorage.removeItem(filtersDraftStorageKey);
    } catch (e) {}
  }

  function applySavedFiltersDraft() {
    const rawDraft = readSavedFiltersDraft();
    if (!rawDraft) return;
    const draft = normalizeFilterState(rawDraft);
    if (!draft) return;

    if (nameInput && typeof draft.q_name === 'string') nameInput.value = draft.q_name;
    if (inStockOnly) inStockOnly.checked = !!draft.f_instock;
    if (notInOzonOnly) notInOzonOnly.checked = !!draft.f_not_in_ozon;
    if (notInOzonArchiveOnly) notInOzonArchiveOnly.checked = !!draft.f_not_in_ozon_archive;
    if (notInWbOnly) notInWbOnly.checked = !!draft.f_not_in_wb;
    if (hasPictureOnly) hasPictureOnly.checked = !!draft.f_has_picture;
    if (notBulkyOzonOnly) notBulkyOzonOnly.checked = !!draft.f_not_bulky_ozon;
    if (ozonMarkingErrorOnly) ozonMarkingErrorOnly.checked = !!draft.f_ozon_marking_error;
    if (selectedOnly) selectedOnly.checked = !!draft.f_selected_only;
    if (priceMinInput && typeof draft.f_price_min === 'string') priceMinInput.value = draft.f_price_min;
    if (priceMaxInput && typeof draft.f_price_max === 'string') priceMaxInput.value = draft.f_price_max;
    if (stockMinInput && typeof draft.f_stock_min === 'string') stockMinInput.value = draft.f_stock_min;
    if (stockMaxInput && typeof draft.f_stock_max === 'string') stockMaxInput.value = draft.f_stock_max;
    applyReadinessDraft(draft);
    applyQualityHintDraft(draft);
    applyAnalyticsRangeDraft(draft);

    const draftSelected = (draft.selected && typeof draft.selected === 'object') ? draft.selected : {};
    ['catpath', 'ozoncat', 'wbcat', 'brand_ozon', 'brand_wb', 'model', 'brand_status_ozon', 'brand_status_wb', 'status_ozon', 'status_wb', 'hashtag'].forEach((key) => {
      if (Array.isArray(draftSelected[key])) {
        selected[key] = Array.from(new Set(draftSelected[key].map((value) => String(value))));
      }
    });

    const draftSelectedParams = (draft.selectedParams && typeof draft.selectedParams === 'object') ? draft.selectedParams : {};
    Object.keys(selectedParams).forEach((key) => {
      if (Array.isArray(draftSelectedParams[key])) {
        selectedParams[key] = Array.from(new Set(draftSelectedParams[key].map((value) => String(value))));
      }
    });
  }

  const paramsHost = document.getElementById('offersParamFilters');
  const paramsCard = document.getElementById('offersParamFiltersCard');
  const paramsCount = document.getElementById('offersParamFiltersCount');
  const paramFacetsUrl = String(cfg.paramFacetsUrl || '');
  const paramValueFacetsUrl = String(cfg.paramValueFacetsUrl || '');
  let paramFacetsLoaded = !!(facets.params && typeof facets.params === 'object' && Object.keys(facets.params).length);
  let paramFacetsLoading = false;
  let paramFacetsError = '';
  const paramValuesLoaded = {};
  const paramValuesLoading = {};
  const paramValuesError = {};

  const ozonLabels = (cfg.ozonLabels && typeof cfg.ozonLabels === 'object') ? cfg.ozonLabels : {};
  const wbLabels = (cfg.wbLabels && typeof cfg.wbLabels === 'object') ? cfg.wbLabels : {};
  const statusFilterLabels = (cfg.statusFilterLabels && typeof cfg.statusFilterLabels === 'object') ? cfg.statusFilterLabels : {};
  const brandStatusFilterLabels = (cfg.brandStatusFilterLabels && typeof cfg.brandStatusFilterLabels === 'object') ? cfg.brandStatusFilterLabels : {};

  const openDropdownPanels = () => Array.from(document.querySelectorAll('.ddpanel.open'));

  window.ftCloseDropdownPanels = function() {
    openDropdownPanels().forEach((panel) => {
      const wasOpen = panel.classList.contains('open');
      panel.classList.remove('open');
      panel.__ftDropdownAnchor = null;
      panel.style.left = '';
      panel.style.right = '';
      panel.style.top = '';
      panel.style.bottom = '';
      panel.style.width = '';
      panel.style.maxHeight = '';
      if (wasOpen) {
        document.dispatchEvent(new CustomEvent('ft-dropdown-panel-closed', { detail: { panel } }));
      }
    });
  };

  window.ftPositionDropdownPanel = function(panel, anchor) {
    if (!panel || !anchor) return;

    const anchorRect = anchor.getBoundingClientRect();
    const isCategoryPicker = panel.classList.contains('supplier-category-panel') || panel.classList.contains('supplier-bulk-category-panel');
    const viewportPad = isCategoryPicker ? 8 : 12;
    const availableWidth = Math.max(240, window.innerWidth - viewportPad * 2);
    const minPanelWidth = isCategoryPicker ? 960 : 320;
    const maxPanelWidth = isCategoryPicker ? 1680 : 680;
    const desiredWidth = Math.min(Math.max(anchorRect.width, minPanelWidth), availableWidth, maxPanelWidth);

    panel.style.position = 'fixed';
    panel.style.boxSizing = 'border-box';
    panel.style.width = desiredWidth + 'px';
    panel.style.maxHeight = Math.min(430, Math.max(220, window.innerHeight - viewportPad * 2)) + 'px';
    panel.style.left = '0px';
    panel.style.right = 'auto';
    panel.style.top = '0px';
    panel.style.bottom = 'auto';

    let rect = panel.getBoundingClientRect();
    const gap = 8;
    const spaceBelow = window.innerHeight - anchorRect.bottom - viewportPad - gap;
    const spaceAbove = anchorRect.top - viewportPad - gap;
    const maxPanelHeight = isCategoryPicker ? 620 : 430;
    let maxHeight = Math.min(maxPanelHeight, Math.max(180, spaceBelow));
    let top = anchorRect.bottom + gap;

    if (spaceBelow < Math.min(rect.height, 260) && spaceAbove > spaceBelow) {
      maxHeight = Math.min(maxPanelHeight, Math.max(180, spaceAbove));
      panel.style.maxHeight = maxHeight + 'px';
      rect = panel.getBoundingClientRect();
      top = Math.max(viewportPad, anchorRect.top - Math.min(rect.height, maxHeight) - gap);
    } else {
      panel.style.maxHeight = maxHeight + 'px';
      top = Math.min(top, window.innerHeight - viewportPad - Math.min(rect.height, maxHeight));
    }

    let left = anchorRect.left;
    if (left + desiredWidth > window.innerWidth - viewportPad) {
      left = window.innerWidth - viewportPad - desiredWidth;
    }
    if (left < viewportPad) left = viewportPad;

    panel.style.left = Math.round(left) + 'px';
    panel.style.top = Math.round(Math.max(viewportPad, top)) + 'px';
  };

  window.ftOpenDropdownPanel = function(panel, anchor) {
    if (!panel || !anchor) return;
    window.ftCloseDropdownPanels();
    panel.__ftDropdownAnchor = anchor;
    panel.classList.add('open');
    requestAnimationFrame(() => window.ftPositionDropdownPanel(panel, anchor));
  };

  window.addEventListener('resize', () => {
    openDropdownPanels().forEach((panel) => {
      const anchor = panel.__ftDropdownAnchor || (panel.parentElement ? panel.parentElement.querySelector('.ddbtn') : null);
      if (anchor) window.ftPositionDropdownPanel(panel, anchor);
    });
  });
  window.addEventListener('scroll', () => {
    openDropdownPanels().forEach((panel) => {
      const anchor = panel.__ftDropdownAnchor || (panel.parentElement ? panel.parentElement.querySelector('.ddbtn') : null);
      if (anchor) window.ftPositionDropdownPanel(panel, anchor);
    });
  }, true);

  function hasActiveParamFilters() {
    return Object.keys(selectedParams || {}).some((k) => Array.isArray(selectedParams[k]) && selectedParams[k].length);
  }

  function selectedParamsCount() {
    return Object.keys(selectedParams || {}).reduce((acc, k) => {
      const arr = selectedParams[k];
      return acc + (Array.isArray(arr) ? arr.length : 0);
    }, 0);
  }

  function updateParamFiltersSummary() {
    if (!paramsCount) return;
    paramsCount.textContent = String(selectedParamsCount());
  }

  function setParamsBlockOpen(isOpen) {
    if (!paramsCard) return;
    paramsCard.open = !!isOpen;
    try {
      localStorage.setItem('ft_offers_param_filters_open', isOpen ? '1' : '0');
    } catch (e) {}
  }

  (function initParamsBlockToggle() {
    if (!paramsCard) return;

    let isOpen = false;
    try {
      const saved = localStorage.getItem('ft_offers_param_filters_open');
      isOpen = saved === '1';
    } catch (e) {}

    if (!isOpen && hasActiveParamFilters()) isOpen = true;
    setParamsBlockOpen(isOpen);

    paramsCard.addEventListener('toggle', () => {
      try {
        localStorage.setItem('ft_offers_param_filters_open', paramsCard.open ? '1' : '0');
      } catch (e) {}
    });
  })();


  const host = document.getElementById('offersFilters');
  const info = document.getElementById('offersFiltersInfo');
  const btnApply = document.getElementById('btnApplyFilters');
  const btnClear = document.getElementById('btnClearFilters');
  const nameInput = document.getElementById('nameSearchInput');
  const inStockOnly = document.getElementById('inStockOnly');
  const notInOzonOnly = document.getElementById('notInOzonOnly');
  const notInOzonArchiveOnly = document.getElementById('notInOzonArchiveOnly');
  const notInWbOnly = document.getElementById('notInWbOnly');
  const hasPictureOnly = document.getElementById('hasPictureOnly');
  const notBulkyOzonOnly = document.getElementById('notBulkyOzonOnly');
  const selectedOnly = document.getElementById('selectedOnlyFilter');
  const btnShowSelectedOnly = document.getElementById('btnShowSelectedOnly');
  const readinessModeInputs = Array.from(document.querySelectorAll('input[name="readiness_filter_mode_ui"]'));
  const readinessInputs = Array.from(document.querySelectorAll('.readiness-filter-input'));
  const qualityHintInputs = Array.from(document.querySelectorAll('.quality-hint-filter-input'));
  const ozonMarkingErrorOnly = document.getElementById('ozonMarkingErrorOnly');
  const priceMinInput = document.getElementById('priceMinInput');
  const priceMaxInput = document.getElementById('priceMaxInput');
  const stockMinInput = document.getElementById('stockMinInput');
  const stockMaxInput = document.getElementById('stockMaxInput');
  const ozonHitsMinInput = document.getElementById('ozonHitsMinInput');
  const ozonHitsMaxInput = document.getElementById('ozonHitsMaxInput');
  const ozonHitsMinTextInput = document.getElementById('ozonHitsMinTextInput');
  const ozonHitsMaxTextInput = document.getElementById('ozonHitsMaxTextInput');
  const ozonSalesMinInput = document.getElementById('ozonSalesMinInput');
  const ozonSalesMaxInput = document.getElementById('ozonSalesMaxInput');
  const ozonSalesMinTextInput = document.getElementById('ozonSalesMinTextInput');
  const ozonSalesMaxTextInput = document.getElementById('ozonSalesMaxTextInput');
  const ozonViewCardMinInput = document.getElementById('ozonViewCardMinInput');
  const ozonViewCardMaxInput = document.getElementById('ozonViewCardMaxInput');
  const ozonViewCardMinTextInput = document.getElementById('ozonViewCardMinTextInput');
  const ozonViewCardMaxTextInput = document.getElementById('ozonViewCardMaxTextInput');
  const ozonCardOrderMinInput = document.getElementById('ozonCardOrderMinInput');
  const ozonCardOrderMaxInput = document.getElementById('ozonCardOrderMaxInput');
  const ozonCardOrderMinTextInput = document.getElementById('ozonCardOrderMinTextInput');
  const ozonCardOrderMaxTextInput = document.getElementById('ozonCardOrderMaxTextInput');
  const analyticsRangeDefs = [
    { key: 'ozonHits', min: ozonHitsMinInput, max: ozonHitsMaxInput, minText: ozonHitsMinTextInput, maxText: ozonHitsMaxTextInput, label: 'показы Ozon', suffix: '' },
    { key: 'ozonSales', min: ozonSalesMinInput, max: ozonSalesMaxInput, minText: ozonSalesMinTextInput, maxText: ozonSalesMaxTextInput, label: 'продажи Ozon', suffix: '' },
    { key: 'ozonViewCard', min: ozonViewCardMinInput, max: ozonViewCardMaxInput, minText: ozonViewCardMinTextInput, maxText: ozonViewCardMaxTextInput, label: 'в карточку', suffix: '%' },
    { key: 'ozonCardOrder', min: ozonCardOrderMinInput, max: ozonCardOrderMaxInput, minText: ozonCardOrderMinTextInput, maxText: ozonCardOrderMaxTextInput, label: 'в заказ', suffix: '%' },
  ].filter((def) => def.min && def.max);


  if (!host) return;

  function readinessStateValue(input) {
    const state = String((input && input.dataset ? input.dataset.readinessState : '') || '').trim();
    return state === 'ready' || state === 'missing' ? state : '';
  }

  function setReadinessInputState(input, state) {
    if (!input) return;
    const normalized = state === 'ready' || state === 'missing' ? state : '';
    input.checked = normalized !== '';
    if (input.dataset) input.dataset.readinessState = normalized;
    const chip = input.closest ? input.closest('.readiness-filter-chip') : null;
    if (!chip) return;
    if (chip.dataset) chip.dataset.readinessState = normalized;
    chip.classList.toggle('is-active', normalized !== '');
    chip.classList.toggle('is-ready', normalized === 'ready');
    chip.classList.toggle('is-missing', normalized === 'missing');
    chip.setAttribute('aria-pressed', normalized !== '' ? 'true' : 'false');
    const stateLabel = chip.querySelector('.readiness-filter-state');
    if (stateLabel) stateLabel.textContent = normalized === 'ready' ? 'готово' : (normalized === 'missing' ? 'не готово' : '');
    const baseLabel = String(input.value || '').trim();
    chip.title = normalized === 'ready'
      ? 'Фильтр: этот пункт готов'
      : (normalized === 'missing' ? 'Фильтр: этот пункт не готов' : 'Фильтр не выбран');
    if (baseLabel) {
      input.setAttribute('aria-label', baseLabel + (normalized ? ': ' + stateLabelText(normalized) : ''));
    }
  }

  function stateLabelText(state) {
    return state === 'ready' ? 'готово' : (state === 'missing' ? 'не готово' : '');
  }

  function currentReadinessStateLists() {
    const ready = [];
    const missing = [];
    readinessInputs.forEach((input) => {
      const key = String(input && input.value || '').trim();
      if (!key) return;
      const state = readinessStateValue(input);
      if (state === 'ready') ready.push(key);
      if (state === 'missing') missing.push(key);
    });
    ready.sort();
    missing.sort();
    return {
      ready,
      missing,
      all: Array.from(new Set(ready.concat(missing))).sort(),
    };
  }

  function readinessModeValue() {
    const states = currentReadinessStateLists();
    return states.ready.length && !states.missing.length ? 'ready' : 'missing';
  }

  function currentReadinessKeys() {
    return currentReadinessStateLists().all;
  }

  function currentQualityHintKeys() {
    return qualityHintInputs
      .filter((input) => input && input.checked)
      .map((input) => String(input.value || '').trim())
      .filter(Boolean)
      .sort();
  }

  function applyReadinessDraft(draft) {
    const readySet = new Set(Array.isArray(draft.f_ready_ready) ? draft.f_ready_ready.map((value) => String(value)) : []);
    const missingSet = new Set(Array.isArray(draft.f_ready_missing) ? draft.f_ready_missing.map((value) => String(value)) : []);
    readinessInputs.forEach((input) => {
      const key = String(input.value || '');
      setReadinessInputState(input, missingSet.has(key) ? 'missing' : (readySet.has(key) ? 'ready' : ''));
    });
  }

  function applyQualityHintDraft(draft) {
    const hintSet = new Set(Array.isArray(draft.f_quality_hint) ? draft.f_quality_hint.map((value) => String(value)) : []);
    qualityHintInputs.forEach((input) => {
      input.checked = hintSet.has(String(input.value || ''));
    });
  }

  function setFilterStatus(state) {
    const filterStatus = document.getElementById('offersFilterStatus');
    if (!filterStatus) return;
    const filterStatusText = filterStatus.querySelector('[data-filter-status-text]');
    const normalized = ['applied', 'pending', 'loading', 'error'].includes(state) ? state : 'pending';
    filterStatus.classList.remove('is-applied', 'is-pending', 'is-loading', 'is-error');
    filterStatus.classList.add('is-' + normalized);
    const labels = {
      applied: 'применено',
      pending: 'есть изменения',
      loading: 'фильтрую',
      error: 'ошибка',
    };
    const titles = {
      applied: 'Таблица соответствует выбранным фильтрам.',
      pending: 'Есть изменения фильтров, которые еще не применены.',
      loading: 'Фильтрация выполняется.',
      error: 'Фильтр не удалось применить.',
    };
    if (filterStatusText) filterStatusText.textContent = labels[normalized] || labels.pending;
    filterStatus.title = titles[normalized] || titles.pending;
    filterStatus.setAttribute('aria-label', titles[normalized] || titles.pending);
  }

  function filterStateSnapshot() {
    return normalizeFilterState(getCurrentFilterState());
  }

  function selectedOnlyToggleIsActive() {
    return !!(
      (selectedOnly && selectedOnly.checked)
      || (btnShowSelectedOnly && btnShowSelectedOnly.classList.contains('is-active'))
      || (btnShowSelectedOnly && btnShowSelectedOnly.getAttribute('aria-pressed') === 'true')
      || (appliedFilterStateSnapshot && appliedFilterStateSnapshot.f_selected_only)
    );
  }

  function updateFilterStatusFromState() {
    if (filterRequestRunning) {
      setFilterStatus('loading');
      return;
    }
    setFilterStatus(filterStatesEqual(filterStateSnapshot(), appliedFilterStateSnapshot) ? 'applied' : 'pending');
  }

  document.addEventListener('supplier-offers-table-replaced', updateFilterStatusFromState);

  function deferDropdownAutoApply(panel) {
    deferredDropdownFiltersDirty = true;
    if (panel && panel.dataset) {
      panel.dataset.ftFilterDirty = '1';
    }
    updateFilterStatusFromState();
  }

  function flushDeferredDropdownAutoApply(panel) {
    const panelWasDirty = !!(panel && panel.dataset && panel.dataset.ftFilterDirty === '1');
    if (panel && panel.dataset) {
      delete panel.dataset.ftFilterDirty;
    }
    if (!panelWasDirty && !deferredDropdownFiltersDirty) {
      return;
    }
    const stillDirtyOpenPanel = openDropdownPanels().some((openPanel) => openPanel.dataset && openPanel.dataset.ftFilterDirty === '1');
    if (stillDirtyOpenPanel) {
      return;
    }
    deferredDropdownFiltersDirty = false;
    scheduleAutoApplyFilters();
  }

  function clearDeferredDropdownDirtyFlags() {
    deferredDropdownFiltersDirty = false;
    document.querySelectorAll('.ddpanel[data-ft-filter-dirty]').forEach((panel) => {
      if (panel && panel.dataset) {
        delete panel.dataset.ftFilterDirty;
      }
    });
  }

  function analyticsRangeDefaultMax(def) {
    const raw = def && def.max ? Number(def.max.dataset.defaultMax || def.max.max || 0) : 0;
    return Number.isFinite(raw) && raw > 0 ? raw : 1;
  }

  function analyticsRangeParseNumber(value) {
    const raw = String(value || '')
      .replace(/\s+/g, '')
      .replace('%', '')
      .replace(',', '.')
      .trim();
    if (raw === '') return null;
    const num = Number(raw);
    return Number.isFinite(num) ? num : null;
  }

  function analyticsRangeFormat(value, suffix) {
    const num = Number(value || 0);
    const rounded = suffix === '%' ? Math.round(num * 10) / 10 : Math.round(num);
    const text = suffix === '%'
      ? String(rounded).replace(/\.0$/, '')
      : String(rounded).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return text + (suffix || '');
  }

  function analyticsRangeInputFormat(value, suffix) {
    const num = Number(value || 0);
    if (!Number.isFinite(num)) return '';
    if (suffix === '%') {
      return String(Math.round(num * 10) / 10).replace(/\.0$/, '');
    }
    return String(Math.round(num));
  }

  function analyticsRangeDef(key) {
    return analyticsRangeDefs.find((def) => def.key === key) || null;
  }

  function analyticsRangeFilterValue(key, role) {
    const def = analyticsRangeDef(key);
    if (!def) return '';
    const input = role === 'max' ? def.max : def.min;
    const value = Number(input && input.value !== '' ? input.value : 0);
    if (!Number.isFinite(value)) return '';
    const bound = analyticsRangeDefaultMax(def);
    if (role === 'max') {
      return value < bound ? String(value).replace(/\.0$/, '') : '';
    }
    return value > 0 ? String(value).replace(/\.0$/, '') : '';
  }

  function applyAnalyticsRangeDraft(draft) {
    const pairs = [
      ['ozonHits', 'f_ozon_hits_min', 'f_ozon_hits_max'],
      ['ozonSales', 'f_ozon_sales_min', 'f_ozon_sales_max'],
      ['ozonViewCard', 'f_ozon_view_card_min', 'f_ozon_view_card_max'],
      ['ozonCardOrder', 'f_ozon_card_order_min', 'f_ozon_card_order_max'],
    ];
    pairs.forEach(([key, minKey, maxKey]) => {
      const def = analyticsRangeDef(key);
      if (!def) return;
      const bound = analyticsRangeDefaultMax(def);
      const minValue = String(draft[minKey] || '').trim();
      const maxValue = String(draft[maxKey] || '').trim();
      const minParsed = analyticsRangeParseNumber(minValue);
      const maxParsed = analyticsRangeParseNumber(maxValue);
      def.min.value = minParsed !== null ? Math.max(0, Math.min(bound, minParsed)) : 0;
      def.max.value = maxParsed !== null ? Math.max(0, Math.min(bound, maxParsed)) : bound;
      syncAnalyticsRange(def);
    });
  }

  function syncAnalyticsRange(def, changedRole, preserveTextInput) {
    if (!def || !def.min || !def.max) return;
    const bound = analyticsRangeDefaultMax(def);
    let minValue = Number(def.min.value || 0);
    let maxValue = Number(def.max.value || bound);
    if (!Number.isFinite(minValue)) minValue = 0;
    if (!Number.isFinite(maxValue)) maxValue = bound;
    minValue = Math.max(0, Math.min(bound, minValue));
    maxValue = Math.max(0, Math.min(bound, maxValue));
    if (minValue > maxValue) {
      if (changedRole === 'min') maxValue = minValue;
      else minValue = maxValue;
    }
    def.min.value = String(minValue);
    def.max.value = String(maxValue);
    def.min.style.zIndex = changedRole === 'min' ? '5' : '4';
    def.max.style.zIndex = changedRole === 'max' ? '5' : '4';

    const card = def.min.closest ? def.min.closest('.analytics-slider-card') : null;
    const active = minValue > 0 || maxValue < bound;
    if (card) card.classList.toggle('is-active', active);
    const track = card ? card.querySelector('[data-analytics-range-track]') : null;
    if (track) {
      const minPct = bound > 0 ? Math.max(0, Math.min(100, (minValue / bound) * 100)) : 0;
      const maxPct = bound > 0 ? Math.max(0, Math.min(100, (maxValue / bound) * 100)) : 100;
      track.style.setProperty('--range-min', minPct + '%');
      track.style.setProperty('--range-max', maxPct + '%');
    }

    if (def.minText && def.minText !== preserveTextInput) {
      def.minText.value = minValue > 0 ? analyticsRangeInputFormat(minValue, def.suffix) : '';
    }
    if (def.maxText && def.maxText !== preserveTextInput) {
      def.maxText.value = maxValue < bound ? analyticsRangeInputFormat(maxValue, def.suffix) : '';
    }

    const value = card ? card.querySelector('[data-analytics-range-value]') : null;
    if (value) {
      value.textContent = analyticsRangeFormat(minValue, def.suffix) + ' - ' + analyticsRangeFormat(maxValue, def.suffix);
    }
  }

  function syncAllAnalyticsRanges() {
    analyticsRangeDefs.forEach((def) => syncAnalyticsRange(def));
  }

  applySavedFiltersDraft();
  syncAllAnalyticsRanges();

  const EMPTY_TOKEN = String(cfg.emptyToken || '__EMPTY__');

  function lbl(key, v) {
    if (v === EMPTY_TOKEN) return '∅ (пусто)';
    if (key === 'ozoncat') {
      const s = String(v || '');
      // если нашли в мапе — покажем full_path (code), иначе показываем как есть
      return (ozonLabels && ozonLabels[s]) ? ozonLabels[s] : s;
    }
    if (key === 'wbcat') {
      const s = String(v || '');
      return (wbLabels && wbLabels[s]) ? wbLabels[s] : s;
    }
    if (key === 'status_ozon' || key === 'status_wb') {
      const s = String(v || '');
      return (statusFilterLabels && statusFilterLabels[s]) ? statusFilterLabels[s] : s;
    }
    if (key === 'brand_status_ozon' || key === 'brand_status_wb') {
      const s = String(v || '');
      return (brandStatusFilterLabels && brandStatusFilterLabels[s]) ? brandStatusFilterLabels[s] : s;
    }
    return v;
  }

  function lblParamValue(v) {
    if (v === EMPTY_TOKEN) return '∅ (пусто)';
    return String(v || '');
  }



  function mkDropdownFlat(key, title, options) {
    const wrap = document.createElement('div');
    wrap.className = 'dd';
    let listRendered = false;

    const btn = document.createElement('div');
    btn.className = 'ddbtn';
    const t = document.createElement('span');
    t.textContent = title;
    const pill = document.createElement('span');
    pill.className = 'pill';
    btn.appendChild(t);
    btn.appendChild(pill);

    const panel = document.createElement('div');
    panel.className = 'ddpanel';

    const search = document.createElement('input');
    search.className = 'ddsearch';
    search.type = 'text';
    search.placeholder = 'поиск...';
    panel.appendChild(search);

    const actions = document.createElement('div');
    actions.className = 'ddactions';

    const btnSelectAll = document.createElement('button');
    btnSelectAll.type = 'button';
    btnSelectAll.textContent = 'Выбрать все';
    actions.appendChild(btnSelectAll);

    const btnClearAll = document.createElement('button');
    btnClearAll.type = 'button';
    btnClearAll.textContent = 'Очистить все';
    actions.appendChild(btnClearAll);

    panel.appendChild(actions);

    const list = document.createElement('div');
    panel.appendChild(list);

    function renderList() {
      const q = search.value.trim().toLowerCase();
      list.innerHTML = '';
      options.forEach(val => {
        const text = lbl(key, val);
        if (q && !text.toLowerCase().includes(q)) return;

        const item = document.createElement('label');
        item.className = 'dditem';

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = val;
        cb.checked = selected[key].includes(val);

        cb.addEventListener('change', () => {
          const s = new Set(selected[key].map(String));
          if (cb.checked) s.add(String(val));
          else s.delete(String(val));
          selected[key] = Array.from(s.values());
          updatePills();
          saveFiltersDraft();
          deferDropdownAutoApply(panel);
        });

        const span = document.createElement('span');
        span.textContent = text;

        item.appendChild(cb);
        item.appendChild(span);
        list.appendChild(item);
      });
      listRendered = true;
    }

    function ensureListRendered() {
      if (!listRendered) renderList();
    }

    btnSelectAll.addEventListener('click', () => {
      selected[key] = Array.from(new Set(options.map((val) => String(val))).values());
      updatePills();
      if (listRendered || panel.classList.contains('open')) renderList();
      saveFiltersDraft();
      deferDropdownAutoApply(panel);
    });

    btnClearAll.addEventListener('click', () => {
      selected[key] = [];
      updatePills();
      if (listRendered || panel.classList.contains('open')) renderList();
      saveFiltersDraft();
      deferDropdownAutoApply(panel);
    });

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = panel.classList.contains('open');
      if (isOpen) window.ftCloseDropdownPanels();
      else {
        ensureListRendered();
        window.ftOpenDropdownPanel(panel, btn);
      }
    });

    panel.addEventListener('click', (e) => e.stopPropagation());
    search.addEventListener('input', () => {
      ensureListRendered();
      renderList();
    });

    wrap.appendChild(btn);
    wrap.appendChild(panel);

    wrap._update = () => {
      const n = (selected[key] || []).length;
      pill.textContent = String(n);
      btn.classList.toggle('is-active', n > 0);
      if (listRendered || panel.classList.contains('open')) renderList();
    };

    return wrap;
  }

  function statusFilterSelectionCount(key) {
    const values = Array.isArray(selected[key]) ? selected[key].map(String) : [];
    if (values.includes('state:error')) {
      return values.filter((value) => !value.startsWith('issue:')).length;
    }
    return values.length;
  }

  function mkDropdownStatus(key, title, options) {
    options = Array.from(new Set((options || []).map((value) => String(value)).filter((value) => value !== '')));
    const optionSet = new Set(options);
    const issueOptions = options.filter((value) => value.startsWith('issue:'));
    const stateOptions = options.filter((value) => value.startsWith('state:'));
    const wrap = document.createElement('div');
    wrap.className = 'dd';
    let listRendered = false;
    const expanded = new Set(['state:error']);

    const btn = document.createElement('div');
    btn.className = 'ddbtn';
    const t = document.createElement('span');
    t.textContent = title;
    const pill = document.createElement('span');
    pill.className = 'pill';
    btn.appendChild(t);
    btn.appendChild(pill);

    const panel = document.createElement('div');
    panel.className = 'ddpanel ddpanel--status';

    const search = document.createElement('input');
    search.className = 'ddsearch';
    search.type = 'text';
    search.placeholder = 'поиск...';
    panel.appendChild(search);

    const actions = document.createElement('div');
    actions.className = 'ddactions';

    const btnSelectAll = document.createElement('button');
    btnSelectAll.type = 'button';
    btnSelectAll.textContent = 'Выбрать все';
    actions.appendChild(btnSelectAll);

    const btnClearAll = document.createElement('button');
    btnClearAll.type = 'button';
    btnClearAll.textContent = 'Очистить все';
    actions.appendChild(btnClearAll);

    panel.appendChild(actions);

    const list = document.createElement('div');
    panel.appendChild(list);

    function orderedSelection(set) {
      const out = [];
      options.forEach((value) => {
        if (set.has(value) && optionSet.has(value)) out.push(value);
      });
      return out;
    }

    function setStatusSelection(set) {
      if (set.has('state:error')) {
        issueOptions.forEach((issue) => set.delete(issue));
      }
      selected[key] = orderedSelection(set);
    }

    function commitStatusSelection(set) {
      setStatusSelection(set);
      updatePills();
      saveFiltersDraft();
      deferDropdownAutoApply(panel);
    }

    function selectedSet() {
      return new Set((selected[key] || []).map(String));
    }

    function renderStateRow(token, hasChildren, childMatchesSearch, parentMatchesSearch) {
      const set = selectedSet();
      const row = document.createElement('div');
      row.className = 'dditem dditem--status-parent';

      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = token;
      cb.checked = set.has(token);
      cb.indeterminate = token === 'state:error' && !cb.checked && issueOptions.some((issue) => set.has(issue));
      cb.addEventListener('change', () => {
        const next = selectedSet();
        if (cb.checked) {
          next.add(token);
          if (token === 'state:error') issueOptions.forEach((issue) => next.delete(issue));
        } else {
          next.delete(token);
        }
        commitStatusSelection(next);
      });
      row.appendChild(cb);

      if (hasChildren) {
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'ddstatus-caret' + ((expanded.has(token) || search.value.trim()) ? ' open' : '');
        toggle.textContent = '›';
        toggle.title = 'Показать типы ошибок';
        toggle.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          if (expanded.has(token)) expanded.delete(token);
          else expanded.add(token);
          renderList();
        });
        row.appendChild(toggle);
      } else {
        const spacer = document.createElement('span');
        spacer.className = 'ddstatus-caret-placeholder';
        row.appendChild(spacer);
      }

      const span = document.createElement('span');
      span.textContent = lbl(key, token);
      row.appendChild(span);

      if (hasChildren) {
        const count = document.createElement('span');
        count.className = 'ddstatus-count';
        count.textContent = String(issueOptions.length);
        row.appendChild(count);
      }

      list.appendChild(row);

      const q = search.value.trim().toLowerCase();
      const showChildren = hasChildren && (expanded.has(token) || q);
      if (!showChildren) return;

      issueOptions.forEach((issue) => {
        const text = lbl(key, issue);
        const childMatch = String(text || '').toLowerCase().includes(q);
        if (q && !parentMatchesSearch && !childMatch) return;
        renderIssueRow(issue, q && parentMatchesSearch ? true : childMatch);
      });
    }

    function renderIssueRow(token, visible) {
      if (!visible) return;
      const set = selectedSet();
      const item = document.createElement('label');
      item.className = 'dditem dditem--status-child';

      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.value = token;
      cb.checked = set.has(token);
      cb.addEventListener('change', () => {
        const next = selectedSet();
        if (cb.checked) {
          next.add(token);
          next.delete('state:error');
        } else {
          next.delete(token);
        }
        commitStatusSelection(next);
      });

      const span = document.createElement('span');
      span.textContent = lbl(key, token);

      item.appendChild(cb);
      item.appendChild(span);
      list.appendChild(item);
    }

    function renderList() {
      const q = search.value.trim().toLowerCase();
      list.innerHTML = '';

      stateOptions.forEach((token) => {
        const text = lbl(key, token);
        const parentMatchesSearch = !q || String(text || '').toLowerCase().includes(q);
        const isError = token === 'state:error';
        const childMatchesSearch = isError && issueOptions.some((issue) => String(lbl(key, issue) || '').toLowerCase().includes(q));
        if (q && !parentMatchesSearch && !childMatchesSearch) return;
        renderStateRow(token, isError && issueOptions.length > 0, childMatchesSearch, parentMatchesSearch);
      });

      if (!stateOptions.includes('state:error') && issueOptions.length > 0) {
        const childMatchesSearch = issueOptions.some((issue) => String(lbl(key, issue) || '').toLowerCase().includes(q));
        if (!q || childMatchesSearch) {
          renderStateRow('state:error', true, childMatchesSearch, !q || String(lbl(key, 'state:error') || '').toLowerCase().includes(q));
        }
      }
      listRendered = true;
    }

    function ensureListRendered() {
      if (!listRendered) renderList();
    }

    btnSelectAll.addEventListener('click', () => {
      const next = new Set(stateOptions.length ? stateOptions : options);
      setStatusSelection(next);
      updatePills();
      if (listRendered || panel.classList.contains('open')) renderList();
      saveFiltersDraft();
      deferDropdownAutoApply(panel);
    });

    btnClearAll.addEventListener('click', () => {
      selected[key] = [];
      updatePills();
      if (listRendered || panel.classList.contains('open')) renderList();
      saveFiltersDraft();
      deferDropdownAutoApply(panel);
    });

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = panel.classList.contains('open');
      if (isOpen) window.ftCloseDropdownPanels();
      else {
        ensureListRendered();
        window.ftOpenDropdownPanel(panel, btn);
      }
    });

    panel.addEventListener('click', (e) => e.stopPropagation());
    search.addEventListener('input', () => {
      ensureListRendered();
      renderList();
    });

    wrap.appendChild(btn);
    wrap.appendChild(panel);

    wrap._update = () => {
      const n = statusFilterSelectionCount(key);
      pill.textContent = String(n);
      btn.classList.toggle('is-active', n > 0);
      if (listRendered || panel.classList.contains('open')) renderList();
    };

    return wrap;
  }

  function splitPathForTree(s) {
    const str = String(s || '').trim();
    if (!str) return [''];
    // поддерживаем несколько типичных разделителей пути
    if (str.includes(' -> ')) return str.split(' -> ').map(x => x.trim()).filter(Boolean);
    if (str.includes('>')) return str.split('>').map(x => x.trim()).filter(Boolean);
    if (str.includes('›')) return str.split('›').map(x => x.trim()).filter(Boolean);
    if (str.includes('→')) return str.split('→').map(x => x.trim()).filter(Boolean);
    if (str.includes(' / ')) return str.split(' / ').map(x => x.trim()).filter(Boolean);
    if (str.includes('/')) return str.split('/').map(x => x.trim()).filter(Boolean);
    return [str];
  }

  function mkDropdownTree(key, title, options) {
    const wrap = document.createElement('div');
    wrap.className = 'dd';
    let listRendered = false;

    const btn = document.createElement('div');
    btn.className = 'ddbtn';
    const t = document.createElement('span');
    t.textContent = title;
    const pill = document.createElement('span');
    pill.className = 'pill';
    btn.appendChild(t);
    btn.appendChild(pill);

    const panel = document.createElement('div');
    panel.className = 'ddpanel';

    const search = document.createElement('input');
    search.className = 'ddsearch';
    search.type = 'text';
    search.placeholder = 'поиск...';
    panel.appendChild(search);

    const actions = document.createElement('div');
    actions.className = 'ddactions';

    const btnSelectAll = document.createElement('button');
    btnSelectAll.type = 'button';
    btnSelectAll.textContent = 'Выбрать все';
    actions.appendChild(btnSelectAll);

    const btnClearAll = document.createElement('button');
    btnClearAll.type = 'button';
    btnClearAll.textContent = 'Очистить все';
    actions.appendChild(btnClearAll);

    panel.appendChild(actions);

    const list = document.createElement('div');
    panel.appendChild(list);

    // дерево строим один раз на основе отображаемых путей (label), но листья — это исходные values
    const expanded = new Set(); // nodeId
    const root = { id: '__root__', label: '', depth: -1, children: new Map(), leafValues: new Set() };

    function ensureNode(parent, seg, nodeId, depth) {
      if (!parent.children.has(seg)) {
        parent.children.set(seg, { id: nodeId, label: seg, depth, children: new Map(), leafValues: new Set() });
      }
      return parent.children.get(seg);
    }

    options.forEach((val) => {
      const label = lbl(key, val);
      const parts = splitPathForTree(label);
      let node = root;
      let acc = [];
      parts.forEach((seg, i) => {
        acc.push(seg);
        const nodeId = acc.join(' / ');
        node = ensureNode(node, seg, nodeId, i);
        node.leafValues.add(String(val));
      });
      // на корне тоже храним листья, чтобы можно было быстро считать
      root.leafValues.add(String(val));
      // дефолт: раскрываем первые 1–2 уровня, чтобы была видна иерархия
      if (parts.length > 1) {
        expanded.add(parts[0]);
        if (parts.length > 2) expanded.add(parts.slice(0, 2).join(' / '));
      }
    });

    function isNodeVisibleBySearch(node, q) {
      if (!q) return true;
      const hay = String(node.label || '').toLowerCase();
      if (hay.includes(q)) return true;
      // если любой потомок видим — показываем и родителей
      for (const ch of node.children.values()) {
        if (isNodeVisibleBySearch(ch, q)) return true;
      }
      return false;
    }

    function nodeSelectionState(node, selectedSet) {
      const total = node.leafValues.size;
      if (!total) return { all: false, none: true, ind: false };
      let sel = 0;
      node.leafValues.forEach(v => { if (selectedSet.has(v)) sel++; });
      const all = sel === total;
      const none = sel === 0;
      return { all, none, ind: (!all && !none) };
    }

    function toggleNodeValues(node, checked) {
      const s = new Set((selected[key] || []).map(String));
      if (checked) node.leafValues.forEach(v => s.add(v));
      else node.leafValues.forEach(v => s.delete(v));
      selected[key] = Array.from(s.values());
      updatePills();
      saveFiltersDraft();
      deferDropdownAutoApply(panel);
    }

    function renderNode(node, q, container) {
      if (node.id !== '__root__' && !isNodeVisibleBySearch(node, q)) return;

      if (node.id !== '__root__') {
        const row = document.createElement('div');
        row.className = 'dditem';
        row.style.display = 'flex';
        row.style.alignItems = 'center';
        row.style.gap = '6px';
        row.style.paddingLeft = (6 + Math.max(0, node.depth) * 14) + 'px';

        const hasChildren = node.children && node.children.size > 0;

        // caret
        const caret = document.createElement('span');
        caret.textContent = hasChildren ? (expanded.has(node.id) ? '▼' : '▶') : '';
        caret.style.width = '14px';
        caret.style.cursor = hasChildren ? 'pointer' : 'default';
        caret.style.userSelect = 'none';

        if (hasChildren) {
          caret.addEventListener('click', (e) => {
            e.stopPropagation();
            if (expanded.has(node.id)) expanded.delete(node.id);
            else expanded.add(node.id);
            renderList();
          });
        }

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        const st = nodeSelectionState(node, new Set((selected[key] || []).map(String)));
        cb.checked = st.all;
        cb.indeterminate = st.ind;

        cb.addEventListener('change', (e) => {
          e.stopPropagation();
          toggleNodeValues(node, cb.checked);
          renderList();
        });

        const txt = document.createElement('span');
        txt.textContent = node.label;

        // клик по тексту тоже раскрывает/сворачивает (если есть дети)
        txt.style.cursor = hasChildren ? 'pointer' : 'default';
        if (hasChildren) {
          txt.addEventListener('click', (e) => {
            e.stopPropagation();
            if (expanded.has(node.id)) expanded.delete(node.id);
            else expanded.add(node.id);
            renderList();
          });
        }

        row.appendChild(caret);
        row.appendChild(cb);
        row.appendChild(txt);
        container.appendChild(row);

        if (!hasChildren) return;
        if (!expanded.has(node.id) && q) {
          // при активном поиске раскрываем ветку, чтобы видеть совпадения
        } else if (!expanded.has(node.id)) {
          return;
        }
      }

      // children
      const children = Array.from(node.children.values()).sort((a, b) => String(a.label).localeCompare(String(b.label), 'ru'));
      children.forEach(ch => renderNode(ch, q, container));
    }

    function renderList() {
      const q = search.value.trim().toLowerCase();
      list.innerHTML = '';
      renderNode(root, q, list);
      listRendered = true;
    }

    function ensureListRendered() {
      if (!listRendered) renderList();
    }

    btnSelectAll.addEventListener('click', () => {
      selected[key] = Array.from(new Set(options.map((val) => String(val))).values());
      updatePills();
      if (listRendered || panel.classList.contains('open')) renderList();
      saveFiltersDraft();
      deferDropdownAutoApply(panel);
    });

    btnClearAll.addEventListener('click', () => {
      selected[key] = [];
      updatePills();
      if (listRendered || panel.classList.contains('open')) renderList();
      saveFiltersDraft();
      deferDropdownAutoApply(panel);
    });

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = panel.classList.contains('open');
      if (isOpen) window.ftCloseDropdownPanels();
      else {
        ensureListRendered();
        window.ftOpenDropdownPanel(panel, btn);
      }
    });

    panel.addEventListener('click', (e) => e.stopPropagation());
    search.addEventListener('input', () => {
      ensureListRendered();
      renderList();
    });

    wrap.appendChild(btn);
    wrap.appendChild(panel);

    wrap._update = () => {
      const n = (selected[key] || []).length;
      pill.textContent = n ? String(n) : '';
      pill.style.display = n ? 'inline-flex' : 'none';
      btn.classList.toggle('is-active', n > 0);
      if (listRendered || panel.classList.contains('open')) renderList();
    };

    return wrap;
  }

  const ddCat = mkDropdownTree('catpath', 'Категория поставщика', facets.catpath || []);
  const ddOzon = mkDropdownTree('ozoncat', 'Категория Ozon', facets.ozoncat || []);
  const ddWb = mkDropdownTree('wbcat', 'Категория WB', facets.wbcat || []);
  const ddBrandOzon = mkDropdownFlat('brand_ozon', 'Бренд Ozon', facets.brand_ozon || []);
  const ddBrandWb = mkDropdownFlat('brand_wb', 'Бренд WB', facets.brand_wb || []);
  const ddModel = mkDropdownFlat('model', 'Модель', facets.model || []);
  const ddBrandStatusOzon = mkDropdownFlat('brand_status_ozon', 'Статус бренда Ozon', facets.brand_status_ozon || []);
  const ddBrandStatusWb = mkDropdownFlat('brand_status_wb', 'Статус бренда WB', facets.brand_status_wb || []);
  const ddStatusOzon = mkDropdownStatus('status_ozon', 'Статус Ozon', facets.status_ozon || []);
  const ddStatusWb = mkDropdownStatus('status_wb', 'Статус WB', facets.status_wb || []);
  const ddHashtag = mkDropdownFlat('hashtag', 'Хештеги', (facets && facets.hashtag) ? facets.hashtag : []);


  host.innerHTML = '';
  host.appendChild(ddCat);
  host.appendChild(ddOzon);
  host.appendChild(ddWb);
  host.appendChild(ddBrandOzon);
  host.appendChild(ddBrandWb);
  host.appendChild(ddModel);
  host.appendChild(ddBrandStatusOzon);
  host.appendChild(ddBrandStatusWb);
  host.appendChild(ddStatusOzon);
  host.appendChild(ddStatusWb);
  host.appendChild(ddHashtag);

  function renderParamFilters() {
    if (!paramsHost) return;
    const facetsParams = (facets && facets.params) ? facets.params : {};
    const pnames = Object.keys(facetsParams || {});

    if (!pnames.length) {
      if (paramsCard) paramsCard.style.display = '';
      if (paramFacetsError) {
        paramsHost.innerHTML = '<div class="toolbar-note">' + paramFacetsError + '</div>';
      } else if (paramFacetsLoading) {
        paramsHost.innerHTML = '<div class="toolbar-note">Загружаю значения характеристик...</div>';
      } else if (paramFacetsLoaded) {
        paramsHost.innerHTML = '<div class="toolbar-note">Нет доступных значений характеристик для фильтра.</div>';
      } else {
        paramsHost.innerHTML = '<div class="toolbar-note">Открой раздел, чтобы загрузить значения характеристик.</div>';
      }
      return;
    }

    if (paramsCard) paramsCard.style.display = '';
    paramsHost.innerHTML = '';

    pnames.forEach((pname) => {
      if (!Array.isArray(selectedParams[pname])) selectedParams[pname] = [];

      const wrap = document.createElement('div');
      wrap.className = 'dd';
      let listRendered = false;

      const btn = document.createElement('div');
      btn.className = 'ddbtn';

      const t = document.createElement('span');
      t.textContent = pname;

      const pill = document.createElement('span');
      pill.className = 'pill';

      btn.appendChild(t);
      btn.appendChild(pill);

      const panel = document.createElement('div');
      panel.className = 'ddpanel';

      const search = document.createElement('input');
      search.className = 'ddsearch';
      search.type = 'text';
      search.placeholder = 'поиск...';
      panel.appendChild(search);

      const actions = document.createElement('div');
      actions.className = 'ddactions';

      const btnClearSelected = document.createElement('button');
      btnClearSelected.type = 'button';
      btnClearSelected.textContent = 'Очистить выбор';
      actions.appendChild(btnClearSelected);

      panel.appendChild(actions);

      const list = document.createElement('div');
      panel.appendChild(list);

      if (Array.isArray(facetsParams[pname]) && facetsParams[pname].length) {
        paramValuesLoaded[pname] = true;
      }

      function currentOptions() {
        return Array.isArray(facets.params && facets.params[pname]) ? facets.params[pname] : [];
      }

      function includeSelectedOptions(options) {
        const out = Array.isArray(options) ? options.slice() : [];
        const seen = new Set(out.map((value) => String(value)));
        (selectedParams[pname] || []).forEach((value) => {
          value = String(value);
          if (value !== '' && !seen.has(value)) {
            out.push(value);
            seen.add(value);
          }
        });
        return out;
      }

      function updatePill() {
        const n = (selectedParams[pname] || []).length;
        pill.textContent = n ? String(n) : '';
        pill.style.display = n ? 'inline-flex' : 'none';
        btn.classList.toggle('is-active', n > 0);
        btnClearSelected.disabled = n <= 0;
      }

      function renderList() {
        listRendered = true;
        const q = search.value.trim().toLowerCase();
        list.innerHTML = '';
        if (paramValuesLoading[pname]) {
          list.innerHTML = '<div class="toolbar-note">Загружаю значения...</div>';
          return;
        }
        if (paramValuesError[pname]) {
          list.innerHTML = '<div class="toolbar-note">' + paramValuesError[pname] + '</div>';
          return;
        }
        if (!paramValuesLoaded[pname] && paramValueFacetsUrl) {
          list.innerHTML = '<div class="toolbar-note">Открой список, чтобы загрузить значения.</div>';
          return;
        }

        const options = includeSelectedOptions(currentOptions());
        if (!options.length) {
          list.innerHTML = '<div class="toolbar-note">Нет значений.</div>';
          return;
        }

        options.forEach((val) => {
          const text = lblParamValue(val);
          if (q && !text.toLowerCase().includes(q)) return;

          const item = document.createElement('label');
          item.className = 'dditem';

          const cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.value = val;
          cb.checked = (selectedParams[pname] || []).includes(val);

          cb.addEventListener('change', () => {
            const s = new Set((selectedParams[pname] || []).map(String));
            if (cb.checked) s.add(String(val));
            else s.delete(String(val));
            selectedParams[pname] = Array.from(s.values());
          updatePill();
          updateParamFiltersSummary();
          updateInfo();
          saveFiltersDraft();
          deferDropdownAutoApply(panel);
        });

          const span = document.createElement('span');
          span.textContent = text;

          item.appendChild(cb);
          item.appendChild(span);
          list.appendChild(item);
        });
      }

      btnClearSelected.addEventListener('click', () => {
        if (!Array.isArray(selectedParams[pname]) || selectedParams[pname].length === 0) return;
        selectedParams[pname] = [];
        updatePill();
        updateParamFiltersSummary();
        updateInfo();
        if (listRendered || panel.classList.contains('open')) renderList();
        saveFiltersDraft();
        deferDropdownAutoApply(panel);
      });

      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const isOpen = panel.classList.contains('open');
        if (isOpen) window.ftCloseDropdownPanels();
        else {
          window.ftOpenDropdownPanel(panel, btn);
          if (!paramValuesLoaded[pname] && paramValueFacetsUrl) {
            await loadParamValues(pname, renderList);
          } else {
            renderList();
          }
        }
      });

      panel.addEventListener('click', (e) => e.stopPropagation());
      search.addEventListener('input', async () => {
        if (!paramValuesLoaded[pname] && paramValueFacetsUrl) {
          await loadParamValues(pname, renderList);
        } else {
          renderList();
        }
      });

      wrap.appendChild(btn);
      wrap.appendChild(panel);
      paramsHost.appendChild(wrap);

      updatePill();
      if ((selectedParams[pname] || []).length) {
        renderList();
      }
    });

    updateParamFiltersSummary();
  }


  let paramFiltersRendered = false;
  async function loadParamValues(pname, onDone) {
    pname = String(pname || '').trim();
    if (!pname || !paramValueFacetsUrl || paramValuesLoaded[pname] || paramValuesLoading[pname]) return;
    paramValuesLoading[pname] = true;
    paramValuesError[pname] = '';
    if (typeof onDone === 'function') onDone();
    try {
      const url = new URL(paramValueFacetsUrl, window.location.href);
      url.searchParams.set('param', pname);
      const response = await fetch(url.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const payload = await response.json();
      if (!payload || payload.ok !== true || !Array.isArray(payload.values)) {
        throw new Error((payload && payload.error) ? String(payload.error) : 'Не удалось загрузить значения.');
      }
      if (!facets.params || typeof facets.params !== 'object') facets.params = {};
      facets.params[pname] = payload.values;
      paramValuesLoaded[pname] = true;
    } catch (e) {
      paramValuesError[pname] = 'Не удалось загрузить значения. Попробуй открыть список ещё раз.';
    } finally {
      paramValuesLoading[pname] = false;
      if (typeof onDone === 'function') onDone();
    }
  }

  async function loadParamFacets() {
    if (paramFacetsLoaded || paramFacetsLoading || !paramFacetsUrl) return;
    paramFacetsLoading = true;
    paramFacetsError = '';
    renderParamFilters();
    try {
      const response = await fetch(paramFacetsUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const payload = await response.json();
      if (!payload || payload.ok !== true) {
        throw new Error((payload && payload.error) ? String(payload.error) : 'Не удалось загрузить значения характеристик.');
      }
      facets.params = (payload.params && typeof payload.params === 'object') ? payload.params : {};
      Object.keys(facets.params).forEach((pname) => {
        if (Array.isArray(facets.params[pname]) && facets.params[pname].length) {
          paramValuesLoaded[pname] = true;
        }
      });
      paramFacetsLoaded = true;
    } catch (e) {
      paramFacetsError = 'Не удалось загрузить значения характеристик. Обнови страницу или попробуй снова.';
    } finally {
      paramFacetsLoading = false;
      paramFiltersRendered = false;
      ensureParamFiltersRendered();
    }
  }

  function ensureParamFiltersRendered() {
    if (!paramFacetsLoaded && paramFacetsUrl && !paramFacetsError) {
      loadParamFacets().catch(() => {});
      return;
    }
    if (paramFiltersRendered) {
      updateParamFiltersSummary();
      return;
    }
    renderParamFilters();
    paramFiltersRendered = true;
  }

  if (paramsCard && paramsCard.open) {
    ensureParamFiltersRendered();
  } else {
    updateParamFiltersSummary();
  }

  if (paramsCard) {
    paramsCard.addEventListener('toggle', () => {
      if (paramsCard.open) ensureParamFiltersRendered();
    });
  }


  function updateInfo() {
    const paramsCnt = selectedParamsCount();
    const cnt = (
      selected.catpath.length +
      selected.ozoncat.length +
      selected.wbcat.length +
      selected.brand_ozon.length +
      selected.brand_wb.length +
      selected.model.length +
      selected.brand_status_ozon.length +
      selected.brand_status_wb.length +
      selected.status_ozon.length +
      selected.status_wb.length +
      selected.hashtag.length +
      paramsCnt
    );
    const q = appliedSearchValue;
    const stockOn = inStockOnly && inStockOnly.checked;
    const ozonOff = notInOzonOnly && notInOzonOnly.checked;
    const ozonArchOff = notInOzonArchiveOnly && notInOzonArchiveOnly.checked;
    const wbOff = notInWbOnly && notInWbOnly.checked;
    const hasPicture = hasPictureOnly && hasPictureOnly.checked;
    const notBulkyOzon = notBulkyOzonOnly && notBulkyOzonOnly.checked;
    const ozonMarkingError = ozonMarkingErrorOnly && ozonMarkingErrorOnly.checked;
    const selectedOnlyOn = selectedOnly && selectedOnly.checked;
    const priceMin = priceMinInput ? String(priceMinInput.value || '').trim() : '';
    const priceMax = priceMaxInput ? String(priceMaxInput.value || '').trim() : '';
    const stockMin = stockMinInput ? String(stockMinInput.value || '').trim() : '';
    const stockMax = stockMaxInput ? String(stockMaxInput.value || '').trim() : '';
    const readinessStates = currentReadinessStateLists();
    const readinessKeys = readinessStates.all;
    const readinessSummaryParts = [];
    if (readinessStates.ready.length) readinessSummaryParts.push('готово ' + readinessStates.ready.length);
    if (readinessStates.missing.length) readinessSummaryParts.push('не готово ' + readinessStates.missing.length);
    const qualityHintKeys = currentQualityHintKeys();
    const analyticsActive = analyticsRangeDefs
      .map((def) => {
        const minValue = analyticsRangeFilterValue(def.key, 'min');
        const maxValue = analyticsRangeFilterValue(def.key, 'max');
        if (!minValue && !maxValue) return '';
        return def.label + ': ' + (minValue || '0') + '–' + (maxValue || '∞') + (def.suffix || '');
      })
      .filter(Boolean);
    const visibleFilterCount = cnt + analyticsActive.length + readinessKeys.length + qualityHintKeys.length + (ozonMarkingError ? 1 : 0);

    updateParamFiltersSummary();

    if (info) {
      if (cnt || q || stockOn || ozonOff || ozonArchOff || wbOff || hasPicture || notBulkyOzon || ozonMarkingError || selectedOnlyOn || priceMin || priceMax || stockMin || stockMax || readinessKeys.length || qualityHintKeys.length || analyticsActive.length) {

        info.textContent =
          'Активно: ' +
          (visibleFilterCount ? ('фильтры ' + visibleFilterCount) : 'фильтры 0') +
          (q ? (', поиск: "' + q + '"') : '') +
          (stockOn ? ', в наличии' : '') +
          (hasPicture ? ', только с фото' : '') +
          (notBulkyOzon ? ', без КГТ Ozon' : '') +
          (selectedOnlyOn ? ', только выбранные' : '') +
          ((priceMin || priceMax) ? (', цена: ' + (priceMin || '0') + '–' + (priceMax || '∞')) : '') +
          ((stockMin || stockMax) ? (', остатки: ' + (stockMin || '0') + '–' + (stockMax || '∞')) : '') +
          (readinessKeys.length ? (', готовность: ' + readinessSummaryParts.join(', ')) : '') +
          (qualityHintKeys.length ? (', улучшить: ' + qualityHintKeys.length) : '') +
          (ozonMarkingError ? ', Ozon: нужен код маркировки' : '') +
          (analyticsActive.length ? (', ' + analyticsActive.join(', ')) : '') +
          (ozonOff ? ', нет в Ozon' : '') +
          (ozonArchOff ? ', нет в архиве Ozon' : '') +
          (wbOff ? ', нет на WB' : '');
      } else {
        info.textContent = 'Фильтры не выбраны';
      }
    }
  }

  function updateActiveFilterChrome() {
    [
      inStockOnly,
      notInOzonOnly,
      notInOzonArchiveOnly,
      notInWbOnly,
      hasPictureOnly,
      notBulkyOzonOnly,
      selectedOnly
    ].forEach((input) => {
      if (!input) return;
      const chip = input.closest ? input.closest('.checkbox-chip') : null;
      if (chip) chip.classList.toggle('is-active', !!input.checked);
    });
    if (btnShowSelectedOnly) {
      const active = !!(selectedOnly && selectedOnly.checked);
      btnShowSelectedOnly.classList.toggle('is-active', active);
      btnShowSelectedOnly.setAttribute('aria-pressed', active ? 'true' : 'false');
    }
    readinessInputs.forEach((input) => {
      const chip = input && input.closest ? input.closest('.readiness-filter-chip') : null;
      const state = readinessStateValue(input);
      if (chip) {
        chip.classList.toggle('is-active', state !== '');
        chip.classList.toggle('is-ready', state === 'ready');
        chip.classList.toggle('is-missing', state === 'missing');
        chip.setAttribute('aria-pressed', state !== '' ? 'true' : 'false');
      }
    });
    const readinessCard = readinessInputs.length ? readinessInputs[0].closest('.readiness-filter-card') : null;
    if (readinessCard) readinessCard.classList.toggle('is-active', currentReadinessKeys().length > 0);
    qualityHintInputs.forEach((input) => {
      const chip = input && input.closest ? input.closest('.quality-hint-filter-chip') : null;
      if (chip) chip.classList.toggle('is-active', !!input.checked);
    });
    const qualityHintCard = qualityHintInputs.length ? qualityHintInputs[0].closest('.readiness-filter-card') : null;
    if (qualityHintCard) qualityHintCard.classList.toggle('is-active', currentQualityHintKeys().length > 0);
    if (ozonMarkingErrorOnly) {
      const chip = ozonMarkingErrorOnly.closest ? ozonMarkingErrorOnly.closest('.ozon-marking-error-filter-chip') : null;
      const card = ozonMarkingErrorOnly.closest ? ozonMarkingErrorOnly.closest('.readiness-filter-card') : null;
      if (chip) chip.classList.toggle('is-active', !!ozonMarkingErrorOnly.checked);
      if (card) card.classList.toggle('is-active', !!ozonMarkingErrorOnly.checked);
    }

    const toggleRange = (inputA, inputB) => {
      const card = inputA && inputA.closest ? inputA.closest('.range-card') : null;
      if (!card) return;
      const active = String(inputA.value || '').trim() !== '' || (inputB && String(inputB.value || '').trim() !== '');
      card.classList.toggle('is-active', active);
    };
    toggleRange(priceMinInput, priceMaxInput);
    toggleRange(stockMinInput, stockMaxInput);
    syncAllAnalyticsRanges();
  }

  function buildFiltersUrl(clearAll, includeSearch) {
    const url = new URL(cfg.formAction || window.location.pathname, window.location.href);
    const current = new URL(window.location.href);
    const deleteKeys = Array.from(current.searchParams.keys()).filter((key) => {
      return key === 'page'
        || key === 'q_name'
        || key === 'content_filter'
        || key === 'content_issue'
        || key === 'filter_action'
        || key === 'filter_apply'
        || key === 'filter_clear'
        || key.startsWith('f_');
    });
    current.searchParams.forEach((value, key) => {
      if (!deleteKeys.includes(key)) {
        url.searchParams.append(key, value);
      }
    });
    deleteKeys.forEach((key) => url.searchParams.delete(key));

    url.searchParams.set('id', datasetId);
    url.searchParams.set('limit', String(cfg.limit || '10'));
    url.searchParams.set('page', '1');
    if (cfg.sort) {
      url.searchParams.set('sort', String(cfg.sort || ''));
      url.searchParams.set('dir', String(cfg.dir || 'asc'));
    }

    if (clearAll) {
      url.searchParams.set('filter_clear', '1');
      return url.toString();
    }

    if (cfg.contentFilter) {
      url.searchParams.set('content_filter', String(cfg.contentFilter || ''));
      if (cfg.contentIssue) url.searchParams.set('content_issue', String(cfg.contentIssue || ''));
    }

    url.searchParams.set('filter_apply', '1');
    selected.catpath.forEach((value) => url.searchParams.append('f_catpath[]', value));
    selected.ozoncat.forEach((value) => url.searchParams.append('f_ozoncat[]', value));
    selected.wbcat.forEach((value) => url.searchParams.append('f_wbcat[]', value));
    selected.brand_ozon.forEach((value) => url.searchParams.append('f_brand_ozon[]', value));
    selected.brand_wb.forEach((value) => url.searchParams.append('f_brand_wb[]', value));
    selected.model.forEach((value) => url.searchParams.append('f_model[]', value));
    selected.brand_status_ozon.forEach((value) => url.searchParams.append('f_brand_status_ozon[]', value));
    selected.brand_status_wb.forEach((value) => url.searchParams.append('f_brand_status_wb[]', value));
    selected.status_ozon.forEach((value) => url.searchParams.append('f_status_ozon[]', value));
    selected.status_wb.forEach((value) => url.searchParams.append('f_status_wb[]', value));
    selected.hashtag.forEach((value) => url.searchParams.append('f_hashtag[]', value));
    const readinessStates = currentReadinessStateLists();
    readinessStates.ready.forEach((value) => url.searchParams.append('f_ready_ready[]', value));
    readinessStates.missing.forEach((value) => url.searchParams.append('f_ready_missing[]', value));
    currentQualityHintKeys().forEach((value) => url.searchParams.append('f_quality_hint[]', value));
    if (ozonMarkingErrorOnly && ozonMarkingErrorOnly.checked) url.searchParams.set('f_ozon_marking_error', '1');

    const q = includeSearch ? (nameInput ? String(nameInput.value || '').trim() : '') : appliedSearchValue;
    if (q) url.searchParams.set('q_name', q);
    if (inStockOnly && inStockOnly.checked) url.searchParams.set('f_instock', '1');
    if (notInOzonOnly && notInOzonOnly.checked) url.searchParams.set('f_not_in_ozon', '1');
    if (notInOzonArchiveOnly && notInOzonArchiveOnly.checked) url.searchParams.set('f_not_in_ozon_archive', '1');
    if (notInWbOnly && notInWbOnly.checked) url.searchParams.set('f_not_in_wb', '1');
    if (hasPictureOnly && hasPictureOnly.checked) url.searchParams.set('f_has_picture', '1');
    if (notBulkyOzonOnly && notBulkyOzonOnly.checked) url.searchParams.set('f_not_bulky_ozon', '1');
    if (selectedOnly && selectedOnly.checked) {
      url.searchParams.set('f_selected_only', '1');
    }

    const priceMin = priceMinInput ? String(priceMinInput.value || '').trim() : '';
    const priceMax = priceMaxInput ? String(priceMaxInput.value || '').trim() : '';
    const stockMin = stockMinInput ? String(stockMinInput.value || '').trim() : '';
    const stockMax = stockMaxInput ? String(stockMaxInput.value || '').trim() : '';
    if (priceMin) url.searchParams.set('f_price_min', priceMin);
    if (priceMax) url.searchParams.set('f_price_max', priceMax);
    if (stockMin) url.searchParams.set('f_stock_min', stockMin);
    if (stockMax) url.searchParams.set('f_stock_max', stockMax);
    const ozonHitsMin = analyticsRangeFilterValue('ozonHits', 'min');
    const ozonHitsMax = analyticsRangeFilterValue('ozonHits', 'max');
    const ozonSalesMin = analyticsRangeFilterValue('ozonSales', 'min');
    const ozonSalesMax = analyticsRangeFilterValue('ozonSales', 'max');
    const ozonViewCardMin = analyticsRangeFilterValue('ozonViewCard', 'min');
    const ozonViewCardMax = analyticsRangeFilterValue('ozonViewCard', 'max');
    const ozonCardOrderMin = analyticsRangeFilterValue('ozonCardOrder', 'min');
    const ozonCardOrderMax = analyticsRangeFilterValue('ozonCardOrder', 'max');
    if (ozonHitsMin) url.searchParams.set('f_ozon_hits_min', ozonHitsMin);
    if (ozonHitsMax) url.searchParams.set('f_ozon_hits_max', ozonHitsMax);
    if (ozonSalesMin) url.searchParams.set('f_ozon_sales_min', ozonSalesMin);
    if (ozonSalesMax) url.searchParams.set('f_ozon_sales_max', ozonSalesMax);
    if (ozonViewCardMin) url.searchParams.set('f_ozon_view_card_min', ozonViewCardMin);
    if (ozonViewCardMax) url.searchParams.set('f_ozon_view_card_max', ozonViewCardMax);
    if (ozonCardOrderMin) url.searchParams.set('f_ozon_card_order_min', ozonCardOrderMin);
    if (ozonCardOrderMax) url.searchParams.set('f_ozon_card_order_max', ozonCardOrderMax);

    Object.keys(selectedParams).forEach((pname) => {
      const values = selectedParams[pname];
      if (!Array.isArray(values) || !values.length) return;
      values.forEach((value) => url.searchParams.append(`f_param[${pname}][]`, value));
    });

    return url.toString();
  }

  function buildSessionBackedFiltersUrl() {
    const url = new URL(cfg.formAction || window.location.pathname, window.location.href);
    const current = new URL(window.location.href);
    current.searchParams.forEach((value, key) => {
      if (
        key === 'page'
        || key === 'q_name'
        || key === 'content_filter'
        || key === 'content_issue'
        || key === 'filter_action'
        || key === 'filter_apply'
        || key === 'filter_clear'
        || key.startsWith('f_')
      ) {
        return;
      }
      url.searchParams.append(key, value);
    });
    url.searchParams.set('id', datasetId);
    url.searchParams.set('limit', String(cfg.limit || '10'));
    url.searchParams.set('page', '1');
    if (cfg.sort) {
      url.searchParams.set('sort', String(cfg.sort || ''));
      url.searchParams.set('dir', String(cfg.dir || 'asc'));
    }
    if (cfg.contentFilter) {
      url.searchParams.set('content_filter', String(cfg.contentFilter || ''));
      if (cfg.contentIssue) url.searchParams.set('content_issue', String(cfg.contentIssue || ''));
    }
    return url.toString();
  }

  function selectedOfferIdsForFilter() {
    const hidden = document.getElementById('offerIdsJson');
    const parseIds = (raw) => {
      try {
        const parsed = JSON.parse(String(raw || ''));
        if (Array.isArray(parsed)) {
          return Array.from(new Set(parsed.map((id) => String(id || '').trim()).filter(Boolean)));
        }
      } catch (e) {}
      return [];
    };

    const fromHidden = hidden ? parseIds(hidden.value) : [];
    if (fromHidden.length) return fromHidden;

    try {
      return parseIds(localStorage.getItem('feedtools_selected_offers_' + datasetId) || '');
    } catch (e) {
      return [];
    }
  }

  function appendFilterFields(target, includeSearch) {
    target.set('filter_action', 'apply');
    target.set('id', datasetId);
    target.set('limit', String(cfg.limit || '10'));
    if (cfg.sort) {
      target.set('sort', String(cfg.sort || ''));
      target.set('dir', String(cfg.dir || 'asc'));
    }
    if (cfg.contentFilter) {
      target.set('content_filter', String(cfg.contentFilter || ''));
      if (cfg.contentIssue) target.set('content_issue', String(cfg.contentIssue || ''));
    }

    selected.catpath.forEach((value) => target.append('f_catpath[]', value));
    selected.ozoncat.forEach((value) => target.append('f_ozoncat[]', value));
    selected.wbcat.forEach((value) => target.append('f_wbcat[]', value));
    selected.brand_ozon.forEach((value) => target.append('f_brand_ozon[]', value));
    selected.brand_wb.forEach((value) => target.append('f_brand_wb[]', value));
    selected.model.forEach((value) => target.append('f_model[]', value));
    selected.brand_status_ozon.forEach((value) => target.append('f_brand_status_ozon[]', value));
    selected.brand_status_wb.forEach((value) => target.append('f_brand_status_wb[]', value));
    selected.status_ozon.forEach((value) => target.append('f_status_ozon[]', value));
    selected.status_wb.forEach((value) => target.append('f_status_wb[]', value));
    selected.hashtag.forEach((value) => target.append('f_hashtag[]', value));
    const readinessStates = currentReadinessStateLists();
    readinessStates.ready.forEach((value) => target.append('f_ready_ready[]', value));
    readinessStates.missing.forEach((value) => target.append('f_ready_missing[]', value));
    currentQualityHintKeys().forEach((value) => target.append('f_quality_hint[]', value));
    if (ozonMarkingErrorOnly && ozonMarkingErrorOnly.checked) target.set('f_ozon_marking_error', '1');

    const q = includeSearch ? (nameInput ? String(nameInput.value || '').trim() : '') : appliedSearchValue;
    if (q) target.set('q_name', q);
    if (inStockOnly && inStockOnly.checked) target.set('f_instock', '1');
    if (notInOzonOnly && notInOzonOnly.checked) target.set('f_not_in_ozon', '1');
    if (notInOzonArchiveOnly && notInOzonArchiveOnly.checked) target.set('f_not_in_ozon_archive', '1');
    if (notInWbOnly && notInWbOnly.checked) target.set('f_not_in_wb', '1');
    if (hasPictureOnly && hasPictureOnly.checked) target.set('f_has_picture', '1');
    if (notBulkyOzonOnly && notBulkyOzonOnly.checked) target.set('f_not_bulky_ozon', '1');
    if (selectedOnly && selectedOnly.checked) {
      target.set('f_selected_only', '1');
      target.set('f_selected_ids', JSON.stringify(selectedOfferIdsForFilter()));
    }

    const priceMin = priceMinInput ? String(priceMinInput.value || '').trim() : '';
    const priceMax = priceMaxInput ? String(priceMaxInput.value || '').trim() : '';
    const stockMin = stockMinInput ? String(stockMinInput.value || '').trim() : '';
    const stockMax = stockMaxInput ? String(stockMaxInput.value || '').trim() : '';
    if (priceMin) target.set('f_price_min', priceMin);
    if (priceMax) target.set('f_price_max', priceMax);
    if (stockMin) target.set('f_stock_min', stockMin);
    if (stockMax) target.set('f_stock_max', stockMax);
    const ozonHitsMin = analyticsRangeFilterValue('ozonHits', 'min');
    const ozonHitsMax = analyticsRangeFilterValue('ozonHits', 'max');
    const ozonSalesMin = analyticsRangeFilterValue('ozonSales', 'min');
    const ozonSalesMax = analyticsRangeFilterValue('ozonSales', 'max');
    const ozonViewCardMin = analyticsRangeFilterValue('ozonViewCard', 'min');
    const ozonViewCardMax = analyticsRangeFilterValue('ozonViewCard', 'max');
    const ozonCardOrderMin = analyticsRangeFilterValue('ozonCardOrder', 'min');
    const ozonCardOrderMax = analyticsRangeFilterValue('ozonCardOrder', 'max');
    if (ozonHitsMin) target.set('f_ozon_hits_min', ozonHitsMin);
    if (ozonHitsMax) target.set('f_ozon_hits_max', ozonHitsMax);
    if (ozonSalesMin) target.set('f_ozon_sales_min', ozonSalesMin);
    if (ozonSalesMax) target.set('f_ozon_sales_max', ozonSalesMax);
    if (ozonViewCardMin) target.set('f_ozon_view_card_min', ozonViewCardMin);
    if (ozonViewCardMax) target.set('f_ozon_view_card_max', ozonViewCardMax);
    if (ozonCardOrderMin) target.set('f_ozon_card_order_min', ozonCardOrderMin);
    if (ozonCardOrderMax) target.set('f_ozon_card_order_max', ozonCardOrderMax);

    Object.keys(selectedParams).forEach((pname) => {
      const values = selectedParams[pname];
      if (!Array.isArray(values) || !values.length) return;
      values.forEach((value) => target.append(`f_param[${pname}][]`, value));
    });
  }

  async function persistFiltersToSession(includeSearch, requestSeq) {
    const form = new FormData();
    appendFilterFields(form, includeSearch);
    form.set('filter_request_seq', String(requestSeq || 0));
    const response = await fetch(cfg.formAction || window.location.pathname, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: form
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data || !data.ok) {
      throw new Error('Не удалось сохранить фильтры.');
    }
  }

  async function submitFilters(clearAll, opts) {
    const options = opts || {};
    if (autoSubmitTimer) {
      window.clearTimeout(autoSubmitTimer);
      autoSubmitTimer = null;
    }
    if (clearAll) {
      appliedSearchValue = '';
    } else if (options.includeSearch) {
      appliedSearchValue = nameInput ? String(nameInput.value || '').trim() : '';
    }
    updateInfo();
    updateActiveFilterChrome();
    clearDeferredDropdownDirtyFlags();
    filterRequestRunning = true;
    setFilterStatus('loading');

    let url = clearAll
      ? buildFiltersUrl(true, !!options.includeSearch)
      : buildSessionBackedFiltersUrl();
    const seq = ++submitSeq;
    try {
      if (!clearAll) {
        await persistFiltersToSession(!!options.includeSearch, seq);
        if (seq !== submitSeq) return;
        url = buildFiltersUrl(false, !!options.includeSearch);
      }
      if (window.ftSupplierOffersLoad) {
        await window.ftSupplierOffersLoad(url);
        if (seq !== submitSeq) return;
        appliedFilterStateSnapshot = filterStateSnapshot();
        filterRequestRunning = false;
        updateFilterStatusFromState();
        return;
      }
      window.location.href = url;
    } catch (e) {
      if (seq === submitSeq) {
        filterRequestRunning = false;
        setFilterStatus('error');
      }
      throw e;
    }
  }

  function scheduleAutoApplyFilters() {
    if (autoSubmitTimer) window.clearTimeout(autoSubmitTimer);
    updateFilterStatusFromState();
    autoSubmitTimer = window.setTimeout(() => {
      submitFilters(false, { includeSearch: false }).catch(() => {});
    }, 220);
  }

  function updatePills() {
    ddCat._update();
    ddOzon._update();
    ddWb._update();
    ddBrandOzon._update();
    ddBrandWb._update();
    ddModel._update();
    ddBrandStatusOzon._update();
    ddBrandStatusWb._update();
    ddStatusOzon._update();
    ddStatusWb._update();
    ddHashtag._update();
    updateParamFiltersSummary();
    updateInfo();
  }

  if (nameInput) nameInput.addEventListener('input', () => {
    updateInfo();
    updateFilterStatusFromState();
    saveFiltersDraft();
  });
  if (nameInput) nameInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      saveFiltersDraft();
      submitFilters(false, { includeSearch: true }).catch(() => {});
    }
  });
  if (inStockOnly) inStockOnly.addEventListener('change', () => {
    updateInfo();
    updateActiveFilterChrome();
    saveFiltersDraft();
    scheduleAutoApplyFilters();
  });

  if (notInOzonOnly) notInOzonOnly.addEventListener('change', () => {
    updateInfo();
    updateActiveFilterChrome();
    saveFiltersDraft();
    scheduleAutoApplyFilters();
  });
  if (notInOzonArchiveOnly) notInOzonArchiveOnly.addEventListener('change', () => {
    updateInfo();
    updateActiveFilterChrome();
    saveFiltersDraft();
    scheduleAutoApplyFilters();
  });
  if (notInWbOnly) notInWbOnly.addEventListener('change', () => {
    updateInfo();
    updateActiveFilterChrome();
    saveFiltersDraft();
    scheduleAutoApplyFilters();
  });
  if (hasPictureOnly) hasPictureOnly.addEventListener('change', () => {
    updateInfo();
    updateActiveFilterChrome();
    saveFiltersDraft();
    scheduleAutoApplyFilters();
  });
  if (notBulkyOzonOnly) notBulkyOzonOnly.addEventListener('change', () => {
    updateInfo();
    updateActiveFilterChrome();
    saveFiltersDraft();
    scheduleAutoApplyFilters();
  });
  if (selectedOnly) selectedOnly.addEventListener('change', () => {
    updateInfo();
    updateActiveFilterChrome();
    saveFiltersDraft();
    scheduleAutoApplyFilters();
  });
  if (btnShowSelectedOnly) btnShowSelectedOnly.addEventListener('click', () => {
    const active = selectedOnlyToggleIsActive();
    if (!active) {
      const selectedIds = selectedOfferIdsForFilter();
      if (!selectedIds.length) {
        window.alert('Сначала выбери товары в таблице.');
        return;
      }
    }
    if (selectedOnly) selectedOnly.checked = !active;
    if (active && btnShowSelectedOnly) {
      btnShowSelectedOnly.classList.remove('is-active');
      btnShowSelectedOnly.setAttribute('aria-pressed', 'false');
    }
    updateInfo();
    updateActiveFilterChrome();
    saveFiltersDraft();
    submitFilters(false, { includeSearch: true }).catch(() => {});
  });
  readinessModeInputs.forEach((input) => {
    input.addEventListener('change', () => {
      updateInfo();
      updateActiveFilterChrome();
      saveFiltersDraft();
      if (currentReadinessKeys().length) scheduleAutoApplyFilters();
    });
  });
  readinessInputs.forEach((input) => {
    const chip = input && input.closest ? input.closest('.readiness-filter-chip') : null;
    const cycle = (event) => {
      if (event) event.preventDefault();
      const state = readinessStateValue(input);
      const next = state === '' ? 'ready' : (state === 'ready' ? 'missing' : '');
      setReadinessInputState(input, next);
      updateInfo();
      updateActiveFilterChrome();
      saveFiltersDraft();
      scheduleAutoApplyFilters();
    };
    if (chip) {
      chip.addEventListener('click', cycle);
      chip.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          cycle(event);
        }
      });
    } else {
      input.addEventListener('change', () => {
        setReadinessInputState(input, input.checked ? 'ready' : '');
        updateInfo();
        updateActiveFilterChrome();
        saveFiltersDraft();
        scheduleAutoApplyFilters();
      });
    }
  });
  qualityHintInputs.forEach((input) => {
    input.addEventListener('change', () => {
      updateInfo();
      updateActiveFilterChrome();
      saveFiltersDraft();
      scheduleAutoApplyFilters();
    });
  });
  if (ozonMarkingErrorOnly) {
    ozonMarkingErrorOnly.addEventListener('change', () => {
      updateInfo();
      updateActiveFilterChrome();
      saveFiltersDraft();
      scheduleAutoApplyFilters();
    });
  }
  document.addEventListener('supplier-selection-change', () => {
    if (!selectedOnly || !selectedOnly.checked) return;
    updateInfo();
    saveFiltersDraft();
    scheduleAutoApplyFilters();
  });
  [priceMinInput, priceMaxInput, stockMinInput, stockMaxInput].forEach((input) => {
    if (!input) return;
    input.addEventListener('input', () => {
      updateInfo();
      updateActiveFilterChrome();
      saveFiltersDraft();
      scheduleAutoApplyFilters();
    });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        saveFiltersDraft();
        submitFilters(false, { includeSearch: false }).catch(() => {});
      }
    });
  });
  analyticsRangeDefs.forEach((def) => {
    [def.min, def.max].forEach((input) => {
      if (!input) return;
      input.addEventListener('input', () => {
        syncAnalyticsRange(def, input.dataset.role === 'max' ? 'max' : 'min');
        updateInfo();
        updateActiveFilterChrome();
        saveFiltersDraft();
        scheduleAutoApplyFilters();
      });
    });
    [
      { input: def.minText, role: 'min' },
      { input: def.maxText, role: 'max' },
    ].forEach(({ input, role }) => {
      if (!input) return;
      input.addEventListener('input', () => {
        const bound = analyticsRangeDefaultMax(def);
        const parsed = analyticsRangeParseNumber(input.value);
        if (role === 'min') {
          def.min.value = parsed !== null ? Math.max(0, Math.min(bound, parsed)) : 0;
        } else {
          def.max.value = parsed !== null ? Math.max(0, Math.min(bound, parsed)) : bound;
        }
        syncAnalyticsRange(def, role, input);
        updateInfo();
        updateActiveFilterChrome();
        saveFiltersDraft();
        scheduleAutoApplyFilters();
      });
      input.addEventListener('blur', () => {
        syncAnalyticsRange(def, role);
        saveFiltersDraft();
      });
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          syncAnalyticsRange(def, role);
          saveFiltersDraft();
          submitFilters(false, { includeSearch: false }).catch(() => {});
        }
      });
    });
  });

  if (btnApply) btnApply.addEventListener('click', () => {
    saveFiltersDraft();
    submitFilters(false, { includeSearch: true }).catch(() => {});
  });
  if (btnClear) btnClear.addEventListener('click', () => {
    if (nameInput) nameInput.value = '';
    Object.keys(selected).forEach((key) => {
      selected[key] = [];
    });
    Object.keys(selectedParams).forEach((key) => {
      selectedParams[key] = [];
    });
    if (inStockOnly) inStockOnly.checked = false;
    if (notInOzonOnly) notInOzonOnly.checked = false;
    if (notInOzonArchiveOnly) notInOzonArchiveOnly.checked = false;
    if (notInWbOnly) notInWbOnly.checked = false;
    if (hasPictureOnly) hasPictureOnly.checked = false;
    if (notBulkyOzonOnly) notBulkyOzonOnly.checked = false;
    if (ozonMarkingErrorOnly) ozonMarkingErrorOnly.checked = false;
    if (selectedOnly) selectedOnly.checked = false;
    readinessInputs.forEach((input) => { setReadinessInputState(input, ''); });
    readinessModeInputs.forEach((input) => { input.checked = String(input.value || '') === 'missing'; });
    if (priceMinInput) priceMinInput.value = '';
    if (priceMaxInput) priceMaxInput.value = '';
    if (stockMinInput) stockMinInput.value = '';
    if (stockMaxInput) stockMaxInput.value = '';
    analyticsRangeDefs.forEach((def) => {
      const bound = analyticsRangeDefaultMax(def);
      def.min.value = '0';
      def.max.value = String(bound);
      syncAnalyticsRange(def);
    });
    clearFiltersDraft();
    updatePills();
    updateActiveFilterChrome();
    submitFilters(true).catch(() => {});
  });

  document.addEventListener('ft-dropdown-panel-closed', (event) => {
    flushDeferredDropdownAutoApply(event.detail ? event.detail.panel : null);
  });
  document.addEventListener('click', () => window.ftCloseDropdownPanels());

  updatePills();
  updateActiveFilterChrome();
  updateFilterStatusFromState();

  const savedDraft = readSavedFiltersDraft();
  const normalizedAppliedState = JSON.stringify(normalizeFilterState(appliedFiltersState));
  const syncGuard = readFiltersSyncGuard();

  if (syncGuard && syncGuard === normalizedAppliedState) {
    clearFiltersSyncGuard();
  }

  if (savedDraft && !filterStatesEqual(savedDraft, appliedFiltersState)) {
    if (syncGuard !== JSON.stringify(normalizeFilterState(savedDraft))) {
      writeFiltersSyncGuard(savedDraft);
    }
    saveFiltersDraft();
    submitFilters(false, { includeSearch: true }).catch(() => {});
    return;
  }
})();
