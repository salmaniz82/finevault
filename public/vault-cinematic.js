(() => {
    const scene = document.querySelector('.cinematic-preview');
    if (!scene || !window.fetch) return;
    const form = scene.querySelector('form');
    const button = form.querySelector('[type=submit]');
    const video = scene.querySelector('video');
    const skip = scene.querySelector('.unlock-skip');
    const motion = matchMedia('(prefers-reduced-motion: reduce)');
    const error = document.createElement('div');
    error.className = 'vault-login-error';
    error.setAttribute('role', 'alert');
    error.hidden = true;
    form.before(error);
    let destination;
    let navigating = false;
    let timer;

    function enter(fade = false) {
        if (!destination || navigating) return;
        navigating = true;
        clearTimeout(timer);
        if (fade && !motion.matches) {
            scene.classList.add('leaving');
            try { sessionStorage.setItem('vault-arrival', String(Date.now())); } catch {}
            setTimeout(() => location.replace(destination), 160);
        } else {
            location.replace(destination);
        }
    }
    skip.addEventListener('click', () => enter());
    video.addEventListener('ended', () => enter(true));
    video.addEventListener('error', () => enter());
    motion.addEventListener('change', () => { if (motion.matches) enter(); });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled) return;
        const data = new FormData(form);
        button.disabled = true;
        form.setAttribute('aria-busy', 'true');
        error.hidden = true;
        if (!motion.matches) {
            video.preload = 'auto';
            video.src = matchMedia('(orientation: portrait)').matches ? '/media/vault/mobile.mp4' : '/media/vault/desktop.mp4';
            video.load();
        }
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                const payload = await response.json().catch(() => ({}));
                const message = response.status === 419 ? 'Refresh the page and sign in again.'
                    : response.status === 429 ? 'Too many attempts. Please try again shortly.'
                    : Object.values(payload.errors || {}).flat()[0] || 'Sign-in failed. Please try again.';
                throw new Error(message);
            }
            const payload = await response.json();
            const target = new URL(payload.destination, location.origin);
            if (!payload.destination || target.origin !== location.origin) throw new Error('Please reload the portal.');
            destination = target.href;
        } catch (failure) {
            form.elements.password.value = '';
            form.elements.password.dispatchEvent(new Event('input'));
            error.textContent = failure.message;
            error.hidden = false;
            button.disabled = false;
            form.removeAttribute('aria-busy');
            form.elements.password.focus();
            return;
        }
        if (motion.matches || video.error) return enter();
        skip.hidden = false;
        skip.focus();
        timer = setTimeout(enter, 650);
        try {
            await video.play();
            if (navigating) return;
            clearTimeout(timer);
            scene.classList.add('unlocking');
            scene.querySelector('.vault-login-content').inert = true;
            timer = setTimeout(enter, 3200);
        } catch { enter(); }
    });
})();
