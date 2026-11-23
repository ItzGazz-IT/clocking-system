'use strict';

const menuButton = document.querySelector('.mobile-menu');
const menuOverlay = document.querySelector('.sidebar-overlay');

const closeNavigation = () => {
    document.body.classList.remove('nav-open');
    menuButton?.setAttribute('aria-expanded', 'false');
};

menuButton?.addEventListener('click', () => {
    const isOpen = document.body.classList.toggle('nav-open');
    menuButton.setAttribute('aria-expanded', String(isOpen));
});

menuOverlay?.addEventListener('click', closeNavigation);
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeNavigation();
});

document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const button = event.submitter;
        if (button instanceof HTMLButtonElement) {
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        }
    });
});

document.getElementById('employee_id')?.addEventListener('change', function () {
    const selected = this.options[this.selectedIndex];
    const username = document.getElementById('username');
    if (username && !username.value) username.value = selected.dataset.username || '';
});
