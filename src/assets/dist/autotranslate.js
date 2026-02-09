(function() {
  if (!window.Craft || !window.PragmaticTranslations) {
    return;
  }

  var config = window.PragmaticTranslations;

  function t(message) {
    return (window.Craft && typeof Craft.t === 'function')
      ? Craft.t('pragmatic-translations', message)
      : message;
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
    if (!fieldEl) return;

    var textarea = fieldEl.querySelector('textarea[name^="fields["]');
    if (textarea) {
      var editor = getCkeditorInstance(textarea);
      if (editor && typeof editor.setData === 'function') {
        editor.setData(value);
        return;
      }
      textarea.value = value;
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      textarea.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }

    var input = fieldEl.querySelector('input[type="text"][name^="fields["]');
    if (input) {
      input.value = value;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  config.openModal = function(fieldEl, entryId, fieldHandle) {
    var currentSiteId = config.currentSiteId;
    var sites = config.sites.filter(function(site) { return site.id !== currentSiteId; });
    if (!sites.length) {
      Craft.cp.displayError(t('No other sites available.'));
      return;
    }

    var modal = document.createElement('div');
    modal.className = 'modal fitted';
    modal.innerHTML =
      '<div class="body">' +
        '<h2>' + t('Translate from site\u2026') + '</h2>' +
        '<div class="field">' +
          '<div class="select">' +
            '<select id="pt-source-site">' +
              sites.map(function(site) {
                return '<option value="' + site.id + '">' + site.name + ' (' + site.language + ')</option>';
              }).join('') +
            '</select>' +
          '</div>' +
        '</div>' +
        '<div class="buttons" style="margin-top:12px;">' +
          '<button class="btn" type="button" id="pt-cancel">' + t('Cancel') + '</button>' +
          '<button class="btn submit" type="button" id="pt-confirm">' + t('Translate') + '</button>' +
        '</div>' +
      '</div>';

    var garnModal = new Garnish.Modal(modal, {
      autoShow: true,
      closeOtherModals: true,
      onHide: function() {
        garnModal.destroy();
      }
    });

    modal.querySelector('#pt-cancel').addEventListener('click', function() {
      garnModal.hide();
    });

    modal.querySelector('#pt-confirm').addEventListener('click', function() {
      var sourceSiteId = parseInt(modal.querySelector('#pt-source-site').value, 10);

      Craft.sendActionRequest('POST', config.autotranslateUrl, {
        data: {
          entryId: entryId,
          fieldHandle: fieldHandle,
          sourceSiteId: sourceSiteId,
          targetSiteId: currentSiteId
        }
      }).then(function(response) {
        if (response.data && response.data.success) {
          setFieldValue(fieldEl, response.data.text || '');
          Craft.cp.displayNotice(t('Translated.'));
        } else {
          Craft.cp.displayError(
            response.data && response.data.error
              ? response.data.error
              : t('Translation failed.')
          );
        }
      }).catch(function(error) {
        var message = error.response && error.response.data && error.response.data.error
          ? error.response.data.error
          : t('Translation failed.');
        Craft.cp.displayError(message);
      }).finally(function() {
        garnModal.hide();
      });
    });
  };
})();
