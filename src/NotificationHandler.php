<?php

namespace GlpiPlugin\Mailaprove;

use NotificationTarget;
use NotificationTargetTicket;
use Ticket;
use TicketValidation;
use TicketSatisfaction;
use CommonITILValidation;

class NotificationHandler
{
    /**
     * Hook callback for ITEM_GET_DATA on NotificationTargetTicket.
     * Called when GLPI assembles notification data for ticket-related events.
     *
     * @param NotificationTargetTicket $target
     */
    public static function handleNotificationData(NotificationTargetTicket $target): void
    {
        $config = Config::getConfig();

        // Diagnostic trace so admins can verify the hook fires and on which event.
        AuditLog::record('hook_invoked', 'info', [
            'tickets_id' => (int) ($target->obj->fields['id'] ?? 0),
            'message'    => 'ITEM_GET_DATA invoked for NotificationTargetTicket',
            'payload'    => [
                'event'           => (string) ($target->raiseevent ?? ''),
                'recipient_data'  => isset($target->recipient_data) ? $target->recipient_data : null,
                'has_user_id'     => isset($target->data['##user.id##']) ? (int) $target->data['##user.id##'] : null,
                'validation_id'   => isset($target->options['validation_id']) ? (int) $target->options['validation_id'] : null,
                'has_obj'         => $target->obj !== null,
                'options_keys'    => is_array($target->options ?? null) ? array_keys($target->options) : [],
                'config_validation'   => (int) ($config['enable_validation'] ?? 0),
                'config_solution'     => (int) ($config['enable_solution'] ?? 0),
                'config_satisfaction' => (int) ($config['enable_satisfaction'] ?? 0),
            ],
        ]);

        // Register all custom tags (so they appear in the template editor)
        self::registerTags($target, $config);

        // Populate tag values based on the current event
        self::populateTagData($target, $config);
    }

    /**
     * Register custom tags in the notification template system.
     */
    private static function registerTags(NotificationTargetTicket $target, array $config): void
    {
        if (!empty($config['enable_validation'])) {
            $target->addTagToList([
                'tag'    => 'ticket.validation.accepturl',
                'label'  => __('URL para aprovar validação', 'mailaprove'),
                'value'  => true,
                'events' => NotificationTarget::TAG_FOR_ALL_EVENTS,
            ]);
            $target->addTagToList([
                'tag'    => 'ticket.validation.rejecturl',
                'label'  => __('URL para recusar validação', 'mailaprove'),
                'value'  => true,
                'events' => NotificationTarget::TAG_FOR_ALL_EVENTS,
            ]);
            $target->addTagToList([
                'tag'    => 'ticket.validation.buttons',
                'label'  => __('Botões de aprovação da validação', 'mailaprove'),
                'value'  => true,
                'events' => NotificationTarget::TAG_FOR_ALL_EVENTS,
            ]);
        }

        if (!empty($config['enable_solution'])) {
            $target->addTagToList([
                'tag'    => 'ticket.solution.accepturl',
                'label'  => __('URL para aceitar solução', 'mailaprove'),
                'value'  => true,
                'events' => NotificationTarget::TAG_FOR_ALL_EVENTS,
            ]);
            $target->addTagToList([
                'tag'    => 'ticket.solution.rejecturl',
                'label'  => __('URL para recusar solução', 'mailaprove'),
                'value'  => true,
                'events' => NotificationTarget::TAG_FOR_ALL_EVENTS,
            ]);
            $target->addTagToList([
                'tag'    => 'ticket.solution.buttons',
                'label'  => __('Botões de aceite da solução', 'mailaprove'),
                'value'  => true,
                'events' => NotificationTarget::TAG_FOR_ALL_EVENTS,
            ]);
        }

        if (!empty($config['enable_satisfaction'])) {
            $target->addTagToList([
                'tag'    => 'ticket.satisfaction.url',
                'label'  => __('URL da pesquisa de satisfação', 'mailaprove'),
                'value'  => true,
                'events' => NotificationTarget::TAG_FOR_ALL_EVENTS,
            ]);
            $target->addTagToList([
                'tag'    => 'ticket.satisfaction.button',
                'label'  => __('Botão da pesquisa de satisfação', 'mailaprove'),
                'value'  => true,
                'events' => NotificationTarget::TAG_FOR_ALL_EVENTS,
            ]);
        }
    }

    /**
     * Populate tag values with generated token URLs.
     */
    private static function populateTagData(NotificationTargetTicket $target, array $config): void
    {
        // Get the ticket object
        $ticket = $target->obj ?? null;
        if (!($ticket instanceof Ticket) || empty($ticket->fields['id'])) {
            return;
        }

        $ticketId = (int)$ticket->fields['id'];
        $event = $target->raiseevent ?? '';

        // Determine the recipient user ID
        // In GLPI notifications, we look at who the notification is being sent to
        $recipientUserId = self::getRecipientUserId($target);

        // Standard NotificationTargetTicket events in GLPI 11:
        //   new, update, solved, rejectsolution, validation, validation_answer,
        //   validation_reminder, closed, delete, alertnotclosed, recall,
        //   recall_ola, satisfaction, replysatisfaction
        // We are permissive about which event triggers each injection so the
        // plugin works regardless of which template (Solved / Update /
        // Closed / etc.) the admin chose to embed the tags in.

        $validationEvents = ['validation', 'validation_answer', 'validation_reminder', 'new', 'update'];
        $solutionEvents   = ['solved', 'rejectsolution', 'update', 'new', 'closed'];
        $satisfactionEvents = ['satisfaction', 'replysatisfaction'];

        if (!empty($config['enable_validation']) && in_array($event, $validationEvents, true)) {
            self::populateValidationUrls($target, $ticketId, $recipientUserId);
        }

        if (!empty($config['enable_solution']) && in_array($event, $solutionEvents, true)) {
            self::populateSolutionUrls($target, $ticketId, $recipientUserId);
        }

        if (!empty($config['enable_satisfaction']) && in_array($event, $satisfactionEvents, true)) {
            self::populateSatisfactionUrls($target, $ticketId, $recipientUserId);
        }
    }

    /**
     * Return true if the user is a member of the given GLPI group.
     */
    private static function isUserInGroup(int $userId, int $groupId): bool
    {
        global $DB;

        if ($userId <= 0 || $groupId <= 0) {
            return false;
        }

        $iterator = $DB->request([
            'FROM'  => 'glpi_groups_users',
            'WHERE' => [
                'users_id'  => $userId,
                'groups_id' => $groupId,
            ],
            'LIMIT' => 1,
        ]);

        return count($iterator) > 0;
    }

    /**
     * Get the recipient user ID from the notification target.
     */
    private static function getRecipientUserId(NotificationTargetTicket $target): int
    {
        // GLPI populates target->recipient_data during recipient resolution.
        if (isset($target->recipient_data)
            && is_array($target->recipient_data)
            && (string) ($target->recipient_data['itemtype'] ?? '') === 'User'
            && !empty($target->recipient_data['items_id'])
        ) {
            return (int) $target->recipient_data['items_id'];
        }

        // Standard per-recipient template data macros.
        foreach (['##user.id##', '##validation.validator.id##', '##author.id##'] as $key) {
            if (isset($target->data[$key]) && (int) $target->data[$key] > 0) {
                return (int) $target->data[$key];
            }
        }

        // Notification options bag, e.g. when invoked programmatically.
        if (isset($target->options['users_id']) && (int) $target->options['users_id'] > 0) {
            return (int) $target->options['users_id'];
        }

        // target->target stores the current recipient identifier in some versions.
        if (
            property_exists($target, 'target')
            && is_array($target->target)
            && (string) ($target->target['itemtype'] ?? '') === 'User'
        ) {
            return (int) ($target->target['items_id'] ?? 0);
        }

        return 0;
    }

    /**
     * Generate and populate validation approval/rejection URLs.
     */
    private static function populateValidationUrls(
        NotificationTargetTicket $target,
        int $ticketId,
        int $recipientUserId
    ): void {
        global $DB;

        // GLPI 11 raises validation notifications with options.validation_id
        // pointing at the specific TicketValidation row being processed.
        // This is the most reliable way to discover which validation we are
        // sending the e-mail for (the recipient may be the validator, a
        // substitute, or the requester depending on the template).
        $optionValidationId = 0;
        if (isset($target->options['validation_id']) && (int) $target->options['validation_id'] > 0) {
            $optionValidationId = (int) $target->options['validation_id'];
        }

        $validation = null;

        if ($optionValidationId > 0) {
            $iterator = $DB->request([
                'FROM'  => 'glpi_ticketvalidations',
                'WHERE' => [
                    'id'         => $optionValidationId,
                    'tickets_id' => $ticketId,
                ],
                'LIMIT' => 1,
            ]);
            if (count($iterator) > 0) {
                $validation = (array) $iterator->current();
            }
        }

        if ($validation === null && $recipientUserId > 0) {
            // Fallback: most recent pending validation for this validator.
            $iterator = $DB->request([
                'FROM'  => 'glpi_ticketvalidations',
                'WHERE' => [
                    'tickets_id'        => $ticketId,
                    'status'            => CommonITILValidation::WAITING,
                    'users_id_validate' => $recipientUserId,
                ],
                'ORDER' => 'submission_date DESC',
                'LIMIT' => 1,
            ]);
            if (count($iterator) > 0) {
                $validation = (array) $iterator->current();
            }
        }

        if ($validation === null) {
            AuditLog::record('validation_url_skip', 'warning', [
                'tickets_id' => $ticketId,
                'message'    => 'No matching validation row found',
                'payload'    => [
                    'option_validation_id' => $optionValidationId,
                    'recipient_user_id'    => $recipientUserId,
                ],
            ]);
            self::clearValidationTags($target);
            return;
        }

        // Resolve the validator user ID.
        //
        // GLPI 11 stores the validation target in (itemtype_target,
        // items_id_target) to support both User and Group targets. The legacy
        // `users_id_validate` column is 0 for rows created on 11.x.
        //
        // - itemtype_target = 'User'  → items_id_target is the validator.
        // - itemtype_target = 'Group' → any member of the group can approve.
        //   We use the recipient's user ID after verifying group membership.
        // - Fallback: legacy `users_id_validate` (rows migrated from 10.x).
        $itemtypeTarget = (string) ($validation['itemtype_target'] ?? '');
        $itemsIdTarget  = (int) ($validation['items_id_target'] ?? 0);
        $legacyUserId   = (int) ($validation['users_id_validate'] ?? 0);

        $validatorUserId = 0;
        if ($itemtypeTarget === 'User' && $itemsIdTarget > 0) {
            $validatorUserId = $itemsIdTarget;
        } elseif ($itemtypeTarget === 'Group' && $itemsIdTarget > 0) {
            if ($recipientUserId > 0 && self::isUserInGroup($recipientUserId, $itemsIdTarget)) {
                $validatorUserId = $recipientUserId;
            }
        } elseif ($legacyUserId > 0) {
            $validatorUserId = $legacyUserId;
        }

        if ($validatorUserId <= 0) {
            AuditLog::record('validation_url_skip', 'warning', [
                'tickets_id' => $ticketId,
                'message'    => 'Could not resolve validator from target/recipient',
                'payload'    => [
                    'validation_id'    => (int) $validation['id'],
                    'itemtype_target'  => $itemtypeTarget,
                    'items_id_target'  => $itemsIdTarget,
                    'legacy_user_id'   => $legacyUserId,
                    'recipient_user_id'=> $recipientUserId,
                ],
            ]);
            self::clearValidationTags($target);
            return;
        }

        if (
            $itemtypeTarget === 'User'
            && $recipientUserId > 0
            && $recipientUserId !== $validatorUserId
        ) {
            // Recipient is not the validator (e.g. CC, watcher) — do not leak tokens.
            AuditLog::record('validation_url_skip', 'warning', [
                'tickets_id' => $ticketId,
                'message'    => 'Recipient is not the validator',
                'payload'    => [
                    'recipient_user_id' => $recipientUserId,
                    'validator_user_id' => $validatorUserId,
                ],
            ]);
            self::clearValidationTags($target);
            return;
        }

        if ((int) ($validation['status'] ?? 0) !== CommonITILValidation::WAITING) {
            AuditLog::record('validation_url_skip', 'warning', [
                'tickets_id' => $ticketId,
                'message'    => 'Validation is not in WAITING status',
                'payload'    => [
                    'validation_id' => (int) $validation['id'],
                    'status'        => (int) ($validation['status'] ?? 0),
                ],
            ]);
            self::clearValidationTags($target);
            return;
        }

        AuditLog::record('validation_url_ok', 'info', [
            'tickets_id' => $ticketId,
            'message'    => 'Generating validation approve/reject tokens',
            'payload'    => [
                'validation_id'     => (int) $validation['id'],
                'validator_user_id' => $validatorUserId,
            ],
        ]);

        $validationId = (int) $validation['id'];
        $userId       = $validatorUserId;

        // Generate approve token
        $approveToken = Token::generateToken(
            Token::ACTION_VALIDATION_APPROVE,
            $ticketId,
            $validationId,
            $userId
        );
        $target->data['##ticket.validation.accepturl##'] = $approveToken
            ? Token::buildActionUrl($approveToken, Token::ACTION_VALIDATION_APPROVE)
            : '';

        // Generate reject token
        $rejectToken = Token::generateToken(
            Token::ACTION_VALIDATION_REJECT,
            $ticketId,
            $validationId,
            $userId
        );
        $target->data['##ticket.validation.rejecturl##'] = $rejectToken
            ? Token::buildActionUrl($rejectToken, Token::ACTION_VALIDATION_REJECT)
            : '';

        $target->data['##ticket.validation.buttons##'] = self::renderDualButtonBlock(
            $target,
            'template_validation',
            __('Revise a validação solicitada para este chamado.', 'mailaprove'),
            $target->data['##ticket.validation.accepturl##'],
            __('Aprovar', 'mailaprove'),
            '#0f766e',
            $target->data['##ticket.validation.rejecturl##'],
            __('Recusar', 'mailaprove'),
            '#b91c1c'
        );
    }

    /**
     * Generate and populate solution accept/reject URLs.
     */
    private static function populateSolutionUrls(
        NotificationTargetTicket $target,
        int $ticketId,
        int $recipientUserId
    ): void {
        global $DB;

        // Find the most recent solution for this ticket
        $iterator = $DB->request([
            'FROM'  => 'glpi_itilsolutions',
            'WHERE' => [
                'items_id' => $ticketId,
                'itemtype' => 'Ticket',
                'status'   => CommonITILValidation::WAITING,
            ],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => 1,
        ]);

        if (count($iterator) === 0) {
            self::clearSolutionTags($target);
            return;
        }

        $solution = $iterator->current();
        $solutionId = (int)$solution['id'];

        $userId = $recipientUserId;
        if (!PublicAction::isTicketRequester($ticketId, $userId)) {
            self::clearSolutionTags($target);
            return;
        }

        // Generate accept token
        $acceptToken = Token::generateToken(
            Token::ACTION_SOLUTION_APPROVE,
            $ticketId,
            $solutionId,
            $userId
        );
        $target->data['##ticket.solution.accepturl##'] = $acceptToken
            ? Token::buildActionUrl($acceptToken, Token::ACTION_SOLUTION_APPROVE)
            : '';

        // Generate reject token
        $rejectToken = Token::generateToken(
            Token::ACTION_SOLUTION_REJECT,
            $ticketId,
            $solutionId,
            $userId
        );
        $target->data['##ticket.solution.rejecturl##'] = $rejectToken
            ? Token::buildActionUrl($rejectToken, Token::ACTION_SOLUTION_REJECT)
            : '';

        $target->data['##ticket.solution.buttons##'] = self::renderDualButtonBlock(
            $target,
            'template_solution',
            __('Uma solução foi registrada. Confirme se ela resolveu sua solicitação.', 'mailaprove'),
            $target->data['##ticket.solution.accepturl##'],
            __('Aceitar solução', 'mailaprove'),
            '#2563eb',
            $target->data['##ticket.solution.rejecturl##'],
            __('Recusar solução', 'mailaprove'),
            '#b45309'
        );
    }

    /**
     * Generate and populate satisfaction survey URL.
     */
    private static function populateSatisfactionUrls(
        NotificationTargetTicket $target,
        int $ticketId,
        int $recipientUserId
    ): void {
        global $DB;

        // Find satisfaction record for this ticket
        $iterator = $DB->request([
            'FROM'  => 'glpi_ticketsatisfactions',
            'WHERE' => [
                'tickets_id'      => $ticketId,
                'date_answered'   => null,
            ],
            'LIMIT' => 1,
        ]);

        if (count($iterator) === 0) {
            self::clearSatisfactionTags($target);
            return;
        }

        $satisfaction = $iterator->current();
        $satisfactionId = (int)$satisfaction['id'];

        $userId = $recipientUserId;
        if (!PublicAction::isTicketRequester($ticketId, $userId)) {
            self::clearSatisfactionTags($target);
            return;
        }

        $token = Token::generateToken(
            Token::ACTION_SATISFACTION,
            $ticketId,
            $satisfactionId,
            $userId
        );
        $target->data['##ticket.satisfaction.url##'] = $token
            ? Token::buildActionUrl($token, Token::ACTION_SATISFACTION)
            : '';

        $target->data['##ticket.satisfaction.button##'] = self::renderSingleButtonBlock(
            $target,
            'template_satisfaction',
            __('Sua opinião ajuda a melhorar a experiência de atendimento.', 'mailaprove'),
            $target->data['##ticket.satisfaction.url##'],
            __('Responder pesquisa', 'mailaprove'),
            '#2563eb'
        );
    }

    private static function renderDualButtonBlock(
        NotificationTargetTicket $target,
        string $configKey,
        string $message,
        string $primaryUrl,
        string $primaryLabel,
        string $primaryColor,
        string $secondaryUrl,
        string $secondaryLabel,
        string $secondaryColor
    ): string {
        if ($primaryUrl === '' || $secondaryUrl === '') {
            return '';
        }

        $custom = self::renderCustomTemplate($configKey, $target, [
            'approve_url'     => $primaryUrl,
            'accept_url'      => $primaryUrl,
            'primary_url'     => $primaryUrl,
            'reject_url'      => $secondaryUrl,
            'secondary_url'   => $secondaryUrl,
            'approve_label'   => $primaryLabel,
            'accept_label'    => $primaryLabel,
            'primary_label'   => $primaryLabel,
            'reject_label'    => $secondaryLabel,
            'secondary_label' => $secondaryLabel,
        ]);
        if ($custom !== '') {
            return $custom;
        }

        return self::renderButtonShell(
            $message,
            self::renderButton($primaryUrl, $primaryLabel, $primaryColor)
            . ' '
            . self::renderButton($secondaryUrl, $secondaryLabel, $secondaryColor)
        );
    }

    private static function clearValidationTags(NotificationTargetTicket $target): void
    {
        $target->data['##ticket.validation.accepturl##'] = '';
        $target->data['##ticket.validation.rejecturl##'] = '';
        $target->data['##ticket.validation.buttons##'] = '';
    }

    private static function clearSolutionTags(NotificationTargetTicket $target): void
    {
        $target->data['##ticket.solution.accepturl##'] = '';
        $target->data['##ticket.solution.rejecturl##'] = '';
        $target->data['##ticket.solution.buttons##'] = '';
    }

    private static function clearSatisfactionTags(NotificationTargetTicket $target): void
    {
        $target->data['##ticket.satisfaction.url##'] = '';
        $target->data['##ticket.satisfaction.button##'] = '';
    }

    private static function renderSingleButtonBlock(
        NotificationTargetTicket $target,
        string $configKey,
        string $message,
        string $url,
        string $label,
        string $color
    ): string
    {
        if ($url === '') {
            return '';
        }

        $custom = self::renderCustomTemplate($configKey, $target, [
            'survey_url'     => $url,
            'url'            => $url,
            'primary_url'    => $url,
            'survey_label'   => $label,
            'label'          => $label,
            'primary_label'  => $label,
        ]);
        if ($custom !== '') {
            return $custom;
        }

        return self::renderButtonShell($message, self::renderButton($url, $label, $color));
    }

    private static function renderCustomTemplate(string $configKey, NotificationTargetTicket $target, array $values): string
    {
        $config = Config::getConfig();
        $template = trim((string) ($config[$configKey] ?? ''));
        if ($template === '') {
            return '';
        }

        $ticket = $target->obj ?? null;
        $values += [
            'ticket_id'    => $ticket instanceof Ticket ? (string) ($ticket->fields['id'] ?? '') : '',
            'ticket_name'  => $ticket instanceof Ticket ? (string) ($ticket->fields['name'] ?? '') : '',
        ];

        $replace = [];
        foreach ($values as $key => $value) {
            $replace['{{' . $key . '}}'] = (string) $value;
        }

        return strtr($template, $replace);
    }

    private static function renderButtonShell(string $message, string $buttons): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; margin:18px 0;">'
            . '<tr><td style="padding:16px; border:1px solid #d9e1ec; border-radius:8px; background:#f8fafc;">'
            . '<p style="margin:0 0 12px; color:#344054; font-size:14px;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . $buttons
            . '</td></tr></table>';
    }

    private static function renderButton(string $url, string $label, string $color): string
    {
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block; padding:10px 16px; margin:0 8px 8px 0; border-radius:6px; background:'
            . htmlspecialchars($color, ENT_QUOTES, 'UTF-8')
            . '; color:#ffffff; text-decoration:none; font-weight:700;">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</a>';
    }
}
