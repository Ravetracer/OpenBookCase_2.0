import { Controller } from '@hotwired/stimulus';
import { searchPhoton, placeLabel, parseGeo } from '../geocode.js';

/*
 * Lives on the permanent <dialog id="bookcaseModal">. The detail / edit HTML is
 * fetched and injected into the body target. Because that markup is dynamic,
 * its buttons carry plain `data-modal-action` attributes and are handled here
 * via event delegation (no Stimulus controllers on injected nodes).
 */
export default class extends Controller {
    static targets = ['body'];

    // Translated strings emitted as data-trans-* on the dialog element (base/index/list).
    t(key, fallback) {
        return this.element.dataset[key] || fallback;
    }

    connect() {
        // Open from a map popup button anywhere in the document.
        this.onDocumentClick = (event) => {
            const opener = event.target.closest('[data-bc-open]');
            if (opener) {
                event.preventDefault();
                this.open(opener.dataset.bcOpen);
            }
        };
        document.addEventListener('click', this.onDocumentClick);

        // Programmatic open (e.g. shared deep link via map controller).
        this.onOpenEvent = (event) => this.open(event.detail.id);
        document.addEventListener('bc:open', this.onOpenEvent);

        // Open the quick-add dialog. Anchors carrying [data-bc-create] (e.g. the
        // navbar button) are intercepted here so no page reload happens when the
        // modal is present; on pages without it the anchor navigates to /?add=1.
        this.onCreateClick = (event) => {
            const opener = event.target.closest('[data-bc-create]');
            if (!opener) return;
            event.preventDefault();
            this.openCreate({ editable: opener.dataset.editable === '1' });
        };
        document.addEventListener('click', this.onCreateClick);

        // Programmatic open from the map (right-click / long-press / geolocation).
        this.onCreateEvent = (event) => this.openCreate(event.detail || {});
        document.addEventListener('bc:create', this.onCreateEvent);

        // Address-search / geo: typing inside the injected create form.
        this.onModalInput = (event) => {
            if (event.target.matches('[data-geo-input]')) this.applyGeo(event.target);
            else if (event.target.matches('[data-create-address-search]')) this.onAddressInput(event.target);
        };
        this.element.addEventListener('input', this.onModalInput);

        // Arriving on the map via the navbar button on another page (/?add=1).
        if (new URLSearchParams(window.location.search).get('add') === '1') {
            history.replaceState(null, '', window.location.pathname);
            this.openCreate({ editable: true });
        }

        // Keep the Watch button in sync with changes made in the profile modal.
        this.onWatchlistChanged = (event) => this.syncWatchButton(event.detail);
        document.addEventListener('watchlist:changed', this.onWatchlistChanged);

        // Keep the "Wishlist (N)" button count in sync with the wishlist modal.
        this.onWishlistChanged = (event) => this.syncWishlistButton(event.detail);
        document.addEventListener('wishlist:changed', this.onWishlistChanged);

        // Route actions inside the modal.
        this.onModalClick = (event) => {
            // Address-search result pick (create form) — own little delegated path.
            const pick = event.target.closest('[data-addr-pick]');
            if (pick && this.element.contains(pick)) {
                event.preventDefault();
                this.pickAddress(pick);
                return;
            }

            const trigger = event.target.closest('[data-modal-action]');
            if (!trigger || !this.element.contains(trigger)) return;

            const id = trigger.dataset.bcId;
            switch (trigger.dataset.modalAction) {
                case 'detail': event.preventDefault(); this.loadDetail(id); break;
                case 'edit':   event.preventDefault(); this.loadEdit(id); break;
                case 'create': event.preventDefault(); this.create(); break;
                case 'save':   event.preventDefault(); this.save(id); break;
                case 'delete': event.preventDefault(); this.deleteCase(id); break;
                case 'photos': event.preventDefault(); this.openPhotos(id); break;
                case 'wishlist': event.preventDefault(); this.openWishlist(id); break;
                case 'toggle-watch': event.preventDefault(); this.toggleWatch(trigger); break;
                case 'copy-link':        event.preventDefault(); this.copyLink(trigger); break;
                case 'add-caretaker':    event.preventDefault(); this.addCaretaker(); break;
                case 'remove-caretaker': event.preventDefault(); trigger.closest('[data-caretaker-entry]')?.remove(); break;
                case 'close':  event.preventDefault(); this.element.close(); break;
            }
        };
        this.element.addEventListener('click', this.onModalClick);

        // Submitting the edit form (e.g. Enter key) saves via AJAX instead of navigating.
        this.onSubmit = (event) => {
            const form = event.target.closest('form[data-bc-id]');
            if (!form || !this.element.contains(form)) return;
            event.preventDefault();
            this.save(form.dataset.bcId);
        };
        this.element.addEventListener('submit', this.onSubmit);

        // Per-user rating: save immediately when a heart is picked.
        this.onChange = (event) => {
            const radio = event.target;
            if (radio.name !== 'user-rating') return;
            const container = radio.closest('[data-user-rating]');
            if (container) this.saveRating(container.dataset.bcId, radio.value, container);
        };
        this.element.addEventListener('change', this.onChange);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('bc:open', this.onOpenEvent);
        document.removeEventListener('click', this.onCreateClick);
        document.removeEventListener('bc:create', this.onCreateEvent);
        document.removeEventListener('watchlist:changed', this.onWatchlistChanged);
        document.removeEventListener('wishlist:changed', this.onWishlistChanged);
        this.element.removeEventListener('click', this.onModalClick);
        this.element.removeEventListener('submit', this.onSubmit);
        this.element.removeEventListener('input', this.onModalInput);
        this.element.removeEventListener('change', this.onChange);
    }

    open(id) {
        this.loadDetail(id);
        if (!this.element.open) this.element.showModal();
    }

    async loadDetail(id) {
        await this.loadInto(`/api/bookcase/${id}/html`);
    }

    async loadEdit(id) {
        await this.loadInto(`/api/bookcase/${id}/edit`);
    }

    // ── Quick-add flow ───────────────────────────────────────────────────────

    async openCreate({ lat, lon, editable } = {}) {
        const params = new URLSearchParams();
        if (lat != null && lon != null) { params.set('lat', lat); params.set('lon', lon); }
        if (editable) params.set('editable', '1');

        await this.loadInto(`/api/bookcase/new?${params.toString()}`);
        if (!this.element.open) this.element.showModal();
    }

    async create() {
        const form = this.bodyTarget.querySelector('#create-form');
        if (!form) return;

        const errorBox = this.bodyTarget.querySelector('[data-edit-error]');
        const submitBtn = this.bodyTarget.querySelector('[data-modal-action="create"]');
        if (errorBox) errorBox.classList.add('hidden');
        if (submitBtn) submitBtn.disabled = true;

        try {
            const response = await fetch('/api/bookcase/create', { method: 'POST', body: new FormData(form) });
            const json = await response.json().catch(() => ({}));

            if (response.ok && json.id) {
                // Drop the marker on the map right away, then offer follow-up steps.
                document.dispatchEvent(new CustomEvent('bc:created', { detail: json }));
                this.renderCreated(json.id);
                return;
            }

            if (errorBox) {
                errorBox.textContent = json.errors || this.t('transCreateFailed', 'Could not add the bookcase. Please try again.');
                errorBox.classList.remove('hidden');
            }
        } catch {
            if (errorBox) {
                errorBox.textContent = this.t('transCreateFailed', 'Could not add the bookcase. Please try again.');
                errorBox.classList.remove('hidden');
            }
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    // Post-create confirmation: offer to add full details or photos (photos need
    // an existing entry, which now exists), or just finish.
    renderCreated(id) {
        this.bodyTarget.innerHTML =
            '<h3 class="mb-2 text-lg font-bold" data-created-title></h3>' +
            '<p class="mb-6 text-base-content/70" data-created-question></p>' +
            '<div class="flex flex-col gap-2 sm:flex-row sm:justify-end">' +
            `<button type="button" class="btn btn-ghost" data-modal-action="detail" data-bc-id="${id}" data-done></button>` +
            `<button type="button" class="btn btn-outline btn-primary" data-modal-action="photos" data-bc-id="${id}" data-add-photos></button>` +
            `<button type="button" class="btn btn-primary" data-modal-action="edit" data-bc-id="${id}" data-add-details></button>` +
            '</div>';

        this.bodyTarget.querySelector('[data-created-title]').textContent = this.t('transCreatedTitle', 'Bookcase added!');
        this.bodyTarget.querySelector('[data-created-question]').textContent = this.t('transCreatedQuestion', 'Do you want to add more details?');
        this.bodyTarget.querySelector('[data-done]').textContent = this.t('transDone', 'Done');
        this.bodyTarget.querySelector('[data-add-photos]').textContent = this.t('transAddPhotos', 'Add photos');
        this.bodyTarget.querySelector('[data-add-details]').textContent = this.t('transAddDetails', 'Add details');
    }

    // Paste of a "geo:lat,lon" string → fill the coordinate inputs.
    applyGeo(input) {
        const coords = parseGeo(input.value);
        if (coords) this.setCoordinates(coords.lat, coords.lon);
    }

    // Debounced address search in the create form → Photon suggestions.
    onAddressInput(input) {
        const query = input.value.trim();
        const list = this.bodyTarget.querySelector('[data-create-address-results]');
        clearTimeout(this.addrTimer);
        if (!list || query.length < 3) { if (list) list.classList.add('hidden'); return; }

        this.addrTimer = setTimeout(async () => {
            let features = [];
            try { features = await searchPhoton(query); } catch { /* ignore */ }

            list.innerHTML = '';
            features.forEach((f) => {
                const label = placeLabel(f.properties);
                if (!label) return;
                const li = document.createElement('li');
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.dataset.addrPick = '';
                btn.dataset.lat = f.geometry.coordinates[1];
                btn.dataset.lon = f.geometry.coordinates[0];
                btn.textContent = label;
                li.append(btn);
                list.append(li);
            });
            list.classList.toggle('hidden', list.children.length === 0);
        }, 300);
    }

    pickAddress(btn) {
        this.setCoordinates(parseFloat(btn.dataset.lat), parseFloat(btn.dataset.lon));
        const list = this.bodyTarget.querySelector('[data-create-address-results]');
        if (list) { list.innerHTML = ''; list.classList.add('hidden'); }
    }

    setCoordinates(lat, lon) {
        const latInput = this.bodyTarget.querySelector('#create-form [id$="position_latitude"]');
        const lonInput = this.bodyTarget.querySelector('#create-form [id$="position_longitude"]');
        if (latInput) latInput.value = lat;
        if (lonInput) lonInput.value = lon;
    }

    async loadInto(url) {
        this.setLoading();
        try {
            const response = await fetch(url);
            if (!response.ok) {
                this.showError(this.t('transCouldNotLoad', 'This bookcase could not be loaded.'));
                return;
            }
            this.bodyTarget.innerHTML = await response.text();
        } catch {
            this.showError(this.t('transSomethingWrong', 'Something went wrong. Please try again.'));
        }
    }

    showError(message) {
        const close = this.t('transClose', 'Close');
        this.bodyTarget.innerHTML =
            '<div class="alert alert-error my-2" role="alert"></div>' +
            '<div class="modal-action"><button type="button" class="btn btn-ghost" data-modal-action="close"></button></div>';
        this.bodyTarget.querySelector('.alert').textContent = message;
        this.bodyTarget.querySelector('[data-modal-action="close"]').textContent = close;
    }

    setLoading() {
        this.bodyTarget.innerHTML =
            '<div class="flex justify-center py-10"><span class="loading loading-spinner loading-lg"></span></div>';
    }

    async save(id) {
        const form = this.bodyTarget.querySelector('form[data-bc-id]');
        if (!form) return;

        const errorBox = this.bodyTarget.querySelector('[data-edit-error]');
        const submitBtn = this.bodyTarget.querySelector('[data-modal-action="save"]');
        if (errorBox) errorBox.classList.add('hidden');
        if (submitBtn) submitBtn.disabled = true;

        try {
            const response = await fetch(`/api/bookcase/${id}/save`, {
                method: 'POST',
                body: new FormData(form),
            });

            if (response.ok) {
                // Refresh the map marker (icon, popup, position) without a reload.
                const json = await response.json().catch(() => ({}));
                if (json.marker) {
                    document.dispatchEvent(new CustomEvent('bc:updated', { detail: json.marker }));
                }
                await this.loadDetail(id);
                return;
            }

            const json = await response.json().catch(() => ({}));
            if (errorBox) {
                errorBox.textContent = json.errors || this.t('transSavingFailed', 'Saving failed. Please try again.');
                errorBox.classList.remove('hidden');
            }
        } catch {
            if (errorBox) {
                errorBox.textContent = this.t('transSavingFailed', 'Saving failed. Please try again.');
                errorBox.classList.remove('hidden');
            }
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    }

    async deleteCase(id) {
        // The entry is soft-deleted (archived), so a reason is mandatory.
        const reason = (window.prompt(this.t('transDeleteReason', 'Why is this entry being deleted? A reason is required.')) || '').trim();
        if (!reason) return;

        const response = await fetch(`/api/bookcase/${id}`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reason }),
        });
        if (response.ok) {
            this.element.close();
            window.location.reload();
        } else {
            alert(this.t('transDeleteUnavailable', 'Deletion is not available.'));
        }
    }

    openPhotos(id) {
        document.dispatchEvent(new CustomEvent('bc:photos', { detail: { id } }));
    }

    openWishlist(id) {
        document.dispatchEvent(new CustomEvent('bc:wishlist', { detail: { id } }));
    }

    // Update the "Wishlist (N)" button count when the wishlist modal changes it.
    syncWishlistButton({ id, openCount } = {}) {
        const button = this.bodyTarget.querySelector('[data-modal-action="wishlist"]');
        if (button && button.dataset.bcId === id) {
            const label = button.querySelector('[data-wishlist-count]');
            if (label) label.textContent = openCount;
        }
    }

    // Add/remove this bookcase from the current user's watchlist and update the button.
    async toggleWatch(button) {
        const id = button.dataset.bcId;
        const watching = button.dataset.watching === '1';
        const method = watching ? 'DELETE' : 'POST';

        button.disabled = true;
        try {
            const response = await fetch(`/api/bookcase/${id}/watch`, { method });
            if (!response.ok) return;

            const json = await response.json().catch(() => ({}));
            const now = !!json.watching;
            this.reflectWatchState(button, now);

            // Let the profile modal update its watchlist live (no reload).
            const title = this.bodyTarget.querySelector('h3')?.textContent.trim();
            document.dispatchEvent(new CustomEvent('watchlist:changed', { detail: { id, title, watching: now } }));
        } finally {
            button.disabled = false;
        }
    }

    reflectWatchState(button, watching) {
        button.dataset.watching = watching ? '1' : '0';
        button.classList.toggle('btn-secondary', watching);
        button.classList.toggle('btn-outline', !watching);
        const label = button.querySelector('[data-watch-label]');
        if (label) label.textContent = watching
            ? (button.dataset.watchLabelOn || 'Watching')
            : (button.dataset.watchLabelOff || 'Watch');
    }

    // Keep the Watch button in sync when the state changes elsewhere (e.g. the
    // user removes the bookcase from their watchlist in the profile modal).
    syncWatchButton({ id, watching } = {}) {
        const button = this.bodyTarget.querySelector('[data-modal-action="toggle-watch"]');
        if (button && button.dataset.bcId === id) {
            this.reflectWatchState(button, !!watching);
        }
    }

    // Copy the shareable deep link to the clipboard and give brief feedback.
    async copyLink(button) {
        const input = this.bodyTarget.querySelector('[data-share-url]');
        const url = input ? input.value : button.dataset.shareUrl;
        if (!url) return;

        try {
            await navigator.clipboard.writeText(url);
        } catch {
            if (input) { input.select(); document.execCommand('copy'); }
        }

        const original = button.textContent;
        button.textContent = this.t('transCopied', 'Copied!');
        button.classList.add('btn-success');
        setTimeout(() => {
            button.textContent = original;
            button.classList.remove('btn-success');
        }, 1500);
    }

    // Save the current user's rating, then update the average display live.
    async saveRating(id, value, container) {
        const status = container.querySelector('[data-rating-status]');
        if (status) status.textContent = '';
        try {
            const response = await fetch(`/api/bookcase/${id}/rating`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `value=${encodeURIComponent(value)}`,
            });
            if (!response.ok) {
                if (status) status.textContent = this.t('transRatingFailed', 'Could not save rating');
                return;
            }
            const json = await response.json().catch(() => ({}));
            this.updateRatingDisplay(json);
            // Reflect the new average on the map marker's tooltip too.
            document.dispatchEvent(new CustomEvent('bc:rating', {
                detail: { id, average: json.average, count: json.count },
            }));
            if (status) {
                status.textContent = this.t('transSaved', 'Saved');
                setTimeout(() => { status.textContent = ''; }, 1500);
            }
        } catch {
            if (status) status.textContent = this.t('transRatingFailed', 'Could not save rating');
        }
    }

    // Reflect the new average on the read-only hearts shown in the view dialog.
    updateRatingDisplay({ rounded, average, count } = {}) {
        const display = this.bodyTarget.querySelector('[data-rating-display]');
        if (!display) return;
        display.querySelectorAll('.rating input').forEach((input, idx) => {
            input.checked = (idx + 1) === rounded;
        });
        display.title = count > 0 ? `${Number(average).toFixed(1)} / 5 (${count})` : 'No ratings yet';
    }

    // Clone the caretaker prototype, giving it the next collection index.
    addCaretaker() {
        const container = this.bodyTarget.querySelector('[data-caretaker-collection]');
        if (!container) return;
        const index = parseInt(container.dataset.index || '0', 10);
        container.insertAdjacentHTML('beforeend', container.dataset.prototype.replaceAll('__name__', index));
        container.dataset.index = index + 1;
    }
}
