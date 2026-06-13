import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        bcid: String
    }

    initialize() {
        super.initialize();

        this.detailsWindows = $('#bookcaseDetails');
    }

    connect() {
    }

    disconnect() {
        this.detailsWindows.off('show.bs.offcanvas');
    }

    loadCase() {
        document.getElementById('bookcaseDetails').innerHTML = '';
        fetch(`/api/bookcase/${this.bcidValue}/html`)
            .then((response) => response.text())
            .then(html => document.getElementById('bookcaseDetails').innerHTML = html)
    }

    editCase() {
        alert('Editing bookcase with ID ' + this.bcidValue);
    }
}
