// Árvore do catálogo de técnicas: filtro por nome e abrir/recolher tudo. Sem JS, a árvore funciona com <details>.
document.querySelectorAll('[data-tree-toolbar]').forEach(toolbar => {
    toolbar.hidden = false;
});
const treeOf = name => document.querySelector(`[data-tree="${name}"]`);
document.addEventListener('click', event => {
    const expand = event.target.closest('[data-tree-expand]');
    const collapse = event.target.closest('[data-tree-collapse]');
    const button = expand || collapse;
    if (!button) {
        return;
    }
    const tree = treeOf(button.dataset.treeExpand || button.dataset.treeCollapse);
    tree?.querySelectorAll('details.tree-group').forEach(group => {
        group.open = Boolean(expand);
    });
});
document.querySelectorAll('[data-tree-filter]').forEach(input => {
    const tree = treeOf(input.dataset.treeFilter);
    if (!tree) {
        return;
    }
    const groups = [...tree.querySelectorAll('details.tree-group')];
    const initial = groups.map(group => group.open);
    const nodes = [...tree.querySelectorAll('[data-tree-node]')];
    const empty = tree.querySelector('[data-tree-empty]');
    input.addEventListener('input', () => {
        const term = input.value.trim().toLowerCase();
        if (term === '') {
            nodes.forEach(node => { node.hidden = false; });
            groups.forEach((group, index) => { group.open = initial[index]; });
            tree.querySelectorAll('[data-tree-block], .tree-card').forEach(block => { block.hidden = false; });
            if (empty) { empty.hidden = true; }
            return;
        }
        // Mostra o item que combina, os grupos acima dele (abertos) e tudo o que está dentro de um grupo que combina.
        nodes.forEach(node => { node.hidden = true; });
        nodes.filter(node => node.dataset.name.includes(term)).forEach(node => {
            node.hidden = false;
            node.querySelectorAll('[data-tree-node]').forEach(child => { child.hidden = false; });
            for (let parent = node.parentElement.closest('[data-tree-node]'); parent; parent = parent.parentElement.closest('[data-tree-node]')) {
                parent.hidden = false;
                const group = parent.querySelector(':scope > details.tree-group');
                if (group) { group.open = true; }
            }
        });
        let visible = 0;
        tree.querySelectorAll('.tree-card').forEach(card => {
            const shown = [...card.querySelectorAll('.tree-root > [data-tree-node]')].some(node => !node.hidden);
            const block = card.closest('[data-tree-block]') || card;
            block.hidden = !shown;
            visible += shown ? 1 : 0;
        });
        if (empty) { empty.hidden = visible > 0; }
    });
});
