const appearanceQuery = window.matchMedia('(prefers-color-scheme: dark)');

const currentAppearance = () => localStorage.getItem('appearance') || 'system';

const applyAppearance = (appearance = currentAppearance()) => {
    const dark = appearance === 'dark' || (appearance === 'system' && appearanceQuery.matches);

    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.dataset.appearance = appearance;
};

window.Appearance = {
    get: currentAppearance,
    set(appearance) {
        localStorage.setItem('appearance', appearance);
        applyAppearance(appearance);
        window.dispatchEvent(new CustomEvent('appearance-changed', { detail: appearance }));
    },
};

appearanceQuery.addEventListener('change', () => {
    if (currentAppearance() === 'system') applyAppearance();
});

document.addEventListener('livewire:navigated', () => applyAppearance());
applyAppearance();
