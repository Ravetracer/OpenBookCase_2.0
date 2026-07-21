import { Controller } from '@hotwired/stimulus';

/*
 * Custom, engine-independent scroll affordance for the bookcase modal.
 *
 * Firefox/LibreWolf and iOS Safari can't be CSS-forced to keep a NATIVE
 * scrollbar visible when the OS uses overlay scrollbars, so this draws its own:
 * a draggable thumb that mirrors the viewport's scroll position, plus soft
 * top/bottom fade shadows. Lives on the permanent .modal-box shell; the inner
 * `viewport` target is the real scroll container whose content the
 * bookcase-modal controller swaps in — recomputes on scroll, resize and content
 * changes. See the .obc-* rules in app.css.
 */
export default class extends Controller {
    static targets = ['viewport', 'thumb'];

    connect() {
        this.update = this.update.bind(this);
        this.onThumbPointerDown = this.onThumbPointerDown.bind(this);

        this.viewportTarget.addEventListener('scroll', this.update, { passive: true });
        this.thumbTarget.addEventListener('pointerdown', this.onThumbPointerDown);

        this.resizeObserver = new ResizeObserver(this.update);
        this.resizeObserver.observe(this.viewportTarget);

        // The detail/edit/quick-add HTML is injected asynchronously — recompute
        // whenever the viewport's content changes.
        this.mutationObserver = new MutationObserver(this.update);
        this.mutationObserver.observe(this.viewportTarget, { childList: true, subtree: true });

        this.update();
    }

    disconnect() {
        this.viewportTarget.removeEventListener('scroll', this.update);
        this.thumbTarget.removeEventListener('pointerdown', this.onThumbPointerDown);
        this.resizeObserver?.disconnect();
        this.mutationObserver?.disconnect();
    }

    update() {
        const vp = this.viewportTarget;
        const scrollable = vp.scrollHeight - vp.clientHeight;
        const isScrollable = scrollable > 1;

        this.element.classList.toggle('is-scrollable', isScrollable);
        this.element.classList.toggle('can-scroll-up', isScrollable && vp.scrollTop > 1);
        this.element.classList.toggle('can-scroll-down', isScrollable && vp.scrollTop < scrollable - 1);

        if (!isScrollable) return;

        const trackH = this.thumbTarget.parentElement.clientHeight;
        const thumbH = Math.max(trackH * (vp.clientHeight / vp.scrollHeight), 24);
        const maxThumbTop = trackH - thumbH;
        const top = maxThumbTop > 0 ? (vp.scrollTop / scrollable) * maxThumbTop : 0;

        this.thumbTarget.style.height = `${thumbH}px`;
        this.thumbTarget.style.transform = `translateY(${top}px)`;
    }

    onThumbPointerDown(event) {
        event.preventDefault();
        const vp = this.viewportTarget;
        const scrollable = vp.scrollHeight - vp.clientHeight;
        const trackH = this.thumbTarget.parentElement.clientHeight;
        const maxThumbTop = trackH - this.thumbTarget.offsetHeight;
        const startY = event.clientY;
        const startScroll = vp.scrollTop;

        const onMove = (e) => {
            const delta = e.clientY - startY;
            vp.scrollTop = startScroll + (maxThumbTop > 0 ? (delta / maxThumbTop) * scrollable : 0);
        };
        const onUp = () => {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
        };
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
    }
}
