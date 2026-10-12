# Ambiente Docker

O Docker é exclusivo para desenvolvimento, testes e validação local. A produção continua compatível com hospedagem PHP/MySQL convencional.

## Serviços

| Serviço | Finalidade | Acesso local |
|---|---|---|
| `app` | PHP 8.4.25, Apache e Laravel | `http://localhost:8080` |
| `db` | MySQL 8.4.10 persistente | `127.0.0.1:3307` |
| `mailpit` | SMTP capturado, sem envio externo | `http://localhost:8025` |
| `test` | suíte PHPUnit contra `sigam_test` | executado sob demanda |

As portas escutam somente no loopback. O volume `sigam_mysql_data` mantém o banco de desenvolvimento; `sigam_vendor` isola dependências Linux das dependências do Windows. O SMTP do `app` aponta para o Mailpit interno; nenhuma mensagem sai para a internet.

## Subir e validar

```powershell
.\scripts\docker-up.ps1
.\scripts\docker-test.ps1
```

Migrations e seeders idempotentes são executados ao iniciar `app`. O healthcheck público usa `/up`.

## Criar o administrador local

Não existe senha padrão versionada. Escolha uma senha apenas para o ambiente local e recrie a aplicação:

```powershell
$env:SIGAM_ADMIN_PASSWORD = 'escolha-uma-senha-local-forte'
.\scripts\docker-up.ps1
```

O e-mail padrão é `admin@sigam.local`. Nome, e-mail, portas e credenciais locais podem ser sobrescritos pelas variáveis descritas em `.env.docker.example`.

Desde a Sprint 14, o seeder cria o administrador somente se a conta ainda não existe; não redefine senha, reativa ou promove conta existente. Alterar a variável de bootstrap não troca uma senha já criada.

Para testar convites/notificações, crie a ação na aplicação e execute o scheduler ou comando correspondente. Abra `http://localhost:8025` para ler a mensagem capturada. O Mailpit é somente local e não deve ser publicado na internet:

```powershell
docker compose exec -T app php artisan sigam:teacher-invitations:send
```

## Parar

Preservar banco e dependências:

```powershell
.\scripts\docker-down.ps1
```

Remover também os volumes e todos os dados locais do MySQL:

```powershell
.\scripts\docker-down.ps1 -Volumes
```

Use `-Volumes` somente quando a perda do banco local for intencional.

## Diagnóstico

```powershell
docker ps
docker logs sigam-app-1
docker logs sigam-db-1
docker logs sigam-mailpit-1
```

Se `docker compose` não estiver disponível no `PATH`, os scripts usam diretamente o Compose instalado pelo Docker Desktop no perfil do usuário.
