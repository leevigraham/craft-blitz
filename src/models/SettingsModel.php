<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\models;

use craft\base\Model;
use putyourlightson\blitz\drivers\FileDriver;

class SettingsModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool
     */
    public $cachingEnabled = false;

    /**
     * @var bool
     */
    public $warmCacheAutomatically = true;

    /**
     * @var int
     */
    public $queryStringCaching = 0;

    /**
     * @var string
     */
    public $cacheFolderPath = 'cache/blitz';

    /**
     * @var int
     */
    public $concurrency = 5;

    /**
     * @var mixed
     */
    public $driverType = FileDriver::class;

    /**
     * @var mixed
     */
    public $driverSettings;

    /**
     * @var mixed
     */
    public $includedUriPatterns = [];

    /**
     * @var mixed
     */
    public $excludedUriPatterns = [];

    /**
     * @var bool
     */
    public $cacheElements = true;

    /**
     * @var bool
     */
    public $cacheElementQueries = true;

    /**
     * @var string[]
     */
    public $nonCacheableElementTypes = [
        'craft\elements\GlobalSet',
        'craft\elements\MatrixBlock',
    ];

    /**
     * @var bool
     */
    public $sendPoweredByHeader = true;

    /**
     * @var bool
     */
    public $warmCacheAutomaticallyForGlobals = true;

    // Public Methods
    // =========================================================================

    /**
     * @return array
     */
    public function rules(): array
    {
        return [
            [['concurrency'], 'integer', 'min' => 1],
        ];
    }

}
