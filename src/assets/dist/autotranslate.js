(function() {
  if (!window.Craft || !window.PragmaticTranslations) {
    return;
  }

  const config = window.PragmaticTranslations;

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
    if (fieldEl.querySelector('.ck-editor')) return true;
    if (fieldEl.querySelector('textarea[name^="fields["]')) return true;
    if (fieldEl.querySelector('input[type="text"][name^="fields["]')) return true;
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

  function openAutotranslateModal(fieldEl, menuEl) {
    const currentSiteId = config.currentSiteId;
    const sites = config.sites.filter(site => site.id !== currentSiteId);
    if (!sites.length) {
      Craft.cp.displayError('No other sites available.');
      return;
    }

    const modal = document.createElement('div');
    modal.className = 'modal fitted';
    modal.innerHTML = `
      <div class="body">
        <h2>Autotranslate from</h2>
        <div class="field">
          <div class="select">
            <select id="pt-source-site">
              ${sites.map(site => `<option value="${site.id}">${site.name} (${site.language})</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="buttons" style="margin-top:12px;">
          <button class="btn" type="button" id="pt-cancel">Cancel</button>
          <button class="btn submit" type="button" id="pt-confirm">Translate</button>
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
        Craft.cp.displayError('Unable to resolve entry or field handle.');
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
          Craft.cp.displayNotice('Translated.');
        } else {
          Craft.cp.displayError(response.data && response.data.error ? response.data.error : 'Translation failed.');
        }
      }).catch((error) => {
        const message = error.response && error.response.data && error.response.data.error ? error.response.data.error : 'Translation failed.';
        Craft.cp.displayError(message);
      }).finally(() => {
        garnModal.hide();
      });
    });
  }

  function ensureMenuItem(fieldEl, menuEl) {
    if (menuEl.querySelector('.pt-autotranslate')) return;
    const li = document.createElement('li');
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'menu-item pt-autotranslate';
    button.innerHTML = '<span class=\"menu-item-label inline-flex flex-col items-start gap-2xs\">Autotranslate from...</span>';
    button.addEventListener('click', function(ev) {
      ev.preventDefault();
      openAutotranslateModal(fieldEl, menuEl);
    });
    li.appendChild(button);

    const list = menuEl.querySelector('ul:first-of-type') || menuEl.querySelector('ul') || menuEl;
    list.appendChild(li);
  }

  function findMenuForButton(btn, fieldEl) {
    const menuId = btn.getAttribute('aria-controls');
    if (menuId) {
      const byId = document.getElementById(menuId);
      if (byId) return byId;
    }

    const localMenu = fieldEl.querySelector('.menu');
    if (localMenu) return localMenu;

    return document.querySelector('.menu.menu--active, .menu[aria-hidden=\"false\"]');
  }

  function waitForMenu(btn, fieldEl, attempts) {
    const menuEl = findMenuForButton(btn, fieldEl);
    if (menuEl) return menuEl;
    if (attempts <= 0) return null;
    return new Promise((resolve) => {
      setTimeout(function() {
        resolve(waitForMenu(btn, fieldEl, attempts - 1));
      }, 50);
    });
  }

  function addInlineButton(fieldEl) {
    if (!fieldEl || fieldEl.getAttribute('data-pt-autotranslate') === '1') return;
    if (!isEligibleField(fieldEl)) return;

    const heading = fieldEl.querySelector('.heading');
    if (!heading) return;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn small';
    button.textContent = 'Autotranslate from...';
    button.style.marginRight = '8px';

    button.addEventListener('click', function(ev) {
      ev.preventDefault();
      openAutotranslateModal(fieldEl);
    });

    const menuBtn = heading.querySelector('.menubtn');
    if (menuBtn && menuBtn.parentElement) {
      menuBtn.parentElement.insertBefore(button, menuBtn);
    } else {
      heading.appendChild(button);
    }

    fieldEl.setAttribute('data-pt-autotranslate', '1');
  }

  function scanFields() {
    document.querySelectorAll('.field').forEach(addInlineButton);
  }

  scanFields();

  const observer = new MutationObserver(function(mutations) {
    for (const mutation of mutations) {
      mutation.addedNodes.forEach(function(node) {
        if (!(node instanceof Element)) return;
        if (node.classList.contains('field')) {
          addInlineButton(node);
          return;
        }
        node.querySelectorAll && node.querySelectorAll('.field').forEach(addInlineButton);
      });
    }
  });

  observer.observe(document.body, { childList: true, subtree: true });
})();
