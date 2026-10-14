(() => {
    const app = document.getElementById('scenario-app');

    if (!app) {
        return;
    }

    const message = document.getElementById('scenario-message');

    if (!window.supabase) {
        message.textContent = 'Le module de connexion n’a pas été chargé.';
        return;
    }

    const client = window.supabase.createClient(
        app.dataset.url,
        app.dataset.key
    );

    let busy = false;

    app.querySelectorAll('.start-simulation').forEach((button) => {
        button.addEventListener('click', async () => {
            if (busy) {
                return;
            }

            busy = true;
            message.textContent = 'Création de la simulation…';

            app.querySelectorAll('button').forEach((item) => {
                item.disabled = true;
            });

            try {
                const { data, error } = await client.auth.getSession();

                if (error) {
                    throw error;
                }

                if (!data.session) {
                    window.location.assign('/compte');
                    return;
                }

                const response = await fetch(
                    '/api/simulations/start/' + button.dataset.scenarioId,
                    {
                        method: 'POST',

                        headers: {
                            Authorization:
                                'Bearer ' + data.session.access_token,
                        },
                    }
                );

                const result = await response.json();

                if (!response.ok) {
                    throw new Error(
                        result.error || 'Création impossible.'
                    );
                }

                if (!result.id) {
                    throw new Error('Identifiant de simulation absent.');
                }

                window.location.assign('/simulation/' + result.id);
            } catch (error) {
                message.textContent = error.message;
            } finally {
                busy = false;

                app.querySelectorAll('button').forEach((item) => {
                    item.disabled = false;
                });
            }
        });
    });
})();