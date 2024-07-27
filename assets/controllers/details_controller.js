import { Controller } from '@hotwired/stimulus';
import axios from 'axios';

/*
 * This is an example Stimulus controller!
 *
 * Any element with a data-controller="hello" attribute will cause
 * this controller to be executed. The name "hello" comes from the filename:
 * hello_controller.js -> "hello"
 *
 * Delete this file or adapt it for your use!
 */
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
        fetch(`/api/bookcase/${this.bcidValue}`)
            .then((response) => response.json())
            .then((data) => {
                console.log(data);
                document.getElementById('bookcaseDetailsLabel').textContent = data.title;
                document.getElementById('view_bc_type').textContent = data.entryType;
                document.getElementById('view_bc_instType').textContent = data.installationType;
                document.getElementById('view_bc_lat').textContent = data.position.latitude;
                document.getElementById('view_bc_lon').textContent = data.position.longitude;

                // remove existing images
                document.getElementById('bookcase_images_items').innerHTML = '';

                if (data.images.length > 0) {
                    let cnt = 1;
                    data.images.forEach((item) => {
                        const newItem = document.createElement('div');
                        newItem.className = 'carousel-item';
                        if (cnt === 1) {
                            newItem.className += ' active';
                        }

                        const newImage = document.createElement('img');
                        newImage.className = 'd-block w-100';
                        newImage.src = `images/${item.filename}`;
                        newImage.alt = `Bookcase image. Author: ${item.author}`;

                        newItem.append(newImage);

                        document.getElementById('bookcase_images_items').append(newItem);
                        cnt++;
                    });

                    if (data.images.length === 1) {
                        let buttons = document.getElementById('imageControls').getElementsByTagName('button');
                        for (let buttonItem of buttons) {
                            buttonItem.style.visibility = 'hidden';
                        }
                    }
                }
            }
        );
    }
}
