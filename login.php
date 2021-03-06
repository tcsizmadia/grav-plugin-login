<?php
namespace Grav\Plugin;

use Grav\Plugin\Admin;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\User\User;
use Grav\Common\Utils;

use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\Session\Message;

class LoginPlugin extends Plugin
{
    /**
     * @var string
     */
    protected $route;

    /**
     * @var string
     */
    protected $route_register;

    /**
     * @var bool
     */
    protected $authenticated = true;

    /**
     * @var bool
     */
    protected $authorized = true;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['initialize', 10000],
            'onTask.login.login' => ['loginController', 0],
            'onTask.login.logout' => ['loginController', 0],
            'onPageInitialized' => ['authorizePage', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', -100000],
            'onFormProcessed' => ['onFormProcessed', 0]
        ];
    }

    /**
     * Initialize login plugin if path matches.
     */
    public function initialize()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        // Check to ensure sessions are enabled.
        if ($this->grav['config']->get('system.session.enabled') === false) {
            throw new \RuntimeException('The Login plugin requires "system.session" to be enabled');
        }

        /** @var Grav\Common\Session */
        $session = $this->grav['session'];

        // Autoload classes
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new \Exception('Login Plugin failed to load. Composer dependencies not met.');
        }
        require_once $autoload;

        // Define session message service.
        $this->grav['messages'] = function ($c) {
            $session = $c['session'];

            if (!isset($session->messages)) {
                $session->messages = new Message;
            }

            return $session->messages;
        };

        // Define current user service.
        $this->grav['user'] = function ($c) {
            $session = $c['session'];

            if (!isset($session->user)) {
                $session->user = new User;

                if ($c['config']->get('plugins.login.rememberme.enabled')) {
                    $controller = new Login\Controller($c, '');
                    $rememberMe = $controller->rememberMe();

                    // If we can present the correct tokens from the cookie, we are logged in
                    $username = $rememberMe->login();
                    if ($username) {
                        // Normal login process
                        $user = User::load($username);
                        if ($user->exists()) {
                            // There is a chance that an attacker has stolen
                            // the login token, so we store the fact that
                            // the user was logged in via RememberMe
                            // (instead of login form)
                            $session->remember_me = $rememberMe;
                            $session->user = $user;
                        }
                    }

                    // Check if the token was invalid
                    if ($rememberMe->loginTokenWasInvalid()) {
                        $controller->setMessage($c['language']->translate('LOGIN_PLUGIN.REMEMBER_ME_STOLEN_COOKIE'));
                    }
                }
            }

            return $session->user;
        };

        // Manage OAuth login
        $task = !empty($_POST['task']) ? $_POST['task'] : $uri->param('task');
        if (!$task && isset($_POST['oauth']) || (!empty($_GET) && $session->oauth)) {
            $this->oauthController();
        }

        // Aborted OAuth authentication (invalidate it)
        unset($session->oauth);

        $admin_route = $this->config->get('plugins.admin.route');

        // Register route to login page if it has been set.
        if ($uri->path() != $admin_route && substr($uri->path(), 0, strlen($admin_route) + 1) != ($admin_route . '/')) {
            $this->route = $this->config->get('plugins.login.route');
        }

        $this->route_register = $this->config->get('plugins.login.route_register');

        if ($this->route && $this->route == $uri->path()) {
            $this->enable([
                'onPagesInitialized' => ['addLoginPage', 0],
            ]);
        }

        if ($this->route_register && $this->route_register == $uri->path()) {
            $this->enable([
                'onPagesInitialized' => ['addRegisterPage', 0],
            ]);
        }
    }

    /**
     * Add Login page
     */
    public function addLoginPage()
    {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        $page = $pages->dispatch($this->route);

        if (!$page) {
            // Only add login page if it hasn't already been defined.
            $page = new Page;
            $page->init(new \SplFileInfo(__DIR__ . "/pages/login.md"));
            $page->slug(basename($this->route));

            $pages->addPage($page, $this->route);
        }
    }

    /**
     * Add Register page
     */
    public function addRegisterPage()
    {
        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . "/pages/register.md"));
        $page->template('form');
        $page->slug(basename($this->route_register));

        $pages->addPage($page, $this->route_register);
    }

    /**
     * Initialize login controller
     */
    public function loginController()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $task = !empty($_POST['task']) ? $_POST['task'] : $uri->param('task');
        $task = substr($task, strlen('login.'));
        $post = !empty($_POST) ? $_POST : [];

        if (method_exists('Grav\Common\Utils', 'getNonce')) {
            if ($task == 'login') {
                if (!isset($post['login-form-nonce']) || !Utils::verifyNonce($post['login-form-nonce'], 'login-form')) {
                    $this->grav['messages']->add($this->grav['language']->translate('LOGIN_PLUGIN.ACCESS_DENIED'), 'info');
                    $this->authenticated = false;
                    $twig = $this->grav['twig'];
                    $twig->twig_vars['notAuthorized'] = true;
                    return;
                }
            } else if ($task == 'logout') {
                $nonce = $this->grav['uri']->param('logout-nonce');
                if (!isset($nonce) || !Utils::verifyNonce($nonce, 'logout-form')) {
                    return;
                }
            }
        }

        $controller = new Login\LoginController($this->grav, $task, $post);
        $controller->execute();
        $controller->redirect();
    }

    /**
     * Initialize OAuth login controller
     */
    public function oauthController()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $oauth = !empty($_POST['oauth']) ? $_POST['oauth'] : $uri->param('oauth');
        $oauth = $oauth ?: $this->grav['session']->oauth;
        $post = !empty($_POST) ? $_POST : [];

        $controller = new Login\OAuthLoginController($this->grav, $oauth, $post);
        $controller->execute();
        $controller->redirect();
    }

    /**
     * Authorize Page
     */
    public function authorizePage()
    {
        /** @var User $user */
        $user = $this->grav['user'];

        /** @var Page $page */
        $page = $this->grav['page'];

        if (!$page) {
            return;
        }

        $header = $page->header();
        $rules = isset($header->access) ? (array) $header->access : [];

        // Continue to the page if it has no ACL rules.
        if (!$rules) {
            return;
        }

        // Continue to the page if user is authorized to access the page.
        foreach ($rules as $rule => $value) {
            if ($user->authorize($rule) == $value) {
                return;
            }
        }

        // User is not logged in; redirect to login page.
        if ($this->route && !$user->authenticated) {
            $this->grav->redirect($this->route, 302);
        }

        /** @var Language $l */
        $l = $this->grav['language'];

        // Reset page with login page.
        if (!$user->authenticated) {
            $page = new Page;
            $page->init(new \SplFileInfo(__DIR__ . "/pages/login.md"));
            $page->slug(basename($this->route));

            $this->authenticated = false;

            unset($this->grav['page']);
            $this->grav['page'] = $page;
        } else {
            $this->grav['messages']->add($l->translate('LOGIN_PLUGIN.ACCESS_DENIED'), 'info');
            $this->authenticated = false;

            $twig = $this->grav['twig'];
            $twig->twig_vars['notAuthorized'] = true;
        }
    }


    /**
     * Add twig paths to plugin templates.
     */
    public function onTwigTemplatePaths()
    {
        $twig = $this->grav['twig'];
        $twig->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Set all twig variables for generating output.
     */
    public function onTwigSiteVariables()
    {
        /** @var Twig $twig */
        $twig = $this->grav['twig'];

        $extension = $this->grav['uri']->extension();
        $extension = $extension ?: 'html';

        if (!$this->authenticated) {
            $twig->template = "login." . $extension . ".twig";

            $providers = [];
            foreach ($this->config->get('plugins.login.oauth.providers') as $provider => $options) {
                if ($options['enabled'] && isset($options['credentials'])) {
                    $providers[$provider] = $options['credentials'];
                }
            }
            $twig->twig_vars['oauth'] = [
                'enabled' => $this->config->get('plugins.login.oauth.enabled'),
                'providers' => $providers
            ];
        }

        // add CSS for frontend if required
        if (!$this->isAdmin() && $this->config->get('plugins.login.built_in_css')) {
            $this->grav['assets']->add('plugin://login/css/login.css');
        }
    }

    /**
     * Validate a value. Currently validates
     *
     * - 'user' for username format and username availability.
     * - 'password1' for password format
     * - 'password2' for equality to password1
     *
     * @param object $form      The form
     * @param string $type      The field type
     * @param string $value     The field value
     * @param string $extra     Any extra value required
     *
     * @return mixed
     */
    protected function validate($type, $value, $extra = '')
    {
        switch ($type) {
            case 'username_format':
                if (!preg_match('/^[a-z0-9_-]{3,16}$/', $value)) {
                    return false;
                }
                return true;
                break;

            case 'username_is_available':
                if (file_exists($this->grav['locator']->findResource('user://accounts/' . $value . YAML_EXT))) {
                    return false;
                }
                return true;
                break;

            case 'password1':
                if (!preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}/', $value)) {
                    return false;
                }
                return true;
                break;

            case 'password2':
                if (strcmp($value, $extra)) {
                    return false;
                }
                return true;
                break;
        }
    }

    /**
     * Process a registration form. Handles the following actions:
     *
     * - validate_password: validates a password
     * - register_user: registers a user
     *
     * @param Event $event
     */
    public function onFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];
        $params = $event['params'];

        switch ($action) {

            case 'register_user':

                if (!$this->config->get('plugins.login.enabled')) {
                    throw new \RuntimeException($this->grav['language']->translate('LOGIN_PLUGIN.LOGIN_PLUGIN_DISABLED'));
                }

                if (!$this->config->get('plugins.login.user_registration.enabled')) {
                    throw new \RuntimeException($this->grav['language']->translate('LOGIN_PLUGIN.USER_REGISTRATION_DISABLED'));
                }

                $data = [];
                $username = $form->value('username');
                if (!$this->validate('username_format', $username)) {
                    $this->grav->fireEvent('onFormValidationError', new Event(['form' => $form, 'message' => $this->grav['language']->translate('LOGIN_PLUGIN.USERNAME_NOT_VALID')]));
                    $event->stopPropagation();
                    return;
                }

                if (!$this->validate('username_is_available', $username)) {
                    $this->grav->fireEvent('onFormValidationError', new Event(['form' => $form, 'message' => $this->grav['language']->translate(['LOGIN_PLUGIN.USERNAME_NOT_AVAILABLE', $username])]));
                    $event->stopPropagation();
                    return;
                }

                if (isset($params['options']['validate_password1_and_password2']) && $params['options']['validate_password1_and_password2']) {
                    if (!$this->validate('password1', $form->value('password1'))) {
                        $this->grav->fireEvent('onFormValidationError', new Event(['form' => $form, 'message' => $this->grav['language']->translate('LOGIN_PLUGIN.PASSWORD_NOT_VALID')]));
                        $event->stopPropagation();
                        return;
                    }
                    if (!$this->validate('password2', $form->value('password2'), $form->value('password1'))) {
                        $this->grav->fireEvent('onFormValidationError', new Event(['form' => $form, 'message' => $this->grav['language']->translate('LOGIN_PLUGIN.PASSWORDS_DO_NOT_MATCH')]));
                        $event->stopPropagation();
                        return;
                    }
                    $data['password'] = $form->value('password1');
                }

                if (isset($params['options']['validate_password']) && $params['options']['validate_password']) {
                    if (!$this->validate('password1', $form->value('password'))) {
                        $this->grav->fireEvent('onFormValidationError', new Event(['form' => $form, 'message' => $this->grav['language']->translate('LOGIN_PLUGIN.PASSWORD_NOT_VALID')]));
                        $event->stopPropagation();
                        return;
                    }
                }

                $fields = $this->config->get('plugins.login.user_registration.fields', []);

                foreach($fields as $field) {
                    // Process value of field if set in the page process.register_user
                    if (isset($params['fields'])) {
                        foreach($params['fields'] as $key => $param) {
                            if ($key == $field) {
                                $data[$field] = $param;
                            }
                        }
                    }

                    if (!isset($data[$field]) && $form->value($field)) {
                        $data[$field] = $form->value($field);
                    }
                }

                if (isset($params['options']['validate_password1_and_password2']) && $params['options']['validate_password1_and_password2']) {
                    unset($data['password1']);
                    unset($data['password2']);
                }

                // Don't store the username: that is part of the filename
                unset($data['username']);

                // Create user object and save it
                $user = new User($data);
                $file = CompiledYamlFile::instance($this->grav['locator']->findResource('user://accounts/' . $username . YAML_EXT, true, true));
                $user->file($file);
                $user->save();

                if (isset($params['options']['login_after_registration']) && $params['options']['login_after_registration']) {
                    //Login user
                    $user = User::load($username);
                    $this->grav['session']->user = $user;
                    unset($this->grav['user']);
                    $this->grav['user'] = $user;
                    $user->authenticated = $user->authorize('site.login');
                }

                break;
        }
    }
}
