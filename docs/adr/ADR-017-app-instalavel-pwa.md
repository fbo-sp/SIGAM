# ADR-017 — App instalável (PWA)

**Estado:** aceito (implementado em 01/11/2026).

## Contexto

Fabio quer o JUDOMA como app no celular (Android e iPhone). Publicar nas lojas exige contas pagas, empacotamento e revisão a cada versão. Pelo estágio e custo do projeto, ele escolheu um PWA: o mesmo site, instalável pelo navegador, com ícone na tela inicial e tela cheia. A hospedagem continua sendo compartilhada, sem Node nem worker (ADR-001, ADR-005).

## Decisão

1. **Arquivos estáticos em `public/`:** `manifest.json` (nome, cores, `start_url` `./dashboard`), ícones em `public/icons` (192, 512, 512 "maskable", `apple-touch-icon` 180 e favicon 32) desenhados a partir do logo do quimono, `offline.html` e `sw.js`. Nada é gerado no build; o zip de publicação já os leva.
2. **Service worker só para estáticos:** navegações vão sempre à rede e, sem conexão, mostram `offline.html`. Só CSS, JS e ícones de `/css`, `/js` e `/icons` são guardados (resposta do cache, atualização em segundo plano). Páginas com dados pessoais, downloads, POST e outros domínios nunca passam pelo cache; as páginas logadas continuam com `Cache-Control: no-store`.
3. **Versão pelo endereço:** o layout registra `sw.js?v=<hash>`; o hash (`App\Support\Pwa\PwaVersion`) sai da data e do tamanho dos arquivos guardados. Cada atualização do sistema muda o hash, o navegador instala o novo worker e apaga os caches `judoma-*` antigos. Ao sair, `common.js` pede ao worker para apagar os caches.
4. **CSP:** `manifest-src 'self'` e `worker-src 'self'`; continua sem script ou estilo inline.
5. **Oferta de instalação (escolha de Fabio: aviso e tela Mais):** aviso no topo das telas logadas no celular (Android com o botão do navegador; iPhone com "Ver como"), escondido por 30 dias com "×" e nunca mostrado dentro do app instalado; tela `/instalar-app` com os passos para iPhone e Android, ligada no Mais do portal e do professor.

## Consequências

- Não há modo offline de dados: chamada e notas continuam exigindo internet. Trabalhar sem conexão exigiria fila local e sincronização, fora deste escopo.
- O iPhone só instala pelo Compartilhar, sem botão automático; a tela de passos cobre isso.
- Notificações push e publicação nas lojas (por exemplo, com TWA no Android) ficam para depois e partem destes mesmos arquivos.
- Mudar o ícone é trocar os PNGs em `public/icons`; a fonte do desenho fica fora do repositório (SVG da prévia).
