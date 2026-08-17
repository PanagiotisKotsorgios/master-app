(function () {
  function byId(id) { return document.getElementById(id); }
  function openModal(id) { var el = byId(id); if (el) el.classList.add('open'); }
  function closeModal(id) { var el = byId(id); if (el) el.classList.remove('open'); }
  function setText(id, value) {
    var el = byId(id);
    if (!el) return;
    el.textContent = (value !== undefined && value !== null && value !== '') ? value : '—';
  }
  function setValue(id, value) {
    var el = byId(id);
    if (!el) return;
    el.value = (value !== undefined && value !== null) ? value : '';
  }
  function rowDataFromElement(el) {
    // Legacy: kept for compatibility. Data is now read directly from button.dataset.
    var row = el.closest('[data-school-row]');
    return row ? row.dataset : null;
  }

  window.openModal = openModal;
  window.closeModal = closeModal;

  document.addEventListener('click', function (e) {
    var openBtn = e.target.closest('[data-emp-open]');
    if (openBtn) {
      e.preventDefault();
      openModal(openBtn.getAttribute('data-emp-open'));
      return;
    }

    var closeBtn = e.target.closest('[data-emp-close]');
    if (closeBtn) {
      e.preventDefault();
      closeModal(closeBtn.getAttribute('data-emp-close'));
      return;
    }

    var bg = e.target.closest('.emp-modal-bg');
    if (bg && e.target === bg) {
      bg.classList.remove('open');
      return;
    }

    var sidebarToggle = e.target.closest('[data-emp-sidebar-toggle]');
    if (sidebarToggle) {
      e.preventDefault();
      var sidebar = byId('empSidebar');
      if (sidebar) sidebar.classList.toggle('open');
      return;
    }

    var actionBtn = e.target.closest('[data-emp-action]');
    if (actionBtn) {
      e.preventDefault();
      var action = actionBtn.getAttribute('data-emp-action');
      // Read data directly from the button's own dataset — reliable, no DOM traversal needed
      var d = actionBtn.dataset;

      if (action === 'preview') {
        setText('pvTitle', d.name);
        setText('pvEmail', d.email);
        setText('pvPhone', d.phone);
        setText('pvCity', d.city);
        setText('pvAfm', d.afm);
        setText('pvAddress', d.address);
        setText('pvPlan', d.planName);
        setText('pvPlanStatus', d.planStatus);
        setText('pvSubStatus', d.subscriptionStatus);
        setText('pvTrial', d.trial);
        setText('pvUsers', d.userCount);
        setText('pvAthletes', d.athleteCount);
        var pvEdit = byId('pvEditBtn');
        if (pvEdit) {
          pvEdit.setAttribute('data-id', d.id || '');
          pvEdit.setAttribute('data-name', d.name || '');
          pvEdit.setAttribute('data-email', d.email || '');
          pvEdit.setAttribute('data-phone', d.phone || '');
          pvEdit.setAttribute('data-city', d.city || '');
          pvEdit.setAttribute('data-address', d.address || '');
          pvEdit.setAttribute('data-afm', d.afm || '');
        }
        openModal('modalPreview');
        return;
      }

      if (action === 'edit') {
        setValue('editId', d.id);
        setValue('editName', d.name);
        setValue('editEmail', d.email);
        setValue('editPhone', d.phone);
        setValue('editCity', d.city);
        setValue('editAddress', d.address);
        setValue('editAfm', d.afm);
        openModal('modalEdit');
        return;
      }

      if (action === 'delete') {
        setValue('deleteId', d.id);
        setText('deleteName', d.name);
        openModal('modalDelete');
      }
      return;
    }

    var pvEditBtn = e.target.closest('[data-emp-preview-edit]');
    if (pvEditBtn) {
      e.preventDefault();
      closeModal('modalPreview');
      setValue('editId', pvEditBtn.getAttribute('data-id'));
      setValue('editName', pvEditBtn.getAttribute('data-name'));
      setValue('editEmail', pvEditBtn.getAttribute('data-email'));
      setValue('editPhone', pvEditBtn.getAttribute('data-phone'));
      setValue('editCity', pvEditBtn.getAttribute('data-city'));
      setValue('editAddress', pvEditBtn.getAttribute('data-address'));
      setValue('editAfm', pvEditBtn.getAttribute('data-afm'));
      openModal('modalEdit');
      return;
    }

    var outsideSidebar = byId('empSidebar');
    var burger = byId('empHamburger');
    if (outsideSidebar && window.innerWidth <= 920 && outsideSidebar.classList.contains('open')) {
      if (!outsideSidebar.contains(e.target) && !(burger && burger.contains(e.target))) {
        outsideSidebar.classList.remove('open');
      }
    }
  });
})();