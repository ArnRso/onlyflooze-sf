import './stimulus_bootstrap.js';
import '@hotwired/turbo';
import 'bootstrap';
import 'bootstrap/dist/css/bootstrap.min.css';
import '@fortawesome/fontawesome-free/css/fontawesome.min.css';
import '@fortawesome/fontawesome-free/css/solid.min.css';
import './styles/app.css';

// Derrière le SSO Pangolin, une session expirée transforme chaque requête en
// redirection cross-origin que fetch ne peut pas suivre (« Failed to fetch »).
// Dans ce cas, un rechargement complet déclenche la redirection SSO normale.
document.addEventListener('turbo:fetch-request-error', (event) => {
    event.preventDefault();
    window.location.reload();
});
