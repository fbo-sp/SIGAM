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
    const refresh = () => {
        conditionalFields.forEach(field => {
            const [name, values] = field.dataset.showWhen.split(':');
            field.hidden = !values.split(',').includes(currentValue(field.closest('form'), name));
        });
        // Com blocos aninhados, o campo só vale se nenhum bloco acima dele estiver oculto.
        document.querySelectorAll('[data-show-when] :is(input, select, textarea)').forEach(control => {
            control.disabled = control.closest('[hidden]') !== null;
        });
    };
    document.addEventListener('change', event => {
        if (event.target.name) {
            refresh();
        }
    });
    refresh();
}
// Janelas <dialog>: data-modal-open="id" abre; a que tem data-open-on-load abre ao carregar (erro de validação).
document.querySelectorAll('[data-modal-open]').forEach(opener => {
    const dialog = document.getElementById(opener.dataset.modalOpen);
    if (dialog && dialog.showModal) {
        opener.addEventListener('click', event => {
            event.preventDefault();
            dialog.showModal();
        });
    }
});
document.querySelectorAll('dialog[data-open-on-load]').forEach(dialog => dialog.showModal && dialog.showModal());
// Filtra uma lista de opções pelo texto digitado: data-filter-list="id-da-lista".
// Usa classe, não [hidden], para não desabilitar as opções já marcadas.
document.querySelectorAll('[data-filter-list]').forEach(input => {
    const list = document.getElementById(input.dataset.filterList);
    if (!list) {
        return;
    }
    const plain = text => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    input.addEventListener('input', () => {
        const term = plain(input.value.trim());
        list.querySelectorAll('label').forEach(label => {
            label.classList.toggle('is-filtered', term !== '' && !plain(label.textContent).includes(term));
        });
    });
});
// Marca a mesma escolha em todas as linhas do formulário: data-check-all="valor" (importação: Ignorar/Atualizar todas).
document.querySelectorAll('[data-check-all]').forEach(button => {
    button.addEventListener('click', () => {
        button.closest('form').querySelectorAll(`input[type="radio"][value="${button.dataset.checkAll}"]`).forEach(radio => {
            radio.checked = true;
        });
    });
});
