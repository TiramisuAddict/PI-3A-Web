/**
 * Shared bad word warning UI for feed comment forms.
 */
(function () {
    'use strict';

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function showBadWordWarning(errorData, form, textarea) {
        if (!form) return;

        var isHarmfulIntent = errorData.type === 'harmful_intent';
        var labels = errorData.bad_words_found || [];
        var heading = isHarmfulIntent ? 'Harmful Intent Detected' : 'Inappropriate Language Detected';
        var badgeLabel = isHarmfulIntent ? 'Detected signal(s): ' : 'Found ' + labels.length + ' word(s): ';
        var previewTitle = isHarmfulIntent
            ? 'This comment expresses an opinion in a harmful or targeted way:'
            : 'Your comment contains these inappropriate words:';

        var existingWarning = form.querySelector('.bad-word-warning-box');
        if (existingWarning) {
            existingWarning.remove();
        }

        var warningBox = document.createElement('div');
        warningBox.className = 'bad-word-warning-box mb-3';

        var badWordsHTML = labels
            .map(function (word) {
                return '<span class="badge bg-danger ms-1">' + escapeHtml(word) + '</span>';
            })
            .join('');

        warningBox.innerHTML =
            '<div class="alert alert-danger alert-dismissible fade show d-flex gap-3" role="alert">' +
                '<i class="ti ti-alert-triangle-filled flex-shrink-0" style="font-size:20px; margin-top:2px;"></i>' +
                '<div class="flex-grow-1">' +
                    '<h6 class="alert-heading mb-2">' + heading + '</h6>' +
                    '<p class="mb-2 small">' +
                        badgeLabel +
                        badWordsHTML +
                    '</p>' +
                    '<div class="bg-white p-2 rounded mb-2" style="border-left: 3px solid #dc2626; font-size:0.85rem; word-break: break-word;">' +
                        '<strong>' + previewTitle + '</strong><br>' +
                        '<em class="text-muted">' + escapeHtml(errorData.censored_preview || '') + '</em>' +
                    '</div>' +
                    '<small class="text-muted">' + escapeHtml(errorData.suggestion || '') + '</small>' +
                '</div>' +
                '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
            '</div>';

        form.parentElement.insertBefore(warningBox, form);

        if (textarea) {
            textarea.style.animation = 'shake 0.4s ease-in-out';
            setTimeout(function () {
                textarea.style.animation = '';
            }, 400);
        }

        warningBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        setTimeout(function () {
            var alert = warningBox.querySelector('.alert');
            if (alert) {
                alert.classList.remove('show');
                setTimeout(function () {
                    if (warningBox.parentElement) {
                        warningBox.remove();
                    }
                }, 150);
            }
        }, 13000);
    }

    window.showBadWordWarning = showBadWordWarning;
})();
