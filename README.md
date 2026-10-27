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

### Dashboard de Chamados

O menu **Ferramentas > Dashboard de Chamados** (`front/dashboard.php`) abre um
painel que segue o tema do GLPI, com filtros de entidade, período, categoria,
status e analista (todos opcionais, exceto o período). Ele mostra:

- roscas de **SLA Atendimento** e **SLA Solução** (empilhadas) e rosca de **Tipo do chamado**;
- **Horas Categorias**: horas apontadas no período por categoria;
- KPIs de status (Em Atendimento, Pendente, Fechado, Total), SLA de solução (Violado, No
  prazo) e **Média de Satisfação**;
- tabela **Chamados**, com uma linha por chamado e analista.

Entram no painel os chamados com tarefas apontadas no período (data da tarefa)
**ou** abertos no período. No filtro, a data de abertura é `date_creation`, com
`date` como alternativa nos chamados antigos em que ela é nula. As colunas
**Abertura** (`date_creation`) e **Data do Chamado** (`date`) são independentes e
mostram cada campo como está. "Horas Apontadas Período" soma só as
tarefas do analista dentro do período; "Total Horas Apontadas" é o tempo total
do chamado.

O botão **Colunas**, no topo da tabela, deixa cada usuário escolher quais colunas
ver (entidade, grupos, origem, localização, prioridade e impacto do GLPI,
fechamento, última atualização e tempos calculados pelo GLPI, além das colunas
padrão). A escolha é aplicada na hora e gravada por usuário em `glpi_configs`
(contexto `plugin:relatorioglpicomercial:dashboard`, via
`ajax/dashboard_columns.php`); **Restaurar padrão** volta às colunas originais.
O catálogo de colunas fica em `src/DashboardColumns.php`.

O botão **Exportar PDF**, no topo do painel, baixa o dashboard em PDF A4 paisagem
(`front/dashboard_pdf.php`) com os mesmos filtros: KPIs, roscas, horas por
categoria e a tabela de chamados, que usa as colunas escolhidas pelo usuário.

As colunas padrão seguem `docs/feature/Tabela GLPI.txt`. Uma observação: **Tempo para
Atendimento** mostra o prazo de atendimento (`time_to_own`), e não a data do
primeiro atendimento do item 10. A coluna **SLA Atendimento** (item 16) existe no
seletor de colunas, mas vem desmarcada; **SLA Solução** (item 17) é a coluna padrão.

Regras de SLA (itens 16 e 17 da tabela), iguais para os dois:

| Situação | Resultado |
| --- | --- |
| status diferente de 5 (Solucionado) e 6 (Fechado) | **Em andamento** |
| sem prazo (`time_to_own` / `time_to_resolve`) | **Sem prazo definido** |
| sem data de atendimento / solução (`takeintoaccountdate` / `solvedate`) | **A validar** |
| data de atendimento / solução maior que o prazo | **Violado** |
| demais casos | **No prazo** |

O SLA de atendimento compara `takeintoaccountdate` com `time_to_own`; o de solução
compara `solvedate` com `time_to_resolve`. Os KPIs Violado e No prazo usam o SLA de
solução. Os status seguem `docs/feature/Tabela GLPI.txt` (2 e 3 = Em atendimento).

A coluna **Horas Previstas** vem do plugin *Fields*
(`glpi_plugin_fields_ticketprevisodehoras.horasprevistasfieldtwo`) e fica vazia
quando essa tabela não existe. As roscas usam o Chartist que já acompanha o GLPI.

## Envio automático por e-mail (Grupos de Transmissão)

Configurado em **Configurar > Plugins > Relatorio Comercial** (ícone de chave
inglesa) ou pelo link de configuração do menu. Requer o direito de atualização
do plugin.

Um **Grupo de Transmissão** é a unidade de configuração do envio: ele reúne um
agendamento, um modelo de e-mail, um tipo de relatório e a lista de entidades
(clientes) que o recebem. É possível criar quantos grupos forem necessários —
por exemplo, um grupo mensal do Relatório Comercial para um conjunto de
clientes e outro semanal do Dashboard de Chamados para outro conjunto,
cada um com seu próprio agendamento e destinatários.

### Configuração do grupo

| Opção               | Descrição                                                                                     |
| ------------------- | ---------------------------------------------------------------------------------------------- |
| Nome / Ativo        | Identifica o grupo e liga/desliga o seu envio automático.                                     |
| Tipo de relatório   | **Relatório Comercial** (horas por cliente) ou **Dashboard de Chamados**. Um único tipo por grupo. |
| Anexos              | **Anexar PDF**. O corpo do e-mail é sempre o modelo de texto abaixo; o PDF é o relatório Mindworks daquela entidade no período, montado como descrito em [Anexo PDF](#anexo-pdf). |
| Periodicidade       | Diária, semanal (dia da semana) ou mensal (dia do mês, 1-31).                                 |
| Hora de envio       | Hora do dia (0-23) a partir da qual o envio pode ocorrer.                                     |
| Janela de dados     | **Mês vigente**, **Mês anterior** ou **Últimos N dias** (1-365).                              |
| Assunto e corpo HTML| Modelos com variáveis; se o corpo ficar vazio, usa-se o modelo padrão do tipo de relatório (`templates/mail/default_body_comercial.html` ou `default_body_dashboard.html`). |

Um dia do mês maior que o tamanho do mês corrente cai no último dia do mês,
então "dia 31" também dispara em fevereiro.

### Destinatários por cliente

Depois de salvo, o grupo lista as entidades elegíveis para o tipo de relatório
escolhido (entidades com contrato de horas, no caso do Relatório Comercial;
todas as entidades não inativas, no caso do Dashboard de Chamados). Cada uma
pode ser incluída ou não no envio e recebe uma lista de destinatários própria,
**dentro daquele grupo**. Os destinatários são **endereços livres** (separados
por vírgula, ponto e vírgula, espaço ou quebra de linha), não precisam ser
usuários do GLPI. Endereços inválidos são ignorados e registrados no log da
ação automática; duplicados são removidos.

A mesma entidade pode pertencer a vários grupos (por exemplo, um grupo do
Relatório Comercial e outro do Dashboard de Chamados), cada um com seu próprio
controle de "último envio".

Há também um botão de **e-mail de teste**, que envia o relatório de uma
entidade do grupo apenas para o e-mail do usuário logado, pelo mesmo caminho do
envio real (incluindo os anexos configurados), sem marcar o período como
enviado.

### Anexo PDF

O PDF anexado segue o modelo de relatório da Mindworks
(`docs/email_tamplates/MINDWORKS_Relatório_Suporte_Especializado (1).html`):
A4 paisagem, capa azul-marinho com a logo, e cabeçalho "RELATÓRIO {CLIENTE}" e
rodapé com a logo em todas as páginas. O card **Anexo PDF** do grupo define:

- quais páginas entram e em que ordem (botões ↑/↓);
- o título e o rodapé de cada página, e os campos próprios dela (abra em **Configurar**);
- o nome do arquivo e o nº do contrato padrão.

| Página                          | Conteúdo                                                                                         |
| ------------------------------- | ------------------------------------------------------------------------------------------------ |
| Capa                            | Ano, subtítulo, cliente e período                                                                |
| Aviso de confidencialidade      | Texto editável, com os nomes das partes em destaque                                              |
| Identificação e controle        | Versão, período, coordenador, revisão, cliente, gerente e data de geração                        |
| Sumário e visão geral           | Sumário automático das páginas incluídas, com número de página e link, e texto da visão geral    |
| Detalhamento                    | Cards de resumo e o painel de indicadores (SLA de atendimento e de solução, com meta configurável) |
| Relatório de chamados           | O Dashboard de Chamados: cards, gráficos (SLA atendimento, SLA solução, horas por categoria, tipo) e tabela com as colunas escolhidas no grupo |
| Relatório comercial             | Horas contratadas × trabalhadas, horas por categoria e chamados do cliente                      |
| Justificativas                  | Painel de indicadores, texto de justificativas e lista dos chamados com SLA violado             |
| Formalização e aceite           | Texto do termo e assinaturas da Mindworks e do cliente                                           |

Os indicadores consideram apenas chamados com SLA medido: "no prazo" conta como
atendido e "violado" como não atendido; chamados em andamento ou sem prazo
ficam fora do percentual.

Os textos do anexo aceitam os marcadores `cliente`, `ano`, `periodo`,
`periodo_curto` (ex.: `SET/2026` quando a janela é um mês inteiro),
`data_inicio`, `data_fim`, `gerado_em`, `contrato`, `versao`, `revisao`,
`coordenador`, `gerente`, `responsavel_mindworks`, `cargo_mindworks`,
`responsavel_cliente` e `cargo_cliente`. Nos textos longos, uma linha em branco
separa parágrafos.

Em **Destinatários por cliente**, o botão **Dados do anexo** de cada cliente
permite informar o nº do contrato e o responsável (nome e cargo) que assina o
aceite; quando vazios, valem os padrões do grupo.

A **pré-visualização** ao lado gera o PDF de um cliente com os valores atuais
do formulário (mesmo os ainda não salvos) e a janela de dados do agendamento
(`ajax/attachment_preview.php`).

### Variáveis do assunto e do corpo

Use `{{ variavel }}` no assunto e no corpo HTML. As variáveis disponíveis
dependem do tipo de relatório do grupo:

**Relatório Comercial**

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

**Dashboard de Chamados**

| Variável              | Conteúdo                                              |
| ---------------------- | ----------------------------------------------------- |
| `cliente`              | Nome do cliente (entidade)                             |
| `periodo`               | Período completo (`dd/mm/aaaa - dd/mm/aaaa`)           |
| `data_inicio`           | Início do período                                      |
| `data_fim`              | Fim do período                                         |
| `total_chamados`        | Total de chamados no período                           |
| `sla_percentual`        | Percentual de chamados no prazo                        |
| `sla_percentual_cor`    | Cor (hex) correspondente ao percentual de SLA          |
| `gerado_em`             | Data e hora da geração                                 |

Os modelos **não** são Twig: apenas essas variáveis são substituídas; qualquer
outra vira texto vazio. No corpo HTML os valores são escapados.

### Como o agendamento funciona

A ação automática `SendCommercialReport` (Configurar > Ações automáticas) roda
**a cada hora** e percorre todos os grupos ativos; é o plugin quem decide, por
grupo, se é hora de enviar, pois a frequência do cron do GLPI não consegue
expressar "todo dia 1º" ou "toda segunda-feira".

- Cada execução calcula, por grupo, a **chave do período** (o dia, a semana ISO
  ou o mês) e guarda, por (grupo, entidade), a chave do último envio
  bem-sucedido. Assim, duas execuções na mesma hora não enviam duas vezes, e um
  cron atrasado ainda recupera o envio.
- Ao habilitar um cliente no meio de um período, o período atual é marcado como
  já enviado, evitando um envio imediato inesperado.
- Clientes sem destinatário válido, sem dado a reportar no período (sem
  contrato com horas, no Relatório Comercial; sem chamados, no Dashboard) ou com
  falha no envio não são marcados como enviados e são tentados novamente na
  próxima execução.
- Os e-mails saem com os cabeçalhos `Auto-Submitted` e
  `X-Auto-Response-Suppress`, usando o remetente configurado no GLPI.

Para que o envio ocorra mesmo sem acessos ao GLPI, configure a execução da ação
automática em modo **CLI/externo** (cron do sistema executando
`php bin/console glpi:cron`).

> Instalações que já usavam a configuração global única (versões anteriores a
> esta) têm seus dados migrados automaticamente, na atualização do plugin, para
> um Grupo de Transmissão chamado "Envio Automático (migrado)", do tipo
> Relatório Comercial e sem anexos — preservando o agendamento, o modelo e os
> destinatários já configurados.
>
> Na versão 1.3.0, todos os grupos passam a anexar o PDF no layout Mindworks,
> com todas as páginas ativas (e o relatório do tipo do grupo).

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

Os chamados são distribuídos ao longo de um mês, sempre até o momento atual.
Por padrão é o mês corrente; use `--month=AAAA-MM` para popular outro mês
(rodar de novo para o mesmo mês não duplica). O seed cria 5 clientes com
utilização verde, amarela, vermelha, `INATIVOS` e sem contrato, além de
origens, localizações, status variados, SLAs no prazo/violados e notas de
satisfação.

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
| `src/Dashboard*.php`             | Dashboard de Chamados: consultas, apresentação e menu           |
| `src/ReportPdf*.php`             | Exportação em PDF (TCPDF)                                       |
| `src/Attachment*.php`            | Anexo PDF Mindworks: configuração, formulário e montagem        |
| `src/PdfCharts.php`              | Cards e gráficos de rosca desenhados no PDF                     |
| `src/ReportScheduler.php`        | Lógica pura de quando enviar e qual período cobrir              |
| `src/ReportMailer.php`           | Ação automática e montagem do e-mail                            |
| `src/TransmissionGroup.php`      | Grupo de Transmissão: agendamento, tipo de relatório e modelo    |
| `src/TransmissionGroupEntity.php`| Destinatários por cliente, dentro de um Grupo de Transmissão     |
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
