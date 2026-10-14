(async () => {
    const app = document.getElementById('simulation-app');

    if (!app) {
        return;
    }

    const title = document.getElementById('simulation-title');
    const message = document.getElementById('simulation-message');
    const content = document.getElementById('simulation-content');
    const status = document.getElementById('simulation-status');
    const transcript = document.getElementById('simulation-transcript');

    const form = document.getElementById('simulation-form');
    const input = document.getElementById('simulation-text');
    const sendButton = document.getElementById('simulation-send');

    let current = null;
    let busy = false;

    if (!window.supabase) {
        title.textContent = 'Simulation indisponible';
        message.textContent = 'Le module Supabase ne s’est pas chargé.';
        return;
    }

    const client = window.supabase.createClient(
        app.dataset.url,
        app.dataset.key
    );

    async function api(method = 'GET', body = null) {
        const { data, error } = await client.auth.getSession();

        if (error) {
            throw error;
        }

        if (!data.session) {
            throw new Error('Reconnectez-vous sur la page Mon compte.');
        }

        let url = '/api/simulations/' + app.dataset.sessionId;

        if (method === 'POST') {
            url += '/messages';
        }

        const options = {
            method,

            headers: {
                Authorization: 'Bearer ' + data.session.access_token,
                'Content-Type': 'application/json',
            },
        };

        if (body !== null) {
            options.body = JSON.stringify(body);
        }

        const response = await fetch(url, options);
        const result = await response.json();

        if (!response.ok) {
            throw new Error(result.error || 'Erreur serveur.');
        }

        return result;
    }

    function render(simulation) {
        current = simulation;

        title.textContent = simulation.title;

        status.textContent = simulation.status === 'active'
            ? 'Statut : en cours'
            : 'Statut : terminée';

        transcript.replaceChildren();

        for (const item of simulation.transcript) {
            const card = document.createElement('article');

            card.className = item.role === 'learner'
                ? 'chat-message chat-message-learner'
                : 'chat-message';

            const author = document.createElement('small');

            author.textContent = item.role === 'learner'
                ? 'Vous'
                : 'Simulation';

            const text = document.createElement('p');
            text.textContent = item.content;

            card.append(author, text);
            transcript.append(card);
        }

        content.hidden = false;
        form.hidden = simulation.status !== 'active';

        transcript.scrollTop = transcript.scrollHeight;
    }

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
        sendButton.disabled = true;
        input.disabled = true;
        message.textContent = 'Analyse en cours…';

        try {
            const updated = await api('POST', {
                text,
                revision: current.revision,
            });

            render(updated);

            input.value = '';
            message.textContent = '';
        } catch (error) {
            message.textContent = error.message;
        } finally {
            busy = false;
            sendButton.disabled = false;
            input.disabled = false;
            input.focus();
        }
    });

    try {
        render(await api());
    } catch (error) {
        title.textContent = 'Simulation indisponible';
        message.textContent = error.message;
    }
})();