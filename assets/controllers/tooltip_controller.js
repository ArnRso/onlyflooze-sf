import { Controller } from '@hotwired/stimulus';
import { Tooltip } from 'bootstrap';

// Infobulle Bootstrap sur l'élément porteur (title ou data-bs-title) : la
// version native de <abbr title> est trop lente et absente au toucher.
export default class extends Controller {
    connect() {
        this.tooltip = new Tooltip(this.element);
    }

    disconnect() {
        this.tooltip?.dispose();
    }
}
