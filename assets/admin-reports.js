/**
 * Renders the Response Overview admin page's charts from window.BRS_REPORT_DATA
 * (built server-side by BRS_Reports::aggregate()). Plain vanilla JS + Chart.js's
 * UMD build (assets/vendor/chart.umd.min.js) - this page is plugin admin-only
 * chrome, not part of the React survey app, so it doesn't go through Vite.
 *
 * Per-question charts are instantiated lazily via IntersectionObserver rather
 * than all at once on load: a full 58-question survey means up to ~50 charts
 * on one page, and Chart.js canvases aren't free to construct.
 */
(function () {
  'use strict';

  var DATA = window.BRS_REPORT_DATA;
  if (!DATA || typeof Chart === 'undefined') {
    return;
  }

  var NAVY = '#16294f';
  var PALETTE = ['#2a78d6', '#eb6834', '#769c28', '#c98900', '#83465b', '#5c6b7a', '#b13232', '#0a8b0a'];

  Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", sans-serif';
  Chart.defaults.color = '#3c434a';

  function colorAt(i) {
    return PALETTE[i % PALETTE.length];
  }

  function renderLevelDistribution(canvas) {
    var levels = DATA.levelDistribution || [];

    new Chart(canvas, {
      type: 'bar',
      data: {
        labels: levels.map(function (l) { return 'Level ' + l.level + '\n' + l.label; }),
        datasets: [
          {
            label: 'Responses',
            data: levels.map(function (l) { return l.count; }),
            backgroundColor: NAVY,
            borderRadius: 4,
            maxBarThickness: 56,
          },
        ],
      },
      options: {
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
        },
      },
    });
  }

  function renderSectionScores(canvas) {
    var sections = DATA.sectionScores || [];

    new Chart(canvas, {
      type: 'bar',
      data: {
        labels: sections.map(function (s) { return s.name; }),
        datasets: [
          {
            label: 'Average score (%)',
            data: sections.map(function (s) { return s.avgPct; }),
            backgroundColor: sections.map(function (_, i) { return colorAt(i); }),
            borderRadius: 4,
          },
        ],
      },
      options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: {
          x: { beginAtZero: true, max: 100, ticks: { callback: function (v) { return v + '%'; } } },
        },
      },
    });
  }

  function findQuestion(id) {
    for (var s = 0; s < DATA.sections.length; s++) {
      var qs = DATA.sections[s].questions;
      for (var i = 0; i < qs.length; i++) {
        if (qs[i].id === id) return qs[i];
      }
    }
    return null;
  }

  function renderChoiceQuestion(canvas, q) {
    var labels = Object.keys(q.counts);
    var counts = labels.map(function (l) { return q.counts[l]; });

    if (q.notAnswered > 0) {
      labels = labels.concat(['Not answered']);
      counts = counts.concat([q.notAnswered]);
    }

    new Chart(canvas, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [
          {
            data: counts,
            backgroundColor: labels.map(function (l, i) {
              return l === 'Not answered' ? '#c3c4c7' : colorAt(i);
            }),
            borderRadius: 4,
          },
        ],
      },
      options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: {
          x: { beginAtZero: true, ticks: { precision: 0 } },
        },
      },
    });
  }

  function renderLikertQuestion(canvas, q) {
    var rowLabels = q.rows.map(function (r) { return r.label; });

    var datasets = q.columns.map(function (col, i) {
      return {
        label: col,
        data: q.rows.map(function (r) { return r.counts[col]; }),
        backgroundColor: colorAt(i),
      };
    });

    new Chart(canvas, {
      type: 'bar',
      data: { labels: rowLabels, datasets: datasets },
      options: {
        indexAxis: 'y',
        plugins: { legend: { position: 'bottom' } },
        scales: {
          x: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
          y: { stacked: true },
        },
      },
    });
  }

  function renderRatingQuestion(canvas, q) {
    var labels = [];
    var counts = [];

    for (var star = 1; star <= q.stars; star++) {
      labels.push(star + (1 === star ? ' star' : ' stars'));
      counts.push(q.counts[star] || 0);
    }

    new Chart(canvas, {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{ data: counts, backgroundColor: NAVY, borderRadius: 4, maxBarThickness: 48 }],
      },
      options: {
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
        },
      },
    });
  }

  function renderQuestionChart(canvas) {
    var q = findQuestion(canvas.getAttribute('data-brs-question'));
    if (!q) return;

    if ('choice' === q.kind) renderChoiceQuestion(canvas, q);
    else if ('likert' === q.kind) renderLikertQuestion(canvas, q);
    else if ('rating' === q.kind) renderRatingQuestion(canvas, q);
  }

  // Forces every not-yet-rendered question chart to draw immediately, for
  // the "Download PDF" flow - the lazy IntersectionObserver below is exactly
  // wrong there, since a screenshot needs every chart drawn up front, not
  // only the ones that happen to have scrolled into view yet. Chart.getChart
  // is Chart.js's own registry lookup, so this is safe to call on a canvas
  // that's already been rendered (no double-instantiation).
  function renderAllQuestionCharts() {
    document.querySelectorAll('canvas[data-brs-chart="question"]').forEach(function (canvas) {
      if (!Chart.getChart(canvas)) {
        renderQuestionChart(canvas);
      }
    });
  }

  // Same rationale as Results.jsx's PDF export: a real downloaded file
  // (html2canvas snapshot -> jsPDF, JPEG rather than PNG to keep file size
  // sane) rather than window.print(), which just opens the OS print dialog
  // instead of actually downloading anything. Scale is lower here than the
  // participant dashboard's export (1.5 vs 2) since this page runs to many
  // times the length - a full-size 2x capture would produce an unworkably
  // large image for very little visible quality gain in a chart-heavy report.
  function downloadReportPdf(node) {
    var jsPDF = window.jspdf.jsPDF;

    return html2canvas(node, { scale: 1.5, useCORS: true }).then(function (canvas) {
      var imgData = canvas.toDataURL('image/jpeg', 0.9);
      var pdf = new jsPDF({ unit: 'pt', format: 'a4', compress: true });
      var pageWidth = pdf.internal.pageSize.getWidth();
      var pageHeight = pdf.internal.pageSize.getHeight();
      var imgWidth = pageWidth;
      var imgHeight = (canvas.height * imgWidth) / canvas.width;

      var heightLeft = imgHeight;
      var position = 0;

      pdf.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight);
      heightLeft -= pageHeight;

      while (heightLeft > 0) {
        position -= pageHeight;
        pdf.addPage();
        pdf.addImage(imgData, 'JPEG', 0, position, imgWidth, imgHeight);
        heightLeft -= pageHeight;
      }

      var today = new Date().toISOString().slice(0, 10);
      pdf.save('Response-Overview-' + today + '.pdf');
    });
  }

  function wireDownloadButton() {
    var btn = document.getElementById('brs-report-download');
    if (!btn || typeof html2canvas === 'undefined' || !window.jspdf) return;

    btn.addEventListener('click', function () {
      btn.disabled = true;
      var originalText = btn.textContent;
      btn.textContent = 'Preparing PDF…';

      renderAllQuestionCharts();

      // One frame so every newly-created chart actually paints before the
      // capture runs - html2canvas reads the live rendered canvas pixels,
      // not chart data, so it would otherwise screenshot blank canvases.
      requestAnimationFrame(function () {
        setTimeout(function () {
          downloadReportPdf(document.querySelector('.brs-reports-wrap'))
            .catch(function (err) {
              window.alert('Could not generate the PDF: ' + err.message);
            })
            .then(function () {
              btn.disabled = false;
              btn.textContent = originalText;
            });
        }, 300);
      });
    });
  }

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var overview = document.querySelectorAll('canvas[data-brs-chart]');

    overview.forEach(function (canvas) {
      var kind = canvas.getAttribute('data-brs-chart');

      if ('levelDistribution' === kind) {
        renderLevelDistribution(canvas);
      } else if ('sectionScores' === kind) {
        renderSectionScores(canvas);
      }
    });

    var questionCanvases = document.querySelectorAll('canvas[data-brs-chart="question"]');

    if (!('IntersectionObserver' in window)) {
      // Fallback for older browsers: just render everything up front.
      questionCanvases.forEach(renderQuestionChart);
    } else {
      var observer = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              renderQuestionChart(entry.target);
              observer.unobserve(entry.target);
            }
          });
        },
        { rootMargin: '200px 0px' }
      );

      questionCanvases.forEach(function (canvas) {
        observer.observe(canvas);
      });
    }

    wireDownloadButton();
  });
})();
