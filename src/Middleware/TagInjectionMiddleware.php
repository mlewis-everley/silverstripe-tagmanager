<?php 

namespace SilverStripe\TagManager\Middleware;

use Psr\Log\LoggerInterface;
use SilverStripe\Admin\AdminRootController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\DevelopmentAdmin;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\TagManager\Model\Snippet;
use SilverStripe\TagManager\SnippetProvider;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Config\Config;
use SilverStripe\GraphQL\Controller as GraphQLController;

class TagInjectionMiddleware implements HTTPMiddleware
{
    private static $excluded_base_controllers = [
        AdminRootController::class,
        DevelopmentAdmin::class,
        GraphQLController::class
    ];

    protected function insertSnippetsIntoHTML($html)
    {
        // TO DO: work out how to get info on current page
        $snippets = Snippet::get()->filter(['Active' => 'on']);

        $combinedHTML = [];

        $logger = null;
        if (Injector::inst()->has(LoggerInterface::class)) {
            $logger = Injector::inst()->get(LoggerInterface::class);
        }

        foreach ($snippets as $snippet) {
            try {
                $thisHTML = $snippet->getSnippets();
            } catch (\InvalidArgumentException $e) {
                $message = sprintf(
                    "Misconfigured snippet %s: %s",
                    $snippet->getTitle(),
                    $e->getMessage()
                );

                if ($logger) {
                    $logger->warning($message, ['exception' => $e]);
                } else {
                    user_error($message, E_USER_WARNING);
                }

                continue;
            }

            foreach ($thisHTML as $k => $v) {
                if (!isset($combinedHTML[$k])) {
                    $combinedHTML[$k] = "";
                }
                $combinedHTML[$k] .= $v;
            }
        }

        foreach ($combinedHTML as $k => $v) {
            switch ($k) {
                case SnippetProvider::ZONE_HEAD_START:
                    $html = preg_replace('#(<head(>+|[\s]+(.*)?>))#i', '\\1' . $v, $html);
                    break;

                case SnippetProvider::ZONE_HEAD_END:
                    $html = preg_replace('#(</head(>+|[\s]+(.*)?>))#i', $v . '\\1', $html);
                    break;

                case SnippetProvider::ZONE_BODY_START:
                    $html = preg_replace('#(<body(>+|[\s]+(.*)?>))#i', '\\1' . $v, $html);
                    break;

                case SnippetProvider::ZONE_BODY_END:
                    $html = preg_replace('#(</body(>+|[\s]+(.*)?>))#i', $v . '\\1', $html);
                    break;

                default:
                    $message = "Unknown snippet zone '$k'; ignoring";
                    if ($logger) {
                        $logger->warning($message, ['exception' => $e]);
                    } else {
                        user_error($message, E_USER_WARNING);
                    }
            }
        }

        return $html;
    }

    public function process(HTTPRequest $request, callable $delegate)
    {
        $response = $delegate($request);
        $ignore = Config::inst()->get(
            self::class,
            'excluded_base_controllers'
        );

        if (Director::is_cli()) {
            return $response;
        }

        foreach ($ignore as $baseClass) {
            $params = $request->routeParams();

            if (!isset($params['Controller'])) {
                continue;
            }

            $controller_class = str_replace(
                ['%', '$', '.admin'],
                '',
                $params['Controller']
            );

            if (is_a($controller_class, $baseClass, true)) {
                return $response;
            }
        }

        $body = $response->getBody();

        if (empty($body)) {
            return $response;
        }

        $body = $this->insertSnippetsIntoHTML($body);
        $response->setBody($body);

        return $response;
    }
}