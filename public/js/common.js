document.querySelectorAll('[data-action="print"]').forEach(button => {
    button.addEventListener('click', () => window.print());
});
const validationAlert = document.querySelector('[role="alert"]');
if (validationAlert) {
    validationAlert.setAttribute('tabindex', '-1');
    validationAlert.focus();
}
