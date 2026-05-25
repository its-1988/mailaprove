<?php

if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

use GlpiPlugin\Mailaprove\Token;
use GlpiPlugin\Mailaprove\PublicAction;
use GlpiPlugin\Mailaprove\AuditLog;

$rawToken = $_GET['token'] ?? $_POST['token'] ?? '';

if (empty($rawToken)) {
    $errorTitle = __('Token ausente', 'mailaprove');
    $errorMessage = __('Nenhum token foi informado. Use o link original recebido por e-mail.', 'mailaprove');
    include(__DIR__ . '/../templates/error.php');
    exit;
}

$result = Token::validateTokenWithStatus($rawToken);

if (!$result['valid']) {
    PublicAction::renderError(PublicAction::tokenErrorContent($result['error']));
    exit;
}

$tokenData = (array) $result['data'];

if ($tokenData['action_type'] !== Token::ACTION_SOLUTION_REJECT) {
    PublicAction::renderError(PublicAction::tokenErrorContent('invalid_action'));
    exit;
}

$context = PublicAction::solutionContext($tokenData);
if (!$context['ok']) {
    PublicAction::renderError($context);
    exit;
}

// POST: Process the rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comment = trim($_POST['comment'] ?? '');

    if (empty($comment)) {
        $formError = __('Informe o motivo da recusa da solução.', 'mailaprove');
        $ticketId = (int)$tokenData['tickets_id'];
        $ticketSummary = $context['ticket'];
        $formTitle = __('Recusar solução', 'mailaprove');
        $formAction = 'solution_reject';
        include(__DIR__ . '/../templates/reject_form.php');
        exit;
    }

    $claim = Token::claimTokenWithStatus($rawToken, Token::ACTION_SOLUTION_REJECT);
    if (!$claim['valid']) {
        PublicAction::renderError(PublicAction::tokenErrorContent($claim['error']));
        exit;
    }
    $tokenData = (array) $claim['data'];

    $context = PublicAction::solutionContext($tokenData, true);
    if (!$context['ok']) {
        PublicAction::renderError($context);
        exit;
    }

    // Bypass session-based permission checks (token is the auth) and apply
    // the solution rejection through $DB directly.
    $updateResult = PublicAction::applySolutionDecision(
        (int) $tokenData['items_id'],
        CommonITILValidation::REFUSED,
        (int) $tokenData['users_id'],
        sprintf(__('Solução recusada por e-mail. Motivo: %s', 'mailaprove'), $comment)
    );

    if ($updateResult) {
        Token::markRelatedAsUsed([
            Token::ACTION_SOLUTION_APPROVE,
            Token::ACTION_SOLUTION_REJECT,
        ], (int) $tokenData['tickets_id'], (int) $tokenData['items_id']);
        AuditLog::record('solution_rejected', 'success', AuditLog::contextFromTokenRow($tokenData));

        $confirmTitle = __('Solução recusada', 'mailaprove');
        $confirmMessage = sprintf(
            __('A solução do chamado #%d foi recusada.', 'mailaprove'),
            (int)$tokenData['tickets_id']
        );
        $confirmType = 'warning';
        include(__DIR__ . '/../templates/confirm.php');
    } else {
        AuditLog::record('solution_reject_failed', 'error', AuditLog::contextFromTokenRow($tokenData));
        $errorTitle = __('Erro ao processar', 'mailaprove');
        $errorMessage = __('Não foi possível recusar a solução. Acesse o GLPI para verificar o chamado.', 'mailaprove');
        include(__DIR__ . '/../templates/error.php');
    }
    exit;
}

// GET: Show rejection form
$ticketId = (int)$tokenData['tickets_id'];
$formError = '';
$ticketSummary = $context['ticket'];
$formTitle = __('Recusar solução', 'mailaprove');
$formAction = 'solution_reject';
include(__DIR__ . '/../templates/reject_form.php');
