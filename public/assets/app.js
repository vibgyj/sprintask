(() => {
  const stored = localStorage.getItem('theme');
  if (stored && stored !== 'system') {
    document.documentElement.setAttribute('data-theme', stored);
  }

  const timer = document.querySelector('[data-running-since]');
  if (timer) {
    const started = parseInt(timer.getAttribute('data-running-since') || '0', 10);
    const baselineSeconds = parseInt(timer.getAttribute('data-elapsed-baseline') || '0', 10);
    const output = timer.querySelector('[data-elapsed]');
    if (output && started > 0) {
      const localTickOriginMs = Date.now();
      const tick = () => {
        const localElapsedSeconds = Math.max(0, Math.floor((Date.now() - localTickOriginMs) / 1000));
        const seconds = Math.max(0, baselineSeconds) + localElapsedSeconds;
        const h = Math.floor(seconds / 3600).toString().padStart(2, '0');
        const m = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
        const s = (seconds % 60).toString().padStart(2, '0');
        output.textContent = `${h}:${m}:${s}`;
      };
      tick();
      setInterval(tick, 1000);
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
          tick();
        }
      });
    }
  }

  document.querySelectorAll('[data-theme-select]').forEach((el) => {
    el.addEventListener('change', () => {
      const value = el.value;
      if (value === 'system') {
        localStorage.removeItem('theme');
        document.documentElement.removeAttribute('data-theme');
      } else {
        localStorage.setItem('theme', value);
        document.documentElement.setAttribute('data-theme', value);
      }
    });
  });

  initTimerComposer();
  initRecentEntryDisclosure();
  initAutoSubmit();
  initConfirmForms();
  initSettingsGrid();

  // Filters/selects that apply immediately. When the report range changes and
  // the start picker still holds the server default, drop it so the server
  // picks the default start for the new range.
  function initAutoSubmit() {
    document.querySelectorAll('[data-autosubmit]').forEach((input) => {
      input.addEventListener('change', () => {
        const form = input.form;
        if (!form) return;
        if (input.hasAttribute('data-range-input')) {
          const start = form.querySelector('[data-default-start]');
          if (start instanceof HTMLInputElement && start.value === start.defaultValue) {
            start.disabled = true;
          }
        }
        form.requestSubmit ? form.requestSubmit() : form.submit();
      });
    });
  }

  function initConfirmForms() {
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
      form.addEventListener('submit', (event) => {
        if (!window.confirm(form.getAttribute('data-confirm') || 'Are you sure?')) {
          event.preventDefault();
        }
      });
    });
  }

  // Settings grid: inline row edit. Category renames preview on every row of
  // that category; Cancel restores the original values.
  function initSettingsGrid() {
    const rows = Array.from(document.querySelectorAll('[data-grid-row]'));
    if (!rows.length) return;

    let editingRow = null;

    const setLabels = (categoryId, text) => {
      document.querySelectorAll(`[data-category-label="${categoryId}"]`).forEach((label) => {
        label.textContent = text;
      });
    };

    const setMode = (row, editing) => {
      row.classList.toggle('is-editing', editing);
      row.querySelectorAll('.grid-view').forEach((node) => { node.hidden = editing; });
      row.querySelectorAll('.grid-edit').forEach((node) => { node.hidden = !editing; });
    };

    const cancel = (row) => {
      row.querySelectorAll('input.grid-edit').forEach((input) => { input.value = input.defaultValue; });
      const categoryInput = row.querySelector('[data-category-input]');
      if (categoryInput) {
        setLabels(categoryInput.getAttribute('data-category-input'), categoryInput.defaultValue);
      }
      setMode(row, false);
      if (editingRow === row) editingRow = null;
    };

    rows.forEach((row) => {
      const editButton = row.querySelector('[data-grid-edit]');
      const cancelButton = row.querySelector('[data-grid-cancel]');
      const categoryInput = row.querySelector('[data-category-input]');

      editButton?.addEventListener('click', () => {
        if (editingRow && editingRow !== row) cancel(editingRow);
        editingRow = row;
        setMode(row, true);
        const first = row.querySelector('input.grid-edit');
        if (first instanceof HTMLInputElement) {
          first.focus();
          first.select();
        }
      });

      cancelButton?.addEventListener('click', () => {
        cancel(row);
        editButton?.focus();
      });

      categoryInput?.addEventListener('input', () => {
        setLabels(categoryInput.getAttribute('data-category-input'), categoryInput.value);
      });

      row.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && row.classList.contains('is-editing')) {
          cancel(row);
          editButton?.focus();
        }
      });
    });
  }

  function initTimerComposer() {
    const composer = document.querySelector('[data-timer-composer]');
    const sourceNode = document.querySelector('[data-timer-composer-source]');
    if (!composer || !sourceNode) {
      return;
    }

    let source = { categories: [], tasks: [] };
    try {
      source = JSON.parse(sourceNode.textContent || '{}');
    } catch (_error) {
      source = { categories: [], tasks: [] };
    }

    const categoryIdInput = composer.querySelector('[data-selected-category-id]');
    const taskIdInput = composer.querySelector('[data-selected-task-id]');
    const categoryInput = composer.querySelector('[data-combobox-input="category"]');
    const taskInput = composer.querySelector('[data-combobox-input="task"]');
    const categoryList = composer.querySelector('[data-combobox-list="category"]');
    const taskList = composer.querySelector('[data-combobox-list="task"]');

    const state = {
      category: { options: [], activeIndex: -1 },
      task: { options: [], activeIndex: -1 },
    };

    const closeList = (type) => {
      const input = type === 'category' ? categoryInput : taskInput;
      const list = type === 'category' ? categoryList : taskList;
      if (!input || !list) return;
      input.setAttribute('aria-expanded', 'false');
      input.setAttribute('aria-activedescendant', '');
      list.hidden = true;
      list.innerHTML = '';
      state[type].options = [];
      state[type].activeIndex = -1;
    };

    const closeAll = () => {
      closeList('category');
      closeList('task');
    };

    const renderList = (type, options) => {
      const input = type === 'category' ? categoryInput : taskInput;
      const list = type === 'category' ? categoryList : taskList;
      if (!input || !list) return;
      if (!options.length) {
        closeList(type);
        return;
      }

      state[type].options = options;
      state[type].activeIndex = 0;
      input.setAttribute('aria-expanded', 'true');
      list.hidden = false;

      list.innerHTML = options.map((option, index) => {
        const stableId = `${type}-${option.id}`;
        return `<li id="${type}-option-${stableId}" role="option" aria-selected="${index === 0 ? 'true' : 'false'}" data-index="${index}"><span>${escapeHtml(option.name)}</span></li>`;
      }).join('');

      syncActiveDescendant(type);
    };

    const syncActiveDescendant = (type) => {
      const input = type === 'category' ? categoryInput : taskInput;
      const list = type === 'category' ? categoryList : taskList;
      if (!input || !list) return;
      const { activeIndex } = state[type];
      const items = list.querySelectorAll('[role="option"]');
      items.forEach((item, index) => {
        item.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
      });
      if (activeIndex >= 0 && items[activeIndex]) {
        input.setAttribute('aria-activedescendant', items[activeIndex].id);
        items[activeIndex].scrollIntoView({ block: 'nearest' });
      } else {
        input.setAttribute('aria-activedescendant', '');
      }
    };

    const resolveExactCategory = () => {
      if (!categoryInput) return null;
      const typed = categoryInput.value.trim().toLowerCase();
      if (!typed) return null;
      const matches = (source.categories || []).filter((category) => String(category.name || '').trim().toLowerCase() === typed);
      if (matches.length !== 1) return null;
      return matches[0];
    };

    const applyExactCategorySelection = () => {
      const match = resolveExactCategory();
      const previousCategoryId = Number(categoryIdInput?.value || '0');
      if (match && categoryIdInput) {
        categoryIdInput.value = String(match.id);
      } else if (categoryIdInput) {
        categoryIdInput.value = '';
      }
      if (Number(categoryIdInput?.value || '0') !== previousCategoryId && taskIdInput) {
        taskIdInput.value = '';
      }
      return match;
    };

    const findTaskInResolvedCategoryByName = (resolvedCategoryId) => {
      if (!taskInput) return null;
      const typedTask = taskInput.value.trim().toLowerCase();
      if (!typedTask || resolvedCategoryId <= 0) return null;
      const matches = (source.tasks || []).filter((task) => Number(task.category_id) === Number(resolvedCategoryId) && String(task.name || '').trim().toLowerCase() === typedTask);
      if (matches.length !== 1) return null;
      return matches[0];
    };

    const revalidateSelectedIds = () => {
      const resolvedCategory = applyExactCategorySelection();
      const resolvedCategoryId = Number(resolvedCategory?.id || 0);

      if (taskIdInput && taskIdInput.value) {
        const selectedTask = (source.tasks || []).find((task) => Number(task.id) === Number(taskIdInput.value));
        const taskNameMatches = !!selectedTask && !!taskInput && selectedTask.name.trim().toLowerCase() === taskInput.value.trim().toLowerCase();
        const categoryMatches = !!selectedTask && resolvedCategoryId > 0 && Number(selectedTask.category_id) === resolvedCategoryId;
        if (!taskNameMatches || !categoryMatches) {
          taskIdInput.value = '';
        }
      }

      if (taskIdInput && !taskIdInput.value) {
        const exactTask = findTaskInResolvedCategoryByName(resolvedCategoryId);
        if (exactTask) {
          taskIdInput.value = String(exactTask.id);
        }
      }

      if (!resolvedCategoryId && taskIdInput) {
        taskIdInput.value = '';
      }
    };

    const selectCategory = (category) => {
      if (!categoryInput || !categoryIdInput) return;
      categoryInput.value = category.name;
      categoryIdInput.value = String(category.id);
      taskIdInput.value = '';
      closeList('category');
    };

    const selectTask = (task) => {
      if (!taskInput || !taskIdInput || !categoryIdInput || !categoryInput) return;
      taskInput.value = task.name;
      taskIdInput.value = String(task.id);
      categoryIdInput.value = String(task.category_id);
      categoryInput.value = String(task.category_name || '');
      closeList('task');
    };

    const filterCategories = (typed) => {
      const q = typed.trim().toLowerCase();
      const options = (source.categories || []).filter((category) => {
        if (!q) return true;
        return String(category.name || '').toLowerCase().includes(q);
      });
      renderList('category', options.slice(0, 10));
    };

    const filterTasks = (typed) => {
      const resolvedCategory = resolveExactCategory();
      if (!resolvedCategory) {
        closeList('task');
        return;
      }
      const q = typed.trim().toLowerCase();
      const options = (source.tasks || [])
        .filter((task) => Number(task.category_id) === Number(resolvedCategory.id))
        .filter((task) => (q ? String(task.name || '').toLowerCase().includes(q) : true));
      renderList('task', options.slice(0, 10));
    };

    const moveActive = (type, step) => {
      const options = state[type].options;
      if (!options.length) return;
      const next = state[type].activeIndex + step;
      if (next < 0) {
        state[type].activeIndex = options.length - 1;
      } else if (next >= options.length) {
        state[type].activeIndex = 0;
      } else {
        state[type].activeIndex = next;
      }
      syncActiveDescendant(type);
    };

    const selectActive = (type) => {
      const option = state[type].options[state[type].activeIndex];
      if (!option) return false;
      if (type === 'category') {
        selectCategory(option);
        taskInput?.focus();
      } else {
        selectTask(option);
      }
      return true;
    };

    const bindKeyboard = (type, input) => {
      input?.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          if (!state[type].options.length) {
            if (type === 'category') filterCategories(input.value);
            if (type === 'task') filterTasks(input.value);
          } else {
            moveActive(type, 1);
          }
          return;
        }
        if (event.key === 'ArrowUp') {
          event.preventDefault();
          if (state[type].options.length) {
            moveActive(type, -1);
          }
          return;
        }
        if (event.key === 'Enter' && state[type].options.length) {
          event.preventDefault();
          selectActive(type);
          return;
        }
        if (event.key === 'Escape') {
          closeList(type);
          return;
        }
        if (event.key === 'Tab') {
          if (state[type].options.length) {
            selectActive(type);
          }
          revalidateSelectedIds();
          closeList(type);
        }
      });
    };

    const bindClick = (type, list) => {
      list?.addEventListener('mousedown', (event) => {
        event.preventDefault();
        const optionNode = event.target.closest('[role="option"]');
        if (!optionNode) return;
        const index = Number(optionNode.getAttribute('data-index') || '-1');
        if (index < 0 || index >= state[type].options.length) return;
        state[type].activeIndex = index;
        selectActive(type);
      });
    };

    categoryInput?.addEventListener('input', () => {
      applyExactCategorySelection();
      taskIdInput.value = '';
      filterCategories(categoryInput.value);
    });

    taskInput?.addEventListener('input', () => {
      taskIdInput.value = '';
      filterTasks(taskInput.value);
    });

    categoryInput?.addEventListener('focus', () => {
      filterCategories(categoryInput.value);
    });

    categoryInput?.addEventListener('blur', () => {
      revalidateSelectedIds();
      closeList('category');
    });

    taskInput?.addEventListener('focus', () => {
      revalidateSelectedIds();
      filterTasks(taskInput.value);
    });

    taskInput?.addEventListener('blur', () => {
      revalidateSelectedIds();
      closeList('task');
    });

    bindKeyboard('category', categoryInput);
    bindKeyboard('task', taskInput);
    bindClick('category', categoryList);
    bindClick('task', taskList);

    document.addEventListener('click', (event) => {
      if (!composer.contains(event.target)) {
        revalidateSelectedIds();
        closeAll();
      }
    });

    composer.addEventListener('submit', () => {
      revalidateSelectedIds();
      closeAll();
    });
  }

  function initRecentEntryDisclosure() {
    const toggles = Array.from(document.querySelectorAll('[data-entry-edit-toggle]'));
    if (!toggles.length) {
      return;
    }

    let openId = null;

    const closeEntry = (entryId, restoreFocus = false) => {
      const button = toggles.find((node) => String(node.getAttribute('data-entry-id')) === String(entryId));
      const detailRow = document.getElementById(`entry-detail-${entryId}`);
      if (!button || !detailRow) return;
      button.setAttribute('aria-expanded', 'false');
      detailRow.hidden = true;
      if (restoreFocus) {
        button.focus();
      }
      if (String(openId) === String(entryId)) {
        openId = null;
      }
    };

    const openEntry = (entryId) => {
      const button = toggles.find((node) => String(node.getAttribute('data-entry-id')) === String(entryId));
      const detailRow = document.getElementById(`entry-detail-${entryId}`);
      if (!button || !detailRow) return;
      button.setAttribute('aria-expanded', 'true');
      detailRow.hidden = false;
      openId = String(entryId);
      const firstFocusable = detailRow.querySelector('input, button, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (firstFocusable instanceof HTMLElement) {
        firstFocusable.focus();
      }
    };

    toggles.forEach((button) => {
      button.addEventListener('click', () => {
        const entryId = button.getAttribute('data-entry-id');
        if (!entryId) return;
        if (openId && openId !== entryId) {
          closeEntry(openId, false);
        }
        if (openId === entryId) {
          closeEntry(entryId, true);
          return;
        }
        openEntry(entryId);
      });
    });
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }
})();
