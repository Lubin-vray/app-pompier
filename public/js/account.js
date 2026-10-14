(() => {
    const app = document.getElementById('account-app');

    if (!app) {
        return;
    }

    const loading = document.getElementById('account-loading');
    const authPanel = document.getElementById('account-auth');
    const connectedPanel = document.getElementById('account-connected');
    const message = document.getElementById('account-message');

    const form = document.getElementById('account-form');
    const emailInput = document.getElementById('account-email');
    const passwordInput = document.getElementById('account-password');

    const registerButton = document.getElementById('account-register');
    const logoutButton = document.getElementById('account-logout');
    const userEmail = document.getElementById('account-user-email');

    let busy = false;

    function showMessage(text, isError = false) {
        message.textContent = text;
        message.className = isError
            ? 'account-message error'
            : 'account-message success';
    }

    if (!window.supabase) {
        loading.hidden = true;

        showMessage(
            'Impossible de charger le module de connexion. ' +
            'Vérifiez votre connexion internet et rechargez la page.',
            true
        );

        return;
    }

    const client = window.supabase.createClient(
        app.dataset.supabaseUrl,
        app.dataset.supabaseKey
    );

    function displaySession(session) {
        loading.hidden = true;

        const connected = Boolean(session?.user);

        authPanel.hidden = connected;
        connectedPanel.hidden = !connected;

        userEmail.textContent = session?.user?.email ?? '';
    }

    async function execute(action) {
        if (busy) {
            return;
        }

        busy = true;
        showMessage('');

        app.querySelectorAll('button').forEach((button) => {
            button.disabled = true;
        });

        try {
            await action();
        } catch (error) {
            showMessage(
                error.message || 'Une erreur est survenue.',
                true
            );
        } finally {
            busy = false;

            app.querySelectorAll('button').forEach((button) => {
                button.disabled = false;
            });
        }
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        execute(async () => {
            const { data, error } = await client.auth.signInWithPassword({
                email: emailInput.value.trim(),
                password: passwordInput.value,
            });

            if (error) {
                throw error;
            }

            if (!data.session) {
                throw new Error(
                    'La connexion n’a pas fourni de session utilisateur.'
                );
            }

            passwordInput.value = '';

            displaySession(data.session);
            showMessage('Connexion réussie.');
        });
    });

    registerButton.addEventListener('click', () => {
        if (!form.reportValidity()) {
            return;
        }

        execute(async () => {
            const { data, error } = await client.auth.signUp({
                email: emailInput.value.trim(),
                password: passwordInput.value,

                options: {
                    emailRedirectTo: window.location.origin + '/compte',
                },
            });

            if (error) {
                throw error;
            }

            passwordInput.value = '';

            if (data.session) {
                displaySession(data.session);
                showMessage('Votre session est ouverte.');
            } else {
                showMessage(
                    'Demande d’inscription envoyée. ' +
                    'Si l’inscription est acceptée et nécessite une ' +
                    'confirmation, consultez votre email puis connectez-vous.'
                );
            }
        });
    });

    logoutButton.addEventListener('click', () => {
        execute(async () => {
            const { error } = await client.auth.signOut();

            if (error) {
                throw error;
            }

            displaySession(null);
            showMessage('Vous êtes déconnecté.');
        });
    });

    client.auth.onAuthStateChange((_event, session) => {
        displaySession(session);
    });

    execute(async () => {
        const { data, error } = await client.auth.getSession();

        if (error) {
            throw error;
        }

        displaySession(data.session);
    });
})();