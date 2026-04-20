import { Controller } from '@hotwired/stimulus';

/**
 * BadWord Filter Controller
 * Handles errors from bad word detection with nice UI feedback
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

    /**
     * Intercept form submission to handle bad word errors
     */
    submitComment(event) {
        // Let the original handler do its thing, but patch the error handling
        const originalHandler = this.element.onsubmit;
        
        // We'll handle errors in the fetch interceptor instead
    }

    /**
     * Real-time validation as user types
     */
    validateCommentOnInput() {
        const content = this.textareaTarget.value.trim();
        
        if (!content) {
            this.clearWarning();
            return;
        }

        // Optional: You could add real-time preview here
        // by calling the BadWordService analysis endpoint
    }

    /**
     * Show warning with bad words
     */
    showBadWordWarning(errorData) {
        let warningBox = this.element.querySelector('.bad-word-warning-box');
        
        if (!warningBox) {
            warningBox = document.createElement('div');
            warningBox.className = 'bad-word-warning-box mb-3';
            this.element.insertBefore(warningBox, this.element.firstChild);
        }

        const badWords = (errorData.bad_words_found || [])
            .map(w => `<span class="badge bg-danger ms-1">${this.escapeHtml(w)}</span>`)
            .join('');

        warningBox.innerHTML = `
            <div class="alert alert-warning alert-dismissible fade show d-flex gap-3" role="alert">
                <i class="ti ti-alert-triangle-filled flex-shrink-0" style="font-size:20px; margin-top:2px;"></i>
                <div class="flex-grow-1">
                    <h6 class="alert-heading mb-2">⚠️ Inappropriate Language</h6>
                    <p class="mb-2 small">
                        Found ${errorData.bad_words_found.length} word(s):
                        ${badWords}
                    </p>
                    <div class="bg-white p-2 rounded mb-2" style="border-left: 3px solid #ffc107; font-size:0.85rem;">
                        <strong>How it will appear:</strong><br/>
                        <em class="text-muted">${this.escapeHtml(errorData.censored_preview)}</em>
                    </div>
                    <small class="text-muted">${errorData.suggestion}</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        // Shake the textarea
        this.textareaTarget.style.animation = 'shake 0.4s ease-in-out';
        setTimeout(() => {
            this.textareaTarget.style.animation = '';
        }, 400);

        // Auto-hide after 10 seconds
        setTimeout(() => {
            const alert = warningBox.querySelector('.alert');
            if (alert) {
                alert.classList.remove('show');
                alert.addEventListener('transitionend', () => warningBox.remove(), { once: true });
            }
        }, 10000);
    }

    /**
     * Clear any displayed warnings
     */
    clearWarning() {
        const warning = this.element.querySelector('.bad-word-warning-box');
        if (warning) {
            warning.remove();
        }
    }

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
