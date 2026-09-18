# Relatorio Comercial GLPI plugin

Exibe relatórios comerciais gerados a partir do banco de dados do GLPI e os
envia automaticamente por e-mail aos clientes:

- **Relatório por Cliente**: chamados, SLA e horas trabalhadas por categoria, para uma entidade e período.
- **Relatório Geral**: horas contratadas x trabalhadas por cliente, para um período.
- **Envio automático por e-mail**: resumo de horas contratadas x trabalhadas enviado a cada cliente, em periodicidade configurável.

Os dois relatórios podem ser exportados em PDF.

## Requisitos

- GLPI **11.0.x** (`>= 11.0.0` e `< 11.0.99`)
- PHP **>= 8.2**
- SMTP configurado no GLPI (Configurar > Notificações) para o envio por e-mail

## Pré-configuração no GLPI

O plugin cria sozinho seus direitos, tabela e ação automática, mas depende de
alguns dados e ajustes do próprio GLPI:

- **Contratos**: o campo **Número** de cada contrato deve conter as horas
  contratadas (ex.: `40`). Entidades sem contrato com horas não aparecem no
  relatório geral nem recebem e-mail.
- **Chamados e tarefas**: as horas trabalhadas vêm da duração das **tarefas** dos
  chamados; chamados sem tarefas contam 0 h.
- **E-mail (apenas para o envio automático)**: SMTP e remetente configurados em
  Configurar > Notificações. Sem remetente, o envio falha e é registrado no log.
- **Cron do GLPI (apenas para o envio automático)**: a ação automática
  `SendCommercialReport` só roda se o cron do GLPI estiver em execução.
- **Perfis**: perfis sem permissão de configuração do GLPI precisam receber o
  direito do plugin manualmente.

## Instalação

1. Copie a pasta do plugin para `plugins/relatorioglpicomercial` na instalação do GLPI.
2. Em **Configurar > Plugins**, instale e ative o **Relatorio Comercial**.
3. Em **Administração > Perfis**, use a aba **Relatório Comercial** para conceder os direitos (veja [Permissões](#permissões)).

Ao instalar, o plugin cria o direito `plugin_relatorioglpicomercial_report`
(concedido automaticamente aos perfis que já gerenciam a configuração do GLPI),
a tabela `glpi_plugin_relatorioglpicomercial_entityconfigs` e a ação automática
`SendCommercialReport`. A desinstalação remove tudo isso.

## Uso

O menu **Ferramentas > Relatório Comercial** abre a tela de relatórios
(`front/report.php`), onde é possível escolher o tipo, a entidade (no relatório
por cliente) e o período, visualizar o resultado e exportá-lo em PDF.

### Relatório por Cliente

Lista os chamados da entidade abertos no período, com tipo (Incidente /
Requisição), situação de SLA (**Normal** ou **Violação**, quando a solução
ocorre após o prazo de resolução), categoria, requerente, técnico, datas e
tempo trabalhado, além do total de horas por categoria.

### Relatório Geral

Para cada entidade com contrato, mostra as horas contratadas (soma do campo
**Número** dos contratos, que deve conter as horas como valor numérico), as horas trabalhadas (soma das tarefas dos chamados
abertos no período) e o percentual de utilização. O percentual é colorido:

| Utilização | Cor      |
| ---------- | -------- |
| < 80%      | verde    |
| 80% a 99%  | amarelo  |
| >= 100%    | vermelho |

Entidades sem contrato com horas e entidades cujo nome completo contém
`INATIVOS` não entram no relatório.

## Envio automático por e-mail

Configurado em **Configurar > Plugins > Relatorio Comercial** (ícone de chave
inglesa) ou pelo link de configuração do menu. Requer o direito de atualização
do plugin.

### Configuração global

| Opção               | Descrição                                                                                     |
| ------------------- | --------------------------------------------------------------------------------------------- |
| Ativo               | Liga/desliga o envio automático.                                                              |
| Periodicidade       | Diária, semanal (dia da semana) ou mensal (dia do mês, 1-31).                                 |
| Hora de envio       | Hora do dia (0-23) a partir da qual o envio pode ocorrer.                                     |
| Janela de dados     | **Mês vigente**, **Mês anterior** ou **Últimos N dias** (1-365).                              |
| Assunto e corpo HTML| Modelos com variáveis; se o corpo ficar vazio, usa-se o modelo padrão (`templates/mail/default_body.html`). |

Um dia do mês maior que o tamanho do mês corrente cai no último dia do mês,
então "dia 31" também dispara em fevereiro.

### Configuração por cliente

Na mesma página, cada entidade com contrato pode ser incluída ou não no envio e
recebe uma lista de destinatários. Os destinatários são **endereços livres**
(separados por vírgula, ponto e vírgula, espaço ou quebra de linha), não
precisam ser usuários do GLPI. Endereços inválidos são ignorados e registrados
no log da ação automática; duplicados são removidos.

Há também um botão de **e-mail de teste**, que envia o relatório de uma entidade
apenas para o e-mail do usuário logado, pelo mesmo caminho do envio real, sem
marcar o período como enviado.

### Variáveis do assunto e do corpo

Use `{{ variavel }}` no assunto e no corpo HTML:

| Variável            | Conteúdo                                              |
| ------------------- | ----------------------------------------------------- |
| `cliente`           | Nome do cliente (entidade)                            |
| `periodo`           | Período completo (`dd/mm/aaaa - dd/mm/aaaa`)          |
| `data_inicio`       | Início do período                                     |
| `data_fim`          | Fim do período                                        |
| `horas_contratadas` | Horas contratadas                                     |
| `horas_trabalhadas` | Horas trabalhadas                                     |
| `percentual`        | Percentual de utilização                              |
| `percentual_cor`    | Cor (hex) correspondente ao percentual                |
| `gerado_em`         | Data e hora da geração                                |

O assunto padrão é `Relatório de Horas - {{ cliente }} - {{ periodo }}`. Os
modelos **não** são Twig: apenas essas variáveis são substituídas; qualquer
outra vira texto vazio. No corpo HTML os valores são escapados.

### Como o agendamento funciona

A ação automática `SendCommercialReport` (Configurar > Ações automáticas) roda
**a cada hora**, e é o plugin quem decide se é hora de enviar, pois a frequência
do cron do GLPI não consegue expressar "todo dia 1º" ou "toda segunda-feira".

- Cada execução calcula a **chave do período** (o dia, a semana ISO ou o mês) e
  guarda, por entidade, a chave do último envio bem-sucedido. Assim, duas
  execuções na mesma hora não enviam duas vezes, e um cron atrasado ainda
  recupera o envio.
- Ao habilitar um cliente no meio de um período, o período atual é marcado como
  já enviado, evitando um envio imediato inesperado.
- Clientes sem destinatário válido, sem contrato com horas ou com falha no envio
  não são marcados como enviados e são tentados novamente na próxima execução.
- Os e-mails saem com os cabeçalhos `Auto-Submitted` e
  `X-Auto-Response-Suppress`, usando o remetente configurado no GLPI.

Para que o envio ocorra mesmo sem acessos ao GLPI, configure a execução da ação
automática em modo **CLI/externo** (cron do sistema executando
`php bin/console glpi:cron`).

## Permissões

O direito **Relatório Comercial** é configurado por perfil, na aba homônima em
Administração > Perfis:

| Direito                                      | Permite                                            |
| -------------------------------------------- | -------------------------------------------------- |
| Visualizar o relatório comercial (leitura)   | Acessar o menu, ver e exportar os relatórios       |
| Configurar o envio automático (atualização)  | Acessar e alterar a configuração de e-mail         |

## Dados de exemplo

Para popular o ambiente local com entidades, contratos, categorias e chamados
de exemplo (útil para testar os relatórios manualmente):

```
php bin/console plugins:relatorioglpicomercial:seed_demo_data
```

Use `--reset` para remover os dados criados anteriormente e recriá-los do
zero, e `--username=<login>` para rodar como outro usuário (padrão: `glpi`).

## Desenvolvimento

Estrutura principal:

| Caminho                          | Conteúdo                                                        |
| -------------------------------- | --------------------------------------------------------------- |
| `setup.php` / `hook.php`         | Registro do plugin, instalação e desinstalação                  |
| `front/`                         | Telas: relatórios, PDF, configuração e direitos por perfil      |
| `src/Report.php`                 | Consultas dos relatórios                                        |
| `src/ReportRenderer.php`         | Formatação e dados de apresentação                              |
| `src/ReportPdf*.php`             | Exportação em PDF (TCPDF)                                       |
| `src/ReportScheduler.php`        | Lógica pura de quando enviar e qual período cobrir              |
| `src/ReportMailer.php`           | Ação automática e montagem do e-mail                            |
| `src/MailConfig.php`             | Configuração global do envio (tabela `glpi_configs`)            |
| `src/EntityMailConfig.php`       | Configuração por cliente                                        |
| `templates/`                     | Twig das telas e do PDF, e corpo HTML padrão do e-mail          |
| `docs/reference/`                | Fluxo n8n original, mantido como referência da migração         |

O plugin nasceu de um fluxo n8n (`docs/reference/relatorio-glpi-comercial.n8n.json`),
cujas consultas e o envio de e-mail foram portados para dentro do GLPI.

Ferramentas de qualidade (PHPStan, Psalm, Rector, PHP-CS-Fixer, Twig-CS) e
testes PHPUnit seguem o padrão dos plugins GLPI e rodam na CI
(`.github/workflows/continuous-integration.yml`). Os testes ficam em `tests/`.

## Contributing

* Open a ticket for each bug/feature so it can be discussed
* Follow [development guidelines](http://glpi-developer-documentation.readthedocs.io/en/latest/plugins/index.html)
* Refer to [GitFlow](http://git-flow.readthedocs.io/) process for branching
* Work on a new branch on your own fork
* Open a PR that will be reviewed by a developer

## Licença

MIT, veja [LICENSE](LICENSE).
