DROP DATABASE IF EXISTS acisjm;
CREATE DATABASE acisjm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE acisjm;

CREATE TABLE empresas_associadas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_associado VARCHAR(50) NOT NULL UNIQUE,
    nome_empresa VARCHAR(255) NOT NULL,
    nome_comercial VARCHAR(255),
    nif VARCHAR(20) NOT NULL UNIQUE,
    cae VARCHAR(20),
    setor_atividade VARCHAR(255),
    email VARCHAR(255),
    telefone VARCHAR(50),
    morada VARCHAR(255),
    codigo_postal VARCHAR(20),
    localidade VARCHAR(100),
    responsavel VARCHAR(255),
    numero_colaboradores INT,
    tipo_associado VARCHAR(100),
    quota_plano ENUM('mensal','semestral','anual') NULL,
    quota_valor DECIMAL(8,2) NULL,
    quota_estado ENUM('pendente','pago','atrasado','isento') NOT NULL DEFAULT 'pendente',
    quota_pago_em DATE NULL,
    quota_validade DATE NULL,
    comprovativo_quota VARCHAR(255),
    estado ENUM('ativo', 'inativo', 'suspenso') NOT NULL DEFAULT 'ativo',
    data_adesao DATE,
    observacoes_internas TEXT
);

CREATE TABLE utilizadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    perfil ENUM('admin', 'associado') NOT NULL,
    FOREIGN KEY (empresa_id)
    REFERENCES empresas_associadas(id)
    ON DELETE CASCADE
);

CREATE TABLE servicos_iniciativas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT,
    categoria VARCHAR(100),
    data_inicio DATE,
    data_fim DATE,
    local_evento VARCHAR(255),
    link_inscricao VARCHAR(255),
    estado ENUM('ativo', 'concluido', 'em_preparacao') NOT NULL DEFAULT 'ativo'
);

CREATE TABLE parceiros_descontos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome_parceiro VARCHAR(255) NOT NULL,
    descricao_beneficio TEXT,
    desconto VARCHAR(100),
    condicoes TEXT,
    contacto VARCHAR(255),
    estado ENUM('ativo', 'inativo') NOT NULL DEFAULT 'ativo'
);

CREATE TABLE qr_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    codigo_qr VARCHAR(255) NOT NULL UNIQUE,
    FOREIGN KEY (empresa_id)
    REFERENCES empresas_associadas(id)
    ON DELETE CASCADE
);

CREATE TABLE solicitacoes_associado (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome_empresa VARCHAR(255) NOT NULL,
    nif VARCHAR(20) NOT NULL,
    setor_atividade VARCHAR(255),
    localidade VARCHAR(100),
    responsavel VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    telefone VARCHAR(50),
    quota_plano ENUM('mensal','semestral','anual') NULL,
    quota_valor DECIMAL(8,2) NULL,
    comprovativo_quota VARCHAR(255),
    aceita_privacidade TINYINT(1) NOT NULL DEFAULT 0,
    aceita_contacto TINYINT(1) NOT NULL DEFAULT 0,
    password_hash VARCHAR(255) NOT NULL,
    estado ENUM('pendente', 'aprovado', 'rejeitado') NOT NULL DEFAULT 'pendente',
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    analisado_em DATETIME NULL,
    UNIQUE KEY uniq_solicitacao_email_estado (email, estado),
    UNIQUE KEY uniq_solicitacao_nif_estado (nif, estado)
);

CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_login_attempts_lookup (email, ip_address, attempted_at)
);
