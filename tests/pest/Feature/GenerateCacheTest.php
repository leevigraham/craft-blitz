<?php

/**
 * Tests the saving of cached values, element cache records and element query records.
 */

use craft\commerce\elements\Product;
use craft\db\Query;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use craft\helpers\App;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\mutex\Mutex;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\helpers\FieldHelper;
use putyourlightson\blitz\records\CacheRecord;
use putyourlightson\blitz\records\ElementCacheRecord;
use putyourlightson\blitz\records\ElementFieldCacheRecord;
use putyourlightson\blitz\records\ElementQueryAttributeRecord;
use putyourlightson\blitz\records\ElementQueryCacheRecord;
use putyourlightson\blitz\records\ElementQueryFieldRecord;
use putyourlightson\blitz\records\ElementQueryRecord;
use putyourlightson\blitz\records\ElementQuerySourceRecord;
use putyourlightson\blitz\records\IncludeRecord;
use putyourlightson\blitz\records\SsiIncludeCacheRecord;
use putyourlightson\campaign\elements\CampaignElement;
use putyourlightson\campaign\elements\MailingListElement;
use yii\db\Expression;

beforeEach(function() {
    Blitz::$plugin->settings->cachingEnabled = true;
    Blitz::$plugin->settings->outputComments = true;
    Blitz::$plugin->settings->excludedTrackedElementQueryParams = [];
    Blitz::$plugin->generateCache->options->outputComments = null;
    Blitz::$plugin->generateCache->reset();
    Blitz::$plugin->cacheStorage->deleteAll();
    Blitz::$plugin->flushCache->flushAll(true);

    $mutex = Mockery::mock(Mutex::class);
    $mutex->shouldReceive('acquire')->andReturn(true);
    $mutex->shouldReceive('release');
    Craft::$app->set('mutex', $mutex);
});

test('Cached value is saved with output comments', function() {
    $output = createOutput();
    $siteUri = createSiteUri();
    Blitz::$plugin->generateCache->save($output, $siteUri);

    expect(Blitz::$plugin->cacheStorage->get($siteUri))
        ->toContain($output, 'Cached by Blitz on');
});

test('Cached value is saved without output comments', function() {
    $output = createOutput();
    $siteUri = createSiteUri();
    Blitz::$plugin->generateCache->options->outputComments = false;
    Blitz::$plugin->generateCache->save($output, $siteUri);

    expect(Blitz::$plugin->cacheStorage->get($siteUri))
        ->toContain($output)
        ->not()->toContain('Cached by Blitz on');
});

test('Cached value is saved with output comments when file extension is ".html"', function() {
    $siteUri = createSiteUri(uri: 'page.html');
    Blitz::$plugin->generateCache->save(createOutput(), $siteUri);

    expect(Blitz::$plugin->cacheStorage->get($siteUri))
        ->toContain('Cached by Blitz on');
});

test('Cached value is saved without output comments when file extension is not `.html`', function() {
    $siteUri = createSiteUri(uri: 'page.json');
    Blitz::$plugin->generateCache->save(createOutput(), $siteUri);

    expect(Blitz::$plugin->cacheStorage->get($siteUri))
        ->not()->toContain('Cached by Blitz on');
});

test('Cache record with max URI length is saved', function() {
    $siteUri = createSiteUri(uri: StringHelper::randomString(Blitz::$plugin->settings->maxUriLength));
    Blitz::$plugin->generateCache->save(createOutput(), $siteUri);
    $count = CacheRecord::find()
        ->where($siteUri->toArray())
        ->count();

    expect($count)
        ->toEqual(1);
});

test('Cache record with max URI length exceeded throws exception', function() {
    $siteUri = createSiteUri(uri: StringHelper::randomString(Blitz::$plugin->settings->maxUriLength + 1));
    Blitz::$plugin->generateCache->save(createOutput(), $siteUri);
})->throws(Exception::class);

test('Element cache record is saved without custom fields', function() {
    $entry = createEntry();
    Blitz::$plugin->generateCache->addElement($entry);
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    expect(ElementCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id])
        ->and(ElementFieldCacheRecord::class)
        ->toHaveRecordCount(0, ['elementId' => $entry->id]);
});

test('Element cache record is saved with custom fields', function() {
    $entry = createEntry();
    Blitz::$plugin->generateCache->addElement($entry);
    $entry->plainText;
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    expect(ElementCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id])
        ->and(ElementFieldCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id]);
});

test('Element cache record is saved with custom fields with renamed handles', function() {
    $entry = createEntry();
    Blitz::$plugin->generateCache->addElement($entry);
    $entry->plainTextRenamed;
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    expect(ElementCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id])
        ->and(ElementFieldCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id]);
});

test('Element cache record is saved with eager-loaded custom fields', function() {
    createEntry();
    $entry = Entry::find()->with(['relatedTo'])->one();
    Blitz::$plugin->generateCache->addElement($entry);
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    expect(ElementCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id])
        ->and(ElementFieldCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id]);
});

test('Element cache record is saved with nested eager-loaded custom fields', function() {
    $childEntry = createEntryWithRelationship();
    $entry = createEntryWithRelationship([$childEntry]);
    Craft::$app->elements->eagerLoadElements(Entry::class, [$entry], ['relatedTo.relatedTo']);
    Blitz::$plugin->generateCache->addElement($entry);
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    expect(ElementCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id])
        ->toHaveRecordCount(1, ['elementId' => $childEntry->id])
        ->and(ElementFieldCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id])
        ->toHaveRecordCount(1, ['elementId' => $childEntry->id]);
});

test('Element cache record is saved with eager-loaded matrix fields', function() {
    $childEntry = createEntryWithRelationship();
    $entry = createEntry(customFields: [
        'matrix' => [
            [
                'type' => 'test',
                'fields' => [
                    'relatedTo' => [$childEntry->id],
                ],
            ],
        ],
    ]);
    Craft::$app->elements->eagerLoadElements(Entry::class, [$entry], ['matrix.test:relatedTo.relatedTo']);
    Blitz::$plugin->generateCache->addElement($entry);
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    expect(ElementCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id])
        ->toHaveRecordCount(1, ['elementId' => $childEntry->id])
        ->and(ElementFieldCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id])
        ->toHaveRecordCount(1, ['elementId' => $childEntry->id]);
});

test('Element cache record is saved with eager-loaded custom fields in variable', function() {
    $entry = createEntryWithRelationship();
    Craft::$app->elements->eagerLoadElements(Entry::class, [$entry], ['relatedTo']);
    Blitz::$plugin->generateCache->addElement($entry);
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    expect(ElementCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id])
        ->and(ElementFieldCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id]);
});

test('Element cache record is saved for preloaded single', function() {
    Craft::$app->config->general->preloadSingles = true;
    Craft::$app->view->renderString('{{ single.title }}');
    $entry = Entry::find()->section(App::env('TEST_SINGLE_SECTION_HANDLE'))->one();
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    expect(ElementCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id]);
});

test('Element cache record is saved with eager-loaded custom fields for preloaded single', function() {
    Craft::$app->config->general->preloadSingles = true;
    $singleSectionHandle = App::env('TEST_SINGLE_SECTION_HANDLE');
    $entry = Entry::find()->section($singleSectionHandle)->one();
    Craft::$app->getView()->setTemplatesPath(Craft::getAlias('@putyourlightson/blitz/test/templates'));
    Craft::$app->view->renderTemplate('eager.twig');

    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    expect(ElementCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id])
        ->and(ElementFieldCacheRecord::class)
        ->toHaveRecordCount(1, ['elementId' => $entry->id]);
});

test('Element cache records are saved with all statuses for relation field queries', function() {
    $enabledEntry = createEntry();
    $disabledEntry = createEntry(enabled: false);
    $entry = createEntryWithRelationship([$enabledEntry, $disabledEntry]);

    // The entry must be fetched from the DB for the test to work.
    $entry = Entry::find()->id($entry->id)->one();
    $entry->relatedTo->all();

    expect(Blitz::$plugin->generateCache->generateData->getElementIds())
        ->toContain($enabledEntry->id, $disabledEntry->id);
});

test('Element cache records are saved with all statuses for eager-loaded relation field queries', function() {
    $enabledEntry = createEntry();
    $disabledEntry = createEntry(enabled: false);
    $entry = createEntryWithRelationship([$enabledEntry, $disabledEntry]);

    // The entry must be fetched from the DB for the test to work.
    Entry::find()->id($entry->id)->with('relatedTo')->one();

    expect(Blitz::$plugin->generateCache->generateData->getElementIds())
        ->toContain($enabledEntry->id, $disabledEntry->id);
});

test('Element cache records are saved irrespective of the criteria for eager-loaded relation field queries', function() {
    $qualifyingEntry = createEntry(customFields: ['plainText' => '1']);
    $nonQualifyingEntry = createEntry(customFields: ['plainText' => '2']);
    $entry = createEntryWithRelationship([$qualifyingEntry, $nonQualifyingEntry]);

    // The entry must be fetched from the DB for the test to work.
    Entry::find()->id($entry->id)
        ->with([
            ['relatedTo', ['plainText' => '1']],
        ])
        ->one();

    expect(Blitz::$plugin->generateCache->generateData->getElementIds())
        ->toContain($qualifyingEntry->id, $nonQualifyingEntry->id);
});

test('Element cache records are saved for archived and deleted elements with eager-loaded relation field queries', function() {
    $archivedEntry = createEntry(params: ['archived' => true]);
    $deletedEntry = createEntry(params: ['dateDeleted' => new DateTime()]);
    $entry = createEntryWithRelationship([$archivedEntry, $deletedEntry]);

    // The entry must be fetched from the DB for the test to work.
    Entry::find()->id($entry->id)->with('relatedTo')->one();

    expect(Blitz::$plugin->generateCache->generateData->getElementIds())
        ->toContain($archivedEntry->id, $deletedEntry->id);
});

test('Element query records without specific identifiers are saved', function() {
    $elementQuerySets = [
        [
            Entry::find(),
            Entry::find()->limit(null),
            Entry::find()->offset(null),
        ],
        [
            Entry::find()->id('not 1'),
        ],
        [
            Entry::find()->id(['not', 1]),
            Entry::find()->id(['not', '1']),
        ],
        [
            Entry::find()->slug('not slug'),
        ],
        [
            Entry::find()->slug(['not', 'slug']),
        ],
        [
            Entry::find()->sectionId(1),
            Entry::find()->sectionId('1'),
            Entry::find()->sectionId([1]),
            Entry::find()->sectionId(['1']),
        ],
        [
            Entry::find()->sectionId('1, 2'),
            Entry::find()->sectionId([1, 2]),
        ],
    ];

    foreach ($elementQuerySets as $elementQuerySet) {
        foreach ($elementQuerySet as $elementQuery) {
            Blitz::$plugin->generateCache->addElementQuery($elementQuery);
        }
    }

    expect(ElementQueryRecord::class)
        ->toHaveRecordCount(count($elementQuerySets));
});

test('Element query records with specific identifiers are not saved', function() {
    $elementQueries = [
        Entry::find()->id(1),
        Entry::find()->id('1'),
        Entry::find()->id('1, 2, 3'),
        Entry::find()->id([1, 2, 3]),
        Entry::find()->id(['1', '2', '3']),
        Entry::find()->slug('slug'),
        Entry::find()->slug(['slug']),
        Entry::find()->slug([null, 'slug']),
        Entry::find()->orderBy('RAND()'),
        Entry::find()->orderBy('Rand(123)'),
    ];

    foreach ($elementQueries as $elementQuery) {
        Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    }

    expect(ElementQueryRecord::class)
        ->toHaveRecordCount(0);
});

test('Element query record with select is saved', function() {
    $elementQuery = Entry::find()->select(['title']);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    expect(ElementQueryRecord::class)
        ->toHaveRecordCount(1);
});

test('Element query record with join is saved', function() {
    $elementQuery = Entry::find()->innerJoin('{{%users}}');
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    expect(ElementQueryRecord::class)
        ->toHaveRecordCount(1);
});

test('Element query record with relation field is not saved', function() {
    $entry = createEntryWithRelationship();
    ElementQueryRecord::deleteAll();
    $elementQuery = $entry->relatedTo;
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    expect(ElementQueryRecord::class)
        ->toHaveRecordCount(0);
});

test('Element query record with related to param is saved', function() {
    $elementQuery = Entry::find()->relatedTo(1);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    expect(ElementQueryRecord::class)
        ->toHaveRecordCount(1);
});

test('Element query record with query param is saved without the param', function() {
    $elementQuery = Entry::find();
    $elementQuery->query = new Query();
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    /** @var ElementQueryRecord $record */
    $record = ElementQueryRecord::find()->one();
    $params = Json::decodeIfJson($record->params);

    expect($params)
        ->not()->toHaveKey('query');
});

test('Element query record with expression is not saved', function() {
    $elementQuery = Entry::find()->title(new Expression(1));
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    expect(ElementQueryRecord::class)
        ->toHaveRecordCount(0);
});

test('Element query record for entry in single sections is not saved', function() {
    $elementQuery = Entry::find()->section([App::env('TEST_SINGLE_SECTION_HANDLE')]);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    expect(ElementQueryRecord::class)
        ->toHaveRecordCount(0);
});

test('Element query record with option field data is converted to value', function() {
    $optionFieldData = new OptionData('One', 1, true);
    $elementQuery = Entry::find()->dropdown($optionFieldData);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    /** @var ElementQueryRecord $record */
    $record = ElementQueryRecord::find()->one();
    $params = Json::decodeIfJson($record->params);

    expect($params['dropdown'] ?? null)
        ->toEqual(1);
});

test('Element query record with multi options field data is converted to array of values', function() {
    $optionFieldData = new MultiOptionsFieldData();
    $optionFieldData->setOptions([
        new OptionData('One', 1, true),
        new OptionData('Two', 2, false),
    ]);
    $elementQuery = Entry::find()->multiSelect($optionFieldData);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    /** @var ElementQueryRecord $record */
    $record = ElementQueryRecord::find()->one();
    $params = Json::decodeIfJson($record->params);

    expect($params['multiSelect'] ?? null)
        ->toEqual([1, 2]);
});

test('Element query record keeps limit and offset params', function() {
    $elementQuery = Entry::find()->limit(10)->offset(5);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    /** @var ElementQueryRecord $record */
    $record = ElementQueryRecord::find()->one();
    $params = Json::decodeIfJson($record->params);

    expect($params['limit'] ?? null)
        ->toBe(10)
        ->and($params['offset'] ?? null)
        ->toBe(5);
});

test('Element query record keeps order by if a limit param is present', function() {
    $elementQuery = Entry::find()->orderBy(['title' => SORT_ASC])->limit(10);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    /** @var ElementQueryRecord $record */
    $record = ElementQueryRecord::find()->one();
    $params = Json::decodeIfJson($record->params);

    expect($params['orderBy'] ?? null)
        ->toBe(['title' => SORT_ASC]);
});

test('Element query record keeps order by if an offset param is present', function() {
    $elementQuery = Entry::find()->orderBy(['title' => SORT_ASC])->offset(10);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    /** @var ElementQueryRecord $record */
    $record = ElementQueryRecord::find()->one();
    $params = Json::decodeIfJson($record->params);

    expect($params['orderBy'] ?? null)
        ->toBe(['title' => SORT_ASC]);
});

test('Element query record does not keep order by if no limit or offset param is present', function() {
    $elementQuery = Entry::find()->orderBy(['title' => SORT_ASC]);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);

    /** @var ElementQueryRecord $record */
    $record = ElementQueryRecord::find()->one();
    $params = Json::decodeIfJson($record->params);

    expect($params)
        ->not()->toHaveKey('orderBy');
});

test('Element query record respects excluded tracked element query params', function() {
    Blitz::$plugin->settings->excludedTrackedElementQueryParams = ['limit'];
    Blitz::$plugin->generateCache->addElementQuery(Entry::find()->limit(10));

    /** @var ElementQueryRecord $record */
    $record = ElementQueryRecord::find()->one();
    $params = Json::decodeIfJson($record->params);

    expect($params)
        ->not()->toHaveKey('limit');
});

test('Element query cache records are saved', function() {
    $elementQuery = Entry::find();
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri(uri: 'page-new'));

    expect(ElementQueryCacheRecord::class)
        ->toHaveRecordCount(2);
});

test('Element query cache records with matching params and a higher limit and offset sum are the only ones saved', function(array $params1, array $params2) {
    $elementQuery = new EntryQuery(Entry::class, $params1);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    $elementQuery = new EntryQuery(Entry::class, $params2);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    /** @var ElementQueryRecord $elementQueryRecords [] */
    $elementQueryRecords = ElementQueryRecord::find()
        ->innerJoinWith('elementQueryCaches')
        ->all();

    expect($elementQueryRecords)
        ->toHaveCount(1)
        ->and(Json::decodeIfJson($elementQueryRecords[0]->params))
        ->toBe($params2);
})->with([
    [['limit' => 1], ['limit' => 10]],
    [['limit' => 1, 'offset' => 1], ['limit' => 10, 'offset' => 10]],
    [['limit' => 1, 'offset' => 10], ['limit' => 10, 'offset' => 1]],
    [['limit' => 10, 'offset' => 1], ['limit' => 1, 'offset' => 20]],
]);

test('Element query source records with specific source identifiers are saved', function() {
    $elementQueries = [
        Entry::find(),
        Entry::find()->sectionId(1),
        Entry::find()->sectionId([1, 2, 3]),
        Product::find()->typeId(4),
        CampaignElement::find()->campaignTypeId(5),
        MailingListElement::find()->mailingListTypeId(6),
    ];

    foreach ($elementQueries as $elementQuery) {
        Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    }

    $count = ElementQueryRecord::find()
        ->joinWith('elementQuerySources', false)
        ->where(['not', ['sourceId' => null]])
        ->count();

    expect($count)
        ->toEqual(7);

    $sourceIds = ElementQuerySourceRecord::find()
        ->select(['sourceId'])
        ->column();

    expect($sourceIds)
        ->toEqual([1, 1, 2, 3, 4, 5, 6]);
});

test('Element query source records without specific source identifiers are not saved', function() {
    $elementQueries = [
        Entry::find()->sectionId('not 1'),
        Entry::find()->sectionId('> 1'),
        Entry::find()->sectionId(['not', 1]),
        Entry::find()->sectionId(['not', '1']),
    ];

    foreach ($elementQueries as $elementQuery) {
        Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    }

    expect(ElementQuerySourceRecord::class)
        ->toHaveRecordCount(0);
});

test('Entry query source records are saved when only a structure ID is set', function() {
    $section = Craft::$app->getEntries()->getSectionByHandle(App::env('TEST_STRUCTURE_SECTION_HANDLE'));
    Blitz::$plugin->generateCache->addElementQuery(Entry::find()->structureId($section->structureId));

    expect(ElementQuerySourceRecord::class)
        ->toHaveRecordCount(1)
        ->and(ElementQuerySourceRecord::find()->one()->sourceId)
        ->toBe($section->id);
});

test('Element query attribute records are saved', function() {
    $elementQuery = Entry::find()->title('x');
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    $attributes = ElementQueryAttributeRecord::find()
        ->select(['attribute'])
        ->column();

    expect($attributes)
        ->toEqual(['postDate', 'title']);
});

test('Element query attribute records are saved with order by', function() {
    $elementQuery = Entry::find()->orderBy(['title' => SORT_ASC]);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    $attributes = ElementQueryAttributeRecord::find()
        ->select(['attribute'])
        ->column();

    expect($attributes)
        ->toEqual(['title']);
});

test('Element query attribute records are saved with order by parts array', function() {
    $elementQuery = Entry::find()->orderBy(['entries.title' => SORT_ASC]);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    $attributes = ElementQueryAttributeRecord::find()
        ->select(['attribute'])
        ->column();

    expect($attributes)
        ->toEqual(['title']);
});

test('Element query attribute records are saved with before', function() {
    $elementQuery = Entry::find()
        ->before('1999-12-31')
        ->orderBy(['title' => SORT_ASC]);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    $attributes = ElementQueryAttributeRecord::find()
        ->select(['attribute'])
        ->column();

    expect($attributes)
        ->toEqual(['postDate', 'title']);
});

test('Element query field records are saved with order by', function() {
    $elementQuery = Entry::find()->orderBy('plainText asc');
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    $fieldInstanceUids = ElementQueryFieldRecord::find()
        ->select(['fieldInstanceUid'])
        ->column();
    $fieldInstanceUidsForElementQuery = FieldHelper::getFieldInstanceUidsForElementQuery($elementQuery, ['plainText']);

    expect($fieldInstanceUids)
        ->toHaveCount(count($fieldInstanceUidsForElementQuery))
        ->toContain(...$fieldInstanceUidsForElementQuery);
});

test('Element query field records are saved with order by array', function() {
    $elementQuery = Entry::find()->orderBy(['plainText' => SORT_ASC]);
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    $fieldInstanceUids = ElementQueryFieldRecord::find()
        ->select(['fieldInstanceUid'])
        ->column();
    $fieldInstanceUidsForElementQuery = FieldHelper::getFieldInstanceUidsForElementQuery($elementQuery, ['plainText']);

    expect($fieldInstanceUids)
        ->toHaveCount(count($fieldInstanceUidsForElementQuery))
        ->toContain(...$fieldInstanceUidsForElementQuery);
});

test('Element query field records with renamed handles are saved with order by', function() {
    $elementQuery = Entry::find()->orderBy('plainTextRenamed');
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    $fieldInstanceUids = ElementQueryFieldRecord::find()
        ->select(['fieldInstanceUid'])
        ->column();
    $fieldInstanceUidsForElementQuery = FieldHelper::getFieldInstanceUidsForElementQuery($elementQuery, ['plainTextRenamed']);

    expect($fieldInstanceUids)
        ->toHaveCount(count($fieldInstanceUidsForElementQuery))
        ->toContain(...$fieldInstanceUidsForElementQuery);
});

test('Element query field records with section are saved with order by only for fields in layouts', function() {
    $elementQuery = Entry::find()->section(App::env('TEST_CHANNEL_SECTION_HANDLE'))->orderBy('plainText');
    Blitz::$plugin->generateCache->addElementQuery($elementQuery);
    $fieldInstanceUids = ElementQueryFieldRecord::find()
        ->select(['fieldInstanceUid'])
        ->column();
    $fieldInstanceUidsForElementQuery = FieldHelper::getFieldInstanceUidsForElementQuery($elementQuery, ['plainText']);

    expect($fieldInstanceUids)
        ->toHaveCount(1)
        ->toContain(...$fieldInstanceUidsForElementQuery);
});

test('Cache tags are saved', function() {
    $tags = ['tag1', 'tag2', 'tag3'];
    Blitz::$plugin->generateCache->options->tags = $tags;
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    expect(Blitz::$plugin->cacheTags->getCacheIds($tags))
        ->toHaveCount(1);
});

test('Include record is saved', function() {
    IncludeRecord::deleteAll();
    Blitz::$plugin->generateCache->saveInclude(1, 't', []);

    expect(IncludeRecord::class)
        ->toHaveRecordCount(1);
});

test('SSI include cache record is saved', function() {
    [$includeId] = Blitz::$plugin->generateCache->saveInclude(1, 't', []);
    Blitz::$plugin->generateCache->addSsiInclude($includeId);
    Blitz::$plugin->generateCache->save(createOutput(), createSiteUri());

    expect(SsiIncludeCacheRecord::class)
        ->toHaveRecordCount(1);
});
