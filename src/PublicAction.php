<?php

namespace GlpiPlugin\Mailaprove;

use CommonITILValidation;
use ITILSolution;
use Ticket;
use TicketSatisfaction;
use TicketValidation;
use User;

class PublicAction
{
    public static function tokenErrorContent(?string $error): array
    {
        return match ($error) {
            'used' => [
                'title'   => __('Ação já processada', 'mailaprove'),
                'message' => __('Este link já foi utilizado ou outra ação relacionada já foi concluída.', 'mailaprove'),
            ],
            'expired' => [
                'title'   => __('Link expirado', 'mailaprove'),
                'message' => __('Este link expirou. Acesse o GLPI para continuar o atendimento.', 'mailaprove'),
            ],
            'invalid_action' => [
                'title'   => __('Ação inválida', 'mailaprove'),
                'message' => __('Este link não corresponde à ação solicitada.', 'mailaprove'),
            ],
            default => [
                'title'   => __('Link inválido', 'mailaprove'),
                'message' => __('Não foi possível validar este link. Use o link original recebido por e-mail.', 'mailaprove'),
            ],
        };
    }

    public static function ticketSummary(int $ticketId): array
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return [
                'id'       => $ticketId,
                'name'     => '',
                'subtitle' => __('Chamado não encontrado', 'mailaprove'),
            ];
        }

        $meta = [];
        if (!empty($ticket->fields['date_creation'])) {
            $meta[] = sprintf(__('aberto em %s', 'mailaprove'), (string) $ticket->fields['date_creation']);
        }

        $requesterId = (int) ($ticket->fields['users_id_recipient'] ?? 0);
        if ($requesterId > 0) {
            $user = new User();
            if ($user->getFromDB($requesterId)) {
                $meta[] = sprintf(__('solicitante: %s', 'mailaprove'), self::formatUserName($user));
            }
        }

        return [
            'id'       => $ticketId,
            'name'     => (string) ($ticket->fields['name'] ?? ''),
            'subtitle' => sprintf(__('Chamado #%d', 'mailaprove'), $ticketId),
            'meta'     => implode(' · ', $meta),
            'preview'  => self::textPreview((string) ($ticket->fields['content'] ?? ''), 220),
        ];
    }

    public static function validationContext(array $tokenData, bool $invalidateProcessedTokens = false): array
    {
        $validation = new TicketValidation();
        if (!$validation->getFromDB((int) $tokenData['items_id'])) {
            return self::errorContext(
                __('Validação não encontrada', 'mailaprove'),
                __('A solicitação de validação não foi encontrada. Ela pode ter sido removida no GLPI.', 'mailaprove')
            );
        }

        if ((int) ($validation->fields['tickets_id'] ?? 0) !== (int) $tokenData['tickets_id']) {
            AuditLog::record('validation_context_mismatch', 'error', AuditLog::contextFromTokenRow($tokenData));
            return self::errorContext(
                __('Link inconsistente', 'mailaprove'),
                __('Este link não corresponde mais à validação do chamado informado.', 'mailaprove')
            );
        }

        // GLPI 11 stores the validator target in (itemtype_target, items_id_target).
        // We accept the token's user_id when it matches the new schema, the
        // legacy users_id_validate column, or a member of the target group.
        if (!self::isAuthorizedValidator($validation, (int) $tokenData['users_id'])) {
            AuditLog::record('validation_user_mismatch', 'error', AuditLog::contextFromTokenRow($tokenData, [
                'payload' => [
                    'itemtype_target' => (string) ($validation->fields['itemtype_target'] ?? ''),
                    'items_id_target' => (int) ($validation->fields['items_id_target'] ?? 0),
                    'legacy_validator' => (int) ($validation->fields['users_id_validate'] ?? 0),
                ],
            ]));
            return self::errorContext(
                __('Ação não autorizada', 'mailaprove'),
                __('Este link pertence a outro aprovador.', 'mailaprove')
            );
        }

        if ((int) ($validation->fields['status'] ?? 0) !== CommonITILValidation::WAITING) {
            if ($invalidateProcessedTokens) {
                Token::markRelatedAsUsed([
                    Token::ACTION_VALIDATION_APPROVE,
                    Token::ACTION_VALIDATION_REJECT,
                ], (int) $tokenData['tickets_id'], (int) $tokenData['items_id']);
            }
            return self::errorContext(
                __('Validação já processada', 'mailaprove'),
                __('Esta validação já foi aprovada ou recusada no GLPI.', 'mailaprove'),
                'processed'
            );
        }

        return [
            'ok'         => true,
            'item'       => $validation,
            'ticket'     => self::ticketSummary((int) $tokenData['tickets_id']),
            'error_code' => null,
        ];
    }

    public static function solutionContext(array $tokenData, bool $invalidateProcessedTokens = false): array
    {
        $solution = new ITILSolution();
        if (!$solution->getFromDB((int) $tokenData['items_id'])) {
            return self::errorContext(
                __('Solução não encontrada', 'mailaprove'),
                __('A solução informada neste link não foi encontrada.', 'mailaprove')
            );
        }

        if (
            (string) ($solution->fields['itemtype'] ?? '') !== 'Ticket'
            || (int) ($solution->fields['items_id'] ?? 0) !== (int) $tokenData['tickets_id']
        ) {
            AuditLog::record('solution_context_mismatch', 'error', AuditLog::contextFromTokenRow($tokenData));
            return self::errorContext(
                __('Link inconsistente', 'mailaprove'),
                __('Este link não corresponde mais à solução do chamado informado.', 'mailaprove')
            );
        }

        if (!self::isTicketRequester((int) $tokenData['tickets_id'], (int) $tokenData['users_id'])) {
            AuditLog::record('solution_user_not_requester', 'error', AuditLog::contextFromTokenRow($tokenData));
            return self::errorContext(
                __('Ação não autorizada', 'mailaprove'),
                __('Somente o solicitante do chamado pode aceitar ou recusar a solução por e-mail.', 'mailaprove')
            );
        }

        if ((int) ($solution->fields['status'] ?? 0) !== CommonITILValidation::WAITING) {
            if ($invalidateProcessedTokens) {
                Token::markRelatedAsUsed([
                    Token::ACTION_SOLUTION_APPROVE,
                    Token::ACTION_SOLUTION_REJECT,
                ], (int) $tokenData['tickets_id'], (int) $tokenData['items_id']);
            }
            return self::errorContext(
                __('Solução já processada', 'mailaprove'),
                __('Esta solução já foi aceita ou recusada no GLPI.', 'mailaprove'),
                'processed'
            );
        }

        return [
            'ok'         => true,
            'item'       => $solution,
            'ticket'     => self::ticketSummary((int) $tokenData['tickets_id']),
            'error_code' => null,
        ];
    }

    public static function satisfactionContext(array $tokenData, bool $invalidateProcessedTokens = false): array
    {
        $satisfaction = new TicketSatisfaction();
        if (!$satisfaction->getFromDB((int) $tokenData['items_id'])) {
            return self::errorContext(
                __('Pesquisa não encontrada', 'mailaprove'),
                __('A pesquisa de satisfação informada neste link não foi encontrada.', 'mailaprove')
            );
        }

        if ((int) ($satisfaction->fields['tickets_id'] ?? 0) !== (int) $tokenData['tickets_id']) {
            AuditLog::record('satisfaction_context_mismatch', 'error', AuditLog::contextFromTokenRow($tokenData));
            return self::errorContext(
                __('Link inconsistente', 'mailaprove'),
                __('Este link não corresponde mais à pesquisa do chamado informado.', 'mailaprove')
            );
        }

        if (!self::isTicketRequester((int) $tokenData['tickets_id'], (int) $tokenData['users_id'])) {
            AuditLog::record('satisfaction_user_not_requester', 'error', AuditLog::contextFromTokenRow($tokenData));
            return self::errorContext(
                __('Ação não autorizada', 'mailaprove'),
                __('Somente o solicitante do chamado pode responder esta pesquisa por e-mail.', 'mailaprove')
            );
        }

        if (!empty($satisfaction->fields['date_answered'])) {
            if ($invalidateProcessedTokens) {
                Token::markRelatedAsUsed([
                    Token::ACTION_SATISFACTION,
                ], (int) $tokenData['tickets_id'], (int) $tokenData['items_id']);
            }
            return self::errorContext(
                __('Pesquisa já respondida', 'mailaprove'),
                __('Esta pesquisa de satisfação já foi respondida.', 'mailaprove'),
                'processed'
            );
        }

        return [
            'ok'         => true,
            'item'       => $satisfaction,
            'ticket'     => self::ticketSummary((int) $tokenData['tickets_id']),
            'error_code' => null,
        ];
    }

    /**
     * Apply an accept/reject decision to a TicketValidation row.
     *
     * The public mail endpoints run without a GLPI session, so calling
     * `$validation->update()` would silently strip the status / date /
     * comment fields in CommonITILValidation::prepareInputForUpdate
     * because "current user (0) is not the validator". We bypass that by
     * writing the row through $DB directly and then asking GLPI to
     * recompute the ticket's global_validation status.
     *
     * @return bool true if the row was actually changed.
     */
    public static function applyValidationDecision(
        int $validationId,
        int $newStatus,
        int $approverUserId,
        string $comment
    ): bool {
        global $DB;

        if ($validationId <= 0 || $approverUserId <= 0) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        $DB->update(
            'glpi_ticketvalidations',
            [
                'status'             => $newStatus,
                'users_id_validate'  => $approverUserId,
                'comment_validation' => $comment,
                'validation_date'    => $now,
            ],
            ['id' => $validationId]
        );

        if ((int) $DB->affectedRows() < 1) {
            return false;
        }

        // Reload the row and recompute the parent ticket's global_validation.
        $validation = new TicketValidation();
        if (!$validation->getFromDB($validationId)) {
            return true; // row updated; ticket recompute will eventually catch up.
        }

        $ticketId = (int) ($validation->fields['tickets_id'] ?? 0);
        if ($ticketId <= 0) {
            return true;
        }

        $ticket = new \Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return true;
        }

        if (method_exists(\CommonITILValidation::class, 'computeValidationStatus')) {
            $globalStatus = \CommonITILValidation::computeValidationStatus($ticket);
            $DB->update(
                'glpi_tickets',
                ['global_validation' => $globalStatus],
                ['id' => $ticketId]
            );
        }

        // Mirror GLPI's UI behaviour: notify the requester that their
        // validation request was answered.
        if (class_exists(\NotificationEvent::class)) {
            try {
                \NotificationEvent::raiseEvent('validation_answer', $ticket, [
                    'validation_id'     => $validationId,
                    'validation_status' => $newStatus,
                ]);
            } catch (\Throwable $e) {
                // Never block the approval flow on a notification failure.
            }
        }

        return true;
    }

    /**
     * Apply a solution accept/reject decision from the public endpoints.
     *
     * Same rationale as applyValidationDecision: we write through $DB to
     * avoid the model-level permission checks (which silently drop fields
     * when there is no GLPI session) and then drive the side-effects
     * (ticket status change, follow-up entry, requester notification) by
     * hand.
     *
     * @return bool true if the solution row was updated.
     */
    public static function applySolutionDecision(
        int $solutionId,
        int $newStatus,
        int $approverUserId,
        string $followupContent
    ): bool {
        global $DB;

        if ($solutionId <= 0 || $approverUserId <= 0) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        $DB->update(
            'glpi_itilsolutions',
            [
                'status'             => $newStatus,
                'users_id_approval'  => $approverUserId,
                'date_approval'      => $now,
                'date_mod'           => $now,
            ],
            ['id' => $solutionId]
        );

        if ((int) $DB->affectedRows() < 1) {
            return false;
        }

        $solution = new \ITILSolution();
        if (!$solution->getFromDB($solutionId)) {
            return true;
        }

        $itemtype = (string) ($solution->fields['itemtype'] ?? '');
        $ticketId = (int) ($solution->fields['items_id'] ?? 0);
        if ($itemtype !== 'Ticket' || $ticketId <= 0) {
            return true;
        }

        // Audit follow-up so the decision is visible in the timeline.
        $DB->insert('glpi_itilfollowups', [
            'items_id'        => $ticketId,
            'itemtype'        => 'Ticket',
            'users_id'        => $approverUserId,
            'date'            => $now,
            'date_creation'   => $now,
            'date_mod'        => $now,
            'content'         => $followupContent,
            'is_private'      => 0,
            'requesttypes_id' => 0,
        ]);

        $ticket = new \Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return true;
        }

        if ($newStatus === \CommonITILValidation::ACCEPTED) {
            // Mirror GLPI UI: accepting a solution closes the ticket.
            $closedStatus = defined('Ticket::CLOSED')
                ? \Ticket::CLOSED
                : 6; // CommonITILObject::CLOSED is 6.
            $update = [
                'status'   => $closedStatus,
                'closedate'=> $now,
                'date_mod' => $now,
            ];
            if (empty($ticket->fields['solvedate'])) {
                $update['solvedate'] = $now;
            }
            $DB->update('glpi_tickets', $update, ['id' => $ticketId]);
        }
        // On REFUSED we deliberately leave the ticket status alone — that's
        // what GLPI's UI does too: only the solution row flips, the ticket
        // stays where it is so techs can pick it back up.

        // Fire the matching notification (parity with the GLPI UI flow).
        if (class_exists(\NotificationEvent::class)) {
            try {
                $event = $newStatus === \CommonITILValidation::REFUSED
                    ? 'rejectsolution'
                    : 'solved';
                \NotificationEvent::raiseEvent($event, $ticket, [
                    'solution_id' => $solutionId,
                ]);
            } catch (\Throwable $e) {
                // Never block the approval flow on a notification failure.
            }
        }

        return true;
    }

    /**
     * Persist a satisfaction survey answer from the public endpoint.
     *
     * @return bool true if the row was updated.
     */
    public static function applySatisfactionResponse(
        int $satisfactionId,
        int $rating,
        string $comment
    ): bool {
        global $DB;

        if ($satisfactionId <= 0 || $rating < 1 || $rating > 5) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        $DB->update(
            'glpi_ticketsatisfactions',
            [
                'satisfaction'  => $rating,
                'comment'       => $comment,
                'date_answered' => $now,
            ],
            ['id' => $satisfactionId]
        );

        if ((int) $DB->affectedRows() < 1) {
            return false;
        }

        // Fire the matching notification so techs see the rating arrive.
        $satisfaction = new \TicketSatisfaction();
        if ($satisfaction->getFromDB($satisfactionId)) {
            $ticketId = (int) ($satisfaction->fields['tickets_id'] ?? 0);
            if ($ticketId > 0 && class_exists(\NotificationEvent::class)) {
                $ticket = new \Ticket();
                if ($ticket->getFromDB($ticketId)) {
                    try {
                        \NotificationEvent::raiseEvent('replysatisfaction', $ticket, [
                            'satisfaction_id' => $satisfactionId,
                            'satisfaction'    => $rating,
                        ]);
                    } catch (\Throwable $e) {
                        // ignore — answer is already stored.
                    }
                }
            }
        }

        return true;
    }

    /**
     * Check that the given user is allowed to approve the given validation.
     *
     * Handles both the GLPI 11 schema (itemtype_target/items_id_target) and
     * the legacy users_id_validate column.
     */
    public static function isAuthorizedValidator(TicketValidation $validation, int $userId): bool
    {
        global $DB;

        if ($userId <= 0) {
            return false;
        }

        $itemtypeTarget = (string) ($validation->fields['itemtype_target'] ?? '');
        $itemsIdTarget  = (int) ($validation->fields['items_id_target'] ?? 0);
        $legacyUserId   = (int) ($validation->fields['users_id_validate'] ?? 0);

        if ($itemtypeTarget === 'User' && $itemsIdTarget > 0) {
            return $itemsIdTarget === $userId;
        }

        if ($itemtypeTarget === 'Group' && $itemsIdTarget > 0) {
            $iterator = $DB->request([
                'FROM'  => 'glpi_groups_users',
                'WHERE' => ['users_id' => $userId, 'groups_id' => $itemsIdTarget],
                'LIMIT' => 1,
            ]);
            return count($iterator) > 0;
        }

        if ($legacyUserId > 0) {
            return $legacyUserId === $userId;
        }

        return false;
    }

    public static function isTicketRequester(int $ticketId, int $userId): bool
    {
        global $DB;

        if ($ticketId <= 0 || $userId <= 0) {
            return false;
        }

        $ticket = new Ticket();
        if ($ticket->getFromDB($ticketId) && (int) ($ticket->fields['users_id_recipient'] ?? 0) === $userId) {
            return true;
        }

        $requesterType = class_exists('\\CommonITILActor')
            ? \CommonITILActor::REQUESTER
            : 1;

        $iterator = $DB->request([
            'FROM'  => 'glpi_tickets_users',
            'WHERE' => [
                'tickets_id' => $ticketId,
                'users_id'   => $userId,
                'type'       => $requesterType,
            ],
            'LIMIT' => 1,
        ]);

        return count($iterator) > 0;
    }

    public static function renderError(array $context): void
    {
        $errorTitle = $context['title'] ?? __('Não foi possível processar', 'mailaprove');
        $errorMessage = $context['message'] ?? __('A ação não pôde ser concluída.', 'mailaprove');
        include(__DIR__ . '/../templates/error.php');
    }

    private static function errorContext(string $title, string $message, string $code = 'invalid'): array
    {
        return [
            'ok'         => false,
            'title'      => $title,
            'message'    => $message,
            'error_code' => $code,
        ];
    }

    private static function formatUserName(User $user): string
    {
        $parts = array_filter([
            trim((string) ($user->fields['firstname'] ?? '')),
            trim((string) ($user->fields['realname'] ?? '')),
        ]);

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        return (string) ($user->fields['name'] ?? __('usuário', 'mailaprove'));
    }

    private static function textPreview(string $html, int $maxLength): string
    {
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')) ?? '');
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $maxLength
                ? rtrim(mb_substr($text, 0, $maxLength - 1)) . '...'
                : $text;
        }

        return strlen($text) > $maxLength
            ? rtrim(substr($text, 0, $maxLength - 1)) . '...'
            : $text;
    }
}
