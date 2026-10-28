// Chamada SIM/NÃO: "Todos SIM" marca quem ainda está em branco ou como NÃO, e o contador acompanha as marcações.
const counter = document.querySelector('[data-attendance-count]');
const choices = () => [...document.querySelectorAll('[data-attendance-choice]')];
const refresh = () => {
    if (!counter) return;
    const marked = choices().filter((group) => group.querySelector('input:checked')).length;
    counter.querySelector('strong').textContent = `${marked} de ${counter.dataset.total}`;
};
document.querySelector('[data-mark-all-present]')?.addEventListener('click', () => {
    choices().forEach((group) => { const yes = group.querySelector('input[value="present"]'); if (yes) yes.checked = true; });
    refresh();
});
document.addEventListener('change', (event) => { if (event.target.closest('[data-attendance-choice]')) refresh(); });
