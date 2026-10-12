# JUDOMA

JUDOMA é o nome apresentado aos usuários. O identificador técnico legado `SIGAM` permanece em comandos, variáveis de ambiente, bancos, arquivos e infraestrutura para preservar compatibilidade operacional.

Sistema de Gestão de Associação de Artes Marciais, em Laravel 12 e PHP 8.2+. O roadmap funcional das Sprints 1–21 está implementado e disponível em homologação com MySQL. A publicação em produção permanece condicionada aos gates documentados.

## Estado atual

- plano de desenvolvimento e especificações preservados na raiz;
- arquitetura monolítica modular documentada em `docs/`;
- autenticação, RBAC, escopo por associação e auditoria implementados;
- gestão de associações, dojos e pessoas com busca, inativação, prevenção de duplicidade e CSV;
- cadastro esportivo, turmas, chamadas, documentos privados, planos e benefícios financeiros;
- cobranças, pagamentos/estornos, federações, campeonatos, portal, notificações e dashboards;
- hardening, preflight e procedimentos de deploy, backup, restauração e operação;
- cadastro direto e convite seguro de professor, com aceite/verificação e recuperação de senha;
- agenda semanal com vários dias e visitantes gratuitos por aula dentro da associação;
- importação assistida de alunos/presenças, reposição automática de 4/8 aulas, financeiro local e campeonatos compartilhados;
- interface em português brasileiro e renderização server-side;
- testes locais usam SQLite em memória; produção usa MySQL/MariaDB.

## Desenvolvimento no Windows

O workspace pode usar PHP e Composer portáteis, ignorados pelo Git:

```powershell
.\scripts\php.ps1 --version
.\scripts\composer.ps1 install
.\scripts\php.ps1 artisan key:generate
.\scripts\php.ps1 artisan migrate --seed
.\scripts\php.ps1 artisan serve
```

Copie `.env.example` para `.env` e ajuste o banco antes das migrations. Nunca versione o `.env` real.

`key:generate` é exclusivo da instalação nova. Nunca regenerar a chave em atualização ou recuperação de uma instalação existente.

## Verificações

```powershell
.\scripts\php.ps1 artisan test
.\scripts\php.ps1 vendor\bin\pint --test
```

## Docker para desenvolvimento e validação

Com o Docker Desktop iniciado:

```powershell
.\scripts\docker-up.ps1
.\scripts\docker-test.ps1
```

A aplicação fica em `http://localhost:8080`, o capturador de e-mail em `http://localhost:8025` e o MySQL em `127.0.0.1:3307`. Consulte `docs/DOCKER.md` para criar o administrador local, testar convites, alterar portas e remover volumes.

## Produção

**NO-GO até aprovação do [checklist de release](docs/RELEASE_CHECKLIST.md).** Consulte as [evidências de validação](docs/RELEASE_VALIDATION.md), o [procedimento de publicação](docs/DEPLOYMENT.md) e o [guia rápido dos usuários](docs/USER_GUIDE.md). Docker local saudável não representa aceite de produção. MySQL 8.4 e MySQL 5.7 (versão da hospedagem prevista) são testados no CI; outro banco/versão exige suíte específica.

Produção requer Linux, HTTPS, PHP 8.4 (exigido pelo `composer.lock` atual), MySQL 5.7+/MariaDB equivalente, filesystem privado, cron e SMTP. Redis, Docker, worker persistente e Node.js não são requisitos de produção. O document root deve apontar para `public/`.

## Leitura obrigatória

Comece por [docs/INDEX.md](docs/INDEX.md), confira o [estado implementado](docs/IMPLEMENTATION_STATUS.md), diferencie-o do [backlog de melhorias](docs/IMPROVEMENT_BACKLOG.md) e siga o [guia de manutenção](docs/MAINTENANCE_GUIDE.md). Alterações estruturais exigem atualização do documento do módulo e, quando aplicável, um ADR.

O mapa técnico e funcional de cada incremento está no índice de [docs/modules/](docs/INDEX.md).
