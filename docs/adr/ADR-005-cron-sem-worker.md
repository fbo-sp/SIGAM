# ADR-005 — Cron sem worker permanente

**Estado:** aceito.

Jobs essenciais são idempotentes e executados pelo Scheduler via cron. Redis, mensageria e processos residentes não são dependências da V1.

