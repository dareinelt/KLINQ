<?php

declare(strict_types=1);

use App\Controllers\Helpdesk\HelpdeskAdminController;
use App\Controllers\Helpdesk\HelpdeskApiController;
use App\Controllers\Helpdesk\HelpdeskDashboardController;
use App\Controllers\Helpdesk\HelpdeskReportController;
use App\Controllers\Helpdesk\KnowledgeBaseController;
use App\Controllers\Helpdesk\PortalController;
use App\Controllers\Helpdesk\QuickReportController;
use App\Controllers\Helpdesk\TicketAttachmentController;
use App\Controllers\Helpdesk\TicketController;
use App\Core\Config;
use App\Core\Container;
use App\Core\Logger;
use App\Core\View;
use App\Repositories\AssetRepository;
use App\Repositories\CostCenterRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\KnowledgeBaseRepository;
use App\Repositories\LocationRepository;
use App\Repositories\TicketAttachmentRepository;
use App\Repositories\TicketCategoryRepository;
use App\Repositories\TicketCommentRepository;
use App\Repositories\TicketMasterDataRepository;
use App\Repositories\TicketRelationRepository;
use App\Repositories\TicketReportRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TicketRuleRepository;
use App\Repositories\TicketSlaRepository;
use App\Repositories\TicketTagRepository;
use App\Repositories\TicketTemplateRepository;
use App\Repositories\TicketWorklogRepository;
use App\Repositories\UserRepository;
use App\Security\CurrentUser;
use App\Security\Permissions;
use App\Security\WindowsIdentity;
use App\Services\Ad\AdUserLookupService;
use App\Services\AuditLogService;
use App\Services\DocumentService;
use App\Services\Helpdesk\HelpdeskAdminService;
use App\Services\Helpdesk\HelpdeskSchedulerService;
use App\Services\Helpdesk\KnowledgeBaseService;
use App\Services\Helpdesk\ReporterIdentityService;
use App\Services\Helpdesk\Mail\FileMailboxClient;
use App\Services\Helpdesk\Mail\ImapMailboxClient;
use App\Services\Helpdesk\Mail\MailboxClientInterface;
use App\Services\Helpdesk\Mail\MimeMessageParser;
use App\Services\Helpdesk\TicketMailIngestionService;
use App\Services\Helpdesk\TicketMergeService;
use App\Services\Helpdesk\TicketNotificationService;
use App\Services\Helpdesk\TicketNumberService;
use App\Services\Helpdesk\TicketReportService;
use App\Services\Helpdesk\TicketRuleEvaluator;
use App\Services\Helpdesk\TicketRuleService;
use App\Services\Helpdesk\TicketService;
use App\Services\Helpdesk\TicketSlaService;
use App\Services\Helpdesk\TicketWorkflowService;
use App\Services\MailClient;

return static function (Container $c): void {
    // Repositories
    foreach ([
        TicketRepository::class, TicketMasterDataRepository::class, TicketCategoryRepository::class, TicketSlaRepository::class,
        TicketTagRepository::class, TicketCommentRepository::class, TicketAttachmentRepository::class, TicketWorklogRepository::class,
        TicketRelationRepository::class, TicketTemplateRepository::class, TicketRuleRepository::class, TicketReportRepository::class,
        KnowledgeBaseRepository::class,
    ] as $repository) {
        $c->singleton($repository, static fn (Container $c): object => new $repository($c->get(PDO::class)));
    }

    // Fachlogik ohne Abhängigkeiten
    $c->singleton(TicketWorkflowService::class, static fn (): TicketWorkflowService => new TicketWorkflowService());
    $c->singleton(TicketRuleEvaluator::class, static fn (): TicketRuleEvaluator => new TicketRuleEvaluator());
    $c->singleton(TicketSlaService::class, static function (Container $c): TicketSlaService {
        $config = $c->get(Config::class);

        return new TicketSlaService(
            (string) $config->get('app.timezone', 'Europe/Berlin'),
            (int) $config->get('helpdesk.sla_warning_percent', 75),
            (int) $config->get('helpdesk.sla_escalation_percent', 90)
        );
    });
    $c->singleton(TicketNumberService::class, static fn (Container $c): TicketNumberService => new TicketNumberService($c->get(TicketRepository::class), $c->get(Config::class)));

    $c->singleton(TicketNotificationService::class, static fn (Container $c): TicketNotificationService => new TicketNotificationService(
        $c->get(MailClient::class),
        $c->get(TicketRuleRepository::class),
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(UserRepository::class),
        $c->get(Config::class),
        $c->get(Logger::class)
    ));
    $c->singleton(TicketRuleService::class, static fn (Container $c): TicketRuleService => new TicketRuleService(
        $c->get(TicketRuleRepository::class),
        $c->get(TicketRuleEvaluator::class),
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(UserRepository::class),
        $c->get(TicketNotificationService::class),
        $c->get(Logger::class)
    ));
    $c->singleton(TicketService::class, static fn (Container $c): TicketService => new TicketService(
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketCommentRepository::class),
        $c->get(TicketAttachmentRepository::class),
        $c->get(TicketWorklogRepository::class),
        $c->get(TicketRelationRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(EmployeeRepository::class),
        $c->get(UserRepository::class),
        $c->get(AssetRepository::class),
        $c->get(TicketNumberService::class),
        $c->get(TicketWorkflowService::class),
        $c->get(TicketSlaService::class),
        $c->get(TicketRuleService::class),
        $c->get(TicketNotificationService::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class),
        $c->get(Config::class)
    ));
    $c->singleton(TicketMergeService::class, static fn (Container $c): TicketMergeService => new TicketMergeService(
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCommentRepository::class),
        $c->get(TicketAttachmentRepository::class),
        $c->get(TicketWorklogRepository::class),
        $c->get(TicketRelationRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketWorkflowService::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(KnowledgeBaseService::class, static fn (Container $c): KnowledgeBaseService => new KnowledgeBaseService(
        $c->get(KnowledgeBaseRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketRepository::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(HelpdeskAdminService::class, static fn (Container $c): HelpdeskAdminService => new HelpdeskAdminService(
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(TicketRuleRepository::class),
        $c->get(TicketRuleEvaluator::class),
        $c->get(AuditLogService::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(TicketReportService::class, static fn (Container $c): TicketReportService => new TicketReportService(
        $c->get(TicketReportRepository::class),
        $c->get(TicketRepository::class),
        $c->get(TicketRelationRepository::class),
        $c->get(CurrentUser::class)
    ));
    $c->singleton(HelpdeskSchedulerService::class, static fn (Container $c): HelpdeskSchedulerService => new HelpdeskSchedulerService(
        $c->get(TicketRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketSlaService::class),
        $c->get(TicketNotificationService::class),
        $c->get(TicketRuleService::class),
        $c->get(Config::class),
        $c->get(Logger::class)
    ));
    $c->singleton(MimeMessageParser::class, static fn (): MimeMessageParser => new MimeMessageParser());
    // Postfach-Client je Konfiguration (imap = Socket-IMAP-Client, file = .eml-Verzeichnis)
    $c->singleton(MailboxClientInterface::class, static function (Container $c): MailboxClientInterface {
        $config = $c->get(Config::class);
        $mail = (array) $config->get('helpdesk.mail', []);
        if (($mail['driver'] ?? 'imap') === 'file') {
            return new FileMailboxClient((string) ($mail['file_path'] ?? (dirname(__DIR__, 3) . '/storage/mail-inbox')));
        }

        return new ImapMailboxClient($mail);
    });
    $c->singleton(TicketMailIngestionService::class, static fn (Container $c): TicketMailIngestionService => new TicketMailIngestionService(
        $c->get(Config::class),
        $c->get(Logger::class),
        $c->get(CurrentUser::class),
        $c->get(Permissions::class),
        $c->get(TicketRepository::class),
        $c->get(TicketRuleRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketAttachmentRepository::class),
        $c->get(EmployeeRepository::class),
        $c->get(UserRepository::class),
        $c->get(TicketService::class),
        $c->get(DocumentService::class),
        $c->get(MimeMessageParser::class),
        static fn (): MailboxClientInterface => $c->get(MailboxClientInterface::class)
    ));

    // Controller
    $c->singleton(HelpdeskDashboardController::class, static fn (Container $c): HelpdeskDashboardController => new HelpdeskDashboardController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketReportService::class),
        $c->get(TicketRepository::class)
    ));
    $c->singleton(TicketController::class, static fn (Container $c): TicketController => new TicketController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketService::class),
        $c->get(TicketMergeService::class),
        $c->get(TicketReportService::class),
        $c->get(KnowledgeBaseService::class),
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(TicketWorklogRepository::class),
        $c->get(TicketRelationRepository::class),
        $c->get(TicketRuleRepository::class),
        $c->get(TicketSlaService::class),
        $c->get(EmployeeRepository::class),
        $c->get(AssetRepository::class),
        $c->get(LocationRepository::class),
        $c->get(CostCenterRepository::class),
        $c->get(Config::class)
    ));
    $c->singleton(TicketAttachmentController::class, static fn (Container $c): TicketAttachmentController => new TicketAttachmentController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketService::class),
        $c->get(TicketAttachmentRepository::class),
        $c->get(TicketRepository::class),
        $c->get(DocumentService::class),
        $c->get(AuditLogService::class)
    ));
    $c->singleton(PortalController::class, static fn (Container $c): PortalController => new PortalController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketService::class),
        $c->get(KnowledgeBaseService::class),
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(KnowledgeBaseRepository::class),
        $c->get(TicketSlaService::class),
        $c->get(Config::class)
    ));
    $c->singleton(ReporterIdentityService::class, static fn (Container $c): ReporterIdentityService => new ReporterIdentityService(
        $c->get(Config::class),
        $c->get(CurrentUser::class),
        $c->get(UserRepository::class),
        $c->get(EmployeeRepository::class),
        $c->get(AdUserLookupService::class),
        $c->get(WindowsIdentity::class)
    ));
    $c->singleton(QuickReportController::class, static fn (Container $c): QuickReportController => new QuickReportController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketService::class),
        $c->get(ReporterIdentityService::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(UserRepository::class),
        $c->get(Permissions::class),
        $c->get(Config::class),
        $c->get(Logger::class)
    ));
    $c->singleton(KnowledgeBaseController::class, static fn (Container $c): KnowledgeBaseController => new KnowledgeBaseController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(KnowledgeBaseService::class),
        $c->get(KnowledgeBaseRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketService::class),
        $c->get(Config::class)
    ));
    $c->singleton(HelpdeskReportController::class, static fn (Container $c): HelpdeskReportController => new HelpdeskReportController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketReportService::class)
    ));
    $c->singleton(HelpdeskAdminController::class, static fn (Container $c): HelpdeskAdminController => new HelpdeskAdminController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(HelpdeskAdminService::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketSlaRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(TicketRuleRepository::class),
        $c->get(TicketRuleEvaluator::class),
        $c->get(TicketNotificationService::class),
        $c->get(TicketMailIngestionService::class),
        $c->get(Config::class)
    ));
    $c->singleton(HelpdeskApiController::class, static fn (Container $c): HelpdeskApiController => new HelpdeskApiController(
        $c->get(View::class),
        $c->get(CurrentUser::class),
        $c->get(TicketService::class),
        $c->get(TicketRepository::class),
        $c->get(TicketMasterDataRepository::class),
        $c->get(TicketCategoryRepository::class),
        $c->get(TicketTagRepository::class),
        $c->get(TicketTemplateRepository::class),
        $c->get(KnowledgeBaseService::class),
        $c->get(KnowledgeBaseRepository::class),
        $c->get(Config::class)
    ));
};
