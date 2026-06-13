import { Controller } from '@hotwired/stimulus';

/*
 * Lives on the permanent <dialog id="wishlistModal">. Opened by the bookcase-modal
 * controller via the `bc:wishlist` custom event. The list fragment is injected into
 * the body target; its buttons use `data-action="wishlist-modal#..."` which resolves
 * because this controller sits on the permanent dialog element.
 *
 * After every mutation it dispatches a `wishlist:changed` document event carrying the
 * new open-wish count so the bookcase detail dialog can update its button live.
 */
export default class extends Controller {
    static targets = ['body'];

    // Translated strings emitted as data-trans-* on the dialog element (base.html.twig).
    t(key, fallback) {
        return this.element.dataset[key] || fallback;
    }

    connect() {
        this.bcid = null;
        this.onOpen = (event) => this.openFor(event.detail.id);
        document.addEventListener('bc:wishlist', this.onOpen);
    }

    disconnect() {
        document.removeEventListener('bc:wishlist', this.onOpen);
    }

    async openFor(bcid) {
        this.bcid = bcid;
        await this.reload();
        if (!this.element.open) this.element.showModal();
    }

    async reload() {
        const res = await fetch(`/api/bookcase/${this.bcid}/wishlist`);
        this.bodyTarget.innerHTML = await res.text();
    }

    // Add a new wish. The form carries title (required) + optional author/isbn/misc.
    async addItem(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const submitBtn = form.querySelector('[type=submit]');
        const errorEl = form.querySelector('.wishlist-error');

        errorEl.classList.add('hidden');
        errorEl.textContent = '';
        submitBtn.disabled = true;

        try {
            const response = await fetch(`/api/bookcase/${this.bcid}/wishlist`, {
                method: 'POST',
                body: new FormData(form),
            });
            const json = await response.json().catch(() => ({}));

            if (!response.ok) {
                errorEl.textContent = json.error || this.t('transAddFailed', 'Could not add this wish. Please try again.');
                errorEl.classList.remove('hidden');
                return;
            }

            this.announce(json.openCount);
            await this.reload();
        } catch {
            errorEl.textContent = this.t('transAddFailed', 'Could not add this wish. Please try again.');
            errorEl.classList.remove('hidden');
        } finally {
            submitBtn.disabled = false;
        }
    }

    // Reveal/hide the inline "not found" note box for a dropped item.
    toggleNotFound(event) {
        const box = event.currentTarget.closest('[data-wl-item]')?.querySelector('[data-notfound-box]');
        if (box) box.classList.toggle('hidden');
    }

    // Drop / fulfill / not-found. The button carries data-item-id + data-wl-action;
    // a not-found send also reads the optional comment from the inline note box.
    async changeStatus(event) {
        const btn = event.currentTarget;
        const action = btn.dataset.wlAction;
        const itemId = btn.dataset.itemId;

        const body = new URLSearchParams({ action });
        if (action === 'notfound') {
            const note = btn.closest('[data-wl-item]')?.querySelector('[data-notfound-comment]');
            if (note) body.append('comment', note.value);
        }

        btn.disabled = true;
        try {
            const response = await fetch(`/api/bookcase/${this.bcid}/wishlist/${itemId}/status`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok) {
                alert(json.error || this.t('transUpdateFailed', 'Could not update this wish.'));
                btn.disabled = false;
                return;
            }
            this.announce(json.openCount);
            await this.reload();
        } catch {
            btn.disabled = false;
        }
    }

    // Cancel an own open wish.
    async deleteItem(event) {
        if (!confirm(this.t('transConfirmCancel', 'Cancel this wish?'))) return;

        const btn = event.currentTarget;
        btn.disabled = true;
        try {
            const response = await fetch(`/api/bookcase/${this.bcid}/wishlist/${btn.dataset.itemId}`, {
                method: 'DELETE',
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok) {
                btn.disabled = false;
                return;
            }
            this.announce(json.openCount);
            await this.reload();
        } catch {
            btn.disabled = false;
        }
    }

    // Let the detail dialog refresh its "Wishlist (N)" button without a reload.
    announce(openCount) {
        if (typeof openCount === 'undefined') return;
        document.dispatchEvent(new CustomEvent('wishlist:changed', {
            detail: { id: this.bcid, openCount },
        }));
    }
}
