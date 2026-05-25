<?php

if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

use GlpiPlugin\Mailaprove\Token;
use GlpiPlugin\Mailaprove\PublicAction;
use GlpiPlugin\Mailaprove\AuditLog;

$rawToken = $_POST['token'] ?? $_GET['token'] ?? '';

if (empty($rawToken)) {
    $errorTitle = __('Token ausente', 'mailaprove');
    $errorMessage = __('Nenhum token foi informado. Use o link original recebido por e-mail.', 'mailaprove');
    include(__DIR__ . '/../templates/error.php');
    exit;
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$result = $isPost
    ? Token::claimTokenWithStatus($rawToken, Token::ACTION_SOLUTION_APPROVE)
    : Token::validateTokenWithStatus($rawToken);

if (!$result['valid']) {
    PublicAction::renderError(PublicAction::tokenErrorContent($result['error']));
    exit;
}

$tokenData = (array) $result['data'];

if ($tokenData['action_type'] !== Token::ACTION_SOLUTION_APPROVE) {
    PublicAction::renderError(PublicAction::tokenErrorContent('invalid_action'));
    exit;
}

$context = PublicAction::solutionContext($tokenData, $isPost);
if (!$context['ok']) {
    PublicAction::renderError($context);
    exit;
}

if (!$isPost) {
    $confirmTitle = __('Confirmar aceite da solução', 'mailaprove');
    $confirmMessage = __('Revise o chamado antes de aceitar esta solução por e-mail.', 'mailaprove');
    $confirmButton = __('Aceitar solução', 'mailaprove');
    $confirmType = 'success';
    $confirmNote = __('Após confirmar, a solução será marcada como aceita no GLPI e os links relacionados serão invalidados.', 'mailaprove');
    $actionUrl = 'solution_approve.php';
    $ticketSummary = $context['ticket'];
    include(__DIR__ . '/../templates/action_confirm.php');
    exit;
}

// Bypass session-based permission checks (token is the auth) and apply the
// solution acceptance through $DB directly — same reason as approve.php.
$updateResult = PublicAction::applySolutionDecision(
    (int) $tokenData['items_id'],
    CommonITILValidation::ACCEPTED,
    (int) $tokenData['users_id'],
    __('Solução aceita por e-mail', 'mailaprove')
);

if ($updateResult) {
    Token::markRelatedAsUsed([
        Token::ACTION_SOLUTION_APPROVE,
        Token::ACTION_SOLUTION_REJECT,
    ], (int) $tokenData['tickets_id'], (int) $tokenData['items_id']);
    AuditLog::record('solution_approved', 'success', AuditLog::contextFromTokenRow($tokenData));

    $confirmTitle = __('Solução aceita', 'mailaprove');
    $confirmMessage = sprintf(
        __('A solução do chamado #%d foi aceita com sucesso.', 'mailaprove'),
        (int)$tokenData['tickets_id']
    );
    $confirmType = 'success';
    include(__DIR__ . '/../templates/confirm.php');
} else {
    AuditLog::record('solution_approve_failed', 'error', AuditLog::contextFromTokenRow($tokenData));
    $errorTitle = __('Erro ao processar', 'mailaprove');
    $errorMessage = __('Não foi possível aceitar a solução. Acesse o GLPI para verificar o chamado.', 'mailaprove');
    include(__DIR__ . '/../templates/error.php');
}
