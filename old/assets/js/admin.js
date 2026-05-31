/* global tgrData, jQuery */
(function ($) {
  'use strict';

  let routes = [];
  let stopRequested = false;

  // -------------------------------------------------------------------------
  // DOM ready
  // -------------------------------------------------------------------------
  $(function () {
    $('#tgr-parse-btn').on('click', handleParseCsv);
    $('#tgr-generate-btn').on('click', handleGenerate);
    $('#tgr-stop-btn').on('click', function () {
      stopRequested = true;
      $(this).prop('disabled', true).text('Зупиняємо…');
    });
  });

  // -------------------------------------------------------------------------
  // 1. Parse CSV
  // -------------------------------------------------------------------------
  function handleParseCsv() {
    var fileInput = document.getElementById('tgr-csv-file');
    if (!fileInput.files.length) {
      setStatus('Будь ласка, оберіть CSV файл.', 'error');
      return;
    }

    var formData = new FormData();
    formData.append('action', 'tgr_parse_csv');
    formData.append('nonce', tgrData.nonceCsv);
    formData.append('tgr_csv', fileInput.files[0]);

    setStatus('Розбір файлу…', 'info');
    $('#tgr-parse-btn').prop('disabled', true);

    $.ajax({
      url: tgrData.ajaxUrl,
      type: 'POST',
      data: formData,
      processData: false,
      contentType: false,
      success: function (res) {
        if (res.success && res.data.routes.length) {
          routes = res.data.routes;
          renderRouteList(routes);
          setStatus('Знайдено ' + routes.length + ' маршрут(ів).', 'success');
          $('#tgr-routes-card').show();
        } else {
          var msg = (res.data && res.data.message) ? res.data.message : 'Невідома помилка.';
          setStatus(msg, 'error');
        }
      },
      error: function () {
        setStatus('Помилка мережі. Спробуйте ще раз.', 'error');
      },
      complete: function () {
        $('#tgr-parse-btn').prop('disabled', false);
      },
    });
  }

  // -------------------------------------------------------------------------
  // 2. Render route list as badges
  // -------------------------------------------------------------------------
  function renderRouteList(list) {
    var $container = $('#tgr-routes-list').empty();
    list.forEach(function (route, i) {
      var label = route.title || '—';
      if (route.city) {
        label += ' (' + route.city + ')';
      }
      if (route.route_number) {
        label = '№' + route.route_number + ' ' + label;
      }
      $container.append(
        $('<div>').addClass('tgr-route-item').attr('data-index', i).text(label)
      );
    });
  }

  // -------------------------------------------------------------------------
  // 3. Generate — sequential AJAX loop
  // -------------------------------------------------------------------------
  async function handleGenerate() {
    if (!routes.length) {
      alert('Спочатку завантажте CSV файл.');
      return;
    }

    stopRequested = false;

    $('#tgr-generate-btn').hide();
    $('#tgr-stop-btn').show().prop('disabled', false).text('■ Зупинити');
    $('#tgr-progress-card').show();
    $('#tgr-log').empty();
    setProgress(0, routes.length);

    var created = 0, skipped = 0, errors = 0;

    for (var i = 0; i < routes.length; i++) {
      if (stopRequested) {
        appendLog('⏹ Зупинено користувачем.', 'warn');
        break;
      }

      var route = routes[i];
      var label = (route.route_number ? '№' + route.route_number + ' ' : '') + (route.title || '—');
      setProgress(i, routes.length, 'Генерую: ' + label);

      // Mark route badge as active
      $('.tgr-route-item').eq(i).addClass('tgr-route-active');

      var result = await generateRoute(route);

      // Update badge style
      $('.tgr-route-item').eq(i).removeClass('tgr-route-active').addClass('tgr-route-' + result.status);

      if (result.status === 'created') {
        created++;
        appendLog('✓ ' + result.message, 'created');
      } else if (result.status === 'skipped') {
        skipped++;
        appendLog('— ' + result.message, 'skipped');
      } else {
        errors++;
        appendLog('✗ ' + result.message, 'error');
      }

      // Pause between requests (3 s) — skip pause after last item
      if (i < routes.length - 1 && !stopRequested) {
        await countdown(3);
      }
    }

    setProgress(routes.length, routes.length, tgrData.i18n.done);
    appendLog(
      '──────────────────────────────────────────────────────',
      'divider'
    );
    appendLog(
      '📊 Результат: створено ' + created + ', пропущено ' + skipped + ', помилок ' + errors,
      'summary'
    );
    $('#tgr-stop-btn').hide();
    $('#tgr-generate-btn').show();
  }

  // -------------------------------------------------------------------------
  // Single-route AJAX call — returns Promise<{status, message}>
  // -------------------------------------------------------------------------
  function generateRoute(route) {
    return new Promise(function (resolve) {
      $.post(
        tgrData.ajaxUrl,
        {
          action: 'tgr_generate_route',
          nonce: tgrData.nonceGen,
          route: JSON.stringify(route),
        },
        function (res) {
          if (res.success) {
            resolve(res.data);
          } else {
            resolve({
              status: 'error',
              message: (res.data && res.data.message) ? res.data.message : 'Невідома помилка.',
            });
          }
        }
      ).fail(function () {
        resolve({ status: 'error', message: 'Помилка мережі.' });
      });
    });
  }

  // -------------------------------------------------------------------------
  // Countdown (seconds) visual pause
  // -------------------------------------------------------------------------
  function countdown(seconds) {
    return new Promise(function (resolve) {
      var remaining = seconds;

      function tick() {
        if (remaining <= 0 || stopRequested) {
          resolve();
          return;
        }
        $('#tgr-progress-text').text('Пауза між запитами: ' + remaining + ' с…');
        remaining--;
        setTimeout(tick, 1000);
      }

      tick();
    });
  }

  // -------------------------------------------------------------------------
  // UI helpers
  // -------------------------------------------------------------------------
  function setProgress(done, total, label) {
    var pct = total > 0 ? Math.round((done / total) * 100) : 0;
    $('#tgr-progress-bar').css('width', pct + '%').text(pct + '%');
    if (label) {
      $('#tgr-progress-text').text(label);
    }
  }

  function appendLog(html, type) {
    var $entry = $('<div>').addClass('tgr-log-entry tgr-log-' + type).html(html);
    $('#tgr-log').append($entry);
    var log = document.getElementById('tgr-log');
    if (log) log.scrollTop = log.scrollHeight;
  }

  function setStatus(msg, type) {
    $('#tgr-csv-status')
      .removeClass('tgr-status-info tgr-status-success tgr-status-error')
      .addClass('tgr-status-' + type)
      .text(msg);
  }
})(jQuery);
