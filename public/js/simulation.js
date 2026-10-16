(() => {
    const app = document.getElementById('simulation-app');

    if (!app) {
        console.warn('Simulation : #simulation-app introuvable.');
        return;
    }

    const title = document.getElementById('simulation-title');
    const briefCard = document.getElementById('simulation-brief');
    const briefText = document.getElementById('simulation-brief-text');

    const sceneCard = document.getElementById('simulation-scene');
    const sceneImage = document.getElementById('simulation-scene-image');

    const message = document.getElementById('simulation-message');
    const content = document.getElementById('simulation-content');
    const status = document.getElementById('simulation-status');
    const transcript = document.getElementById('simulation-transcript');

    const form = document.getElementById('simulation-form');
    const input = document.getElementById('simulation-text');
    const sendButton = document.getElementById('simulation-send');
    const modeLabel = document.getElementById('simulation-mode-label');

    const scoreCard = document.getElementById('simulation-score');
    const scoreTotal = document.getElementById('simulation-score-total');
    const scoreBar = document.getElementById('simulation-score-bar');
    const scorePrimary = document.getElementById('simulation-score-primary');
    const scoreHemorrhage = document.getElementById('simulation-score-hemorrhage');

    const missingCard = document.getElementById('simulation-missing');
    const missingList = document.getElementById('simulation-missing-list');

    let current = null;
    let busy = false;
    let currentMode = 'question';
    let client = null;

    function showMessage(text, type = 'info') {
        if (!message) {
            return;
        }

        message.className = `alert alert-${type}`;
        message.classList.remove('d-none');
        message.textContent = text;
    }

    function hideMessage() {
        if (!message) {
            return;
        }

        message.classList.add('d-none');
        message.textContent = '';
    }

    function setFormDisabled(disabled) {
        if (sendButton) {
            sendButton.disabled = disabled;
        }

        if (input) {
            input.disabled = disabled;
        }
    }

    function updateModeInterface() {
        document.querySelectorAll('[data-mode]').forEach((button) => {
            button.classList.toggle(
                'active',
                button.dataset.mode === currentMode
            );
        });

        if (modeLabel) {
            modeLabel.textContent = currentMode === 'question'
                ? 'Poser une question'
                : 'Réaliser un geste';

            modeLabel.className = currentMode === 'question'
                ? 'fw-semibold text-primary'
                : 'fw-semibold text-danger';
        }

        if (input) {
            input.placeholder = currentMode === 'question'
                ? 'Exemple : Bonjour, que s’est-il passé ?'
                : 'Exemple : Je réalise le bilan de la victime.';
        }
    }

    async function getSession() {
        const { data, error } = await client.auth.getSession();

        if (error) {
            throw error;
        }

        if (!data.session) {
            throw new Error(
                'Vous devez être connecté pour accéder à cette simulation.'
            );
        }

        return data.session;
    }

    async function requestSimulation(method = 'GET', body = null) {
        const session = await getSession();

        let url = `/api/simulations/${app.dataset.sessionId}`;

        if (method === 'POST') {
            url += '/messages';
        }

        const options = {
            method,
            headers: {
                'Authorization': `Bearer ${session.access_token}`,
                'Content-Type': 'application/json',
            },
        };

        if (body !== null) {
            options.body = JSON.stringify(body);
        }

        const response = await fetch(url, options);
        const result = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(
                result.error ||
                'Erreur lors de la communication avec le serveur.'
            );
        }

        return result;
    }

    function createMessageCard(role, text) {
        const card = document.createElement('article');

        card.className = role === 'learner'
            ? 'alert alert-danger'
            : 'alert alert-info';

        const author = document.createElement('div');
        author.className = 'fw-semibold small mb-1';
        author.textContent = role === 'learner'
            ? 'Vous'
            : 'Simulation';

        const content = document.createElement('p');
        content.className = 'mb-0';
        content.textContent = text;

        card.append(author, content);

        return card;
    }

    function renderSceneImage(simulation) {
        if (!sceneCard || !sceneImage) {
            return;
        }

        if (!simulation.image) {
            sceneCard.classList.add('d-none');
            sceneImage.removeAttribute('src');
            return;
        }

        const newSrc = simulation.image.startsWith('/')
            ? simulation.image
            : '/' + simulation.image;

        if (sceneImage.getAttribute('src') !== newSrc) {
            sceneImage.src = newSrc;
        }

        sceneCard.classList.remove('d-none');
    }

    function renderLastExchange(simulation) {
        transcript.replaceChildren();

        const lastExchange = simulation.last_exchange ?? {};

        if (lastExchange.learner?.content) {
            transcript.append(
                createMessageCard(
                    'learner',
                    lastExchange.learner.content
                )
            );
        }

        if (lastExchange.simulator?.content) {
            transcript.append(
                createMessageCard(
                    'simulator',
                    lastExchange.simulator.content
                )
            );
        }

        transcript.scrollTop = transcript.scrollHeight;
    }

    function renderScore(score) {
        if (!score || !scoreCard) {
            return;
        }

        scoreCard.classList.remove('d-none');

        if (scoreTotal) {
            scoreTotal.textContent = `${score.total} / ${score.max}`;
        }

        const percent = Math.min(
            100,
            Math.round((score.total / score.max) * 100)
        );

        if (scoreBar) {
            scoreBar.style.width = `${percent}%`;
            scoreBar.setAttribute('aria-valuenow', percent);
        }

        if (scorePrimary) {
            scorePrimary.textContent =
                `${score.primary_assessment.earned} / ` +
                `${score.primary_assessment.max} points`;
        }

        if (scoreHemorrhage) {
            scoreHemorrhage.textContent =
                `${score.hemorrhage_management.earned} / ` +
                `${score.hemorrhage_management.max} points`;
        }
    }

    function renderMissingActions(missingActions) {
        if (!missingCard || !missingList) {
            return;
        }

        missingList.replaceChildren();

        if (!missingActions || missingActions.length === 0) {
            missingCard.classList.add('d-none');
            return;
        }

        missingCard.classList.remove('d-none');

        missingActions.forEach((action) => {
            const item = document.createElement('li');
            item.className = 'd-flex align-items-start gap-2 mb-2';

            const icon = document.createElement('i');
            icon.className = 'bi bi-x-circle-fill text-danger mt-1';

            const text = document.createElement('span');
            text.textContent = action.label;

            item.append(icon, text);
            missingList.append(item);
        });
    }

    function render(simulation) {
        current = simulation;

        title.textContent = simulation.title;

        if (briefCard && briefText) {
            briefText.textContent = simulation.brief;
            briefCard.classList.remove('d-none');
        }

        renderSceneImage(simulation);

        status.textContent = simulation.status === 'active'
            ? 'Statut : en cours'
            : 'Statut : terminée';

        renderLastExchange(simulation);

        content.hidden = false;
        form.hidden = simulation.status !== 'active';

        if (simulation.status !== 'active') {
            renderScore(simulation.score);
            renderMissingActions(simulation.missing_actions);

            showMessage(
                'Simulation terminée. Consultez votre bilan, votre score et les éléments manquants.',
                'success'
            );
        } else {
            hideMessage();
        }
    }

    async function loadSimulation() {
        title.textContent = 'Chargement de la simulation…';
        showMessage('Chargement de la simulation…', 'info');

        try {
            const simulation = await requestSimulation('GET');

            hideMessage();
            render(simulation);
        } catch (error) {
            title.textContent = 'Simulation indisponible';
            showMessage(
                error.message || 'Impossible de charger la simulation.',
                'danger'
            );
        }
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-mode]');

        if (!button || !app.contains(button)) {
            return;
        }

        currentMode = button.dataset.mode;
        updateModeInterface();
        input.focus();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (busy || !current) {
            return;
        }

        const text = input.value.trim();

        if (!text) {
            return;
        }

        busy = true;
        setFormDisabled(true);

        showMessage('Analyse en cours…', 'info');

        try {
            const updated = await requestSimulation('POST', {
                text,
                revision: current.revision,
                mode: currentMode,
            });

            render(updated);

            input.value = '';
            hideMessage();
        } catch (error) {
            showMessage(
                error.message || 'Erreur lors de l’envoi du message.',
                'danger'
            );
        } finally {
            busy = false;
            setFormDisabled(false);
            input.focus();
        }
    });

    if (!window.supabase) {
        title.textContent = 'Simulation indisponible';
        showMessage('Le module Supabase ne s’est pas chargé.', 'danger');
        console.error('Simulation : window.supabase est indisponible.');
        return;
    }

    client = window.supabase.createClient(
        app.dataset.url,
        app.dataset.key
    );

    updateModeInterface();
    loadSimulation();
})();