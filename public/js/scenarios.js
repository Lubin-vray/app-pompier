(() => {
    const app = document.getElementById('scenario-app');

    if (!app) {
        return;
    }

    const message = document.getElementById('scenario-message');

    if (!window.supabase) {
        showMessage('Le module de connexion n’a pas été chargé.', 'danger');
        return;
    }

    const client = window.supabase.createClient(
        app.dataset.url,
        app.dataset.key
    );

    let busy = false;

    function showMessage(text, type = 'info') {
        message.className = `alert alert-${type}`;
        message.classList.remove('d-none');
        message.textContent = text;
    }

    function hideMessage() {
        message.classList.add('d-none');
        message.textContent = '';
    }

    function setButtonsDisabled(disabled) {
        app.querySelectorAll('.start-simulation').forEach((button) => {
            button.disabled = disabled;
        });
    }

    async function getSession() {
        const { data, error } = await client.auth.getSession();

        if (error) {
            throw error;
        }

        if (!data.session) {
            return null;
        }

        return data.session;
    }

    async function startSimulation(button) {
        const scenarioId = button.dataset.scenarioId;

        if (!scenarioId) {
            showMessage('Identifiant de scénario manquant.', 'danger');
            return;
        }

        busy = true;
        setButtonsDisabled(true);

        showMessage('Création de la simulation…', 'info');

        try {
            const session = await getSession();

            if (!session) {
                window.location.assign(app.dataset.accountUrl || '/compte');
                return;
            }

            const response = await fetch(
                `/api/simulations/start/${scenarioId}`,
                {
                    method: 'POST',
                    headers: {
                        'Authorization': `Bearer ${session.access_token}`,
                        'Content-Type': 'application/json',
                    },
                }
            );

            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(
                    result.error || 'Impossible de créer la simulation.'
                );
            }

            if (!result.id) {
                throw new Error('Identifiant de simulation absent.');
            }

            window.location.assign(`/simulation/${result.id}`);
        } catch (error) {
            showMessage(
                error.message || 'Une erreur est survenue.',
                'danger'
            );
        } finally {
            busy = false;
            setButtonsDisabled(false);
        }
    }

    app.querySelectorAll('.start-simulation').forEach((button) => {
        button.addEventListener('click', () => {
            if (busy) {
                return;
            }

            startSimulation(button);
        });
    });
})();