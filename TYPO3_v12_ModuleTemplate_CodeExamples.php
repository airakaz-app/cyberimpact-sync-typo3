<?php
/**
 * TYPO3 v12 ModuleTemplate - Quick Reference Code Examples
 * Based on the typo3-cyberimpact-sync extension
 */

declare(strict_types=1);

namespace Example\ModuleTemplate;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// ============================================================================
// EXAMPLE 1: Simplest Setup
// ============================================================================
class SimpleController
{
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = GeneralUtility::makeInstance(ModuleTemplateFactory::class)
            ->create($request);
        
        $moduleTemplate->setTitle('My Module');
        $moduleTemplate->setContent('<p>Hello World</p>');
        
        return $moduleTemplate->renderResponse();
    }
}

// ============================================================================
// EXAMPLE 2: Setting Content with HTML
// ============================================================================
class ContentController
{
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory()->create($request);
        $moduleTemplate->setTitle('Content Module');
        
        // Build HTML content
        $html = '<div class="card">';
        $html .= '  <div class="card-header">';
        $html .= '    <h5>Section Title</h5>';
        $html .= '  </div>';
        $html .= '  <div class="card-body">';
        $html .= '    <p>Content goes here</p>';
        $html .= '  </div>';
        $html .= '</div>';
        
        // Set the content
        $moduleTemplate->setContent($html);
        
        return $moduleTemplate->renderResponse();
    }
    
    private function moduleTemplateFactory(): ModuleTemplateFactory
    {
        return GeneralUtility::makeInstance(ModuleTemplateFactory::class);
    }
}

// ============================================================================
// EXAMPLE 3: Adding CSS and JavaScript Files
// ============================================================================
class AssetController
{
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory()->create($request);
        $moduleTemplate->setTitle('Module with Assets');
        $moduleTemplate->setContent('<div id="app"></div>');
        
        // Get PageRenderer to add CSS and JS
        $pageRenderer = $moduleTemplate->getPageRenderer();
        
        // Add CSS file
        $pageRenderer->addCssFile('/typo3conf/ext/my_ext/Resources/Public/CSS/module.css');
        
        // Add JavaScript file
        $pageRenderer->addJsFile('/typo3conf/ext/my_ext/Resources/Public/JavaScript/module.js');
        
        // Add inline JavaScript
        $inlineJs = <<<'JS'
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Module loaded');
        });
        JS;
        $pageRenderer->addJsInlineCode('myMod', $inlineJs);
        
        return $moduleTemplate->renderResponse();
    }
    
    private function moduleTemplateFactory(): ModuleTemplateFactory
    {
        return GeneralUtility::makeInstance(ModuleTemplateFactory::class);
    }
}

// ============================================================================
// EXAMPLE 4: Passing AJAX URLs via Data Attributes (Real-world pattern)
// ============================================================================
class AjaxModule
{
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory()->create($request);
        $moduleTemplate->setTitle('AJAX Module');
        
        // Build API route URLs
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $apiUrls = [
            'testAction' => (string)$uriBuilder->buildUriFromRoute(
                'my_module', 
                ['route' => 'test-action']
            ),
            'saveAction' => (string)$uriBuilder->buildUriFromRoute(
                'my_module', 
                ['route' => 'save-action']
            ),
        ];
        
        // Build content with data attributes
        $dataAttrs = ' data-url-test="' . htmlspecialchars($apiUrls['testAction']) . '"'
                   . ' data-url-save="' . htmlspecialchars($apiUrls['saveAction']) . '"';
        
        $content = '<div id="module-container"' . $dataAttrs . '>';
        $content .= '  <button id="test-btn">Test</button>';
        $content .= '  <button id="save-btn">Save</button>';
        $content .= '</div>';
        
        $moduleTemplate->setContent($content);
        
        // Register JS file that will use these URLs
        $pageRenderer = $moduleTemplate->getPageRenderer();
        $pageRenderer->addJsFile('/typo3conf/ext/my_ext/Resources/Public/JavaScript/ajax-handler.js');
        
        return $moduleTemplate->renderResponse();
    }
    
    // AJAX endpoint handler
    public function testAction(ServerRequestInterface $request): ResponseInterface
    {
        // Handle AJAX request
        return new \TYPO3\CMS\Core\Http\JsonResponse(['status' => 'ok']);
    }
    
    private function moduleTemplateFactory(): ModuleTemplateFactory
    {
        return GeneralUtility::makeInstance(ModuleTemplateFactory::class);
    }
}

// ============================================================================
// EXAMPLE 5: Complex Content with Multiple Sections (from cyberimpact-sync)
// ============================================================================
class ComplexModule
{
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory()->create($request);
        $moduleTemplate->setTitle('Cyberimpact Sync');
        
        $flashMessages = [];
        if (strtoupper($request->getMethod()) === 'POST') {
            $flashMessages[] = $this->handleUpload($request);
        }
        
        // Build API URLs
        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $apiUrls = [
            'testToken' => (string)$uriBuilder->buildUriFromRoute('tools_cyberimpactsync', ['route' => 'test-token']),
            'fields' => (string)$uriBuilder->buildUriFromRoute('tools_cyberimpactsync', ['route' => 'cyberimpact-fields']),
            'groups' => (string)$uriBuilder->buildUriFromRoute('tools_cyberimpactsync', ['route' => 'cyberimpact-groups']),
        ];
        
        // Concatenate multiple content sections
        $content = implode('', $flashMessages);
        $content .= $this->renderSection1($apiUrls);
        $content .= $this->renderSection2($apiUrls);
        $content .= $this->renderSection3();
        
        // Handle query parameters for detail view
        $queryParams = $request->getQueryParams();
        $itemId = (int)($queryParams['id'] ?? 0);
        if ($itemId > 0) {
            $content .= $this->renderDetail($itemId);
        }
        
        $moduleTemplate->setContent($content);
        
        // Register JavaScript
        $pageRenderer = $moduleTemplate->getPageRenderer();
        $pageRenderer->addJsFile('/typo3conf/ext/my_ext/Resources/Public/JavaScript/module.js');
        
        return $moduleTemplate->renderResponse();
    }
    
    private function renderSection1(array $apiUrls): string
    {
        return '<div class="card"><h3>Section 1</h3></div>';
    }
    
    private function renderSection2(array $apiUrls): string
    {
        return '<div class="card"><h3>Section 2</h3></div>';
    }
    
    private function renderSection3(): string
    {
        return '<div class="card"><h3>Section 3</h3></div>';
    }
    
    private function renderDetail(int $itemId): string
    {
        return '<div class="card"><h3>Detail for item #' . $itemId . '</h3></div>';
    }
    
    private function handleUpload(ServerRequestInterface $request): string
    {
        return '<div class="alert alert-success">Upload successful</div>';
    }
    
    private function moduleTemplateFactory(): ModuleTemplateFactory
    {
        return GeneralUtility::makeInstance(ModuleTemplateFactory::class);
    }
}

// ============================================================================
// CORRESPONDING JAVASCRIPT FILE (ajax-handler.js or module.js)
// ============================================================================
?>

<!-- File: Resources/Public/JavaScript/module.js -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Get container and API URLs from data attributes
    const container = document.getElementById('module-container');
    if (!container) return;
    
    const apiUrls = {
        test: container.dataset.urlTest,
        save: container.dataset.urlSave,
    };
    
    // Example: Handle button click
    const testBtn = document.getElementById('test-btn');
    if (testBtn) {
        testBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            
            try {
                const response = await fetch(apiUrls.test, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                    }
                });
                
                const data = await response.json();
                console.log('Response:', data);
                
                if (data.status === 'ok') {
                    alert('Action successful!');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error: ' + error.message);
            }
        });
    }
    
    // Example: Handle save button
    const saveBtn = document.getElementById('save-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            
            const formData = new URLSearchParams();
            formData.append('data', JSON.stringify({ value: 'test' }));
            
            try {
                const response = await fetch(apiUrls.save, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: formData
                });
                
                const data = await response.json();
                console.log('Saved:', data);
            } catch (error) {
                console.error('Error:', error);
            }
        });
    }
});
</script>

<?php
// ============================================================================
// PAGE RENDERER Methods Reference
// ============================================================================

/**
 * Common PageRenderer methods for CSS and JavaScript
 */
class PageRendererMethods
{
    public function exampleMethods($pageRenderer)
    {
        // Add external CSS file
        $pageRenderer->addCssFile('/typo3conf/ext/my_ext/Resources/Public/CSS/style.css');
        
        // Add external JavaScript file
        $pageRenderer->addJsFile('/typo3conf/ext/my_ext/Resources/Public/JavaScript/module.js');
        
        // Add inline CSS
        $pageRenderer->addCssInlineBlock('myStyles', 'body { color: red; }');
        
        // Add inline JavaScript
        $pageRenderer->addJsInlineCode('myScript', 'console.log("test");');
        
        // Add inline JavaScript in footer
        $pageRenderer->addJsFooterInlineCode('myFooterScript', 'console.log("footer");');
        
        // Load jQuery (if needed)
        $pageRenderer->loadJQuery();
        
        // Get the charset
        $charset = $pageRenderer->getCharSet();
        
        // Get the language
        $lang = $pageRenderer->getLanguage();
    }
}
?>
