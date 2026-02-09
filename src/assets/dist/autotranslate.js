(function() {
  if (!window.Craft || !window.PragmaticTranslations) {
    return;
  }

  const config = window.PragmaticTranslations;

  function t(message) {
    return (window.Craft && typeof Craft.t === 'function')
      ? Craft.t('pragmatic-translations', message)
      : message;
  }

  function getEntryId(fieldEl) {
    const form = fieldEl.closest('form');
    if (!form) return null;
    const input = form.querySelector('input[name="entryId"], input[name="elementId"], input[name="id"]');
    return input ? parseInt(input.value, 10) : null;
  }

  function getFieldHandle(fieldEl) {
    const input = fieldEl.querySelector('input[name^="fields["]');
    const textarea = fieldEl.querySelector('textarea[name^="fields["]');
    const el = input || textarea;
    if (!el) return null;
    const match = el.name.match(/^fields\[([^\]]+)\]/);
    return match ? match[1] : null;
  }

  function isEligibleField(fieldEl) {
    const type = fieldEl.getAttribute('data-type') || '';
    if (type.indexOf('craft\\fields\\PlainText') !== -1) return true;
    if (type.indexOf('craft\\ckeditor\\Field') !== -1) return true;
    return false;
  }

  function isTranslatableField(fieldEl) {
    if (fieldEl.getAttribute('data-translatable') === '1' || fieldEl.getAttribute('data-translatable') === 'true') {
      return true;
    }
    if (fieldEl.querySelector('.t9n-indicator')) {
      return true;
    }
    return false;
  }

  function getCkeditorInstance(textarea) {
    if (!textarea) return null;
    if (window.Craft && Craft.CKEditor) {
      if (Craft.CKEditor.instances && Craft.CKEditor.instances[textarea.id]) {
        return Craft.CKEditor.instances[textarea.id];
      }
      if (typeof Craft.CKEditor.getInstanceById === 'function') {
        return Craft.CKEditor.getInstanceById(textarea.id);
      }
    }
    if (textarea.ckeditorInstance) return textarea.ckeditorInstance;
    if (window.CKEDITOR && CKEDITOR.instances && CKEDITOR.instances[textarea.id]) {
      return CKEDITOR.instances[textarea.id];
    }
    return null;
  }

  function setFieldValue(fieldEl, value) {
    const textarea = fieldEl.querySelector('textarea[name^="fields["]');
    if (textarea) {
      const editor = getCkeditorInstance(textarea);
      if (editor && typeof editor.setData === 'function') {
        editor.setData(value);
        return;
      }
      textarea.value = value;
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      textarea.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }

    const input = fieldEl.querySelector('input[type="text"][name^="fields["]');
    if (input) {
      input.value = value;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function openAutotranslateModal(fieldEl) {
    const currentSiteId = config.currentSiteId;
    const sites = config.sites.filter(site => site.id !== currentSiteId);
    if (!sites.length) {
      Craft.cp.displayError(t('No other sites available.'));
      return;
    }

    const modal = document.createElement('div');
    modal.className = 'modal fitted';
    modal.innerHTML = `
      <div class="body">
        <h2>${t('Translate from site…')}</h2>
        <div class="field">
          <div class="select">
            <select id="pt-source-site">
              ${sites.map(site => `<option value="${site.id}">${site.name} (${site.language})</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="buttons" style="margin-top:12px;">
          <button class="btn" type="button" id="pt-cancel">${t('Cancel')}</button>
          <button class="btn submit" type="button" id="pt-confirm">${t('Translate')}</button>
        </div>
      </div>
    `;

    const garnModal = new Garnish.Modal(modal, {
      autoShow: true,
      closeOtherModals: true,
      onHide() {
        garnModal.destroy();
      }
    });

    modal.querySelector('#pt-cancel').addEventListener('click', function() {
      garnModal.hide();
    });

    modal.querySelector('#pt-confirm').addEventListener('click', function() {
      const sourceSiteId = parseInt(modal.querySelector('#pt-source-site').value, 10);
      const entryId = getEntryId(fieldEl);
      const fieldHandle = getFieldHandle(fieldEl);
      const targetSiteId = currentSiteId;

      if (!entryId || !fieldHandle) {
        Craft.cp.displayError(t('Unable to resolve entry or field handle.'));
        garnModal.hide();
        return;
      }

      Craft.sendActionRequest('POST', config.autotranslateUrl, {
        data: {
          entryId,
          fieldHandle,
          sourceSiteId,
          targetSiteId
        }
      }).then((response) => {
        if (response.data && response.data.success) {
          setFieldValue(fieldEl, response.data.text || '');
          Craft.cp.displayNotice(t('Translated.'));
        } else {
          Craft.cp.displayError(response.data && response.data.error ? response.data.error : t('Translation failed.'));
        }
      }).catch((error) => {
        const message = error.response && error.response.data && error.response.data.error ? error.response.data.error : t('Translation failed.');
        Craft.cp.displayError(message);
      }).finally(() => {
        garnModal.hide();
      });
    });
  }

  function addMenuOption(fieldEl) {
    if (!fieldEl || fieldEl.getAttribute('data-pt-autotranslate') === '1') return;
    if (!isEligibleField(fieldEl)) return;
    if (!isTranslatableField(fieldEl)) return;

    const menuBtnEl = fieldEl.querySelector('.menubtn');
    if (!menuBtnEl || !window.Garnish || !window.Garnish.$) return;

    const getMenuBtn = function() {
      const $btn = window.Garnish.$(menuBtnEl);
      return $btn.data('menubtn') || $btn.data('menuBtn') || menuBtnEl.menuBtn || menuBtnEl._menuBtn;
    };

    const tryAdd = function() {
      const menuBtn = getMenuBtn();
      if (!menuBtn || !menuBtn.menu || typeof menuBtn.menu.addOptions !== 'function') return false;
      const label = t('Translate from site…');
      menuBtn.menu.addOptions([{ label, onClick: function() { openAutotranslateModal(fieldEl); } }]);
      fieldEl.setAttribute('data-pt-autotranslate', '1');
      return true;
    };

    if (tryAdd()) return;

    menuBtnEl.addEventListener('click', function() {
      let attempts = 6;
      const poll = function() {
        if (tryAdd()) return;
        attempts -= 1;
        if (attempts <= 0) return;
        setTimeout(poll, 50);
      };
      poll();
    }, { once: true });
  }

  function scanFields() {
    document.querySelectorAll('.field').forEach(addMenuOption);
  }

  if (window.Garnish && Garnish.$doc) {
    Garnish.$doc.ready(function() {
      scanFields();
    });
  } else {
    scanFields();
  }

  const observer = new MutationObserver(function(mutations) {
    for (const mutation of mutations) {
      mutation.addedNodes.forEach(function(node) {
        if (!(node instanceof Element)) return;
        if (node.classList.contains('field')) {
          addMenuOption(node);
          return;
        }
        node.querySelectorAll && node.querySelectorAll('.field').forEach(addMenuOption);
      });
    }
  });

  observer.observe(document.body, { childList: true, subtree: true });
})();
