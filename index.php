<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

ensure_default_admin();
ensure_registration_requests_table();
ensure_security_tables();
ensure_membership_payment_columns();

$page    = $_GET['page'] ?? 'login';
$message = '';

if ($page === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

if ($page === 'export_associados') {
    require_admin();
    export_associados_xlsx();
    exit;
}

if ($page === 'import_associados') {
    require_admin();
    verify_csrf();
    $_SESSION['flash_success'] = import_associados_xlsx();
    header('Location: index.php?page=ferramentas');
    exit;
}

if ($page === 'restore_database') {
    require_admin();
    verify_csrf();
    try {
        $_SESSION['flash_success'] = restore_database_backup_upload();
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: index.php?page=ferramentas');
    exit;
}

if ($page === 'backup_database') {
    require_admin();
    $path = create_database_backup();
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        verify_csrf();
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (login_is_blocked($email)) {
            $message = 'Demasiadas tentativas falhadas. Aguarde 15 minutos e tente novamente.';
        } else {
            $stmt = db()->prepare(
                'SELECT u.*, e.estado AS empresa_estado
                 FROM utilizadores u
                 LEFT JOIN empresas_associadas e ON e.id = u.empresa_id
                 WHERE u.email = ?'
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['perfil'] === 'associado' && $user['empresa_estado'] === 'inativo') {
                    record_login_attempt($email, false);
                    $message = 'A conta da empresa não está ativa. Contacte a ACISJM.';
                } else {
                    clear_failed_login_attempts($email);
                    record_login_attempt($email, true);
                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'id'         => $user['id'],
                        'empresa_id' => $user['empresa_id'],
                        'nome'       => $user['nome'],
                        'email'      => $user['email'],
                        'perfil'     => $user['perfil'],
                    ];
                    header('Location: index.php?page=' . ($user['perfil'] === 'admin' ? 'dashboard' : 'associado'));
                    exit;
                }
            } else {
                record_login_attempt($email, false);
                $message = 'E-mail ou palavra-passe inválidos.';
            }
        }
    }

    if ($action === 'save_company') {
        require_admin();
        verify_csrf();
        try {
            save_company();
        } catch (InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        header('Location: index.php?page=empresas');
        exit;
    }

    if ($action === 'delete_company') {
        require_admin();
        verify_csrf();
        db()->prepare('DELETE FROM empresas_associadas WHERE id = ?')
            ->execute([(int) ($_POST['id'] ?? 0)]);
        header('Location: index.php?page=empresas');
        exit;
    }

    if ($action === 'approve_request') {
        require_admin();
        verify_csrf();
        approve_registration_request((int) ($_POST['id'] ?? 0));
        header('Location: index.php?page=empresas');
        exit;
    }

    if ($action === 'reject_request') {
        require_admin();
        verify_csrf();
        db()->prepare(
            "UPDATE solicitacoes_associado SET estado = 'rejeitado', analisado_em = NOW()
             WHERE id = ? AND estado = 'pendente'"
        )->execute([(int) ($_POST['id'] ?? 0)]);
        header('Location: index.php?page=empresas');
        exit;
    }

    if ($action === 'save_service') {
        require_admin();
        verify_csrf();
        save_service();
        header('Location: index.php?page=servicos');
        exit;
    }

    if ($action === 'delete_service') {
        require_admin();
        verify_csrf();
        db()->prepare('DELETE FROM servicos_iniciativas WHERE id = ?')
            ->execute([(int) ($_POST['id'] ?? 0)]);
        header('Location: index.php?page=servicos');
        exit;
    }

    if ($action === 'save_partner') {
        require_admin();
        verify_csrf();
        try {
            save_partner();
            $_SESSION['flash_success'] = 'Parceiro guardado com sucesso.';
        } catch (RuntimeException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        header('Location: index.php?page=parceiros');
        exit;
    }

    if ($action === 'delete_partner') {
        require_admin();
        verify_csrf();
        delete_partner_image((int) ($_POST['id'] ?? 0));
        db()->prepare('DELETE FROM parceiros_descontos WHERE id = ?')
            ->execute([(int) ($_POST['id'] ?? 0)]);
        header('Location: index.php?page=parceiros');
        exit;
    }
}

function export_associados_xlsx(): void
{
    $rows = db()->query(
        'SELECT numero_associado, nome_empresa, nome_comercial, nif, cae, setor_atividade, email,
         telefone, localidade, responsavel, numero_colaboradores, tipo_associado, estado, data_adesao
         FROM empresas_associadas ORDER BY nome_empresa'
    )->fetchAll(PDO::FETCH_ASSOC);

    $headers = [
        'numero_associado'     => 'Nº Associado',
        'nome_empresa'         => 'Nome da Empresa',
        'nome_comercial'       => 'Nome Comercial',
        'nif'                  => 'NIF',
        'cae'                  => 'CAE',
        'setor_atividade'      => 'Setor de Atividade',
        'email'                => 'E-mail',
        'telefone'             => 'Telefone',
        'localidade'           => 'Localidade',
        'responsavel'          => 'Responsável',
        'numero_colaboradores' => 'Nº Colaboradores',
        'tipo_associado'       => 'Tipo',
        'estado'               => 'Estado',
        'data_adesao'          => 'Data Adesão',
    ];

    $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/sheet"><sheetData>';

    $rowNum = 1;
    $xml   .= '<row r="' . $rowNum . '">';
    $col    = 0;
    foreach ($headers as $label) {
        $xml .= '<c r="' . xlsx_cell($col, $rowNum) . '" t="inlineStr" s="1"><is><t>' . xlsx_esc($label) . '</t></is></c>';
        $col++;
    }
    $xml .= '</row>';
    $rowNum++;

    foreach ($rows as $row) {
        $xml .= '<row r="' . $rowNum . '">';
        $col  = 0;
        foreach (array_keys($headers) as $key) {
            $xml .= '<c r="' . xlsx_cell($col, $rowNum) . '" t="inlineStr"><is><t>' . xlsx_esc((string) ($row[$key] ?? '')) . '</t></is></c>';
            $col++;
        }
        $xml .= '</row>';
        $rowNum++;
    }
    $xml .= '</sheetData></worksheet>';

    $styles  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $styles .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/sheet">';
    $styles .= '<fonts count="2"><font><sz val="11"/></font><font><b/><sz val="11"/></font></fonts>';
    $styles .= '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>';
    $styles .= '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>';
    $styles .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
    $styles .= '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs>';
    $styles .= '</styleSheet>';

    $rels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>';
    $rels .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $rels .= '</Relationships>';

    $workbook  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $workbook .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/sheet" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $workbook .= '<sheets><sheet name="Associados" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $contentTypes  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $contentTypes .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
    $contentTypes .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
    $contentTypes .= '<Default Extension="xml" ContentType="application/xml"/>';
    $contentTypes .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
    $contentTypes .= '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $contentTypes .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $contentTypes .= '</Types>';

    $rootRels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $rootRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $rootRels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
    $rootRels .= '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="associados-acisjm-' . date('Ymd') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
}

function xlsx_cell(int $col, int $row): string
{
    $letter = '';
    $c      = $col;
    do {
        $letter = chr(65 + ($c % 26)) . $letter;
        $c      = intdiv($c, 26) - 1;
    } while ($c >= 0);
    return $letter . $row;
}

function xlsx_esc(string $val): string
{
    return htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function import_associados_xlsx(): string
{
    if (empty($_FILES['ficheiro']['tmp_name'])) {
        return 'Nenhum ficheiro enviado.';
    }

    $tmp = $_FILES['ficheiro']['tmp_name'];
    $zip = new ZipArchive();

    if ($zip->open($tmp) !== true) {
        return 'Ficheiro inválido ou corrompido.';
    }

    $sheetXml  = $zip->getFromName('xl/worksheets/sheet1.xml');
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();

    if ($sheetXml === false) {
        return 'Não foi possível ler a folha de cálculo.';
    }

    $sharedStrings = [];
    if ($sharedXml !== false) {
        $sst = new SimpleXMLElement($sharedXml);
        foreach ($sst->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text = (string) $si->t;
            } else {
                foreach ($si->r as $r) {
                    $text .= (string) $r->t;
                }
            }
            $sharedStrings[] = $text;
        }
    }

    $sheet = new SimpleXMLElement($sheetXml);
    $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/sheet');
    $rows = $sheet->xpath('//s:sheetData/s:row');

    if (empty($rows)) {
        return 'A folha de cálculo está vazia.';
    }

    $colMap = [
        0  => 'numero_associado',
        1  => 'nome_empresa',
        2  => 'nome_comercial',
        3  => 'nif',
        4  => 'cae',
        5  => 'setor_atividade',
        6  => 'email',
        7  => 'telefone',
        8  => 'localidade',
        9  => 'responsavel',
        10 => 'numero_colaboradores',
        11 => 'tipo_associado',
        12 => 'estado',
        13 => 'data_adesao',
    ];

    $imported = 0;
    $skipped  = 0;
    $errors   = [];

    foreach ($rows as $rowIdx => $row) {
        if ($rowIdx === 0) {
            continue;
        }

        $cells = [];
        foreach ($row->c as $cell) {
            $ref  = (string) $cell['r'];
            $col  = xlsx_col_index($ref);
            $type = (string) $cell['t'];
            $val  = (string) $cell->v;

            if ($type === 's') {
                $val = $sharedStrings[(int) $val] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string) $cell->is->t;
            }

            $cells[$col] = trim($val);
        }

        $nif  = trim((string) ($cells[3] ?? ''));
        $nome = trim($cells[1] ?? '');

        if ($nome === '' || $nif === '') {
            $skipped++;
            continue;
        }

        $exists = db()->prepare('SELECT id FROM empresas_associadas WHERE nif = ?');
        $exists->execute([$nif]);
        $existingId = $exists->fetchColumn();

        $data = [];
        foreach ($colMap as $colIdx => $field) {
            $data[$field] = ($cells[$colIdx] ?? '') !== '' ? $cells[$colIdx] : null;
        }
        $data['nif'] = $nif;

        if (!in_array($data['estado'] ?? '', ['ativo', 'inativo', 'suspenso'], true)) {
            $data['estado'] = 'ativo';
        }

        if ($existingId) {
            $data['id'] = $existingId;
            db()->prepare(
                'UPDATE empresas_associadas SET
                 numero_associado=:numero_associado, nome_empresa=:nome_empresa, nome_comercial=:nome_comercial,
                 nif=:nif, cae=:cae, setor_atividade=:setor_atividade, email=:email, telefone=:telefone,
                 localidade=:localidade, responsavel=:responsavel, numero_colaboradores=:numero_colaboradores,
                 tipo_associado=:tipo_associado, estado=:estado, data_adesao=:data_adesao
                 WHERE id=:id'
            )->execute($data);
        } else {
            db()->prepare(
                'INSERT INTO empresas_associadas
                 (numero_associado, nome_empresa, nome_comercial, nif, cae, setor_atividade, email,
                  telefone, localidade, responsavel, numero_colaboradores, tipo_associado, estado, data_adesao)
                 VALUES
                 (:numero_associado, :nome_empresa, :nome_comercial, :nif, :cae, :setor_atividade, :email,
                  :telefone, :localidade, :responsavel, :numero_colaboradores, :tipo_associado, :estado, :data_adesao)'
            )->execute($data);
            ensure_qr_code((int) db()->lastInsertId());
        }

        $imported++;
    }

    $msg = $imported . ' associado(s) importado(s) ou atualizado(s).';
    if ($skipped) $msg .= ' ' . $skipped . ' linha(s) ignorada(s).';
    if ($errors)  $msg .= ' Erros: ' . implode('; ', $errors);

    return $msg;
}

function xlsx_col_index(string $cellRef): int
{
    preg_match('/^([A-Z]+)/', $cellRef, $m);
    $letters = $m[1] ?? 'A';
    $idx     = 0;
    foreach (str_split($letters) as $char) {
        $idx = $idx * 26 + (ord($char) - 64);
    }
    return $idx - 1;
}

function save_company(): void
{
    $fields = [
        'numero_associado', 'nome_empresa', 'nome_comercial', 'nif', 'cae', 'setor_atividade',
        'email', 'telefone', 'morada', 'codigo_postal', 'localidade', 'responsavel',
        'numero_colaboradores', 'tipo_associado', 'quota_plano', 'quota_estado', 'quota_pago_em',
        'quota_validade', 'estado', 'data_adesao', 'observacoes_internas',
    ];
    $data = [];

    foreach ($fields as $field) {
        $value        = $_POST[$field] ?? null;
        $data[$field] = $value === '' ? null : $value;
    }
    $data['nif'] = trim((string) $data['nif']);

    if (!in_array($data['estado'], ['ativo', 'inativo', 'suspenso'], true)) {
        $data['estado'] = 'ativo';
    }

    if (!array_key_exists((string) $data['quota_plano'], membership_plans())) {
        $data['quota_plano'] = null;
    }
    $data['quota_valor'] = membership_plan_value($data['quota_plano']);
    if (!in_array((string) $data['quota_estado'], ['pendente', 'pago', 'atrasado', 'isento'], true)) {
        $data['quota_estado'] = 'pendente';
    }
    if ($data['quota_estado'] === 'pago' && empty($data['quota_pago_em'])) {
        $data['quota_pago_em'] = date('Y-m-d');
    }
    if (in_array($data['quota_estado'], ['pendente', 'atrasado'], true)) {
        $data['estado'] = 'suspenso';
    } elseif (in_array($data['quota_estado'], ['pago', 'isento'], true) && $data['estado'] === 'suspenso') {
        $data['estado'] = 'ativo';
    }

    if (!empty($_POST['id'])) {
        $data['id'] = (int) $_POST['id'];
        db()->prepare(
            'UPDATE empresas_associadas SET
             numero_associado=:numero_associado, nome_empresa=:nome_empresa, nome_comercial=:nome_comercial,
             nif=:nif, cae=:cae, setor_atividade=:setor_atividade, email=:email, telefone=:telefone,
             morada=:morada, codigo_postal=:codigo_postal, localidade=:localidade, responsavel=:responsavel,
             numero_colaboradores=:numero_colaboradores, tipo_associado=:tipo_associado,
             quota_plano=:quota_plano, quota_valor=:quota_valor, quota_estado=:quota_estado,
             quota_pago_em=:quota_pago_em, quota_validade=:quota_validade, estado=:estado,
             data_adesao=:data_adesao, observacoes_internas=:observacoes_internas
             WHERE id=:id'
        )->execute($data);
        ensure_qr_code((int) $data['id']);
        return;
    }

    db()->prepare(
        'INSERT INTO empresas_associadas
         (numero_associado, nome_empresa, nome_comercial, nif, cae, setor_atividade, email, telefone,
          morada, codigo_postal, localidade, responsavel, numero_colaboradores, tipo_associado,
          quota_plano, quota_valor, quota_estado, quota_pago_em, quota_validade,
          estado, data_adesao, observacoes_internas)
         VALUES
         (:numero_associado, :nome_empresa, :nome_comercial, :nif, :cae, :setor_atividade, :email,
          :telefone, :morada, :codigo_postal, :localidade, :responsavel, :numero_colaboradores,
          :tipo_associado, :quota_plano, :quota_valor, :quota_estado, :quota_pago_em, :quota_validade,
          :estado, :data_adesao, :observacoes_internas)'
    )->execute($data);

    $empresaId = (int) db()->lastInsertId();
    ensure_qr_code($empresaId);

    if (!empty($data['email'])) {
        db()->prepare(
            'INSERT IGNORE INTO utilizadores (empresa_id, nome, email, password, perfil) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $empresaId,
            $data['responsavel'] ?: $data['nome_empresa'],
            $data['email'],
            password_hash('Associado123!', PASSWORD_DEFAULT),
            'associado',
        ]);
    }
}

function approve_registration_request(int $requestId): void
{
    $stmt = db()->prepare("SELECT * FROM solicitacoes_associado WHERE id = ? AND estado = 'pendente'");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();

    if (!$request) {
        return;
    }

    db()->beginTransaction();

    try {
        $numeroAssociado = 'SOL-' . str_pad((string) $requestId, 5, '0', STR_PAD_LEFT);
        db()->prepare(
            'INSERT INTO empresas_associadas
             (numero_associado, nome_empresa, nif, setor_atividade, email, telefone, localidade, responsavel,
              quota_plano, quota_valor, quota_estado, comprovativo_quota, estado, data_adesao, observacoes_internas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)'
        )->execute([
            $numeroAssociado,
            $request['nome_empresa'],
            $request['nif'],
            $request['setor_atividade'],
            $request['email'],
            $request['telefone'],
            $request['localidade'],
            $request['responsavel'],
            $request['quota_plano'] ?: null,
            $request['quota_valor'] ?: membership_plan_value($request['quota_plano'] ?? null),
            'pendente',
            $request['comprovativo_quota'] ?: null,
            'suspenso',
            'Criado a partir da solicitação #' . $requestId . '. Quota pendente de validação.',
        ]);

        $empresaId = (int) db()->lastInsertId();
        ensure_qr_code($empresaId);

        db()->prepare(
            'INSERT INTO utilizadores (empresa_id, nome, email, password, perfil) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $empresaId,
            $request['responsavel'],
            $request['email'],
            $request['password_hash'],
            'associado',
        ]);

        db()->prepare("UPDATE solicitacoes_associado SET estado = 'aprovado', analisado_em = NOW() WHERE id = ?")
            ->execute([$requestId]);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}

function save_service(): void
{
    $data = [
        'titulo'         => trim($_POST['titulo'] ?? ''),
        'descricao'      => trim($_POST['descricao'] ?? ''),
        'categoria'      => trim($_POST['categoria'] ?? ''),
        'data_inicio'    => ($_POST['data_inicio'] ?? '') !== '' ? $_POST['data_inicio'] : null,
        'data_fim'       => ($_POST['data_fim'] ?? '') !== '' ? $_POST['data_fim'] : null,
        'local_evento'   => trim($_POST['local_evento'] ?? ''),
        'link_inscricao' => trim($_POST['link_inscricao'] ?? ''),
        'estado'         => $_POST['estado'] ?? 'ativo',
    ];

    if (!empty($_POST['id'])) {
        $data['id'] = (int) $_POST['id'];
        db()->prepare(
            'UPDATE servicos_iniciativas SET titulo=:titulo, descricao=:descricao, categoria=:categoria,
             data_inicio=:data_inicio, data_fim=:data_fim, local_evento=:local_evento,
             link_inscricao=:link_inscricao, estado=:estado WHERE id=:id'
        )->execute($data);
        return;
    }

    db()->prepare(
        'INSERT INTO servicos_iniciativas
         (titulo, descricao, categoria, data_inicio, data_fim, local_evento, link_inscricao, estado)
         VALUES (:titulo, :descricao, :categoria, :data_inicio, :data_fim, :local_evento, :link_inscricao, :estado)'
    )->execute($data);
}

function save_partner(): void
{
    $data = [
        'nome_parceiro'       => trim($_POST['nome_parceiro'] ?? ''),
        'descricao_beneficio' => trim($_POST['descricao_beneficio'] ?? ''),
        'desconto'            => trim($_POST['desconto'] ?? ''),
        'condicoes'           => trim($_POST['condicoes'] ?? ''),
        'contacto'            => trim($_POST['contacto'] ?? ''),
        'estado'              => $_POST['estado'] ?? 'ativo',
    ];

    if (!empty($_POST['id'])) {
        $data['id'] = (int) $_POST['id'];
        db()->prepare(
            'UPDATE parceiros_descontos SET nome_parceiro=:nome_parceiro,
             descricao_beneficio=:descricao_beneficio, desconto=:desconto, condicoes=:condicoes,
             contacto=:contacto, estado=:estado WHERE id=:id'
        )->execute($data);
        save_partner_image($data['id']);
        return;
    }

    db()->prepare(
        'INSERT INTO parceiros_descontos
         (nome_parceiro, descricao_beneficio, desconto, condicoes, contacto, estado)
         VALUES (:nome_parceiro, :descricao_beneficio, :desconto, :condicoes, :contacto, :estado)'
    )->execute($data);
    save_partner_image((int) db()->lastInsertId());
}

function partner_upload_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'parceiros';
}

function partner_image_file(int $partnerId): ?string
{
    foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $extension) {
        $file = partner_upload_dir() . DIRECTORY_SEPARATOR . 'parceiro-' . $partnerId . '.' . $extension;
        if (is_file($file)) {
            return $file;
        }
    }

    return null;
}

function partner_image_url(int $partnerId): ?string
{
    $file = partner_image_file($partnerId);
    if (!$file) {
        return null;
    }

    return 'uploads/parceiros/' . basename($file) . '?v=' . filemtime($file);
}

function delete_partner_image(int $partnerId): void
{
    $file = partner_image_file($partnerId);
    if ($file && is_file($file)) {
        unlink($file);
    }
}

function save_partner_image(int $partnerId): void
{
    if (empty($_FILES['imagem_parceiro']) || ($_FILES['imagem_parceiro']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return;
    }

    if (($_FILES['imagem_parceiro']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Não foi possível carregar a imagem do parceiro.');
    }

    if ((int) ($_FILES['imagem_parceiro']['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('A imagem do parceiro não pode ultrapassar 5 MB.');
    }

    $tmp = (string) ($_FILES['imagem_parceiro']['tmp_name'] ?? '');
    $info = @getimagesize($tmp);
    if (!$info || empty($info['mime'])) {
        throw new RuntimeException('O ficheiro enviado não é uma imagem válida.');
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    if (!isset($extensions[$info['mime']])) {
        throw new RuntimeException('Use uma imagem JPG, PNG, WEBP ou GIF.');
    }

    $dir = partner_upload_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    delete_partner_image($partnerId);

    $destination = $dir . DIRECTORY_SEPARATOR . 'parceiro-' . $partnerId . '.' . $extensions[$info['mime']];
    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Não foi possível guardar a imagem do parceiro.');
    }
}

function create_database_backup(): string
{
    $backupDir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0775, true);
    }

    $file   = $backupDir . DIRECTORY_SEPARATOR . 'backup-acisjm-' . date('Ymd-His') . '.sql';
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $sql    = "-- Backup ACISJM\n-- Gerado em " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $tableName  = (string) $table;
        $createStmt = db()->query('SHOW CREATE TABLE `' . str_replace('`', '``', $tableName) . '`')->fetch();
        $createSql  = $createStmt['Create Table'] ?? array_values($createStmt)[1] ?? '';

        $sql .= "DROP TABLE IF EXISTS `" . str_replace('`', '``', $tableName) . "`;\n";
        $sql .= $createSql . ";\n\n";

        $rows = db()->query('SELECT * FROM `' . str_replace('`', '``', $tableName) . '`')->fetchAll();
        foreach ($rows as $row) {
            $columns = array_map(
                fn ($col) => '`' . str_replace('`', '``', (string) $col) . '`',
                array_keys($row)
            );
            $values = array_map(
                fn ($val) => $val === null ? 'NULL' : db()->quote((string) $val),
                array_values($row)
            );
            $sql .= 'INSERT INTO `' . str_replace('`', '``', $tableName) . '` ('
                . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    file_put_contents($file, $sql);

    return $file;
}

function sql_statements(string $sql): array
{
    $statements = [];
    $current = '';
    $quote = null;
    $escape = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $current .= $char;

        if ($escape) {
            $escape = false;
            continue;
        }

        if ($quote !== null && $char === '\\') {
            $escape = true;
            continue;
        }

        if ($quote !== null) {
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            continue;
        }

        if ($char === ';') {
            $statement = trim(substr($current, 0, -1));
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
        }
    }

    $statement = trim($current);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

function sql_without_line_comments(string $statement): string
{
    $lines = preg_split('/\R/', $statement) ?: [];
    $lines = array_filter($lines, fn ($line) => !str_starts_with(ltrim($line), '--'));

    return trim(implode("\n", $lines));
}

function normalized_create_sql(string $sql): string
{
    $sql = preg_replace('/AUTO_INCREMENT=\d+\s*/i', '', $sql) ?? $sql;
    $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

    return strtolower($sql);
}

function backup_create_statements(string $sql): array
{
    $creates = [];

    foreach (sql_statements($sql) as $statement) {
        $statement = sql_without_line_comments($statement);
        if (preg_match('/^CREATE\s+TABLE\s+`([^`]+)`/i', $statement, $matches)) {
            $creates[$matches[1]] = normalized_create_sql($statement);
        }
    }

    ksort($creates);

    return $creates;
}

function current_create_statements(): array
{
    $creates = [];
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $table) {
        $tableName = (string) $table;
        $stmt = db()->query('SHOW CREATE TABLE `' . str_replace('`', '``', $tableName) . '`')->fetch();
        $createSql = $stmt['Create Table'] ?? array_values($stmt)[1] ?? '';
        $creates[$tableName] = normalized_create_sql((string) $createSql);
    }

    ksort($creates);

    return $creates;
}

function validate_backup_sql(string $sql): array
{
    if (!str_contains($sql, '-- Backup ACISJM')) {
        throw new RuntimeException('O ficheiro não parece ser um backup gerado por esta aplicação.');
    }

    foreach (sql_statements($sql) as $statement) {
        $statement = sql_without_line_comments($statement);
        if (preg_match('/^(CREATE|DROP)\s+DATABASE\b|^USE\s+`?\w+`?\b|^LOAD\s+DATA\b/i', $statement)) {
            throw new RuntimeException('O backup contém instruções que não são aceites neste restore.');
        }
    }

    $backupCreates = backup_create_statements($sql);
    $currentCreates = current_create_statements();

    if (array_keys($backupCreates) !== array_keys($currentCreates)) {
        throw new RuntimeException('A estrutura do backup não corresponde às tabelas atuais da aplicação.');
    }

    foreach ($currentCreates as $table => $currentCreate) {
        if (($backupCreates[$table] ?? '') !== $currentCreate) {
            throw new RuntimeException('A estrutura da tabela "' . $table . '" não corresponde à estrutura atual.');
        }
    }

    return array_keys($currentCreates);
}

function restore_database_backup_upload(): string
{
    if (empty($_FILES['backup_sql']['tmp_name']) || !is_uploaded_file($_FILES['backup_sql']['tmp_name'])) {
        throw new RuntimeException('Selecione um ficheiro de backup SQL.');
    }

    if (($_FILES['backup_sql']['size'] ?? 0) > 25 * 1024 * 1024) {
        throw new RuntimeException('O backup é demasiado grande. Limite máximo: 25 MB.');
    }

    $name = (string) ($_FILES['backup_sql']['name'] ?? '');
    if (!str_ends_with(strtolower($name), '.sql')) {
        throw new RuntimeException('O ficheiro de backup tem de ser .sql.');
    }

    $sql = file_get_contents($_FILES['backup_sql']['tmp_name']);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Não foi possível ler o ficheiro de backup.');
    }

    $allowedTables = validate_backup_sql($sql);
    $allowedTableLookup = array_flip($allowedTables);
    $safetyBackup = create_database_backup();

    try {
        foreach (sql_statements($sql) as $statement) {
            $statement = sql_without_line_comments($statement);
            if ($statement === '') {
                continue;
            }

            if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]$/i', $statement)) {
                db()->exec($statement);
                continue;
            }

            if (preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`([^`]+)`$/i', $statement, $matches)) {
                if (!isset($allowedTableLookup[$matches[1]])) {
                    throw new RuntimeException('O backup tenta apagar uma tabela inesperada.');
                }
                db()->exec($statement);
                continue;
            }

            if (preg_match('/^CREATE\s+TABLE\s+`([^`]+)`/i', $statement, $matches)) {
                if (!isset($allowedTableLookup[$matches[1]])) {
                    throw new RuntimeException('O backup tenta criar uma tabela inesperada.');
                }
                db()->exec($statement);
                continue;
            }

            if (preg_match('/^INSERT\s+INTO\s+`([^`]+)`/i', $statement, $matches)) {
                if (!isset($allowedTableLookup[$matches[1]])) {
                    throw new RuntimeException('O backup tenta inserir dados numa tabela inesperada.');
                }
                db()->exec($statement);
                continue;
            }

            throw new RuntimeException('O backup contém uma instrução não permitida.');
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Restore interrompido: ' . $e->getMessage() . ' Foi criado um backup de segurança em ' . basename($safetyBackup) . '.');
    }

    return 'Backup restaurado com sucesso. Antes do restore foi criado um backup de segurança: ' . basename($safetyBackup) . '.';
}

function render_registration_requests(): string
{
    $rows = db()->query(
        "SELECT * FROM solicitacoes_associado WHERE estado = 'pendente' ORDER BY criado_em DESC"
    )->fetchAll();

    $html  = '<section class="card requests-card">';
    $html .= '<div class="requests-header">';
    $html .= '<div><h3>Solicitações</h3><p class="muted">Pedidos de novas empresas para serem associadas.</p></div>';
    $html .= '<strong>' . count($rows) . '</strong>';
    $html .= '</div>';

    if (!$rows) {
        return $html . empty_state('Não existem solicitações pendentes.') . '</section><br>';
    }

    $html .= '<div class="table-wrap"><table><thead><tr>';
    $html .= '<th>Empresa</th><th>NIF</th><th>Responsável</th><th>Contacto</th><th>Quota</th><th>Data</th><th>Ações</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>';
        $html .= '<td data-label="Empresa"><strong>' . e($row['nome_empresa']) . '</strong><br><span class="muted">' . e($row['setor_atividade'] ?: '-') . ' &middot; ' . e($row['localidade'] ?: '-') . '</span></td>';
        $html .= '<td data-label="NIF">' . e($row['nif']) . '</td>';
        $html .= '<td data-label="Responsável">' . e($row['responsavel']) . '</td>';
        $html .= '<td data-label="Contacto"><a href="mailto:' . e($row['email']) . '">' . e($row['email']) . '</a><br><span class="muted">' . e($row['telefone'] ?: '-') . '</span></td>';
        $html .= '<td data-label="Quota"><strong>' . e(membership_plan_label($row['quota_plano'] ?? null)) . '</strong>';
        $html .= !empty($row['comprovativo_quota']) ? '<br><a href="' . e($row['comprovativo_quota']) . '" target="_blank" rel="noreferrer">Ver comprovativo</a>' : '<br><span class="badge warn">sem comprovativo</span>';
        $html .= '</td>';
        $html .= '<td data-label="Data">' . e($row['criado_em']) . '</td>';
        $html .= '<td data-label="Ações" class="actions">';
        $html .= '<form class="inline-form" method="post">' . csrf_input() . '<input type="hidden" name="action" value="approve_request"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button type="submit">Aprovar</button></form>';
        $html .= '<form class="inline-form" method="post">' . csrf_input() . '<input type="hidden" name="action" value="reject_request"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><button class="danger" type="submit">Rejeitar</button></form>';
        $html .= '</td></tr>';
    }

    return $html . '</tbody></table></div></section><br>';
}

function layout(string $title, string $subtitle, string $content): void
{
    $user    = current_user();
    $isAdmin = $user && $user['perfil'] === 'admin';
    $flash   = '';

    if (!empty($_SESSION['flash_error'])) {
        $flash = '<div class="alert danger">' . e((string) $_SESSION['flash_error']) . '</div><br>';
        unset($_SESSION['flash_error']);
    } elseif (!empty($_SESSION['flash_success'])) {
        $flash = '<div class="alert ok">' . e((string) $_SESSION['flash_success']) . '</div><br>';
        unset($_SESSION['flash_success']);
    }

    $items = $isAdmin
        ? ['dashboard' => 'Painel', 'empresas' => 'Associados', 'servicos' => 'Serviços', 'parceiros' => 'Parceiros', 'ferramentas' => 'Ferramentas']
        : ['associado' => 'Área do associado', 'servicos' => 'Serviços', 'parceiros' => 'Benefícios', 'informacoes' => 'Informações relevantes'];

    echo '<!doctype html><html lang="pt-PT">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<title>' . e($title) . ' - ACISJM</title>';
    echo '<meta name="theme-color" content="#ff5100">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-title" content="ACISJM">';
    echo '<link rel="manifest" href="manifest.json">';
    echo '<link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png">';
    echo '<link rel="stylesheet" href="styles.css?v=12">';
    echo '</head><body>';
    echo '<div class="app-shell">';
    echo '<aside class="sidebar">';
    echo '<div class="brand"><img class="brand-logo" src="assets/aci-sjm-marca.png" alt="ACISJM"><div><h1>ACISJM</h1><p>' . e($isAdmin ? 'Administração' : 'Associado') . '</p></div></div>';
    echo '<nav class="nav">';
    $infoSubPages = ['laboratorios', 'rgpd', 'estatutos', 'diretores'];
    $currentPage  = $_GET['page'] ?? '';
    foreach ($items as $key => $label) {
        $isActive = $currentPage === $key || ($key === 'informacoes' && in_array($currentPage, $infoSubPages, true));
        $active   = $isActive ? ' active' : '';
        echo '<button class="' . $active . '" onclick="location.href=\'index.php?page=' . e($key) . '\'">' . e($label) . '</button>';
    }
    echo '</nav>';
    echo '<div class="sidebar-footer"><strong>' . e($user['nome']) . '</strong><br>' . e($user['email']) . '<br><br><button class="secondary" onclick="location.href=\'index.php?page=logout\'">Terminar sessão</button></div>';
    echo '</aside>';
    echo '<main class="main">';
    echo '<div class="topbar"><div class="page-title"><h2>' . e($title) . '</h2><p>' . e($subtitle) . '</p></div></div>';
    echo $flash . $content;
    echo '</main></div>';
    echo '<script src="pwa.js"></script>';
    echo '</body></html>';
}

function company_form(?array $company): string
{
    $c = $company ?? [
        'id' => '', 'numero_associado' => '', 'nome_empresa' => '', 'nome_comercial' => '', 'nif' => '',
        'cae' => '', 'setor_atividade' => '', 'email' => '', 'telefone' => '', 'morada' => '',
        'codigo_postal' => '', 'localidade' => '', 'responsavel' => '', 'numero_colaboradores' => '',
        'tipo_associado' => '', 'quota_plano' => '', 'quota_estado' => 'pendente',
        'quota_pago_em' => '', 'quota_validade' => '', 'comprovativo_quota' => '',
        'estado' => 'ativo', 'data_adesao' => '', 'observacoes_internas' => '',
    ];

    $labels = [
        'nome_empresa'         => 'Nome da empresa',
        'nome_comercial'       => 'Nome comercial',
        'nif'                  => 'NIF',
        'numero_associado'     => 'Número de associado',
        'cae'                  => 'CAE',
        'setor_atividade'      => 'Setor de atividade',
        'morada'               => 'Morada',
        'codigo_postal'        => 'Código postal',
        'localidade'           => 'Localidade',
        'email'                => 'E-mail',
        'telefone'             => 'Telefone',
        'responsavel'          => 'Nome do responsável',
        'numero_colaboradores' => 'Número de colaboradores',
        'tipo_associado'       => 'Tipo de associado',
    ];

    $html  = '<form class="panel form-panel" method="post">';
    $html .= csrf_input();
    $html .= '<input type="hidden" name="action" value="save_company">';
    $html .= '<input type="hidden" name="id" value="' . e((string) $c['id']) . '">';
    $html .= '<h3>' . ($c['id'] ? 'Editar associado' : 'Adicionar associado') . '</h3>';
    $html .= '<div class="grid three">';

    foreach ($labels as $name => $label) {
        $type     = $name === 'email' ? 'email' : ($name === 'numero_colaboradores' ? 'number' : 'text');
        $required = in_array($name, ['nome_empresa', 'nif', 'numero_associado'], true) ? ' required' : '';
        $html    .= '<label>' . e($label) . '<input type="' . $type . '" name="' . e($name) . '" value="' . e((string) $c[$name]) . '"' . $required . '></label>';
    }

    $html .= '<label>Estado<select name="estado">';
    foreach (['ativo', 'inativo', 'suspenso'] as $status) {
        $html .= '<option value="' . $status . '"' . ($c['estado'] === $status ? ' selected' : '') . '>' . $status . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<label>Quota<select name="quota_plano"><option value="">Selecionar quota</option>';
    foreach (membership_plans() as $planKey => $plan) {
        $html .= '<option value="' . e($planKey) . '"' . (($c['quota_plano'] ?? '') === $planKey ? ' selected' : '') . '>' . e($plan['label']) . ' - ' . number_format($plan['value'], 2, ',', '.') . '€</option>';
    }
    $html .= '</select></label>';
    $html .= '<label>Estado da quota<select name="quota_estado">';
    foreach (['pendente', 'pago', 'atrasado', 'isento'] as $quotaStatus) {
        $html .= '<option value="' . $quotaStatus . '"' . (($c['quota_estado'] ?? 'pendente') === $quotaStatus ? ' selected' : '') . '>' . $quotaStatus . '</option>';
    }
    $html .= '</select></label>';
    $html .= '<label>Pago em<input type="date" name="quota_pago_em" value="' . e((string) ($c['quota_pago_em'] ?? '')) . '"></label>';
    $html .= '<label>Quota válida até<input type="date" name="quota_validade" value="' . e((string) ($c['quota_validade'] ?? '')) . '"></label>';
    $html .= '<label>Data de adesão<input type="date" name="data_adesao" value="' . e((string) $c['data_adesao']) . '"></label>';
    $html .= '</div><br>';
    $html .= '<label>Observações internas<textarea name="observacoes_internas">' . e((string) $c['observacoes_internas']) . '</textarea></label>';
    $html .= '<div class="form-actions"><a class="button-link" href="index.php?page=empresas">Cancelar</a><button type="submit">Guardar</button></div>';
    $html .= '</form>';

    return $html;
}

function service_form(?array $service): string
{
    $s = $service ?? [
        'id' => '', 'titulo' => '', 'descricao' => '', 'categoria' => '',
        'data_inicio' => '', 'data_fim' => '', 'local_evento' => '', 'link_inscricao' => '', 'estado' => 'ativo',
    ];

    $html  = '<form class="panel form-panel" method="post">';
    $html .= csrf_input();
    $html .= '<input type="hidden" name="action" value="save_service">';
    $html .= '<input type="hidden" name="id" value="' . e((string) $s['id']) . '">';
    $html .= '<h3>' . ($s['id'] ? 'Editar serviço ou iniciativa' : 'Novo serviço ou iniciativa') . '</h3>';
    $html .= '<div class="grid two">';
    $html .= '<label>Título<input name="titulo" required value="' . e($s['titulo']) . '"></label>';
    $html .= '<label>Categoria<input name="categoria" value="' . e($s['categoria']) . '"></label>';
    $html .= '<label>Data de início<input type="date" name="data_inicio" value="' . e($s['data_inicio']) . '"></label>';
    $html .= '<label>Data de fim<input type="date" name="data_fim" value="' . e($s['data_fim']) . '"></label>';
    $html .= '<label>Local<input name="local_evento" value="' . e($s['local_evento']) . '"></label>';
    $html .= '<label>Link de inscrição<input name="link_inscricao" value="' . e($s['link_inscricao']) . '"></label>';
    $html .= '<label>Estado<select name="estado">';

    foreach (['ativo', 'concluido', 'em_preparacao'] as $status) {
        $label = match ($status) {
            'concluido'     => 'concluído',
            'em_preparacao' => 'em preparação',
            default         => $status,
        };
        $html .= '<option value="' . $status . '"' . ($s['estado'] === $status ? ' selected' : '') . '>' . $label . '</option>';
    }

    $html .= '</select></label></div><br>';
    $html .= '<label>Descrição<textarea name="descricao">' . e($s['descricao']) . '</textarea></label>';
    $html .= '<div class="form-actions"><a class="button-link" href="index.php?page=servicos">Cancelar</a><button type="submit">Guardar</button></div>';
    $html .= '</form>';

    return $html;
}

function partner_form(?array $partner): string
{
    $p = $partner ?? [
        'id' => '', 'nome_parceiro' => '', 'descricao_beneficio' => '',
        'desconto' => '', 'condicoes' => '', 'contacto' => '', 'estado' => 'ativo',
    ];
    $imageUrl = !empty($p['id']) ? partner_image_url((int) $p['id']) : null;

    $html  = '<form class="panel form-panel partner-editor" method="post" enctype="multipart/form-data">';
    $html .= csrf_input();
    $html .= '<input type="hidden" name="action" value="save_partner">';
    $html .= '<input type="hidden" name="id" value="' . e((string) $p['id']) . '">';
    $html .= '<div class="partner-editor-head"><div><span class="eyebrow">Benefício</span><h3>' . ($p['id'] ? 'Editar parceiro' : 'Novo parceiro') . '</h3></div><a class="button-link" href="index.php?page=parceiros">Fechar</a></div>';
    $html .= '<div class="grid two">';
    $html .= '<label>Nome do parceiro<input name="nome_parceiro" required value="' . e($p['nome_parceiro']) . '"></label>';
    $html .= '<label>Desconto<input name="desconto" placeholder="Ex: 15% desconto" value="' . e($p['desconto']) . '"></label>';
    $html .= '<label>Contacto<input name="contacto" value="' . e($p['contacto']) . '"></label>';
    $html .= '<label>Estado<select name="estado">';

    foreach (['ativo', 'inativo'] as $status) {
        $html .= '<option value="' . $status . '"' . ($p['estado'] === $status ? ' selected' : '') . '>' . $status . '</option>';
    }

    $html .= '</select></label></div><br>';
    $html .= '<div class="grid two partner-upload-row">';
    $html .= '<label>Imagem do parceiro<input type="file" name="imagem_parceiro" accept="image/png,image/jpeg,image/webp,image/gif"></label>';
    $html .= '<div class="partner-image-preview">' . ($imageUrl ? '<img src="' . e($imageUrl) . '" alt="Imagem atual de ' . e($p['nome_parceiro']) . '">' : '<span>Sem imagem carregada</span>') . '</div>';
    $html .= '</div><br>';
    $html .= '<label>Descrição do benefício<textarea name="descricao_beneficio">' . e($p['descricao_beneficio']) . '</textarea></label><br>';
    $html .= '<label>Condições<textarea name="condicoes">' . e($p['condicoes']) . '</textarea></label>';
    $html .= '<div class="form-actions"><a class="button-link" href="index.php?page=parceiros">Cancelar</a><button type="submit">Guardar parceiro</button></div>';
    $html .= '</form>';

    return $html;
}

function delete_form(string $action, int $id, string $label): string
{
    return '<form method="post" class="inline-form" onsubmit="return confirm(\'Tem a certeza que pretende apagar este registo?\')">'
        . csrf_input()
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button class="danger" type="submit">' . e($label) . '</button>'
        . '</form>';
}

function detail(string $label, ?string $value): string
{
    return '<div class="detail-item"><span>' . e($label) . '</span><strong>' . e($value ?: '-') . '</strong></div>';
}

function empty_state(string $text): string
{
    return '<div class="empty-state">' . e($text) . '</div>';
}

function payment_required_card(?array $company): string
{
    $plan = membership_plan_label($company['quota_plano'] ?? null);
    $status = $company['quota_estado'] ?? 'pendente';

    return '<section class="card payment-required">'
        . '<h3>Quota de associado por validar</h3>'
        . '<p class="muted">Para aceder a benefícios, laboratórios e restantes vantagens reservadas, a quota tem de estar paga e validada pela administração.</p>'
        . '<div class="detail-list">'
        . detail('Plano escolhido', $plan)
        . detail('Estado da quota', $status)
        . detail('IBAN para transferência', 'PT50 0018 2188 0219 6209 0202 9')
        . detail('Contacto', 'secretaria@acisjm.pt')
        . '</div>'
        . '<p class="muted">Se já efetuou o pagamento, envie o comprovativo para a ACISJM ou aguarde validação administrativa.</p>'
        . '</section>';
}

function render_company_detail(array $company, bool $internal): string
{
    $code          = ensure_qr_code((int) $company['id']);
    $validationUrl = app_base_url() . '/index.php?page=validar&code=' . urlencode($code);
    $qrUrl         = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($validationUrl);

    $html  = '<section class="grid two">';
    $html .= '<div class="card qr-box"><h3>QR Code do associado</h3>';
    $html .= '<img class="qr-img" src="' . e($qrUrl) . '" alt="QR Code do associado" width="220" height="220">';
    $html .= '<p><strong>' . e($company['numero_associado']) . '</strong><br>' . display_status($company['estado']) . '</p>';
    $html .= '<div class="detail-list qr-details">';
    $html .= detail('Identificador interno', (string) $company['id']);
    $html .= detail('Código QR', $code);
    $html .= detail('Conteúdo', 'Validação pública do associado');
    $html .= '</div>';
    $html .= '<div class="actions no-print"><a class="button-link" href="' . e($validationUrl) . '">Abrir validação</a><button type="button" onclick="window.print()">Imprimir QR</button></div>';
    $html .= '</div>';
    $html .= '<div class="card"><h3>' . e($company['nome_empresa']) . '</h3><div class="detail-list">';
    $html .= detail('Número de associado', $company['numero_associado']);
    $html .= detail('NIF', $company['nif']);
    $html .= detail('CAE', $company['cae']);
    $html .= detail('Setor', $company['setor_atividade']);
    $html .= detail('E-mail', $company['email']);
    $html .= detail('Telefone', $company['telefone']);
    $html .= detail('Responsável', $company['responsavel']);
    $html .= detail('Colaboradores', (string) $company['numero_colaboradores']);
    $html .= detail('Morada', trim(($company['morada'] ?? '') . ', ' . ($company['codigo_postal'] ?? '') . ' ' . ($company['localidade'] ?? '')));
    $html .= detail('Tipo', $company['tipo_associado']);
    $html .= detail('Quota', membership_plan_label($company['quota_plano'] ?? null));
    $html .= detail('Estado da quota', $company['quota_estado'] ?? 'pendente');
    $html .= detail('Quota válida até', $company['quota_validade'] ?? null);
    $html .= detail('Estado', $company['estado']);
    $html .= detail('Data de adesão', $company['data_adesao']);
    $html .= '</div>';

    if ($internal) {
        $html .= '<h3>Observações internas</h3><p class="muted">' . e($company['observacoes_internas'] ?: 'Sem observações.') . '</p>';
    }

    $html .= '</div></section>';

    return $html;
}

if ($page === 'validar') {
    $stmt = db()->prepare(
        'SELECT e.* FROM qr_codes q JOIN empresas_associadas e ON e.id = q.empresa_id WHERE q.codigo_qr = ?'
    );
    $stmt->execute([$_GET['code'] ?? '']);
    $company = $stmt->fetch();

    echo '<!doctype html><html lang="pt-PT">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<title>Validação ACISJM</title>';
    echo '<meta name="theme-color" content="#ff5100">';
    echo '<link rel="manifest" href="manifest.json">';
    echo '<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">';
    echo '<link rel="stylesheet" href="styles.css?v=12">';
    echo '</head><body>';
    echo '<main class="login-page"><section class="card validation-card">';
    echo '<div class="brand"><img class="brand-logo" src="assets/aci-sjm-marca.png" alt="ACISJM"><div><h1>Validação ACISJM</h1><p>QR Code identificativo</p></div></div>';

    if ($company) {
        echo '<h2>' . ($company['estado'] === 'ativo' ? 'Associado ACISJM ativo' : 'Associado sem validação ativa') . '</h2>';
        echo '<div class="detail-list">';
        echo detail('Nome da empresa', $company['nome_empresa']);
        echo detail('Número de associado', $company['numero_associado']);
        echo detail('Estado', $company['estado']);
        echo '</div>';
    } else {
        echo '<h2>QR Code inválido</h2><p class="muted">Não foi encontrado nenhum associado com este identificador.</p>';
    }

    echo '</section></main>';
    echo '<script src="pwa.js"></script>';
    echo '</body></html>';
    exit;
}

if (!current_user()) {
    echo '<!doctype html><html lang="pt-PT">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<title>ACISJM</title>';
    echo '<meta name="theme-color" content="#ff5100">';
    echo '<link rel="manifest" href="manifest.json">';
    echo '<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">';
    echo '<link rel="stylesheet" href="styles.css?v=12">';
    echo '</head><body>';
    echo '<main class="login-page">';
    echo '<section class="login-panel">';
    echo '<div class="login-copy">';
    echo '<div class="brand login-brand">';
    echo '<div><h1>ACISJM Associados</h1><p>Gestão e comunicação com associados.</p></div>';
    echo '<img class="brand-logo" src="assets/aci-sjm-marca.png" alt="ACISJM">';
    echo '</div>';
    echo '<h1>Plataforma de associados</h1>';
    echo '<p>Acesso reservado à administração e às empresas associadas.</p>';
    echo '</div>';
    echo '<form class="login-form" method="post">';
    echo csrf_input();
    echo '<input type="hidden" name="action" value="login">';
    echo '<label>E-mail<input name="email" type="email" required autocomplete="email"></label>';
    echo '<label>Palavra-passe<input name="password" type="password" required autocomplete="current-password"></label>';
    echo '<button type="submit">Iniciar sessão</button>';
    echo '<button type="button" class="register-btn" onclick="location.href=\'registro.php\'">Registar Novo Login</button>';
    echo '<div class="error">' . e($message) . '</div>';
    echo '</form>';
    echo '</section>';
    echo '<div class="logos-bar"><img src="assets/logos.png" alt="Apoios institucionais" height="32"></div>';
    echo '<div class="login-footer">';
    echo '<div class="login-footer-info">';
    echo '<span class="login-footer-copy"><strong>ACISJM</strong> &nbsp;|&nbsp; 2021&ndash;2026 &copy; All Rights Reserved</span>';
    echo '<div class="login-footer-links">';
    echo '<a href="https://www.livroreclamacoes.pt" target="_blank" rel="noreferrer">Livro de Reclamações</a>';
    echo '<a href="index.php?page=rgpd">Privacidade</a>';
    echo '<a href="mailto:secretaria@acisjm.pt">Contacto</a>';
    echo '<a href="https://www.google.com/maps/place/Av.+Dr.+Renato+Ara%C3%BAjo+433,+3700-214+S%C3%A3o+Jo%C3%A3o+da+Madeira/" target="_blank" rel="noreferrer">Localização</a>';
    echo '</div></div>';
    echo '<div class="footer-socials">';
    echo '<a href="https://www.instagram.com/acisjm/" target="_blank" rel="noreferrer"><img src="assets/instagram.svg" alt="Instagram"></a>';
    echo '<a href="https://www.facebook.com/acsjm.pt" target="_blank" rel="noreferrer"><img src="assets/facebook.svg" alt="Facebook"></a>';
    echo '<a href="https://www.linkedin.com/" target="_blank" rel="noreferrer"><img src="assets/linkedin.svg" alt="LinkedIn"></a>';
    echo '<a href="https://www.youtube.com/" target="_blank" rel="noreferrer"><img src="assets/youtube.svg" alt="YouTube"></a>';
    echo '</div></div>';
    echo '</main>';
    echo '<script src="pwa.js"></script>';
    echo '</body></html>';
    exit;
}

if ($page === 'dashboard') {
    require_admin();

    $total     = (int) db()->query('SELECT COUNT(*) FROM empresas_associadas')->fetchColumn();
    $active    = (int) db()->query("SELECT COUNT(*) FROM empresas_associadas WHERE estado='ativo'")->fetchColumn();
    $inactive  = (int) db()->query("SELECT COUNT(*) FROM empresas_associadas WHERE estado='inativo'")->fetchColumn();
    $suspended = (int) db()->query("SELECT COUNT(*) FROM empresas_associadas WHERE estado='suspenso'")->fetchColumn();
    $services  = (int) db()->query('SELECT COUNT(*) FROM servicos_iniciativas')->fetchColumn();
    $partners  = (int) db()->query('SELECT COUNT(*) FROM parceiros_descontos')->fetchColumn();

    $content  = '<section class="grid three">';
    $content .= '<div class="card metric"><span class="muted">Associados</span><strong>' . $total . '</strong></div>';
    $content .= '<div class="card metric"><span class="muted">Ativos</span><strong>' . $active . '</strong></div>';
    $content .= '<div class="card metric"><span class="muted">Inativos</span><strong>' . $inactive . '</strong></div>';
    $content .= '</section><br>';
    $content .= '<section class="grid three">';
    $content .= '<div class="card metric"><span class="muted">Suspensos</span><strong>' . $suspended . '</strong></div>';
    $content .= '<div class="card metric"><span class="muted">Serviços</span><strong>' . $services . '</strong></div>';
    $content .= '<div class="card metric"><span class="muted">Parceiros</span><strong>' . $partners . '</strong></div>';
    $content .= '</section><br>';
    $content .= '<section class="grid two">';
    $content .= '<div class="card"><h3>Operação</h3><p class="muted">A base de dados está pronta para registos reais. Não existem dados de exemplo criados automaticamente.</p></div>';
    $content .= '<div class="card"><h3>Benefícios</h3><p class="muted">' . $partners . ' parceiros registados.</p></div>';
    $content .= '</section>';

    layout('Painel da ACISJM', 'Resumo ligado à base de dados MySQL.', $content);
    exit;
}

if ($page === 'ferramentas') {
    require_admin();

    $sectorRows = db()->query(
        'SELECT COALESCE(NULLIF(setor_atividade, ""), "Sem setor") AS setor, COUNT(*) AS total
         FROM empresas_associadas GROUP BY setor ORDER BY total DESC, setor LIMIT 8'
    )->fetchAll();

    $localityRows = db()->query(
        'SELECT COALESCE(NULLIF(localidade, ""), "Sem localidade") AS localidade, COUNT(*) AS total
         FROM empresas_associadas GROUP BY localidade ORDER BY total DESC, localidade LIMIT 8'
    )->fetchAll();

    $emails = db()->query(
        "SELECT email FROM empresas_associadas WHERE email IS NOT NULL AND email <> '' AND estado = 'ativo' ORDER BY email"
    )->fetchAll(PDO::FETCH_COLUMN);

    $content  = '<section class="grid two">';
    $content .= '<div class="card"><h3>Exportar associados (Excel)</h3><p class="muted">Exporta todos os associados para ficheiro <strong>.xlsx</strong> abrível no Excel ou Google Sheets.</p><button onclick="location.href=\'index.php?page=export_associados\'">Exportar Excel</button></div>';
    $content .= '<div class="card"><h3>Importar associados (Excel)</h3><p class="muted">Importa ou atualiza associados a partir de um ficheiro <strong>.xlsx</strong> com o mesmo formato da exportação. Associados existentes (mesmo NIF) são atualizados; novos são criados.</p>';
    $content .= '<form method="post" action="index.php?page=import_associados" enctype="multipart/form-data">' . csrf_input() . '<label>Ficheiro .xlsx<input type="file" name="ficheiro" accept=".xlsx" required></label><br><br><button type="submit">Importar</button></form></div>';
    $content .= '<div class="card"><h3>Newsletter</h3><p class="muted">Lista de e-mails dos associados ativos para usar numa ferramenta externa de newsletter.</p><textarea readonly>' . e(implode('; ', $emails)) . '</textarea></div>';
    $content .= '<div class="card"><h3>Backup da base de dados</h3><p class="muted">Gera uma cópia SQL completa da base de dados para guardar em segurança.</p><button onclick="location.href=\'index.php?page=backup_database\'">Gerar backup</button></div>';
    $content .= '<div class="card"><h3>Repor backup</h3><p class="muted">Carrega um backup SQL gerado por esta aplicação. A estrutura é validada antes do restore e é criado um backup de segurança do estado atual.</p>';
    $content .= '<form method="post" action="index.php?page=restore_database" enctype="multipart/form-data" onsubmit="return confirm(\'Esta ação vai substituir os dados atuais pelos dados do backup selecionado. Pretende continuar?\')">' . csrf_input() . '<label>Ficheiro .sql<input type="file" name="backup_sql" accept=".sql" required></label><br><br><button class="danger" type="submit">Repor backup</button></form></div>';
    $content .= '</section><br>';
    $content .= '<section class="grid two">';
    $content .= '<div class="card"><h3>Associados por setor</h3>';
    foreach ($sectorRows as $row) {
        $content .= '<p><strong>' . e($row['setor']) . '</strong>: ' . (int) $row['total'] . '</p>';
    }
    $content .= $sectorRows ? '' : '<p class="muted">Sem dados.</p>';
    $content .= '</div>';
    $content .= '<div class="card"><h3>Associados por localidade</h3>';
    foreach ($localityRows as $row) {
        $content .= '<p><strong>' . e($row['localidade']) . '</strong>: ' . (int) $row['total'] . '</p>';
    }
    $content .= $localityRows ? '' : '<p class="muted">Sem dados.</p>';
    $content .= '</div></section>';

    layout('Ferramentas', 'Exportação, newsletter e estatísticas.', $content);
    exit;
}

if ($page === 'empresas') {
    require_admin();

    $edit = null;
    if (isset($_GET['edit'])) {
        $stmt = db()->prepare('SELECT * FROM empresas_associadas WHERE id = ?');
        $stmt->execute([(int) $_GET['edit']]);
        $edit = $stmt->fetch() ?: null;
    }
    if (isset($_GET['new'])) {
        $edit = [];
    }

    $q      = trim($_GET['q'] ?? '');
    $params = [];
    $where  = '';
    if ($q !== '') {
        $where  = 'WHERE nome_empresa LIKE ? OR nif LIKE ? OR cae LIKE ? OR setor_atividade LIKE ? OR localidade LIKE ? OR numero_associado LIKE ?';
        $params = array_fill(0, 6, '%' . $q . '%');
    }

    $stmt = db()->prepare("SELECT * FROM empresas_associadas $where ORDER BY nome_empresa");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $content  = render_registration_requests();
    $content .= '<div class="toolbar">';
    $content .= '<form method="get" class="search"><input type="hidden" name="page" value="empresas"><input type="search" name="q" placeholder="Pesquisar por nome, NIF, CAE, setor, localidade ou número" value="' . e($q) . '"></form>';
    $content .= '<button onclick="location.href=\'index.php?page=empresas&new=1\'">Adicionar associado</button>';
    $content .= '</div>';

    if ($edit !== null) {
        $content .= company_form($edit ?: null);
    }

    $content .= '<div class="table-wrap"><table>';
    $content .= '<thead><tr><th>Empresa</th><th>N.º associado</th><th>NIF</th><th>CAE</th><th>Setor</th><th>Localidade</th><th>Quota</th><th>Estado</th><th>Ações</th></tr></thead>';
    $content .= '<tbody>';

    foreach ($rows as $row) {
        $content .= '<tr>';
        $content .= '<td data-label="Empresa"><strong>' . e($row['nome_empresa']) . '</strong><br><span class="muted">' . e($row['nome_comercial']) . '</span></td>';
        $content .= '<td data-label="N.º associado">' . e($row['numero_associado']) . '</td>';
        $content .= '<td data-label="NIF">' . e($row['nif']) . '</td>';
        $content .= '<td data-label="CAE">' . e($row['cae']) . '</td>';
        $content .= '<td data-label="Setor">' . e($row['setor_atividade']) . '</td>';
        $content .= '<td data-label="Localidade">' . e($row['localidade']) . '</td>';
        $content .= '<td data-label="Quota">' . e(membership_plan_label($row['quota_plano'] ?? null)) . '<br>' . display_status($row['quota_estado'] ?? 'pendente') . '</td>';
        $content .= '<td data-label="Estado">' . display_status($row['estado']) . '</td>';
        $content .= '<td data-label="Ações" class="actions">';
        $content .= '<button onclick="location.href=\'index.php?page=ficha&id=' . (int) $row['id'] . '\'">Ver</button>';
        $content .= '<button class="secondary" onclick="location.href=\'index.php?page=empresas&edit=' . (int) $row['id'] . '\'">Editar</button>';
        $content .= delete_form('delete_company', (int) $row['id'], 'Apagar');
        $content .= '</td></tr>';
    }

    $content .= $rows ? '' : '<tr><td colspan="9">' . empty_state('Ainda não existem associados registados.') . '</td></tr>';
    $content .= '</tbody></table></div>';

    layout('Associados', 'Gestão e pesquisa da tabela de empresas associadas.', $content);
    exit;
}

if ($page === 'ficha') {
    require_admin();
    $stmt = db()->prepare('SELECT * FROM empresas_associadas WHERE id = ?');
    $stmt->execute([(int) ($_GET['id'] ?? 0)]);
    $company = $stmt->fetch();
    $associatedContent = $company ? render_company_detail($company, false) : '<div class="card">Utilizador sem empresa associada.</div>';
    if ($company && !membership_paid($company)) {
        $associatedContent = payment_required_card($company) . '<br>' . $associatedContent;
    }
    layout(
        'Ficha do associado',
        'Dados completos disponíveis apenas à administração.',
        $company ? render_company_detail($company, true) : '<div class="card">Associado não encontrado.</div>'
    );
    exit;
}

if ($page === 'associado') {
    require_login();
    $stmt = db()->prepare('SELECT * FROM empresas_associadas WHERE id = ?');
    $stmt->execute([(int) current_user()['empresa_id']]);
    $company = $stmt->fetch();
    layout(
        'Área do associado',
        'Consulta de dados principais, número de associado e QR Code.',
        $company ? render_company_detail($company, false) : '<div class="card">Utilizador sem empresa associada.</div>'
    );
    exit;
}

if ($page === 'servicos') {
    require_login();

    $isAdmin = current_user()['perfil'] === 'admin';
    $edit    = null;

    if ($isAdmin && isset($_GET['edit'])) {
        $stmt = db()->prepare('SELECT * FROM servicos_iniciativas WHERE id = ?');
        $stmt->execute([(int) $_GET['edit']]);
        $edit = $stmt->fetch() ?: null;
    }
    if ($isAdmin && isset($_GET['new'])) {
        $edit = [];
    }

    $services = db()->query('SELECT * FROM servicos_iniciativas ORDER BY COALESCE(data_inicio, "2099-12-31"), titulo')->fetchAll();
    $today = date('Y-m-d');
    $activeCount = 0;
    $upcomingCount = 0;
    $nextService = null;

    foreach ($services as $service) {
        if (($service['estado'] ?? '') === 'ativo') {
            $activeCount++;
        }

        if (!empty($service['data_inicio']) && $service['data_inicio'] >= $today && ($service['estado'] ?? '') !== 'concluido') {
            $upcomingCount++;
            $nextService ??= $service;
        }
    }

    $content  = '<section class="services-hero">';
    $content .= '<div><span class="eyebrow">Agenda ACISJM</span><h3>Serviços e iniciativas</h3></div>';
    $content .= '<div class="services-hero-stats">';
    $content .= '<div><strong>' . count($services) . '</strong><span>Total</span></div>';
    $content .= '<div><strong>' . $activeCount . '</strong><span>Ativos</span></div>';
    $content .= '<div><strong>' . $upcomingCount . '</strong><span>Próximos</span></div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<div class="services-toolbar">';
    $content .= $nextService
        ? '<div class="next-service"><span>Próximo</span><strong>' . e($nextService['titulo']) . '</strong></div>'
        : '<div class="next-service"><span>Próximo</span><strong>A anunciar</strong></div>';
    if ($isAdmin) {
        $content .= '<button onclick="location.href=\'index.php?page=servicos&new=1\'">Publicar serviço ou iniciativa</button>';
    }
    $content .= '</div>';

    if ($edit !== null) {
        $content .= service_form($edit ?: null);
    }

    $content .= '<section class="service-list">';

    foreach ($services as $service) {
        $dateStart = $service['data_inicio'] ?: null;
        $dateEnd = $service['data_fim'] ?: null;
        $dateLabel = '';
        $timingClass = 'no-date';

        if ($dateStart) {
            $dateLabel = date('d/m/Y', strtotime($dateStart));
            $timingClass = $dateStart < $today ? 'past' : 'upcoming';

            if ($dateEnd && $dateEnd !== $dateStart) {
                $dateLabel .= ' a ' . date('d/m/Y', strtotime($dateEnd));
                if ($dateEnd < $today) {
                    $timingClass = 'past';
                }
            }
        }

        $statusClass = preg_replace('/[^a-z0-9_-]/i', '', (string) $service['estado']);
        $category = trim((string) ($service['categoria'] ?? ''));

        $content .= '<article class="service-card service-' . e($statusClass) . ' service-' . e($timingClass) . '">';
        $content .= '<div class="service-card-head">';
        $content .= '<div><span class="service-category">' . e($category !== '' ? $category : 'Iniciativa') . '</span><h3>' . e($service['titulo']) . '</h3></div>';
        if (!empty($service['estado']) && $service['estado'] !== 'ativo') {
            $content .= display_status($service['estado']);
        }
        $content .= '</div>';
        if ($dateLabel !== '' || !empty($service['local_evento'])) {
            $content .= '<div class="service-meta">';
            if ($dateLabel !== '') {
                $content .= '<div><span>Data</span><strong>' . e($dateLabel) . '</strong></div>';
            }
            if (!empty($service['local_evento'])) {
                $content .= '<div><span>Local</span><strong>' . e($service['local_evento']) . '</strong></div>';
            }
            $content .= '</div>';
        }
        if (!empty($service['descricao'])) {
            $content .= '<p class="service-description">' . e($service['descricao']) . '</p>';
        }
        $content .= '<div class="service-card-footer">';
        if ($service['link_inscricao']) {
            $content .= '<a class="button-link service-link" href="' . e($service['link_inscricao']) . '" target="_blank" rel="noreferrer noopener">Inscrição</a>';
        }
        if ($isAdmin) {
            $content .= '<div class="actions service-admin-actions"><button class="secondary" onclick="location.href=\'index.php?page=servicos&edit=' . (int) $service['id'] . '\'">Editar</button>' . delete_form('delete_service', (int) $service['id'], 'Apagar') . '</div>';
        }
        $content .= '</div>';
        $content .= '</article>';
    }

    $content .= $services ? '' : empty_state('Ainda não existem serviços ou iniciativas publicados.');
    $content .= '</section>';

    layout('Serviços e iniciativas', 'Gestão de serviços, eventos, campanhas e iniciativas.', $content);
    exit;
}

if ($page === 'parceiros') {
    require_login();

    $isAdmin = current_user()['perfil'] === 'admin';
    if (!$isAdmin) {
        $company = current_user_company();
        if (!membership_paid($company)) {
            layout('Benefícios', 'Acesso reservado a associados com quota validada.', payment_required_card($company));
            exit;
        }
    }
    $edit    = null;

    if ($isAdmin && isset($_GET['edit'])) {
        $stmt = db()->prepare('SELECT * FROM parceiros_descontos WHERE id = ?');
        $stmt->execute([(int) $_GET['edit']]);
        $edit = $stmt->fetch() ?: null;
    }
    if ($isAdmin && isset($_GET['new'])) {
        $edit = [];
    }

    $query = trim((string) ($_GET['q'] ?? ''));
    $category = (string) ($_GET['categoria'] ?? 'todos');
    $order = (string) ($_GET['ordem'] ?? 'nome');
    $currentPartnerPage = max(1, (int) ($_GET['pagina'] ?? 1));
    $cardsPerPage = 4;

    $where = '';
    $params = [];
    if ($query !== '') {
        $where = 'WHERE nome_parceiro LIKE ? OR descricao_beneficio LIKE ? OR desconto LIKE ? OR contacto LIKE ?';
        $like = '%' . $query . '%';
        $params = [$like, $like, $like, $like];
    }

    $countStmt = db()->prepare('SELECT COUNT(*) FROM parceiros_descontos ' . $where);
    $countStmt->execute($params);
    $totalPartners = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalPartners / $cardsPerPage));
    $currentPartnerPage = min($currentPartnerPage, $totalPages);
    $offset = ($currentPartnerPage - 1) * $cardsPerPage;

    $orderSql = 'nome_parceiro ASC';
    if ($order === 'recentes') {
        $orderSql = 'id DESC';
    } elseif ($order === 'estado') {
        $orderSql = 'estado ASC, nome_parceiro ASC';
    }

    $stmt = db()->prepare('SELECT * FROM parceiros_descontos ' . $where . ' ORDER BY ' . $orderSql . ' LIMIT ' . $cardsPerPage . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $partners = $stmt->fetchAll();

    $pageUrl = function (int $number) use ($query, $category, $order): string {
        return 'index.php?' . http_build_query([
            'page' => 'parceiros',
            'q' => $query,
            'categoria' => $category,
            'ordem' => $order,
            'pagina' => $number,
        ]);
    };

    $content  = '<section class="partners-hero">';
    $content .= '<div><span class="eyebrow">Parceiros ACISJM</span><h3>Benefícios </h3><p>A nossa associação tem vantagens e parcerias com outras empresas!</p></div>';
    if ($isAdmin) {
        $content .= '<button class="hero-action" onclick="location.href=\'index.php?page=parceiros&new=1\'">Adicionar parceiro</button>';
    }
    $content .= '</section>';

    if ($edit !== null) {
        $content .= partner_form($edit ?: null);
    }

    $content .= '<form class="partners-controls" method="get">';
    $content .= '<input type="hidden" name="page" value="parceiros">';
    $content .= '<label>Pesquisar<input name="q" placeholder="Pesquisar parceiro, desconto ou contacto" value="' . e($query) . '"></label>';
    $content .= '<label>Categoria<select name="categoria"><option value="todos"' . ($category === 'todos' ? ' selected' : '') . '>Todas</option><option value="saude"' . ($category === 'saude' ? ' selected' : '') . '>Saúde</option><option value="servicos"' . ($category === 'servicos' ? ' selected' : '') . '>Serviços</option><option value="comercio"' . ($category === 'comercio' ? ' selected' : '') . '>Comércio</option></select></label>';
    $content .= '<label>Ordenar<select name="ordem"><option value="nome"' . ($order === 'nome' ? ' selected' : '') . '>Nome</option><option value="recentes"' . ($order === 'recentes' ? ' selected' : '') . '>Recentes</option><option value="estado"' . ($order === 'estado' ? ' selected' : '') . '>Estado</option></select></label>';
    $content .= '<button class="filter-button" type="submit">Aplicar</button>';
    $content .= '</form>';

    $content .= '<section class="partners-grid">';

    $editIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
    $trashIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/></svg>';

    foreach ($partners as $partner) {
        $partnerId = (int) $partner['id'];
        $name = (string) $partner['nome_parceiro'];
        $imageUrl = partner_image_url($partnerId);
        $initials = strtoupper(substr(trim($name), 0, 2)) ?: 'AC';
        $status = (string) ($partner['estado'] ?: 'ativo');
        $discount = trim((string) $partner['desconto']) !== '' ? (string) $partner['desconto'] : 'Benefício';
        $description = trim(preg_replace('/\s+/', ' ', (string) $partner['descricao_beneficio']));
        $conditions = trim(preg_replace('/\s+/', ' ', (string) $partner['condicoes']));
        $contact = trim((string) $partner['contacto']) !== '' ? (string) $partner['contacto'] : 'Contacto a confirmar';

        $content .= '<article class="partner-card">';
        $content .= '<div class="partner-media">';
        $content .= $imageUrl
            ? '<img src="' . e($imageUrl) . '" alt="' . e($name) . '">'
            : '<div class="partner-placeholder"><span>' . e($initials) . '</span></div>';
        $content .= '<span class="partner-status ' . ($status === 'ativo' ? 'is-active' : 'is-inactive') . '">' . e($status) . '</span>';
        $content .= '</div>';
        $content .= '<div class="partner-card-body">';
        $content .= '<div class="partner-card-top"><h3>' . e($name) . '</h3><span class="discount-pill">' . e($discount) . '</span></div>';
        $content .= '<p class="partner-description">' . e($description ?: 'Benefício disponível para associados ACISJM.') . '</p>';
        $content .= '<div class="partner-meta"><span>Contacto</span><strong>' . e($contact) . '</strong></div>';
        $content .= '<div class="partner-card-actions">';
        $content .= '<button type="button" class="partner-view-btn" data-benefit-open data-name="' . e($name) . '" data-discount="' . e($discount) . '" data-description="' . e($description) . '" data-conditions="' . e($conditions ?: 'Sem condições adicionais registadas.') . '" data-contact="' . e($contact) . '">Ver benefício</button>';
        if ($isAdmin) {
            $content .= '<button class="icon-button" type="button" title="Editar parceiro" onclick="location.href=\'index.php?page=parceiros&edit=' . $partnerId . '\'">' . $editIcon . '</button>';
            $content .= '<form method="post" class="inline-form" onsubmit="return confirm(\'Tem a certeza que pretende apagar este parceiro?\')">' . csrf_input() . '<input type="hidden" name="action" value="delete_partner"><input type="hidden" name="id" value="' . $partnerId . '"><button class="icon-button danger-icon" type="submit" title="Apagar parceiro">' . $trashIcon . '</button></form>';
        }
        $content .= '</div>';
        $content .= '</div>';
        $content .= '</article>';
    }

    $content .= $partners ? '' : empty_state('Ainda não existem parceiros ou benefícios registados.');
    $content .= '</section>';

    if ($totalPages > 1) {
        $content .= '<nav class="partners-pagination" aria-label="Paginação de parceiros">';
        for ($i = 1; $i <= $totalPages; $i++) {
            $content .= '<a class="' . ($i === $currentPartnerPage ? 'is-current' : '') . '" href="' . e($pageUrl($i)) . '">' . $i . '</a>';
        }
        $content .= '</nav>';
    }

    $content .= '<div class="benefit-modal" data-benefit-modal aria-hidden="true"><div class="benefit-modal-card"><button type="button" class="modal-close" data-benefit-close aria-label="Fechar">×</button><span class="eyebrow">Benefício</span><h3 data-benefit-title></h3><span class="discount-pill" data-benefit-discount></span><p data-benefit-description></p><div class="modal-detail"><span>Condições</span><strong data-benefit-conditions></strong></div><div class="modal-detail"><span>Contacto</span><strong data-benefit-contact></strong></div></div></div>';
    $content .= '<script>
document.querySelectorAll("[data-benefit-open]").forEach(function (button) {
    button.addEventListener("click", function () {
        var modal = document.querySelector("[data-benefit-modal]");
        if (!modal) return;
        modal.querySelector("[data-benefit-title]").textContent = button.dataset.name || "";
        modal.querySelector("[data-benefit-discount]").textContent = button.dataset.discount || "Benefício";
        modal.querySelector("[data-benefit-description]").textContent = button.dataset.description || "Benefício disponível para associados ACISJM.";
        modal.querySelector("[data-benefit-conditions]").textContent = button.dataset.conditions || "Sem condições adicionais registadas.";
        modal.querySelector("[data-benefit-contact]").textContent = button.dataset.contact || "Contacto a confirmar";
        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
    });
});
document.querySelectorAll("[data-benefit-close], [data-benefit-modal]").forEach(function (item) {
    item.addEventListener("click", function (event) {
        if (event.target !== item && !item.hasAttribute("data-benefit-close")) return;
        var modal = document.querySelector("[data-benefit-modal]");
        if (!modal) return;
        modal.classList.remove("is-open");
        modal.setAttribute("aria-hidden", "true");
    });
});
document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        var modal = document.querySelector("[data-benefit-modal]");
        if (modal) modal.classList.remove("is-open");
    }
});
</script>';

    layout('Parceiros e benefícios', 'Gestão de protocolos, benefícios e descontos.', $content);
    exit;
}

if ($page === 'informacoes') {
    require_associado();

    $iconLab   = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="12" rx="2"/><path d="M12 2v4"/><circle cx="9" cy="14" r="1.5"/><circle cx="15" cy="14" r="1.5"/></svg>';
    $iconRgpd  = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    $iconEstat = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
    $iconDir   = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';

    $content  = '<div class="info-subcategories">';
    $content .= '<a class="info-subcat-card" href="index.php?page=laboratorios"><div class="info-subcat-icon">' . $iconLab . '</div><div class="info-subcat-body"><h4>Laboratórios Colaborativos</h4><p>A ACISJM possui espaços de aprendizagem prática e experimentação tecnológica, venha conhecer!</p></div></a>';
    $content .= '<a class="info-subcat-card" href="index.php?page=rgpd"><div class="info-subcat-icon">' . $iconRgpd . '</div><div class="info-subcat-body"><h4>RGPD e Privacidade</h4><p>Resumo das boas práticas de segurança e proteção de dados aplicadas na plataforma.</p></div></a>';
    $content .= '<a class="info-subcat-card" href="index.php?page=estatutos"><div class="info-subcat-icon">' . $iconEstat . '</div><div class="info-subcat-body"><h4>Estatutos</h4><p>Documentos constitutivos e regras que regem a associação ACISJM.</p></div></a>';
    $content .= '<a class="info-subcat-card" href="index.php?page=diretores"><div class="info-subcat-icon">' . $iconDir . '</div><div class="info-subcat-body"><h4>Orgãos Sociais</h4><p>Conheça os nossos membros os órgãos sociais que lideram a associação.</p></div></a>';
    $content .= '</div>';

    layout('Informações relevantes', '', $content);
    exit;
}

if ($page === 'estatutos') {
    require_associado();

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="estatutos-acisjm-2023.pdf"');
    readfile(__DIR__ . '/assets/estatutos-acisjm-2023.pdf');
    exit;
}
if ($page === 'diretores') {
    require_associado();

    $breadcrumb  = '<nav class="os-breadcrumb"><a href="index.php?page=informacoes">Informações relevantes</a>';
    $breadcrumb .= '<span>›</span><span>Órgãos Sociais</span></nav>';

    $card = fn (string $nome, string $cargo, bool $presidente = false): string =>
        '<div class="os-card' . ($presidente ? ' os-card--presidente' : '') . '">'
        . '<div class="os-avatar">' . e(strtoupper(mb_substr($nome, 0, 1)) . strtoupper(mb_substr(explode(' ', $nome)[1] ?? '', 0, 1))) . '</div>'
        . '<div class="os-info"><strong>' . e($nome) . '</strong><span>' . e($cargo) . '</span></div>'
        . '</div>';

    $section = fn (string $label, string $cards): string =>
        '<div class="os-section">'
        . '<p class="os-section-label">' . e($label) . '</p>'
        . '<div class="os-cards">' . $cards . '</div>'
        . '</div>';

    $content  = $breadcrumb;
    $content .= '<div class="os-header"><h2>Órgãos Sociais 2026/2029</h2><p>Eleitos na Assembleia Geral Ordinária de 30 de abril de 2026, na sede da ACISJM.</p></div>';

    $content .= $section('Direção',
        $card('Paulo Barreira',  'Presidente', true) .
        $card('Álvaro Gouveia',  'Vice-Presidente — Indústria') .
        $card('Tiago Ferreira',  'Vice-Presidente — Comércio e Serviços') .
        $card('Wilson Quintas',  'Tesoureiro') .
        $card('Tiago Gomes',     'Secretário') .
        $card('António Coelho',  'Vogal') .
        $card('Rui Pinho',       'Vogal')
    );

    $content .= $section('Mesa da Assembleia Geral',
        $card('Susana Pádua',    'Presidente', true) .
        $card('Miguel Oliveira', 'Vice-Presidente') .
        $card('Rui Pinto',       'Secretário')
    );

    $content .= $section('Conselho Fiscal',
        $card('Edmundo Loio', 'Presidente', true) .
        $card('Bruno Nunes',  'Secretário') .
        $card('José Rocha',   'Secretário')
    );

    $content .= $section('Suplentes',
        $card('Marco Cardoso',  'Suplente') .
        $card('Antero Quinta',  'Suplente') .
        $card('Arlindo Vieira', 'Suplente')
    );

    layout('Órgãos Sociais', 'Triénio 2026/2029', $content);
    exit;
}
if ($page === 'laboratorios') {
    require_associado();
    $company = current_user_company();
    if (!membership_paid($company)) {
        layout('Laboratórios Colaborativos', 'Acesso reservado a associados com quota validada.', payment_required_card($company));
        exit;
    }

    $iconRobo = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="12" rx="2"/><path d="M12 2v4"/><circle cx="9" cy="14" r="1.5"/><circle cx="15" cy="14" r="1.5"/><path d="M8 20v2M16 20v2"/></svg>';
    $iconFri  = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M4.93 4.93l14.14 14.14M2 12h20M4.93 19.07L19.07 4.93"/></svg>';
    $iconAuto = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17h14l-1.5-6.5a2 2 0 0 0-2-1.5h-7a2 2 0 0 0-2 1.5L5 17z"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="16.5" cy="17.5" r="1.5"/></svg>';

    $labCard = fn (string $name, string $desc, string $color, string $tag, string $icon): string =>
        '<article class="lab-card" style="--lab-accent:' . $color . ';">'
        . '<div class="lab-icon">' . $icon . '</div>'
        . '<h4>' . $name . '</h4>'
        . '<p>' . $desc . '</p>'
        . '<span class="lab-tag">' . $tag . '</span>'
        . '</article>';

    $breadcrumb  = '<nav class="info-breadcrumb"><a href="index.php?page=informacoes">Informações relevantes</a>';
    $breadcrumb .= '<span>›</span><span>Laboratórios</span></nav>';

    $content  = $breadcrumb;
    $content .= '<section class="card labs-hero">';
    $content .= '<h3>Laboratórios Colaborativos</h3>';
    $content .= '<p>A ACISJM organiza três laboratórios colaborativos distintos, dos quais criados em função de promover a aprendizagem prática, a experimentação e o desenvolvimento de projetos tecnológicos em diferentes áreas.</p>';
    $content .= '</section>';
    $content .= '<div class="labs-grid">';
    $content .= $labCard('RoboLAB', 'O Laboratório Colaborativo de Robótica está instalado na empresa CEI Solutions, parceira do Turismo Industrial de São João da Madeira. Este laboratório pretende proporcionar a aprendizagem prática com robôs tradicionais e colaborativos, em ambiente industrial, contribuindo para uma melhoria acentuada das competências dos estudantes, técnicos e público em geral da região.', '#c0392b', 'Robótica · Automação', $iconRobo);
    $content .= $labCard('FriLAB', 'O Laboratório Colaborativo de Frio e Robótica Doméstica está instalado na empresa Friparque. Este laboratório pretende proporcionar uma experiência de aprendizagem prática relacionada com tecnologias de refrigeração doméstica, eletrodomésticos e robôs domésticos, em ambiente comercial e industrial, contribuindo para uma melhoria acentuada das competências dos estudantes, técnicos e público em geral da região.', '#e25822', 'Refrigeração · Climatização', $iconFri);
    $content .= $labCard('AutoLAB', 'A Raulauto é uma empresa de referência na área da manutenção e reparação automóvel, com sede em S. João da Madeira. Ao longo de mais de quatro décadas, consolidou uma identidade marcada pelo profissionalismo, pela proximidade ao cliente e pela aposta contínua na qualidade dos seus serviços.', '#f59e0b', 'Automóvel · Mecatrónica', $iconAuto);
    $content .= '</div>';

    layout('Laboratórios Colaborativos', 'RoboLAB · FriLAB · AutoLAB', $content);
    exit;
}

if ($page === 'rgpd') {
    require_associado();

    $breadcrumb  = '<nav class="info-breadcrumb"><a href="index.php?page=informacoes">Informações relevantes</a>';
    $breadcrumb .= '<span>›</span><span>RGPD e Privacidade</span></nav>';

    $content  = $breadcrumb;
    $content .= '<section class="card"><h3>Segurança e RGPD</h3>';
    $content .= '<p>A aplicação guarda apenas os dados necessários à gestão associativa, separa perfis de acesso e não apresenta observações internas aos associados.</p>';
    $content .= '<div class="detail-list">';
    $content .= detail('Base de dados', 'MySQL com tabelas normalizadas.');
    $content .= detail('Palavra-passe', 'Guardada com password_hash do PHP.');
    $content .= detail('Formulários', 'Protegidos com token CSRF.');
    $content .= detail('QR Code', 'Validação pública limitada a nome, número e estado.');
    $content .= '</div></section>';

    layout('RGPD e segurança', 'Resumo das boas práticas aplicadas.', $content);
    exit;
}

$user = current_user();
if ($user && $user['perfil'] === 'admin') {
    header('Location: index.php?page=dashboard');
} else {
    header('Location: index.php?page=associado');
}
exit;
