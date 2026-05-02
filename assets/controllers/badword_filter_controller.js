import { Controller } from '@hotwired/stimulus';

/**
 * BadWord Filter Controller
 * Handles moderation errors with clear UI feedback.
 */
export default class extends Controller {
    static targets = ['textarea', 'submitButton', 'container'];
    static values = {
        commentRoute: String,
    };

    connect() {
        if (this.hasTextareaTarget) {
            this.textareaTarget.addEventListener('input', () => this.validateCommentOnInput());
        }
    }

    submitComment(event) {
        const originalHandler = this.element.onsubmit;
        void originalHandler;
        void event;
    }

    validateCommentOnInput() {
        const content = this.textareaTarget.value.trim();

        if (!content) {
            this.clearWarning();
        }
    }

    showBadWordWarning(errorData) {
        let warningBox = this.element.querySelector('.bad-word-warning-box');
        const isHarmfulIntent = errorData.type === 'harmful_intent';
        const labels = errorData.bad_words_found || [];
        const heading = isHarmfulIntent ? 'Harmful Intent Detected' : 'Inappropriate Language';
        const badgeLabel = isHarmfulIntent ? 'Detected signal(s):' : `Found ${labels.length} word(s):`;
        const previewTitle = isHarmfulIntent
            ? 'This comment expresses an opinion in a harmful or targeted way:'
            : 'How it will appear:';

        if (!warningBox) {
            warningBox = document.createElement('div');
            warningBox.className = 'bad-word-warning-box mb-3';
            this.element.insertBefore(warningBox, this.element.firstChild);
        }

        const badges = labels
            .map((label) => `<span class="badge bg-danger ms-1">${this.escapeHtml(label)}</span>`)
            .join('');

        warningBox.innerHTML = `
            <div class="alert alert-warning alert-dismissible fade show d-flex gap-3" role="alert">
                <i class="ti ti-alert-triangle-filled flex-shrink-0" style="font-size:20px; margin-top:2px;"></i>
                <div class="flex-grow-1">
                    <h6 class="alert-heading mb-2">${heading}</h6>
                    <p class="mb-2 small">
                        ${badgeLabel}
                        ${badges}
                    </p>
                    <div class="bg-white p-2 rounded mb-2" style="border-left: 3px solid #ffc107; font-size:0.85rem;">
                        <strong>${previewTitle}</strong><br/>
                        <em class="text-muted">${this.escapeHtml(errorData.censored_preview || '')}</em>
                    </div>
                    <small class="text-muted">${this.escapeHtml(errorData.suggestion || '')}</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        this.textareaTarget.style.animation = 'shake 0.4s ease-in-out';
        setTimeout(() => {
            this.textareaTarget.style.animation = '';
        }, 400);

        setTimeout(() => {
            const alert = warningBox.querySelector('.alert');
            if (alert) {
                alert.classList.remove('show');
                alert.addEventListener('transitionend', () => warningBox.remove(), { once: true });
            }
        }, 10000);
    }

    clearWarning() {
        const warning = this.element.querySelector('.bad-word-warning-box');
        if (warning) {
            warning.remove();
        }
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }
}
