# Changelog

Todas as mudanças relevantes deste plugin são documentadas neste arquivo.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o versionamento segue o [SemVer](https://semver.org/lang/pt-BR/).

Branch: `updates-request-25-09`

## [Não lançado]

### Adicionado
- Na aba **Destinatários** do grupo de transmissão, cada cliente pode selecionar um **grupo do GLPI**: os e-mails padrão dos usuários ativos do grupo são somados aos e-mails digitados, lidos no momento do envio (entrar ou sair do grupo vale para o próximo envio). Usuários do grupo sem e-mail são registrados no log do cron.

### Migração
- Nova coluna `groups_id` nos clientes do grupo (padrão 0 = nenhum grupo).

### Alterado
- O seed de dados de demonstração (`plugins:relatorioglpicomercial:seed_demo_data`) passa a gerar os chamados dentro de um único mês (opção `--month=AAAA-MM`, padrão: mês atual), sem datas no futuro. Inclui cliente com utilização acima de 100%, origens, localizações, status variados, SLAs de atendimento/solução no prazo e violados e notas de satisfação.

## [1.3.0] - 2026-10-23

### Adicionado
- **Anexo PDF no layout Mindworks** (`AttachmentComposer`, `AttachmentPdfDocument`), baseado no modelo `docs/email_tamplates/MINDWORKS_Relatório_Suporte_Especializado (1).html`: capa, aviso de confidencialidade, identificação do documento, sumário com números de página, painel de indicadores, relatório de chamados (Dashboard com gráficos e tabela), relatório comercial, justificativas e termo de aceite.
- Card **Anexo PDF** no grupo de transmissão (`AttachmentForm`): escolha e ordem das páginas, títulos, rodapés, textos com marcadores, metas de SLA e colunas da tabela de chamados (`AttachmentConfig`).
- **Pré-visualização** do anexo no formulário do grupo, com os valores ainda não salvos (`ajax/attachment_preview.php`).
- Dados do anexo por cliente (nº do contrato, responsável e cargo do aceite) na lista de destinatários.
- Painel de indicadores calculado a partir dos SLAs do dashboard (`AttachmentIndicators`).
- Testes `AttachmentConfigTest` e `AttachmentIndicatorsTest`.

### Alterado
- O PDF anexado pelos grupos passa a ser o relatório Mindworks, com nome de arquivo configurável; os PDFs baixados nas telas não mudam.
- Cards e gráficos de rosca do PDF foram extraídos de `ReportPdfDocument` para o trait `PdfCharts`. Os gráficos se adaptam a quatro painéis por linha.

### Migração
- Novas colunas `attachment_config` (grupos) e `contract_number`, `client_rep_name` e `client_rep_title` (clientes do grupo). Os grupos existentes recebem a configuração padrão do anexo.

## [1.2.0] - 2026-10-05

### Adicionado
- **Grupos de transmissão** para envio de relatórios por e-mail. Cada grupo possui tipo de relatório, agendamento, template, anexos e destinatários por entidade (`TransmissionGroup`, `TransmissionGroupEntity`).
- Telas de listagem e formulário dos grupos de transmissão (`front/transmissiongroup.php` e `front/transmissiongroup.form.php`).
- Envio por e-mail do **Dashboard de Chamados**, além do relatório comercial, com template padrão próprio (`templates/mail/default_body_dashboard.html`).
- Script `tools/sync-glpi-v10.ps1`.
- Testes para renderização de variáveis, validação de destinatários e agendamento (`ReportMailerTest`, `ReportSchedulerTest`, `TransmissionGroupEntityTest`).

### Alterado
- A configuração global única de e-mail foi substituída por grupos de transmissão nomeados. O cron e o e-mail de teste processam cada grupo de forma independente.
- Template padrão do relatório comercial renomeado de `default_body.html` para `default_body_comercial.html`.
- Atualização do README com a documentação dos grupos de transmissão.

### Removido
- `MailConfig` e a tela `front/config.form.php`, substituídos pelos grupos de transmissão. `EntityMailConfig` foi renomeada para `TransmissionGroupEntity`.

### Migração
- As configurações de e-mail existentes são migradas automaticamente para um grupo padrão, sem perda do comportamento atual.

## [1.1.0] - 2026-09-28

### Adicionado
- **Permissão própria do dashboard** (`Report::DASHBOARD_RIGHTNAME`), independente da permissão de relatórios.
- Checkbox no perfil para conceder o acesso ao dashboard separadamente.
- **Exportação do dashboard em PDF** (`front/dashboard_pdf.php`) em A4 paisagem, com KPIs, gráficos de rosca e tabela de chamados. A tabela usa heurísticas de largura e alinhamento das colunas e fontes adaptativas.
- Botão "Exportar PDF" na tela do dashboard.
- `DashboardReport::filtersFromRequest` para normalizar os filtros da requisição, com teste unitário.

### Alterado
- Páginas e endpoints AJAX do dashboard passam a usar a nova permissão.
- `ReportPdfDocument` ganhou desenho de gráficos de rosca, legendas e suporte a orientação da página.
- Ajuste no template PDF do dashboard.

### Migração
- Perfis que já tinham permissão de leitura em relatórios recebem automaticamente a permissão do dashboard. A desinstalação remove as duas permissões.

## [1.0.0] - 2026-09-25

Funcionalidades do dashboard desenvolvidas nesta branch, antes da introdução da permissão própria. O `setup.php` ainda marcava a versão 1.0.0 nesses commits.

### Adicionado
- **Dashboard de Chamados** no menu Ferramentas, com:
  - filtros por período, entidade, categoria, status e analista;
  - gráficos de rosca de SLA e de tipo de chamado;
  - resumo de KPIs;
  - horas por categoria;
  - tabela detalhada com horas previstas e registradas e métricas de satisfação.
- **Colunas configuráveis** na tabela de chamados, por usuário. A escolha é salva em `glpi_configs` e pode ser restaurada ao padrão pelo endpoint `ajax/dashboard_columns.php`.
- Novos dados na tabela: entidade, datas, impacto, grupos solicitante e atribuído, e estatísticas de SLA.
- **SLA de atendimento**, além do SLA de solução. O estado do SLA passa a considerar chamados em andamento, com rótulos e semântica de status alinhados à tabela do GLPI.
- Dados de demonstração (seed) com datas históricas de SLA de atendimento.
- Testes para as colunas, a sanitização e o comportamento das métricas de SLA (`DashboardReportTest`).
- Especificações de funcionalidade em `docs/feature/` e documentação no README.

### Alterado
- Novo layout do dashboard e dos gráficos de rosca.

## [Compatibilidade GLPI 10] - 2026-09-23

### Adicionado
- Camada de compatibilidade com o GLPI 10.0.x, incluindo uma implementação de `htmlescape()` usada quando o helper não existe.
- Carregamento do core do GLPI nos front controllers do plugin.
- Helper de bootstrap de sessão de usuário para o seed de dados de demonstração.

### Corrigido
- Codificação HTML dos valores de POST no GLPI 10 em `MailConfig`. Assunto e corpo salvos já codificados são reparados ao carregar, e os valores são escapados antes de gravar, o que evita consultas quebradas e injeção.
- Acesso ao relatório e emissão correta de aliases nas consultas ao banco no GLPI 10.
- Namespace do `QueryExpression`.
- Tratamento de parâmetros inválidos.
- Rótulos de "gerado em" quebrando linha nas visualizações exportadas.

### Alterado
- Faixa de versões do GLPI suportadas e metadados do plugin (`relatorioglpicomercial.xml`).
- APIs exclusivas do GLPI 11 substituídas por equivalentes do 10.x.
- Montagem do e-mail e do PDF adaptada ao fluxo do GLPI 10.

## [Inicial] - 2026-09-18

- Commit inicial do plugin Relatório GLPI Comercial.
