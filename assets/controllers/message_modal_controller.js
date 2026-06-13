import { Controller } from '@hotwired/stimulus';

/*
 * Lives on the permanent <dialog id="messageModal">. The inbox HTML is fetched
 * and injected into the body target. Opened by a document-level `msg:open`
 * CustomEvent (dispatched by the navbar bell), mirroring the bc:open / bc:photos
 * pattern. Read-only inbox, so no delegation is needed inside the modal.
 */
export default class extends Controller {
    static targets = ['body'];

    connect() {
        this.onOpenEvent = () => this.open();
        document.addEventListener('msg:open', this.onOpenEvent);
    }

    disconnect() {
        document.removeEventListener('msg:open', this.onOpenEvent);
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
