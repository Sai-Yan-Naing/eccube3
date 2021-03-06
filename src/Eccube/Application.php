<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace Eccube;

use Binfo\Silex\MobileDetectServiceProvider;
use Eccube\Application\ApplicationTrait;
use Eccube\Common\Constant;
use Eccube\Doctrine\ORM\Mapping\Driver\YamlDriver;
use Eccube\EventListener\TransactionListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Yaml\Yaml;

class Application extends ApplicationTrait
{
    protected static $instance;

    protected $initialized = false;
    protected $initializedPlugin = false;
    protected $testMode = false;

    public static function getInstance(array $values = array())
    {
        if (!is_object(self::$instance)) {
            self::$instance = new Application($values);
        }

        return self::$instance;
    }

    public static function clearInstance()
    {
        self::$instance = null;
    }

    final public function __clone()
    {
        throw new \Exception('Clone is not allowed against '.get_class($this));
    }

    public function __construct(array $values = array())
    {
        parent::__construct($values);

        if (is_null(self::$instance)) {
            self::$instance = $this;
        }

        // load config
        $this->initConfig();

        // init monolog
        $this->initLogger();
    }

    /**
     * Application::run?????????????????????????????????????????????????????????????????????
     *
     * @return bool
     */
    public function isBooted()
    {
        return $this->booted;
    }

    public function initConfig()
    {
        // load config
        $app = $this;
        $this['config'] = $this->share(function() use ($app) {
            $configAll = array();
            $app->parseConfig('constant', $configAll)
                ->parseConfig('path', $configAll)
                ->parseConfig('config', $configAll)
                ->parseConfig('database', $configAll)
                ->parseConfig('mail', $configAll)
                ->parseConfig('log', $configAll)
                ->parseConfig('nav', $configAll, true)
                ->parseConfig('doctrine_cache', $configAll)
                ->parseConfig('http_cache', $configAll)
                ->parseConfig('session_handler', $configAll);

            return $configAll;
        });
    }

    public function initLogger()
    {
        $app = $this;
        $this->register(new ServiceProvider\LogServiceProvider($app));
    }

    public function initialize()
    {
        if ($this->initialized) {
            return;
        }

        // init locale
        $this->initLocale();

        // init session
        if (!$this->isSessionStarted()) {
            $this->initSession();
        }

        // init twig
        $this->initRendering();

        // init provider
        $this->register(new \Silex\Provider\HttpCacheServiceProvider(), array(
            'http_cache.cache_dir' => __DIR__.'/../../app/cache/http/',
        ));
        $this->register(new \Silex\Provider\HttpFragmentServiceProvider());
        $this->register(new \Silex\Provider\UrlGeneratorServiceProvider());
        $this->register(new \Silex\Provider\FormServiceProvider());
        $this->register(new \Silex\Provider\SerializerServiceProvider());
        $this->register(new \Silex\Provider\ValidatorServiceProvider());
        $this->register(new MobileDetectServiceProvider());

        $app = $this;
        $this->error(function (\Exception $e, $code) use ($app) {
            if ($app['debug']) {
                return;
            }

            switch ($code) {
                case 403:
                    $title = '??????????????????????????????';
                    $message = '????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????';
                    break;
                case 404:
                    $title = '????????????????????????????????????';
                    $message = 'URL????????????????????????????????????????????????';
                    break;
                default:
                    $title = '?????????????????????????????????????????????';
                    $message = '???????????????????????????????????????????????????????????????????????????';
                    break;
            }

            return $app->render('error.twig', array(
                'error_title' => $title,
                'error_message' => $message,
            ));
        });

        // init mailer
        $this->initMailer();

        // init doctrine orm
        $this->initDoctrine();

        // Set up the DBAL connection now to check for a proper connection to the database.
        $this->checkDatabaseConnection();

        // init security
        $this->initSecurity();

        // init proxy
        $this->initProxy();

        // init ec-cube service provider
        $this->register(new ServiceProvider\EccubeServiceProvider());

        // mount controllers
        $this->register(new \Silex\Provider\ServiceControllerServiceProvider());
        $this->mount('', new ControllerProvider\FrontControllerProvider());
        $this->mount('/'.trim($this['config']['admin_route'], '/').'/', new ControllerProvider\AdminControllerProvider());
        Request::enableHttpMethodParameterOverride(); // PUT???DELETE????????????????????????

        // add transaction listener
        $this['dispatcher']->addSubscriber(new TransactionListener($this));

        // init http cache
        $this->initCacheRequest();

        $this->initialized = true;
    }

    public function initLocale()
    {

        // timezone
        if (!empty($this['config']['timezone'])) {
            date_default_timezone_set($this['config']['timezone']);
        }

        $this->register(new \Silex\Provider\TranslationServiceProvider(), array(
            'locale' => $this['config']['locale'],
            'translator.cache_dir' => $this['debug'] ? null : $this['config']['root_dir'].'/app/cache/translator',
        ));
        $this['translator'] = $this->share($this->extend('translator', function ($translator, \Silex\Application $app) {
            $translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());

            $file = __DIR__.'/Resource/locale/validator.'.$app['locale'].'.yml';
            if (file_exists($file)) {
                $translator->addResource('yaml', $file, $app['locale'], 'validators');
            }

            $file = __DIR__.'/Resource/locale/message.'.$app['locale'].'.yml';
            if (file_exists($file)) {
                $translator->addResource('yaml', $file, $app['locale']);
            }

            return $translator;
        }));
    }

    public function initSession()
    {
        $this->register(new \Silex\Provider\SessionServiceProvider(), array(
            'session.storage.save_path' => $this['config']['root_dir'].'/app/cache/eccube/session',
            'session.storage.options' => array(
                'name' => $this['config']['cookie_name'],
                'cookie_path' => $this['config']['root_urlpath'] ?: '/',
                'cookie_secure' => $this['config']['force_ssl'],
                'cookie_lifetime' => $this['config']['cookie_lifetime'],
                'cookie_httponly' => true,
                // cookie_domain??????????????????
                // http://blog.tokumaru.org/2011/10/cookiedomain.html
            ),
        ));

        $options = $this['config']['session_handler'];

        if ($options['enabled']) {
            // @see http://silex.sensiolabs.org/doc/providers/session.html#custom-session-configurations
            $this['session.storage.handler'] = null;
            ini_set('session.save_handler', $options['save_handler']);
            ini_set('session.save_path', $options['save_path']);
        }
    }

    public function initRendering()
    {
        $this->register(new \Silex\Provider\TwigServiceProvider(), array(
            'twig.form.templates' => array('Form/form_layout.twig'),
        ));
        $this['twig'] = $this->share($this->extend('twig', function (\Twig_Environment $twig, \Silex\Application $app) {
            $twig->addExtension(new \Eccube\Twig\Extension\EccubeExtension($app));
            $twig->addExtension(new \Twig_Extension_StringLoader());

            return $twig;
        }));

        $this->before(function (Request $request, \Silex\Application $app) {
            $app['admin'] = false;
            $app['front'] = false;
            $pathinfo = rawurldecode($request->getPathInfo());
            if (strpos($pathinfo, '/'.trim($app['config']['admin_route'], '/').'/') === 0) {
                $app['admin'] = true;
            } else {
                $app['front'] = true;
            }

            // ???????????? or ?????????????????????twig?????????????????????????????????.
            $app['twig'] = $app->share($app->extend('twig', function (\Twig_Environment $twig, \Silex\Application $app) {
                $paths = array();

                // ????????????????????????profiler ???production ??????cache???????????????
                if (isset($app['profiler'])) {
                    $cacheBaseDir = __DIR__.'/../../app/cache/twig/profiler/';
                } else {
                    $cacheBaseDir = __DIR__.'/../../app/cache/twig/production/';
                }

                if ($app->isAdminRequest()) {
                    if (file_exists(__DIR__.'/../../app/template/admin')) {
                        $paths[] = __DIR__.'/../../app/template/admin';
                    }
                    $paths[] = $app['config']['template_admin_realdir'];
                    $paths[] = __DIR__.'/../../app/Plugin';
                    $cache = $cacheBaseDir.'admin';

                } else {
                    if (file_exists($app['config']['template_realdir'])) {
                        $paths[] = $app['config']['template_realdir'];
                    }
                    $paths[] = $app['config']['template_default_realdir'];
                    $paths[] = __DIR__.'/../../app/Plugin';
                    $cache = $cacheBaseDir.$app['config']['template_code'];
                    $app['front'] = true;
                }
                $twig->setCache($cache);
                $app['twig.loader']->addLoader(new \Twig_Loader_Filesystem($paths));

                return $twig;
            }));

            // ???????????????IP??????????????????.
            if ($app->isAdminRequest()) {
                // IP??????????????????
                $allowHost = $app['config']['admin_allow_host'];
                if (is_array($allowHost) && count($allowHost) > 0) {
                    if (array_search($app['request']->getClientIp(), $allowHost) === false) {
                        throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
                    }
                }
            }
        }, self::EARLY_EVENT);

        // twig?????????????????????????????????.
        $app = $this;
        $this->on(\Symfony\Component\HttpKernel\KernelEvents::CONTROLLER, function (\Symfony\Component\HttpKernel\Event\FilterControllerEvent $event) use ($app) {
            // ?????????????????????????????????????????????????????????????????????????????????SubRequest????????????????????????,
            // $event->isMasterRequest()?????????????????????????????????????????????????????????????????????????????????
            if (isset($app['twig_global_initialized']) && $app['twig_global_initialized'] === true) {
                return;
            }
            // ????????????????????????
            $BaseInfo = $app['eccube.repository.base_info']->get();
            $app['twig']->addGlobal('BaseInfo', $BaseInfo);

            if ($app->isAdminRequest()) {
                // ????????????
                // ????????????????????????
                $menus = array('', '', '');
                $app['twig']->addGlobal('menus', $menus);

                $Member = $app->user();
                if (is_object($Member)) {
                    // ?????????????????????????????????????????????????????????
                    $AuthorityRoles = $app['eccube.repository.authority_role']->findBy(array('Authority' => $Member->getAuthority()));

                    $roles = array();
                    foreach ($AuthorityRoles as $AuthorityRole) {
                        // ???????????????????????????????????????????????????????????????????????????
                        $roles[] = $app['request']->getBaseUrl().'/'.$app['config']['admin_route'].$AuthorityRole->getDenyUrl();
                    }

                    $app['twig']->addGlobal('AuthorityRoles', $roles);
                }

            } else {
                // ??????????????????
                $request = $event->getRequest();
                $route = $request->attributes->get('_route');
                $page = $route;
                // ?????????????????????
                if ($route === 'user_data') {
                    $params = $request->attributes->get('_route_params');
                    $route = $params['route'];
                    // ?????????????????????
                } elseif ($request->get('preview')) {
                    $route = 'preview';
                }

                try {
                    $DeviceType = $app['eccube.repository.master.device_type']
                        ->find(\Eccube\Entity\Master\DeviceType::DEVICE_TYPE_PC);
                    $PageLayout = $app['eccube.repository.page_layout']->getByUrl($DeviceType, $route, $page);
                } catch (\Doctrine\ORM\NoResultException $e) {
                    $PageLayout = $app['eccube.repository.page_layout']->newPageLayout($DeviceType);
                }

                $app['twig']->addGlobal('PageLayout', $PageLayout);
                $app['twig']->addGlobal('title', $PageLayout->getName());
            }

            $app['twig_global_initialized'] = true;
        });
    }

    public function initMailer()
    {

        // ????????????????????????????????????????????????(??????????????????UTF-8)
        if (isset($this['config']['mail']['charset_iso_2022_jp']) && is_bool($this['config']['mail']['charset_iso_2022_jp'])) {
            if ($this['config']['mail']['charset_iso_2022_jp'] === true) {
                \Swift::init(function () {
                    \Swift_DependencyContainer::getInstance()
                        ->register('mime.qpheaderencoder')
                        ->asAliasOf('mime.base64headerencoder');
                    \Swift_Preferences::getInstance()->setCharset('iso-2022-jp');
                });
            }
        }

        $this->register(new \Silex\Provider\SwiftmailerServiceProvider());
        $this['swiftmailer.options'] = $this['config']['mail'];

        if (isset($this['config']['mail']['use_spool']) && is_bool($this['config']['mail']['use_spool'])) {
            $this['swiftmailer.use_spool'] = $this['config']['mail']['use_spool'];
        }
        // ??????????????????smtp?????????
        $transport = $this['config']['mail']['transport'];
        if ($transport == 'sendmail') {
            $this['swiftmailer.transport'] = \Swift_SendmailTransport::newInstance();
        } elseif ($transport == 'mail') {
            $this['swiftmailer.transport'] = \Swift_MailTransport::newInstance();
        }
    }

    public function initDoctrine()
    {
        $this->register(new \Silex\Provider\DoctrineServiceProvider(), array(
            'dbs.options' => array(
                'default' => $this['config']['database']
            )));
        $this->register(new \Saxulum\DoctrineOrmManagerRegistry\Silex\Provider\DoctrineOrmManagerRegistryProvider());

        // ??????????????????metadata???????????????????????????.
        $pluginConfigs = $this->getPluginConfigAll();
        $ormMappings = array();
        $ormMappings[] = array(
            'type' => 'yml',
            'namespace' => 'Eccube\Entity',
            'path' => array(
                __DIR__.'/Resource/doctrine',
                __DIR__.'/Resource/doctrine/master',
            ),
        );

        foreach ($pluginConfigs as $code) {
            $config = $code['config'];
            // Doctrine Extend
            if (isset($config['orm.path']) && is_array($config['orm.path'])) {
                $paths = array();
                foreach ($config['orm.path'] as $path) {
                    $paths[] = $this['config']['plugin_realdir'].'/'.$config['code'].$path;
                }
                $ormMappings[] = array(
                    'type' => 'yml',
                    'namespace' => 'Plugin\\'.$config['code'].'\\Entity',
                    'path' => $paths,
                );
            }
        }

        $options = array(
            'mappings' => $ormMappings
        );

        if (!$this['debug']) {
            $cacheDrivers = array();
            if (array_key_exists('doctrine_cache', $this['config'])) {
                $cacheDrivers = $this['config']['doctrine_cache'];
            }

            if (array_key_exists('metadata_cache', $cacheDrivers)) {
                $options['metadata_cache'] = $cacheDrivers['metadata_cache'];
            }
            if (array_key_exists('query_cache', $cacheDrivers)) {
                $options['query_cache'] = $cacheDrivers['query_cache'];
            }
            if (array_key_exists('result_cache', $cacheDrivers)) {
                $options['result_cache'] = $cacheDrivers['result_cache'];
            }
            if (array_key_exists('hydration_cache', $cacheDrivers)) {
                $options['hydration_cache'] = $cacheDrivers['hydration_cache'];
            }
        }

        $this->register(new \Dflydev\Silex\Provider\DoctrineOrm\DoctrineOrmServiceProvider(), array(
            'orm.proxies_dir' => __DIR__.'/../../app/cache/doctrine/proxies',
            'orm.em.options' => $options,
            'orm.custom.functions.string' => array(
                'NORMALIZE' => 'Eccube\Doctrine\ORM\Query\Normalize',
            ),
            'orm.custom.functions.numeric' => array(
                'EXTRACT' => 'Eccube\Doctrine\ORM\Query\Extract',
            ),
        ));

        /**
         * YamlDriver???PHP7??????. Doctrine2.4???????????????????????????.
         * @see https://github.com/EC-CUBE/ec-cube/issues/1338
         */
        $config = $this['orm.em']->getConfiguration();
        /** @var $driver \Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain */
        $chain = $config->getMetadataDriverImpl();
        // $ormMappings???1???????????????Driver????????????????????????.
        $drivers = $chain->getDrivers();
        foreach ($drivers as $namespace => $oldDriver) {
            /** @var $newDriver \Eccube\Doctrine\ORM\Mapping\Driver\YamlDriver */
            $newDriver = new YamlDriver($oldDriver->getLocator());
            // ????????????Driver??????????????????. ??????????????????add??????????????????set?????????.
            $chain->addDriver($newDriver, $namespace);
        }
    }

    public function initSecurity()
    {
        $this->register(new \Silex\Provider\SecurityServiceProvider());
        $this->register(new \Silex\Provider\RememberMeServiceProvider());

        $this['security.firewalls'] = array(
            'admin' => array(
                'pattern' => "^/{$this['config']['admin_route']}/",
                'form' => array(
                    'login_path' => "/{$this['config']['admin_route']}/login",
                    'check_path' => "/{$this['config']['admin_route']}/login_check",
                    'username_parameter' => 'login_id',
                    'password_parameter' => 'password',
                    'with_csrf' => true,
                    'use_forward' => true,
                    'default_target_path' => "/{$this['config']['admin_route']}",
                ),
                'logout' => array(
                    'logout_path' => "/{$this['config']['admin_route']}/logout",
                    'target_url' => "/{$this['config']['admin_route']}/",
                ),
                'users' => $this['orm.em']->getRepository('Eccube\Entity\Member'),
                'anonymous' => true,
            ),
            'customer' => array(
                'pattern' => '^/',
                'form' => array(
                    'login_path' => '/mypage/login',
                    'check_path' => '/login_check',
                    'username_parameter' => 'login_email',
                    'password_parameter' => 'login_pass',
                    'with_csrf' => true,
                    'use_forward' => true,
                ),
                'logout' => array(
                    'logout_path' => '/logout',
                    'target_url' => '/',
                ),
                'remember_me' => array(
                    'key' => sha1($this['config']['auth_magic']),
                    'name' => $this['config']['cookie_name'].'_rememberme',
                    // lifetime?????????????????????1???????????????
                    // 'lifetime' => $this['config']['cookie_lifetime'],
                    'path' => $this['config']['root_urlpath'] ?: '/',
                    'secure' => $this['config']['force_ssl'],
                    'httponly' => true,
                    'always_remember_me' => false,
                    'remember_me_parameter' => 'login_memory',
                ),
                'users' => $this['orm.em']->getRepository('Eccube\Entity\Customer'),
                'anonymous' => true,
            ),
        );

        $channel = null;
        // ??????SSL
        if ($this['config']['force_ssl'] == \Eccube\Common\Constant::ENABLED) {
            $channel = "https";
        }

        $this['security.access_rules'] = array(
            array("^/{$this['config']['admin_route']}/login", 'IS_AUTHENTICATED_ANONYMOUSLY', $channel),
            array("^/{$this['config']['admin_route']}/", 'ROLE_ADMIN', $channel),
            array('^/mypage/login', 'IS_AUTHENTICATED_ANONYMOUSLY', $channel),
            array('^/mypage/withdraw_complete', 'IS_AUTHENTICATED_ANONYMOUSLY', $channel),
            array('^/mypage/change', 'IS_AUTHENTICATED_FULLY', $channel),
            array('^/mypage', 'ROLE_USER', $channel),
        );

        $this['eccube.password_encoder'] = $this->share(function ($app) {
            return new \Eccube\Security\Core\Encoder\PasswordEncoder($app['config']);
        });
        $this['security.encoder_factory'] = $this->share(function ($app) {
            return new \Symfony\Component\Security\Core\Encoder\EncoderFactory(array(
                'Eccube\Entity\Customer' => $app['eccube.password_encoder'],
                'Eccube\Entity\Member' => $app['eccube.password_encoder'],
            ));
        });
        $this['eccube.event_listner.security'] = $this->share(function ($app) {
            return new \Eccube\EventListener\SecurityEventListener($app['orm.em']);
        });
        $this['user'] = function ($app) {
            $token = $app['security']->getToken();

            return ($token !== null) ? $token->getUser() : null;
        };

        // ???????????????????????????????????????.
        $this['dispatcher']->addListener(\Symfony\Component\Security\Http\SecurityEvents::INTERACTIVE_LOGIN, array($this['eccube.event_listner.security'], 'onInteractiveLogin'));

        // Voter?????????
        $app = $this;
        $this['authority_voter'] = $this->share(function ($app) {
            return new \Eccube\Security\Voter\AuthorityVoter($app);
        });

        $app['security.voters'] = $app->extend('security.voters', function ($voters) use ($app) {
            $voters[] = $app['authority_voter'];

            return $voters;
        });

        $this['security.access_manager'] = $this->share(function ($app) {
            return new \Symfony\Component\Security\Core\Authorization\AccessDecisionManager($app['security.voters'], 'unanimous');
        });

        $app = $this;
        $app['security.authentication.success_handler.admin'] = $app->share(function ($app) {
            $handler = new \Eccube\Security\Http\Authentication\EccubeAuthenticationSuccessHandler(
                $app['security.http_utils'],
                $app['security.firewalls']['admin']['form']
            );

            $handler->setProviderKey('admin');

            return $handler;
        });

        $app['security.authentication.failure_handler.admin'] = $app->share(function ($app) {
            return new \Eccube\Security\Http\Authentication\EccubeAuthenticationFailureHandler(
                $app,
                $app['security.http_utils'],
                $app['security.firewalls']['admin']['form'],
                $app['logger']
            );
        });

        $app['security.authentication.success_handler.customer'] = $app->share(function ($app) {
            $handler = new \Eccube\Security\Http\Authentication\EccubeAuthenticationSuccessHandler(
                $app['security.http_utils'],
                $app['security.firewalls']['customer']['form']
            );

            $handler->setProviderKey('customer');

            return $handler;
        });

        $app['security.authentication.failure_handler.customer'] = $app->share(function ($app) {
            return new \Eccube\Security\Http\Authentication\EccubeAuthenticationFailureHandler(
                $app,
                $app['security.http_utils'],
                $app['security.firewalls']['customer']['form'],
                $app['logger']
            );
        });
    }

    /**
     * ??????????????????????????????????????????????????????????????????
     */
    public function initProxy()
    {
        $config = $this['config'];
        if (isset($config['trusted_proxies_connection_only']) && !empty($config['trusted_proxies_connection_only'])) {
            $this->on(KernelEvents::REQUEST, function (GetResponseEvent $event) use ($config) {
                // ????????????????????????REMOTE_ADDR???????????????????????????????????????????????????KernelEvents::REQUEST???????????????
                Request::setTrustedProxies(array_merge(array($event->getRequest()->server->get('REMOTE_ADDR')), $config['trusted_proxies']));
            }, self::EARLY_EVENT);
        } elseif (isset($config['trusted_proxies']) && !empty($config['trusted_proxies'])) {
            Request::setTrustedProxies($config['trusted_proxies']);
        }
    }

    public function initializePlugin()
    {
        if ($this->initializedPlugin) {
            return;
        }

        // setup event dispatcher
        $this->initPluginEventDispatcher();

        // load plugin
        $this->loadPlugin();

        $this->initializedPlugin = true;
    }

    public function initPluginEventDispatcher()
    {
        // EventDispatcher
        $this['eccube.event.dispatcher'] = $this->share(function () {
            return new EventDispatcher();
        });

        $app = $this;

        // hook point
        $this->on(KernelEvents::REQUEST, function (GetResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }
            $hookpoint = 'eccube.event.app.before';
            $app['eccube.event.dispatcher']->dispatch($hookpoint, $event);
        }, self::EARLY_EVENT);

        $this->on(KernelEvents::REQUEST, function (GetResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }
            $route = $event->getRequest()->attributes->get('_route');
            $hookpoint = "eccube.event.controller.$route.before";
            $app['eccube.event.dispatcher']->dispatch($hookpoint, $event);
        });

        $this->on(KernelEvents::RESPONSE, function (FilterResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }
            $route = $event->getRequest()->attributes->get('_route');
            $hookpoint = "eccube.event.controller.$route.after";
            $app['eccube.event.dispatcher']->dispatch($hookpoint, $event);
        });

        $this->on(KernelEvents::RESPONSE, function (FilterResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }
            $hookpoint = 'eccube.event.app.after';
            $app['eccube.event.dispatcher']->dispatch($hookpoint, $event);
        }, self::LATE_EVENT);

        $this->on(KernelEvents::TERMINATE, function (PostResponseEvent $event) use ($app) {
            $route = $event->getRequest()->attributes->get('_route');
            $hookpoint = "eccube.event.controller.$route.finish";
            $app['eccube.event.dispatcher']->dispatch($hookpoint, $event);
        });

        $this->on(\Symfony\Component\HttpKernel\KernelEvents::RESPONSE, function (\Symfony\Component\HttpKernel\Event\FilterResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }
            $route = $event->getRequest()->attributes->get('_route');
            $app['eccube.event.dispatcher']->dispatch('eccube.event.render.'.$route.'.before', $event);
        });

        // Request Event
        $this->on(\Symfony\Component\HttpKernel\KernelEvents::REQUEST, function (\Symfony\Component\HttpKernel\Event\GetResponseEvent $event) use ($app) {

            if (!$event->isMasterRequest()) {
                return;
            }

            $route = $event->getRequest()->attributes->get('_route');

            if (is_null($route)) {
                return;
            }

            $app['monolog']->debug('KernelEvents::REQUEST '.$route);

            // ??????
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.request', $event);

            if (strpos($route, 'admin') === 0) {
                // ????????????
                $app['eccube.event.dispatcher']->dispatch('eccube.event.admin.request', $event);
            } else {
                // ??????????????????
                $app['eccube.event.dispatcher']->dispatch('eccube.event.front.request', $event);
            }

            // ????????????????????????
            $app['eccube.event.dispatcher']->dispatch("eccube.event.route.{$route}.request", $event);

        }, 30); // Routing(32)???????????????, ????????????(8)???????????????????????????????????????.

        // Controller Event
        $this->on(\Symfony\Component\HttpKernel\KernelEvents::CONTROLLER, function (\Symfony\Component\HttpKernel\Event\FilterControllerEvent $event) use ($app) {

            if (!$event->isMasterRequest()) {
                return;
            }

            $route = $event->getRequest()->attributes->get('_route');

            if (is_null($route)) {
                return;
            }

            $app['monolog']->debug('KernelEvents::CONTROLLER '.$route);

            // ??????
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.controller', $event);

            if (strpos($route, 'admin') === 0) {
                // ????????????
                $app['eccube.event.dispatcher']->dispatch('eccube.event.admin.controller', $event);
            } else {
                // ??????????????????
                $app['eccube.event.dispatcher']->dispatch('eccube.event.front.controller', $event);
            }

            // ????????????????????????
            $app['eccube.event.dispatcher']->dispatch("eccube.event.route.{$route}.controller", $event);
        });

        // Response Event
        $this->on(\Symfony\Component\HttpKernel\KernelEvents::RESPONSE, function (\Symfony\Component\HttpKernel\Event\FilterResponseEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }

            $route = $event->getRequest()->attributes->get('_route');

            if (is_null($route)) {
                return;
            }

            $app['monolog']->debug('KernelEvents::RESPONSE '.$route);

            // ????????????????????????
            $app['eccube.event.dispatcher']->dispatch("eccube.event.route.{$route}.response", $event);

            if (strpos($route, 'admin') === 0) {
                // ????????????
                $app['eccube.event.dispatcher']->dispatch('eccube.event.admin.response', $event);
            } else {
                // ??????????????????
                $app['eccube.event.dispatcher']->dispatch('eccube.event.front.response', $event);
            }

            // ??????
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.response', $event);
        });

        // Exception Event
        $this->on(\Symfony\Component\HttpKernel\KernelEvents::EXCEPTION, function (\Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent $event) use ($app) {

            if (!$event->isMasterRequest()) {
                return;
            }

            $route = $event->getRequest()->attributes->get('_route');

            if (is_null($route)) {
                return;
            }

            $app['monolog']->debug('KernelEvents::EXCEPTION '.$route);

            // ????????????????????????
            $app['eccube.event.dispatcher']->dispatch("eccube.event.route.{$route}.exception", $event);

            if (strpos($route, 'admin') === 0) {
                // ????????????
                $app['eccube.event.dispatcher']->dispatch('eccube.event.admin.exception', $event);
            } else {
                // ??????????????????
                $app['eccube.event.dispatcher']->dispatch('eccube.event.front.exception', $event);
            }

            // ??????
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.exception', $event);
        });

        // Terminate Event
        $this->on(\Symfony\Component\HttpKernel\KernelEvents::TERMINATE, function (\Symfony\Component\HttpKernel\Event\PostResponseEvent $event) use ($app) {

            $route = $event->getRequest()->attributes->get('_route');

            if (is_null($route)) {
                return;
            }

            $app['monolog']->debug('KernelEvents::TERMINATE '.$route);

            // ????????????????????????
            $app['eccube.event.dispatcher']->dispatch("eccube.event.route.{$route}.terminate", $event);

            if (strpos($route, 'admin') === 0) {
                // ????????????
                $app['eccube.event.dispatcher']->dispatch('eccube.event.admin.terminate', $event);
            } else {
                // ??????????????????
                $app['eccube.event.dispatcher']->dispatch('eccube.event.front.terminate', $event);
            }

            // ??????
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.terminate', $event);
        });
    }

    public function loadPlugin()
    {
        // ??????????????????????????????????????????.
        $basePath = $this['config']['plugin_realdir'];
        $pluginConfigs = $this->getPluginConfigAll();

        // ???????????????????????????db??????????????????????????????????????????????????????
        $priorities = array();
        $handlers = $this['orm.em']
            ->getRepository('Eccube\Entity\PluginEventHandler')
            ->getHandlers();

        foreach ($handlers as $handler) {
            if ($handler->getPlugin()->getEnable() && !$handler->getPlugin()->getDelFlg()) {

                $priority = $handler->getPriority();
            } else {
                // Plugin???disable???????????????????????????EventHandler???Priority?????????0????????????
                $priority = \Eccube\Entity\PluginEventHandler::EVENT_PRIORITY_DISABLED;
            }
            $priorities[$handler->getPlugin()->getClassName()][$handler->getEvent()][$handler->getHandler()] = $priority;
        }

        // ?????????????????????????????????.
        // config.yml/event.yml?????????????????????????????????????????????????????????, ???????????????????????????.
        foreach ($pluginConfigs as $code => $pluginConfig) {
            // ?????????????????? pluginConfig ??????????????????
            $path = $basePath.'/'.$code;
            try {
                $this['eccube.service.plugin']->checkPluginArchiveContent($path, $pluginConfig['config']);
            } catch (\Eccube\Exception\PluginException $e) {
                $this['monolog']->warning("Configuration file config.yml for plugin {$code} not found or is invalid. Skipping loading.", array(
                    'path' => $path,
                    'original-message' => $e->getMessage()
                ));
                continue;
            }
            $config = $pluginConfig['config'];

            $plugin = $this['orm.em']
                ->getRepository('Eccube\Entity\Plugin')
                ->findOneBy(array('code' => $config['code']));

            // const
            if (isset($config['const'])) {
                $this['config'] = $this->share($this->extend('config', function ($eccubeConfig) use ($config) {
                    $eccubeConfig[$config['code']] = array(
                        'const' => $config['const'],
                    );

                    return $eccubeConfig;
                }));
            }

            if ($plugin && $plugin->getEnable() == Constant::DISABLED) {
                // ???????????????????????????????????????????????????????????????
                continue;
            }

            // Type: Event
            if (isset($config['event'])) {
                $class = '\\Plugin\\'.$config['code'].'\\'.$config['event'];
                $eventExists = true;

                if (!class_exists($class)) {
                    $this['monolog']->warning("Event class for plugin {$code} not exists.", array(
                        'class' => $class,
                    ));
                    $eventExists = false;
                }

                if ($eventExists && isset($config['event'])) {

                    $subscriber = new $class($this);

                    foreach ($pluginConfig['event'] as $event => $handlers) {
                        foreach ($handlers as $handler) {
                            if (!isset($priorities[$config['event']][$event][$handler[0]])) { // ????????????????????????????????????????????????????????????????????????????????????????????????)????????????????????????????????????
                                $priority = \Eccube\Entity\PluginEventHandler::EVENT_PRIORITY_LATEST;
                            } else {
                                $priority = $priorities[$config['event']][$event][$handler[0]];
                            }
                            // ????????????0????????????????????????????????????
                            if (\Eccube\Entity\PluginEventHandler::EVENT_PRIORITY_DISABLED != $priority) {
                                $this['eccube.event.dispatcher']->addListener($event, array($subscriber, $handler[0]), $priority);
                            }
                        }
                    }
                }
            }
            // Type: ServiceProvider
            if (isset($config['service'])) {
                foreach ($config['service'] as $service) {
                    $class = '\\Plugin\\'.$config['code'].'\\ServiceProvider\\'.$service;
                    if (!class_exists($class)) {
                        $this['monolog']->warning("Service provider class for plugin {$code} not exists.", array(
                            'class' => $class,
                        ));
                        continue;
                    }
                    $this->register(new $class($this));
                }
            }
        }
    }

    /**
     * PHPUnit ???????????????????????????????????????.
     *
     * @param boolean $testMode PHPUnit ????????????????????? true
     */
    public function setTestMode($testMode)
    {
        $this->testMode = $testMode;
    }

    /**
     * PHPUnit ????????????????????????.
     *
     * @return boolean PHPUnit ????????????????????? true
     */
    public function isTestMode()
    {
        return $this->testMode;
    }

    /**
     *
     * ????????????????????????????????????
     * ?????? : true?????????
     * ?????? : \Doctrine\DBAL\DBALException??????????????????( ??????????????????????????? )??????????????????????????????die()
     * ?????? : app['debug']???true??????????????????????????????
     *
     * @return boolean true
     *
     */
    protected function checkDatabaseConnection()
    {
        if ($this['debug']) {
            return;
        }
        try {
            $this['db']->connect();
        } catch (\Doctrine\DBAL\DBALException $e) {
            $this['monolog']->error($e->getMessage());
            $this['twig.path'] = array(__DIR__.'/Resource/template/exception');
            $html = $this['twig']->render('error.twig', array(
                'error_title' => '????????????????????????????????????',
                'error_message' => '????????????????????????????????????????????????',
            ));
            $response = new Response();
            $response->setContent($html);
            $response->setStatusCode('500');
            $response->headers->set('Content-Type', 'text/html');
            $response->send();
            die();
        }

        return true;
    }

    /**
     * Config ?????????????????????????????????????????????????????????.
     *
     * $config_name.yml ?????????????????????????????????????????????????????????.
     * $config_name.php ??????????????????????????? PHP ???????????????????????????????????????????????????????????????
     *
     * @param string $config_name Config ??????
     * @param array $configAll Config ???????????????
     * @param boolean $wrap_key Config ?????????????????? config_name ?????????????????????????????? true, ??????????????? false
     * @param string $ymlPath config yaml ?????????????????????????????????
     * @param string $distPath config yaml dist ?????????????????????????????????
     * @return Application
     */
    public function parseConfig($config_name, array &$configAll, $wrap_key = false, $ymlPath = null, $distPath = null)
    {
        $ymlPath = $ymlPath ? $ymlPath : __DIR__.'/../../app/config/eccube';
        $distPath = $distPath ? $distPath : __DIR__.'/../../src/Eccube/Resource/config';
        $config = array();
        $config_php = $ymlPath.'/'.$config_name.'.php';
        if (!file_exists($config_php)) {
            $config_yml = $ymlPath.'/'.$config_name.'.yml';
            if (file_exists($config_yml)) {
                $config = Yaml::parse(file_get_contents($config_yml));
                $config = empty($config) ? array() : $config;
                if (isset($this['output_config_php']) && $this['output_config_php']) {
                    file_put_contents($config_php, sprintf('<?php return %s', var_export($config, true)).';');
                }
            }
        } else {
            $config = require $config_php;
        }

        $config_dist = array();
        $config_php_dist = $distPath.'/'.$config_name.'.dist.php';
        if (!file_exists($config_php_dist)) {
            $config_yml_dist = $distPath.'/'.$config_name.'.yml.dist';
            if (file_exists($config_yml_dist)) {
                $config_dist = Yaml::parse(file_get_contents($config_yml_dist));
                if (isset($this['output_config_php']) && $this['output_config_php']) {
                    file_put_contents($config_php_dist, sprintf('<?php return %s', var_export($config_dist, true)).';');
                }
            }
        } else {
            $config_dist = require $config_php_dist;
        }

        if ($wrap_key) {
            $configAll = array_replace_recursive($configAll, array($config_name => $config_dist), array($config_name => $config));
        } else {
            $configAll = array_replace_recursive($configAll, $config_dist, $config);
        }

        return $this;
    }

    /**
     * ???????????????????????????????????????????????????.
     *
     * @return boolean ??????????????????????????????????????? true
     * @link http://php.net/manual/ja/function.session-status.php#113468
     */
    protected function isSessionStarted()
    {
        if (php_sapi_name() !== 'cli') {
            if (version_compare(phpversion(), '5.4.0', '>=')) {
                return session_status() === PHP_SESSION_ACTIVE ? true : false;
            } else {
                return session_id() === '' ? false : true;
            }
        }

        return false;
    }

    /**
     * Http Cache??????
     */
    protected function initCacheRequest()
    {
        // http?????????????????????????????????????????????????????????????????????.
        if (!$this['config']['http_cache']['enabled']) {
            return;
        }

        $app = $this;

        // Response Event(http cache?????????event???????????????????????????)
        $this->on(\Symfony\Component\HttpKernel\KernelEvents::RESPONSE, function (\Symfony\Component\HttpKernel\Event\FilterResponseEvent $event) use ($app) {

            if (!$event->isMasterRequest()) {
                return;
            }

            $request = $event->getRequest();
            $response = $event->getResponse();

            $route = $request->attributes->get('_route');

            $etag = md5($response->getContent());

            if (strpos($route, 'admin') === 0) {
                // ????????????

                // ????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????????private?????????
                $response->setCache(array(
                    'etag' => $etag,
                    'private' => true,
                ));

                if ($response->isNotModified($request)) {
                    return $response;
                }

            } else {
                // ??????????????????
                $cacheRoute = $app['config']['http_cache']['route'];

                if (in_array($route, $cacheRoute) === true) {
                    // ????????????????????????????????????l????????????????????????????????????????????????
                    // max-age???????????????????????????Expires?????????
                    // Last-Modified???????????????????????????????????????ETag?????????
                    // max-age??????????????????????????????content????????????????????????????????????????????????

                    $age = $app['config']['http_cache']['age'];

                    $response->setCache(array(
                        'etag' => $etag,
                        'max_age' => $age,
                        's_maxage' => $age,
                        'public' => true,
                    ));

                    if ($response->isNotModified($request)) {
                        return $response;
                    }
                }
            }

        }, -1024);
    }

    /**
     * ???????????????????????????????????????????????????.
     *
     * ?????????????????????????????? config.yml ?????? event.yml ???????????????????????????????????????.
     * ????????????????????????????????????????????????????????????????????????????????????.
     * ???????????????????????????????????????????????????????????????????????????????????????.
     * $app['debug'] = true ????????????????????????????????????????????????.
     *
     * @return array
     */
    public function getPluginConfigAll()
    {
        if ($this['debug']) {
            return $this->parsePluginConfigs();
        }
        $pluginConfigCache = $this->getPluginConfigCacheFile();
        if (file_exists($pluginConfigCache)) {
            return require $pluginConfigCache;
        }
        if ($this->writePluginConfigCache($pluginConfigCache) === false) {
            return $this->parsePluginConfigs();
        } else {
            return require $pluginConfigCache;
        }
    }

    /**
     * ????????????????????????????????????????????????????????????.
     *
     * @param string $cacheFile
     * @return int|boolean file_put_contents() ?????????
     */
    public function writePluginConfigCache($cacheFile = null)
    {
        if (is_null($cacheFile)) {
            $cacheFile = $this->getPluginConfigCacheFile();
        }
        $pluginConfigs = $this->parsePluginConfigs();
        if (!file_exists($this['config']['plugin_temp_realdir'])) {
            @mkdir($this['config']['plugin_temp_realdir']);
        }
        $this['monolog']->debug("write plugin config cache", array($pluginConfigs));
        return file_put_contents($cacheFile, sprintf('<?php return %s', var_export($pluginConfigs, true)).';');
    }

    /**
     * ????????????????????????????????????????????????????????????????????????.
     *
     * @return boolean
     */
    public function removePluginConfigCache()
    {
        $cacheFile = $this->getPluginConfigCacheFile();
        if (file_exists($cacheFile)) {
            $this['monolog']->debug("remove plugin config cache");
            return unlink($cacheFile);
        }
        return false;
    }

    /**
     * ????????????????????????????????????????????????????????????????????????.
     *
     * @return string
     */
    public function getPluginConfigCacheFile()
    {
        return $this['config']['plugin_temp_realdir'].'/config_cache.php';
    }

    /**
     * ??????????????????????????????????????????, ?????????????????????.
     *
     * ?????????????????????????????????????????? config.yml ?????? event.yml ??????????????????.
     * ?????????????????????????????????????????????.
     *
     * @return array
     */
    public function parsePluginConfigs()
    {

        $finder = Finder::create()
            ->in($this['config']['plugin_realdir'])
            ->directories()
            ->depth(0);
        $finder->sortByName();

        $pluginConfigs = array();
        foreach ($finder as $dir) {
            $code = $dir->getBaseName();
            if (!$code) {
                //PHP5.3???getBaseName????????????
                if (PHP_VERSION_ID < 50400) {
                    $code = $dir->getFilename();
                }
            }
            $file = $dir->getRealPath().'/config.yml';
            $config = null;
            if (file_exists($file)) {
                $config = Yaml::parse(file_get_contents($file));
            } else {
                $this['monolog']->warning("skip {$code} orm.path loading. config.yml not found.", array('path' => $file));
                continue;
            }

            $file = $dir->getRealPath().'/event.yml';
            $event = null;
            if (file_exists($file)) {
                $event = Yaml::parse(file_get_contents($file));
            } else {
                $this['monolog']->info("skip {$code} event.yml not found.", array('path' => $file));
            }
            if (!is_null($config)) {
                $pluginConfigs[$code] = array(
                    'config' => $config,
                    'event' => $event
                );
                $this['monolog']->debug("parse {$code} config", array($code => $pluginConfigs[$code]));
            }
        }

        return $pluginConfigs;
    }
}
