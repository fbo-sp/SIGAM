document.querySelectorAll('[data-action="print"]').forEach(button => {
    button.addEventListener('click', () => window.print());
});
const validationAlert = document.querySelector('[role="alert"]');
if (validationAlert) {
    validationAlert.setAttribute('tabindex', '-1');
    validationAlert.focus();
}
// Mostra só os campos da opção escolhida: data-show-when="campo:valor1,valor2".
// Campos ocultos ficam desabilitados para não enviar dados de outra opção.
const conditionalFields = document.querySelectorAll('[data-show-when]');
if (conditionalFields.length) {
    const currentValue = (form, name) => {
        const input = [...form.querySelectorAll(`[name="${name}"]`)].find(item => item.type !== 'radio' || item.checked);
        return input ? input.value : '';
    };
    const refresh = () => conditionalFields.forEach(field => {
        const [name, values] = field.dataset.showWhen.split(':');
        const visible = values.split(',').includes(currentValue(field.closest('form'), name));
        field.hidden = !visible;
        field.querySelectorAll('input, select, textarea').forEach(control => { control.disabled = !visible; });
    });
    document.addEventListener('change', event => {
        if (event.target.name) {
            refresh();
        }
    });
    refresh();
}
