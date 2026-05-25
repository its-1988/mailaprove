<?php

if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

use GlpiPlugin\Mailaprove\Token;
use GlpiPlugin\Mailaprove\PublicAction;
use GlpiPlugin\Mailaprove\AuditLog;

$rawToken = $_GET['token'] ?? $_POST['token'] ?? '';

if (empty($rawToken)) {
    $error = 'missing_token';
    $errorTitle = __('Token ausente', 'mailaprove');
    $errorMessage = __('Nenhum token de aprovação foi informado. Use o link original recebido por e-mail.', 'mailaprove');
    include(__DIR__ . '/../templates/error.php');
    exit;
}

$result = Token::validateTokenWithStatus($rawToken);

if (!$result['valid']) {
    PublicAction::renderError(PublicAction::tokenErrorContent($result['error']));
    exit;
}

$tokenData = (array) $result['data'];

if ($tokenData['action_type'] !== Token::ACTION_VALIDATION_REJECT) {
    PublicAction::renderError(PublicAction::tokenErrorContent('invalid_action'));
    exit;
}

$context = PublicAction::validationContext($tokenData);
if (!$context['ok']) {
    PublicAction::renderError($context);
    exit;
}

// POST: Process the rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comment = trim($_POST['comment_validation'] ?? '');

    if (empty($comment)) {
        $formError = __('Informe o motivo da recusa.', 'mailaprove');
        $ticketId = (int)$tokenData['tickets_id'];
        $ticketSummary = $context['ticket'];
        include(__DIR__ . '/../templates/reject_form.php');
        exit;
    }

    $claim = Token::claimTokenWithStatus($rawToken, Token::ACTION_VALIDATION_REJECT);
    if (!$claim['valid']) {
        PublicAction::renderError(PublicAction::tokenErrorContent($claim['error']));
        exit;
    }
    $tokenData = (array) $claim['data'];

    $context = PublicAction::validationContext($tokenData, true);
    if (!$context['ok']) {
        PublicAction::renderError($context);
        exit;
    }

    // Bypass session-based permission checks (token is the auth) and
    // write the rejection directly through $DB.
    $updateResult = PublicAction::applyValidationDecision(
        (int) $tokenData['items_id'],
        CommonITILValidation::REFUSED,
        (int) $tokenData['users_id'],
        $comment
    );

    if ($updateResult) {
        Token::markRelatedAsUsed([
            Token::ACTION_VALIDATION_APPROVE,
            Token::ACTION_VALIDATION_REJECT,
        ], (int) $tokenData['tickets_id'], (int) $tokenData['items_id']);
        AuditLog::record('validation_rejected', 'success', AuditLog::contextFromTokenRow($tokenData));

        $confirmTitle = __('Validação recusada', 'mailaprove');
        $confirmMessage = sprintf(
            __('A validação do chamado #%d foi recusada.', 'mailaprove'),
            (int)$tokenData['tickets_id']
        );
        $confirmType = 'warning';
        include(__DIR__ . '/../templates/confirm.php');
    } else {
        AuditLog::record('validation_reject_failed', 'error', AuditLog::contextFromTokenRow($tokenData));
        $errorTitle = __('Erro ao processar', 'mailaprove');
        $errorMessage = __('Não foi possível recusar a validação. Acesse o GLPI para verificar o chamado.', 'mailaprove');
        include(__DIR__ . '/../templates/error.php');
    }
    exit;
}

// GET: Show rejection form
$ticketId = (int)$tokenData['tickets_id'];
$formError = '';
$ticketSummary = $context['ticket'];
include(__DIR__ . '/../templates/reject_form.php');
