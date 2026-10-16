const config = document.querySelector('meta[name="mkm-pwa"]');
if (config) {
    const buttons = [...document.querySelectorAll('[data-pwa-install]')];
    const dialog = document.querySelector('[data-pwa-help]');
    let installPrompt;
    const standalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    const updateButtons = () => buttons.forEach(button => { button.hidden = standalone(); });
    updateButtons();
    matchMedia('(display-mode: standalone)').addEventListener('change', updateButtons);
    if ('serviceWorker' in navigator && window.isSecureContext && location.pathname.startsWith(new URL(config.dataset.scope).pathname)) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register(config.dataset.worker, { scope: config.dataset.scope, updateViaCache: 'none' })
                .catch(() => { config.dataset.workerStatus = 'unavailable'; });
        });
    }
    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        installPrompt = event;
    });
    window.addEventListener('appinstalled', () => {
        installPrompt = null;
        buttons.forEach(button => { button.hidden = true; });
    });
    buttons.forEach(button => button.addEventListener('click', async () => {
        if (!location.pathname.startsWith(new URL(config.dataset.scope).pathname)) {
            location.assign(config.dataset.install);
            return;
        }
        if (installPrompt) {
            const prompt = installPrompt;
            installPrompt = null;
            await prompt.prompt();
            await prompt.userChoice;
            return;
        }
        if (dialog) {
            dialog.querySelector('[data-pwa-message]').textContent = window.isSecureContext
                ? `Install ${config.dataset.name} from your browser. If it is already installed, open it from your home screen or app launcher.`
                : 'This address is not a secure installation context. Open the shared HTTPS address, or use localhost on this computer for testing.';
            dialog.showModal();
        }
    }));
    document.querySelectorAll('[data-pwa-close]').forEach(button => button.addEventListener('click', () => dialog?.close()));
}
