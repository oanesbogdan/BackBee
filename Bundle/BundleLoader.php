<?php
namespace BackBuilder\Bundle;


use BackBuilder\BBApplication;
use BackBuilder\Config\Config;
use BackBuilder\DependencyInjection\Util\ServiceLoader;
use BackBuilder\DependencyInjection\Dumper\DumpableServiceProxyInterface;
use BackBuilder\DependencyInjection\Loader\ContainerProxy;

use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Yaml\Yaml;

class BundleLoader
{
    const BUNDLE_SERVICE_KEY_PATTERN = 'bundle.%sbundle';

    const EVENT_RECIPE_KEY = 'event';
    const SERVICE_RECIPE_KEY = 'service';
    const CLASSCONTENT_RECIPE_KEY = 'classcontent';
    const RESOURCE_RECIPE_KEY = 'resource';
    const TEMPLATE_RECIPE_KEY = 'template';
    const HELPER_RECIPE_KEY = 'helper';
    const ROUTE_RECIPE_KEY = 'route';

    private static $bundles_base_dir = array();

    private static $bundles_config = array();

    private static $application = null;

    /**
     * [loadBundlesIntoApplication description]
     * @param  BBApplication $application  [description]
     * @param  array         $bundles_config [description]
     * @return [type]                      [description]
     */
    public static function loadBundlesIntoApplication(BBApplication $application, array $bundles_config)
    {
        self::$application = $application;
        $container = $application->getContainer();
        self::$bundles_base_dir = true === $container->hasParameter('bundles.base_dir')
            ? $container->getParameter('bundles.base_dir')
            : array()
        ;

        $do_get_base_dir = !(0 < count(self::$bundles_base_dir)) ?: true;

        foreach ($bundles_config as $name => $classname) {
            $key = sprintf(self::BUNDLE_SERVICE_KEY_PATTERN, strtolower($name));

            if (false === $container->hasDefinition($key)) {
                $r = new \ReflectionClass($classname);
                self::$bundles_base_dir[$key] = dirname($r->getFileName());

                $definition = new Definition($classname, array(new Reference('bbapp')));
                $definition->addTag('bundle');
                $container->setDefinition($key, $definition);
            }


        }

        $container->setParameter('bundles.base_dir', self::$bundles_base_dir);

        self::loadBundlesConfig();
        self::loadBundleEvents();
        self::loadBundlesServices();
        self::registerBundleClassContentDir();
        self::registerBundleResourceDir();
        self::registerBundleScriptDir();
        self::registerBundleHelperDir();

        // add BundleListener event (service.tagged.bundle)
        $event_dispatcher = $application->getContainer()->get('event.dispatcher');
        if (
            false === ($event_dispatcher instanceof DumpableServiceProxyInterface)
            || false === $event_dispatcher->isRestored()
        ) {
            $event_dispatcher->addListeners(array(
                'bbapplication.start' => array(
                    'listeners' => array(
                        array(
                            'BackBuilder\Bundle\Listener\BundleListener',
                            'onApplicationStart'
                        )
                    )
                ),
                'service.tagged.bundle' => array(
                    'listeners' => array(
                        array(
                            'BackBuilder\Bundle\Listener\BundleListener',
                            'onGetBundleService'
                        )
                    )
                ),
                'bbapplication.stop' => array(
                    'listeners' => array(
                        array(
                            'BackBuilder\Bundle\Listener\BundleListener',
                            'onApplicationStop'
                        )
                    )
                )
            ));
        }

        // Cleaning memory
        self::$bundles_base_dir = array();
        self::$bundles_config = array();
        self::$application = null;
    }

    /**
     * [loadBundlesConfig description]
     */
    private static function loadBundlesConfig()
    {
        $services_id = array();
        $container = self::$application->getContainer();
        foreach (self::$bundles_base_dir as $key => $base_dir) {
            $config = null;
            $config_service_id = \BackBuilder\Bundle\ABundle::getBundleConfigServiceId($base_dir);
            if (false === $container->hasDefinition($config_service_id)) {
                $definition = new Definition('BackBuilder\Config\Config', array(
                    $base_dir . DIRECTORY_SEPARATOR . 'Ressources',
                    new Reference('cache.bootstrap'),
                    null,
                    '%debug%',
                    '%config.yml_files_to_ignore%'
                ));
                $definition->addTag('dumpable');
                $definition->addMethodCall('setContainer', array(new Reference('service_container')));
                $definition->addMethodCall('setEnvironment', array('%bbapp.environment%'));
                $config = \BackBuilder\Bundle\ABundle::initBundleConfig(self::$application, $base_dir);
                $container->set($config_service_id, $config);
                $container->setDefinition($config_service_id, $definition);
            } else {
                $config = $container->get($config_service_id);
            }

            self::$bundles_config[$key] = $config;
            $services_id[] = $config_service_id;
        }

        self::$application->getContainer()->get('registry')->set('bundle.config_services_id', $services_id);
    }

    private static function loadBundleEvents()
    {
        $event_dispatcher = self::$application->getContainer()->get('event.dispatcher');

        if (($event_dispatcher instanceof DumpableServiceProxyInterface) && $event_dispatcher->isRestored()) {
            return;
        }

        foreach (self::$bundles_config as $key => $config) {
            $recipe = self::getBundleLoaderRecipeFor($config, self::EVENT_RECIPE_KEY);
            if (null === $recipe) {
                $events = $config->getRawSection('events');
                if (false === is_array($events) || 0 === count($events)) {
                    continue;
                }

                $event_dispatcher->addListeners($events);
            } else {
                if (true === is_callable($recipe)) {
                    call_user_func_array($recipe, array(self::$application, $config));
                }
            }
        }
    }

    /**
     * Load every service definition defined in bundle
     */
    private static function loadBundlesServices()
    {
        $container = self::$application->getContainer();
        if (true === ($container instanceof ContainerProxy)) {
            return;
        }

        $bundle_env_directory = null;
        if (BBApplication::DEFAULT_ENVIRONMENT !== self::$application->getEnvironment()) {
            $bundle_env_directory = implode(DIRECTORY_SEPARATOR, array(
                self::$application->getRepository(), 'Config', self::$application->getEnvironment(), 'bundle'
            ));
        }

        $container = self::$application->getContainer();
        foreach (self::$bundles_base_dir as $key => $dir) {
            $config = self::$application->getContainer()->get($key . '.config');
            $recipe = null;
            if (null !== $config) {
                $recipe = self::getBundleLoaderRecipeFor($config, self::SERVICE_RECIPE_KEY);
            }

            if (null === $recipe) {
                $services_directory = array($dir . DIRECTORY_SEPARATOR . 'Ressources');
                if (null !== $bundle_env_directory) {
                    $services_directory[] = $bundle_env_directory . DIRECTORY_SEPARATOR . basename($dir);
                }

                foreach ($services_directory as $sd) {
                    $filepath = $sd . DIRECTORY_SEPARATOR . 'services.xml';
                    if (true === is_file($filepath) && true === is_readable($filepath)) {
                        try {
                            ServiceLoader::loadServicesFromXmlFile($container, $sd);
                        } catch (Exception $e) { /* nothing to do, just ignore it */ }
                    }
                }
            } else {
                if (null !== $config && true === is_callable($recipe)) {
                    call_user_func_array($recipe, array(self::$application, $config));
                }
            }
        }
    }

    private static function registerBundleClassContentDir()
    {
        if (true === self::$application->isRestored()) {
            return;
        }

        foreach (self::$bundles_base_dir as $key => $dir) {
            $config = self::$application->getContainer()->get($key . '.config');
            $recipe = null;
            if (null !== $config) {
                $recipe = self::getBundleLoaderRecipeFor($config, self::CLASSCONTENT_RECIPE_KEY);
            }

            if (null === $recipe) {
                $classcontent_dir = realpath($dir . DIRECTORY_SEPARATOR . 'ClassContent');
                if (false === $classcontent_dir) {
                    continue;
                }

                self::$application->pushClassContentDir($classcontent_dir);
            } else {
                if (null !== $config && true === is_callable($recipe)) {
                    call_user_func_array($recipe, array(self::$application, $config));
                }
            }
        }
    }

    private static function registerBundleResourceDir()
    {
        if (true === self::$application->isRestored()) {
            return;
        }

        foreach (self::$bundles_base_dir as $key => $dir) {
            $config = self::$application->getContainer()->get($key . '.config');
            $recipe = null;
            if (null !== $config) {
                $recipe = self::getBundleLoaderRecipeFor($config, self::RESOURCE_RECIPE_KEY);
            }

            if (null === $recipe) {
                $resources_dir = realpath($dir . DIRECTORY_SEPARATOR . 'Ressources');
                if (false === $resources_dir) {
                    continue;
                }

                self::$application->pushResourceDir($resources_dir);
            } else {
                if (null !== $config && true === is_callable($recipe)) {
                    call_user_func_array($recipe, array(self::$application, $config));
                }
            }
        }
    }

    private static function registerBundleScriptDir()
    {
        $renderer = self::$application->getRenderer();
        if (true === $renderer->isRestored()) {
            return;
        }

        foreach (self::$bundles_base_dir as $key => $dir) {
            $config = self::$application->getContainer()->get($key . '.config');
            $recipe = null;
            if (null !== $config) {
                $recipe = self::getBundleLoaderRecipeFor($config, self::TEMPLATE_RECIPE_KEY);
            }

            if (null === $recipe) {
                $scripts_dir = realpath($dir . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'scripts');
                if (false === $scripts_dir) {
                    continue;
                }

                $renderer->addScriptDir($scripts_dir);
            } else {
                if (null !== $config && true === is_callable($recipe)) {
                    call_user_func_array($recipe, array(self::$application, $config));
                }
            }
        }
    }

    private static function registerBundleHelperDir()
    {
        if (true === self::$application->getAutoloader()->isRestored()) {
            return;
        }

        $renderer = self::$application->getRenderer();
        foreach (self::$bundles_base_dir as $key => $dir) {
            $config = self::$application->getContainer()->get($key . '.config');
            $recipe = null;
            if (null !== $config) {
                $recipe = self::getBundleLoaderRecipeFor($config, self::HELPER_RECIPE_KEY);
            }

            if (null === $recipe) {
                $helper_dir = realpath($dir . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'helpers');
                if (false === $helper_dir) {
                    continue;
                }

                $renderer->addHelperDir($helper_dir);
            } else {
                if (null !== $config && true === is_callable($recipe)) {
                    call_user_func_array($recipe, array(self::$application, $config));
                }
            }
        }
    }

    public static function getBundleLoaderRecipeFor(Config $config, $key)
    {
        $recipe = null;
        $bundle_config = $config->getBundleConfig();
        if (true === isset($bundle_config['bundle_loader_recipes'])) {
            $recipe = true === isset($bundle_config['bundle_loader_recipes'][$key])
                ? $bundle_config['bundle_loader_recipes'][$key]
                : null
            ;
        }

        return $recipe;
    }
}