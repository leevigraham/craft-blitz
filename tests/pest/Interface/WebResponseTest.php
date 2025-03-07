<?php

/**
 * Tests that cached web responses contain the correct headers and comments.
 */

use Mockery\MockInterface;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\enums\HeaderEnum;
use putyourlightson\blitz\models\SettingsModel;
use putyourlightson\blitz\services\RefreshCacheService;

beforeEach(function() {
    Blitz::$plugin->cacheStorage->deleteAll();
    Blitz::$plugin->generateCache->options->outputComments = null;
    Blitz::$plugin->set('refreshCache', Mockery::mock(RefreshCacheService::class . '[refresh]'));
});

afterAll(function() {
    Blitz::$plugin->cacheStorage->deleteAll();
});

test('Response contains the default cache control header when the page is not cacheable', function() {
    $response = sendRequest();
    Blitz::$plugin->cacheRequest->setDefaultCacheControlHeader();

    expect($response->headers->get(HeaderEnum::CACHE_CONTROL->value))
        ->toEqual(Blitz::$plugin->settings->defaultCacheControlHeader);
});

test('Response contains the cache control header when the page is cacheable', function() {
    $response = sendRequest();

    expect($response->headers->get(HeaderEnum::CACHE_CONTROL->value))
        ->toEqual(Blitz::$plugin->settings->cacheControlHeader);
});

test('Response contains the expired cache control header and the cache is refreshed when the page is expired', function() {
    sendRequest();

    // Must use a blank URI for this test!
    $siteUri = createSiteUri(uri: '');

    Blitz::$plugin->expireCache->expireUris([$siteUri]);

    /** @var MockInterface $refreshCache */
    $refreshCache = Blitz::$plugin->refreshCache;
    $refreshCache->shouldReceive('refresh')->once();

    $response = Blitz::$plugin->cacheRequest->getCachedResponse($siteUri);

    expect($response->headers->get(HeaderEnum::CACHE_CONTROL->value))
        ->toEqual(Blitz::$plugin->settings->cacheControlHeaderExpired);
});

test('Response adds the powered by header', function() {
    Craft::$app->config->general->sendPoweredByHeader = true;
    $response = sendRequest();

    expect($response->headers->get(HeaderEnum::X_POWERED_BY->value))
        ->toContain('Blitz');
});

test('Response contains output comments when enabled', function(bool|int $value) {
    Blitz::$plugin->settings->outputComments = $value;
    $response = sendRequest();

    expect($response->content)
        ->toContain('Cached by Blitz');
})->with([
    'true' => true,
    'SettingsModel::OUTPUT_COMMENTS_CACHED' => SettingsModel::OUTPUT_COMMENTS_CACHED,
]);

test('Response does not contain output comments when disabled', function(bool|int $value) {
    Blitz::$plugin->settings->outputComments = $value;
    $response = sendRequest();

    expect($response->content)
        ->not()->toContain('Cached by Blitz');
})->with([
    'false' => false,
    'SettingsModel::OUTPUT_COMMENTS_SERVED' => SettingsModel::OUTPUT_COMMENTS_SERVED,
]);

test('Response with mime type has headers and does not contain output comments', function() {
    $output = createOutput();
    $siteUri = createSiteUri(uri: 'page.json');
    Blitz::$plugin->cacheStorage->save($output, $siteUri);
    $response = Blitz::$plugin->cacheRequest->getCachedResponse($siteUri);

    expect($response->headers->get(HeaderEnum::CONTENT_TYPE->value))
        ->toBe('application/json')
        ->and($response->content)
        ->toBe($output);
});

test('Response is encoded when compression is enabled', function() {
    $output = createOutput();
    $siteUri = createSiteUri();
    Blitz::$plugin->cacheStorage->compressCachedValues = true;
    Blitz::$plugin->cacheStorage->save($output, $siteUri);
    Craft::$app->getRequest()->headers->remove(HeaderEnum::ACCEPT_ENCODING->value);
    Craft::$app->getRequest()->headers->set(HeaderEnum::ACCEPT_ENCODING->value, 'deflate, gzip');
    $response = Blitz::$plugin->cacheRequest->getCachedResponse($siteUri);

    expect($response->headers->get(HeaderEnum::CONTENT_ENCODING->value))
        ->toBe('gzip')
        ->and(gzdecode($response->content))
        ->toBe($output);
});
