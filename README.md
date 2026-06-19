# ACISJM-App

Plataforma web administrativa e associativa desenvolvida para a **Associação Comercial e Industrial de São João da Madeira (ACISJM)**, permitindo gerir empresas associadas, pedidos de inscrição, quotas, serviços e iniciativas, parceiros e benefícios, e validação de associados por código QR.

---

## Índice

- [Visão geral](#visão-geral)
- [Funcionalidades](#funcionalidades)
- [Tecnologias](#tecnologias)
- [Estrutura do projeto](#estrutura-do-projeto)
- [Modelo de navegação](#modelo-de-navegação)
- [Base de dados](#base-de-dados)
- [Instalação](#instalação)
- [Boas práticas de segurança em produção](#boas-práticas-de-segurança-em-produção)
- [Roadmap](#roadmap)
- [Licença](#licença)

---

## Visão geral

A ACISJM-App centraliza a relação entre a ACISJM e as suas empresas associadas, substituindo processos manuais de gestão de associados por uma plataforma única. A aplicação é construída em **PHP nativo**, sem recurso a frameworks (Laravel, Symfony, WordPress, React, Vue, etc.), com persistência em **MySQL**. Esta escolha torna a instalação simples e sem dependências de build, à custa de concentrar parte significativa da lógica num número reduzido de ficheiros.

A aplicação distingue dois perfis de utilizador:

- **Administrador** — gestão completa: cria, edita e apaga associados, aprova ou rejeita pedidos de inscrição, publica serviços e iniciativas, gere parceiros e benefícios, importa/exporta listas de associados e gera cópias de segurança da base de dados.
- **Associado** — acede a uma área reservada para consultar os seus dados, o código QR de validação, os serviços disponíveis, os benefícios de parceiros e os laboratórios colaborativos, sujeitos à validação do estado da quota quando aplicável.

## Funcionalidades

- Registo público de novos associados, com submissão de comprovativo de pagamento e aprovação administrativa
- Gestão de quotas (mensal, semestral, anual) com estados (pendente, pago, atrasado, isento)
- Emissão e validação pública de código QR por associado
- Gestão de serviços, iniciativas, parceiros e benefícios
- Importação e exportação de associados em formato Excel (.xlsx)
- Geração de cópias de segurança da base de dados
- Estatísticas agregadas por setor de atividade e por localidade
- Interface responsiva (desktop, tablet e telemóvel) e suporte básico a **Progressive Web App** (instalável em dispositivos móveis)

## Tecnologias

| Componente | Tecnologia |
|---|---|
| Back-end | PHP nativo (sem framework) |
| Base de dados | MySQL, via PDO |
| Front-end | HTML, CSS e JavaScript simples |
| PWA | `manifest.json`, service worker (`sw.js`) |
| Ambiente de desenvolvimento | XAMPP (Apache + MySQL) ou qualquer stack PHP/MySQL equivalente |

## Estrutura do projeto

```text
ACISJM-App/
├── assets/                 # Imagens e ícones da interface
├── uploads/
│   ├── parceiros/          # Imagens dos cartões de parceiros
│   └── quotas/             # Comprovativos de pagamento de quota
├── config.php              # Configuração, autenticação, CSRF, quotas e utilitários
├── database.sql            # Script de criação da base de dados
├── index.php               # Ponto de entrada principal (rotas, CRUD, dashboard)
├── manifest.json            # Manifesto PWA
├── pwa.js                   # Registo do service worker
├── registro.php             # Página pública de pedido de inscrição
├── styles.css                # Estilos e responsividade
└── sw.js                     # Service worker / cache de recursos estáticos
```

> Em produção, recomenda-se manter a pasta de **backups da base de dados** fora da raiz pública do servidor (`htdocs`/`public_html`), conforme detalhado em [Boas práticas de segurança](#boas-práticas-de-segurança-em-produção).

## Modelo de navegação

A aplicação não usa um ficheiro por página: o `index.php` interpreta um único parâmetro `page` recebido via GET e decide o que apresentar.

```php
$page = $_GET['page'] ?? 'login';
```

Exemplos de rotas:

```text
index.php?page=dashboard
index.php?page=empresas
index.php?page=parceiros
index.php?page=associado
index.php?page=validar&code=...
```

Principais áreas:

| Rota | Acesso | Descrição |
|---|---|---|
| `login` | Público | Início de sessão |
| `dashboard` | Administrador | Painel de indicadores |
| `empresas` | Administrador | Gestão de associados |
| `associado` | Associado | Área reservada do associado |
| `servicos` | Administrador / Associado | Serviços e iniciativas |
| `parceiros` | Administrador / Associado | Parceiros e benefícios |
| `laboratorios` | Associado | Laboratórios colaborativos (sujeito a quota válida) |
| `ferramentas` | Administrador | Exportação, importação, backups, estatísticas |
| `validar` | Público | Validação pública de código QR |

## Base de dados

A base de dados (nome configurável) é criada a partir de `database.sql` e contém, entre outras, as seguintes tabelas:

| Tabela | Função |
|---|---|
| `empresas_associadas` | Tabela central: dados de cada empresa associada, estado e quota |
| `utilizadores` | Contas de acesso (administrador / associado), com palavra-passe cifrada |
| `servicos_iniciativas` | Serviços, eventos e campanhas promovidos pela associação |
| `parceiros_descontos` | Parceiros institucionais e respetivos benefícios/descontos |
| `qr_codes` | Código único de validação associado a cada empresa |
| `solicitacoes_associado` | Pedidos de inscrição submetidos publicamente, até aprovação |
| `login_attempts` | Registo de tentativas de início de sessão, para bloqueio anti-força-bruta |

## Instalação

1. Clone o repositório para o diretório servido pelo seu servidor web (por exemplo, a pasta `htdocs` do XAMPP).
2. Crie uma base de dados MySQL dedicada e importe o ficheiro `database.sql`.
3. Em `config.php`, defina as credenciais de ligação à sua base de dados local (anfitrião, nome da base de dados, utilizador e palavra-passe), usando um utilizador MySQL dedicado, **nunca o utilizador `root`** em produção.
4. Garanta que as pastas `uploads/` e `backups/` têm permissão de escrita pelo servidor web.
5. Aceda à aplicação no navegador. Na primeira execução é criada automaticamente uma conta de administrador inicial — **inicie sessão e altere de imediato o e-mail e a palavra-passe** dessa conta.

## Boas práticas de segurança em produção

- Defina as credenciais de base de dados num utilizador MySQL próprio, com permissões limitadas ao necessário — nunca utilize `root`.
- Altere imediatamente as credenciais da conta de administrador criada automaticamente no primeiro arranque.
- Mantenha a pasta de backups da base de dados **fora da raiz pública do servidor** ou bloqueie o seu acesso direto via HTTP (por exemplo, com uma regra `.htaccess`).
- Restrinja a execução de scripts dentro da pasta `uploads/`, permitindo apenas o acesso aos tipos de ficheiro esperados (imagens e documentos).
- Sirva a aplicação exclusivamente sobre HTTPS.
- Não publique pastas de histórico de edição (por exemplo, geradas por editores de código) no servidor de produção.
- Reveja periodicamente os utilizadores com perfil de administrador e os respetivos acessos.

## Roadmap

- Separar `index.php` em módulos mais pequenos (controladores, vistas e funções auxiliares)
- Introduzir um sistema formal de migrações de base de dados
- Gerar os códigos QR localmente, eliminando a dependência de serviços externos
- Paginação na listagem de associados
- Alteração de palavra-passe por iniciativa do próprio associado
- Registos de auditoria (logs) de ações administrativas

## Licença

Projeto de uso interno da ACISJM. Direitos reservados.
