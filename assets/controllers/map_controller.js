import { Controller } from '@hotwired/stimulus';
import axios from 'axios';

export default class extends Controller {
    static values = { detailstrans: String };

    initialize() {
        super.initialize();

        this.map = L.map('map').setView([50.195, 8.8], 13);
        this.markerCluster = L.markerClusterGroup();
        this.loadedMarkers = [];

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(this.map);

        const bounds = this.getBoundingCoordinates(this.map.getBounds());

        this.latMin = bounds.latMin;
        this.latMax = bounds.latMax;
        this.lngMin = bounds.lngMin;
        this.lngMax = bounds.lngMax;

        this.map.addLayer(this.markerCluster);

        this.loadEntries();
    }

    connect() {
        this.map.on('moveend', this.loadEntries.bind(this));
    }

    loadEntries(e) {
        const bounds = this.getBoundingCoordinates(this.map.getBounds());

        if (!this.loadRequired(bounds) && this.loadedMarkers.length > 0) {
            return;
        }

        $('#loaderSpinner').removeClass('hidden');
        axios.get(`/api/bookcase?latMin=${bounds.latMin}&latMax=${bounds.latMax}&lonMin=${bounds.lngMin}&lonMax=${bounds.lngMax}`)
            .then((response) => {
                response.data.forEach((item) => {
                    if (this.loadedMarkers.includes(item.id)) {
                        return;
                    }
                    this.loadedMarkers.push(item.id);

                    const stdIcon = L.icon({
                        iconUrl: 'build/images/marker-icon.png',
                        shadowUrl: 'build/images/marker-shadow.png',
                        iconSize: [25, 41],
                        iconAnchor: [12, 41],
                    });

                    const tardisIcon = L.icon({
                        iconUrl: 'build/images/marker-icon-tardis.png',
                        iconSize: [25, 38],
                        iconAncor: [8, 38]
                    });

                    let newMarker = null;

                    if ('tardis' === item.mapSymbol) {
                        newMarker = L.marker([item.position.latitude, item.position.longitude], {
                            icon: tardisIcon
                        });
                    } else {
                        newMarker = L.marker([item.position.latitude, item.position.longitude], {
                            icon: stdIcon
                        });
                    }

                    const popup = L.popup({
                        offset: [0, -20]
                    }).setContent('<div class="text-center">' +
                        '<h6>' + item.title + '</h6>' +
                        '<button data-action="click->details#loadCase" data-controller="details" data-details-bcid-value="' + item.id + '" data-bs-target="#bookcaseDetails" data-bs-toggle="offcanvas" class="btn btn-primary py-2" role="button" aria-controls="bookcaseDetails">' + this.detailstransValue + '</button> ' +
                        '</div>'
                    );

                    newMarker.bindPopup(popup);

                    this.markerCluster.addLayer(newMarker);
                });
                $('#loaderSpinner').addClass('hidden');
            });
    }

    loadRequired(bounds) {

        if (bounds.latMin < this.latMin ||
            bounds.latMax > this.latMax ||
            bounds.lngMin < this.lngMin ||
            bounds.lngMax > this.lngMax) {

            this.lngMin = bounds.lngMin < this.lngMin ? bounds.lngMin : this.lngMin;
            this.lngMax = bounds.lngMax > this.lngMax ? bounds.lngMax : this.lngMax;
            this.latMin = bounds.latMin < this.latMin ? bounds.latMin : this.latMin;
            this.latMax = bounds.latMax > this.latMax ? bounds.latMax : this.latMax;

            return true;
        }

        return false;
    }

    getBoundingCoordinates(bounds) {
        const ne = bounds.getNorthEast();
        const sw = bounds.getSouthWest();

        return {
            latMin: sw.lat,
            latMax: ne.lat,
            lngMin: sw.lng,
            lngMax: ne.lng
        };
    }
}
