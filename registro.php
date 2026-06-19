<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

ensure_registration_requests_table();
ensure_membership_payment_columns();

$message = '';
$messageClass = '';

function save_quota_proof_upload(string $field, string $basename): ?string
{
    if (empty($_FILES[$field]['tmp_name'])) {
        return null;
    }

    if (!is_uploaded_file($_FILES[$field]['tmp_name'])) {
        return null;
    }

    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg'     => 'jpg',
        'image/png'      => 'png',
        'image/webp'     => 'webp',
    ];
    $mime = mime_content_type($_FILES[$field]['tmp_name']) ?: '';

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('O comprovativo deve ser PDF, JPG, PNG ou WebP.');
    }

    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'quotas';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $relative = 'uploads/quotas/' . $basename . '.' . $allowed[$mime];
    $target = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    move_uploaded_file($_FILES[$field]['tmp_name'], $target);

    return $relative;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $nomeEmpresa = trim($_POST['nome_empresa'] ?? '');
    $nif = trim($_POST['nif'] ?? '');
    $setorAtividade = trim($_POST['setor_atividade'] ?? '');
    $localidade = trim($_POST['localidade'] ?? '');
    $responsavel = trim($_POST['responsavel'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $quotaPlano = $_POST['quota_plano'] ?? '';
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $aceitaPrivacidade = isset($_POST['aceita_privacidade']);
    $aceitaContacto = isset($_POST['aceita_contacto']);
    $plans = membership_plans();

    if ($nomeEmpresa === '' || $nif === '' || $responsavel === '' || $email === '' || $password === '') {
        $message = 'Preencha todos os campos obrigatórios.';
        $messageClass = 'danger';
    } elseif (!isset($plans[$quotaPlano])) {
        $message = 'Selecione a quota de associado.';
        $messageClass = 'danger';
    } elseif (!$aceitaPrivacidade || !$aceitaContacto) {
        $message = 'Tem de aceitar as declarações obrigatórias.';
        $messageClass = 'danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Indique um e-mail válido.';
        $messageClass = 'danger';
    } elseif (strlen($password) < 8) {
        $message = 'A palavra-passe deve ter pelo menos 8 caracteres.';
        $messageClass = 'danger';
    } elseif ($password !== $passwordConfirm) {
        $message = 'As palavras-passe não coincidem.';
        $messageClass = 'danger';
    } else {
        $existsCompany = db()->prepare('SELECT COUNT(*) FROM empresas_associadas WHERE email = ? OR nif = ?');
        $existsCompany->execute([$email, $nif]);

        $existsRequest = db()->prepare("SELECT COUNT(*) FROM solicitacoes_associado WHERE estado = 'pendente' AND (email = ? OR nif = ?)");
        $existsRequest->execute([$email, $nif]);

        if ((int) $existsCompany->fetchColumn() > 0) {
            $message = 'Já existe um associado com esse e-mail ou NIF.';
            $messageClass = 'danger';
        } elseif ((int) $existsRequest->fetchColumn() > 0) {
            $message = 'Já existe uma solicitação pendente com esse e-mail ou NIF.';
            $messageClass = 'danger';
        } else {
            $proofPath = null;
            try {
                $proofPath = save_quota_proof_upload('comprovativo_quota', 'pedido-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)));
            } catch (RuntimeException $e) {
                $message = $e->getMessage();
                $messageClass = 'danger';
                goto render_page;
            }

            db()->prepare(
                'INSERT INTO solicitacoes_associado
                (nome_empresa, nif, setor_atividade, localidade, responsavel, email, telefone, quota_plano, quota_valor, comprovativo_quota, aceita_privacidade, aceita_contacto, password_hash)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $nomeEmpresa,
                $nif,
                $setorAtividade !== '' ? $setorAtividade : null,
                $localidade !== '' ? $localidade : null,
                $responsavel,
                $email,
                $telefone !== '' ? $telefone : null,
                $quotaPlano,
                $plans[$quotaPlano]['value'],
                $proofPath,
                1,
                1,
                password_hash($password, PASSWORD_DEFAULT),
            ]);

            $message = 'Pedido submetido com sucesso. A administração irá analisar a sua solicitação.';
            $messageClass = 'ok';
            $_POST = [];
        }
    }
}
render_page:
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Registar - ACISJM</title>
    <meta name="theme-color" content="#ff5100">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="assets/aci-sjm-marca.png">
    <link rel="stylesheet" href="styles.css?v=6">
    <style>
        .register-page {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 1.5rem;
            background:
                radial-gradient(circle at 90% 10%, rgba(255, 81, 0, .15), transparent 35%),
                radial-gradient(circle at 10% 90%, rgba(183, 25, 32, .12), transparent 35%),
                linear-gradient(135deg, rgba(255, 81, 0, .08), rgba(200, 25, 30, .08)),
                var(--bg);
        }

        .register-panel {
            width: min(1020px, 100%);
            display: grid;
            grid-template-columns: .9fr 1.1fr;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .register-copy {
            background:
                linear-gradient(160deg, rgba(183, 25, 32, .98), rgba(255, 81, 0, .92)),
                #b71920;
            color: white;
            padding: 2.5rem 2rem;
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .register-copy .brand {
            margin-bottom: 0;
        }

        .register-copy h1 {
            font-size: clamp(1.5rem, 4vw, 2.4rem);
            line-height: 1.1;
            margin: 0;
            font-weight: 800;
            letter-spacing: -.02em;
        }

        .register-copy p {
            color: rgba(255,255,255,.85);
            margin: .5rem 0 0;
            line-height: 1.6;
        }

        .register-steps {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-top: auto;
        }

        .register-step {
            display: flex;
            align-items: flex-start;
            gap: .85rem;
        }

        .step-num {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: rgba(255,255,255,.2);
            border: 1.5px solid rgba(255,255,255,.4);
            display: grid;
            place-items: center;
            font-size: .8rem;
            font-weight: 800;
            flex-shrink: 0;
            color: white;
        }

        .step-text {
            font-size: .88rem;
            color: rgba(255,255,255,.85);
            line-height: 1.5;
            padding-top: .15rem;
        }

        .step-text strong {
            display: block;
            color: white;
            font-size: .92rem;
            margin-bottom: .1rem;
        }

        .register-form-wrap {
            padding: 2.5rem 2rem;
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .register-form-wrap h2 {
            margin: 0 0 .3rem;
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -.01em;
        }

        .register-form-wrap > p {
            color: var(--muted);
            margin: 0 0 1.75rem;
            font-size: .92rem;
        }

        .register-form {
            display: grid;
            gap: 1rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .divider {
            border: none;
            border-top: 1px solid var(--line);
            margin: .5rem 0;
        }

        .section-label {
            font-size: .78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--brand);
            margin: .25rem 0 -.25rem;
        }

        .register-actions {
            display: flex;
            flex-direction: column;
            gap: .75rem;
            margin-top: .5rem;
        }

        .register-actions button[type="submit"] {
            width: 100%;
            padding: .85rem;
            font-size: 1rem;
        }

        .back-link {
            text-align: center;
            font-size: .88rem;
            color: var(--muted);
        }

        .back-link a {
            color: var(--brand);
            font-weight: 700;
            text-decoration: none;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .notice {
            background: #fff8f2;
            border: 1px solid #ffd5b8;
            border-radius: 8px;
            padding: .85rem 1rem;
            font-size: .86rem;
            color: #7a3a1a;
            line-height: 1.55;
        }

        .notice strong {
            color: var(--brand);
        }

        .quota-options {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .75rem;
        }

        .quota-option {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fffaf5;
            padding: .85rem;
            cursor: pointer;
        }

        .quota-option input {
            width: auto;
            margin-right: .35rem;
        }

        .quota-option strong {
            display: block;
            color: var(--text);
            font-size: 1rem;
            margin-top: .25rem;
        }

        .payment-box {
            border: 1px solid #ffd0b5;
            border-radius: 8px;
            background: #fff4e8;
            padding: .9rem 1rem;
            color: #6b3328;
            line-height: 1.55;
        }

        .payment-box code {
            display: inline-block;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 7px;
            color: var(--text);
            font-weight: 800;
            padding: .2rem .45rem;
        }

        .consent-label {
            display: flex;
            align-items: flex-start;
            gap: .55rem;
            font-weight: 600;
            color: var(--muted);
            line-height: 1.45;
        }

        .consent-label input {
            width: auto;
            margin-top: .2rem;
        }

        @media (max-width: 860px) {
            .register-panel {
                grid-template-columns: 1fr;
            }

            .register-copy {
                padding: 1.5rem;
            }

            .register-steps {
                display: none;
            }

            .register-form-wrap {
                padding: 1.5rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .quota-options {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<main class="register-page">
    <section class="register-panel">

        <div class="register-copy">
            <div class="brand">
                <img class="brand-logo" src="assets/aci-sjm-marca.png" alt="ACISJM">
                <div>
                    <h1 style="font-size:1rem;margin:0;">ACISJM</h1>
                    <p style="font-size:.85rem;margin:.1rem 0 0;color:rgba(255,255,255,.8);">Plataforma de Associados</p>
                </div>
            </div>

            <div>
                <h1>Criar nova conta</h1>
                <p>Registe-se para aceder à área reservada aos associados da ACISJM.</p>
            </div>

            <div class="register-steps">
                <div class="register-step">
                    <div class="step-num">1</div>
                    <div class="step-text">
                        <strong>Preencha os dados</strong>
                        Indique os dados da empresa e os seus dados de acesso.
                    </div>
                </div>
                <div class="register-step">
                    <div class="step-num">2</div>
                    <div class="step-text">
                        <strong>Aguarde validação</strong>
                        O pedido será analisado pela administração da ACISJM.
                    </div>
                </div>
                <div class="register-step">
                    <div class="step-num">3</div>
                    <div class="step-text">
                        <strong>Aceda à plataforma</strong>
                        Após aprovação, receberá as credenciais de acesso por e-mail.
                    </div>
                </div>
            </div>
        </div>

        <div class="register-form-wrap">
            <h2>Criar conta de associado</h2>
            <p>Todos os campos marcados com * são obrigatórios.</p>

            <form class="register-form" method="post" enctype="multipart/form-data">
                <?= csrf_input() ?>

                <?php if ($message !== ''): ?>
                    <div class="alert <?= e($messageClass) ?>"><?= e($message) ?></div>
                <?php endif; ?>

                <p class="section-label">Dados da empresa</p>

                <div class="form-row">
                    <label>Nome da empresa *<input type="text" name="nome_empresa" placeholder="Empresa, Lda." required value="<?= e($_POST['nome_empresa'] ?? '') ?>"></label>
                    <label>NIF *<input type="text" name="nif" placeholder="123456789" required value="<?= e($_POST['nif'] ?? '') ?>"></label>
                </div>

                <div class="form-row">
                    <label>Setor de atividade<input type="text" name="setor_atividade" placeholder="ex: Tecnologia" value="<?= e($_POST['setor_atividade'] ?? '') ?>"></label>
                    <label>Localidade<input type="text" name="localidade" placeholder="ex: São João da Madeira" value="<?= e($_POST['localidade'] ?? '') ?>"></label>
                </div>

                <hr class="divider">
                <p class="section-label">Dados de acesso</p>

                <label>Nome do responsável *<input type="text" name="responsavel" placeholder="Nome completo" required value="<?= e($_POST['responsavel'] ?? '') ?>"></label>

                <div class="form-row">
                    <label>E-mail *<input type="email" name="email" placeholder="email@empresa.pt" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
                    <label>Telefone<input type="tel" name="telefone" placeholder="+351 900 000 000" value="<?= e($_POST['telefone'] ?? '') ?>"></label>
                </div>

                <div class="form-row">
                    <label>Palavra-passe *<input type="password" name="password" placeholder="Mínimo 8 caracteres" required autocomplete="new-password"></label>
                    <label>Confirmar palavra-passe *<input type="password" name="password_confirm" placeholder="Repita a palavra-passe" required autocomplete="new-password"></label>
                </div>

                <hr class="divider">
                <p class="section-label">Quota de associado *</p>

                <div class="quota-options">
                    <?php foreach (membership_plans() as $planKey => $plan): ?>
                        <label class="quota-option">
                            <input type="radio" name="quota_plano" value="<?= e($planKey) ?>" required <?= (($_POST['quota_plano'] ?? '') === $planKey) ? 'checked' : '' ?>>
                            <?= e($plan['label']) ?>
                            <strong><?= number_format($plan['value'], 2, ',', '.') ?>€</strong>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="payment-box">
                    <strong>Pagamento por transferência bancária</strong><br>
                    IBAN: <code>PT50 0018 2188 0219 6209 0202 9</code><br>
                    Anexe o comprovativo para a administração validar a quota mais rapidamente.
                </div>

                <label>Comprovativo de pagamento<input type="file" name="comprovativo_quota" accept="application/pdf,image/jpeg,image/png,image/webp"></label>

                <label class="consent-label">
                    <input type="checkbox" name="aceita_privacidade" value="1" required <?= isset($_POST['aceita_privacidade']) ? 'checked' : '' ?>>
                    <span>* Declaro que li e aceito o tratamento dos dados pessoais comunicados na Política de Privacidade.</span>
                </label>

                <label class="consent-label">
                    <input type="checkbox" name="aceita_contacto" value="1" required <?= isset($_POST['aceita_contacto']) ? 'checked' : '' ?>>
                    <span>* Declaro que consinto que os dados recolhidos neste formulário sejam tratados pela ACISJM para efeitos de envio de informação sobre o assunto identificado no mesmo.</span>
                </label>

                <div class="notice">
                    <strong>Nota:</strong> O acesso a benefícios, laboratórios e restantes vantagens de associado só fica disponível após validação do pagamento da quota pela administração.
                </div>

                <div class="register-actions">
                    <button type="submit">Submeter pedido de registo</button>
                    <p class="back-link">Já tem conta? <a href="index.php">Iniciar sessão</a></p>
                </div>

            </form>
        </div>

    </section>
</main>

<script src="pwa.js"></script>
</body>
</html>
