<?php

/**
 * @copyright Copyright (c) John Henry Donovan
 */

namespace johnhenry\accessibilityaudit\services;

use yii\base\InvalidConfigException;

/**
 * Registers the plugin's service components and hands each one back typed.
 *
 * Yii's Component::get() is signed `?object`, so every accessor narrows it with
 * an assert: without that a caller gets no completion and static analysis has
 * nothing to check a call against.
 *
 * @property AuditService $audit
 * @property ContentScanner $content
 * @property HeadlessScanner $headless
 * @property PotentialScanner $potential
 * @property AssetScanner $assets
 * @property ReportService $report
 * @property VpatService $vpat
 * @property OrganisationService $organisation
 * @property StatementService $statement
 * @property ReadabilityService $readability
 * @property NotificationService $notifications
 * @property VerdictService $verdicts
 * @property OverlayService $overlay
 *
 * @author John Henry Donovan <info@johnhenry.ie>
 * @since 1.0.0
 */
trait ServicesTrait
{
    // Public Methods
    // =========================================================================

    /**
     * The plugin's service components.
     *
     * @return array{components: array<string, class-string>} The component map.
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public static function config(): array
    {
        return [
            'components' => [
                'audit' => AuditService::class,
                'content' => ContentScanner::class,
                'headless' => HeadlessScanner::class,
                'potential' => PotentialScanner::class,
                'assets' => AssetScanner::class,
                'report' => ReportService::class,
                'vpat' => VpatService::class,
                'organisation' => OrganisationService::class,
                'statement' => StatementService::class,
                'readability' => ReadabilityService::class,
                'notifications' => NotificationService::class,
                'verdicts' => VerdictService::class,
                'overlay' => OverlayService::class,
            ],
        ];
    }

    /**
     * The AuditService component.
     *
     * @return AuditService The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getAudit(): AuditService
    {
        $component = $this->get('audit');
        assert($component instanceof AuditService);
        return $component;
    }

    /**
     * The ContentScanner component.
     *
     * @return ContentScanner The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getContent(): ContentScanner
    {
        $component = $this->get('content');
        assert($component instanceof ContentScanner);
        return $component;
    }

    /**
     * The PotentialScanner component.
     *
     * @return PotentialScanner The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getPotential(): PotentialScanner
    {
        $component = $this->get('potential');
        assert($component instanceof PotentialScanner);
        return $component;
    }

    /**
     * The AssetScanner component.
     *
     * @return AssetScanner The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getAssets(): AssetScanner
    {
        $component = $this->get('assets');
        assert($component instanceof AssetScanner);
        return $component;
    }

    /**
     * The ReportService component.
     *
     * @return ReportService The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getReport(): ReportService
    {
        $component = $this->get('report');
        assert($component instanceof ReportService);
        return $component;
    }

    /**
     * The VpatService component.
     *
     * @return VpatService The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getVpat(): VpatService
    {
        $component = $this->get('vpat');
        assert($component instanceof VpatService);
        return $component;
    }

    /**
     * The OrganisationService component.
     *
     * @return OrganisationService The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getOrganisation(): OrganisationService
    {
        $component = $this->get('organisation');
        assert($component instanceof OrganisationService);
        return $component;
    }

    /**
     * The StatementService component.
     *
     * @return StatementService The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getStatement(): StatementService
    {
        $component = $this->get('statement');
        assert($component instanceof StatementService);
        return $component;
    }

    /**
     * The ReadabilityService component.
     *
     * @return ReadabilityService The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getReadability(): ReadabilityService
    {
        $component = $this->get('readability');
        assert($component instanceof ReadabilityService);
        return $component;
    }

    /**
     * The NotificationService component.
     *
     * @return NotificationService The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getNotifications(): NotificationService
    {
        $component = $this->get('notifications');
        assert($component instanceof NotificationService);
        return $component;
    }

    /**
     * The OverlayService component.
     *
     * @return OverlayService The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getOverlay(): OverlayService
    {
        $component = $this->get('overlay');
        assert($component instanceof OverlayService);
        return $component;
    }

    /**
     * The HeadlessScanner component.
     *
     * @return HeadlessScanner The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getHeadless(): HeadlessScanner
    {
        $component = $this->get('headless');
        assert($component instanceof HeadlessScanner);
        return $component;
    }

    /**
     * The VerdictService component.
     *
     * @return VerdictService The service.
     * @throws InvalidConfigException
     *
     * @author John Henry Donovan <info@johnhenry.ie>
     * @since 1.0.0
     */
    public function getVerdicts(): VerdictService
    {
        $component = $this->get('verdicts');
        assert($component instanceof VerdictService);
        return $component;
    }
}
