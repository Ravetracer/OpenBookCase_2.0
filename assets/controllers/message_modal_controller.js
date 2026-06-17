import { Controller } from '@hotwired/stimulus';

/*
 * Lives on the permanent <dialog id="messageModal">. The inbox HTML is fetched
 * and injected into the body target. Opened by a document-level `msg:open`
 * CustomEvent (dispatched by the navbar bell), mirroring the bc:open / bc:photos
 * pattern. The inbox is read-only EXCEPT for an API-application reply box, whose
 * submit is handled here via delegation (the injected fragment carries plain data-*).
 */
export default class extends Controller {
    static targets = ['body'];

    connect() {
        this.onOpenEvent = () => this.open();
        document.addEventListener('msg:open', this.onOpenEvent);

        // Delegated submit for the (injected) application-thread reply form.
        this.onSubmit = (event) => {
            const form = event.target.closest('form[data-msg-reply]');
            if (form && this.element.contains(form)) {
                event.preventDefault();
                this.sendReply(form);
            }
        };
        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        document.removeEventListener('msg:open', this.onOpenEvent);
        this.element.removeEventListener('submit', this.onSubmit);
    }

    async sendReply(form) {
        const button = form.querySelector('button[type="submit"]');
        if (button) button.disabled = true;
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
            if (response.ok) {
                // Reload the inbox so the sent reply is reflected in the thread.
                await this.load();
            } else if (button) {
                button.disabled = false;
            }
        } catch {
            if (button) button.disabled = false;
        }
    }

    async open() {
        if (!this.element.open) this.element.showModal();
        await this.load();
    }

    async load() {
        this.setLoading();
        try {
            const response = await fetch('/messages');
            if (!response.ok) {
                this.showError('Your messages could not be loaded.');
                return;
            }
            this.bodyTarget.innerHTML = await response.text();
            // Opening the inbox marks everything read server-side, so clear the badge.
            document.querySelector('[data-message-badge]')?.remove();
        } catch {
            this.showError('Something went wrong. Please try again.');
        }
    }

    showError(message) {
        this.bodyTarget.innerHTML =
            '<div class="alert alert-error my-2" role="alert"></div>' +
            '<div class="modal-action"><button type="button" class="btn btn-ghost" onclick="messageModal.close()">Close</button></div>';
        this.bodyTarget.querySelector('.alert').textContent = message;
    }

    setLoading() {
        this.bodyTarget.innerHTML =
            '<div class="flex justify-center py-10"><span class="loading loading-spinner loading-lg"></span></div>';
    }
}
