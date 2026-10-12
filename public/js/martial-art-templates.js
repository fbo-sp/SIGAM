document.querySelectorAll('[data-martial-art-template-form]').forEach((form) => {
    const select = form.querySelector('[data-template-select]');
    const name = form.querySelector('[data-template-name]');
    const description = form.querySelector('[data-template-description]');
    const preview = form.querySelector('[data-template-preview]');

    if (!select || !name || !description || !preview) return;

    const title = preview.querySelector('[data-template-title]');
    const summary = preview.querySelector('[data-template-summary]');
    const review = preview.querySelector('[data-template-review]');

    const apply = (fillFields) => {
        const option = select.selectedOptions[0];
        const hasTemplate = Boolean(option?.value);
        preview.hidden = !hasTemplate;

        if (!hasTemplate) return;

        title.textContent = option.textContent.trim();
        summary.textContent = option.dataset.summary || '';
        review.textContent = option.dataset.review || '';

        if (fillFields) {
            name.value = option.dataset.defaultName || '';
            description.value = option.dataset.description || '';
        }
    };

    select.addEventListener('change', () => apply(true));
    apply(false);
});
