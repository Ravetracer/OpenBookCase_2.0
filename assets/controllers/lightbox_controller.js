import { Controller } from '@hotwired/stimulus';

/*
 * Lives on the permanent <dialog id="lightboxModal">. Opens any image marked
 * with `data-lightbox="<group>"` in a large overlay with prev/next navigation.
 *
 * Because bookcase photos are rendered inside *injected* fragments (the detail
 * carousel in #bookcaseModal, the grid in #photoModal), the clickable images
 * carry only plain `data-lightbox*` attributes and are handled here via a
 * delegated click/keydown listener on `document` — no Stimulus on injected nodes
 * (see the feedback-stimulus-dynamic-content rule). The lightbox stacks on top
 * of those dialogs via the browser's top-layer (both use showModal()).
 *
 * Per clickable image:
 *   data-lightbox="<group>"        groups images that page through together
 *   data-lightbox-caption="…"      optional caption (e.g. photo credit)
 * The full-size src and alt are read from the image element itself, so a
 * rotated photo in the manager shows its current orientation.
 */
export default class extends Controller {
    static targets = ['image', 'caption', 'counter', 'prev', 'next'];

    t(key, fallback) {
        return this.element.dataset[key] || fallback;
    }

    connect() {
        this.items = [];
        this.index = 0;

        this.onDocumentClick = (event) => {
            const trigger = event.target.closest('[data-lightbox]');
            if (!trigger) return;
            event.preventDefault();
            this.openFrom(trigger);
        };
        document.addEventListener('click', this.onDocumentClick);

        // Keyboard activation of an image (role="button").
        this.onDocumentKeydown = (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            const trigger = event.target.closest('[data-lightbox]');
            if (!trigger) return;
            event.preventDefault();
            this.openFrom(trigger);
        };
        document.addEventListener('keydown', this.onDocumentKeydown);

        // Arrow keys page through while the lightbox is open.
        this.onKeydown = (event) => {
            if (!this.element.open) return;
            if (event.key === 'ArrowLeft') { event.preventDefault(); this.prev(); }
            else if (event.key === 'ArrowRight') { event.preventDefault(); this.next(); }
        };
        this.element.addEventListener('keydown', this.onKeydown);

        // Swipe to page through on touch devices.
        this.touchX = null;
        this.onTouchStart = (event) => { this.touchX = event.changedTouches[0].clientX; };
        this.onTouchEnd = (event) => {
            if (this.touchX === null) return;
            const dx = event.changedTouches[0].clientX - this.touchX;
            this.touchX = null;
            if (Math.abs(dx) < 40) return;
            if (dx > 0) this.prev(); else this.next();
        };
        if (this.hasImageTarget) {
            this.imageTarget.addEventListener('touchstart', this.onTouchStart, { passive: true });
            this.imageTarget.addEventListener('touchend', this.onTouchEnd, { passive: true });
        }
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onDocumentKeydown);
        this.element.removeEventListener('keydown', this.onKeydown);
    }

    // Collect every image sharing the clicked image's group, in DOM order.
    openFrom(trigger) {
        const group = trigger.dataset.lightbox;
        const nodes = Array.from(document.querySelectorAll(`[data-lightbox="${CSS.escape(group)}"]`));
        this.items = nodes.map((el) => ({
            src: el.currentSrc || el.src || el.dataset.lightboxSrc || '',
            alt: el.getAttribute('alt') || '',
            caption: el.dataset.lightboxCaption || '',
        }));
        this.index = Math.max(0, nodes.indexOf(trigger));
        if (!this.items.length) return;

        this.render();
        if (!this.element.open) this.element.showModal();
    }

    prev() {
        if (this.items.length < 2) return;
        this.index = (this.index - 1 + this.items.length) % this.items.length;
        this.render();
    }

    next() {
        if (this.items.length < 2) return;
        this.index = (this.index + 1) % this.items.length;
        this.render();
    }

    close() {
        if (this.element.open) this.element.close();
    }

    render() {
        const item = this.items[this.index];
        if (!item) return;

        if (this.hasImageTarget) {
            this.imageTarget.src = item.src;
            this.imageTarget.alt = item.alt;
        }
        if (this.hasCaptionTarget) {
            this.captionTarget.textContent = item.caption;
            this.captionTarget.classList.toggle('hidden', !item.caption);
        }

        const multiple = this.items.length > 1;
        if (this.hasCounterTarget) {
            this.counterTarget.textContent = `${this.index + 1} / ${this.items.length}`;
            this.counterTarget.classList.toggle('hidden', !multiple);
        }
        if (this.hasPrevTarget) this.prevTarget.classList.toggle('hidden', !multiple);
        if (this.hasNextTarget) this.nextTarget.classList.toggle('hidden', !multiple);
    }
}
