# Test Specification

This document outlines the test specification for the Blitz plugin.

---

## Integration Tests

### [Seomatic](pest/Integration/SeomaticTest.php)

_Tests that cached pages are refreshed when SEOmatic meta containers are invalidated._

![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) Invalidate container caches event without a URL or source triggers a refresh all.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) Invalidate container caches event with a specific source triggers a refresh.  
![Pass](https://raw.githubusercontent.com/putyourlightson/craft-generate-test-spec/main/icons/pass.svg) Invalidate container caches event for a specific element does not trigger a refresh.  
