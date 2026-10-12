document.querySelector('[data-mark-all-present]')?.addEventListener('click', () => {
    document.querySelectorAll('[data-attendance-status]').forEach((field) => { field.value = 'present'; });
});
