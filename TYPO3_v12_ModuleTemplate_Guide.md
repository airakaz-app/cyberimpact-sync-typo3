# TYPO3 v12 ModuleTemplate - Complete Usage Guide

This guide shows the correct API usage for `ModuleTemplate` in TYPO3 v12 based on the typo3-cyberimpact-sync extension implementation.

## 1. Basic Setup & Initialization

### Import the Factory
```php
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Psr\Http\Message\ServerRequestInterface;

final class MyBackendController
{
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        // Create ModuleTemplate instance using the factory
        $moduleTemplate = $this->moduleTemplateFactory()->create($request);
        
        // ... rest of the code
        
        return $moduleTemplate->renderResponse();
    }

    private function moduleTemplateFactory(): ModuleTemplateFactory
    {
        return GeneralUtility::makeInstance(ModuleTemplateFactory::class);
    }
}
```

## 2. Setting the Main Content Body HTML

### Method: `setContent(string $content): ModuleTemplate`

Sets the main HTML content body of the module.

```php
$moduleTemplate = $this->moduleTemplateFactory()->create($request);

// Set the title
$moduleTemplate->setTitle('My Module Title');

// Build your HTML content
$content = '<div class="my-content">';
$content .= '<h2>Welcome</h2>';
$content .= '<p>Your module content here</p>';
$content .= '</div>';

// Set the content
$moduleTemplate->setContent($content);
```

**Complete Example from typo3-cyberimpact-sync:**
```php
$moduleTemplate = $this->moduleTemplateFactory()->create($request);
$moduleTemplate->setTitle('Cyberimpact Sync');

// Build multiple content sections
$flashMessages = [];
if (strtoupper($request->getMethod()) === 'POST') {
    $flashMessages[] = $this->handleUpload($request);
}

// Concatenate content
$content = implode('', $flashMessages) . 
           $this->renderUploadForm($apiUrls) .
           $this->renderExactSyncSettings($apiUrls) .
           $this->renderRunsList();

// Add detail section based on query parameter
$queryParams = $request->getQueryParams();
$runUid = (int)($queryParams['run'] ?? 0);
if ($runUid > 0) {
    $content .= $this->renderRunDetail($runUid);
}

$moduleTemplate->setContent($content);
```

## 3. Registering JavaScript Files

### Method: `getPageRenderer(): PageRenderer`

Get the PageRenderer instance to register CSS and JavaScript files.

#### Adding JavaScript Files
```php
$pageRenderer = $moduleTemplate->getPageRenderer();

// Add external JavaScript file
$publicPath = '/typo3conf/ext/my_extension/Resources/Public/JavaScript/module.js';
$pageRenderer->addJsFile($publicPath);
```

#### Adding CSS Files
```php
$pageRenderer = $moduleTemplate->getPageRenderer();

// Add CSS file
$cssPath = '/typo3conf/ext/my_extension/Resources/Public/CSS/module.css';
$pageRenderer->addCssFile($cssPath);
```

#### Adding Inline JavaScript
```php
$pageRenderer = $moduleTemplate->getPageRenderer();

// Add inline JavaScript code
$inlineJs = <<<'JS'
document.addEventListener('DOMContentLoaded', function() {
    console.log('Module loaded');
});
JS;

$pageRenderer->loadJQuery();  // Load jQuery if needed (TYPO3)
$pageRenderer->addJsInlineCode('myModule', $inlineJs);
```

**Complete Example from typo3-cyberimpact-sync:**
```php
$moduleTemplate = $this->moduleTemplateFactory()->create($request);
$moduleTemplate->setTitle('Cyberimpact Sync');

// ... set content ...

$moduleTemplate->setContent($content);

// Register the JavaScript file
$pageRenderer = $moduleTemplate->getPageRenderer();
$publicPath = '/typo3conf/ext/cyberimpact_sync/Resources/Public/JavaScript/sync-module.js';
$pageRenderer->addJsFile($publicPath);

return $moduleTemplate->renderResponse();
```

## 4. Available ModuleTemplate Methods

### Core Methods

| Method | Return Type | Description |
|--------|------------|-------------|
| `setTitle(string $title)` | `ModuleTemplate` | Set the module title/heading |
| `setContent(string $content)` | `ModuleTemplate` | Set the main HTML content body |
| `setFlashMessageQueue()` | `ModuleTemplate` | Set flash message queue (messages shown at top) |
| `getPageRenderer()` | `PageRenderer` | Get PageRenderer instance for CSS/JS registration |
| `renderResponse()` | `ResponseInterface` | Return the final HTTP response (PSR-7) |
| `makeDocumentHeader()` | `string` | Generate the header HTML (typically handled internally) |

### PageRenderer Methods (obtained via `getPageRenderer()`)

| Method | Purpose |
|--------|---------|
| `addJsFile(string $jsfile, ...)` | Register external JavaScript file |
| `addCssFile(string $cssfile, ...)` | Register external CSS file |
| `addJsInlineCode(string $name, string $code)` | Add inline JavaScript code |
| `addCssInlineBlock(string $name, string $css)` | Add inline CSS block |
| `loadJQuery()` | Load jQuery library |
| `addJsFooterInlineCode(string $name, string $code)` | Add JS code in footer |

## 5. Complete Controller Example

Here's a fully functional example based on the typo3-cyberimpact-sync extension:

```php
<?php
declare(strict_types=1);

namespace MyVendor\MyExtension\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Routing\UriBuilder;

final class MyBackendController
{
    /**
     * Main request handler
     */
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        // 1. Create module template using factory
        $moduleTemplate = $this->moduleTemplateFactory()->create($request);
        
        // 2. Set the module title
        $moduleTemplate->setTitle('My Awesome Module');
        
        // 3. Build your content
        $content = $this->renderMainContent($request);
        
        // 4. Set the content
        $moduleTemplate->setContent($content);
        
        // 5. Register CSS and JavaScript files
        $pageRenderer = $moduleTemplate->getPageRenderer();
        
        // Register CSS
        $pageRenderer->addCssFile('/typo3conf/ext/my_extension/Resources/Public/CSS/module.css');
        
        // Register JavaScript
        $pageRenderer->addJsFile('/typo3conf/ext/my_extension/Resources/Public/JavaScript/module.js');
        
        // Optional: Add inline JavaScript
        $inlineJs = <<<'JS'
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Module initialized');
            // Add your JavaScript here
        });
        JS;
        $pageRenderer->addJsInlineCode('myModule', $inlineJs);
        
        // 6. Return the response
        return $moduleTemplate->renderResponse();
    }
    
    /**
     * Render the main content
     */
    private function renderMainContent(ServerRequestInterface $request): string
    {
        $html = '<div class="card">';
        $html .= '  <div class="card-header">';
        $html .= '    <h4>Main Section</h4>';
        $html .= '  </div>';
        $html .= '  <div class="card-body">';
        $html .= '    <p>Your content goes here</p>';
        $html .= '  </div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Factory method for ModuleTemplate
     */
    private function moduleTemplateFactory(): ModuleTemplateFactory
    {
        return GeneralUtility::makeInstance(ModuleTemplateFactory::class);
    }
}
```

## 6. JavaScript File Communication Pattern

### Setting Up Dynamic URLs in Data Attributes

The typo3-cyberimpact-sync extension uses a pattern where AJAX endpoints are passed to JavaScript via data attributes:

```php
// In your controller, build API URLs
$uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
$apiUrls = [
    'action1' => (string)$uriBuilder->buildUriFromRoute('my_route', ['route' => 'action-1']),
    'action2' => (string)$uriBuilder->buildUriFromRoute('my_route', ['route' => 'action-2']),
];

// Pass them as data attributes to the content
$dataAttrs = ' data-url-action1="' . htmlspecialchars($apiUrls['action1']) . '"'
           . ' data-url-action2="' . htmlspecialchars($apiUrls['action2']) . '"';

$content = '<div id="module-container"' . $dataAttrs . '>';
$content .= '  <!-- your HTML -->';
$content .= '</div>';

$moduleTemplate->setContent($content);
```

### Accessing URLs in JavaScript

```javascript
// sync-module.js
document.addEventListener('DOMContentLoaded', function() {
    const mainContainer = document.getElementById('module-container');
    if (!mainContainer) return;
    
    const apiUrls = {
        action1: mainContainer.dataset.urlAction1,
        action2: mainContainer.dataset.urlAction2,
    };
    
    // Use the URLs in fetch calls
    fetch(apiUrls.action1, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    });
});
```

## 7. Best Practices for TYPO3 v12

1. **Always use ModuleTemplateFactory** - Don't instantiate ModuleTemplate directly
2. **Pass ServerRequestInterface** - The factory needs the request object
3. **Return ResponseInterface** - All controller methods must return PSR-7 responses
4. **Use renderResponse()** - This properly sets headers and converts the template to a response
5. **Security** - Always escape HTML output with `htmlspecialchars()` when passing user data
6. **TypeScript/Modern JS** - Use ES6+ features and fetch API instead of jQuery when possible
7. **CSS/JS Paths** - Use web-accessible paths from the public directory

## 8. File Structure Example

```
my_extension/
├── Classes/
│   └── Controller/
│       └── Backend/
│           └── MyController.php
├── Resources/
│   ├── Public/
│   │   ├── CSS/
│   │   │   └── module.css
│   │   ├── JavaScript/
│   │   │   └── module.js
│   │   └── Icons/
│   └── Private/
│       └── Templates/
└── Configuration/
    └── Backend/
        └── Modules.php
```

## References

- **ModuleTemplate Class**: `TYPO3\CMS\Backend\Template\ModuleTemplate`
- **ModuleTemplateFactory**: `TYPO3\CMS\Backend\Template\ModuleTemplateFactory`
- **PageRenderer**: `TYPO3\CMS\Core\Page\PageRenderer`
- **TYPO3 Docs**: https://docs.typo3.org/
