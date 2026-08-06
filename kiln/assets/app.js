(function () {
  'use strict';

  /* ---------- planner form ---------- */

  var planner = document.getElementById('planner');
  if (planner) {
    /**
     * Work the plan out again without reloading the page.
     *
     * Submitting the form would take the focus out of whatever you are using —
     * intolerable on the date field, where every change would throw you out of
     * the picker. So fetch the same URL, and swap in only the parts the server
     * recalculated: the results, the booking window, and any time fields you
     * have not typed into yourself.
     */
    function replan() {
      var params = new URLSearchParams(new FormData(planner)).toString();
      var url = 'index.php?' + params;

      var results = document.getElementById('plan-results');
      if (results) { results.classList.add('replanning'); }

      fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
        .then(function (response) { return response.text(); })
        .then(function (html) {
          var fresh = new DOMParser().parseFromString(html, 'text/html');

          var incoming = fresh.getElementById('plan-results');
          if (incoming && results) { results.innerHTML = incoming.innerHTML; }

          // Hints inside the form whose wording the server derives from the
          // choices — the cone advice, the kiln blurb, the booking window.
          // Marked with data-refresh so adding one needs no change here.
          document.querySelectorAll('[data-refresh]').forEach(function (here) {
            var there = here.id && fresh.getElementById(here.id);
            if (there) { here.innerHTML = there.innerHTML; }
          });

          // Times the server worked out for us, unless they are yours.
          ['start', 'finish'].forEach(function (name) {
            var field = document.getElementById(name);
            var updated = fresh.getElementById(name);
            if (field && updated && !field.dataset.touched) { field.value = updated.value; }
          });

          // Keep the address bar in step, so a refresh shows the same plan.
          if (window.history && history.replaceState) { history.replaceState(null, '', url); }
        })
        .catch(function () {
          // Fall back to the plain submit rather than leaving a stale plan.
          planner.submit();
        })
        .then(function () {
          if (results) { results.classList.remove('replanning'); }
        });
    }

    // Every field below re-plans on its own, so the submit button has nothing
    // left to do here. It stays in the markup for browsers without JavaScript,
    // and the catch() above still falls back to submitting the form.
    planner.querySelectorAll('[data-submit-fallback]').forEach(function (button) {
      button.hidden = true;
    });

    // Changing the booking day moves every other time with it.
    planner.querySelectorAll('[data-replan]').forEach(function (input) {
      input.addEventListener('change', function () {
        // Start and finish were derived from the old day; clear them so the
        // server works them out afresh, unless they were typed in.
        ['start', 'finish'].forEach(function (name) {
          var field = document.getElementById(name);
          if (field && !field.dataset.touched) { field.value = ''; }
        });
        replan();
      });
    });

    ['start', 'finish'].forEach(function (name) {
      var field = document.getElementById(name);
      if (field) {
        field.addEventListener('input', function () { field.dataset.touched = '1'; });
        field.addEventListener('change', replan);
      }
    });

    planner.querySelectorAll('input[name="kiln"]').forEach(function (input) {
      input.addEventListener('change', replan);
    });

    planner.querySelectorAll('input[name="program"]').forEach(function (input) {
      input.addEventListener('change', function () {
        // A new program brings its own default length and cone.
        var hours = document.getElementById('hours');
        var cone = document.getElementById('cone');
        if (hours && input.dataset.hours) { hours.value = input.dataset.hours; }
        if (cone && input.dataset.cone) { cone.value = input.dataset.cone; }
        replan();
      });
    });

    planner.querySelectorAll('#cone, #hours, #hold, #overhead, #fired_by, #fired_by_name')
      .forEach(function (field) {
        field.addEventListener('change', replan);
      });

    // These links sit inside the results, which get replaced — so listen on the
    // document rather than on the links themselves.
    document.addEventListener('click', function (event) {
      var hoursLink = event.target.closest && event.target.closest('[data-set-hours]');
      if (hoursLink) {
        event.preventDefault();
        var hours = document.getElementById('hours');
        if (hours) { hours.value = hoursLink.dataset.setHours; replan(); }
        return;
      }
      var finishLink = event.target.closest && event.target.closest('[data-set-finish]');
      if (finishLink) {
        event.preventDefault();
        var finish = document.getElementById('finish');
        if (finish) {
          finish.value = finishLink.dataset.setFinish;
          finish.dataset.touched = '1';   // it is a choice now, not a default
          replan();
        }
      }
    });
  }

  function stamp(date) {
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate()) +
      'T' + pad(date.getHours()) + ':' + pad(date.getMinutes());
  }

  document.querySelectorAll('[data-set-now]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      event.preventDefault();
      var field = document.getElementById(link.dataset.setNow);
      if (!field) { return; }
      var d = new Date();
      d.setSeconds(0, 0);
      field.value = stamp(d);
    });
  });

  /* ---------- controller walkthrough ---------- */

  var stepsEl = document.getElementById('steps-data');
  if (stepsEl) {
    var steps = JSON.parse(stepsEl.textContent || '[]');
    var led = document.getElementById('led');
    // 14-segment starburst. Must match led_segments() in lib/led.php.
    var SEGMENT_BITS = {
      a: 1, b: 2, c: 4, d: 8, e: 16, f: 32, g1: 64, g2: 128,
      h: 256, j: 512, k: 1024, l: 2048, m: 4096, n: 8192, dp: 16384
    };

    // Re-light the SVG readout: every segment knows its digit and its letter,
    // so a bitmask per digit is all it takes.
    function setDisplay(masks) {
      if (!led || !masks) { return; }
      led.querySelectorAll('.led-seg').forEach(function (seg) {
        var name = seg.getAttribute('data-seg');
        // A segment this script does not know about is left exactly as the
        // server drew it. Better a stale glyph than a blank one if the two
        // segment tables ever drift apart.
        if (!Object.prototype.hasOwnProperty.call(SEGMENT_BITS, name)) { return; }
        var digit = parseInt(seg.getAttribute('data-digit'), 10);
        var mask = masks[digit] || 0;
        seg.classList.toggle('on', (mask & SEGMENT_BITS[name]) !== 0);
      });
    }
    var title = document.getElementById('step-title');
    var detail = document.getElementById('step-detail');
    var count = document.getElementById('step-count');
    var prev = document.getElementById('step-prev');
    var next = document.getElementById('step-next');
    var checksEl = document.getElementById('step-checks');
    var index = 0;
    var timers = [];
    // Ticks are remembered per step while the page is open, so stepping back
    // and forth does not lose what you have already confirmed.
    var stepChecks = {};

    function clearKeys() {
      timers.forEach(clearTimeout);
      timers = [];
      document.querySelectorAll('.key.lit').forEach(function (key) {
        key.classList.remove('lit');
      });
    }

    function show(i) {
      if (!steps.length) { return; }
      index = Math.max(0, Math.min(steps.length - 1, i));
      var step = steps[index];
      clearKeys();
      setDisplay(step.masks);
      title.textContent = step.title;
      detail.textContent = step.detail;
      count.textContent = 'Step ' + (index + 1) + ' of ' + steps.length;
      prev.disabled = index === 0;
      next.textContent = index === steps.length - 1 ? 'Start over' : 'Next';

      if (checksEl) {
        var checks = step.checks || [];
        checksEl.innerHTML = '';
        checksEl.hidden = checks.length === 0;
        stepChecks[index] = stepChecks[index] || {};

        checks.forEach(function (text, n) {
          var item = document.createElement('li');
          var label = document.createElement('label');
          label.className = 'check';

          var box = document.createElement('input');
          box.type = 'checkbox';
          box.checked = !!stepChecks[index][n];
          box.addEventListener('change', function () { stepChecks[index][n] = box.checked; });

          var mark = document.createElement('span');
          mark.className = 'box';

          var caption = document.createElement('span');
          caption.className = 'check-text';
          caption.textContent = text;

          label.appendChild(box);
          label.appendChild(mark);
          label.appendChild(caption);
          item.appendChild(label);
          checksEl.appendChild(item);
        });
      }

      (step.keys || []).forEach(function (id, n) {
        timers.push(setTimeout(function () {
          var key = document.querySelector('.key[data-key="' + id + '"]');
          if (key) { key.classList.add('lit'); }
        }, n * 280));
      });
    }

    prev.addEventListener('click', function () { show(index - 1); });
    next.addEventListener('click', function () {
      show(index === steps.length - 1 ? 0 : index + 1);
    });

    show(0);
  }

  /* ---------- autosave ---------- */

  // Everything in the firing record saves itself: immediately for anything you
  // click, and on a timer for anything you type. The Save button is only there
  // for browsers without JavaScript, so it gets hidden below.
  var TYPING_DELAY = 1000;

  var statusEl = document.getElementById('save-status');
  var recordForm = document.getElementById('record-form');
  var recordId = recordForm ? recordForm.dataset.firing : null;
  var pendingSaves = {};
  var lastError = '';

  function setStatus(state, message) {
    if (!statusEl) { return; }
    statusEl.hidden = false;
    statusEl.className = 'save-status save-status--' + state;
    statusEl.textContent = message;
    statusEl.title = state === 'error' ? message : '';
  }

  function saveField(name, value, extra) {
    var fields = { action: 'save_field', id: recordId, name: name, value: value };
    Object.keys(extra || {}).forEach(function (key) { fields[key] = extra[key]; });

    setStatus('saving', 'Saving');
    return apiPost(fields).then(function (data) {
      lastError = '';
      setStatus('saved', 'Saved ' + (data.at || ''));
    }).catch(function (error) {
      lastError = error.message;
      setStatus('error', 'NOT SAVED — ' + error.message);
    });
  }

  // Typing waits for a pause; anything else goes straight out.
  function queueField(name, value, extra) {
    if (pendingSaves[name]) { clearTimeout(pendingSaves[name].timer); }
    pendingSaves[name] = {
      value: value,
      extra: extra,
      timer: setTimeout(function () {
        delete pendingSaves[name];
        saveField(name, value, extra);
      }, TYPING_DELAY)
    };
    setStatus('saving', 'Unsaved changes');
  }

  function flushField(name) {
    var pending = pendingSaves[name];
    if (!pending) { return; }
    clearTimeout(pending.timer);
    delete pendingSaves[name];
    saveField(name, pending.value, pending.extra);
  }

  function flushAll(useBeacon) {
    Object.keys(pendingSaves).forEach(function (name) {
      var pending = pendingSaves[name];
      clearTimeout(pending.timer);
      delete pendingSaves[name];
      if (useBeacon && navigator.sendBeacon) {
        // The page is going away — a beacon still gets there.
        var body = new URLSearchParams();
        body.set('action', 'save_field');
        body.set('id', recordId);
        body.set('name', name);
        body.set('value', pending.value);
        Object.keys(pending.extra || {}).forEach(function (k) { body.set(k, pending.extra[k]); });
        navigator.sendBeacon('api.php', body);
      } else {
        saveField(name, pending.value, pending.extra);
      }
    });
  }

  if (recordForm && recordId) {
    var autosaveNote = document.getElementById('autosave-note');
    if (autosaveNote) { autosaveNote.hidden = false; }

    // There is no Save button any more, but a browser still submits a form when
    // you press Enter in a field. Treat that as "save what is pending, now".
    recordForm.addEventListener('submit', function (event) {
      event.preventDefault();
      flushAll(false);
    });

    var typed = 'input[type="text"], input[type="number"], textarea';
    recordForm.querySelectorAll(typed).forEach(function (field) {
      if (!field.name || field.name.indexOf('checklist') === 0) { return; }
      field.addEventListener('input', function () { queueField(field.name, field.value); });
      field.addEventListener('blur', function () { flushField(field.name); });
    });

    recordForm.querySelectorAll('select, input[type="datetime-local"]').forEach(function (field) {
      if (!field.name) { return; }
      field.addEventListener('change', function () { saveField(field.name, field.value); });
    });

    // Checkboxes that are not checklist items: vacuumed, manual stop.
    recordForm.querySelectorAll('input[type="checkbox"]:not([data-item])').forEach(function (box) {
      if (!box.name) { return; }
      box.addEventListener('change', function () {
        saveField(box.name, box.checked ? '1' : '0').then(function () {
          if (lastError) { box.checked = !box.checked; }
        });
      });
    });

    window.addEventListener('pagehide', function () { flushAll(true); });
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { flushAll(true); }
    });

    if (statusEl) {
      statusEl.addEventListener('click', function () {
        if (lastError) { alert(lastError); }
      });
    }
  }

  /* ---------- test message ---------- */

  // Send the test to whatever is in the box right now, not to whatever was
  // saved — otherwise typing a number and testing it does the wrong thing.
  var testPhone = document.getElementById('test-sms-phone');
  var phoneField = document.getElementById('phone');
  if (testPhone && phoneField) {
    var copyPhone = function () { testPhone.value = phoneField.value; };
    phoneField.addEventListener('input', copyPhone);
    copyPhone();
  }

  /* ---------- fired by ---------- */

  // "Someone else…" reveals a name box, for the many people in the old logs
  // who will never have an account here.
  document.querySelectorAll('[data-firer]').forEach(function (field) {
    var select = field.querySelector('[data-firer-select]');
    var name = field.querySelector('[data-firer-name]');
    if (!select || !name) { return; }

    var sync = function (focus) {
      var guest = select.value === '-1';
      name.hidden = !guest;
      if (!guest) { name.value = ''; }
      if (guest && focus) { name.focus(); }
    };

    select.addEventListener('change', function () { sync(true); });
  });

  /* ---------- witness cone pack ---------- */

  // Every bend is already in the SVG; stepping just changes which one shows.
  var conePack = document.querySelector('.cone-pack');
  if (conePack) {
    var coneLabels = JSON.parse(conePack.dataset.positions || '[]');
    var coneShort = JSON.parse(conePack.dataset.short || '[]');

    conePack.querySelectorAll('[data-cone-slot]').forEach(function (slot) {
      var input = slot.querySelector('input[type="hidden"]');
      var state = slot.querySelector('.cone-state');
      // The cones all live in one SVG now, so find this slot's bends by index.
      var bends = conePack.querySelectorAll('.cone-body[data-slot="' + slot.dataset.slot + '"]');

      var show = function (n) {
        n = Math.max(0, Math.min(coneLabels.length - 1, n));
        input.value = n;
        bends.forEach(function (bend) {
          bend.classList.toggle('on', parseInt(bend.getAttribute('data-pos'), 10) === n);
        });
        if (state) {
          state.textContent = coneShort[n] || coneLabels[n];
          state.title = coneLabels[n];
        }
      };

      slot.querySelectorAll('[data-cone-step]').forEach(function (button) {
        button.addEventListener('click', function () {
          show(parseInt(input.value, 10) + parseInt(button.dataset.coneStep, 10));
          if (recordId) {
            saveField('cone_pos', input.value, { index: slot.dataset.slot });
          }
        });
      });
    });
  }

  /* ---------- hourly temperatures ---------- */

  // Keep the new-reading time pinned to now, so logging a temperature is just
  // "type the number, press Log it". Stops the moment you set a time yourself.
  var tempAt = document.getElementById('temp_at');
  if (tempAt) {
    var pinNow = function () {
      if (!tempAt.dataset.touched) { tempAt.value = stamp(new Date()); }
    };
    tempAt.addEventListener('input', function () { tempAt.dataset.touched = '1'; });

    // A "now" decorator beside the picker's own calendar button.
    var nowButton = document.getElementById('temp-now');
    if (nowButton) {
      nowButton.addEventListener('click', function () {
        delete tempAt.dataset.touched;
        tempAt.value = stamp(new Date());
      });
    }
    var tempC = document.getElementById('temp_c');
    if (tempC) { tempC.addEventListener('focus', pinNow); }
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { pinNow(); }
    });
    pinNow();
    setInterval(pinNow, 30000);
  }

  // Adding, correcting and deleting readings all go through api.php, so the
  // page never reloads and a failed save can say why instead of looking like
  // nothing happened. Without JavaScript the same forms post to firing.php.
  var tempsList = document.getElementById('temps-list');
  var tempError = document.getElementById('temp-error');
  var tempAdd = document.getElementById('temp-add');

  function showTempError(message) {
    if (!tempError) { return; }
    tempError.textContent = message;
    tempError.hidden = !message;
  }

  /**
   * One POST to api.php. Anything that is not a JSON success comes back as a
   * thrown Error carrying the server's own words — including the raw body if
   * the server managed to fail without producing JSON at all.
   */
  function apiPost(fields) {
    var body = new URLSearchParams();
    Object.keys(fields).forEach(function (key) { body.set(key, fields[key]); });

    return fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (response) {
      return response.text().then(function (text) {
        var data;
        try {
          data = JSON.parse(text);
        } catch (e) {
          throw new Error('api.php returned HTTP ' + response.status + ': ' +
            text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 400));
        }
        if (!data.ok) {
          throw new Error((data.error || 'That did not save.') +
            (data.where ? ' [' + data.where + ']' : ''));
        }
        return data;
      });
    });
  }

  function postTemps(fields) {

    return apiPost(fields).then(function (data) {
      tempsList.innerHTML = data.html;
      var chart = document.getElementById('temps-chart');
      if (chart && typeof data.chart === 'string') { chart.innerHTML = data.chart; }
      bindTempRows();
      showTempError('');
      return data;
    });
  }

  // Tap a logged reading to correct it: the row becomes fields and the delete
  // button becomes save.
  function bindTempRows() {
    if (!tempsList) { return; }
    tempsList.querySelectorAll('.temp-row').forEach(function (row) {
      var action = row.querySelector('input[name="action"]');

      row.addEventListener('click', function (event) {
        if (row.classList.contains('editing')) { return; }
        if (event.target.closest('button')) { return; }
        row.classList.add('editing');
        action.value = 'temp_update';
        row.querySelectorAll('.temp-edit').forEach(function (field) {
          field.disabled = false;
        });
        var number = row.querySelector('input[name="temp_c"]');
        if (number) { number.focus(); number.select(); }
      });

      row.addEventListener('submit', function (event) {
        event.preventDefault();
        var editing = row.classList.contains('editing');
        if (!editing && !confirm('Delete this reading?')) { return; }

        var fields = {
          action: editing ? 'temp_update' : 'temp_delete',
          id: row.querySelector('input[name="id"]').value,
          index: row.querySelector('input[name="index"]').value
        };
        if (editing) {
          fields.temp_c = row.querySelector('input[name="temp_c"]').value;
          fields.temp_at = row.querySelector('input[name="temp_at"]').value;
        }
        postTemps(fields).catch(function (error) { showTempError(error.message); });
      });
    });
  }

  bindTempRows();

  if (tempAdd && tempsList) {
    tempAdd.addEventListener('submit', function (event) {
      event.preventDefault();
      var number = tempAdd.querySelector('input[name="temp_c"]');
      if (!number.value.trim()) { showTempError('Enter a temperature first.'); return; }

      postTemps({
        action: 'temp_add',
        id: tempAdd.querySelector('input[name="id"]').value,
        temp_c: number.value,
        temp_at: tempAdd.querySelector('input[name="temp_at"]').value
      }).then(function () {
        // Ready for the next hourly reading.
        number.value = '';
        if (tempAt) { delete tempAt.dataset.touched; tempAt.value = stamp(new Date()); }
        number.focus();
      }).catch(function (error) {
        showTempError(error.message);
      });
    });
  }

  /* ---------- checklist autosave ---------- */

  var form = document.getElementById('record-form');
  if (form && form.dataset.firing) {
    var doneEl = document.getElementById('check-done');
    var bar = document.querySelector('.progress span');
    var boxes = form.querySelectorAll('input[type="checkbox"][data-item]');

    var checkError = document.getElementById('check-error');

    boxes.forEach(function (box) {
      box.addEventListener('change', function () {
        apiPost({
          action: 'check',
          id: form.dataset.firing,
          item: box.dataset.item,
          checked: box.checked ? '1' : '0'
        }).then(function (data) {
          // The server counts the visit this item belongs to, which is the
          // only visit on screen. Its own total, never the whole firing's.
          var total = data.total || boxes.length;
          if (doneEl) { doneEl.textContent = data.done; }
          if (bar) { bar.style.width = (total ? data.done / total * 100 : 0) + '%'; }
          if (checkError) { checkError.hidden = true; }

          // Keep the tab for this visit in step with the checklist below it.
          var tab = document.querySelector('[data-visit-count="' + data.visit + '"]');
          if (tab) {
            var tabTotal = parseInt(tab.dataset.visitTotal, 10) || total;
            tab.textContent = data.done + '/' + tabTotal;
            tab.classList.toggle('complete', tabTotal > 0 && data.done === tabTotal);
          }
        }).catch(function (error) {
          // Put the box back — it did not save, so it should not look saved.
          box.checked = !box.checked;
          if (checkError) {
            checkError.textContent = error.message;
            checkError.hidden = false;
          }
        });
      });
    });
  }
})();
