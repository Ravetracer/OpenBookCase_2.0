import { Controller } from '@hotwired/stimulus';

/*
 * Lives on the permanent <dialog id="exportTermsModal"> on the /list page.
 * The JSON export links carry `data-export-download` (plus their real href +
 * download attr as a no-JS fallback). When JS is on, a delegated document click
 * is intercepted: instead of downloading straight away we show the ODbL / OSM
 * usage-terms dialog. Only after the user agrees does the download start.
 *
 * Follows the delegation rule: the trigger links live outside this dialog, so we
 * listen on `document` rather than putting Stimulus actions on them. The Agree /
 * Cancel buttons sit inside the dialog, so they use plain `export-terms#...` actions.
 */
export default class extends Controller {
    connect() {
        this.pendingUrl = null;
        this.onClick = (event) => {
            const trigger = event.target.closest('[data-export-download]');
            if (!trigger) return;
            event.preventDefault();
            this.pendingUrl = trigger.getAttribute('href') || trigger.dataset.exportUrl;
            if (!this.element.open) this.element.showModal();
        };
        document.addEventListener('click', this.onClick);
    }

    disconnect() {
        document.removeEventListener('click', this.onClick);
    }

    confirm() {
        const url = this.pendingUrl;
        this.element.close();
        if (!url) return;

        // Trigger the download via a transient anchor so the `download` attribute
        // is honoured (same-origin streamed response).
        const a = document.createElement('a');
        a.href = url;
        a.setAttribute('download', '');
        document.body.appendChild(a);
        a.click();
        a.remove();
        this.pendingUrl = null;
    }

    cancel() {
        this.pendingUrl = null;
        this.element.close();
    }
}
