/**
 * "Download PDF" on the admin single-submission view (Bounce Survey ->
 * Submissions -> a reference) - a PDF of the full raw Q&A detail for that
 * submission, distinct from the branded results dashboard PDF (that one's
 * built in Results.jsx / assets/admin-reports.js, this page has no charts,
 * just the plain question/answer table BRS_Admin::render_single() already
 * outputs).
 */
(function () {
  'use strict';

  function downloadSubmissionPdf(node, reference) {
    var jsPDF = window.jspdf.jsPDF;

    var options = {
      scale: 1.5,
      useCORS: true,
      // Same reasoning as the Response Overview export: html2canvas clones
      // the whole page and crops to the target element's screen position,
      // so WordPress's own chrome and any other plugin's admin notices can
      // bleed into the capture even though they're not inside `node`.
      onclone: function (clonedDoc) {
        var selectors = [
          '#wpadminbar',
          '#adminmenumain',
          '#adminmenuback',
          '#adminmenuwrap',
          '#wpfooter',
          '.notice',
          '.updated',
          '.error',
          '.page-title-action',
          '.brs-admin-back-link',
        ];

        clonedDoc.querySelectorAll(selectors.join(',')).forEach(function (el) {
          el.style.display = 'none';
        });
      },
    };

    return html2canvas(node, options).then(function (canvas) {
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

      pdf.save('Submission-' + reference + '.pdf');
    });
  }

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var btn = document.getElementById('brs-submission-download');
    if (!btn) return;

    btn.addEventListener('click', function () {
      if (typeof html2canvas === 'undefined' || !window.jspdf) {
        console.error(
          'BRS Submission PDF export: html2canvas and/or jsPDF failed to load.',
          { html2canvas: typeof html2canvas, jspdf: typeof window.jspdf }
        );
        window.alert('Could not generate the PDF: a required script did not load. Check the browser console for details.');
        return;
      }

      var node = document.querySelector('.brs-submission-detail');
      if (!node) return;

      btn.disabled = true;
      var originalText = btn.textContent;
      btn.textContent = 'Preparing PDF…';

      downloadSubmissionPdf(node, window.BRS_SUBMISSION_REF || 'response')
        .catch(function (err) {
          window.alert('Could not generate the PDF: ' + err.message);
        })
        .then(function () {
          btn.disabled = false;
          btn.textContent = originalText;
        });
    });
  });
})();
