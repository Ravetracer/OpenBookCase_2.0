import { Controller } from '@hotwired/stimulus';

/*
 * Lives on the permanent <dialog id="photoModal">. Opened by the bookcase-modal
 * controller via the `bc:photos` custom event. The photo grid HTML is injected
 * into the body target; its buttons use `data-action="photo-modal#..."` which
 * resolves because this controller sits on the permanent dialog element.
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
        document.addEventListener('bc:photos', this.onOpen);
    }

    disconnect() {
        document.removeEventListener('bc:photos', this.onOpen);
    }

    async openFor(bcid) {
        this.bcid = bcid;
        await this.reload();
        if (!this.element.open) this.element.showModal();
    }

    async reload() {
        const res = await fetch(`/api/bookcase/${this.bcid}/photos`);
        this.bodyTarget.innerHTML = await res.text();
    }

    async upload(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const submitBtn = form.querySelector('[type=submit]');
        const errorEl = form.querySelector('.photo-error');

        errorEl.classList.add('hidden');
        errorEl.textContent = '';
        submitBtn.disabled = true;

        try {
            const response = await fetch(`/api/bookcase/${this.bcid}/image`, {
                method: 'POST',
                body: new FormData(form),
            });
            const json = await response.json();

            if (!response.ok) {
                errorEl.textContent = json.error || this.t('transUploadFailed', 'Upload failed. Please try again.');
                errorEl.classList.remove('hidden');
                return;
            }

            await this.reload();
        } catch {
            errorEl.textContent = this.t('transUploadFailed', 'Upload failed. Please try again.');
            errorEl.classList.remove('hidden');
        } finally {
            submitBtn.disabled = false;
        }
    }

    // Save an existing image's alt text (screen-reader description) on blur.
    async saveAlt(event) {
        const input = event.currentTarget;
        input.classList.remove('input-success', 'input-error');
        try {
            const response = await fetch(
                `/api/bookcase/${this.bcid}/image/${input.dataset.imageId}/alt`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `altText=${encodeURIComponent(input.value)}`,
                },
            );
            input.classList.add(response.ok ? 'input-success' : 'input-error');
        } catch {
            input.classList.add('input-error');
        }
        setTimeout(() => input.classList.remove('input-success', 'input-error'), 1500);
    }

    async rotate(event) {
        const btn = event.currentTarget;
        btn.disabled = true;

        try {
            const response = await fetch(
                `/api/bookcase/${this.bcid}/image/${btn.dataset.imageId}/rotate`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `direction=${btn.dataset.direction}`,
                },
            );
            if (response.ok) {
                const card = btn.closest('[data-image-card]');
                const img = card.querySelector('img');
                img.src = img.src.split('?')[0] + '?v=' + Date.now();
            }
        } finally {
            btn.disabled = false;
        }
    }

    async deleteImage(event) {
        if (!confirm(this.t('transConfirmDelete', 'Delete this image? This cannot be undone.'))) return;

        const btn = event.currentTarget;
        btn.disabled = true;

        const response = await fetch(
            `/api/bookcase/${this.bcid}/image/${btn.dataset.imageId}`,
            { method: 'DELETE' },
        );

        if (response.ok) {
            await this.reload();
        } else {
            btn.disabled = false;
        }
    }
}
