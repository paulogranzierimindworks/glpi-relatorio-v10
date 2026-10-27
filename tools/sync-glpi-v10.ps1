<#
.SYNOPSIS
    Sincroniza a branch glpi-v10 do repositório upstream com a branch atual (main) via cherry-pick.

.DESCRIPTION
    Busca upstream/glpi-v10 e aplica, em ordem cronológica, os commits que ainda
    não existem na branch atual. Commits já aplicados (mesmo patch, ou registrados
    pelo trailer do "cherry-pick -x") são ignorados.

    Em caso de conflito o script para. Resolva os arquivos, faça "git add" e rode o
    script novamente: ele conclui o cherry-pick pendente (registrando um commit
    vazio se a resolução não trouxe mudanças) e segue com os próximos commits.
    Para desistir, use "git cherry-pick --abort".

.PARAMETER DryRun
    Apenas lista os commits pendentes, sem aplicar nada.

.PARAMETER Remote
    Nome do remote (padrão: upstream).

.PARAMETER Branch
    Branch do remote a sincronizar (padrão: glpi-v10).

.EXAMPLE
    ./tools/sync-glpi-v10.ps1 -DryRun
    ./tools/sync-glpi-v10.ps1
#>
[CmdletBinding()]
param(
    [switch]$DryRun,
    [string]$Remote = 'upstream',
    [string]$Branch = 'glpi-v10',
    [string]$RemoteUrl = 'https://github.com/Mindworks-Informatica/glpi-relatorio-comercial'
)

$ErrorActionPreference = 'Stop'

function Invoke-Git {
    $out = & git @args
    if ($LASTEXITCODE -ne 0) { throw "git $($args -join ' ') falhou (exit $LASTEXITCODE)" }
    return $out
}

Set-Location (Invoke-Git rev-parse --show-toplevel)

# Remote
if (-not ((Invoke-Git remote) -contains $Remote)) {
    Write-Host "Adicionando remote '$Remote' -> $RemoteUrl"
    Invoke-Git remote add $Remote $RemoteUrl | Out-Null
}

# Estado da árvore
$current = (Invoke-Git rev-parse --abbrev-ref HEAD)
if ($current -eq 'HEAD') { throw 'HEAD destacado; faça checkout da branch de destino (ex.: main).' }

$gitDir = (Invoke-Git rev-parse --git-dir)
if (-not $DryRun -and (Test-Path (Join-Path $gitDir 'CHERRY_PICK_HEAD'))) {
    # Retoma um cherry-pick interrompido por conflito
    if (Invoke-Git diff --name-only --diff-filter=U) {
        throw 'Ainda há arquivos em conflito; resolva, faça "git add" e rode o script novamente.'
    }
    if (Invoke-Git diff --cached --name-only) {
        & git -c core.editor=true cherry-pick --continue
        if ($LASTEXITCODE -ne 0) { throw 'git cherry-pick --continue falhou.' }
    } else {
        # Resolução vazia (mudança já existia na branch): registra commit vazio para não reaparecer como pendente
        $sha = (Invoke-Git rev-parse CHERRY_PICK_HEAD)
        $subject = (Invoke-Git log -1 --format=%s $sha)
        Invoke-Git commit --allow-empty -m $subject -m "(cherry picked from commit $sha)`nMudanças já presentes na branch; resolução vazia." | Out-Null
        Write-Host "Registrado como já aplicado: $subject"
    }
} elseif (-not $DryRun -and (Invoke-Git diff --cached --name-only)) {
    throw 'Há alterações staged; faça commit ou stash antes de sincronizar.'
}

Write-Host "Buscando $Remote/$Branch..."
Invoke-Git fetch $Remote $Branch | Out-Null

# git cherry: '+' = ainda não aplicado, '-' = já existe patch equivalente.
# Também ignora commits aplicados com conflito resolvido (o patch difere, mas o trailer do -x registra a origem).
$picked = (Invoke-Git log --format=%B $current) -join "`n"
$pending = @(Invoke-Git cherry $current "$Remote/$Branch" |
    Where-Object { $_ -like '+ *' } |
    ForEach-Object { $_.Substring(2).Trim() } |
    Where-Object { $picked -notmatch "cherry picked from commit $_" })

if ($pending.Count -eq 0) {
    Write-Host "'$current' já está sincronizada com $Remote/$Branch." -ForegroundColor Green
    return
}

Write-Host "`nCommits pendentes ($($pending.Count)) de $Remote/$Branch para '$current':"
foreach ($sha in $pending) {
    Write-Host ('  ' + (Invoke-Git log -1 --format='%h %s (%an)' $sha))
}

if ($DryRun) {
    Write-Host "`n(dry-run) nada foi aplicado."
    return
}

Write-Host ''
foreach ($sha in $pending) {
    $label = Invoke-Git log -1 --format='%h %s' $sha
    Write-Host "cherry-pick $label"
    & git cherry-pick -x $sha
    if ($LASTEXITCODE -ne 0 -and -not (Invoke-Git diff --name-only --diff-filter=U) -and -not (Invoke-Git diff --cached --name-only)) {
        # Pick vazio: a mudança já existe na branch
        Invoke-Git commit --allow-empty -m (Invoke-Git log -1 --format=%s $sha) -m "(cherry picked from commit $sha)`nMudanças já presentes na branch; commit vazio." | Out-Null
        Write-Host '  já aplicado (vazio); registrado.'
        continue
    }
    if ($LASTEXITCODE -ne 0) {
        Write-Host "`nConflito ao aplicar $label." -ForegroundColor Red
        Write-Host 'Resolva os arquivos, faça "git add" e rode o script novamente (ou use "git cherry-pick --abort").'
        exit 1
    }
}

Write-Host "`n$($pending.Count) commit(s) aplicado(s) em '$current'." -ForegroundColor Green
