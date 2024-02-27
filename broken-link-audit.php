<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Plugin\BrokenLinkAudit\Auditor;
use Pimple\Container;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Class BrokenLinkAuditPlugin
 * @package Grav\Plugin
 */
class BrokenLinkAuditPlugin extends Plugin
{
    protected $route = 'broken-links';
    protected $auditor;

    /** @var array */
    public $features = [
        'blueprints' => 0, // Use priority 0
    ];

  /**
   * @return array
   *
   * The getSubscribedEvents() gives the core a list of events
   *     that the plugin wants to listen to. The key of each
   *     array section is the event that the plugin listens to
   *     and the value (in the form of an array) contains the
   *     callable (or function) as well as the priority. The
   *     higher the number the higher the priority.
   */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
                ['onPluginsInitialized', 0],
            ],
            'onTwigLoader' => ['onTwigLoader', 0],
        ];
    }


    /**
     * [onPluginsInitialized:100000] Composer autoload.
     *is
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize plugin.
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            $this->auditor = new Auditor();
            if (null !== ($this->auditor->pdo)) {
                $this->enable([
                    'onAdminMenu'           => ['onAdminMenu', 0],
                    'onAdminAfterSave'      => ['onAdminAfterSave', 0],
                    'onAdminTaskExecute'    => ['onAdminTaskExecute', 0],
                    'onTwigSiteVariables'   => ['onTwigAdminVariables', 0],
                    'onTwigTemplatePaths'   => ['onTwigAdminTemplatePaths', 0],
                    'onTwigLoader'          => ['addAdminTwigTemplates', 0],
                    'onGetPageTemplates'    => ['onGetPageTemplates', 0],
                    'onPagesInitialized'    => ['onPagesInitialized', 0],
                ]);
            }
        }
        return;
    }

    /**
     * Set some twig vars and load CSS/JS assets for admin
     */
    public function onTwigAdminVariables(): void
    {
        $this->grav['assets']->addJs('plugin://broken-link-audit/assets/broken-link-audit.js', ['group' => 'bottom']);
    }

    /**
     * Add navigation item to the admin plugin
     */
    public function onAdminMenu(): void
    {
        // Set title of the admin page.

        $count = new Container([
            'updates' => 0,
            'count' => function () {
                return $this->auditor->countRoutes();
            }
        ]);

        $this->grav['twig']->plugins_hooked_nav['PLUGIN_BROKEN_LINK_AUDIT.ADMIN.TITLE'] = [
            'route' => $this->route,
            'icon' => 'fa-chain-broken',
            'authorize' => ['admin.pages', 'admin.super'],
            'badge' => $count,
        ];

        $this->grav['twig']->plugins_quick_tray['Broken Link Audit'] = [
            'authorize' => 'rescanBrokenLinkAudit', //todo: fix this
            'hint' => 'Reindexs the site for broken links',
            'class' => 'brokenLinkAudit-rescan', //todo: fix this
            'icon' => 'fa-chain-broken'
        ];
    }

    /**
     * Rescan a page after saving.
     *
     * @return void
     */
    public function onAdminAfterSave(Event $event): void
    {
        $page = $event['object'];
        if (method_exists($page, 'template')) {
            $this->auditor->scanPage($page);
        }
    }

    /**
     * Handle the ReScan task from the admin quick tray.
     * Todo: fix this.
     *
     * @param Event $e
     */
    public function onAdminTaskExecute(Event $e): void
    {
        if ($e['method'] === 'taskRescanBrokenLinkAudit') {
            $controller = $e['controller'];

            header('Content-type: application/json');

            if (!$controller->authorizeTask('reindexTNTSearch', ['admin.configuration', 'admin.super'])) {
                $json_response = [
                    'status'  => 'error',
                    'message' => '<i class="fa fa-warning"></i> Index not created',
                    'details' => 'Insufficient permissions to reindex the search engine database.'
                ];
                echo json_encode($json_response);
                exit;
            }

            // disable warnings
            error_reporting(1);
            // disable execution time
            set_time_limit(0);

            list($status, $msg, $output) = static::rescanJob();

            $json_response = [
                'status'  => $status ? 'success' : 'error',
                'message' => $msg
            ];

            echo json_encode($json_response);
            exit;
        }
    }

    /**
     * Add the Twig template paths to the Twig loader.
     */
    public function onTwigLoader(): void
    {
        $this->grav['twig']->addPath(__DIR__ . '/templates');
    }

    /**
     * Add the current template paths to the admin Twig loader
     */
    public function addAdminTwigTemplates(): void
    {
        $this->grav['twig']->addPath($this->grav['locator']->findResource('theme://templates'));
    }

    /**
     * Add plugin templates path
     */
    public function onTwigAdminTemplatePaths()
    {
        // Check if we're on admin, editing a page.
        $current_admin_route = $this->grav['admin']->page()->route();
        if (isset($current_admin_route)) {
            $route = $current_admin_route;
            $home = $this->grav['config']['system']['home']['alias'];
            if ($route == $home) {
                $route = "/";
            }
        } else {
            $route = null;
        }

        $this->grav['twig']->twig_paths[] = __DIR__ . '/admin/templates';

        // Reads invalid links from db.
        if (!isset($this->grav['twig']->bla_links)) {
            $this->grav['twig']->bla_links = $this->getInvalidLinks($route);
        }

        // Offer Run Reports button if links returns empty.
        if (empty($this->grav['twig']->bla_links)) {
            $this->grav['twig']->bla_links = [
                "Run Report" => []
            ];
        }
        $config = $this->config();
        $this->grav['twig']->bla_inspection = $config['inspection_level'];
    }

    /**
     * Add blueprint directory to page templates.
     */
    public function onGetPageTemplates(Event $event): void
    {
        $types = $event->types;
        $locator = $this->grav['locator'];
        $types->scanBlueprints($locator->findResource('plugin://' . $this->name . '/blueprints'));
        //$types->scanTemplates($locator->findResource('plugin://' . $this->name . '/templates'));
    }

    /**
     *  Build search
     */
    public function onPagesInitialized(): void
    {
        if ($this->grav['page']->template() != 'broken-links') {
            return;
        }

    }


    public static function scanPages(): int
    {
        $auditor = new Auditor();

        $grav = Grav::instance();

        $auditor->clearUnresolvableLinks();

        /** @var Pages $pages */
        $pages = $grav['pages'];
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }

        $i = 0;
        foreach ($pages->all() as $key => $page) {
            $auditor->scanPage($page);
            $i++;
        }

        //$bad_links = $this->checkLinks($all_links, $inspection_level, $valid_routes);
        return $i;
    }

    /**
     * Function to force a reindex.
     */
    public function onReIndex(): void
    {
        $this->auditor->reCreateIndex();
    }

    /**
     * @param $content
     * @param $inspection_level
     * @param $valid_routes
     * @return array
     *   Array of links found within the content.
     */
    public function findPageLinks($content, $inspection_level): array|null
    {
        $links = [];
        if ($inspection_level == 'raw') {
            // Create list of matching URLS to patterns.
            foreach ($this->rawInspectionPatterns() as $type => $page_pattern) {
                preg_match_all($page_pattern, $content, $matches);

                if (count($matches[0]) > 0) {
                    $links[$type] = $matches[0];
                }
            }
        } elseif ($inspection_level == 'rendered') {
            $links = [];
        }
        return $links;
    }

    /**
     * Returns links from database.
     *
     * @param string $route
     * @return array
     */
    public function getInvalidLinks($route = null): array
    {
        if (isset($route)) {
            $where = [
                "route[=]" => $route
            ];
        } else {
            $where = null;
        }
        $results = $this->auditor->pdo->select("per_route", [
            "route",
            "link_type",
            "link",
            "last_found",
        ], $where);
        $data = [];
        foreach ($results as $row) {
            // Change display of the home-aliased route.
            if ($row['route'] == '/') {
                $route = $this->grav['config']['system']['home']['alias'];
            } else {
                $route = $row['route'];
            }
            $link_type = $row['link_type'];
            $link = $row['link'];
            $last_found = $row['last_found'];

            if (!isset($data[$route])) {
                $data[$route] = [];
                $data[$route][$link_type] = [];
            }
            if (!isset($data[$route][$link_type])) {
                $data[$route][$link_type] = [];
            }
            $data[$route][$link_type][] = $link;
        }

        return $data;
    }

    public function checkLinks($links, $inspection_level, $valid_routes): array
    {
        // todo: make this a direct call from find links rather than having to reparse the whole thing again.
        $bad_links = array();
        foreach ($links as $path => $page) {
            if ($inspection_level == 'raw') {
                foreach ($this->rawInspectionPatterns() as $type => $pattern) {
                    if (isset($page[ $type ])) {
                        foreach ($page[ $type ] as $key => $link) {
                            switch ($type) {
                                case 'page_relative':
                                    $bad_links[$path][$type][$key] = $link;
                                    break;
                                case 'page_absolute':
                                    $bad_links[$path][$type][$key] = $link;
                                    break;
                                case 'page_remote':
                                    // Don't return remote links.
                                    $bad_links[$path][$type][$key] = $link;
                                    break;
                                case 'combined':
                                    $bad_links[$path][$type][$key] = $link;
                                    break;
                                case 'media_relative':
                                    $bad_links[$path][$type][$key] = $link;
                                    break;
                                case 'media_absolute':
                                    $bad_links[$path][$type][$key] = $link;
                                    break;
                                case 'media_remote':
                                    // Don't return remote links.
                                    //$bad_links[$path][$type][$key] = $link;
                                    break;
                                default:
                                    $bad_links[$path][$type][$key] = $link;
                            }
                        }
                    }
                }
            }
        }

        return $bad_links;
    }

    private static function rescanJob(): array
    {
        $response = [true, "ok", ""];
        $i = static::scanPages();
        $response[1] = "Checked $i pages";
        return $response;
    }
}
